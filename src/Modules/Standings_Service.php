<?php
/**
 * Standings service.
 *
 * Rebuilds a league's standings into a new published generation and swaps it
 * into use atomically — the last completed generation stays readable during
 * processing. Ranking is the pure competition-rank rule: points, then
 * scoring picks, then shared positions (1, 2, 2, 4).
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Entry_Rules;
use Obitleague\Domain\Ranking;

final class Standings_Service {

	private function __construct() {}

	/**
	 * Rebuild standings for a league+season from submitted entries and the
	 * award ledger, then publish as the current generation.
	 *
	 * @return int The new generation id.
	 */
	public static function rebuild( int $league_id, int $season ): int {
		global $wpdb;

		$entries = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, user_id FROM ' . $wpdb->prefix . 'obitleague_entries
				 WHERE league_id = %d AND season = %d AND state = %s',
				$league_id,
				$season,
				Entry_Rules::SUBMITTED
			)
		);

		$rows = array();
		foreach ( (array) $entries as $entry ) {
			$revision = Entry_Service::submitted_revision( (int) $entry->id );
			if ( ! $revision ) {
				continue; // No valid submitted revision: does not compete.
			}

			$awards = array();
			foreach ( Entry_Service::revision_picks( (int) $revision->id ) as $pick ) {
				$points = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COALESCE(SUM(award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards
						 WHERE entry_id = %d AND pick_slug = %s AND season = %d',
						(int) $entry->id,
						(string) $pick->person_uuid,
						$season
					)
				);
				if ( $points > 0 ) {
					$awards[ (string) $pick->person_uuid ] = $points;
				}
			}

			$score        = Entry_Rules::score_submission( array_column( (array) Entry_Service::revision_picks( (int) $revision->id ), 'person_uuid' ), $awards );
			$rows[ $entry->user_id ] = array(
				'score'         => $score['points'],
				'scoring_picks' => $score['scoring_picks'],
			);
		}

		$ranked = Ranking::competition_rank( $rows );

		$wpdb->query( 'START TRANSACTION' );
		try {
			// Retire the previous current generation only at swap time.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'obitleague_standings_generations SET is_current = 0
					 WHERE league_id = %d AND season = %d AND is_current = 1',
					$league_id,
					$season
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_standings_generations (league_id, season, is_current, published_at)
					 VALUES (%d, %d, 1, %s)',
					$league_id,
					$season,
					current_time( 'mysql', true )
				)
			);
			$generation_id = (int) $wpdb->insert_id;

			foreach ( $ranked as $user_id => $row ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO ' . $wpdb->prefix . 'obitleague_standings_rows (generation_id, user_id, points, scoring_picks, rank_pos)
						 VALUES (%d, %d, %d, %d, %d)',
						$generation_id,
						(int) $user_id,
						(int) $row['score'],
						(int) $row['scoring_picks'],
						(int) $row['rank']
					)
				);
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return $generation_id;
	}

	/** Rows of the current published generation, or null when none exists. */
	public static function current( int $league_id, int $season ): ?array {
		global $wpdb;

		$generation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_standings_generations
				 WHERE league_id = %d AND season = %d AND is_current = 1
				 ORDER BY id DESC LIMIT 1',
				$league_id,
				$season
			)
		);
		if ( ! $generation_id ) {
			return null;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.user_id, r.points, r.scoring_picks, r.rank_pos, u.display_name
				 FROM ' . $wpdb->prefix . 'obitleague_standings_rows r
				 LEFT JOIN ' . $wpdb->users . " u ON u.ID = r.user_id
				 WHERE r.generation_id = %d
				 ORDER BY r.rank_pos ASC, u.display_name ASC",
				$generation_id
			)
		);

		return array_map(
			static fn ( object $row ): array => array(
				'user_id'       => (int) $row->user_id,
				'player'        => (string) ( $row->display_name ?: ( 'Player ' . $row->user_id ) ),
				'points'        => (int) $row->points,
				'scoring_picks' => (int) $row->scoring_picks,
				'rank'          => (int) $row->rank_pos,
			),
			(array) $rows
		);
	}
}

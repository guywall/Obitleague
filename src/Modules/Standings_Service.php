<?php
/** Rebuilds and pages published standings for leagues of any membership size. */
declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Entry_Rules;

final class Standings_Service {

	private function __construct() {}

	/** Rebuild one league from the award ledger and publish a new generation. */
	public static function rebuild( int $league_id, int $season ): int {
		global $wpdb;
		$lock_name = 'obitleague_standings_' . $league_id . '_' . $season;
		$lock_acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $lock_acquired ) {
			throw new \RuntimeException( 'Standings rebuild already in progress; retry it shortly.' );
		}

		$entries = $wpdb->prefix . 'obitleague_entries';
		$revisions = $wpdb->prefix . 'obitleague_entry_revisions';
		$picks = $wpdb->prefix . 'obitleague_entry_picks';
		$awards = $wpdb->prefix . 'obitleague_awards';
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'obitleague_standings_generations SET is_current = 0 WHERE league_id = %d AND season = %d AND is_current = 1',
					$league_id,
					$season
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_standings_generations (league_id, season, is_current, published_at) VALUES (%d, %d, 1, %s)',
					$league_id,
					$season,
					current_time( 'mysql', true )
				)
			);
			$generation_id = (int) $wpdb->insert_id;			$position = 0;
			$rank = 0;
			$previous = null;
			$batch_size = 500;
			/*
			 * The award aggregate is expensive and is computed once into a
			 * temporary table; the ranking then pages over that small, indexed
			 * set. Re-running the join per batch was quadratic in league size
			 * (30s per batch at 2,500 teams).
			 *
			 * Paging uses a keyset over the FULL sort tuple
			 * (points DESC, scoring_picks DESC, user_id ASC). A single-column
			 * cursor cannot express this: paging on e.id advanced the cursor to
			 * the highest id in a batch and stranded lower-id teams outside it;
			 * paging on user_id regressed whenever a batch ended on a
			 * non-scorer and re-selected rows already inserted.
			 *
			 * CREATE/ALTER/DROP TEMPORARY TABLE do not commit the open
			 * transaction, so the rebuild still rolls back as one unit.
			 */
			$tmp = 'tmp_ob_rank_' . (int) $league_id . '_' . (int) $season;
			// Test accounts are excluded at build time as well as at read time:
			// the published generation is itself a public artifact, and a rank
			// computed over synthetic teams would be wrong even after they were
			// filtered from the printed table.
			$scope = Public_Scope::test_user_exclusion( 'e.user_id' );
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$tmp}" );
			$wpdb->query(
				"CREATE TEMPORARY TABLE {$tmp} AS
				SELECT e.id AS entry_id, e.user_id,
					COALESCE(SUM(CASE WHEN a.points > 0 THEN a.points ELSE 0 END), 0) AS points,
					COALESCE(SUM(CASE WHEN a.points > 0 THEN 1 ELSE 0 END), 0) AS scoring_picks
				FROM {$entries} e
				JOIN {$revisions} r ON r.entry_id = e.id AND r.kind = '" . Entry_Rules::KIND_SUBMITTED . "'
				JOIN {$picks} p ON p.revision_id = r.id
				LEFT JOIN (
					SELECT entry_id, pick_slug, SUM(award_delta) AS points
					FROM {$awards} WHERE season = " . (int) $season . ' GROUP BY entry_id, pick_slug
				) a ON a.entry_id = e.id AND a.pick_slug = p.person_uuid
				WHERE e.league_id = ' . (int) $league_id . ' AND e.season = ' . (int) $season . "
				  AND e.state = '" . Entry_Rules::SUBMITTED . "'" . $scope . "
				GROUP BY e.id, e.user_id"
			);
			$wpdb->query( "ALTER TABLE {$tmp} ADD INDEX ob_rank (points, scoring_picks, user_id)" );

			$cursor_points  = PHP_INT_MAX;
			$cursor_scoring = PHP_INT_MAX;
			$cursor_user    = 0;
			$page_sql       = "SELECT entry_id, user_id, points, scoring_picks FROM {$tmp}
				WHERE points < %d
					OR (points = %d AND scoring_picks < %d)
					OR (points = %d AND scoring_picks = %d AND user_id > %d)
				ORDER BY points DESC, scoring_picks DESC, user_id ASC
				LIMIT %d";
			while ( true ) {
				$scores = $wpdb->get_results(
					$wpdb->prepare(
						$page_sql,
						$cursor_points,
						$cursor_points,
						$cursor_scoring,
						$cursor_points,
						$cursor_scoring,
						$cursor_user,
						$batch_size
					)
				);
				if ( ! $scores ) {
					break;
				}
				$values = array();
				$args   = array();
				foreach ( $scores as $score ) {
					$last = $score;
					++$position;
					$key = (int) $score->points . ':' . (int) $score->scoring_picks;
					if ( $key !== $previous ) {
						$rank     = $position;
						$previous = $key;
					}
					$values[] = '(%d, %d, %d, %d, %d)';
					array_push( $args, $generation_id, (int) $score->user_id, (int) $score->points, (int) $score->scoring_picks, $rank );
				}
				self::insert_rank_batch( $values, $args );
				// Advance the keyset to the final row of this batch.
				$cursor_points  = (int) $last->points;
				$cursor_scoring = (int) $last->scoring_picks;
				$cursor_user    = (int) $last->user_id;
				if ( count( $scores ) < $batch_size ) {
					break;
				}
			}
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$tmp}" );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
		return $generation_id;
	}

	/**
	 * Insert bounded standings batches.
	 *
	 * A failed insert must abort the rebuild: standings_rows is unique on
	 * (generation_id, user_id), so a silently dropped batch would publish a
	 * short generation that looks successful to every caller.
	 *
	 * @throws \RuntimeException When the batch cannot be written.
	 */
	private static function insert_rank_batch( array $values, array $args ): void {
		if ( ! $values ) {
			return;
		}
		global $wpdb;
		$sql    = 'INSERT INTO ' . $wpdb->prefix . 'obitleague_standings_rows (generation_id, user_id, points, scoring_picks, rank_pos) VALUES ' . implode( ', ', $values );
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		if ( false === $result ) {
			throw new \RuntimeException(
				'Could not write a standings batch: ' . (string) $wpdb->last_error
			);
		}
	}

	/** Current generation rows; callers should provide a page size. */
	public static function current( int $league_id, int $season, int $limit = 0, int $offset = 0 ): ?array {
		global $wpdb;
		$generation_id = self::generation_id( $league_id, $season );
		if ( ! $generation_id ) {
			return null;
		}
		$limit = $limit > 0 ? min( 100, max( 1, $limit ) ) : 100;
		$offset = max( 0, $offset );
		$scope = Public_Scope::test_user_exclusion( 'r.user_id' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.user_id, r.points, r.scoring_picks, r.rank_pos, u.display_name, e.id AS entry_id, e.team_name
				 FROM ' . $wpdb->prefix . 'obitleague_standings_rows r
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.user_id
				 LEFT JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.user_id = r.user_id AND e.league_id = %d AND e.season = %d
				 WHERE r.generation_id = %d' . $scope . '
				 ORDER BY r.rank_pos ASC, r.user_id ASC LIMIT %d OFFSET %d',
				$league_id, $season, $generation_id, $limit, $offset
			)
		);
		return array_map( static fn ( object $row ): array => self::public_row( $row ), (array) $rows );
	}

	/** Find one player's score/rank without loading the whole standings table. */
	public static function row_for_user( int $league_id, int $season, int $user_id ): ?array {
		global $wpdb;
		$generation_id = self::generation_id( $league_id, $season );
		if ( ! $generation_id ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT r.user_id, r.points, r.scoring_picks, r.rank_pos, u.display_name, e.id AS entry_id, e.team_name
				 FROM ' . $wpdb->prefix . 'obitleague_standings_rows r
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.user_id
				 LEFT JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.user_id = r.user_id AND e.league_id = %d AND e.season = %d
				 WHERE r.generation_id = %d AND r.user_id = %d LIMIT 1',
				$league_id, $season, $generation_id, $user_id
			)
		);
		return $row ? self::public_row( $row ) : null;
	}

	public static function count_current( int $league_id, int $season ): int {
		global $wpdb;
		$generation_id = self::generation_id( $league_id, $season );
		if ( ! $generation_id ) {
			return 0;
		}
		$scope = Public_Scope::test_user_exclusion( 'r.user_id' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_standings_rows r WHERE r.generation_id = %d' . $scope, $generation_id ) );
	}

	private static function generation_id( int $league_id, int $season ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_standings_generations WHERE league_id = %d AND season = %d AND is_current = 1 ORDER BY id DESC LIMIT 1', $league_id, $season )
		);
	}

	private static function public_row( object $row ): array {
		$owner = (string) ( $row->display_name ?: 'Player ' . (int) $row->user_id );
		return array(
			'user_id' => (int) $row->user_id,
			'entry_id' => isset( $row->entry_id ) ? (int) $row->entry_id : 0,
			'player' => (string) ( $row->team_name ?: $owner ),
			'team_name' => (string) $row->team_name,
			'owner' => $owner,
			'points' => (int) $row->points,
			'scoring_picks' => (int) $row->scoring_picks,
			'rank' => (int) $row->rank_pos,
		);
	}
}

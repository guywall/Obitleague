<?php
/**
 * Human vs AI statistics.
 *
 * Analytical views over the shared standings: how many human and AI teams
 * are active, what each group has scored, and where the leaders sit. These
 * are descriptions of the field, not the official ranking — official
 * positions live only in the published standings generations. No statistic
 * here adjusts scoring; averages carry an explicit caveat because teams
 * joined at different dates with different floors.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Vs_Stats {

	private function __construct() {}

	/**
	 * The full comparison snapshot for a season.
	 *
	 * @return array{
	 *   season:int, humans:array, ai:array, overall:array,
	 *   leading_human:?array, leading_ai:?array, generated_at:string
	 * }
	 */
	public static function snapshot( int $season ): array {
		$cache_key = 'obitleague_vs_stats_' . $season;
		$cached = \get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['humans'] ) ) {
			return $cached;
		}

		$rows = Leaderboards::overall( $season, 100, 0 );

		$humans = array();
		$ai     = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_ai'] ) ) {
				$ai[] = $row;
			} else {
				$humans[] = $row;
			}
		}

		$group = static function ( array $group ): array {
			$points = array_sum( array_map( static fn ( array $r ): int => (int) $r['points'], $group ) );
			$count  = count( $group );
			return array(
				'teams'            => $count,
				'points'           => $points,
				'average_points'   => $count > 0 ? round( $points / $count, 1 ) : 0.0,
				'highest_team'     => $count > 0 ? (int) $group[0]['points'] : 0,
				// Group members are in overall rank order, so the first row
				// is the group's leader under the official ranking.
				'leader'           => $count > 0 ? array(
					'player'    => (string) $group[0]['player'],
					'points'    => (int) $group[0]['points'],
					'rank'      => (int) $group[0]['rank'],
					'entry_id'  => (int) $group[0]['entry_id'],
					'agent_slug'=> (string) ( $group[0]['agent_slug'] ?? '' ),
				) : null,
			);
		};

		$snapshot = array(
			'season'        => $season,
			'humans'        => $group( $humans ),
			'ai'            => $group( $ai ),
			'overall'       => array( 'teams' => count( $rows ) ),
			// Overall standings are rank-ordered, so row 0 is the champion.
			'leading_human' => $humans ? array(
				'player' => (string) $humans[0]['player'],
				'rank'   => (int) $humans[0]['rank'],
				'points' => (int) $humans[0]['points'],
			) : null,
			'leading_ai'    => $ai ? array(
				'player'    => (string) $ai[0]['player'],
				'rank'      => (int) $ai[0]['rank'],
				'points'    => (int) $ai[0]['points'],
				'agent_slug'=> (string) $ai[0]['agent_slug'],
			) : null,
			'generated_at'  => current_time( 'mysql', true ),
		);

		\set_transient( $cache_key, $snapshot, 5 * MINUTE_IN_SECONDS );
		return $snapshot;
	}

	/** Drop the cached snapshot (on submission, amendment or rebuild). */
	public static function flush( ?int $season = null ): void {
		if ( null !== $season ) {
			\delete_transient( 'obitleague_vs_stats_' . $season );
			return;
		}
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_obitleague_vs_stats_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	/**
	 * Caveat text shown beside averages: teams that joined later have played
	 * a shorter season with a later scoring floor, so group averages are a
	 * description of the field, not a verdict on predictive skill.
	 */
	public static function caveat(): string {
		return __(
			'Teams joined at different dates face different scoring windows, so averages describe the field rather than prove which side predicts better. Official positions come from the overall championship only.',
			'obitleague'
		);
	}
}

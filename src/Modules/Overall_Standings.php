<?php
/**
 * Overall (cross-league) standings.
 *
 * Aggregates every published league generation for a season into one global
 * ranking. Ruleset v1 has no cross-league tie-breaking beyond the league
 * rules, so overall rank uses the same competition-rank rule applied to
 * a player's best league score: points, then scoring picks, then shared
 * positions (1, 2, 2, 4). A player's best league counts once — this keeps
 * the overall table a "champions' table" across leagues rather than a
 * sum that rewards joining many leagues.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Overall_Standings {

	private function __construct() {}

	/**
	 * Overall rows for a season: rank, player, best score, scoring picks,
	 * leagues played and leading leagues. Empty array when nothing published.
	 *
	 * @return array[]|null
	 */
	public static function for_season( int $season, int $limit = 0 ): ?array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sr.user_id, sr.points, sr.scoring_picks, lg.id AS league_id, lg.name AS league_name
				 FROM {$wpdb->prefix}obitleague_standings_generations sg
				 JOIN {$wpdb->prefix}obitleague_standings_rows sr ON sr.generation_id = sg.id
				 JOIN {$wpdb->prefix}obitleague_leagues lg ON lg.id = sg.league_id
				 WHERE sg.season = %d AND sg.is_current = 1
				 ORDER BY sr.points DESC, sr.scoring_picks DESC",
				$season
			)
		);
		if ( ! $rows ) {
			return null;
		}

		// Best score per player across leagues; carry league context.
		$best = array();
		$played = array();
		$leads  = array();
		foreach ( (array) $rows as $row ) {
			$uid = (int) $row->user_id;
			$played[ $uid ][] = (string) $row->league_name;
			if ( ! isset( $best[ $uid ] ) || (int) $row->points > $best[ $uid ]['points'] ) {
				$best[ $uid ] = array(
					'points'        => (int) $row->points,
					'scoring_picks' => (int) $row->scoring_picks,
					'league_id'     => (int) $row->league_id,
					'league_name'   => (string) $row->league_name,
				);
			}
		}

		// League leaders (rank 1 holders) for the "leading" column.
		foreach ( (array) $rows as $row ) {
			if ( 1 === (int) $row->rank_pos ?? 0 ) {
				$leads[ (int) $row->user_id ][] = (string) $row->league_name;
			}
		}

		// Competition ranking over the best scores.
		$players = array();
		foreach ( $best as $uid => $data ) {
			$players[ $uid ] = $data['points'] . ':' . $data['scoring_picks'];
		}
		$scores = array_values( array_unique( array_values( $players ) ) );
		rsort( $scores, SORT_NUMERIC );
		// Sort strings "points:scoring" descending numerically by points then scoring.
		usort( $scores, static function ( $a, $b ) {
			[ $ap, $as ] = explode( ':', (string) $a );
			[ $bp, $bs ] = explode( ':', (string) $b );
			return ( (int) $bp === (int) $ap ) ? ( (int) $bs - (int) $as ) : ( (int) $bp - (int) $ap );
		} );
		$rank_of = array();
		$pos     = 0;
		foreach ( $scores as $i => $key ) {
			[ $p, $s ] = explode( ':', (string) $key );
			$p         = (int) $p;
			$s         = (int) $s;
			if ( 0 === $i || $p !== $prev_p || $s !== $prev_s ) {
				$pos = $i + 1;
			}
			$rank_of[ $key ] = $pos;
			$prev_p          = $p;
			$prev_s          = $s;
		}

		$names = self::display_names( array_keys( $best ) );

		$out = array();
		foreach ( $best as $uid => $data ) {
			$key = $data['points'] . ':' . $data['scoring_picks'];
			$out[] = array(
				'user_id'       => $uid,
				'player'        => $names[ $uid ] ?? ( 'Player ' . $uid ),
				'points'        => $data['points'],
				'scoring_picks' => $data['scoring_picks'],
				'rank'          => $rank_of[ $key ] ?? 0,
				'leagues'       => count( $played[ $uid ] ?? [] ),
				'league_names'  => array_slice( array_unique( $played[ $uid ] ?? [] ), 0, 3 ),
				'best_league'   => $data['league_name'],
				'leading'       => array_slice( array_unique( $leads[ $uid ] ?? [] ), 0, 2 ),
			);
		}

		usort( $out, static function ( $a, $b ) {
			return ( $a['points'] === $b['points'] )
				? ( $b['scoring_picks'] <=> $a['scoring_picks'] )
				: ( $b['points'] <=> $a['points'] );
		} );

		return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
	}

	/** Published leagues with current generations for a season. */
	public static function leagues_with_standings( int $season ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT lg.id, lg.name
				 FROM {$wpdb->prefix}obitleague_leagues lg
				 JOIN {$wpdb->prefix}obitleague_standings_generations sg ON sg.league_id = lg.id
				 WHERE sg.season = %d AND sg.is_current = 1
				 ORDER BY lg.name ASC",
				$season
			)
		);
	}

	/** Display names for user ids. */
	private static function display_names( array $uids ): array {
		if ( ! $uids ) {
			return array();
		}
		global $wpdb;
		$in  = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
		$sql = "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($in)";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_map( 'intval', $uids ) ) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->ID ] = (string) $row->display_name ?: ( 'Player ' . (int) $row->ID );
		}
		return $out;
	}
}

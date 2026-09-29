<?php
/**
 * All-player standings for the canonical main league. Optional side leagues
 * are independent competitions and never contribute to global rank.
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Overall_Standings {

	private function __construct() {}

	public static function main_league_id( int $season ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE is_main = 1 AND main_season_key = %d LIMIT 1', $season )
		);
	}

	/** One bounded page from the canonical main-season standings. */
	public static function for_season( int $season, int $limit = 50, int $offset = 0 ): ?array {
		global $wpdb;
		$league_id = self::main_league_id( $season );
		if ( ! $league_id ) {
			return null;
		}
		$generation_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_standings_generations WHERE league_id = %d AND season = %d AND is_current = 1 ORDER BY id DESC LIMIT 1', $league_id, $season )
		);
		if ( ! $generation_id ) {
			return null;
		}
		$limit = min( 100, max( 1, $limit ) );
		$offset = max( 0, $offset );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.user_id, r.points, r.scoring_picks, r.rank_pos, e.id AS entry_id, e.team_name, u.display_name
				 FROM ' . $wpdb->prefix . 'obitleague_standings_rows r
				 JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.main_season_key = %d AND e.user_id = r.user_id AND e.state = %s
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.user_id
				 WHERE r.generation_id = %d
				 ORDER BY r.rank_pos ASC, r.user_id ASC LIMIT %d OFFSET %d',
				$season,
				'submitted',
				$generation_id,
				$limit,
				$offset
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$owner = (string) ( $row->display_name ?: 'Player ' . (int) $row->user_id );
			$team = (string) $row->team_name;
			$league_name = 'Overall League ' . $season;
			$out[] = array(
				'user_id' => (int) $row->user_id,
				'entry_id' => (int) $row->entry_id,
				'player' => $team ?: $owner,
				'team_name' => $team,
				'owner' => $owner,
				'points' => (int) $row->points,
				'scoring_picks' => (int) $row->scoring_picks,
				'rank' => (int) $row->rank_pos,
				'leagues' => 1,
				'league_names' => array( $league_name ),
				'best_league' => $league_name,
				'leading' => 1 === (int) $row->rank_pos ? array( $league_name ) : array(),
			);
		}
		return $out;
	}

	public static function count_for_season( int $season ): int {
		$league_id = self::main_league_id( $season );
		return $league_id ? Standings_Service::count_current( $league_id, $season ) : 0;
	}

	/**
	 * Directory of every submitted main-season team, name-searchable.
	 * Standings order where a generation exists; alphabetical fallback for a
	 * season that has not published standings yet.
	 *
	 * @return array{rows:array,total:int}|null null when no main league exists.
	 */
	public static function team_directory( int $season, int $limit = 24, int $offset = 0, string $search = '' ): ?array {
		global $wpdb;
		$league_id = self::main_league_id( $season );
		if ( ! $league_id ) {
			return null;
		}
		$limit  = min( 100, max( 1, $limit ) );
		$offset = max( 0, $offset );
		$search = trim( $search );
		$where  = "e.league_id = %d AND e.season = %d AND e.state = 'submitted'";
		$params = array( $league_id, $season );
		if ( '' !== $search ) {
			$where .= ' AND (e.team_name LIKE %s OR u.display_name LIKE %s)';
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_entries e
				 LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
				 WHERE {$where}",
				...$params
			)
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id AS entry_id, e.team_name, e.user_id, u.display_name,
				        (SELECT r.points FROM {$wpdb->prefix}obitleague_standings_rows r
				          JOIN {$wpdb->prefix}obitleague_standings_generations g ON g.id = r.generation_id
				          WHERE g.league_id = e.league_id AND g.season = e.season AND g.is_current = 1 AND r.user_id = e.user_id LIMIT 1) AS points,
				        (SELECT r.rank_pos FROM {$wpdb->prefix}obitleague_standings_rows r
				          JOIN {$wpdb->prefix}obitleague_standings_generations g ON g.id = r.generation_id
				          WHERE g.league_id = e.league_id AND g.season = e.season AND g.is_current = 1 AND r.user_id = e.user_id LIMIT 1) AS rank_pos,
				        (SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_entry_picks p
				          JOIN {$wpdb->prefix}obitleague_entry_revisions rev ON rev.id = p.revision_id AND rev.kind = 'submitted' AND rev.entry_id = e.id) AS pick_count
				 FROM {$wpdb->prefix}obitleague_entries e
				 LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
				 WHERE {$where}
				 ORDER BY points DESC, e.id ASC LIMIT %d OFFSET %d",
				...array_merge( $params, array( $limit, $offset ) )
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$owner = (string) ( $row->display_name ?: 'Player ' . (int) $row->user_id );
			$out[] = array(
				'entry_id'   => (int) $row->entry_id,
				'team_name'  => (string) $row->team_name,
				'owner'      => $owner,
				'points'     => (int) $row->points,
				'rank'       => (int) $row->rank_pos,
				'pick_count' => (int) $row->pick_count,
			);
		}
		return array( 'rows' => $out, 'total' => $total );
	}

	/** REST-friendly pagination contract; page sizes are capped for bounded work. */
	public static function leaderboard_route( int $season, int $page = 1, int $per_page = 50 ): array {
		$page = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		return array(
			'rows' => self::for_season( $season, $per_page, ( $page - 1 ) * $per_page ) ?? array(),
			'page' => $page,
			'per_page' => $per_page,
			'total' => self::count_for_season( $season ),
		);
	}
}

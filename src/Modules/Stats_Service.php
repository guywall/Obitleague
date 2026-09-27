<?php
/**
 * Statistics service.
 *
 * Read-only aggregates for the public stats boards: pick popularity,
 * highest-scoring teams, teams on a scoring streak, and the season's
 * scoring timeline. All queries key off published standings and the
 * append-only awards ledger; nothing here mutates state.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Stats_Service {

	private function __construct() {}

	/**
	 * Most and least picked people among submitted teams (season scope).
	 *
	 * @return array[] name, url, image, picks, points_won, is_dead
	 */
	public static function pick_popularity( int $season, int $limit = 8 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.person_uuid, COUNT(*) AS picks,
				        COALESCE(SUM(a.delta), 0) AS points_won
				 FROM {$wpdb->prefix}obitleague_entry_picks p
				 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = r.entry_id AND e.season = %d AND e.state = 'submitted'
				 LEFT JOIN (
				     SELECT pick_slug, entry_id, SUM(award_delta) AS delta
				     FROM {$wpdb->prefix}obitleague_awards WHERE season = %d
				     GROUP BY pick_slug, entry_id
				 ) a ON a.pick_slug = p.person_uuid AND a.entry_id = e.id
				 GROUP BY p.person_uuid
				 ORDER BY picks DESC, points_won DESC
				 LIMIT %d",
				$season,
				$season,
				$limit
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$card  = League_View_Service::person_card_by_uuid( (string) $row->person_uuid );
			$awarded = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards WHERE pick_slug = %s AND season = %d AND award_delta > 0',
					(string) $row->person_uuid,
					$season
				)
			);
			$out[] = array(
				'name'       => $card['name'],
				'url'        => $card['url'],
				'image'      => $card['image'],
				'role'       => $card['role'],
				'picks'      => (int) $row->picks,
				'points_won' => $awarded,
				'is_dead'    => $card['is_dead'],
			);
		}
		return $out;
	}

	/**
	 * Highest-scoring submitted teams across all leagues.
	 *
	 * @return array[] entry_id, league, player, points, scoring_picks
	 */
	public static function top_teams( int $season, int $limit = 10 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sr.user_id, sr.points, sr.scoring_picks, sg.league_id, lg.name AS league_name,
				        e.id AS entry_id, u.display_name
				 FROM {$wpdb->prefix}obitleague_standings_generations sg
				 JOIN {$wpdb->prefix}obitleague_standings_rows sr ON sr.generation_id = sg.id
				 JOIN {$wpdb->prefix}obitleague_leagues lg ON lg.id = sg.league_id
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.user_id = sr.user_id AND e.league_id = sg.league_id
				       AND e.season = sg.season AND e.state = 'submitted'
				 JOIN {$wpdb->users} u ON u.ID = sr.user_id
				 WHERE sg.season = %d AND sg.is_current = 1
				 ORDER BY sr.points DESC, sr.scoring_picks DESC
				 LIMIT %d",
				$season,
				$limit
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'entry_id'      => (int) $row->entry_id,
				'league_id'     => (int) $row->league_id,
				'league'        => (string) $row->league_name,
				'player'        => (string) ( $row->display_name ?: ( 'Player ' . $row->user_id ) ),
				'points'        => (int) $row->points,
				'scoring_picks' => (int) $row->scoring_picks,
			);
		}
		return $out;
	}

	/**
	 * Teams scoring in close succession: entries whose positive awards
	 * cluster inside a short window (any 14-day span holding 2+ events).
	 * Returns the strongest streak per team.
	 *
	 * @return array[] entry_id, player, league, streak, last_scored, points_in_streak
	 */
	public static function streaking_teams( int $season, int $window_days = 14, int $limit = 8 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.entry_id, a.created_at, a.award_delta,
				        e.user_id, e.league_id, lg.name AS league_name, u.display_name
				 FROM {$wpdb->prefix}obitleague_awards a
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = a.entry_id AND e.season = %d
				 JOIN {$wpdb->prefix}obitleague_leagues lg ON lg.id = e.league_id
				 JOIN {$wpdb->users} u ON u.ID = e.user_id
				 WHERE a.season = %d AND a.award_delta > 0
				 ORDER BY a.entry_id ASC, a.created_at ASC",
				$season,
				$season
			)
		);

		$by_entry = array();
		foreach ( (array) $rows as $row ) {
			$by_entry[ (int) $row->entry_id ][] = $row;
		}

		$out = array();
		foreach ( $by_entry as $entry_id => $events ) {
			$best          = array( 'count' => 0, 'points' => 0, 'last' => '' );
			$window_seconds = $window_days * DAY_IN_SECONDS;
			for ( $i = 0, $n = count( $events ); $i < $n; $i++ ) {
				$start  = strtotime( (string) $events[ $i ]->created_at );
				$count  = 0;
				$points = 0;
				for ( $j = $i; $j < $n; $j++ ) {
					if ( strtotime( (string) $events[ $j ]->created_at ) - $start > $window_seconds ) {
						break;
					}
					++$count;
					$points += (int) $events[ $j ]->award_delta;
				}
				if ( $count > $best['count'] || ( $count === $best['count'] && $points > $best['points'] ) ) {
					$best = array(
						'count'  => $count,
						'points' => $points,
						'last'   => (string) $events[ $count + $i - 1 ]->created_at,
					);
				}
			}
			if ( $best['count'] < 2 ) {
				continue; // Streaks need two or more events in the window.
			}
			$first = $events[0];
			$out[] = array(
				'entry_id'        => $entry_id,
				'player'          => (string) ( $first->display_name ?: ( 'Player ' . $first->user_id ) ),
				'league'          => (string) $first->league_name,
				'streak'          => $best['count'],
				'points_in_streak' => $best['points'],
				'last_scored'     => mysql2date( 'j M Y', $best['last'] ),
			);
		}

		usort( $out, static function ( $a, $b ) {
			return ( $b['streak'] <=> $a['streak'] ) ?: ( $b['points_in_streak'] <=> $a['points_in_streak'] );
		} );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Season scoring timeline: months with counts of scoring events and
	 * total points awarded.
	 *
	 * @return array[] month_label, events, points
	 */
	public static function timeline( int $season ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(a.created_at, '%Y-%m') AS ym, COUNT(*) AS events, SUM(a.award_delta) AS points
				 FROM {$wpdb->prefix}obitleague_awards a
				 WHERE a.season = %d AND a.award_delta > 0
				 GROUP BY ym ORDER BY ym ASC",
				$season
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'month_label' => mysql2date( 'F Y', $row->ym . '-01 00:00:00' ),
				'events'      => (int) $row->events,
				'points'      => (int) $row->points,
			);
		}
		return $out;
	}
}

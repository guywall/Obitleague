<?php
/**
 * Leaderboard views for Humans vs AI.
 *
 * These are projections over the canonical main-league standings — the same
 * rows, the same ranks, the same scoring engine. The Overall view is the
 * existing `Overall_Standings::for_season()` untouched; every filter here
 * only hides rows or regroups them. No alternative scoring system exists.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Leaderboards {

	private function __construct() {}

	/**
	 * Agent metadata for the competitor accounts of one season's standings,
	 * keyed by user_id. One query for a whole page of rows.
	 *
	 * @return array<int, object>
	 */
	public static function agents_for_users( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . "obitleague_agents WHERE user_id IN ({$placeholders})",
				...$user_ids
			)
		);
		$by_user = array();
		foreach ( (array) $rows as $row ) {
			$by_user[ (int) $row->user_id ] = $row;
		}
		return $by_user;
	}

	/**
	 * The Overall Championship: every eligible participant in one ranking.
	 * Each row carries `is_ai`, the agent slug (for profiles) and the
	 * entry's submission date for late-entry views.
	 *
	 * @return array[] Same shape as Overall_Standings::for_season() plus agent fields.
	 */
	public static function overall( int $season, int $limit = 50, int $offset = 0 ): array {
		$rows = Overall_Standings::for_season( $season, $limit, $offset );
		if ( null === $rows ) {
			return array();
		}
		$agents = self::agents_for_users( array_column( $rows, 'user_id' ) );
		$submitted = self::submitted_at_map( $season, array_column( $rows, 'entry_id' ) );
		foreach ( $rows as &$row ) {
			$agent = $agents[ (int) $row['user_id'] ] ?? null;
			$row['is_ai']       = null !== $agent;
			$row['agent_slug']  = $agent ? (string) $agent->slug : '';
			$row['agent_model'] = $agent ? (string) $agent->model : '';
			$row['submitted_at'] = $submitted[ (int) $row['entry_id'] ] ?? '';
		}
		unset( $row );
		return $rows;
	}

	/** Human Championship: overall rows minus AI competitors, ranks preserved. */
	public static function humans( int $season, int $limit = 50, int $offset = 0 ): array {
		// Over-fetch modestly so a filtered page is still a full page.
		$rows = self::overall( $season, min( 100, $limit + 25 ), $offset );
		return array_values( array_filter( $rows, static fn ( array $row ): bool => ! $row['is_ai'] ) );
	}

	/** AI Championship: overall rows for AI competitors only, ranks preserved. */
	public static function ai( int $season, int $limit = 50, int $offset = 0 ): array {
		$rows = self::overall( $season, min( 100, $limit + 25 ), $offset );
		return array_values( array_filter( $rows, static fn ( array $row ): bool => $row['is_ai'] ) );
	}

	/**
	 * Model Championship: results grouped by the AI model behind each agent.
	 *
	 * This is an editorial grouping, not a controlled evaluation: two agents
	 * on the same model differ in configuration, research effort and
	 * selection strategy. Rows are aggregated with the same
	 * competition-ranking semantics (total points, then scoring picks).
	 *
	 * @return array<int, array{model:string, teams:int, points:int, scoring_picks:int, rank:int}>
	 */
	public static function models( int $season ): array {
		global $wpdb;
		$agents = $wpdb->prefix . 'obitleague_agents';
		$entries = $wpdb->prefix . 'obitleague_entries';
		$revisions = $wpdb->prefix . 'obitleague_entry_revisions';
		$picks = $wpdb->prefix . 'obitleague_entry_picks';
		$awards = $wpdb->prefix . 'obitleague_awards';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.model AS model,
					COUNT(DISTINCT e.id) AS teams,
					COALESCE(SUM(CASE WHEN ap.points > 0 THEN ap.points ELSE 0 END), 0) AS points,
					COALESCE(SUM(CASE WHEN ap.points > 0 THEN 1 ELSE 0 END), 0) AS scoring_picks
				FROM {$agents} a
				JOIN {$entries} e ON e.user_id = a.user_id AND e.season = %d AND e.state = 'submitted'
				JOIN {$revisions} r ON r.entry_id = e.id AND r.kind = 'submitted'
				JOIN {$picks} p ON p.revision_id = r.id
				LEFT JOIN (
					SELECT entry_id, pick_slug, SUM(award_delta) AS points
					FROM {$awards} WHERE season = %d GROUP BY entry_id, pick_slug
				) ap ON ap.entry_id = e.id AND ap.pick_slug = p.person_uuid
				WHERE a.status = 'active' AND a.model <> ''
				GROUP BY a.model
				ORDER BY points DESC, scoring_picks DESC, a.model ASC",
				$season,
				$season
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'model'         => (string) $row->model,
				'teams'         => (int) $row->teams,
				'points'        => (int) $row->points,
				'scoring_picks' => (int) $row->scoring_picks,
				'rank'          => 0,
			);
		}
		// Competition ranking over model aggregates (1, 2, 2, 4).
		$rank = 0;
		$seen = 0;
		$prev = null;
		foreach ( $out as $i => $row ) {
			++$seen;
			$key = $row['points'] . ':' . $row['scoring_picks'];
			if ( null === $prev || $key !== $prev ) {
				$rank = $seen;
				$prev = $key;
			}
			$out[ $i ]['rank'] = $rank;
		}
		return $out;
	}

	/**
	 * Late Entry Challenge: teams ordered by entry month, each carrying its
	 * overall position. A team's overall rank is never affected here — this
	 * is a spotlight, not a rescoring.
	 *
	 * @return array<int, array{month:string, month_label:string, teams:int, best_points:int, best_overall_rank:int, best_team:string, best_entry_id:int}>
	 */
	public static function late_entries( int $season ): array {
		$rows = self::overall( $season, 100, 0 );
		$by_month = array();
		foreach ( $rows as $row ) {
			$submitted = (string) ( $row['submitted_at'] ?? '' );
			if ( '' === $submitted ) {
				continue;
			}
			$month = substr( $submitted, 0, 7 );
			if ( ! isset( $by_month[ $month ] ) || $row['points'] > $by_month[ $month ]['best_points'] ) {
				$by_month[ $month ] = array(
					'month'             => $month,
					'month_label'       => \date_i18n( 'F Y', (int) \mktime( 0, 0, 0, (int) substr( $month, 5, 2 ), 1, (int) substr( $month, 0, 4 ) ) ),
					'teams'             => 0,
					'best_points'       => (int) $row['points'],
					'best_overall_rank' => (int) $row['rank'],
					'best_team'         => (string) $row['player'],
					'best_entry_id'     => (int) $row['entry_id'],
					'best_is_ai'        => (bool) $row['is_ai'],
				);
			}
		}
		// Count teams per month in a second pass (best row first pass only
		// keeps one row per month).
		foreach ( $rows as $row ) {
			$submitted = (string) ( $row['submitted_at'] ?? '' );
			$month = '' !== $submitted ? substr( $submitted, 0, 7 ) : '';
			if ( isset( $by_month[ $month ] ) ) {
				++$by_month[ $month ]['teams'];
			}
		}
		ksort( $by_month );
		return array_values( $by_month );
	}

	/**
	 * Submission-instant per entry (UTC), for late-entry views and profile
	 * display. Reads the competing revision's recorded submitted_at.
	 *
	 * @return array<int, string> entry_id => Y-m-d H:i:s
	 */
	private static function submitted_at_map( int $season, array $entry_ids ): array {
		$entry_ids = array_values( array_unique( array_filter( array_map( 'intval', $entry_ids ) ) ) );
		if ( ! $entry_ids ) {
			return array();
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.entry_id, r.submitted_at
				 FROM {$wpdb->prefix}obitleague_entry_revisions r
				 JOIN (SELECT entry_id, MAX(id) AS revision_id FROM {$wpdb->prefix}obitleague_entry_revisions WHERE kind = 'submitted' AND entry_id IN ({$placeholders}) GROUP BY entry_id) latest
				   ON latest.revision_id = r.id",
				...$entry_ids
			)
		);
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->entry_id ] = (string) $row->submitted_at;
		}
		return $map;
	}
}

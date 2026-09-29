<?php
/**
 * Pick statistics for a person record.
 *
 * How often a name is actually taken is the most interesting thing about a
 * dead pool: it is the difference between an obvious pick and one person quietly
 * took. This module answers it for one person — how many submitted teams have
 * them, what share of the field that is, whether they are one of the most
 * popular names on offer, and who took them.
 *
 * Only submitted entries are counted, and only the current submitted revision
 * of each. A team that amends its picks supersedes its old revision, so
 * counting every revision would inflate totals and could count the same team
 * twice.
 *
 * Figures are scoped to a single season. A user can hold an entry in more than
 * one season, and pooling them would count that player as several teams and
 * make the share meaningless.
 *
 * The ranking aggregate is far too slow to run per page view (a few hundred
 * milliseconds over a large field), so the whole distribution is computed once
 * and cached. Reads are then an option lookup plus one indexed count.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Pick_Stats {

	/** A person inside this many most-picked names is a "hot pick". */
	public const HOT_LIMIT = 50;

	/** Teams listed by name on a person page before collapsing to a count. */
	public const TEAMS_SHOWN = 10;

	/** Leagues summarised before the rest collapse into a count. */
	public const LEAGUES_SHOWN = 6;

	/**
	 * How to write a share for display.
	 *
	 * A single taker in a large field is a real, small number — one pick in a
	 * few thousand rounds to 0.0%, which would read as "nobody took them" and
	 * contradict the figure right next to it. Below half a percent is shown as
	 * "less than 1%" instead.
	 */
	public static function percent_label( float $percent ): string {
		if ( $percent > 0 && $percent < 0.5 ) {
			return '<1%';
		}
		return number_format_i18n( $percent, 1 ) . '%';
	}

	/**
	 * Ordinal suffix for a rank: 1st, 2nd, 3rd, 4th … 11th, 21st.
	 *
	 * English only, and deliberately so: this plugin ships no translation for
	 * its editorial copy, and guessing at other languages' ordinal rules would
	 * be worse than leaving the number undecorated.
	 */
	public static function ordinal( int $n ): string {
		// The last two digits decide the suffix (11th, 12th, 13th), but the
		// number rendered is the original — a 121st is not a 21st.
		$tail   = abs( $n ) % 100;
		$suffix = ( $tail >= 11 && $tail <= 13 )
			? 'th'
			: match ( $tail % 10 ) {
				1 => 'st',
				2 => 'nd',
				3 => 'rd',
				default => 'th',
			};
		return $n . $suffix;
	}

	/** Seconds the cached distribution stays valid. A safety net, not the strategy. */
	private const CACHE_TTL = 300;

	/**
	 * Upper bound on cached rows, so a very large catalogue cannot grow an
	 * unbounded option. People beyond it still get a count and a share, just no
	 * rank.
	 */
	private const CACHE_CAP = 10000;

	private const CACHE_PREFIX = 'obitleague_pick_stats_';

	private function __construct() {}

	/**
	 * The season whose picks are being counted: the one in play.
	 *
	 * Single-sourced so a page can never label its figures with one season and
	 * its scorecard with another.
	 */
	public static function season_in_play(): int {
		return (int) date_i18n( 'Y' );
	}

	/**
	 * Pick statistics for one person record.
	 *
	 * @return array{season:int,picks:int,teams_total:int,percent:float,rank:?int,is_hot:bool,is_unique:bool,leagues:array,teams:array,teams_remaining:int}
	 */
	public static function for_person( int $post_id ): array {
		$uuid = (string) get_post_meta( $post_id, 'obit_uuid', true );
		$season = self::season_in_play();

		$distribution = self::distribution( $season );
		$teams_total  = (int) ( $distribution['teams_total'] ?? 0 );

		// A person outside the cached cap still needs a real count.
		$picks = array_key_exists( $uuid, $distribution['counts'] )
			? (int) $distribution['counts'][ $uuid ]
			: ( '' !== $uuid ? self::count_picks( $uuid, $season ) : 0 );

		$rank = null;
		if ( array_key_exists( $uuid, $distribution['ranks'] ) ) {
			$rank = (int) $distribution['ranks'][ $uuid ];
		}

		$empty = array(
			'season'          => $season,
			'picks'           => $picks,
			'teams_total'     => $teams_total,
			'percent'         => 0.0,
			'rank'            => $rank,
			'is_hot'          => false,
			'is_unique'       => false,
			'leagues'         => array(),
			'teams'           => array(),
			'teams_remaining' => 0,
		);
		if ( '' === $uuid || $picks < 1 ) {
			return $empty;
		}

		/*
		 * Rolling-entry privacy: while a season's entry window is open its
		 * teams stay amendable, so the list of which teams picked a person is
		 * withheld (the aggregate count above stays public). The deadline
		 * policy keeps v1 seasons on their 1 January instant.
		 */
		$window_closed = ! \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		$teams = $window_closed ? self::picking_teams( $uuid, $season ) : array();

		return array(
			'season'          => $season,
			'picks'           => $picks,
			'teams_total'     => $teams_total,
			'percent'         => $teams_total > 0 ? round( 100 * $picks / $teams_total, 3 ) : 0.0,
			'rank'            => $rank,
			'is_hot'          => null !== $rank && $rank <= self::HOT_LIMIT,
			// A lone taker is the rarest possible outcome, and the badge is only
			// meaningful if it is genuinely unique.
			'is_unique'       => 1 === $picks,
			'leagues'         => self::league_breakdown( $uuid, $season ),
			'teams'           => $teams,
			'teams_remaining' => max( 0, $picks - count( $teams ) ),
		);
	}

	/**
	 * Drop the cached distribution for a season (or all of them).
	 *
	 * Called whenever an entry is submitted or amended, so figures never
	 * disagree with the standings by more than the cache TTL.
	 */
	public static function flush( ?int $season = null ): void {
		if ( null !== $season ) {
			delete_transient( self::CACHE_PREFIX . $season );
			return;
		}
		global $wpdb;
		// Transient names are stored in wp_options under _transient_<name>.
		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}

	/* ---------- distribution cache ---------- */

	/**
	 * The full pick distribution for a season, ordered most-picked first.
	 *
	 * @return array{teams_total:int,counts:array<string,int>,ranks:array<string,int>,truncated:bool}
	 */
	private static function distribution( int $season ): array {
		$key    = self::CACHE_PREFIX . $season;
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['counts'] ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.person_uuid AS uuid, COUNT(DISTINCT r.entry_id) AS picks
				 FROM ' . $wpdb->prefix . 'obitleague_entry_picks p
				 JOIN ' . $wpdb->prefix . 'obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = %s
				 JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id AND e.state = %s AND e.season = %d
				 GROUP BY p.person_uuid
				 HAVING picks > 0
				 ORDER BY picks DESC, p.person_uuid ASC
				 LIMIT %d',
				'submitted',
				'submitted',
				$season,
				self::CACHE_CAP
			)
		);

		$counts = array();
		$ranks  = array();
		$rank   = 0;
		foreach ( (array) $rows as $row ) {
			$uuid = (string) $row->uuid;
			++$rank;
			$counts[ $uuid ] = (int) $row->picks;
			$ranks[ $uuid ]  = $rank;
		}

		$distribution = array(
			'teams_total' => (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_entries WHERE state = %s AND season = %d',
					'submitted',
					$season
				)
			),
			'counts'      => $counts,
			'ranks'       => $ranks,
			'truncated'   => count( (array) $rows ) >= self::CACHE_CAP,
		);

		set_transient( $key, $distribution, self::CACHE_TTL );
		return $distribution;
	}

	/** Submitted teams in this season holding this person. */
	/** Picks per uuid for one season; '' => not counted. Used by browse sorting. */
	public static function pick_counts_by_uuid( int $season, int $cap = 5000 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.person_uuid AS uuid, COUNT(DISTINCT r.entry_id) AS picks
				 FROM ' . $wpdb->prefix . 'obitleague_entry_picks p
				 JOIN ' . $wpdb->prefix . 'obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = %s
				 JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id AND e.state = %s AND e.season = %d
				 GROUP BY p.person_uuid
				 HAVING picks > 0
				 ORDER BY picks DESC, p.person_uuid ASC
				 LIMIT %d',
				'submitted',
				'submitted',
				$season,
				$cap
			)
		);
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->uuid ] = (int) $row->picks;
		}
		return $counts;
	}

	private static function count_picks( string $uuid, int $season ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT r.entry_id)
				 FROM ' . $wpdb->prefix . 'obitleague_entry_picks p
				 JOIN ' . $wpdb->prefix . 'obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = %s
				 JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id AND e.state = %s AND e.season = %d
				 WHERE p.person_uuid = %s',
				'submitted',
				'submitted',
				$season,
				$uuid
			)
		);
	}

	/* ---------- breakdowns ---------- */

	/**
	 * How the takers split across leagues, most-populated first.
	 *
	 * A name can be in the main league and several private side leagues, and
	 * without this the totals read as one undifferentiated field.
	 *
	 * @return array<int,array{league_id:int,league_name:string,is_main:int,picks:int}>
	 */
	private static function league_breakdown( string $uuid, int $season ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id AS league_id, l.name AS league_name, l.is_main, COUNT(DISTINCT r.entry_id) AS picks
				 FROM ' . $wpdb->prefix . 'obitleague_entry_picks p
				 JOIN ' . $wpdb->prefix . 'obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = %s
				 JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id AND e.state = %s AND e.season = %d
				 JOIN ' . $wpdb->prefix . 'obitleague_leagues l ON l.id = e.league_id
				 WHERE p.person_uuid = %s
				 GROUP BY l.id, l.name, l.is_main
				 ORDER BY picks DESC, l.id ASC',
				'submitted',
				'submitted',
				$season,
				$uuid
			)
		);

		$leagues = array();
		foreach ( array_slice( (array) $rows, 0, self::LEAGUES_SHOWN ) as $row ) {
			$leagues[] = array(
				'league_id'   => (int) $row->league_id,
				'league_name' => (string) $row->league_name,
				'is_main'     => (int) $row->is_main,
				'picks'       => (int) $row->picks,
			);
		}
		return $leagues;
	}

	/**
	 * Sample of the teams that took this person, most relevant first.
	 *
	 * Team names only, never the account holder: the ruleset makes picks public
	 * after lock, but a public page naming which player picked whom is a
	 * disclosure the game does not need to make.
	 *
	 * @return array<int,array{entry_id:int,team_name:string,league_name:string,is_main:int}>
	 */
	private static function picking_teams( string $uuid, int $season ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.id AS entry_id, e.team_name, l.name AS league_name, l.is_main
				 FROM ' . $wpdb->prefix . 'obitleague_entry_picks p
				 JOIN ' . $wpdb->prefix . 'obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = %s
				 JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id AND e.state = %s AND e.season = %d
				 JOIN ' . $wpdb->prefix . 'obitleague_leagues l ON l.id = e.league_id
				 WHERE p.person_uuid = %s
				 ORDER BY l.is_main DESC, e.id ASC
				 LIMIT %d',
				'submitted',
				'submitted',
				$season,
				$uuid,
				self::TEAMS_SHOWN
			)
		);

		$teams = array();
		foreach ( (array) $rows as $row ) {
			$teams[] = array(
				'entry_id'   => (int) $row->entry_id,
				'team_name'  => (string) $row->team_name,
				'league_name' => (string) $row->league_name,
				'is_main'    => (int) $row->is_main,
			);
		}
		return $teams;
	}
}

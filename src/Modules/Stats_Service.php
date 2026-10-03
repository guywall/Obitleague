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

		// Test accounts and hidden leagues are withheld from the public boards.
		$scope = Public_Scope::test_user_exclusion( 'e.user_id' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.person_uuid, COUNT(*) AS picks,
				        COALESCE(SUM(a.delta), 0) AS points_won
				 FROM {$wpdb->prefix}obitleague_entry_picks p
				 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = r.entry_id AND e.season = %d AND e.state = 'submitted'" . $scope . "
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
					'SELECT COALESCE(SUM(aw.award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards aw'
					. ' JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = aw.entry_id'
					. ' WHERE aw.pick_slug = %s AND aw.season = %d AND aw.award_delta > 0' . $scope,
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
				        e.id AS entry_id, e.team_name, u.display_name
				 FROM {$wpdb->prefix}obitleague_standings_generations sg
				 JOIN {$wpdb->prefix}obitleague_standings_rows sr ON sr.generation_id = sg.id
				 JOIN {$wpdb->prefix}obitleague_leagues lg ON lg.id = sg.league_id
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.user_id = sr.user_id AND e.league_id = sg.league_id
				       AND e.season = sg.season AND e.state = 'submitted'
				 JOIN {$wpdb->users} u ON u.ID = sr.user_id
				 WHERE sg.season = %d AND sg.is_current = 1" . Public_Scope::test_user_exclusion( 'sr.user_id' ) . Public_Scope::hidden_league_exclusion( 'lg' ) . "
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
				'player'        => (string) ( $row->team_name ?: ( $row->display_name ?: ( 'Player ' . $row->user_id ) ) ),
				'owner'         => (string) ( $row->display_name ?: ( 'Player ' . $row->user_id ) ),
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
				        e.user_id, e.league_id, e.team_name, lg.name AS league_name, u.display_name
				 FROM {$wpdb->prefix}obitleague_awards a
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = a.entry_id AND e.season = %d
				 JOIN {$wpdb->prefix}obitleague_leagues lg ON lg.id = e.league_id
				 JOIN {$wpdb->users} u ON u.ID = e.user_id
				 WHERE a.season = %d AND a.award_delta > 0" . Public_Scope::test_user_exclusion( 'e.user_id' ) . Public_Scope::hidden_league_exclusion( 'lg' ) . "
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
				'player'          => (string) ( $first->team_name ?: ( $first->display_name ?: ( 'Player ' . $first->user_id ) ) ),
				'owner'           => (string) ( $first->display_name ?: ( 'Player ' . $first->user_id ) ),
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
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = a.entry_id
				 WHERE a.season = %d AND a.award_delta > 0" . Public_Scope::test_user_exclusion( 'e.user_id' ) . "
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

	/* =====================================================
	 * Commonality analysis of the deceased.
	 *
	 * Every board below reads the same cached payload: the published
	 * people with an exact death date. Nothing here mutates state.
	 * ===================================================== */

	/** Transient key for the deceased-profile payload. */
	private const DECEASED_CACHE = 'obit_deceased_profile_v1';

	/** Cached raw profile rows for every published deceased person. */
	private static function deceased_rows(): array {
		$cached = get_transient( self::DECEASED_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$post_ids = $wpdb->get_col(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} dm ON dm.post_id = p.ID AND dm.meta_key = 'obit_death_date' AND dm.meta_value <> ''
			 WHERE p.post_type = 'obit_person' AND p.post_status = 'publish'
			 ORDER BY p.ID ASC"
		);

		$rows = array();
		foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
			$death_raw = (string) get_post_meta( $post_id, 'obit_death_date', true );
			$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
			try {
				$death = Import_Service::parse_partial( $death_raw );
			} catch ( \InvalidArgumentException ) {
				continue; // Malformed record: leave it out rather than guess.
			}
			if ( ! $death->is_exact() ) {
				continue; // Analysis uses exact dates only.
			}
			$death_date = $death->interpretations()[0] ?? null;
			if ( null === $death_date ) {
				continue;
			}
			try {
				$birth = '' !== $birth_raw ? Import_Service::parse_partial( $birth_raw ) : null;
			} catch ( \InvalidArgumentException ) {
				$birth = null;
			}
			$age = null;
			if ( $birth && $birth->is_exact() ) {
				try {
					$age = \Obitleague\Domain\Age::completed_at( $birth, $death_date );
				} catch ( \InvalidArgumentException ) {
					$age = null;
				}
			}

			$name = (string) get_the_title( $post_id );
			$row  = array(
				'name'       => $name,
				'url'        => (string) get_permalink( $post_id ),
				'birth_year' => ( $birth ? $birth->year : null ),
				'age'        => $age,
			);

			// Name shape: first initial, surname initial, letter counts.
			$parts   = preg_split( '/\s+/', trim( $name ) ) ?: array();
			$parts   = array_values( array_filter( $parts, static fn ( $p ) => '' !== $p ) );
			$letters = (int) preg_match_all( '/\p{L}/u', $name );
			$row['first_initial'] = $parts ? mb_strtoupper( mb_substr( (string) $parts[0], 0, 1 ) ) : '';
			$row['last_initial']  = count( $parts ) > 1 ? mb_strtoupper( mb_substr( (string) $parts[ count( $parts ) - 1 ], 0, 1 ) ) : '';
			$row['name_letters']  = $letters;

			// Birth shape: month, weekday, zodiac.
			if ( $birth && $birth->is_exact() && ( $b = ( $birth->interpretations()[0] ?? null ) ) ) {
				$row['birth_month']   = (int) $b->format( 'n' );
				$row['birth_weekday'] = (int) $b->format( 'N' ); // 1=Mon…7=Sun.
				$zodiac               = self::zodiac( (int) $b->format( 'n' ), (int) $b->format( 'j' ) );
				$row['zodiac']        = $zodiac;
			} else {
				$row['birth_month'] = null;
				$row['birth_weekday'] = null;
				$row['zodiac'] = null;
			}

			// Occupations: taxonomy terms (label, link) for grouping.
			$row['occupations'] = array();
			$terms = get_the_terms( $post_id, Catalogue::TAX_OCCUPATION );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$row['occupations'][ $term->name ] = $term->term_id;
				}
			}

			// Cause of death status, where approved.
			$row['cause'] = (string) get_post_meta( $post_id, 'obit_cause_status', true );

			$rows[] = $row;
		}

		set_transient( self::DECEASED_CACHE, $rows, 6 * HOUR_IN_SECONDS );
		return $rows;
	}

	/** Flush the deceased-profile cache (called when death records change). */
	public static function flush_deceased_cache(): void {
		delete_transient( self::DECEASED_CACHE );
	}

	/** Zodiac sign for a calendar month/day. */
	public static function zodiac( int $month, int $day ): ?string {
		// Each sign runs up to and including its end month/day.
		$ranges = array(
			array( 1, 19, 'Capricorn' ),
			array( 2, 18, 'Aquarius' ),
			array( 3, 20, 'Pisces' ),
			array( 4, 19, 'Aries' ),
			array( 5, 20, 'Taurus' ),
			array( 6, 20, 'Gemini' ),
			array( 7, 22, 'Cancer' ),
			array( 8, 22, 'Leo' ),
			array( 9, 22, 'Virgo' ),
			array( 10, 22, 'Libra' ),
			array( 11, 21, 'Scorpio' ),
			array( 12, 21, 'Sagittarius' ),
			array( 12, 31, 'Capricorn' ),
		);
		foreach ( $ranges as [ $m, $d, $sign ] ) {
			if ( $month === $m && $day <= $d ) {
				return $sign;
			}
		}
		return null;
	}

	/** Frequency board: value → count from one row field. */
	private static function board( array $rows, callable $extract, int $limit = 8 ): array {
		$counts = array();
		foreach ( $rows as $row ) {
			$value = $extract( $row );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$key = (string) $value;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
		arsort( $counts );
		$counts = array_slice( $counts, 0, $limit, true );
		$total  = max( 1, count( $rows ) );
		$out = array();
		foreach ( $counts as $value => $count ) {
			$out[] = array(
				// 'label' is the board row contract shared with the
				// occupations board and consumed by stats.php. Emitting
				// 'value' here left every other board's label undefined.
				'label'  => $value,
				'value'  => $value,
				'count'  => $count,
				'share'  => (int) round( 100 * $count / $total ),
			);
		}
		return $out;
	}

	/**
	 * Full deceased-profile payload: a dozen boards across occupations,
	 * birth timing, name shape and ages. Boards with too little signal
	 * are dropped; the template renders whatever survives.
	 *
	 * @return array{people:int, boards:array[]}
	 */
	public static function deceased_profile(): array {
		$rows   = self::deceased_rows();
		$people = count( $rows );
		if ( $people < 3 ) {
			return array( 'people' => $people, 'boards' => array() );
		}

		$months  = array( 1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December' );
		$weekdays = array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' );

		$boards = array();

		// Occupations: per-term counts, linked to the occupation archive.
		$occ_counts   = array();
		$occ_term_ids = array();
		foreach ( $rows as $row ) {
			foreach ( $row['occupations'] as $label => $term_id ) {
				$occ_counts[ $label ] = ( $occ_counts[ $label ] ?? 0 ) + 1;
				$occ_term_ids[ $label ] = (int) $term_id;
			}
		}
		arsort( $occ_counts );
		$occ_top = array_slice( $occ_counts, 0, 8, true );
		if ( $occ_top ) {
			$board = array( 'title' => 'Occupations of the deceased', 'note' => 'Taxonomy groups — every figure carries these occupation tags.', 'style' => 'bar', 'rows' => array() );
			foreach ( $occ_top as $label => $count ) {
				$link = get_term_link( (int) $occ_term_ids[ $label ], Catalogue::TAX_OCCUPATION );
				$href = ( $link && ! is_wp_error( $link ) ) ? (string) $link : '';
				$board['rows'][] = array( 'label' => $label, 'count' => $count, 'share' => (int) round( 100 * $count / $people ), 'url' => $href );
			}
			$boards[] = $board;
		}

		// Birth decades.
		$decade_rows = self::board( $rows, static fn ( $r ) => $r['birth_year'] ? (string) (int) floor( (int) $r['birth_year'] / 10 ) * 10 . 's' : null, 6 );
		if ( count( $decade_rows ) > 1 ) {
			$boards[] = array( 'title' => 'Born in the', 'note' => 'Birth decades of everyone scored this archive.', 'style' => 'bar', 'rows' => $decade_rows );
		}

		// Birth months.
		$month_rows = self::board( $rows, static fn ( $r ) => null !== $r['birth_month'] ? $months[ (int) $r['birth_month'] ] : null, 5 );
		if ( count( $month_rows ) > 1 ) {
			$boards[] = array( 'title' => 'Birth months', 'note' => 'The calendar months that keep appearing.', 'style' => 'bar', 'rows' => $month_rows );
		}

		// Weekday born.
		$weekday_rows = self::board( $rows, static fn ( $r ) => null !== $r['birth_weekday'] ? $weekdays[ (int) $r['birth_weekday'] ] : null, 4 );
		if ( count( $weekday_rows ) > 1 ) {
			$boards[] = array( 'title' => 'Day of the week born', 'note' => 'Seven ways a life can start.', 'style' => 'bar', 'rows' => $weekday_rows );
		}

		// Star signs.
		$zodiac_rows = self::board( $rows, static fn ( $r ) => $r['zodiac'], 5 );
		if ( count( $zodiac_rows ) > 1 ) {
			$boards[] = array( 'title' => 'Star signs', 'note' => 'Zodiac signs at birth across the archive.', 'style' => 'bar', 'rows' => $zodiac_rows );
		}

		// First initials.
		$initial_rows = self::board( $rows, static fn ( $r ) => $r['first_initial'], 5 );
		if ( count( $initial_rows ) > 1 ) {
			$boards[] = array( 'title' => 'First initials', 'note' => 'The letters the archive starts with.', 'style' => 'bar', 'rows' => $initial_rows );
		}

		// Name lengths bucketed.
		$length_rows = self::board(
			$rows,
			static function ( $r ) {
				$n = (int) $r['name_letters'];
				if ( $n < 10 ) {
					return 'Under 10 letters';
				}
				if ( $n < 14 ) {
					return '10–13 letters';
				}
				if ( $n < 18 ) {
					return '14–17 letters';
				}
				return '18+ letters';
			},
			4
		);
		if ( count( $length_rows ) > 1 ) {
			$boards[] = array( 'title' => 'Lengths of names', 'note' => 'Letter counts across full names.', 'style' => 'bar', 'rows' => $length_rows );
		}

		// Age at death bands.
		$age_rows = self::board(
			$rows,
			static function ( $r ) {
				if ( null === $r['age'] ) {
					return null;
				}
				$age = (int) $r['age'];
				if ( $age < 70 ) {
					return 'Under 70';
				}
				if ( $age < 80 ) {
					return '70–79';
				}
				if ( $age < 90 ) {
					return '80–89';
				}
				if ( $age < 95 ) {
					return '90–94';
				}
				return '95 and over';
			},
			6
		);
		if ( count( $age_rows ) > 1 ) {
			$boards[] = array( 'title' => 'Ages at death', 'note' => 'Ages at death, which drive points.', 'style' => 'bar', 'rows' => $age_rows );
		}

		// Headline fact: the most common occupation worth a sentence.
		$headline = '';
		foreach ( $occ_top as $label => $count ) {
			if ( $count >= 3 ) {
				$headline = "{$count} of the deceased were {$label}s";
				break;
			}
		}

		return array( 'people' => $people, 'headline' => $headline, 'boards' => $boards );
	}
}

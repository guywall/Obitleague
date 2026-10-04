<?php
/**
 * Front-end shortcodes.
 *
 * Statistics-driven render blocks used inside Elementor pages via the
 * Shortcode widget. Public data only: approved people, published standings
 * generations, confirmed deaths. Membership screens stay in REST/widgets.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Support\Time;

final class Shortcodes {

	private function __construct() {}

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'template_redirect', array( self::class, 'redirect_catalogue_search' ), 5 );
		$tags = array(
			'obitleague_hero'         => 'hero',
			'obitleague_stats'        => 'stats',
			'obitleague_recent_deaths' => 'recent_deaths',
			'obitleague_standings'    => 'standings',
			'obitleague_people'       => 'people',
			'obitleague_archive'      => 'archive',
			'obitleague_teams'        => 'teams',
			'obitleague_deaths'       => 'deaths_index',
			'obitleague_rules'        => 'rules',
		);
		foreach ( $tags as $tag => $method ) {
			add_shortcode( $tag, array( self::class, $method ) );
		}
	}

	public static function assets(): void {
		wp_register_style( 'obitleague', OBITLEAGUE_DIR_URL . 'assets/obitleague.css', array(), OBITLEAGUE_VERSION );
	}

	/**
	 * The header search form submits ?s= to the people page, but WordPress
	 * hijacks any ?s= into the search template (a 404 there). Translate it
	 * into the catalogue's ?q= browse instead.
	 */
	public static function redirect_catalogue_search(): void {
		$s = isset( $_GET['s'] ) ? sanitize_text_field( (string) $_GET['s'] ) : '';
		if ( '' === $s ) {
			return;
		}
		// Match on the request path: with ?s= present WordPress has already
		// resolved the request into the search/404 template by the time
		// template_redirect fires, so conditional tags cannot be trusted.
		$search_url = self::people_search_url();
		$search_path = (string) parse_url( (string) $search_url, PHP_URL_PATH );
		$request_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '' !== $search_path && rtrim( $request_path, '/' ) === rtrim( $search_path, '/' ) ) {
			wp_safe_redirect( add_query_arg( array( 'q' => $s ), $search_url ), 302 );
			exit;
		}
	}

	private static function style(): string {
		wp_enqueue_style( 'obitleague' );
		return '';
	}

	/** Public enqueue for sibling modules rendering design-system blocks. */
	public static function enqueue(): string {
		return self::style();
	}

	/* ---------- helpers ---------- */

	public static function season(): int {
		// Prefer the season that actually has published standings (the one in
		// play); fall back to the season currently open for entries.
		global $wpdb;
		$latest = (int) $wpdb->get_var( 'SELECT MAX(season) FROM ' . $wpdb->prefix . 'obitleague_standings_generations WHERE is_current = 1' );
		return $latest > 0 ? $latest : League_Service::current_season();
	}

	/**
	 * Which people were on submitted teams in one season, keyed by person
	 * UUID with the number of teams holding them. Powers the hit/miss split
	 * on the death surfaces: a confirmed death is a "hit" when picked, a
	 * "miss" when nobody chose them. Cached per request.
	 *
	 * @return array<string,int>
	 */
	public static function picked_uuids( int $season ): array {
		static $cache = array();
		if ( isset( $cache[ $season ] ) ) {
			return $cache[ $season ];
		}
		global $wpdb;
		// Synthetic accounts must not inflate a name's popularity, or turn a
		// real death into a "hit" that nobody actually picked.
		$scope = Public_Scope::test_user_exclusion( 'e.user_id' );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.person_uuid AS uuid, COUNT(DISTINCT r.entry_id) AS teams
				 FROM {$wpdb->prefix}obitleague_entry_picks p
				 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
				 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = r.entry_id AND e.state = 'submitted' AND e.season = %d{$scope}
				 GROUP BY p.person_uuid",
				$season
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->uuid ] = (int) $row->teams;
		}
		$cache[ $season ] = $out;
		return $out;
	}

	/** Submitted entry id per user in one league+season (for team links). */
	public static function people_search_url(): string {
		$search_page_id = (int) get_option( 'obitleague_people_search_page', 0 );
		if ( $search_page_id ) {
			return get_permalink( $search_page_id );
		}
		return home_url( '/people/' );
	}

	private static function entry_ids_for_league( int $league_id, int $season ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.user_id, e.id FROM {$wpdb->prefix}obitleague_entries e
				 WHERE e.league_id = %d AND e.season = %d AND e.state = 'submitted'",
				$league_id,
				$season
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->user_id ] = (int) $row->id;
		}
		return $out;
	}

	private static function league_id_by_name( string $name ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE name = %s ORDER BY id DESC LIMIT 1', $name )
		);
	}

	private static function all_leagues( ?int $season = null ): array {
		global $wpdb;
		// Hidden leagues (synthetic test leagues) never reach a public list.
		$scope = Public_Scope::hidden_league_exclusion( 'l' );
		if ( $season ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT l.id, l.name FROM ' . $wpdb->prefix . 'obitleague_leagues l WHERE l.season = %d' . $scope . ' ORDER BY l.name ASC', $season )
			);
		}
		return (array) $wpdb->get_results( 'SELECT l.id, l.name FROM ' . $wpdb->prefix . 'obitleague_leagues l WHERE 1 = 1' . $scope . ' ORDER BY l.name ASC' );
	}

	private static function person_bits( int $post_id ): array {
		$birth = '' !== (string) get_post_meta( $post_id, 'obit_birth_date', true ) ? Import_Service::parse_partial( (string) get_post_meta( $post_id, 'obit_birth_date', true ) ) : null;
		$death = '' !== (string) get_post_meta( $post_id, 'obit_death_date', true ) ? Import_Service::parse_partial( (string) get_post_meta( $post_id, 'obit_death_date', true ) ) : null;
		$age   = ( $birth && $death && $death->is_exact() ) ? \Obitleague\Domain\Age::completed_at( $birth, $death->interpretations()[0] ) : null;
		return array( $birth, $death, $age );
	}

	/* ---------- blocks ---------- */

	public static function hero( $atts = array() ): string {
		$season  = self::season();
		$in_play = method_exists( Pick_Stats::class, 'season_in_play' ) ? (int) Pick_Stats::season_in_play() : $season;
		// The badge in the header shows the in-play season; the kicker must
		// tell the same story, naming the entry season only when it differs.
		$kicker  = $in_play === $season
			? 'Season ' . $in_play . ' · In play'
			: 'Season ' . $in_play . ' in play · picking for ' . $season;
		$out    = '<section class="ob-hero ob-anim"><span class="ob-hero__kicker">' . esc_html( $kicker ) . '</span>';
		$out   .= '<h1>Pick ten lives. Follow the year.</h1>';
		$out   .= '<p>Every confirmed, editor-approved death of a picked figure scores points — younger lives score more.</p>';
		// The deadline is the one fact a first-time visitor needs before the
		// buttons: when the season's entry window closes. It is derived from
		// the same policy the game enforces, so it cannot drift from it.
		$deadline = Deadline_Policy::entry_deadline( $season );
		$note     = Deadline_Policy::in_entry_window( $season, Time::now() )
			? 'Entries for ' . $season . ' are open now, and close at 23:59 London time on ' . $deadline->format( 'j F Y' ) . '.'
			: 'Entries for ' . $season . ' have closed.';
		$out   .= '<p class="ob-hero__note">' . esc_html( $note ) . '</p>';
		$out   .= '<div class="ob-hero__cta">';
		$out   .= '<a class="ob-btn" href="' . esc_url( '/person/' ) . '">Browse the catalogue</a>';
		$out   .= '<a class="ob-btn ob-btn--ghost" href="' . esc_url( '/standings/' ) . '">View standings</a>';
		$out   .= '</div></section>';
		return self::style() . $out;
	}

	public static function stats( $atts = array() ): string {
		global $wpdb;
		$season = self::season();

		// Provisional records (wire-published, awaiting confirmation) are
		// visible but score nothing and sit outside every confirmed count.
		$not_provisional = "AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pv WHERE pv.post_id = p.ID AND pv.meta_key = 'obit_death_provisional')";
		$deaths      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'obit_death_date' AND m.meta_value != '' WHERE p.post_type = 'obit_person' AND p.post_status = 'publish' {$not_provisional}" );
		$provisional = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'obit_death_date' AND m.meta_value != '' WHERE p.post_type = 'obit_person' AND p.post_status = 'publish' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pv WHERE pv.post_id = p.ID AND pv.meta_key = 'obit_death_provisional')" );
		// Test accounts and hidden leagues are excluded from the public totals
		// for the same reason they are excluded from the leaderboards.
		$scope   = Public_Scope::test_user_exclusion( 'e.user_id' );
		$players = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT e.user_id) FROM ' . $wpdb->prefix . 'obitleague_entries e WHERE 1 = 1' . $scope );
		$leagues = count( self::all_leagues() );
		$picks   = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_entry_picks p'
			. ' JOIN ' . $wpdb->prefix . "obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'"
			. ' JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id WHERE 1 = 1' . $scope
		);
		$points  = (int) $wpdb->get_var(
			'SELECT COALESCE(SUM(a.award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards a'
			. ' JOIN ' . $wpdb->prefix . 'obitleague_entries e ON e.id = a.entry_id WHERE a.award_delta > 0' . $scope
		);

		// Season-scoped hit/miss split: how many of this season's confirmed
		// deaths sat on submitted teams, and how many nobody chose.
		$picked = self::picked_uuids( $season );
		$hits   = 0;
		if ( $picked ) {
			$placeholders = implode( ',', array_fill( 0, count( $picked ), '%s' ) );
			$hits         = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
					 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'obit_person' AND p.post_status = 'publish'
					 JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = 'obit_death_date' AND d.meta_value LIKE %s
					 WHERE pm.meta_key = 'obit_uuid' AND pm.meta_value IN ({$placeholders}) {$not_provisional}",
					array_merge( array( $season . '%' ), array_keys( $picked ) )
				)
			);
		}
		$season_deaths = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'obit_death_date' AND m.meta_value LIKE %s
				 WHERE p.post_type = 'obit_person' AND p.post_status = 'publish' {$not_provisional}",
				$season . '%'
			)
		);
		$misses = max( 0, $season_deaths - $hits );

		$tiles = array(
			array( $deaths, 'Confirmed deaths' ),
			array( $provisional, 'Awaiting confirmation' ),
			array( $hits, 'Hits in ' . $season ),
			array( $misses, 'Misses in ' . $season ),
			array( $points, 'Points awarded' ),
			array( $picks, 'Picks on teams' ),
			array( $players, 'Players' ),
			array( $leagues, 'Leagues' ),
		);

		$out = '<div class="ob-stats">';
		foreach ( $tiles as [ $num, $label ] ) {
			$out .= '<div class="ob-stat ob-anim"><span class="ob-stat__num" data-count="' . (int) $num . '">' . esc_html( number_format_i18n( (int) $num ) ) . '</span><span class="ob-stat__label">' . esc_html( $label ) . '</span></div>';
		}
		$out .= '</div>';
		return self::style() . $out;
	}

	public static function recent_deaths( $atts = array() ): string {
		$a      = shortcode_atts( array( 'count' => 8 ), $atts, 'obitleague_recent_deaths' );
		$count  = min( 30, max( 1, (int) $a['count'] ) );
		$season = self::season();

		$query = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $count,
				'meta_key'       => 'obit_death_date',
				'meta_compare'   => 'EXISTS',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'date_query'     => array(),
			)
		);
		$picked = self::picked_uuids( $season );

		$out = '<section class="ob-card ob-anim ob-deaths-card ob-deaths-card--bare">';
		if ( ! $query->have_posts() ) {
			$out .= '<p><em>No confirmed deaths yet this season.</em></p></section>';
			return self::style() . $out;
		}
		foreach ( $query->posts as $post ) {
			[ $birth, $death, $age ] = self::person_bits( (int) $post->ID );
			$cause = (string) get_post_meta( $post->ID, 'obit_cause_status', true );
			$prov  = Death_Wire::is_provisional( (int) $post->ID );
			$out  .= '<div class="ob-death">';
			$out  .= '<span class="ob-death__date">' . ( $death ? esc_html( $death->label() ) : '—' ) . '</span>';
			$out  .= '<span><span class="ob-death__name"><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></span>';
			if ( $prov ) {
				$out .= '<span class="ob-badge ob-badge--provisional">Awaiting confirmation</span>';
			} else {
				$uuid         = (string) get_post_meta( (int) $post->ID, 'obit_uuid', true );
				$picked_teams = isset( $picked[ $uuid ] ) ? (int) $picked[ $uuid ] : 0;
				$out         .= $picked_teams > 0
					? '<span class="ob-badge ob-badge--hit">Hit</span>'
					: '<span class="ob-badge ob-badge--miss">Miss</span>';
			}
			if ( null !== $age ) {
				$out .= '<span class="ob-badge ob-badge--brass">age ' . esc_html( (string) $age ) . ' · ' . esc_html( (string) \Obitleague\Domain\Value\Ruleset::points_for_age( (int) $age ) ) . ' pts</span>';
			}
			if ( Cause_Status::NOT_DISCLOSED !== $cause && '' !== $cause ) {
				$out .= '<span class="ob-badge ob-badge--warn">' . esc_html( Cause_Status::label( $cause ) ) . '</span>';
			}
			$out .= '<div class="ob-death__meta">' . esc_html( (string) get_post_meta( $post->ID, 'obit_role', true ) ) . '</div></span>';
			$out .= '</div>';
		}
		$out .= '</section>';
		return self::style() . $out;
	}

	public static function standings( $atts = array() ): string {
		$a = shortcode_atts( array( 'league' => '', 'season' => self::season(), 'top' => 10 ), $atts, 'obitleague_standings' );
		$season = (int) $a['season'];
		$top = min( 100, max( 1, (int) $a['top'] ) );
		$league_ids = '' !== $a['league'] ? array( self::league_id_by_name( (string) $a['league'] ) ) : array_map( static fn ( $l ) => (int) $l->id, self::all_leagues( $season ) );

		$out = '<div class="ob-grid' . ( count( $league_ids ) > 2 ? ' ob-grid--3' : '' ) . '">';
		foreach ( $league_ids as $league_id ) {
			$name = $league_id ? (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT name FROM ' . $GLOBALS['wpdb']->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) ) : '';
			$out .= '<section class="ob-card"><h2 class="ob-card__title"><a class="ob-league-link" href="' . esc_url( home_url( '/league/' . $league_id . '/' ) ) . '">' . esc_html( $name ?: 'League' ) . '</a></h2>';
			$rows = $league_id ? Standings_Service::current( $league_id, $season, $top ) : null;
			if ( null === $rows ) {
				$out .= '<p><em>Standings not published yet.</em></p></section>';
				continue;
			}
			if ( ! $rows ) {
				// The standings exist, nobody is on them yet. "Not published"
				// would be untrue here, and reads as a broken page rather than a
				// league waiting for its first team.
				$out .= '<p><em>No teams to show yet.</em></p></section>';
				continue;
			}

			$out .= '<div class="ob-table-scroll" role="region" tabindex="0" aria-label="League standings; scroll horizontally to see every column"><table class="ob-table ob-table--compact"><thead><tr><th>#</th><th>Team</th><th>Pts</th><th>Scoring</th></tr></thead><tbody>';
			$lead = (int) ( $rows[0]['points'] ?? 0 );
			foreach ( $rows as $row ) {
				$rank  = (int) $row['rank'];
				$medal = '<span class="ob-medal ob-medal--' . $rank . '">' . (int) $row['rank'] . '</span>';
				$lead_class = ( (int) $row['points'] === $lead && $lead > 0 ) ? ' ob-lead' : '';
				$entry_id = (int) ( $row['entry_id'] ?? 0 );
				$player_cell = $entry_id
					? '<a class="ob-team-link" href="' . esc_url( home_url( '/team/' . $entry_id . '/' ) ) . '">' . esc_html( $row['player'] ) . '</a>'
					: esc_html( $row['player'] );
			if ( $entry_id && ! empty( $row['team_name'] ) && ! empty( $row['owner'] ) ) {
				$player_cell .= '<small class="ob-standing-owner">managed by ' . esc_html( $row['owner'] ) . '</small>';
			}
			$out .= '<tr><td class="ob-rank">' . $medal . '</td><td>' . $player_cell . '</td><td class="ob-pts' . $lead_class . '">' . esc_html( (string) $row['points'] ) . '</td><td>' . esc_html( (string) $row['scoring_picks'] ) . '</td></tr>';
			}
			$out .= '</tbody></table></div></section>';
		}
		$out .= '</div>';
		return self::style() . $out;
	}

	public static function people( $atts = array() ): string {
		$a = shortcode_atts( array( 'living' => '1', 'per_page' => 24 ), $atts, 'obitleague_people' );
		// The living/deceased toggle also comes from the query string — the
		// People mega-menu links advertise /people/?living=0 for the archive.
		$qs_living = isset( $_GET['living'] ) ? sanitize_text_field( (string) $_GET['living'] ) : '';
		$alive     = in_array( $qs_living, array( '0', '1' ), true ) ? ( '1' === $qs_living ) : ( '1' === (string) $a['living'] );

		// Browse controls: search term, sort order, letter jump-to, page.
		$search = isset( $_GET['q'] ) ? sanitize_text_field( (string) $_GET['q'] ) : '';
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( (string) $_GET['sort'] ) : '';
		$letter = isset( $_GET['letter'] ) ? strtoupper( sanitize_text_field( (string) $_GET['letter'] ) ) : '';
		// Canonical /page/N/ URLs carry the number in the query vars, not $_GET.
		$paged = max( 1, (int) ( $_GET['paged'] ?? ( get_query_var( 'paged' ) ?: 1 ) ) );
		if ( ! in_array( $sort, array( 'name', 'newest', 'oldest', 'most_picked' ), true ) ) {
			$sort = 'name';
		}
		$per_page = min( 48, max( 4, (int) $a['per_page'] ) );

		// Query-string filters advertised by the People/Picks mega menu.
		$occupation = isset( $_GET['occupation'] ) ? sanitize_text_field( (string) $_GET['occupation'] ) : '';
		$birth_year = isset( $_GET['birth_year'] ) ? sanitize_text_field( (string) $_GET['birth_year'] ) : '';
		$age_band   = isset( $_GET['age'] ) ? sanitize_text_field( (string) $_GET['age'] ) : '';

		$meta_query = array();
		if ( $alive ) {
			$meta_query[] = array( 'key' => 'obit_death_date', 'compare' => 'NOT EXISTS' );
		} else {
			$meta_query[] = array( 'key' => 'obit_death_date', 'value' => '', 'compare' => '!=' );
		}

		// Occupation filter via taxonomy term slug; occupations live in the
		// obit_occupation taxonomy, so this is a real tax_query.
		$tax_query = array();
		if ( '' !== $occupation ) {
			$term = get_term_by( 'slug', $occupation, Catalogue::TAX_OCCUPATION );
			if ( $term && ! is_wp_error( $term ) ) {
				$tax_query[] = array(
					'taxonomy' => Catalogue::TAX_OCCUPATION,
					'field'    => 'slug',
					'terms'    => $term->slug,
				);
			}
		}

		// Birth-year filter.
		if ( preg_match( '/^\d{4}$/', $birth_year ) ) {
			$meta_query[] = array(
				'key'     => 'obit_birth_date',
				'value'   => $birth_year,
				'compare' => 'LIKE',
			);
		}

		// Age band filter: living figures only. Ages are not stored as meta, so
		// filter on the birth-year window each band implies (today minus age).
		if ( $alive && preg_match( '/^(under|over)-(\d+)$|^(\d{2})$/', $age_band, $m ) ) {
			$now_year = (int) current_time( 'Y' );
			$age_meta = array();
			if ( isset( $m[1] ) && 'under' === $m[1] ) {
				// Younger than N: born in (now - N + 1) or later.
				$age_meta[] = array( 'key' => 'obit_birth_date', 'value' => (string) ( $now_year - (int) $m[2] + 1 ), 'compare' => '>=' );
			} elseif ( isset( $m[1] ) && 'over' === $m[1] ) {
				// Age N or older: born strictly before (now - N + 1), which
				// keeps the whole boundary year (stored dates are 'YYYY…').
				$age_meta[] = array( 'key' => 'obit_birth_date', 'value' => (string) ( $now_year - (int) $m[2] + 1 ), 'compare' => '<' );
			} elseif ( isset( $m[3] ) ) {
				// Bare band: ages N through N+9.
				$age_meta[] = array( 'key' => 'obit_birth_date', 'value' => (string) ( $now_year - (int) $m[3] - 9 ), 'compare' => '>=' );
				$age_meta[] = array( 'key' => 'obit_birth_date', 'value' => (string) ( $now_year - (int) $m[3] + 1 ), 'compare' => '<' );
			}
			if ( $age_meta ) {
				$meta_query[] = $age_meta;
			}
		}

		// Letter jump-to: anchor to titles starting with the chosen letter.
		if ( preg_match( '/^[A-Z]$/', $letter ) ) {
			$meta_query[] = array(
				'key'     => 'obit_sort_name',
				'value'   => '^' . $letter,
				'compare' => 'REGEXP',
			);
		}
		// Free-text search across the precomputed lowercase sort name.
		if ( '' !== $search ) {
			$meta_query[] = array(
				'key'     => 'obit_sort_name',
				'value'   => mb_strtolower( $search ),
				'compare' => 'LIKE',
			);
		}

		// Sorting: name (default), birth date, or seasonal pick popularity.
		$orderby = 'title';
		$order   = 'ASC';
		if ( in_array( $sort, array( 'newest', 'oldest' ), true ) ) {
			$orderby = 'meta_value_num';
			$order   = 'newest' === $sort ? 'DESC' : 'ASC';
		}

		$query_args = array(
			'post_type'      => Catalogue::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'meta_query'     => $meta_query,
			'tax_query'      => $tax_query,
			'orderby'        => $orderby,
			'order'          => $order,
		);
		if ( in_array( $sort, array( 'newest', 'oldest' ), true ) ) {
			$query_args['meta_key'] = 'obit_birth_year_num';
		}
		$q = new \WP_Query( $query_args );

		// Most-picked sorts the whole matching set by seasonal pick count —
		// not just the loaded page — then slices the requested page.
		if ( 'most_picked' === $sort ) {
			$set_args                = $query_args;
			$set_args['posts_per_page'] = 2000;
			$set_args['fields']      = 'ids';
			unset( $set_args['paged'], $set_args['orderby'], $set_args['order'], $set_args['meta_key'] );
			$set_query = new \WP_Query( $set_args );
			$all_ids   = array_map( 'intval', (array) $set_query->posts );
			$pick_counts = Pick_Stats::pick_counts_by_uuid( (int) self::season() );
			usort(
				$all_ids,
				static function ( int $a_id, int $b_id ) use ( $pick_counts ): int {
					$a_uuid = (string) get_post_meta( $a_id, 'obit_uuid', true );
					$b_uuid = (string) get_post_meta( $b_id, 'obit_uuid', true );
					$by_picks = ( $pick_counts[ $b_uuid ] ?? 0 ) <=> ( $pick_counts[ $a_uuid ] ?? 0 );
					// Break a tie on id, without letting || collapse the int to bool.
					return 0 !== $by_picks ? $by_picks : ( $a_id <=> $b_id );
				}
			);
			$offset      = ( $paged - 1 ) * $per_page;
			$q->posts    = array_map( 'get_post', array_slice( $all_ids, $offset, $per_page ) );
			$q->found_posts = count( $all_ids );
			$q->max_num_pages = (int) ceil( count( $all_ids ) / $per_page );
		}
		$active_filters = array();
		if ( '' !== $occupation ) {
			$active_filters[] = array( 'label' => 'occupation: ' . esc_html( $occupation ), 'remove' => remove_query_arg( array( 'occupation' ) ) );
		}
		if ( preg_match( '/^\d{4}$/', $birth_year ) ) {
			$active_filters[] = array( 'label' => 'born in ' . esc_html( $birth_year ), 'remove' => remove_query_arg( array( 'birth_year' ) ) );
		}
		if ( '' !== $age_band ) {
			$active_filters[] = array( 'label' => 'age ' . esc_html( $age_band ), 'remove' => remove_query_arg( array( 'age' ) ) );
		}
		if ( '' !== $search ) {
			$active_filters[] = array( 'label' => 'search: ' . esc_html( $search ), 'remove' => remove_query_arg( array( 'q' ) ) );
		}
		if ( preg_match( '/^[A-Z]$/', $letter ) ) {
			$active_filters[] = array( 'label' => 'letter ' . esc_html( $letter ), 'remove' => remove_query_arg( array( 'letter' ) ) );
		}

		$out = '<div class="ob-people__toolbar">';

		// Free-text search across the catalogue. Hidden inputs preserve the
		// other browse controls through the GET submission.
		$toolbar_hidden = array(
			'living'     => $alive ? '1' : '0',
			'occupation' => $occupation,
			'birth_year' => $birth_year,
			'age'        => $age_band,
			'sort'       => 'name' !== $sort ? $sort : '',
			'letter'     => preg_match( '/^[A-Z]$/', $letter ) ? $letter : '',
		);
		$out .= '<form class="ob-people__search ob-filters" method="get" action="' . esc_url( (string) get_permalink() ) . '" role="search" aria-label="Search the people catalogue">';
		foreach ( $toolbar_hidden as $hidden_key => $hidden_value ) {
			if ( '' !== (string) $hidden_value ) {
				$out .= '<input type="hidden" name="' . esc_attr( (string) $hidden_key ) . '" value="' . esc_attr( (string) $hidden_value ) . '" />';
			}
		}
		$out .= '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search people…" aria-label="Search people" />';
		$out .= '<button type="submit">Search</button>';
		if ( '' !== $search ) {
			$out .= '<a class="ob-people__filter-clear" href="' . esc_url( remove_query_arg( array( 'q', 'paged' ) ) ) . '">Reset</a>';
		}
		$out .= '</form>';

		// Sort selector as links so pagination and filters stay URL-driven.
		$out .= '<nav class="ob-people__sorts" aria-label="Sort people">';
		foreach ( array( 'name' => 'A–Z', 'most_picked' => 'Most picked', 'newest' => 'Youngest', 'oldest' => 'Oldest' ) as $sort_key => $sort_label ) {
			$href    = 'name' === $sort_key ? remove_query_arg( array( 'sort', 'paged' ) ) : add_query_arg( array( 'sort' => $sort_key, 'paged' => false ) );
			$current = 'name' === $sort_key ? 'name' === $sort : $sort_key === $sort;
			$out    .= '<a class="ob-people__sort' . ( $current ? ' is-active' : '' ) . '" href="' . esc_url( $href ) . '"' . ( $current ? ' aria-current="true"' : '' ) . '>' . esc_html( $sort_label ) . '</a>';
		}
		$out .= '</nav>';
		$out .= '</div>';

		if ( $active_filters ) {
			$out .= '<div class="ob-people__filters"><span class="ob-people__filter-label">Filtering by:</span>';
			foreach ( $active_filters as $f ) {
				$out .= '<span class="ob-people__filter">' . esc_html( $f['label'] ) . ' <a class="ob-people__filter-remove" href="' . esc_url( $f['remove'] ) . '">&times;</a></span>';
			}
			$out .= ' <a class="ob-people__filter-clear" href="' . esc_url( remove_query_arg( array( 'occupation', 'birth_year', 'age', 'living', 'q', 'sort', 'letter', 'paged' ) ) ) . '">Clear filters</a></div>';
		}

		// Letter jump-to: only letters actually present in the current scope
		// (living vs deceased) are clickable.
		global $wpdb;
		$letter_scope_sql = $alive
			? "AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} dd WHERE dd.post_id = p.ID AND dd.meta_key = 'obit_death_date' AND dd.meta_value != '')"
			: "AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} dd WHERE dd.post_id = p.ID AND dd.meta_key = 'obit_death_date' AND dd.meta_value != '')";
		$available_letters = array_values(
			array_filter(
				array_map( 'strtoupper', (array) $wpdb->get_col(
					"SELECT DISTINCT UPPER( LEFT( m.meta_value, 1 ) ) FROM {$wpdb->postmeta} m
					 JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE p.post_type = 'obit_person' AND p.post_status = 'publish'
					   AND m.meta_key = 'obit_sort_name'
					   {$letter_scope_sql}"
				) ),
				static fn ( $c ): bool => is_string( $c ) && preg_match( '/^[A-Z]$/', $c ) === 1
			)
		);
		if ( count( $available_letters ) > 1 ) {
			$out .= '<nav class="ob-people__letters" aria-label="Jump to names starting with a letter">';
			$out .= '<a class="ob-people__letter' . ( '' === $letter ? ' is-active' : '' ) . '" href="' . esc_url( remove_query_arg( array( 'letter', 'paged' ) ) ) . '">All</a>';
			foreach ( range( 'A', 'Z' ) as $l ) {
				if ( in_array( $l, $available_letters, true ) ) {
					$out .= '<a class="ob-people__letter' . ( $letter === $l ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'letter' => $l, 'paged' => false ) ) ) . '">' . $l . '</a>';
				} else {
					$out .= '<span class="ob-people__letter is-empty" aria-hidden="true">' . $l . '</span>';
				}
			}
			$out .= '</nav>';
		}

		if ( ! $q->have_posts() ) {
			$empty = '' !== $search || '' !== $letter || '' !== $occupation || '' !== $birth_year || '' !== $age_band
				? 'No people match those filters. Clear a filter or try a different search.'
				: 'No profiles yet — the catalogue fills as figures are imported and approved.';
			return self::style() . $out . '<section class="ob-card"><p><em>' . esc_html( $empty ) . '</em></p></section>';
		}		$out .= '<div class="ob-people">';
		foreach ( $q->posts as $post ) {
			[ $birth, $death ] = self::person_bits( (int) $post->ID );
			$out .= '<div class="ob-person' . ( $death ? ' ob-person--dead' : '' ) . ' ob-anim">';
			// Whole-tile click target: covers the card so image, role, and
			// everything else navigate to the profile, not just the name.
			$out .= '<a class="ob-person__overlay-link" href="' . esc_url( (string) get_permalink( $post ) ) . '" tabindex="-1" aria-hidden="true"></a>';
			$image = (string) get_post_meta( $post->ID, People_Sync::META_IMAGE_URL, true );
			if ( '' !== $image ) {
				$out .= '<img class="ob-person__avatar ob-person__avatar--img" src="' . esc_url( $image ) . '" alt="" loading="lazy" />';
			} else {
				$out .= '<span class="ob-person__avatar" aria-hidden="true">' . esc_html( mb_substr( (string) get_the_title( $post ), 0, 1 ) ) . '</span>';
			}
			$out .= '<span class="ob-person__status">' . ( $death ? 'In memoriam' : 'Living' ) . '</span>';
			$out .= '<p class="ob-person__name"><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></p>';
			$role = Person_Content::descriptor( (int) $post->ID );
			if ( '' !== $role ) {
				$out .= '<p class="ob-person__role">' . esc_html( $role ) . '</p>';
			}
			// One occupation per tile; the full list lives on the profile page.
			$occ_primary = People_Sync::primary_occupation_link( (int) $post->ID );
			if ( '' !== $occ_primary ) {
				$out .= '<p class="ob-person__occ" title="' . esc_attr( implode( ', ', People_Sync::occupation_labels( (int) $post->ID ) ) ) . '">' . $occ_primary . '</p>';
			}
			$out .= '<p class="ob-person__dates">';
			$out .= $birth ? esc_html( 'b. ' . $birth->label() ) : '';
			$out .= $death ? esc_html( ' · d. ' . $death->label() ) : '';
			$out .= '</p>';
			$out .= '<a class="ob-person__cta" href="' . esc_url( (string) get_permalink( $post ) ) . '">View profile →</a>';
			$out .= '</div>';
		}
		$out .= '</div>';

		// Pagination: window of page links with first/last, prev/next, and a count.
		$total_pages = (int) $q->max_num_pages;
		if ( $total_pages > 1 ) {
			$base = remove_query_arg( 'paged' );
			$out .= '<nav class="ob-people__pagination" aria-label="Browse pages">';
			$out .= '<span class="ob-people__count">' . esc_html( number_format_i18n( (int) $q->found_posts ) ) . ' people · page ' . esc_html( number_format_i18n( $paged ) ) . ' of ' . esc_html( number_format_i18n( $total_pages ) ) . '</span>';
			$out .= '<span class="ob-people__links">';
			if ( $paged > 1 ) {
				$out .= '<a class="ob-page-link" rel="prev" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">&larr; Previous</a>';
			}
			$window_start = max( 1, $paged - 2 );
			$window_end   = min( $total_pages, $paged + 2 );
			if ( $window_start > 1 ) {
				$out .= '<a class="ob-page-link" href="' . esc_url( $base ) . '">1</a>';
				if ( $window_start > 2 ) {
					$out .= '<span class="ob-page-gap">…</span>';
				}
			}
			for ( $p = $window_start; $p <= $window_end; ++$p ) {
				$href = 1 === $p ? $base : add_query_arg( 'paged', $p, $base );
				$out .= '<a class="ob-page-link' . ( $p === $paged ? ' is-current' : '' ) . '" href="' . esc_url( $href ) . '"' . ( $p === $paged ? ' aria-current="page"' : '' ) . '>' . esc_html( number_format_i18n( $p ) ) . '</a>';
			}
			if ( $window_end < $total_pages ) {
				if ( $window_end < $total_pages - 1 ) {
					$out .= '<span class="ob-page-gap">…</span>';
				}
				$out .= '<a class="ob-page-link" href="' . esc_url( add_query_arg( 'paged', $total_pages, $base ) ) . '">' . esc_html( number_format_i18n( $total_pages ) ) . '</a>';
			}
			if ( $paged < $total_pages ) {
				$out .= '<a class="ob-page-link" rel="next" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">Next &rarr;</a>';
			}
			$out .= '</span></nav>';
		}
		return self::style() . $out;
	}

	/**
	 * Team directory: every submitted main-season team as a browsable card
	 * grid, with search and pagination. Teams are the social object — picks
	 * belong to them, not the other way round.
	 */
	public static function teams( $atts = array() ): string {
		$a = shortcode_atts( array( 'season' => Season_Switcher::displayed_season(), 'per_page' => 24 ), $atts, 'obitleague_teams' );
		$season   = (int) $a['season'];
		$search   = isset( $_GET['q'] ) ? sanitize_text_field( (string) $_GET['q'] ) : '';
		$paged    = max( 1, (int) ( $_GET['paged'] ?? ( get_query_var( 'paged' ) ?: 1 ) ) );
		$per_page = min( 48, max( 6, (int) $a['per_page'] ) );
		$directory = Overall_Standings::team_directory( $season, $per_page, ( $paged - 1 ) * $per_page, $search );
		$out = self::style() . '<div class="ob-people__toolbar">';
		$out .= '<form class="ob-people__search ob-filters" method="get" role="search" aria-label="Search teams">';
		$out .= '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search teams…" aria-label="Search teams" />';
		$out .= '<button type="submit">Search</button>';
		if ( '' !== $search ) {
			$out .= '<a class="ob-people__filter-clear" href="' . esc_url( remove_query_arg( array( 'q', 'paged' ) ) ) . '">Reset</a>';
		}
		$out .= '</form></div>';
		if ( null === $directory ) {
			$out .= '<section class="ob-card"><p><em>No main league exists for season ' . esc_html( (string) $season ) . ' yet.</em></p></section>';
			return $out;
		}
		$total = (int) $directory['total'];
		$out  .= '<p class="ob-people__count ob-teams__count">' . esc_html( number_format_i18n( $total ) ) . ' teams · season ' . esc_html( (string) $season ) . ( '' !== $search ? ' · matching “' . esc_html( $search ) . '”' : '' ) . '</p>';
		if ( ! $directory['rows'] ) {
			$out .= '<section class="ob-card"><p><em>' . ( '' !== $search ? 'No teams match that search.' : 'No submitted teams yet.' ) . '</em></p></section>';
			return $out;
		}
		$out .= '<div class="ob-teams">';
		foreach ( $directory['rows'] as $row ) {
			$url      = home_url( '/team/' . (int) $row['entry_id'] . '/' );
			$name     = (string) ( '' !== $row['team_name'] ? $row['team_name'] : $row['owner'] );
			$initial  = mb_substr( $name, 0, 1 );
			$out .= '<a class="ob-team ob-anim" href="' . esc_url( $url ) . '">';
			$out .= '<span class="ob-team__badge" aria-hidden="true">' . esc_html( $initial ) . '</span>';
			$out .= '<span class="ob-team__name">' . esc_html( $name ) . '</span>';
			if ( '' !== (string) $row['team_name'] ) {
				$out .= '<span class="ob-team__owner">managed by ' . esc_html( (string) $row['owner'] ) . '</span>';
			}
			$out .= '<span class="ob-team__meta">';
			if ( (int) $row['rank'] > 0 ) {
				$out .= '<span class="ob-team__rank">rank ' . esc_html( (string) $row['rank'] ) . '</span>';
			}
			if ( (int) $row['points'] > 0 ) {
				$out .= '<span class="ob-team__pts">' . esc_html( (string) $row['points'] ) . ' pts</span>';
			}
			$out .= '<span class="ob-team__picks">' . esc_html( (string) $row['pick_count'] ) . ' picks</span>';
			$out .= '</span></a>';
		}
		$out .= '</div>';
		$total_pages = (int) max( 1, ceil( $total / $per_page ) );
		if ( $total_pages > 1 ) {
			$base = remove_query_arg( 'paged' );
			$out .= '<nav class="ob-people__pagination" aria-label="Team pages">';
			$out .= '<span class="ob-people__count">page ' . esc_html( number_format_i18n( $paged ) ) . ' of ' . esc_html( number_format_i18n( $total_pages ) ) . '</span>';
			$out .= '<span class="ob-people__links">';
			if ( $paged > 1 ) {
				$out .= '<a class="ob-page-link" rel="prev" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">&larr; Previous</a>';
			}
			$window_start = max( 1, $paged - 2 );
			$window_end   = min( $total_pages, $paged + 2 );
			for ( $p = $window_start; $p <= $window_end; ++$p ) {
				$href = 1 === $p ? $base : add_query_arg( 'paged', $p, $base );
				$out .= '<a class="ob-page-link' . ( $p === $paged ? ' is-current' : '' ) . '" href="' . esc_url( $href ) . '"' . ( $p === $paged ? ' aria-current="page"' : '' ) . '>' . esc_html( number_format_i18n( $p ) ) . '</a>';
			}
			if ( $paged < $total_pages ) {
				$out .= '<a class="ob-page-link" rel="next" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">Next &rarr;</a>';
			}
			$out .= '</span></nav>';
		}
		return $out;
	}

	/**
	 * Obituary index for one season: every confirmed death that year, with
	 * attached public reporting on each entry.
	 */
	public static function deaths_index( $atts = array() ): string {
		$a         = shortcode_atts( array( 'season' => Pick_Stats::season_in_play(), 'per_page' => 48 ), $atts, 'obitleague_deaths' );
		$season    = (int) $a['season'];
		$search    = isset( $_GET['q'] ) ? sanitize_text_field( (string) $_GET['q'] ) : '';
		$paged     = max( 1, (int) ( $_GET['paged'] ?? ( get_query_var( 'paged' ) ?: 1 ) ) );
		$per_page  = min( 48, max( 6, (int) $a['per_page'] ) );
		$meta_query = array(
			array( 'key' => 'obit_death_date', 'value' => '^' . $season, 'compare' => 'REGEXP' ),
		);
		if ( '' !== $search ) {
			$meta_query[] = array( 'key' => 'obit_sort_name', 'value' => mb_strtolower( $search ), 'compare' => 'LIKE' );
		}
		$picked      = self::picked_uuids( $season );
		$filter      = isset( $_GET['pick'] ) ? sanitize_key( (string) $_GET['pick'] ) : '';
		$pick_filter = in_array( $filter, array( 'picked', 'missed' ), true ) ? $filter : '';
		$q = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'meta_query'     => $meta_query,
				'orderby'        => 'meta_value',
				'meta_key'       => 'obit_death_date',
				'order'          => 'DESC',
			)
		);
		// The Hits/Misses chips are real filters: keep only the matching rows
		// from the loaded page and adjust the pager so the count stays honest.
		if ( '' !== $pick_filter ) {
			$kept = array();
			foreach ( (array) $q->posts as $post ) {
				$uuid = (string) get_post_meta( (int) $post->ID, 'obit_uuid', true );
				$is_picked = '' !== $uuid && isset( $picked[ $uuid ] );
				if ( ( 'picked' === $pick_filter ) === $is_picked ) {
					$kept[] = $post;
				}
			}
			$q->posts       = $kept;
			$q->post_count  = count( $kept );
		}
		$out = self::style();
		wp_enqueue_script( 'obitleague-obituaries', OBITLEAGUE_DIR_URL . 'assets/obituaries.js', array(), OBITLEAGUE_VERSION, true );
		$out .= '<div class="ob-people__toolbar ob-deaths-index__filters">';
		$base_url = remove_query_arg( array( 'pick', 'paged' ) );
		foreach ( array(
			''       => 'All',
			'picked' => 'Hits',
			'missed' => 'Misses',
		) as $value => $label ) {
			$href   = '' === $value ? $base_url : add_query_arg( 'pick', $value, $base_url );
			$active = $pick_filter === $value ? ' is-active' : '';
			$out   .= '<a class="ob-chip' . $active . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= '</div>';
		$out .= '<div class="ob-people__toolbar">';
		$out .= '<form class="ob-people__search ob-filters" method="get" role="search" aria-label="Search the obituaries">';
		$out .= '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search the obituaries…" aria-label="Search the obituaries" />';
		$out .= '<button type="submit">Search</button>';
		if ( '' !== $search ) {
			$out .= '<a class="ob-people__filter-clear" href="' . esc_url( remove_query_arg( array( 'q', 'paged' ) ) ) . '">Reset</a>';
		}
		$out .= '</form></div>';
		// View switch: list is the default and the choice is remembered in
		// the browser. The server renders list, so there is no flash before
		// the script applies a stored grid preference.
		$out .= '<div class="ob-deaths-index__view" role="group" aria-label="Obituary view">';
		$out .= '<span class="ob-deaths-index__viewlabel" aria-hidden="true">View</span>';
		$out .= '<button type="button" class="ob-view-btn" data-ob-view="list" aria-pressed="true">List</button>';
		$out .= '<button type="button" class="ob-view-btn" data-ob-view="grid" aria-pressed="false">Grid</button>';
		$out .= '</div>';
		$hits = 0;
		foreach ( (array) $q->posts as $post ) {
			$uuid = (string) get_post_meta( (int) $post->ID, 'obit_uuid', true );
			if ( '' !== $uuid && isset( $picked[ $uuid ] ) ) {
				++$hits;
			}
		}
		$misses_on_page = max( 0, count( (array) $q->posts ) - $hits );
		$out .= '<p class="ob-people__count ob-deaths-index__count">' . esc_html( number_format_i18n( (int) $q->found_posts ) ) . ' confirmed deaths in ' . esc_html( (string) $season ) . ' &middot; ' . esc_html( number_format_i18n( $hits ) ) . ' on teams (hits) &middot; ' . esc_html( number_format_i18n( $misses_on_page ) ) . ' unpicked on this page (misses)</p>';
		if ( ! $q->have_posts() ) {
			$message = '' !== $pick_filter
				? ( 'picked' === $pick_filter ? 'No hits recorded for ' : 'No misses recorded for ' ) . esc_html( (string) $season ) . ' yet.'
				: 'No confirmed deaths recorded for ' . esc_html( (string) $season ) . ' yet.';
			$out .= '<section class="ob-card"><p><em>' . esc_html( $message ) . '</em></p></section>';
			return $out;
		}
		$out .= '<div class="ob-people ob-people--archive ob-deaths-index ob-deaths-index--list" data-ob-obituaries>';
		foreach ( $q->posts as $post ) {
			$post_id = (int) $post->ID;
			[ $birth, $death, $age ] = self::person_bits( $post_id );
			$image  = (string) get_post_meta( $post_id, People_Sync::META_IMAGE_URL, true );
			$sources = Death_Wire::public_sources( $post_id );
			$out .= '<article class="ob-person ob-person--dead ob-anim">';
			$out .= '<a class="ob-person__overlay-link" href="' . esc_url( (string) get_permalink( $post ) ) . '" tabindex="-1" aria-hidden="true"></a>';
			if ( '' !== $image ) {
				$out .= '<img class="ob-person__avatar ob-person__avatar--img" src="' . esc_url( $image ) . '" alt="" loading="lazy" />';
			} else {
				$out .= '<span class="ob-person__avatar" aria-hidden="true">' . esc_html( mb_substr( (string) get_the_title( $post ), 0, 1 ) ) . '</span>';
			}
			$out .= Death_Wire::is_provisional( $post_id )
				? '<span class="ob-person__status ob-person__status--provisional">Provisional</span>'
				: '<span class="ob-person__status">In memoriam</span>';
			$out .= '<div class="ob-person__body">';
			if ( Death_Wire::is_provisional( $post_id ) ) {
				$out .= '<span class="ob-badge ob-badge--provisional">Awaiting confirmation</span>';
			} else {
				$uuid         = (string) get_post_meta( $post_id, 'obit_uuid', true );
				$picked_teams = isset( $picked[ $uuid ] ) ? (int) $picked[ $uuid ] : 0;
				$out         .= $picked_teams > 0
					? '<span class="ob-badge ob-badge--hit">Hit &middot; ' . esc_html( number_format_i18n( $picked_teams ) ) . ' team' . ( 1 === $picked_teams ? '' : 's' ) . '</span>'
					: '<span class="ob-badge ob-badge--miss">Miss</span>';
			}
			// The points this death scores under the rules: 100 minus the age
			// at death. Only stated when the age is known, so a partial-date
			// record never displays a number the game would not award.
			if ( null !== $age ) {
				$points = \Obitleague\Domain\Value\Ruleset::points_for_age( (int) $age );
				$out   .= '<span class="ob-badge ob-badge--points" title="' . esc_attr( sprintf( 'Scores %d points under the current rules; age at death %d.', $points, (int) $age ) ) . '">' . esc_html( number_format_i18n( $points ) ) . ' pts</span>';
			}
			$out .= '<p class="ob-person__name"><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></p>';
			$role = Person_Content::descriptor( $post_id );
			if ( '' !== $role ) {
				$out .= '<p class="ob-person__role">' . esc_html( $role ) . '</p>';
			}
			// Occupations belong on the obituary card too, not only the profile.
			$occ_primary = People_Sync::primary_occupation_link( $post_id );
			if ( '' !== $occ_primary ) {
				$out .= '<p class="ob-person__occ" title="' . esc_attr( implode( ', ', People_Sync::display_occupation_labels( $post_id ) ) ) . '">' . $occ_primary . '</p>';
			}
			$out .= '<p class="ob-person__dates">';
			$out .= $death ? esc_html( 'd. ' . $death->label() ) : '';
			$out .= ( null !== $age ) ? esc_html( ' · age ' . $age ) : '';
			$out .= '</p>';
			if ( $sources ) {
				$out .= '<div class="ob-person__sources">';
				foreach ( array_slice( $sources, 0, 3 ) as $src ) {
					$out .= '<a class="ob-source-chip" href="' . esc_url( $src['url'] ) . '" rel="nofollow noopener" target="_blank">' . esc_html( '' !== $src['name'] ? $src['name'] : 'Report' ) . '</a>';
				}
				if ( count( $sources ) > 3 ) {
					$out .= '<span class="ob-source-chip ob-source-chip--more">+' . esc_html( (string) ( count( $sources ) - 3 ) ) . '</span>';
				}
				$out .= '</div>';
			}
			$out .= '</div>';
			$out .= '</article>';
		}
		$out .= '</div>';
		$out .= self::pagination_nav( $paged, (int) $q->max_num_pages, remove_query_arg( 'paged' ), 'Obituary pages' );
		return $out;
	}

	/**
	 * Shared pagination control: first/last, a ±2 window with ellipses, and
	 * clear previous/next. One builder so every list pages the same way.
	 */
	private static function pagination_nav( int $paged, int $total_pages, string $base, string $aria_label ): string {
		if ( $total_pages < 2 ) {
			return '';
		}
		$paged = min( max( 1, $paged ), $total_pages );
		$href  = static function ( int $page ) use ( $base ): string {
			return 1 === $page ? $base : (string) add_query_arg( 'paged', $page, $base );
		};
		$out  = '<nav class="ob-people__pagination" aria-label="' . esc_attr( $aria_label ) . '">';
		$out .= '<span class="ob-people__count">Page ' . esc_html( number_format_i18n( $paged ) ) . ' of ' . esc_html( number_format_i18n( $total_pages ) ) . '</span>';
		$out .= '<span class="ob-people__links">';
		if ( $paged > 1 ) {
			$out .= '<a class="ob-page-link" href="' . esc_url( $href( 1 ) ) . '">&laquo; First</a>';
			$out .= '<a class="ob-page-link" rel="prev" href="' . esc_url( $href( $paged - 1 ) ) . '">&larr; Previous</a>';
		}
		$start = max( 1, $paged - 2 );
		$end   = min( $total_pages, $paged + 2 );
		if ( $start > 1 ) {
			$out .= '<span class="ob-page-gap">…</span>';
		}
		for ( $p = $start; $p <= $end; ++$p ) {
			$current = $p === $paged ? ' is-current' : '';
			$aria    = $p === $paged ? ' aria-current="page"' : '';
			$out    .= '<a class="ob-page-link' . $current . '" href="' . esc_url( $href( $p ) ) . '"' . $aria . '>' . esc_html( number_format_i18n( $p ) ) . '</a>';
		}
		if ( $end < $total_pages ) {
			$out .= '<span class="ob-page-gap">…</span>';
		}
		if ( $paged < $total_pages ) {
			$out .= '<a class="ob-page-link" rel="next" href="' . esc_url( $href( $paged + 1 ) ) . '">Next &rarr;</a>';
			$out .= '<a class="ob-page-link" href="' . esc_url( $href( $total_pages ) ) . '">Last &raquo;</a>';
		}
		$out .= '</span></nav>';
		return $out;
	}

	public static function archive( $atts = array() ): string {
		$year  = isset( $_GET['ob_year'] ) ? sanitize_text_field( (string) $_GET['ob_year'] ) : '';
		$paged = max( 1, (int) ( $_GET['paged'] ?? ( get_query_var( 'paged' ) ?: 1 ) ) );
		$args  = array(
			'post_type'      => Catalogue::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 24,
			'paged'          => $paged,
			'meta_key'       => 'obit_death_date',
			'meta_compare'   => 'EXISTS',
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
		);
		if ( preg_match( '/^\d{4}$/', $year ) ) {
			$args['meta_query'] = array( array( 'key' => 'obit_death_date', 'value' => $year, 'compare' => 'LIKE' ) );
		}
		$q   = new \WP_Query( $args );
		$out = '<form class="ob-filters" method="get"><select name="ob_year"><option value="">All years</option>';
		foreach ( array( '2026', '2025' ) as $y ) {
			$out .= '<option value="' . esc_attr( $y ) . '"' . selected( $year, $y, false ) . '>' . esc_html( $y ) . '</option>';
		}
		$out .= '</select><button type="submit">Filter</button></form>';
		$out .= '<p class="ob-archive__count">' . esc_html( number_format_i18n( (int) $q->found_posts ) ) . ' confirmed cases' . ( preg_match( '/^\d{4}$/', $year ) ? ' in ' . esc_html( $year ) : '' ) . ' · season ' . (int) self::season() . '</p>';
		$out .= '<div class="ob-people ob-people--archive">';
		foreach ( $q->posts as $post ) {
			[ $birth, $death, $age ] = self::person_bits( (int) $post->ID );
			$image = (string) get_post_meta( $post->ID, People_Sync::META_IMAGE_URL, true );
			$out .= '<article class="ob-person ob-person--dead ob-anim">';
			if ( '' !== $image ) {
				$out .= '<img class="ob-person__avatar ob-person__avatar--img" src="' . esc_url( $image ) . '" alt="" loading="lazy" />';
			} else {
				$out .= '<span class="ob-person__avatar" aria-hidden="true">' . esc_html( mb_substr( (string) get_the_title( $post ), 0, 1 ) ) . '</span>';
			}
			$out .= '<span class="ob-person__status">In memoriam</span>';
			$out .= '<p class="ob-person__name"><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></p>';
			$role = Person_Content::descriptor( (int) $post->ID );
			if ( '' !== $role ) {
				$out .= '<p class="ob-person__role">' . esc_html( $role ) . '</p>';
			}
			// One occupation per tile; the full list lives on the profile page.
			$occ_primary = People_Sync::primary_occupation_link( (int) $post->ID );
			if ( '' !== $occ_primary ) {
				$out .= '<p class="ob-person__occ" title="' . esc_attr( implode( ', ', People_Sync::occupation_labels( (int) $post->ID ) ) ) . '">' . $occ_primary . '</p>';
			}
			$out .= '<p class="ob-person__dates">';
			$out .= $death ? esc_html( 'd. ' . $death->label() ) : '';
			$out .= ( null !== $age ) ? esc_html( ' · age ' . $age ) : '';
			$out .= '</p></article>';
		}
		$out .= '</div>';
		// The archive holds every confirmed case, so it must page like the rest
		// instead of dumping one grid with no way forward.
		$out .= self::pagination_nav( $paged, (int) $q->max_num_pages, remove_query_arg( 'paged' ), 'Death archive pages' );
		return self::style() . $out;
	}

	/* ---------- rules ---------- */

	public static function rules( $atts = array() ): string {
		$season = self::season();
		$steps  = array(
			array(
				'Get yourself into a league',
				'Obitleague is free to play. Create an account, verify your email, and you are in the game for the coming season — your team competes against everyone else on one overall leaderboard.',
			),
			array(
				'Pick your ten',
				'Build a team of exactly ' . (int) \Obitleague\Domain\Value\Ruleset::TEAM_SIZE . ' living public figures from the catalogue. Everyone you pick must be at least ' . (int) \Obitleague\Domain\Value\Ruleset::MIN_AGE . ' years old when the season starts, and you cannot pick the same person twice. You can save a draft and keep editing it, but once the deadline passes — ' . \Obitleague\Domain\Value\Ruleset::DEADLINE_RULE . ' — your team is locked for the year. One second late is still late.',
			),
			array(
				'Then wait for the news',
				'Through the season, the editors watch public reporting. When someone on your team dies, the story is checked before it counts: the identity is verified, the exact date is confirmed, and the cause is recorded where it is known. Nothing scores itself — a human editor approves every case, and only one approved death per person is ever recorded.',
			),
			array(
				'How your team scores',
				'When a death is approved, every team carrying that person scores points: take their age at death, subtract it from 100, and that is the score (a minimum of 1 point, so every confirmed death is worth something). The younger the life, the heavier the loss, and the more points your team collects. Standings stay provisional until ' . rtrim( \Obitleague\Domain\Value\Ruleset::SETTLEMENT_RULE, '.' ) . ' in case a December death surfaces after New Year, and then the season is settled.',
			),
			array(
				'If something goes wrong',
				'Corrections are part of the game, not an exception to it. If a date turns out to be wrong, the record is corrected and every affected score is recalculated openly. If a death turns out to be a hoax or a case of mistaken identity, the award is reversed and the correction is visible in the archive. Nobody can quietly edit a total: every point comes from a recorded, auditable event.',
			),
		);

		$examples = array( 80, 88, 92, 96, 99, 101 );
		$out      = '<div class="ob-rules">';
		$out     .= '<ol class="ob-rules__steps">';
		foreach ( $steps as $i => [ $title, $body ] ) {
			$n = str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT );
			$out .= '<li class="ob-rules__step ob-anim"><span class="ob-rules__num">' . $n . '</span><div><h3 class="ob-rules__title">' . esc_html( $title ) . '</h3><p>' . esc_html( $body ) . '</p></div></li>';
		}
		$out     .= '</ol>';

		$out     .= '<section class="ob-card ob-rules__scoring ob-anim"><h2 class="ob-card__title">How points work</h2>';
		$out     .= '<p>Each confirmed death is worth <strong>100 minus the person\'s age at death</strong>, with a floor of 1 point — so a life cut short at 64 scores 36 points, and a remarkable life ending at 99 still scores 1. Worked examples:</p>';
		$out     .= '<table class="ob-table"><thead><tr><th>Age at death</th><th>Points scored</th></tr></thead><tbody>';
		foreach ( $examples as $age ) {
			$pts = \Obitleague\Domain\Value\Ruleset::points_for_age( $age );
			$out .= '<tr><td>' . esc_html( (string) $age ) . '</td><td class="ob-pts">' . esc_html( (string) $pts ) . '</td></tr>';
		}
		$out     .= '</tbody></table><p class="ob-rules__note">Ties in the leaderboard are broken by the number of scoring picks; teams still level share a position. Deaths known only to the month or year are verified but never scored — the record waits for an exact date. A death on 31 December still counts for that season if it is confirmed by the settlement date.</p>';
		$out     .= '</section>';

		$out     .= '<section class="ob-card ob-rules__ethics ob-anim"><h2 class="ob-card__title">Played with respect</h2>';
		$out     .= '<p>The figures in this catalogue are real people, and the game treats them that way. Obitleague reports only what public sources and our editors confirm, credits every fact to its source, and exists for people who read the obituaries — not for shock. If a case touches an actively grieving family, editors may hold publication; the game waits. Discussion in the forum follows the same spirit: argue about picks and points as much as you like, but keep it decent.</p>';
		$out     .= '</section>';
		$out     .= '</div>';
		return self::style() . $out;
	}
}

<?php
declare( strict_types = 1 );

namespace Obitleague\Modules;

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

	public static function enqueue(): string {
		return self::style();
	}

	public static function season(): int {
		// One source of truth with the header selector: an explicit validated
		// ?season= wins, otherwise the in-play year when it has data.
		if ( class_exists( Season_Switcher::class ) ) {
			return Season_Switcher::displayed_season();
		}
		global $wpdb;
		$latest = (int) $wpdb->get_var( 'SELECT MAX(season) FROM ' . $wpdb->prefix . 'obitleague_standings_generations WHERE is_current = 1' );
		return $latest > 0 ? $latest : League_Service::current_season();
	}

public static function people_search_url(): string {
	$search_page_id = (int) get_option( 'obitleague_people_search_page', 0 );
	if ( $search_page_id ) {
		return get_permalink( $search_page_id );
	}
	$front_page_id = (int) get_option( 'page_on_front', 0 );
	if ( $front_page_id ) {
		$front_url = get_permalink( $front_page_id );
		if ( $front_url && str_ends_with( $front_url, home_url( '/' ) ) ) {
			return home_url( '/people/' );
		}
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
		if ( $season ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, name FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE season = %d ORDER BY name ASC', $season )
			);
		}
		return (array) $wpdb->get_results( 'SELECT id, name FROM ' . $wpdb->prefix . 'obitleague_leagues ORDER BY name ASC' );
	}

	private static function person_bits( int $post_id ): array {
		$birth = '' !== (string) get_post_meta( $post_id, 'obit_birth_date', true ) ? Import_Service::parse_partial( (string) get_post_meta( $post_id, 'obit_birth_date', true ) ) : null;
		$death = '' !== (string) get_post_meta( $post_id, 'obit_death_date', true ) ? Import_Service::parse_partial( (string) get_post_meta( $post_id, 'obit_death_date', true ) ) : null;
		$age   = ( $birth && $death && $death->is_exact() ) ? \Obitleague\Domain\Age::completed_at( $birth, $death->interpretations()[0] ) : null;
		return array( $birth, $death, $age );
	}

	public static function hero( $atts = array() ): string {
		$season = self::season();
		$kicker = Season_Switcher::is_active_selection()
			? 'Season ' . (int) $season . ' · archive view'
			: Season_Switcher::season_story();
		$out    = '<section class="ob-hero ob-anim"><span class="ob-hero__kicker">' . esc_html( $kicker ) . '</span>';
		$out   .= '<h1>Pick ten lives. Follow the year.</h1>';
		$out   .= '<p>Every confirmed, editor-approved death of a picked figure scores points — younger lives score more: max(1, 100 − age).</p>';
		$out   .= '<div class="ob-hero__cta">';
		$out   .= '<a href="' . esc_url( '/people/' ) . '">Browse the people</a>';
		$out   .= '<a class="ghost" href="' . esc_url( '/standings/' ) . '">View standings</a>';
		$out   .= '</div></section>';
		return self::style() . $out;
	}

	public static function stats( $atts = array() ): string {
		global $wpdb;
		$season = self::season();

		$deaths   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'obit_death_date' AND m.meta_value != '' WHERE p.post_type = 'obit_person' AND p.post_status = 'publish'" );
		$players  = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT user_id) FROM ' . $wpdb->prefix . 'obitleague_entries' );
		$leagues  = count( self::all_leagues() );
		$picks    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_entry_picks p JOIN ' . $wpdb->prefix . "obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'" );
		$points   = (int) $wpdb->get_var( 'SELECT COALESCE(SUM(award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards WHERE award_delta > 0' );

		$tiles = array(
			array( $deaths, 'Confirmed deaths' ),
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

		$out = '<section class="ob-card ob-anim ob-deaths-card ob-deaths-card--bare">';
		if ( ! $query->have_posts() ) {
			$out .= '<p><em>No confirmed deaths yet this season.</em></p></section>';
			return self::style() . $out;
		}
		foreach ( $query->posts as $post ) {
			[ $birth, $death, $age ] = self::person_bits( (int) $post->ID );
			$cause = (string) get_post_meta( $post->ID, 'obit_cause_status', true );
			$out  .= '<div class="ob-death">';
			$out  .= '<span class="ob-death__date">' . ( $death ? esc_html( $death->label() ) : '—' ) . '</span>';
			$out  .= '<span><span class="ob-death__name"><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></span>';
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
			if ( ! $rows ) {
				$out .= '<p><em>Standings not published yet.</em></p></section>';
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
		$a     = shortcode_atts( array( 'living' => '1', 'per_page' => 12 ), $atts, 'obitleague_people' );
		// The living/deceased toggle also comes from the query string — the
		// People mega-menu links advertise /people/?living=0 for the archive.
		$qs_living = isset( $_GET['living'] ) ? sanitize_text_field( (string) $_GET['living'] ) : '';
		$alive     = in_array( $qs_living, array( '0', '1' ), true ) ? ( '1' === $qs_living ) : ( '1' === (string) $a['living'] );

		// Browse controls: search term, sort order, letter jump-to, page.
		$search = isset( $_GET['q'] ) ? sanitize_text_field( (string) $_GET['q'] ) : '';
		$sort   = isset( $_GET['sort'] ) ? sanitize_key( (string) $_GET['sort'] ) : '';
		$letter = isset( $_GET['letter'] ) ? strtoupper( sanitize_text_field( (string) $_GET['letter'] ) ) : '';
		// Canonical /page/N/ URLs carry the number in the query vars, not $_GET.
		$paged  = max( 1, (int) ( $_GET['paged'] ?? ( get_query_var( 'paged' ) ?: 1 ) ) );
		if ( ! in_array( $sort, array( 'name', 'newest', 'oldest', 'most_picked' ), true ) ) {
			$sort = 'name';
		}
		$per_page = min( 48, max( 4, (int) $a['per_page'] ) );

		// Query-string filters advertised by the People/Picks mega menu.
		$occupation = isset( $_GET['occupation'] ) ? sanitize_text_field( (string) $_GET['occupation'] ) : '';
		$birth_year = isset( $_GET['birth_year'] ) ? sanitize_text_field( (string) $_GET['birth_year'] ) : '';
		$age_band   = isset( $_GET['age'] ) ? sanitize_text_field( (string) $_GET['age'] ) : '';

		$meta_query = array();
		// Living/dead toggle is the Picks vs People split.
		if ( $alive ) {
			$meta_query[] = array( 'key' => 'obit_death_date', 'compare' => 'NOT EXISTS' );
		} else {
			$meta_query[] = array( 'key' => 'obit_death_date', 'value' => '', 'compare' => '!=' );
		}

		// Occupation filter via taxonomy term slugs passed in the query string.
		// Occupations live in the obit_occupation taxonomy (mirrored from
		// Wikidata by People_Sync::sync_occupation_terms), so this is a real
		// tax_query — not postmeta.
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
		// Four-digit years compare correctly as strings against the stored
		// 'YYYY-MM-DD' birth dates.
		if ( $alive && preg_match( '/^(under|70|80|90|100|over)-(\d+)$|^(\d+)$/', $age_band, $m ) ) {
			$now_year = (int) current_time( 'Y' );
			$age_meta = array();				if ( isset( $m[1] ) && 'under' === $m[1] ) {
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
			$set_args             = $query_args;
			$set_args['posts_per_page'] = 2000;
			$set_args['fields']   = 'ids';
			unset( $set_args['paged'], $set_args['orderby'], $set_args['order'], $set_args['meta_key'] );
			$set_query = new \WP_Query( $set_args );
			$all_ids   = array_map( 'intval', (array) $set_query->posts );
			$pick_counts = Pick_Stats::pick_counts_by_uuid( (int) self::season() );
			usort(
				$all_ids,
				static function ( int $a_id, int $b_id ) use ( $pick_counts ): int {
					$a_uuid = (string) get_post_meta( $a_id, 'obit_uuid', true );
					$b_uuid = (string) get_post_meta( $b_id, 'obit_uuid', true );
					$primary = ( $pick_counts[ $b_uuid ] ?? 0 ) <=> ( $pick_counts[ $a_uuid ] ?? 0 );
					return 0 !== $primary ? $primary : ( $a_id <=> $b_id );
				}
			);
			$page_ids = array_slice( $all_ids, ( $paged - 1 ) * $per_page, $per_page );
			if ( $page_ids ) {
				$page_query = new \WP_Query(
					array(
						'post_type'      => Catalogue::POST_TYPE,
						'post_status'    => 'publish',
						'post__in'       => $page_ids,
						'orderby'        => 'post__in',
						'posts_per_page' => count( $page_ids ),
					)
				);
				$set_query->posts = $page_query->posts;
			} else {
				$set_query->posts = array();
			}
			$set_query->found_posts   = count( $all_ids );
			$set_query->max_num_pages = (int) max( 1, ceil( count( $all_ids ) / $per_page ) );
			$q = $set_query;
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

		$out .= '<div class="ob-people">';
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
			$out .= '<p class="ob-person__role">' . esc_html( (string) get_post_meta( $post->ID, 'obit_role', true ) ) . '</p>';
			$occ = People_Sync::occupation_labels( (int) $post->ID );
			if ( array() !== $occ ) {
				$out .= '<p class="ob-person__occ" title="' . esc_attr( implode( ', ', $occ ) ) . '">' . esc_html( implode( ', ', $occ ) ) . '</p>';
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

	public static function archive( $atts = array() ): string {
		$year  = isset( $_GET['ob_year'] ) ? sanitize_text_field( (string) $_GET['ob_year'] ) : '';
		$year  = preg_match( '/^\d{4}$/', $year ) ? $year : '';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( (string) $_GET['q'] ) : '';
		$letter = isset( $_GET['letter'] ) ? strtoupper( sanitize_text_field( (string) $_GET['letter'] ) ) : '';
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$sort   = isset( $_GET['sort'] ) && 'name' === sanitize_key( (string) $_GET['sort'] ) ? 'name' : 'recent';
		$args = array(
			'post_type'      => Catalogue::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 24,
			'paged'          => $paged,
			'meta_key'       => 'obit_death_date',
			'meta_compare'   => 'EXISTS',
			'orderby'        => 'recent' === $sort ? 'meta_value' : 'title',
			'order'          => 'recent' === $sort ? 'DESC' : 'ASC',
		);
		$archive_meta_query = array();
		if ( '' !== $year ) {
			$archive_meta_query[] = array( 'key' => 'obit_death_date', 'value' => $year, 'compare' => 'LIKE' );
		}
		if ( '' !== $search ) {
			$archive_meta_query[] = array( 'key' => 'obit_sort_name', 'value' => mb_strtolower( $search ), 'compare' => 'LIKE' );
		}
		if ( preg_match( '/^[A-Z]$/', $letter ) ) {
			$archive_meta_query[] = array( 'key' => 'obit_sort_name', 'value' => '^' . $letter, 'compare' => 'REGEXP' );
		}
		if ( $archive_meta_query ) {
			$args['meta_query'] = $archive_meta_query;
		}
		$q   = new \WP_Query( $args );
		$out = '<form class="ob-filters" method="get">';
		if ( '' !== $search ) {
			$out .= '<input type="hidden" name="q" value="' . esc_attr( $search ) . '" />';
		}
		$out .= '<select name="ob_year"><option value="">All years</option>';
		foreach ( array( '2026', '2025' ) as $y ) {
			$out .= '<option value="' . esc_attr( $y ) . '"' . selected( $year, $y, false ) . '>' . esc_html( $y ) . '</option>';
		}
		$out .= '</select><button type="submit">Filter</button>';
		$out .= '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search the archive…" aria-label="Search the death archive" />';
		$out .= '<button type="submit">Search</button></form>';
		$out .= '<p class="ob-archive__count">' . esc_html( number_format_i18n( (int) $q->found_posts ) ) . ' confirmed cases' . ( '' !== $year ? ' in ' . esc_html( $year ) : '' ) . ' · season ' . (int) self::season() . '</p>';
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
			$out .= '<p class="ob-person__role">' . esc_html( (string) get_post_meta( $post->ID, 'obit_role', true ) ) . '</p>';
			$occ = People_Sync::occupation_labels( (int) $post->ID );
			if ( array() !== $occ ) {
				$out .= '<p class="ob-person__occ" title="' . esc_attr( implode( ', ', $occ ) ) . '">' . esc_html( implode( ', ', $occ ) ) . '</p>';
			}
			$out .= '<p class="ob-person__dates">';
			$out .= $death ? esc_html( 'd. ' . $death->label() ) : '';
			$out .= ( null !== $age ) ? esc_html( ' · age ' . $age ) : '';
			$out .= '</p></article>';
		}
		$out .= '</div>';

		// Same pager as the people browse; keeps the year/search/letter scope.
		$archive_total_pages = (int) $q->max_num_pages;
		if ( $archive_total_pages > 1 ) {
			$archive_base = remove_query_arg( 'paged' );
			$out .= '<nav class="ob-people__pagination" aria-label="Archive pages">';
			$out .= '<span class="ob-people__count">page ' . esc_html( number_format_i18n( $paged ) ) . ' of ' . esc_html( number_format_i18n( $archive_total_pages ) ) . '</span>';
			$out .= '<span class="ob-people__links">';
			if ( $paged > 1 ) {
				$out .= '<a class="ob-page-link" rel="prev" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $archive_base ) ) . '">&larr; Previous</a>';
			}
			$archive_start = max( 1, $paged - 2 );
			$archive_end   = min( $archive_total_pages, $paged + 2 );
			if ( $archive_start > 1 ) {
				$out .= '<a class="ob-page-link" href="' . esc_url( $archive_base ) . '">1</a>';
				if ( $archive_start > 2 ) {
					$out .= '<span class="ob-page-gap">…</span>';
				}
			}
			for ( $p = $archive_start; $p <= $archive_end; ++$p ) {
				$href = 1 === $p ? $archive_base : add_query_arg( 'paged', $p, $archive_base );
				$out .= '<a class="ob-page-link' . ( $p === $paged ? ' is-current' : '' ) . '" href="' . esc_url( $href ) . '"' . ( $p === $paged ? ' aria-current="page"' : '' ) . '>' . esc_html( number_format_i18n( $p ) ) . '</a>';
			}
			if ( $archive_end < $archive_total_pages ) {
				if ( $archive_end < $archive_total_pages - 1 ) {
					$out .= '<span class="ob-page-gap">…</span>';
				}
				$out .= '<a class="ob-page-link" href="' . esc_url( add_query_arg( 'paged', $archive_total_pages, $archive_base ) ) . '">' . esc_html( number_format_i18n( $archive_total_pages ) ) . '</a>';
			}
			if ( $paged < $archive_total_pages ) {
				$out .= '<a class="ob-page-link" rel="next" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $archive_base ) ) . '">Next &rarr;</a>';
			}
			$out .= '</span></nav>';
		}
		return self::style() . $out;
	}

	public static function rules( $atts = array() ): string {
		$season = self::season();
		$steps  = array(
			array(
				'Get yourself into a league',
				'Obitleague is free to play. Create an account, verify your email, and you are in the main game for the coming season — your team competes against everyone else on the overall leaderboard. If your friends, family or office run a private league, they can share an invite code with you; side leagues are small, friendly and entirely optional, and they never change your main-game score.',
			),
			array(
				'Pick your ten',
				'Build a team of exactly ' . (int) \Obitleague\Domain\Value\Ruleset::TEAM_SIZE . ' living public figures from the people list. Everyone you pick must be at least ' . (int) \Obitleague\Domain\Value\Ruleset::MIN_AGE . ' years old when the season starts, and you cannot pick the same person twice. You can save a draft and keep editing it, but once the deadline passes — ' . \Obitleague\Domain\Value\Ruleset::DEADLINE_RULE . ' — your team is locked for the year. One second late is still late.',
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
		$out     .= '<p>The figures in this people list are real people, and the game treats them that way. Obitleague reports only what public sources and our editors confirm, credits every fact to its source, and exists for people who read the obituaries — not for shock. If a case touches an actively grieving family, editors may hold publication; the game waits. Discussion in the forum follows the same spirit: argue about picks and points as much as you like, but keep it decent.</p>';
		$out     .= '</section>';
		$out     .= '</div>';
		return self::style() . $out;
	}
}

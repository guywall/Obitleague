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

use Obitleague\Domain\Value\Cause_Status;

final class Shortcodes {

	private function __construct() {}

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
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

	/* ---------- blocks ---------- */

	public static function hero( $atts = array() ): string {
		$season = self::season();
		$out    = '<section class="ob-hero ob-anim"><span class="ob-hero__kicker">Season ' . (int) $season . ' · In play</span>';
		$out   .= '<h1>Pick ten lives. Follow the year.</h1>';
		$out   .= '<p>Every confirmed, editor-approved death of a picked figure scores points — younger lives score more: max(1, 100 − age).</p>';
		$out   .= '<div class="ob-hero__cta">';
		$out   .= '<a href="' . esc_url( '/person/' ) . '">Browse the catalogue</a>';
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
		$alive = '1' === (string) $a['living'];
		$q     = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => min( 48, max( 4, (int) $a['per_page'] ) ),
				'meta_query'     => $alive
					? array( array( 'key' => 'obit_death_date', 'compare' => 'NOT EXISTS' ) )
					: array( array( 'key' => 'obit_death_date', 'value' => '', 'compare' => '!=' ) ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = '<div class="ob-people">';
		foreach ( $q->posts as $post ) {
			[ $birth, $death ] = self::person_bits( (int) $post->ID );
			$out .= '<div class="ob-person' . ( $death ? ' ob-person--dead' : '' ) . ' ob-anim">';
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
			$out .= '</p></div>';
		}
		$out .= '</div>';
		return self::style() . $out;
	}

	/**
	 * Team directory: every submitted main-season team as a browsable card
	 * grid, with search and pagination. Teams are the social object — picks
	 * belong to them, not the other way round.
	 */
	public static function teams( $atts = array() ): string {
		$a = shortcode_atts( array( 'season' => self::season(), 'per_page' => 24 ), $atts, 'obitleague_teams' );
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
		$a         = shortcode_atts( array( 'season' => Pick_Stats::season_in_play(), 'per_page' => 24 ), $atts, 'obitleague_deaths' );
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
		$out = self::style();
		$out .= '<div class="ob-people__toolbar">';
		$out .= '<form class="ob-people__search ob-filters" method="get" role="search" aria-label="Search the obituaries">';
		$out .= '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Search the obituaries…" aria-label="Search the obituaries" />';
		$out .= '<button type="submit">Search</button>';
		if ( '' !== $search ) {
			$out .= '<a class="ob-people__filter-clear" href="' . esc_url( remove_query_arg( array( 'q', 'paged' ) ) ) . '">Reset</a>';
		}
		$out .= '</form></div>';
		$out .= '<p class="ob-people__count ob-deaths-index__count">' . esc_html( number_format_i18n( (int) $q->found_posts ) ) . ' confirmed deaths in ' . esc_html( (string) $season ) . '</p>';
		if ( ! $q->have_posts() ) {
			$out .= '<section class="ob-card"><p><em>No confirmed deaths recorded for ' . esc_html( (string) $season ) . ' yet.</em></p></section>';
			return $out;
		}
		$out .= '<div class="ob-people ob-people--archive ob-deaths-index">';
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
			$out .= '<span class="ob-person__status">In memoriam</span>';
			$out .= '<p class="ob-person__name"><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></p>';
			$out .= '<p class="ob-person__role">' . esc_html( (string) get_post_meta( $post_id, 'obit_role', true ) ) . '</p>';
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
			$out .= '</article>';
		}
		$out .= '</div>';
		$total_pages = (int) $q->max_num_pages;
		if ( $total_pages > 1 ) {
			$base = remove_query_arg( 'paged' );
			$out .= '<nav class="ob-people__pagination" aria-label="Obituary pages">';
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

	public static function archive( $atts = array() ): string {
		$year = isset( $_GET['ob_year'] ) ? sanitize_text_field( (string) $_GET['ob_year'] ) : '';
		$args = array(
			'post_type'      => Catalogue::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 24,
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
		return self::style() . $out;
	}

	/* ---------- rules ---------- */

	public static function rules( $atts = array() ): string {
		$season = self::season();
		$steps  = array(
			array(
				'Get yourself into a league',
				'Obitleague is free to play. Create an account, verify your email, and you are in the main game for the coming season — your team competes against everyone else on the overall leaderboard. If your friends, family or office run a private league, they can share an invite code with you; side leagues are small, friendly and entirely optional, and they never change your main-game score.',
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

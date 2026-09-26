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

	/* ---------- helpers ---------- */

	private static function season(): int {
		// Prefer the season that actually has published standings (the one in
		// play); fall back to the season currently open for entries.
		global $wpdb;
		$latest = (int) $wpdb->get_var( 'SELECT MAX(season) FROM ' . $wpdb->prefix . 'obitleague_standings_generations WHERE is_current = 1' );
		return $latest > 0 ? $latest : League_Service::current_season();
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
		$out    = '<section class="ob-hero"><h1>Pick ten lives. Follow the year.</h1>';
		$out   .= '<p>The ' . (int) $season . ' season is in play. Every confirmed, editor-approved death of a picked figure scores points — younger lives score more: max(1, 100 − age).</p>';
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
			$out .= '<div class="ob-stat"><span class="ob-stat__num">' . esc_html( number_format_i18n( (int) $num ) ) . '</span><span class="ob-stat__label">' . esc_html( $label ) . '</span></div>';
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

		$out = '<section class="ob-card"><h2 class="ob-card__title">Recently confirmed deaths</h2>';
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
				$out .= '<span class="ob-badge">age ' . esc_html( (string) $age ) . ' · ' . esc_html( (string) \Obitleague\Domain\Value\Ruleset::points_for_age( (int) $age ) ) . ' pts</span>';
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
		$a      = shortcode_atts( array( 'league' => '', 'season' => self::season(), 'top' => 0 ), $atts, 'obitleague_standings' );
		$season = (int) $a['season'];

		$league_ids = '' !== $a['league'] ? array( self::league_id_by_name( (string) $a['league'] ) ) : array_map( static fn ( $l ) => (int) $l->id, self::all_leagues( $season ) );

		$out = '<div class="ob-grid' . ( count( $league_ids ) > 2 ? ' ob-grid--3' : '' ) . '">';
		foreach ( $league_ids as $league_id ) {
			$name = $league_id ? (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT name FROM ' . $GLOBALS['wpdb']->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) ) : '';
			$out .= '<section class="ob-card"><h2 class="ob-card__title">' . esc_html( $name ?: 'League' ) . '</h2>';
			$rows = $league_id ? Standings_Service::current( $league_id, $season ) : null;
			if ( ! $rows ) {
				$out .= '<p><em>Standings not published yet.</em></p></section>';
				continue;
			}
			if ( $a['top'] > 0 ) {
				$rows = array_slice( $rows, 0, (int) $a['top'] );
			}
			$out .= '<table class="ob-table"><thead><tr><th>#</th><th>Player</th><th>Pts</th><th>Scoring</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$out .= '<tr><td class="ob-rank">' . esc_html( (string) $row['rank'] ) . '</td><td>' . esc_html( $row['player'] ) . '</td><td class="ob-pts">' . esc_html( (string) $row['points'] ) . '</td><td>' . esc_html( (string) $row['scoring_picks'] ) . '</td></tr>';
			}
			$out .= '</tbody></table></section>';
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
			$out .= '<div class="ob-person">';
			$out .= '<p class="ob-person__name"><a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></p>';
			$out .= '<p class="ob-person__role">' . esc_html( (string) get_post_meta( $post->ID, 'obit_role', true ) ) . '</p>';
			$out .= '<p class="ob-person__dates">';
			$out .= $birth ? esc_html( 'b. ' . $birth->label() ) : '';
			$out .= $death ? esc_html( ' · d. ' . $death->label() ) : '';
			$out .= '</p></div>';
		}
		$out .= '</div>';
		return self::style() . $out;
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
		$out .= '</select><button type="submit">Filter</button></form><div class="ob-grid ob-grid--3">';
		foreach ( $q->posts as $post ) {
			[ $birth, $death, $age ] = self::person_bits( (int) $post->ID );
			$out .= '<div class="ob-card"><h3 class="ob-card__title"><a href="' . esc_url( (string) get_permalink( $post ) ) . '" style="color:inherit;text-decoration:none">' . esc_html( get_the_title( $post ) ) . '</a></h3>';
			$out .= '<p class="ob-person__role">' . esc_html( (string) get_post_meta( $post->ID, 'obit_role', true ) ) . '</p>';
			$out .= '<p class="ob-person__dates">';
			$out .= $death ? esc_html( 'd. ' . $death->label() ) : '';
			$out .= ( null !== $age ) ? esc_html( ' · age ' . $age ) : '';
			$out .= '</p></div>';
		}
		$out .= '</div>';
		return self::style() . $out;
	}
}

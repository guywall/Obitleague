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
			$lead = (int) ( $rows[0]['points'] ?? 0 );
			foreach ( $rows as $row ) {
				$rank  = (int) $row['rank'];
				$medal = '<span class="ob-medal ob-medal--' . $rank . '">' . (int) $row['rank'] . '</span>';
				$lead_class = ( (int) $row['points'] === $lead && $lead > 0 ) ? ' ob-lead' : '';
				$out .= '<tr><td class="ob-rank">' . $medal . '</td><td>' . esc_html( $row['player'] ) . '</td><td class="ob-pts' . $lead_class . '">' . esc_html( (string) $row['points'] ) . '</td><td>' . esc_html( (string) $row['scoring_picks'] ) . '</td></tr>';
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
				'Join',
				'Leagues are private. An editor issues an invite token, valid for ' . (int) \Obitleague\Domain\League_Rules::INVITE_TTL_DAYS . ' days — one league per token, members join by link.',
			),
			array(
				'Draft',
				'Pick exactly ' . (int) \Obitleague\Domain\Value\Ruleset::TEAM_SIZE . ' living public figures (minimum age ' . (int) \Obitleague\Domain\Value\Ruleset::MIN_AGE . ' at season start) and submit before the deadline: ' . \Obitleague\Domain\Value\Ruleset::DEADLINE_RULE . ' Submissions at 23:59:59.9 on 31 December are too late — the lock is absolute.',
			),
			array(
				'Follow',
				'Death reports are collected from public feeds. Nothing scores automatically: editors verify identity, the exact date, and the cause before any case is approved. One approved death event per person, ever.',
			),
			array(
				'Score',
				'Each approved death scores max(1, 100 − age at death) to every team holding that pick. Standings stay provisional until ' . rtrim( \Obitleague\Domain\Value\Ruleset::SETTLEMENT_RULE, '.' ) . ', then the season settles.',
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
		$out     .= '<p>A confirmed death scores <strong>max(1, 100 − age)</strong> — the younger the life, the heavier the loss. Worked examples for season ' . (int) $season . ':</p>';
		$out     .= '<table class="ob-table"><thead><tr><th>Age at death</th><th>Points scored</th></tr></thead><tbody>';
		foreach ( $examples as $age ) {
			$pts = \Obitleague\Domain\Value\Ruleset::points_for_age( $age );
			$out .= '<tr><td>' . esc_html( (string) $age ) . '</td><td class="ob-pts">' . esc_html( (string) $pts ) . '</td></tr>';
		}
		$out     .= '</tbody></table><p class="ob-rules__note">Deaths with month-only precision are verified but never scored — the record waits for an exact date.</p>';
		$out     .= '</section>';

		$out     .= '<section class="ob-card ob-rules__ethics ob-anim"><h2 class="ob-card__title">Played with respect</h2>';
		$out     .= '<p>The figures in this catalogue are real people. Obitleague reports only what public sources and our editors confirm, credits every fact, and exists for people who read the obituaries — not for shock. If a case touches an actively grieving family, editors may hold publication; the game waits.</p>';
		$out     .= '</section>';
		$out     .= '</div>';
		return self::style() . $out;
	}
}

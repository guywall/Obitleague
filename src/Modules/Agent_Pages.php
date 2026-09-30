<?php
/**
 * AI competitor pages.
 *
 * Public, indexable surfaces for the Humans vs AI competition: the AI
 * directory, individual agent profiles, the human-vs-AI comparison page and
 * the Bring-Your-Own-AI integration page. These pages read the shared
 * standings and agent metadata only — they never expose pre-close picks,
 * and they never recompute scores.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Agent_Pages {

	private function __construct() {}

	public static function boot(): void {
		add_action( 'init', array( self::class, 'rewrites' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'template_include', array( self::class, 'maybe_route' ), 30 );
		add_filter( 'document_title_parts', array( self::class, 'title_parts' ) );
		add_action( 'wp_head', array( self::class, 'profile_meta' ), 2 );

		add_shortcode( 'obitleague_ai_directory', array( self::class, 'directory_shortcode' ) );
		add_shortcode( 'obitleague_vs_stats', array( self::class, 'vs_stats_shortcode' ) );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^ai/([a-z0-9-]+)/?$', 'index.php?ob_ai_slug=$matches[1]', 'top' );
		add_rewrite_rule( '^ai/?$', 'index.php?ob_ai_list=1', 'top' );
	}

	/** @param string[] $vars */
	public static function query_vars( array $vars ): array {
		$vars[] = 'ob_ai_slug';
		$vars[] = 'ob_ai_list';
		return $vars;
	}

	public static function maybe_route( string $template ): string {
		$slug = (string) get_query_var( 'ob_ai_slug' );
		if ( '' !== $slug ) {
			status_header( 200 );
			return OBITLEAGUE_DIR . 'src/Templates/ai-profile.php';
		}
		if ( '1' === (string) get_query_var( 'ob_ai_list' ) ) {
			status_header( 200 );
			return OBITLEAGUE_DIR . 'src/Templates/ai-directory.php';
		}
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( 'ai-vs-humans' === $path ) {
			status_header( 200 );
			return OBITLEAGUE_DIR . 'src/Templates/ai-vs-humans.php';
		}
		if ( 'ai-integrate' === $path ) {
			status_header( 200 );
			return OBITLEAGUE_DIR . 'src/Templates/agents-integrate.php';
		}
		return $template;
	}

	/** @param array<string,string> $parts */
	public static function title_parts( array $parts ): array {
		$slug = (string) get_query_var( 'ob_ai_slug' );
		if ( '' !== $slug ) {
			$agent = Agent_Service::get_agent( 0, $slug );
			if ( $agent ) {
				$parts['title'] = (string) $agent->name . ' — AI competitor';
			}
			return $parts;
		}
		if ( '1' === (string) get_query_var( 'ob_ai_list' ) ) {
			$parts['title'] = 'AI competitors';
		}
		return $parts;
	}

	/** Meta description and Open Graph for an agent profile. */
	public static function profile_meta(): void {
		$slug = (string) get_query_var( 'ob_ai_slug' );
		if ( '' === $slug ) {
			return;
		}
		$agent = Agent_Service::get_agent( 0, $slug );
		if ( ! $agent ) {
			return;
		}
		$description = '' !== (string) $agent->description
			? (string) $agent->description
			: sprintf( '%s competes against humans in the Obitleague death pool.', (string) $agent->name );
		$description = mb_substr( wp_strip_all_tags( $description ), 0, 160 );
		printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:type" content="profile" />' . "\n" );
		printf( '<meta property="og:title" content="%s — Obitleague AI competitor" />' . "\n", esc_attr( (string) $agent->name ) );
		printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( Agent_Service::profile_url( (string) $agent->slug ) ) );
	}

	/** Agent directory listing shortcode. */
	public static function directory_shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'per_page' => 24 ), $atts, 'obitleague_ai_directory' );
		$agents = Agent_Service::public_agents( min( 100, max( 1, (int) $atts['per_page'] ) ) );
		return Shortcodes::enqueue() . self::render_directory( $agents );
	}

	/** Human vs AI snapshot tiles shortcode (Elementor-friendly). */
	public static function vs_stats_shortcode( $atts = array() ): string {
		$atts  = shortcode_atts( array( 'season' => 0 ), $atts, 'obitleague_vs_stats' );
		$season = (int) $atts['season'] > 0 ? (int) $atts['season'] : Pick_Stats::season_in_play();
		$snapshot = Vs_Stats::snapshot( $season );
		return Shortcodes::enqueue() . self::render_vs_tiles( $snapshot, true );
	}

	/** Shared directory rendering (used by shortcode and template). */
	public static function render_directory( array $agents ): string {
		$out = '<div class="ob-ai-grid">';
		foreach ( $agents as $agent ) {
			$url   = Agent_Service::profile_url( (string) $agent->slug );
			$model = '' !== (string) $agent->model ? (string) $agent->model : '';
			$out  .= '<a class="ob-ai-card" href="' . esc_url( $url ) . '">';
			$out  .= '<span class="ob-ai-card__category ob-ai-card__category--' . esc_attr( (string) $agent->category ) . '">'
				. esc_html( 'official' === $agent->category ? 'Obitleague AI' : 'Community AI' ) . '</span>';
			$out  .= '<span class="ob-ai-card__name">' . esc_html( (string) $agent->name ) . '</span>';
			if ( '' !== (string) $agent->description ) {
				$out .= '<span class="ob-ai-card__desc">' . esc_html( mb_substr( (string) $agent->description, 0, 120 ) ) . '</span>';
			}
			if ( '' !== $model ) {
				$verified = (int) $agent->model_verified ? ' · verified' : '';
				$out     .= '<span class="ob-ai-card__model">' . esc_html( $model . $verified ) . '</span>';
			}
			$out .= '</a>';
		}
		if ( ! $agents ) {
			$out .= '<p class="ob-ai-empty">No AI competitors have registered yet. <a href="' . esc_url( home_url( '/ai-integrate/' ) ) . '">Bring your own AI</a>.</p>';
		}
		return $out . '</div>';
	}

	/** Shared VS snapshot tiles rendering. */
	public static function render_vs_tiles( array $snapshot, bool $with_caveat = true ): string {
		$humans = $snapshot['humans'];
		$ai     = $snapshot['ai'];
		$out    = '<div class="ob-vs-grid">';
		foreach ( array(
			array( 'Human teams', number_format_i18n( $humans['teams'] ) ),
			array( 'AI teams', number_format_i18n( $ai['teams'] ) ),
			array( 'Human points', number_format_i18n( $humans['points'] ) ),
			array( 'AI points', number_format_i18n( $ai['points'] ) ),
			array( 'Avg human team', number_format_i18n( (float) $humans['average_points'], 1 ) ),
			array( 'Avg AI team', number_format_i18n( (float) $ai['average_points'], 1 ) ),
		) as $tile ) {
			$out .= '<div class="ob-stat"><span class="ob-stat__num">' . esc_html( $tile[1] ) . '</span><span class="ob-stat__label">' . esc_html( $tile[0] ) . '</span></div>';
		}
		$out .= '</div>';
		if ( $with_caveat ) {
			$out .= '<p class="ob-vs-caveat">' . esc_html( Vs_Stats::caveat() ) . '</p>';
		}
		return $out;
	}
}

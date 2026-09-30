<?php
declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Modules\Pick_Stats;
use Obitleague\Modules\League_Service;
use Obitleague\Modules\Shortcodes;
use Obitleague\Modules\Season_Switcher;

final class Header {

	private function __construct() {}

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );	add_action( 'wp_head', array( self::class, 'print_base_style_tag' ), 9 );
	add_action( 'wp_body_open', array( self::class, 'render' ) );
}

public static function assets(): void {
	$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
	wp_register_script(
		'obitleague-header',
		OBITLEAGUE_DIR_URL . "assets/header{$suffix}.js",
		array(),
		OBITLEAGUE_VERSION,
		true
	);
}

public static function print_base_style_tag(): void {
	if ( did_action( 'wp_enqueue_scripts' ) ) {
		$inline = self::base_style();
		if ( $inline ) {
			wp_add_inline_style(
				'ob-chrome',
				$inline
			);
		}
	}
}

private static function base_style(): string {
	// The header is styled entirely by chrome.css; nothing to inject here.
	return '';
}

public static function render(): void {
	$settings = self::settings();
	$logged_in = is_user_logged_in();
	$entry_season = League_Service::current_season();

	// Picks analytics are optional; never let an unavailable summary break every page.
	$picks_stats = array();
	if ( method_exists( Pick_Stats::class, 'season_picks_summary' ) ) {
		$picks_stats = (array) Pick_Stats::season_picks_summary( Pick_Stats::season_in_play() );
	}

	$mega_columns = self::mega_columns();

	$search_url = Shortcodes::people_search_url();
	if ( ! $search_url ) {
		$search_url = home_url( '/people/' );
	}

	$cta_label = isset( $settings['hero_cta_label'] ) ? (string) $settings['hero_cta_label'] : '';
	if ( '' === $cta_label ) {
		$cta_label = $logged_in ? 'My game' : 'Choose your ' . $entry_season . ' team';
	}

	$cta_url = $logged_in ? home_url( '/my-leagues/#build-team' ) : home_url( '/register/' );
	$signin_url = home_url( '/login/?redirect_to=' . rawurlencode( home_url( '/my-leagues/' ) ) );

	$primary = isset( $settings['primary'] ) ? (array) $settings['primary'] : array();

	if ( did_action( 'wp_enqueue_scripts' ) ) {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script(
			'obitleague-header',
			OBITLEAGUE_DIR_URL . "assets/header{$suffix}.js",
			array(),
			OBITLEAGUE_VERSION,
			true
		);

		wp_enqueue_style(
			'ob-chrome',
			OBITLEAGUE_DIR_URL . 'assets/chrome.css',
			array(),
			OBITLEAGUE_VERSION
		);

		wp_add_inline_style(
			'ob-chrome',
			self::base_style()
		);
	}

	?>
<header class="ob-header ob-js" data-ob-header>
	<div class="ob-header__inner">
		<a class="ob-header__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Obitleague home">
			<span class="ob-mark" aria-hidden="true">O</span>
			<span class="ob-header__name">Obitleague</span>				<span class="ob-header__season" title="<?php echo esc_attr( Season_Switcher::season_story() ); ?>"><?php echo esc_html( (string) Pick_Stats::season_in_play() ); ?></span>
		</a>

		<button class="ob-header__toggle" type="button" aria-expanded="false" aria-controls="ob-header-menu" aria-label="<?php esc_attr_e( 'Menu', 'obitleague' ); ?>">
			<span></span><span></span><span></span>
		</button>

		<nav class="ob-header__panel" id="ob-header-menu" aria-label="Site menu">
		<div class="ob-header__search">
			<?php if ( $search_url ) : ?>
			<form class="ob-header__search-form" method="get" action="<?php echo esc_url( $search_url ); ?>" role="search" aria-label="Search people">
				<input
					type="search"
					name="s"
					placeholder="Search people"
					autocomplete="off"
					aria-label="Search people"
					required
				/>
				<button type="submit" class="ob-btn ob-btn--secondary"><?php esc_html_e( 'Search', 'obitleague' ); ?></button>
			</form>
			<?php endif; ?>
		</div>

		<div class="ob-header__actions">
			<?php foreach ( $primary as $item ) : ?>
				<?php
				$label = isset( $item['label'] ) ? (string) $item['label'] : '';
				$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
				$mega  = ( isset( $item['mega'] ) && 'yes' === $item['mega'] );
				?>
				<?php if ( '' === $label ) : ?>
					<?php continue; ?>
				<?php endif; ?>

				<?php if ( $mega && ! empty( $mega_columns ) ) : ?>
					<div class="ob-header__item">
						<a class="ob-header__link" href="<?php echo esc_url( $url ?: '#' ); ?>"><?php echo esc_html( $label ); ?></a>
						<button class="ob-header__chev" type="button" aria-expanded="false" aria-label="<?php echo esc_attr( sprintf( __( 'Toggle %s submenu', 'obitleague' ), $label ) ); ?>">⌵</button>
						<div class="ob-header__sub" data-ob-mega>
							<div class="ob-header__sub-grid">
								<?php foreach ( $mega_columns as $col ) : ?>
									<?php
									$heading = isset( $col['heading'] ) ? (string) $col['heading'] : '';
									$links   = isset( $col['links'] ) ? (array) $col['links'] : array();
									?>
									<?php if ( '' !== $heading ) : ?>
										<div class="ob-header__sub-col">
											<h4><?php echo esc_html( $heading ); ?></h4>
											<ul>
												<?php foreach ( $links as $link ) : ?>
													<?php
													$link_label = isset( $link['label'] ) ? (string) $link['label'] : '';
													$link_url   = isset( $link['url'] ) ? (string) $link['url'] : '';
													?>
													<?php if ( '' === $link_label ) : ?>
														<?php continue; ?>
													<?php endif; ?>
													<li>
														<a href="<?php echo esc_url( $link_url ?: '#' ); ?>"><?php echo esc_html( $link_label ); ?></a>
													</li>
												<?php endforeach; ?>
											</ul>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>

								<?php if ( ! empty( $picks_stats ) ) : ?>
									<div class="ob-header__picks">
										<h4><?php esc_html_e( 'Most picked', 'obitleague' ); ?></h4>
										<div class="ob-header__picks-row">
											<?php foreach ( $picks_stats as $pick ) : ?>
												<?php
												$team_label = isset( $pick['team_label'] ) ? (string) $pick['team_label'] : '';
												$count      = isset( $pick['count'] ) ? (int) $pick['count'] : 0;
												$percent    = isset( $pick['percent'] ) ? (float) $pick['percent'] : 0.0;
												?>
												<div class="ob-header__picks-item">
													<strong><?php echo esc_html( $team_label ); ?></strong>
													<span class="ob-header__meta"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
													<span class="ob-header__meta"><?php echo esc_html( number_format_i18n( round( $percent, 1 ), 1 ) ); ?>%</span>
												</div>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php else : ?>
					<a class="ob-header__link" href="<?php echo esc_url( $url ?: '#' ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php if ( $logged_in ) : ?>
				<a class="ob-header__link" href="<?php echo esc_url( home_url( '/my-leagues/' ) ); ?>"><?php esc_html_e( 'My leagues', 'obitleague' ); ?></a>
				<a class="ob-header__link" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'obitleague' ); ?></a>
			<?php else : ?>
				<a class="ob-header__link" href="<?php echo esc_url( $signin_url ); ?>"><?php esc_html_e( 'Sign in', 'obitleague' ); ?></a>
				<a class="ob-header__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( ! $logged_in ) : ?>
		<div class="ob-header__mobile-join">
			<a class="ob-header__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
		</div>
		<?php endif; ?>
		</nav>
	</div>
</header>
<?php
	}

	private static function settings(): array {
		$defaults = array(
			'primary'         => array(
				array( 'label' => 'People',         'url' => home_url( '/people/' ),         'mega' => 'yes' ),
				array( 'label' => 'Teams',          'url' => home_url( '/teams/' ),         'mega' => 'yes' ),
				array( 'label' => 'Obituaries',     'url' => home_url( '/obituaries/' ),    'mega' => 'no' ),
				array( 'label' => 'Standings',      'url' => home_url( '/standings/' ),      'mega' => 'no' ),
				array( 'label' => 'Stats',          'url' => home_url( '/stats/' ),          'mega' => 'no' ),
				array( 'label' => 'Rules',         'url' => home_url( '/rules/' ),         'mega' => 'no' ),
				array( 'label' => 'Forum',         'url' => home_url( '/forum/' ),         'mega' => 'no' ),
			),
			'hero_cta_label' => '',
		);

		return wp_parse_args( (array) get_theme_mod( 'ob_header_settings', array() ), $defaults );
	}

	private static function mega_columns(): array {
		return array(
			array(
				'heading' => 'Most picked',
				'links'   => array(
					array( 'label' => 'Most picked',          'url' => home_url( '/stats/#most-picked' ) ),
					array( 'label' => 'Flying under the radar', 'url' => home_url( '/stats/#under-radar' ) ),
				),
			),
			array(
				'heading' => 'Browse',
				'links'   => array(
					array( 'label' => 'By occupation', 'url' => self::browse_url_for_occupation() ),
					array( 'label' => 'By birth year', 'url' => self::browse_url_for_birth_year() ),
					array( 'label' => 'By age band',  'url' => self::browse_url_for_age_band() ),
				),
			),
		);
	}

	/** Default occupation browse link for the mega menu. */
	private static function browse_url_for_occupation(): string {
		$home = home_url( '/people/' );
		$term = self::largest_occupation_term();
		if ( $term ) {
			$url = add_query_arg( 'occupation', $term->slug, $home );
			$url = add_query_arg( 'living', 1, $url );
			return $url;
		}
		return $home;
	}

	/** Default birth-year browse link for the mega menu. */
	private static function browse_url_for_birth_year(): string {
		$home = home_url( '/people/' );
		$y = self::most_common_birth_year();
		if ( $y ) {
			$url = add_query_arg( 'birth_year', (string) $y, $home );
			$url = add_query_arg( 'living', 1, $url );
			return $url;
		}
		return $home;
	}

	/** Default age-band browse link for the mega menu. */
	private static function browse_url_for_age_band(): string {
		$home = home_url( '/people/' );
		$y = self::largest_age_band_anchor();
		if ( $y ) {
			$url = add_query_arg( 'age', 'under-' . (string) $y, $home );
			$url = add_query_arg( 'living', 1, $url );
			return $url;
		}
		return $home;
	}

	/** Largest occupation term by published person count. */
	private static function largest_occupation_term(): ?\WP_Term {
		$terms = get_terms(
			array(
				'taxonomy'   => Catalogue::TAX_OCCUPATION,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 1,
			)
		);
		return is_array( $terms ) && $terms ? ($terms[0] ?? null) : null;
	}

	/** Most common birth year among living catalogue figures. */
	private static function most_common_birth_year(): ?int {
		global $wpdb;
		$year = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} pm
				 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s
				   AND p.post_status = 'publish'
				   AND pm.meta_key = 'obit_birth_date'
				   AND p.ID NOT IN (
				       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_death_date' AND meta_value <> ''
				   )
				 ORDER BY pm.meta_id DESC LIMIT 1",
				Catalogue::POST_TYPE
			)
		);
		if ( ! preg_match( '/^\d{4}$/', (string) $year ) ) {
			return null;
		}
		return $year;
	}

	/** Anchor age band for the mega menu: the upper bound of the largest living cohort. */
	private static function largest_age_band_anchor(): ?int {
		$year = self::most_common_birth_year();
		if ( ! $year ) {
			return null;
		}
		$now  = (int) date_i18n( 'Y' );
		$age  = $now - $year;
		if ( $age < 0 ) {
			return null;
		}
		// Round up to a friendly band: nearest 10.
		return (int) ceil( $age / 10 ) * 10;
	}

	public static function enqueue_script(): void {
		if ( ! function_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script(
			'obitleague-header',
			OBITLEAGUE_DIR_URL . "assets/header{$suffix}.js",
			array(),
			OBITLEAGUE_VERSION,
			true
		);
	}

	public static function script_url(): string {
		if ( ! function_exists( '\Elementor\Widget_Base' ) ) {
			return '';
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		return OBITLEAGUE_DIR_URL . "assets/header{$suffix}.js";
	}
}

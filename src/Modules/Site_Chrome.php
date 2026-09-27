<?php
/**
 * Site chrome: branded navigation, footer, web fonts and motion.
 *
 * Hello Elementor's default header/footer render only a bare site title and
 * "All rights reserved" line; Obitleague replaces them with its own chrome.
 * The header is injected at wp_body_open, the footer at wp_footer (before
 * core script printing); the theme's own header/footer are hidden via CSS
 * (body.ob-on) so both mechanisms can coexist safely.
 *
 * Fonts: Fraunces (display serif) + Public Sans (UI) from Google Fonts.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Site_Chrome {

	private function __construct() {}

	public static function boot(): void {
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'wp_body_open', array( self::class, 'render_header' ) );
		add_action( 'wp_footer', array( self::class, 'render_footer' ), 1 );
	}

	public static function body_class( array $classes ): array {
		if ( is_admin() ) {
			return $classes;
		}
		$classes[] = 'ob-on';
		return $classes;
	}

	public static function assets(): void {
		wp_register_style( 'obitleague', OBITLEAGUE_DIR_URL . 'assets/obitleague.css', array(), OBITLEAGUE_VERSION );
		wp_enqueue_style( 'ob-chrome', OBITLEAGUE_DIR_URL . 'assets/chrome.css', array( 'obitleague' ), OBITLEAGUE_VERSION );
		add_action( 'wp_head', array( self::class, 'fonts' ), 2 );
	}

	/** Display fonts: Fraunces for headings, Public Sans for UI. */
	public static function fonts(): void {
		echo "<link rel='preconnect' href='https://fonts.googleapis.com' />\n";
		echo "<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin />\n";
		echo "<link href='https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700;9..144,900&family=Public+Sans:wght@400;500;600;700;800&display=swap' rel='stylesheet' />\n";
	}

	/* ---------- navigation ---------- */

	/** Nav items from the Primary menu, with a sane fallback list. */
	private static function nav_items(): array {
		$items = array();
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		if ( ! empty( $locations['primary'] ) ) {
			$menu_items = wp_get_nav_menu_items( (int) $locations['primary'] );
			if ( $menu_items ) {
				foreach ( $menu_items as $mi ) {
					$items[] = array(
						'label' => (string) $mi->title,
						'url'   => (string) $mi->url,
					);
				}
			}
		}
		if ( ! $items ) {
			$items = array(
				array( 'label' => 'Home', 'url' => home_url( '/' ) ),
				array( 'label' => 'Standings', 'url' => home_url( '/standings/' ) ),
				array( 'label' => 'People', 'url' => home_url( '/catalogue/' ) ),
				array( 'label' => 'Death Archive', 'url' => home_url( '/archive/' ) ),
				array( 'label' => 'Rules', 'url' => home_url( '/rules/' ) ),
			);
		}
		// Hide the primary-menu duplicate of account pages (rendered below
		// from the auth-aware fallback list instead).
		$items = array_values( array_filter(
			$items,
			static function ( array $item ): bool {
				$path = (string) wp_parse_url( (string) $item['url'], PHP_URL_PATH );
				return ! preg_match( '~/(my-leagues|join)/?$~', $path );
			}
		) );
		// Auth-aware account entries (plan §5: My leagues + join paths).
		$items[] = array( 'label' => 'My Leagues', 'url' => home_url( '/my-leagues/' ) );
		if ( is_user_logged_in() ) {
			$items[] = array( 'label' => 'Log out', 'url' => wp_logout_url( home_url( '/' ) ) );
		} else {
			$items[] = array( 'label' => 'Join', 'url' => home_url( '/join/' ) );
		}
		return $items;
	}

	private static function is_current( string $url ): bool {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = (string) wp_parse_url( $req, PHP_URL_PATH );
		$target = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/' === $target ) {
			return '/' === $path || '' === $path;
		}
		if ( '' === $target ) {
			return false;
		}
		// Route families roll up to their parent nav item: league pages
		// highlight Standings, person profiles highlight People.
		$family = array(
			'/standings/' => '~^/(standings|league)/~',
			'/catalogue/' => '~^/(catalogue|person)/~',
		);
		foreach ( $family as $base => $re ) {
			if ( $target === $base || str_starts_with( $path, $base ) ) {
				return (bool) preg_match( $re, $path ) && ( $path === $target || str_starts_with( $path, $target ) || str_starts_with( $target, $path ) || preg_match( $re, $path ) );
			}
		}
		return str_starts_with( $path, $target );
	}

	public static function render_header(): void {
		if ( ! is_front_page() && ! is_singular() && ! is_archive() && ! is_post_type_archive() && ! is_tax() && ! is_page() && ! is_search() && ! is_home() ) {
			// Unknown templates: still render; chrome is site-wide.
		}
		$season = date_i18n( 'Y' );
		?>
		<a class="ob-skip" href="#ob-main">Skip to content</a>
		<header class="ob-nav" data-ob-nav>
			<div class="ob-nav__inner">
				<a class="ob-nav__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Obitleague home">
					<span class="ob-mark" aria-hidden="true">O</span>
					<span class="ob-nav__name">Obitleague</span>
					<span class="ob-nav__season"><?php echo esc_html( $season ); ?></span>
				</a>
				<button class="ob-nav__toggle" type="button" aria-expanded="false" aria-controls="ob-nav-menu" aria-label="Menu">
					<span></span><span></span><span></span>
				</button>
				<nav class="ob-nav__menu" id="ob-nav-menu" aria-label="Primary">
					<?php $logged_in = is_user_logged_in(); ?>
					<?php foreach ( self::nav_items() as $item ) : ?>
						<?php if ( 'Log out' === $item['label'] && ! $logged_in ) { continue; } ?>
						<a class="ob-nav__link<?php echo self::is_current( $item['url'] ) ? ' is-current' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
					<?php endforeach; ?>
					<?php if ( ! $logged_in ) : ?>
						<a class="ob-nav__link" href="<?php echo esc_url( wp_login_url( home_url( '/my-leagues/' ) ) ); ?>">Sign in</a>
					<?php endif; ?>
					<a class="ob-nav__join" href="<?php echo esc_url( home_url( $logged_in ? '/my-leagues/' : '/join/' ) ); ?>"><?php echo $logged_in ? 'My game' : 'Join a league'; ?></a>
				</nav>
			</div>
		</header>
		<main id="ob-main" class="ob-main">
		<?php
	}

	public static function render_footer(): void {
		$year  = date_i18n( 'Y' );				$links = array(
				'Standings'     => home_url( '/standings/' ),
				'People'        => home_url( '/catalogue/' ),
				'Death Archive' => home_url( '/archive/' ),
				'Rules'         => home_url( '/rules/' ),
				'My Leagues'    => home_url( '/my-leagues/' ),
				'Join a league' => home_url( '/join/' ),
			);
		?>
		</main><!-- #ob-main -->
		<footer class="ob-foot">
			<div class="ob-foot__inner">
				<div class="ob-foot__brand">
					<a class="ob-foot__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<span class="ob-mark" aria-hidden="true">O</span> Obitleague
					</a>
					<p>A fantasy dead-pool for people who read the obituaries. Pick ten public figures before the season locks; score when &mdash; and only when &mdash; the editors confirm.</p>
				</div>
				<nav class="ob-foot__col" aria-label="Explore">
					<h3>Explore</h3>
					<?php foreach ( $links as $label => $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="ob-foot__col ob-foot__data">
					<h3>Data &amp; respect</h3>
					<p>Death records come from public reporting and Wikidata, and every scoring case is confirmed by a human editor. The figures listed here are real people; the game is played with respect for their lives.</p>
				</div>
			</div>
			<div class="ob-foot__legal">
				<span>&copy; <?php echo esc_html( $year ); ?> Obitleague</span>
				<span>Season <?php echo esc_html( $year ); ?> in play</span>
				<span>Sources: Wikipedia &middot; Wikidata (CC BY-SA / CC0)</span>
			</div>
		</footer>
		<script>
		(function(){
			document.documentElement.classList.add('ob-js');
			var nav=document.querySelector('.ob-nav');
			if(nav){
				var t=nav.querySelector('.ob-nav__toggle');
				if(t){t.addEventListener('click',function(){var open=nav.classList.toggle('is-open');t.setAttribute('aria-expanded',open?'true':'false');});}
				nav.addEventListener('click',function(e){if(e.target.closest('.ob-nav__link')){nav.classList.remove('is-open');t&&t.setAttribute('aria-expanded','false');}});
				var onScroll=function(){nav.classList.toggle('is-scrolled',(window.scrollY||0)>8);};
				window.addEventListener('scroll',onScroll,{passive:true});onScroll();
			}
			var reduced=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
			if('IntersectionObserver' in window&&!reduced){
				var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});},{threshold:.12});
				document.querySelectorAll('.ob-anim').forEach(function(el){io.observe(el);});
				var cio=new IntersectionObserver(function(es){es.forEach(function(e){if(!e.isIntersecting){return;}cio.unobserve(e.target);var el=e.target,end=parseInt(el.getAttribute('data-count'),10)||0,t0=null,dur=900;function step(ts){t0=t0||ts;var p=Math.min(1,(ts-t0)/dur),v=Math.round(end*p*p*(3-2*p));el.textContent=v.toLocaleString();if(p<1){requestAnimationFrame(step);}}requestAnimationFrame(step);});},{threshold:.4});
				document.querySelectorAll('.ob-stat__num[data-count]').forEach(function(el){cio.observe(el);});
			}else{
				document.querySelectorAll('.ob-anim').forEach(function(el){el.classList.add('in');});
			}
		})();
		</script>
		<?php
	}
}

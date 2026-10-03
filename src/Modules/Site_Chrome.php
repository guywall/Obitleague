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
 * Fonts: JetBrains Mono (typewriter body/UI) + Outfit (display titles) from
 * Google Fonts.
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

	/**
	 * Typewriter body plus a heavier display face for titles. Both are
	 * variable fonts, so the whole weight range the design uses comes from a
	 * single family request each.
	 */
	public static function fonts(): void {
		echo "<link rel='preconnect' href='https://fonts.googleapis.com' />\n";
		echo "<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin />\n";
		echo "<link href='https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300..800&family=Outfit:wght@300..900&display=swap' rel='stylesheet' />\n";
	}

	public static function render_header(): void {
		// The navigation bar itself is rendered by the Header module
		// (.ob-header via wp_body_open); this legacy hook only opens the
		// content wrapper that render_footer() closes.
		?>
		<a class="ob-skip" href="#ob-main">Skip to content</a>
		<main id="ob-main" class="ob-main">
		<?php
	}

	public static function render_footer(): void {
		$year  = date_i18n( 'Y' );				$links = array(
				'Standings'     => home_url( '/standings/' ),
			'People'        => home_url( '/people/' ),
			'Teams'         => home_url( '/teams/' ),
			'Obituaries'     => home_url( '/obituaries/' ),
			'Death Archive' => home_url( '/archive/' ),
				'Rules'         => home_url( '/rules/' ),
				'My Leagues'    => home_url( '/my-leagues/' ),
				'Forum'         => home_url( '/forum/' ),
			);
			if ( League_Service::side_leagues_enabled() ) {
				$links['Join a league'] = home_url( '/join/' );
			}
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
			<span>Facts: public reporting &middot; Wikidata (CC0)</span>
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
			var revealAll=function(){document.querySelectorAll('.ob-anim:not(.in)').forEach(function(el){el.classList.add('in');});};
			try{
				if('IntersectionObserver' in window&&!reduced){
					var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target);}});},{threshold:.12});
					document.querySelectorAll('.ob-anim').forEach(function(el){io.observe(el);});
					var cio=new IntersectionObserver(function(es){es.forEach(function(e){if(!e.isIntersecting){return;}cio.unobserve(e.target);var el=e.target,end=parseInt(el.getAttribute('data-count'),10)||0,t0=null,dur=900;function step(ts){t0=t0||ts;var p=Math.min(1,(ts-t0)/dur),v=Math.round(end*p*p*(3-2*p));el.textContent=v.toLocaleString();if(p<1){requestAnimationFrame(step);}}requestAnimationFrame(step);});},{threshold:.4});
					document.querySelectorAll('.ob-stat__num[data-count]').forEach(function(el){cio.observe(el);});
					setTimeout(function(){var st=document.createElement('style');st.textContent='.ob-js .ob-anim{transition:none!important}';document.head.appendChild(st);revealAll();},1200);
				}else{
					revealAll();
				}
			}catch(e){revealAll();}
		})();
		</script>
		<?php
	}
}

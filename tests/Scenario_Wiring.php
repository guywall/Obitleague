<?php
/**
 * Static wiring audit.
 *
 * The post-merge history shows whole methods and pages going missing between
 * commits, so this scenario re-proves the wiring on every run without
 * needing WordPress: every module booted, every hook callback, every static
 * call between plugin classes, every template, rewrite, enqueued asset and
 * internal link must resolve to something that exists in the repo.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

final class Scenario_Wiring {

	private string $root;

	/** path => contents of every plugin PHP file under src/ plus the bootstrap. */
	private array $files = array();

	public function __construct() {
		$this->root = str_replace( '\\', '/', dirname( __DIR__ ) );
		$iterator   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root . '/src', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$this->files[ str_replace( '\\', '/', (string) $file->getPathname() ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		$bootstrap = $this->root . '/obitleague.php';
		if ( is_file( $bootstrap ) ) {
			$this->files[ $bootstrap ] = (string) file_get_contents( $bootstrap );
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------- */

	/** All matches of a regex across every plugin file, keyed by class file. */
	private function grep( string $pattern ): array {
		$out = array();
		foreach ( $this->files as $path => $code ) {
			if ( preg_match_all( $pattern, $code, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$out[] = array( 'file' => $path, 'match' => $hit );
				}
			}
		}
		return $out;
	}

	/** PSR-4 location for a plugin class, or null when not a plugin file. */
	private function class_file( string $fqcn ): ?string {
		if ( ! str_starts_with( $fqcn, 'Obitleague\\' ) ) {
			return null;
		}
		$relative = str_replace( '\\', '/', substr( $fqcn, strlen( 'Obitleague\\' ) ) );
		$path     = $this->root . '/src/' . $relative . '.php';
		if ( str_starts_with( $relative, 'Tests/' ) ) {
			return null; // Test classes are not wiring.
		}
		return is_file( $path ) ? $path : null;
	}

	/** FQCN declared by a file (namespace + first class declaration). */
	private function declared_class( string $path ): ?string {
		$code = $this->files[ $path ] ?? ( is_file( $path ) ? (string) file_get_contents( $path ) : '' );
		if ( ! preg_match( '/namespace\s+([^;]+);/', $code, $ns ) ) {
			return null;
		}
		if ( ! preg_match( '/\b(?:final\s+)?(?:abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $cl ) ) {
			return null;
		}
		return trim( $ns[1] ) . '\\' . trim( $cl[1] );
	}

	/** use-import map (alias => FQCN) for one file. */
	private function imports( string $path ): array {
		$code = $this->files[ $path ] ?? '';
		$map  = array();
		if ( preg_match_all( '/^use\s+\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)\s*(?:as\s+([A-Za-z_][A-Za-z0-9_]*))?;/m', $code, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$fqcn  = ltrim( $hit[1], '\\' );
				$alias = isset( $hit[2] ) ? $hit[2] : ( ( $pos = strrpos( $fqcn, '\\' ) ) === false ? $fqcn : substr( $fqcn, $pos + 1 ) );
				$map[ $alias ] = $fqcn;
			}
		}
		return $map;
	}

	/** Resolve a bare or namespaced class name as written inside a file. */
	private function resolve_class( string $name, string $path ): ?string {
		$name = ltrim( $name, '\\' );
		if ( str_contains( $name, '\\' ) ) {
			$candidate = 'Obitleague\\' . $name;
			return null !== $this->class_file( $candidate ) ? $candidate : null;
		}
		$imports = $this->imports( $path );
		if ( isset( $imports[ $name ] ) ) {
			return null !== $this->class_file( $imports[ $name ] ) ? $imports[ $name ] : null;
		}
		$declared = $this->declared_class( $path );
		if ( null !== $declared ) {
			$namespace = substr( $declared, 0, (int) strrpos( $declared, '\\' ) );
			$candidate = $namespace . '\\' . $name;
			if ( null !== $this->class_file( $candidate ) ) {
				return $candidate;
			}
		}
		$candidate = 'Obitleague\\' . $name;
		return null !== $this->class_file( $candidate ) ? $candidate : null;
	}

	/** Methods defined by the class living in a file. */
	private function defined_methods( string $path ): array {
		$code = $this->files[ $path ] ?? '';
		preg_match_all( '/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $m );
		return $m[1];
	}

	private function relative( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return '' !== $this->root && str_starts_with( $path, $this->root . '/' ) ? substr( $path, strlen( $this->root ) + 1 ) : $path;
	}

	/* ------------------------------------------------------------------
	 * The audit.
	 * ---------------------------------------------------------------- */

	public function test_every_booted_module_exists( Runner $t ): void {
		$missing = array();
		foreach ( $this->grep( '/Obitleague\\\\Modules\\\\([A-Za-z_]+)::boot\(\)/' ) as $hit ) {
			$name    = $hit['match'][1];
			$path    = $this->root . '/src/Modules/' . $name . '.php';
			$methods = is_file( $path ) ? $this->defined_methods( $path ) : array();
			if ( ! is_file( $path ) || ! in_array( 'boot', $methods, true ) ) {
				$missing[] = 'Obitleague\\Modules\\' . $name . '::boot';
			}
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every booted module exists with a boot() method'
			: 'missing module boot: ' . implode( ', ', $missing ) );
	}

	public function test_every_hook_callback_exists( Runner $t ): void {
		$missing = array();
		$hits    = $this->grep( '/array\(\s*(self::class|[A-Za-z_][A-Za-z0-9_]*(?:::class)?),\s*\'([a-zA-Z_][a-zA-Z0-9_]*)\'\s*\)/' );
		foreach ( $hits as $hit ) {
			$method = $hit['match'][2];
			if ( str_contains( $hit['match'][1], '$' ) ) {
				continue;
			}
			if ( 'self::class' === $hit['match'][1] ) {
				$fqcn = $this->declared_class( $hit['file'] );
				if ( null === $fqcn ) {
					continue;
				}
			} else {
				$bare  = rtrim( $hit['match'][1], ':' );
				$bare  = str_replace( '::class', '', $bare );
				$fqcn  = $this->resolve_class( $bare, $hit['file'] );
				if ( null === $fqcn ) {
					continue; // Not a plugin class (WP core, callbacks by name...).
				}
			}
			$path = $this->class_file( $fqcn );
			if ( null === $path || ! in_array( $method, $this->defined_methods( $path ), true ) ) {
				$missing[] = $this->relative( $hit['file'] ) . ': ' . $fqcn . '::' . $method;
			}
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every array( Class, "method" ) callback resolves (' . count( $hits ) . ' checked)'
			: 'dangling callbacks: ' . implode( '; ', array_slice( $missing, 0, 8 ) ) );
	}

	public function test_every_static_call_between_plugin_classes_exists( Runner $t ): void {
		$missing = array();
		$hits    = $this->grep( '/(?<![:\w\\\\])(\\\\?[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*)::([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/' );
		foreach ( $hits as $hit ) {
			$fqcn = $this->resolve_class( $hit['match'][1], $hit['file'] );
			if ( null === $fqcn || str_starts_with( $fqcn, 'Obitleague\\Tests\\' ) ) {
				continue;
			}
			$path = $this->class_file( $fqcn );
			if ( null === $path ) {
				continue;
			}
		if ( ! in_array( $hit['match'][2], $this->defined_methods( $path ), true ) ) {
				$missing[] = $this->relative( $hit['file'] ) . ': ' . $fqcn . '::' . $hit['match'][2] . '()';
			}
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every cross-class static call resolves (' . count( $hits ) . ' checked)'
			: 'missing methods: ' . implode( '; ', array_slice( $missing, 0, 8 ) ) );
	}

	public function test_every_referenced_template_exists( Runner $t ): void {
		$missing = array();
		foreach ( $this->grep( '#src/Templates/([a-z0-9_-]+\.php)#' ) as $hit ) {
			if ( ! is_file( $this->root . '/src/Templates/' . $hit['match'][1] ) ) {
				$missing[] = 'src/Templates/' . $hit['match'][1];
			}
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every referenced template file exists'
			: 'missing templates: ' . implode( ', ', array_unique( $missing ) ) );
	}

	public function test_every_shortcode_tag_maps_to_a_real_method( Runner $t ): void {
		$missing  = array();
		$sc_path  = $this->root . '/src/Modules/Shortcodes.php';
		$methods  = $this->defined_methods( $sc_path );
		$boot_code = $this->files[ $sc_path ] ?? '';
		if ( preg_match_all( '/\'(obitleague_[a-z_]+)\'\s*=>\s*\'([a-zA-Z_]+)\'/', $boot_code, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				if ( ! in_array( $hit[2], $methods, true ) ) {
					$missing[] = $hit[1] . ' => ' . $hit[2];
				}
			}
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every shortcode tag maps to a Shortcodes method'
			: 'dangling shortcodes: ' . implode( ', ', $missing ) );
	}

	public function test_every_rewrite_query_var_is_registered_or_read( Runner $t ): void {
		$vars    = array();
		foreach ( $this->grep( '/add_rewrite_rule\(\s*\'[^\']*\',[^,]*,?\s*[^)]*\)/' ) as $hit ) {
			if ( preg_match_all( '/(ob_[a-z0-9_]+)/', $hit['match'][0], $v ) ) {
				foreach ( $v[1] as $var ) {
					if ( str_ends_with( $var, '_id' ) || str_ends_with( $var, '_page' ) ) {
						continue; // Handled alongside their parents below.
					}
					$vars[ $var ] = true;
				}
			}
		}
		$missing = array();
		foreach ( array_keys( $vars ) as $var ) {
			$registered = false;
			foreach ( $this->files as $code ) {
				if ( str_contains( $code, "'" . $var . "'" ) || str_contains( $code, '"' . $var . '"' ) ) {
					$registered = true;
					break;
				}
			}
			if ( ! $registered ) {
				$missing[] = $var;
			}
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every rewrite query var is registered or read somewhere'
			: 'unhandled query vars: ' . implode( ', ', $missing ) );
	}

	public function test_every_referenced_asset_exists( Runner $t ): void {
		$missing = array();
		foreach ( $this->grep( '#assets/([a-z0-9_.-]+\.(?:css|js))#' ) as $hit ) {
			if ( ! is_file( $this->root . '/assets/' . $hit['match'][1] ) ) {
				$missing[] = 'assets/' . $hit['match'][1];
			}
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every literally referenced asset exists'
			: 'missing assets: ' . implode( ', ', array_unique( $missing ) ) );
	}

	/**
	 * No rendered surface may print the raw scoring formula.
	 *
	 * The formula `max(1, 100 − age)` is load-bearing inside the domain
	 * (Ruleset::points_for_age) and the normative spec, but it is not
	 * easy to read and was never meant to be shown to players. Every
	 * surface a visitor or agent reads must describe scoring in plain
	 * words instead. This guards the ban on it reappearing.
	 */
	public function test_no_rendered_surface_prints_the_raw_scoring_formula( Runner $t ): void {
		$surfaces = array(
			'src/Templates/single-obit_person.php',
			'src/Templates/archive-obit_person.php',
			'src/Modules/Person_Content.php',
			'src/Modules/Shortcodes.php',
			'src/Modules/Campaign.php',
			'src/Modules/Stats_Service.php',
			'src/Modules/Admin_Review.php',
			'src/Modules/Rest_Agents.php',
			'src/Modules/A2A.php',
			'src/Modules/Agent_Orchestrator.php',
		);
		$offenders = array();
		foreach ( $surfaces as $relative ) {
			$path = $this->root . '/' . $relative;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$code = (string) file_get_contents( $path );
			// The formula appears with a hyphen or a minus sign, and with or
			// without spacing after the comma.
			if ( preg_match( '/max\(\s*1\s*,\s*100\s*[-−]/u', $code ) ) {
				$offenders[] = $relative;
			}
		}
		$t->check( array() === $offenders, __METHOD__, array() === $offenders
			? 'no rendered surface prints the raw scoring formula'
			: 'raw scoring formula rendered in: ' . implode( ', ', $offenders ) );
	}

	/**
	 * The product is one canonical league. Optional side leagues are kept in
	 * the code but must be invisible unless an operator opts back in, so every
	 * public or creation surface must be gated on League_Service::side_leagues_enabled()
	 * and that flag must default to off. This guards against a refactor
	 * quietly reactivating the feature.
	 */
	public function test_side_league_surfaces_are_gated_on_a_default_off_flag( Runner $t ): void {
		$service = (string) ( $this->files[ $this->root . '/src/Modules/League_Service.php' ] ?? '' );
		$t->check( str_contains( $service, "SIDE_LEAGUES_OPTION = 'obitleague_side_leagues_enabled'" ), __METHOD__, 'the side-league option name is stable' );
		$t->check( (bool) preg_match( '/get_option\(\s*self::SIDE_LEAGUES_OPTION\s*,\s*false\s*\)/', $service ), __METHOD__, 'the flag defaults to off' );

		$gated_surfaces = array(
			'src/Modules/Game_Pages.php'   => 'join route, shortcode and redirect',
			'src/Modules/Rest.php'         => 'league create and join routes',
			'src/Modules/Admin_Game.php'   => 'admin create-league form',
			'src/Modules/Site_Chrome.php'  => 'footer join link',
			'src/Templates/my-leagues.php' => 'hero CTA and side-league section',
		);
		$ungated = array();
		foreach ( $gated_surfaces as $relative => $what ) {
			$code = (string) ( $this->files[ $this->root . '/' . $relative ] ?? '' );
			if ( '' === $code || ! str_contains( $code, 'side_leagues_enabled' ) ) {
				$ungated[] = $relative . ' (' . $what . ')';
			}
		}
		$t->check( array() === $ungated, __METHOD__, array() === $ungated
			? 'every side-league surface is gated on the flag'
			: 'surfaces not gated: ' . implode( ', ', $ungated ) );
	}

	/**
	 * Every static internal link must point at a route the plugin itself
	 * guarantees: an auto-created page, a template-routed path, a rewrite,
	 * or the person CPT. This is the audit that catches nav links 404ing on
	 * a production site where the demo page importer never ran.
	 */
	public function test_every_internal_link_has_a_guaranteed_target( Runner $t ): void {
		$auto_pages    = array( 'people', 'login', 'register', 'verify-email', 'teams', 'obituaries', 'archive', 'standings', 'rules' );
		$template_ok   = array( 'my-leagues', 'join', 'stats', 'ai-vs-humans', 'ai-integrate' );
		$rewrite_pref  = array( 'login', 'forum', 'ai', 'league', 'team', 'person' );

		$missing = array();
		foreach ( $this->grep( '#home_url\(\s*\'/([a-z0-9-]+)/\'#' ) as $hit ) {
			if ( str_contains( $this->relative( $hit['file'] ), 'tests/' ) ) {
				continue;
			}
			$path = $hit['match'][1];
			if ( in_array( $path, $auto_pages, true ) || in_array( $path, $template_ok, true ) ) {
				continue;
			}
			if ( in_array( explode( '/', $path )[0], $rewrite_pref, true ) ) {
				continue;
			}
			$missing[] = $this->relative( $hit['file'] ) . ': /' . $path . '/';
		}
		$t->check( array() === $missing, __METHOD__, array() === $missing
			? 'every internal link points at a route the plugin guarantees'
			: 'links with no guaranteed target: ' . implode( ', ', array_unique( $missing ) ) );
	}
}

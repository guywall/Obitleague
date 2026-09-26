<?php
/**
 * Front-end templates.
 *
 * Plugin-supplied fallback templates for the public person archive and
 * single pages, used when the active theme provides none of its own.
 * Renders approved facts only, with source links and licence attribution.
 * Elementor Theme Builder templates take precedence when configured.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Front_Templates {

	private function __construct() {}

	public static function boot(): void {
		add_filter( 'template_include', array( self::class, 'maybe_override' ), 20 );
	}

	/** Use plugin templates when the theme has none for people. */
	public static function maybe_override( string $template ): string {
		if ( is_singular( Catalogue::POST_TYPE ) ) {
			$theme = locate_template( array( 'single-obit_person.php', 'single-' . Catalogue::POST_TYPE . '.php' ) );
			return $theme ? $theme : OBITLEAGUE_DIR . 'src/Templates/single-obit_person.php';
		}
		if ( is_post_type_archive( Catalogue::POST_TYPE ) || ( is_tax( Catalogue::TAX_OCCUPATION ) ) ) {
			$theme = locate_template( array( 'archive-obit_person.php', 'archive-' . Catalogue::POST_TYPE . '.php' ) );
			return $theme ? $theme : OBITLEAGUE_DIR . 'src/Templates/archive-obit_person.php';
		}
		return $template;
	}
}

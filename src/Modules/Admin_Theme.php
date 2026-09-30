<?php
/**
 * Admin theming.
 *
 * The WordPress admin keeps its default look. This module only scopes a
 * small functional stylesheet (assets/admin.css) to Obitleague screens —
 * the plugin menu tree, review screens, leagues & teams admin, statistics
 * and the person edit screen — for classes referenced in plugin markup.
 * It applies no branding: core admin screens, and the admin chrome on
 * plugin screens, are left untouched.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Admin_Theme {

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_body_class', array( self::class, 'body_class' ) );
	}

	/** Which admin screens count as Obitleague-branded screens. */
	private static function is_plugin_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$id = (string) $screen->id;
		if ( str_starts_with( $id, 'obitleague' ) || str_contains( $id, 'obitleague' ) ) {
			return true;
		}
		// People + nominations + occupation taxonomy screens.
		return in_array( $id, array(
			'edit-obit_person',
			'obit_person',
			'edit-obit_nomination',
			'obit_nomination',
			'edit-obit_occupation',
		), true );
	}

	public static function body_class( string $classes ): string {
		if ( self::is_plugin_screen() ) {
			$classes .= ' ob-admin ob-admin--branded';
		}
		return $classes;
	}

	public static function assets( string $hook_suffix ): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		wp_enqueue_style(
			'obitleague-admin-theme',
			OBITLEAGUE_DIR_URL . 'assets/admin.css',
			array(),
			OBITLEAGUE_VERSION
		);
	}
}

<?php
declare( strict_types = 1 );

namespace Obitleague\Modules;

final class HeaderIntegration {

	private function __construct() {}

	public static function boot(): void {
		if ( function_exists( 'elementor' ) && class_exists( '\Elementor\Plugin' ) ) {
			add_action( 'elementor/loaded', array( self::class, 'maybe_enable_plugin_header' ), 10 );
		}
	}

	public static function maybe_enable_plugin_header(): void {
		if ( ! is_plugin_active( 'obitleague/obitleague.php' ) ) {
			return;
		}

		$settings = get_option( 'ob_header_settings', array() );
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return;
		}

		$primary = isset( $settings['primary'] ) ? (array) $settings['primary'] : array();
		if ( empty( $primary ) ) {
			return;
		}

		$links = array();
		foreach ( $primary as $item ) {
			if ( ! is_array( $item ) || empty( $item['label'] ) ) {
				continue;
			}
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';
			$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
			$mega  = isset( $item['mega'] ) && 'yes' === $item['mega'];
			$links[] = array(
				'label' => $label,
				'url'   => $url,
				'mega'  => $mega,
			);
		}

		if ( ! empty( $links ) ) {
			add_filter( 'body_class', array( self::class, 'add_header_class' ) );
		}
	}

	public static function add_header_class( array $classes ): array {
		$classes[] = 'has-ob-header';
		return $classes;
	}

	private static function is_plugin_active( string $basename ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $basename );
	}
}

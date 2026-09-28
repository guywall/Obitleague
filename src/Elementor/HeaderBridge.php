<?php
declare( strict_types = 1 );

namespace Obitleague\Elementor;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

final class HeaderBridge {

	private function __construct() {}

	public static function boot(): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );
		add_filter( 'elementor/document_types', array( self::class, 'document_types' ) );
	}

	public static function register_widgets( \Elementor\Widgets_Manager $widgets_manager ): void {
		$widgets_dir = OBITLEAGUE_DIR . 'src/Elementor/';

		if ( file_exists( $widgets_dir . 'Widget_ObHeader.php' ) ) {
			require_once $widgets_dir . 'Widget_ObHeader.php';
			if ( class_exists( '\Obitleague\Elementor\Widget_ObHeader' ) ) {
				$widgets_manager->register( new \Obitleague\Elementor\Widget_ObHeader() );
			}
		}
	}

	public static function document_types( array $document_types ): array {
		$document_types['obitleague-header'] = array(
			'title'       => 'Obitleague Header',
			'category'    => 'general',
			'icon'        => 'eicon-menu-bar',
			'with_global' => true,
		);
		return $document_types;
	}
}

<?php
/**
 * Elementor bridge.
 *
 * Registers the Obitleague dynamic tag group and typed tags, plus guarded
 * widgets. The bridge is a render adapter only: tags read approved catalogue
 * data, widgets enforce membership on every render. Without Elementor the
 * plugin stays operable and shows an administrator notice instead of broken
 * private widgets.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Elementor_Bridge {

	private function __construct() {}

	public static function boot(): void {
		if ( ! \did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', array( self::class, 'dependency_notice' ) );
			return;
		}

		add_filter( 'elementor/dynamic_tags/base_groups', array( self::class, 'tag_groups' ) );
		add_action( 'elementor/dynamic_tags/register_tags', array( self::class, 'register_tags' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );

		// The standings widget's membership guard resolves through the real
		// league service — checked on every render.
		\add_filter(
			'obitleague_user_is_league_member',
			static function ( bool $is_member, int $league_id, int $user_id ): bool {
				return League_Service::is_member( $league_id, $user_id );
			},
			10,
			3
		);
	}

	/** Dependency notice when Elementor is missing; data layer stays operable. */
	public static function dependency_notice(): void {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			\esc_html__( 'Obitleague is active, but Elementor is not installed. Game data, jobs and review tools keep working; league and person widgets are unavailable until Elementor is enabled.', 'obitleague' )
		);
	}

	/**
	 * @param array<string, array{title:string}> $groups
	 * @return array<string, array{title:string}>
	 */
	public static function tag_groups( array $groups ): array {
		$groups['obitleague'] = array( 'title' => \__( 'Obitleague', 'obitleague' ) );
		return $groups;
	}

	public static function register_tags( $tags_manager ): void {
		$tag_dir = OBITLEAGUE_DIR . 'src/Elementor/';
		require_once $tag_dir . 'Tag_Person_Field.php';
		require_once $tag_dir . 'Tag_Vs_Stat.php';

		foreach ( array( 'Tag_Person_Field', 'Tag_Vs_Stat' ) as $class ) {
			$tags_manager->register( new ( "Obitleague\\Elementor\\{$class}" )() );
		}
	}

	public static function register_widgets( $widgets_manager ): void {
		require_once OBITLEAGUE_DIR . 'src/Elementor/Widget_League_Standings.php';
		$widgets_manager->register( new \Obitleague\Elementor\Widget_League_Standings() );
	}
}

<?php
/**
 * Plugin Name:       Obitleague
 * Plugin URI:        https://example.com/obitleague
 * Description:       Fantasy dead pool league platform: people catalogue, feed discovery, editorial review, leagues, teams and reversible scoring.
 * Version:           0.14.1
 * Requires at least: 6.4
 * Requires PHP:      8.2	* Author:            Obitleague Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       obitleague
 *
 * One plugin owns catalogue, feeds, imports, editorial review, seasons,
 * leagues, teams, scoring, notifications and administration. The presentation
 * layer (Elementor + PRO Elements) binds to it through typed dynamic tags and
 * guarded widgets; it never re-implements eligibility or scoring.
 *
 * Domain rules that must never drift live in src/Domain and are covered by
 * tests/run-tests.php.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* Constants. */
if ( ! defined( 'OBITLEAGUE_VERSION' ) ) {
	define( 'OBITLEAGUE_VERSION', '0.14.4' );
}
if ( ! defined( 'OBITLEAGUE_DB_VERSION' ) ) {
	define( 'OBITLEAGUE_DB_VERSION', '0.7.0' );
}
if ( ! defined( 'OBITLEAGUE_FILE' ) ) {
	define( 'OBITLEAGUE_FILE', __FILE__ );
}
if ( ! defined( 'OBITLEAGUE_DIR' ) ) {
	define( 'OBITLEAGUE_DIR', __DIR__ . '/' );
}
if ( ! defined( 'OBITLEAGUE_DIR_URL' ) ) {
	define( 'OBITLEAGUE_DIR_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'OBITLEAGUE_REST_NAMESPACE' ) ) {
	define( 'OBITLEAGUE_REST_NAMESPACE', 'obitleague/v1' );
}

/* Autoloader: PSR-4-ish, Obitleague\ → src/. */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'Obitleague\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( 'Obitleague\\' ) ) );
		$file     = OBITLEAGUE_DIR . 'src/' . $relative . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( 'Obitleague requires PHP 8.2 or newer.' );
		}
		Obitleague\Modules\Setup::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static fn () => Obitleague\Modules\Setup::deactivate()
);

// Every new account receives its main-season entry automatically.
add_action( 'user_register', array( Obitleague\Modules\Main_League_Service::class, 'on_user_register' ), 20 );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( is_admin() ) {
			Obitleague\Modules\Setup::maybe_upgrade();
			Obitleague\Modules\Admin_Theme::boot();
		}
		Obitleague\Modules\Catalogue::boot();
		Obitleague\Modules\Occupation_Taxonomy::boot();
		Obitleague\Modules\People_Sync::boot();
		Obitleague\Modules\Admin_Review::boot();
		Obitleague\Modules\Discovery_Service::boot();
		Obitleague\Modules\Wiki_Request_Queue::boot();
		Obitleague\Modules\Admin_Discovery::boot();
		Obitleague\Modules\Admin_Game::boot();
		Obitleague\Modules\Admin_Stats::boot();
		Obitleague\Modules\Demo_Accounts_Admin::boot();
		Obitleague\Modules\Admin_Agents::boot();
		Obitleague\Modules\Agent_Pages::boot();
		Obitleague\Modules\Rest_Agents::boot();
		Obitleague\Modules\Mcp_Server::boot();
		Obitleague\Modules\A2A::boot();
		Obitleague\Modules\Agent_Orchestrator::boot();
		Obitleague\Modules\Front_Templates::boot();
		Obitleague\Modules\Site_Chrome::boot();
		Obitleague\Modules\Header::boot();
		Obitleague\Modules\Season_Switcher::boot();
		Obitleague\Modules\Forum::boot();
		Obitleague\Modules\Seo::boot();
		Obitleague\Modules\Shortcodes::boot();
		Obitleague\Modules\Game_Pages::boot();
		Obitleague\Modules\Jobs::boot();
		Obitleague\Modules\Death_Wire::boot();
		Obitleague\Modules\Rest::boot();
	},
	5
);

// Elementor registers itself on plugins_loaded (default priority), so the
// bridge must check for it after that point.
add_action( 'plugins_loaded', array( Obitleague\Modules\Elementor_Bridge::class, 'boot' ), 20 );
add_action( 'elementor/loaded', array( \Obitleague\Elementor\HeaderBridge::class, 'boot' ), 20 );

<?php
/**
 * Backfill the canonical main-season slot for existing WordPress accounts.
 * This is intentionally an explicit WP-CLI migration; no submissions or
 * side-league entries are changed.
 *
 * Usage: wp eval-file tests/migrate-main-entries.php [season]
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Modules\League_Service;

$season = isset( $args[0] ) ? absint( $args[0] ) : League_Service::current_season();
$processed = League_Service::provision_main_season_for_users( $season );
WP_CLI::success( sprintf( 'Provisioned/checkpointed %d existing accounts for main season %d. Existing entries were preserved.', $processed, $season ) );

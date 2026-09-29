<?php
/**
 * Verify the season selector wiring under WP-CLI:
 *   wp eval-file tests/verify-season-switcher.php
 *
 * Checks that the displayed season defaults to the in-play year, that an
 * invalid ?season= falls back rather than rendering empty views, and that
 * the selector markup is rendered. No strict_types here: wp eval-file
 * wraps this file in an eval context where the declaration is illegal.
 *
 * @package Obitleague
 */

use Obitleague\Modules\Season_Switcher;
use Obitleague\Modules\Shortcodes;
use Obitleague\Modules\Pick_Stats;

if ( ! defined( 'ABSPATH' ) || ! WP_CLI ) {
	return;
}

$failures = array();

$seasons = Season_Switcher::seasons_with_data();
if ( ! $seasons ) {
	$failures[] = 'Season list should never be empty (in-play year is always offered)';
}

$in_play = Pick_Stats::season_in_play();
if ( ! in_array( $in_play, $seasons, true ) ) {
	$failures[] = 'In-play season should always be selectable';
}

if ( Season_Switcher::displayed_season() !== $in_play && ! isset( $_GET['season'] ) ) {
	$failures[] = 'Without ?season= the displayed season should be the in-play year';
}

$_GET['season'] = '999999';
if ( Season_Switcher::displayed_season() !== $in_play ) {
	$failures[] = 'An unknown ?season= must fall back to the in-play year';
}

$_GET['season'] = 'not-a-year';
if ( Season_Switcher::displayed_season() !== $in_play ) {
	$failures[] = 'A non-numeric ?season= must fall back to the in-play year';
}

$valid = $seasons[0] ?? 0;
if ( $valid > 0 && $valid !== $in_play ) {
	$_GET['season'] = (string) $valid;
	if ( Season_Switcher::displayed_season() !== $valid ) {
		$failures[] = 'A valid ?season= should be honoured';
	}
	if ( Shortcodes::season() !== $valid ) {
		$failures[] = 'Shortcodes::season() should honour a valid ?season=';
	}
}
unset( $_GET['season'] );

ob_start();
Season_Switcher::render_toggle();
$toggle_html = (string) ob_get_clean();
if ( count( $seasons ) >= 2 ) {
	if ( ! str_contains( $toggle_html, 'ob-season-toggle' ) ) {
		$failures[] = 'Season toggle markup should render when multiple seasons exist';
	}
	if ( ! str_contains( $toggle_html, (string) $in_play ) ) {
		$failures[] = 'Season toggle should list the in-play year';
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		\WP_CLI::warning( $failure );
	}
	\WP_CLI::error( implode( '; ', $failures ) );
}

\WP_CLI::success( 'Season selector wiring verified' );

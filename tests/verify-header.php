<?php
declare( strict_types = 1 );

use Obitleague\Modules\Header;
use Obitleague\Modules\Shortcodes;
use Obitleague\Modules\League_Service;

if ( ! defined( 'ABSPATH' ) || ! WP_CLI ) {
	return;
}

$failures = array();

$logged_in = is_user_logged_in();

$settings = Header::settings();
$primary = isset( $settings['primary'] ) ? (array) $settings['primary'] : array();

$primary_labels = array();
foreach ( $primary as $item ) {
	if ( is_array( $item ) && ! empty( $item['label'] ) ) {
		$primary_labels[] = (string) $item['label'];
	}
}

if ( ! in_array( 'People', $primary_labels, true ) ) {
	$failures[] = 'Header primary links should include People';
}
if ( ! in_array( 'Picks', $primary_labels, true ) ) {
	$failures[] = 'Header primary links should include Picks';
}
if ( ! in_array( 'Standings', $primary_labels, true ) ) {
	$failures[] = 'Header primary links should include Standings';
}
if ( ! in_array( 'Stats', $primary_labels, true ) ) {
	$failures[] = 'Header primary links should include Stats';
}

$search_url = Shortcodes::people_search_url();
if ( ! $search_url ) {
	$search_url = home_url( '/people/' );
}

if ( ! filter_var( $search_url, FILTER_VALIDATE_URL ) ) {
	$failures[] = 'People search URL should be a valid URL';
}

$entry_season = League_Service::current_season();
if ( $entry_season < 2026 || $entry_season > 2040 ) {
	$failures[] = 'Entry season should be a reasonable future/present season';
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		\WP_CLI::warning( $failure );
	}
	\WP_CLI::error( implode( '; ', $failures ) );
}

\WP_CLI::success( 'Header wiring verified' );

<?php
declare( strict_types = 1 );

use Obitleague\Modules\Header;
use Obitleague\Modules\Shortcodes;
use Obitleague\Modules\League_Service;

if ( ! defined( 'ABSPATH' ) || ! WP_CLI ) {
	return;
}

$failures = array();

$settings = Header::settings();
$primary = isset( $settings['primary'] ) ? (array) $settings['primary'] : array();
$primary_labels = array();
foreach ( $primary as $item ) {
	if ( is_array( $item ) && ! empty( $item['label'] ) ) {
		$primary_labels[] = (string) $item['label'];
	}
}
foreach ( array( 'People', 'Teams', 'Obituaries', 'Standings', 'Stats' ) as $label ) {
	if ( ! in_array( $label, $primary_labels, true ) ) {
		$failures[] = 'Header primary links should include ' . $label;
	}
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

ob_start();
try {
	Header::render();
	$html = (string) ob_get_clean();
	if ( ! str_contains( $html, 'class="ob-header' ) ) {
		$failures[] = 'Header rendering should produce header markup';
	}
	if ( str_contains( $html, 'wp-login.php' ) ) {
		$failures[] = 'Player header must link to branded sign-in rather than the WordPress login screen';
	}
	foreach ( array( 'People', 'Teams', 'Obituaries', 'Standings', 'Stats' ) as $label ) {
		if ( ! str_contains( $html, '>' . $label . '</a>' ) ) {
			$failures[] = 'Header navigation should include ' . $label;
		}
	}
	if ( ! str_contains( $html, 'pick=missed' ) ) {
		$failures[] = 'Obituaries submenu should expose the misses view (?pick=missed)';
	}
} catch ( Throwable $error ) {
	ob_end_clean();
	$failures[] = 'Header rendering failed: ' . $error->getMessage();
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		\WP_CLI::warning( $failure );
	}
	\WP_CLI::error( implode( '; ', $failures ) );
}

\WP_CLI::success( 'Header wiring and rendering verified' );

<?php
/**
 * Direct handler probe: run one deaths2026_list_page through the queue
 * handler with full error visibility.
 * Usage: wp eval-file probe-handler.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

try {
	$result = Obitleague\Modules\Death_Wire::handle_list_page(
		array(
			'month'  => '2026-09',
			'offset' => 350,
		)
	);
	if ( is_wp_error( $result ) ) {
		echo 'WP_Error: ' . $result->get_error_code() . ' — ' . $result->get_error_message() . "\n";
	} else {
		echo 'result: ' . wp_json_encode( $result ) . "\n";
	}
} catch ( \Throwable $e ) {
	echo get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	echo substr( $e->getTraceAsString(), 0, 600 ) . "\n";
}

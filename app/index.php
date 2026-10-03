<?php
declare( strict_types = 1 );

require_once __DIR__ . '/Runtime.php';

use Obitleague\Standalone\Clock;
use Obitleague\Standalone\Runtime;

$database = getenv( 'OBITLEAGUE_DB' ) ?: dirname( __DIR__ ) . '/var/obitleague.sqlite';
$directory = dirname( $database );
if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
	http_response_code( 500 );
	exit( 'Could not create the application data directory.' );
}
$clock = null;
$pinned_now = getenv( 'OBITLEAGUE_NOW' );
if ( false !== $pinned_now && '' !== $pinned_now ) {
	$clock = new Clock( new DateTimeImmutable( $pinned_now, new DateTimeZone( 'UTC' ) ) );
}
$runtime = new Runtime( $database, dirname( __DIR__ ), $clock );
$method = (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
$path = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$input = 'POST' === $method ? $_POST : array();
if ( 'POST' === $method && empty( $input ) ) {
	parse_str( (string) file_get_contents( 'php://input' ), $input );
}
$headers = function_exists( 'getallheaders' ) ? array_change_key_case( (array) getallheaders(), CASE_LOWER ) : array();
if ( isset( $headers['cookie'] ) ) { $headers['Cookie'] = $headers['cookie']; }
if ( 'POST' === $method && isset( $input['_csrf'] ) ) { $headers['X-CSRF-Token'] = (string) $input['_csrf']; }
if ( isset( $headers['x-csrf-token'] ) ) { $headers['X-CSRF-Token'] = (string) $headers['x-csrf-token']; }
$response = $runtime->handle( $method, $path, $_GET, $input, $headers );
http_response_code( (int) $response['status'] );
foreach ( $response['headers'] as $name => $value ) { header( $name . ': ' . $value ); }
echo $response['body'];

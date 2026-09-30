<?php
/**
 * Probe why candidate 508 fails the evidence-URL comparison.
 * Usage: wp --allow-root eval-file probe-evidence-url.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = 508;
$enwiki  = (string) get_post_meta( $post_id, 'obit_enwiki', true );
$source  = 'https://en.wikipedia.org/wiki/' . str_replace( ' ', '_', $enwiki );

$stored  = esc_url_raw( $source );
$decoded = esc_url_raw( rawurldecode( $stored ) );

echo "enwiki: {$enwiki}\n";
echo "source: {$source}\n";
echo "stored:  {$stored}\n";
echo "decoded: {$decoded}\n";
echo 'stored === decoded: ' . var_export( $stored === $decoded, true ) . "\n";

// What assert_approval_evidence compares, step by step:
$url = trim( $stored );
echo 'is_https_source_url: ' . var_export( Obitleague\Domain\Discovery_Rules::is_https_source_url( $url ), true ) . "\n";
echo 'compare (new): ' . var_export( esc_url_raw( rawurldecode( $url ) ) === (string) get_post_meta( $post_id, 'obit_alive_evidence_url', true ), true ) . " (meta currently empty after failure)\n";

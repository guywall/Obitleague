<?php
/**
 * Purge Elementor render caches for the Obitleague pages.
 * Run via wp-cli eval-file. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slugs = array( 'home', 'standings', 'catalogue', 'archive', 'rules' );
foreach ( $slugs as $slug ) {
	$page = get_page_by_path( $slug );
	if ( ! $page ) {
		continue;
	}
	delete_post_meta( $page->ID, '_elementor_css' );
	delete_post_meta( $page->ID, '_elementor_element_cache' );
	echo 'purged ' . $slug . ' (' . (int) $page->ID . ")\n";
}

// Global Elementor CSS meta cache.
delete_option( 'elementor_css_print_method' );
echo "done\n";

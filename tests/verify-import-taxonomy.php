<?php
/**
 * Regression check: importing a person must not write the occupation taxonomy.
 *
 * The occupation taxonomy is public and indexable, so every term in it is a
 * permanent archive URL. Import used to write unsourced feed role text straight
 * into it, which is how a hardcoded "Public figure" placeholder became a public
 * page and how 70 duplicate archive pages accumulated for occupations that
 * already existed as clean sourced terms.
 *
 * This creates a throwaway candidate with a distinctive occupation, proves no
 * term was created and that the occupation was kept as an editorial hint
 * instead, then removes everything it made. Safe to re-run.
 *
 * Run: wp eval-file tests/verify-import-taxonomy.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Modules\Catalogue;
use Obitleague\Modules\Import_Service;

$probe_role   = 'Zzz Import Taxonomy Probe';
// Must satisfy the QID shape (Q + digits) that import_person enforces.
$probe_qid    = 'Q999999991';
$failures     = array();
$total_before = (int) wp_count_terms( array( 'taxonomy' => Catalogue::TAX_OCCUPATION, 'hide_empty' => false ) );

$post_id = Import_Service::import_person(
	array(
		'qid'        => $probe_qid,
		'name'       => 'Taxonomy Probe',
		'birth_date' => '1980-01-01',
		'occupation' => $probe_role,
		'role'       => $probe_role,
	)
);

$terms_on_post = wp_get_object_terms( $post_id, Catalogue::TAX_OCCUPATION );
$total_after  = (int) wp_count_terms( array( 'taxonomy' => Catalogue::TAX_OCCUPATION, 'hide_empty' => false ) );
$hint         = (string) get_post_meta( $post_id, Import_Service::META_OCCUPATION_HINT, true );
$probe_term   = get_term_by( 'name', $probe_role, Catalogue::TAX_OCCUPATION );

if ( array() !== (array) $terms_on_post && ! is_wp_error( $terms_on_post ) ) {
	$failures[] = 'import assigned taxonomy terms: ' . implode( ', ', wp_list_pluck( $terms_on_post, 'name' ) );
}
if ( $total_after !== $total_before ) {
	$failures[] = "import created {$total_after} - {$total_before} new occupation term(s)";
}
if ( $probe_term instanceof WP_Term ) {
	$failures[] = 'import created a public term named "' . $probe_role . '"';
}
if ( $probe_role !== $hint ) {
	$failures[] = 'occupation was not kept as an editorial hint (got "' . $hint . '")';
}

// Leave nothing behind.
wp_delete_post( $post_id, true );
delete_post_meta( $post_id, Import_Service::META_OCCUPATION_HINT );
$leftover = get_term_by( 'name', $probe_role, Catalogue::TAX_OCCUPATION );
if ( $leftover instanceof WP_Term ) {
	wp_delete_term( (int) $leftover->term_id, Catalogue::TAX_OCCUPATION );
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::warning( $failure );
	}
	WP_CLI::error( sprintf( '%d import/taxonomy check(s) failed.', count( $failures ) ) );
}

WP_CLI::success( 'Import wrote no occupation terms; occupation kept as an editorial hint. Probe removed.' );

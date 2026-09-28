<?php
/**
 * Prune occupation terms contaminated with a cause of death.
 *
 * Feed imports occasionally created taxonomy terms such as
 * "South Korean actor , blood cancer". Those terms are publicly browsable and
 * indexable, so each one is a live archive page asserting that a cause of
 * death is an occupation, with no person behind it. The import path now
 * cleans labels before creating terms, so this only has to repair terms that
 * already exist.
 *
 * Only terms with no posts attached are removed, and only when cleaning the
 * name actually changes it — a genuine occupation is never touched.
 *
 * Run: wp eval-file tests/prune-orphan-occupation-terms.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Domain\Value\Role_Label;
use Obitleague\Modules\Catalogue;

// hide_empty must be false: the terms being repaired have no posts, so
// hiding empty terms would exclude exactly what we came to find.
$terms = get_terms(
	array(
		'taxonomy'   => Catalogue::TAX_OCCUPATION,
		'hide_empty' => false,
		'fields'     => 'all',
	)
);
if ( is_wp_error( $terms ) ) {
	WP_CLI::error( 'Could not read occupation terms: ' . $terms->get_error_message() );
}

$pruned = 0;
foreach ( $terms as $term ) {
	if ( ! $term instanceof WP_Term || (int) $term->count > 0 ) {
		continue;
	}
	if ( '' === Role_Label::clean( $term->name ) ) {
		continue;
	}
	// Contaminated: the label names a cause of death. Note this is not the same
	// test as "clean() changes the string" — cleaning also normalises the
	// malformed " , " spacing feeds produce, which would otherwise delete
	// perfectly legitimate terms.
	if ( ! Role_Label::contains_cause( $term->name ) ) {
		continue;
	}
	$deleted = wp_delete_term( (int) $term->term_id, Catalogue::TAX_OCCUPATION );
	if ( ! is_wp_error( $deleted ) ) {
		++$pruned;
		WP_CLI::log( 'pruned "' . $term->name . '"' );
	}
}

WP_CLI::success( sprintf( 'Pruned %d contaminated occupation term(s).', $pruned ) );

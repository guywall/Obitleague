<?php
/**
 * Prune occupation terms that no one is filed under.
 *
 * Terms outlive the people assigned to them, and the occupation taxonomy is
 * public and indexable, so an empty term is a reachable archive page that can
 * only say "0 people". Two things used to produce them:
 *
 *   1. Feed import wrote unsourced role strings into this taxonomy. Every
 *      distinct role string became a permanent archive URL, duplicating an
 *      occupation that already existed as a clean sourced term — 70 of the 71
 *      orphans on a demo install were verbatim copies of a stored role. The
 *      living pool also arrived with a hardcoded "Public figure" occupation,
 *      which is how a generic catch-all became a public page at all.
 *   2. Wikidata sync later replaced each person's terms with sourced labels,
 *      orphaning the old ones.
 *
 * Import no longer writes this taxonomy (see Import_Service), so this only
 * has to clear what earlier versions left behind. It is safe to re-run: only
 * terms with zero people attached are removed, and a term is never deleted
 * while anyone is still filed under it.
 *
 * Run: wp eval-file tests/prune-orphan-occupation-terms.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Modules\Catalogue;

// hide_empty must be false: the terms being removed are exactly the empty ones,
// so hiding empty terms would exclude what we came to find.
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
$skipped = 0;
foreach ( $terms as $term ) {
	if ( ! $term instanceof WP_Term ) {
		continue;
	}
	// Belt and braces: re-read the live count rather than trusting a stale
	// term_taxonomy row, and never remove anything somebody is filed under.
	if ( (int) $term->count > 0 ) {
		++$skipped;
		continue;
	}
	$in_use = get_objects_in_term( array( (int) $term->term_id ), Catalogue::TAX_OCCUPATION );
	if ( is_array( $in_use ) && array() !== $in_use ) {
		++$skipped;
		continue;
	}

	$deleted = wp_delete_term( (int) $term->term_id, Catalogue::TAX_OCCUPATION );
	if ( is_wp_error( $deleted ) ) {
		WP_CLI::warning( 'could not delete "' . $term->name . '": ' . $deleted->get_error_message() );
		continue;
	}
	++$pruned;
}

WP_CLI::success(
	sprintf( 'Pruned %d empty occupation term(s); %d still in use.', $pruned, $skipped )
);

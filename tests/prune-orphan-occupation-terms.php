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
 * Import no longer writes this taxonomy (see Import_Service) and deleting a
 * person now prunes their terms (see Occupation_Taxonomy), so this only has to
 * clear what earlier versions left behind. It is safe to re-run, and it shares
 * `Occupation_Taxonomy::prune_term()` with that hook, so the rule for when a
 * term may be removed is defined in exactly one place.
 *
 * Run: wp eval-file tests/prune-orphan-occupation-terms.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Modules\Catalogue;
use Obitleague\Modules\Occupation_Taxonomy;

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
	// prune_term() re-checks the live relationship rows and refuses to remove
	// anything somebody is still filed under, so the cached count is not
	// trusted here either.
	if ( Occupation_Taxonomy::prune_term( (int) $term->term_id ) ) {
		++$pruned;
	} else {
		++$skipped;
	}
}

WP_CLI::success(
	sprintf( 'Pruned %d empty occupation term(s); %d still in use.', $pruned, $skipped )
);

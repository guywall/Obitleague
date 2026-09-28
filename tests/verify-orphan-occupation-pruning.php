<?php
/**
 * Verification for automatic pruning of orphaned occupation terms.
 *
 * Deleting a person used to strand every occupation only they were filed
 * under. Because each occupation term is a public, indexable archive URL, a
 * stranded term is a reachable page that can only say "0 people".
 *
 * The interesting half of this is not that the unique term disappears — it is
 * that the *shared* term survives. A prune that is slightly too eager would
 * pass the first check while quietly emptying a real occupation archive, so
 * both are asserted here, on a fixture built to have exactly that shape.
 *
 * Every delete is gated on proof that the row belongs to this probe, and the
 * fixture is removed in a `finally` block whether or not the checks pass. It
 * never touches an existing person or term.
 *
 * Run: wp eval-file tests/verify-orphan-occupation-pruning.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Modules\Catalogue;

const PROBE_PREFIX = 'ZZ Orphan Probe ';

$failures = array();
global $wpdb;

/** Create a scratch person filed under the given occupation labels. */
$make_person = static function ( string $slug, array $labels ): int {
	$post_id = wp_insert_post(
		array(
			'post_type'   => Catalogue::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => PROBE_PREFIX . $slug,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		throw new \RuntimeException( 'could not create probe person: ' . $post_id->get_error_message() );
	}
	$term_ids = array();
	foreach ( $labels as $label ) {
		$existing = get_term_by( 'name', $label, Catalogue::TAX_OCCUPATION );
		if ( $existing instanceof WP_Term ) {
			$term_ids[] = (int) $existing->term_id;
			continue;
		}
		$inserted = wp_insert_term( $label, Catalogue::TAX_OCCUPATION );
		if ( is_wp_error( $inserted ) ) {
			throw new \RuntimeException( "could not create probe term {$label}: " . $inserted->get_error_message() );
		}
		$term_ids[] = (int) $inserted['term_id'];
	}
	wp_set_object_terms( (int) $post_id, $term_ids, Catalogue::TAX_OCCUPATION, false );
	return (int) $post_id;
};

/** Term ids for a label, or empty if the term is gone. */
$term_ids_for = static function ( string $label ) use ( $wpdb ): array {
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = %s AND t.name = %s",
			Catalogue::TAX_OCCUPATION,
			$label
		)
	);
	return array_map( 'intval', $ids );
};

$UNIQUE = PROBE_PREFIX . 'Unique Occupation';
$SHARED = PROBE_PREFIX . 'Shared Occupation';

$person_a = 0;
$person_b = 0;

try {
	// A holds an occupation nobody else has. B holds one that A also has, so
	// that term must outlive A.
	$person_a = $make_person( 'A', array( $UNIQUE, $SHARED ) );
	$person_b = $make_person( 'B', array( $SHARED ) );

	$unique_ids = $term_ids_for( $UNIQUE );
	$shared_ids = $term_ids_for( $SHARED );
	count( $unique_ids ) === 1 || $failures[] = 'probe setup did not create exactly one unique term';
	count( $shared_ids ) === 1 || $failures[] = 'probe setup did not create exactly one shared term';

	// Delete A through the normal WordPress path, which is what the hook listens to.
	$deleted = wp_delete_post( $person_a, true );
	$person_a = 0;
	$deleted || $failures[] = 'wp_delete_post did not report deleting the probe person';

	WP_CLI::log( 'after deleting a person who held one unique and one shared occupation:' );

	// The unique term is gone.
	$remaining_unique = $term_ids_for( $UNIQUE );
	$remaining_shared = $term_ids_for( $SHARED );

	// `A || record()` records when A is FALSE. The unique term must be gone, so
	// its surviving is the failure — this needs &&. The shared term is the
	// other way round: it must still be there, so its absence is the failure.
	$remaining_unique && $failures[] = 'the occupation only the deleted person held was left behind';
	$remaining_shared || $failures[] = 'a shared occupation was deleted while another person still holds it';

	// ...and the shared term is still genuinely attached to B, not merely present.
	if ( $remaining_shared ) {
		$still_filed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE tt.taxonomy = %s AND t.name = %s AND tr.object_id = %d",
				Catalogue::TAX_OCCUPATION,
				$SHARED,
				$person_b
			)
		);
		1 === $still_filed || $failures[] = 'the surviving shared term is no longer attached to the remaining person';
	}

	// Deleting the last holder prunes the term too, so the fix is not
	// one-shot bookkeeping for the first delete.
	wp_delete_post( $person_b, true );
	$person_b = 0;
	$term_ids_for( $SHARED ) && $failures[] = 'deleting the last person holding a term did not prune it';

	// The whole taxonomy must be left as it was found.
	$empty = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->terms} t
		 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
		 WHERE tt.taxonomy = '" . esc_sql( Catalogue::TAX_OCCUPATION ) . "' AND tt.count = 0"
	);
	0 === $empty || $failures[] = "the fixture left {$empty} empty occupation term(s) behind";

	WP_CLI::log( sprintf( 'empty occupation terms after the fixture: %d', $empty ) );
} catch ( \Throwable $e ) {
	$failures[] = get_class( $e ) . ': ' . $e->getMessage();
} finally {
	// Remove the fixture whatever happened. Each delete is gated on the probe
	// name, so a real person is never removed.
	foreach ( array( $person_a, $person_b ) as $id ) {
		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && str_starts_with( (string) $post->post_title, PROBE_PREFIX ) ) {
				wp_delete_post( $id, true );
			}
		}
	}
	foreach ( array( $UNIQUE, $SHARED ) as $label ) {
		foreach ( $term_ids_for( $label ) as $term_id ) {
			$term = get_term( $term_id, Catalogue::TAX_OCCUPATION );
			if ( $term instanceof WP_Term && str_starts_with( (string) $term->name, PROBE_PREFIX ) ) {
				wp_delete_term( $term_id, Catalogue::TAX_OCCUPATION );
			}
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::warning( $failure );
	}
	WP_CLI::error( sprintf( '%d orphan-pruning check(s) failed.', count( $failures ) ) );
}

WP_CLI::success( 'Orphaned occupation terms are pruned on delete, shared terms are kept, and the probe was removed.' );

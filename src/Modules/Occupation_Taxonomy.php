<?php
/**
 * Occupation taxonomy upkeep.
 *
 * The occupation taxonomy is public and indexable: every term is a permanent
 * archive URL. That makes an empty term worse than a missing one — it is a
 * reachable page that can only ever say "0 people" — and `Seo` already
 * noindexes those. Deleting a person is the one thing that still produced
 * them silently.
 *
 * `People_Sync` is the only writer of this taxonomy, and it attaches terms to
 * the person record. WordPress removes those relationships when the post is
 * deleted but leaves the terms behind, so a person who is removed — by an
 * editor, by a correction, or by a de-duplication pass — strands every
 * occupation that only they were filed under.
 *
 * This was not a theoretical gap. A live clean-slate re-import on the demo
 * install deleted 150 people and left 45 orphaned terms behind; the existing
 * `tests/prune-orphan-occupation-terms.php` sweep recovered them by hand.
 *
 * Note that custom post types get no trash protection in `wp_delete_post()`:
 * its trash short-circuit only covers `post` and `page`, so an `obit_person`
 * record always takes the permanent-delete path and always loses its term
 * relationships. There is no "it is only in the trash" case to worry about.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Occupation_Taxonomy {

	/**
	 * Occupation term ids captured per person, awaiting the delete.
	 *
	 * Term relationships are removed by core partway through `wp_delete_post()`,
	 * long before `deleted_post` fires, so the terms have to be read while the
	 * record still exists. Keyed by post id and always consumed in the same
	 * request, so this cannot grow.
	 *
	 * @var array<int,int[]>
	 */
	private static array $pending = array();

	private function __construct() {}

	/**
	 * Register the delete hooks.
	 */
	public static function boot(): void {
		add_action( 'before_delete_post', array( self::class, 'capture_terms' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'prune_orphans' ), 10, 2 );
	}

	/**
	 * Record which occupation terms the person being deleted is filed under.
	 *
	 * @param int          $post_id Post id.
	 * @param \WP_Post|null $post   Post object.
	 */
	public static function capture_terms( int $post_id, ?\WP_Post $post = null ): void {
		if ( null === $post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof \WP_Post || Catalogue::POST_TYPE !== $post->post_type ) {
			return;
		}

		$term_ids = wp_get_object_terms( $post_id, Catalogue::TAX_OCCUPATION, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $term_ids ) || ! $term_ids ) {
			return;
		}

		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		if ( $term_ids ) {
			self::$pending[ $post_id ] = $term_ids;
		}
	}

	/**
	 * Remove any of the deleted person's terms that nobody else is filed under.
	 *
	 * @param int          $post_id Post id.
	 * @param \WP_Post|null $post   Post object.
	 */
	public static function prune_orphans( int $post_id, ?\WP_Post $post = null ): void {
		if ( null === $post ) {
			$post = get_post( $post_id );
		}
		if ( $post instanceof \WP_Post && Catalogue::POST_TYPE !== $post->post_type ) {
			return;
		}

		$term_ids = self::$pending[ $post_id ] ?? array();
		unset( self::$pending[ $post_id ] );

		foreach ( $term_ids as $term_id ) {
			self::prune_term( $term_id );
		}
	}

	/**
	 * Delete an occupation term, but only if it has no remaining objects.
	 *
	 * The liveness test is the number of rows in `wp_term_relationships`, not
	 * the cached `count` on the term taxonomy row: the cache is a denormalised
	 * copy and a term showing "0" can still be in use. A term that any object
	 * — any post type, published or not — is still filed under is left alone.
	 *
	 * @param int $term_id Term id (not term_taxonomy_id).
	 * @return bool True when a term was deleted.
	 */
	public static function prune_term( int $term_id ): bool {
		global $wpdb;

		$term = get_term( $term_id, Catalogue::TAX_OCCUPATION );
		if ( ! $term instanceof \WP_Term ) {
			// Either gone already, or a term of some other taxonomy that
			// happens to share the id. Never touch it.
			return false;
		}

		$still_used = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
				(int) $term->term_taxonomy_id
			)
		);
		if ( $still_used > 0 ) {
			return false;
		}

		$deleted = wp_delete_term( (int) $term->term_id, Catalogue::TAX_OCCUPATION );
		return ! is_wp_error( $deleted ) && false !== $deleted;
	}
}

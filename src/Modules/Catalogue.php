<?php
/**
 * Public person catalogue.
 *
 * People are a public post type; identity, evidence and eligibility live in
 * plugin tables and meta. The pipeline never flips a person to deceased —
 * only an editor's approved event does, and this module projects it.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Value\Partial_Date;

final class Catalogue {

	public const POST_TYPE   = 'obit_person';
	public const TAX_OCCUPATION = 'obit_occupation';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_filter( 'obitleague_person_queryable_statuses', array( self::class, 'default_statuses' ) );
	}

	/** Statuses exposed to public queries; private candidates never appear. */
	public static function default_statuses( array $statuses ): array {
		return $statuses ?: array( 'publish' );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'People', 'obitleague' ),
					'singular_name' => __( 'Person', 'obitleague' ),
				),
				'public'          => true,
				'hierarchical'    => false,
				'show_in_rest'    => true,
				'rest_base'       => 'obit-people',
				'has_archive'     => true,
				'menu_icon'       => 'dashicons-id-alt',
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'rewrite'         => array( 'slug' => 'person' ),
				'capability_type' => 'post',
			)
		);

		// Nomination queue: private, editor-facing; never public or selectable.
		register_post_type(
			'obit_nomination',
			array(
				'labels'          => array(
					'name'          => __( 'Nominations', 'obitleague' ),
					'singular_name' => __( 'Nomination', 'obitleague' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::POST_TYPE,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'author' ),
				'capability_type' => 'post',
			)
		);

		register_taxonomy(
			self::TAX_OCCUPATION,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Occupations', 'obitleague' ),
					'singular_name' => __( 'Occupation', 'obitleague' ),
				),
				'public'            => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
			)
		);
	}

	public static function register_meta(): void {
		$protected = array(
			'obit_uuid'            => 'string',
			'obit_qid'             => 'string',
			'obit_birth_date'      => 'string', // Y-m-d, exact only.
			'obit_death_date'      => 'string', // Y-m-d or partial (Y-m / Y).
			'obit_death_precision' => 'string', // exact|month|year|unknown.
			'obit_cause_status'    => 'string',
			'obit_cause_text'      => 'string',
			'obit_cause_source'    => 'string', // wikidata-P509 | editor | none.
			'obit_enriched_at'     => 'string', // UTC datetime of the last Wikidata enrichment fetch.
			'obit_eligibility'     => 'string', // candidate|approved|ineligible.
			'obit_portrait_credit' => 'string',
			'obit_eligibility_note' => 'string',
		);
		foreach ( $protected as $key => $type ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false, // Never editable through the content REST API.
					'auth_callback' => static fn (): bool => current_user_can( 'manage_options' ),
				)
			);
		}
	}

	/** True when the person may be selected for a team in the given season. */
	public static function is_selectable( int $post_id, int $season ): bool {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( 'approved' !== (string) get_post_meta( $post_id, 'obit_eligibility', true ) ) {
			return false;
		}
		// Any unresolved death report blocks new selection (existing entries are unaffected).
		if ( '' !== (string) get_post_meta( $post_id, 'obit_death_date', true ) ) {
			return false;
		}
		// An open review case also blocks selection until an editor decides.
		$uuid = (string) get_post_meta( $post_id, 'obit_uuid', true );
		if ( '' !== $uuid && \Obitleague\Modules\Review_Service::has_open_case( $uuid ) ) {
			return false;
		}
		$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		if ( '' === $birth_raw ) {
			return false;
		}
		try {
			$birth = self::partial_date_from_stored( $birth_raw );
		} catch ( \InvalidArgumentException ) {
			return false;
		}
		if ( ! $birth->is_exact() ) {
			return false; // A verified full birth date is required.
		}
		$age = \Obitleague\Domain\Age::completed_at( $birth, \Obitleague\Domain\Deadline_Policy::season_start( $season ) );
		return null !== $age && $age >= \Obitleague\Domain\Value\Ruleset::MIN_AGE;
	}

	/** Parse a stored Y-m-d / Y-m / Y value into a Partial_Date. */
	public static function partial_date_from_stored( string $value ): Partial_Date {
		if ( ! preg_match( '/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$/', $value, $m ) ) {
			throw new \InvalidArgumentException( 'Malformed stored date: ' . $value );
		}
		return new Partial_Date(
			(int) $m[1],
			isset( $m[2] ) ? (int) $m[2] : null,
			isset( $m[3] ) ? (int) $m[3] : null
		);
	}
}

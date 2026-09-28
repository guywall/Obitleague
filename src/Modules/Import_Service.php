<?php
/**
 * Seed import service.
 *
 * Idempotent, QID-keyed import of candidate people (plan section 10).
 * Structured facts only (Wikidata CC0); candidates stay private until an
 * editor approves them; date precision is preserved exactly as sourced.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Role_Label;

final class Import_Service {

	private function __construct() {}

	/**
	 * Import or refresh one person, idempotent by Wikidata QID.
	 *
	 * @param array{qid:string, name:string, birth_date:string, death_date?:string, occupation?:string, role?:string, enwiki?:string} $data
	 * @return int Post id of the person record.
	 */
	public static function import_person( array $data ): int {
		$qid = strtoupper( trim( (string) ( $data['qid'] ?? '' ) ) );
		if ( ! preg_match( '/^Q\d+$/', $qid ) ) {
			throw new \InvalidArgumentException( 'A valid Wikidata QID is required.' );
		}

		$name = trim( (string) ( $data['name'] ?? '' ) );
		// Strip a trailing disambiguator: "Jane Doe (actress)" → "Jane Doe".
		$name    = preg_replace( '/\s*\([^)]*\)$/', '', $name ) ?? $name;
		$name    = trim( $name );
		if ( '' === $name || mb_strlen( $name ) > 191 ) {
			throw new \InvalidArgumentException( "A usable name is required for {$qid}." );
		}

		$birth  = (string) ( $data['birth_date'] ?? '' );
		$death  = (string) ( $data['death_date'] ?? '' );
		$role   = trim( (string) ( $data['role'] ?? '' ) );
		$enwiki = trim( (string) ( $data['enwiki'] ?? '' ) );
		try {
			$birth_parsed = self::parse_partial( $birth );
			$death_parsed = '' !== $death ? self::parse_partial( $death ) : null;
		} catch ( \InvalidArgumentException $e ) {
			throw new \InvalidArgumentException( "{$qid}: {$e->getMessage()}" );
		}

		$existing = self::post_id_by_qid( $qid );
		if ( $existing ) {
			update_post_meta( $existing, 'obit_birth_date', $birth );
			if ( '' !== $role && '' === (string) get_post_meta( $existing, 'obit_role', true ) ) {
				update_post_meta( $existing, 'obit_role', $role );
			}
			if ( '' !== $enwiki && '' === (string) get_post_meta( $existing, 'obit_enwiki', true ) ) {
				update_post_meta( $existing, 'obit_enwiki', $enwiki );
			}
			if ( $death_parsed ) {
				update_post_meta( $existing, 'obit_death_date', $death );
				update_post_meta( $existing, 'obit_death_precision', $death_parsed->precision() );
			}
			if ( ! empty( $data['occupation'] ) ) {
				self::attach_occupation( $existing, (string) $data['occupation'] );
			}
			return $existing;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => Catalogue::POST_TYPE,
				// Candidates stay private until an editor approves them.
				'post_status'  => 'draft',
				'post_title'   => $name,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Import failed for ' . $qid . ': ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, 'obit_uuid', wp_generate_uuid4() );
		update_post_meta( $post_id, 'obit_qid', $qid );
		update_post_meta( $post_id, 'obit_birth_date', $birth );
		if ( '' !== $role ) {
			update_post_meta( $post_id, 'obit_role', $role );
		}
		if ( '' !== $enwiki ) {
			update_post_meta( $post_id, 'obit_enwiki', $enwiki );
		}
		update_post_meta( $post_id, 'obit_eligibility', 'candidate' );
		update_post_meta( $post_id, 'obit_eligibility_note', 'Seed import — awaiting editorial eligibility review.' );

		if ( $death_parsed ) {
			update_post_meta( $post_id, 'obit_death_date', $death );
			update_post_meta( $post_id, 'obit_death_precision', $death_parsed->precision() );
			update_post_meta( $post_id, 'obit_cause_status', 'not_disclosed' );
		}

		if ( ! empty( $data['occupation'] ) ) {
			self::attach_occupation( $post_id, (string) $data['occupation'] );
		}

		return (int) $post_id;
	}

	/** Find an imported person by Wikidata QID. */
	public static function post_id_by_qid( string $qid ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_qid' AND meta_value = %s LIMIT 1",
				strtoupper( trim( $qid ) )
			)
		);
	}

	/**
	 * Editorial approval: the candidate becomes published and selectable
	 * (an exact birth date is required; already-dead records stay
	 * selectable-for-archive only — Catalogue::is_selectable refuses them).
	 */
	public static function approve_person( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || Catalogue::POST_TYPE !== $post->post_type ) {
			throw new \InvalidArgumentException( 'Not a person record.' );
		}
		$raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		try {
			$birth = self::parse_partial( $raw );
		} catch ( \InvalidArgumentException ) {
			throw new \InvalidArgumentException( 'Person lacks a usable birth date; cannot approve.' );
		}
		if ( ! $birth->is_exact() ) {
			throw new \InvalidArgumentException( 'Birth date lacks day precision; eligibility review required.' );
		}

		update_post_meta( $post_id, 'obit_eligibility', 'approved' );
		update_post_meta( $post_id, 'obit_eligibility_note', 'Approved from seed import (demo).' );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		// Approval is what turns a candidate into a describable public record.
		Person_Content::regenerate( $post_id );
	}

	/** Parse a stored/sourced date (Y, Y-m or Y-m-d) into a Partial_Date. */
	public static function parse_partial( string $value ): Partial_Date {
		$value = trim( $value );
		// Accept full ISO timestamps from Wikidata: 1948-04-01T00:00:00Z.
		if ( preg_match( '/^(-?\d{4})-(\d{2})-(\d{2})T/', $value, $m ) ) {
			return new Partial_Date( self::year_int( $m[1] ), (int) $m[2], (int) $m[3] );
		}
		if ( preg_match( '/^(-?\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return new Partial_Date( self::year_int( $m[1] ), (int) $m[2], (int) $m[3] );
		}
		if ( preg_match( '/^(-?\d{4})-(\d{2})$/', $value, $m ) ) {
			return new Partial_Date( self::year_int( $m[1] ), (int) $m[2] );
		}
		if ( preg_match( '/^(-?\d{4})$/', $value, $m ) ) {
			return new Partial_Date( self::year_int( $m[1] ) );
		}
		throw new \InvalidArgumentException( 'Malformed date: ' . $value );
	}

	private static function year_int( string $year ): int {
		return (int) str_replace( '-', '', $year );
	}

	/**
	 * Attach an occupation label to the occupation taxonomy.
	 *
	 * Feed-extracted occupations occasionally carry a trailing cause of death
	 * ("actor, blood cancer"). The taxonomy is publicly browsable, so the
	 * label is cleaned first: an unsanitised term becomes a permanent, indexable
	 * archive page asserting that a cause of death is an occupation.
	 */
	private static function attach_occupation( int $post_id, string $occupation ): void {
		$occupation = Role_Label::clean( $occupation );
		if ( '' === $occupation ) {
			return;
		}
		$term = get_term_by( 'name', $occupation, Catalogue::TAX_OCCUPATION );
		if ( ! $term ) {
			$result = wp_insert_term( $occupation, Catalogue::TAX_OCCUPATION );
			$term_id = is_wp_error( $result ) ? 0 : (int) $result['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		if ( $term_id ) {
			wp_set_object_terms( $post_id, array( $term_id ), Catalogue::TAX_OCCUPATION, true );
		}
	}
}

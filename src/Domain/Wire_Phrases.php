<?php
/**
 * Editor-managed death-wire phrases.
 *
 * The built-in phrase tables in Feed_Classifier are the default wiring; this
 * class is the managed layer above them. Editors can add phrases (strong
 * death phrases, soft dampeners, hard exclusions, confirmation attributions),
 * exclude (disable) any phrase — including built-ins — and see per-phrase
 * hit statistics.
 *
 * Runtime model: the enabled DB rows are merged over the built-in tables and
 * handed to the classifier as overrides. A disabled built-in is removed from
 * the merged set. Nothing here ever mutates the built-in constants.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Phrases {

	public const KIND_STRONG   = 'strong';
	public const KIND_SOFT     = 'soft';
	public const KIND_EXCLUDE  = 'exclude';
	public const KIND_CONFIRM  = 'confirm';
	public const KINDS         = array( self::KIND_STRONG, self::KIND_SOFT, self::KIND_EXCLUDE, self::KIND_CONFIRM );

	/** Default weights for editor-added phrases, mirroring the built-ins. */
	public const DEFAULT_WEIGHTS = array(
		self::KIND_STRONG  => array( 55, 33 ),
		self::KIND_SOFT    => array( 20, 12 ),
		self::KIND_CONFIRM => array( 10, 10 ),
	);

	private const MAX_PHRASE_LEN = 120;
	private const MAX_ROWS       = 500;

	private function __construct() {}

	/**
	 * The merged phrase tables the classifier should use: built-ins minus
	 * disabled phrases, plus enabled managed ones.
	 *
	 * @return array{strong:array<string,array<int,int>>,soft:array<string,array<int,int>>,exclude:string[],confirm:string[]}
	 */
	public static function effective_tables(): array {
		$tables = array(
			'strong'  => Feed_Classifier::builtin_strong_phrases(),
			'soft'    => Feed_Classifier::builtin_soft_negatives(),
			'exclude' => Feed_Classifier::builtin_hard_exclusions(),
			'confirm' => Feed_Classifier::builtin_confirmation_phrases(),
		);

		foreach ( self::enabled_rows() as $row ) {
			$phrase = (string) $row['phrase'];
			$kind   = (string) $row['kind'];
			if ( self::KIND_EXCLUDE === $kind ) {
				$tables['exclude'][] = $phrase;
				continue;
			}
			if ( self::KIND_CONFIRM === $kind ) {
				$tables['confirm'][] = $phrase;
				continue;
			}
			$tables[ $kind ][ $phrase ] = array( (int) $row['weight_title'], (int) $row['weight_body'] );
		}

		// Disabled managed rows remove that exact phrase from the set.
		foreach ( self::disabled_phrases() as $phrase ) {
			unset( $tables['strong'][ $phrase ], $tables['soft'][ $phrase ] );
			$tables['exclude'] = array_values( array_diff( $tables['exclude'], array( $phrase ) ) );
			$tables['confirm'] = array_values( array_diff( $tables['confirm'], array( $phrase ) ) );
		}

		return $tables;
	}

	/**
	 * Classify with the current managed tables. Thin wrapper kept so
	 * callers never touch Feed_Classifier's override plumbing directly.
	 *
	 * @param string $title   Item title.
	 * @param string $text    Body text.
	 * @param array  $context Source context (source_name, source_url, categories).
	 * @return array{classification:string, score:int, matched:string[], negative:string[], excluded:string[]}
	 */
	public static function classify( string $title, string $text = '', array $context = array() ): array {
		return Feed_Classifier::classify( $title, $text, $context, self::effective_tables() );
	}

	/**
	 * Record that a managed phrase was part of a matching verdict. Built-in
	 * phrases are not tracked here — only rows an editor can see and manage.
	 */
	public static function note_hits( array $matched, array $negative, array $excluded ): void {
		$phrases = array_unique( array_merge( $matched, $negative, $excluded ) );
		if ( ! $phrases ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_wire_phrases';
		$now   = current_time( 'mysql', true );
		foreach ( $phrases as $phrase ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET hits = hits + 1, last_hit_at = %s WHERE phrase = %s AND enabled = 1",
					$now,
					(string) $phrase
				)
			);
		}
	}

	/**
	 * Add a managed phrase. Refuses duplicates (a phrase exists once, in one
	 * kind) and caps the table so the classifier stays fast.
	 *
	 * @return string|WP_Error The inserted phrase, or an error.
	 */
	public static function add( string $phrase, string $kind, ?int $weight_title = null, ?int $weight_body = null, ?int $created_by = null ) {
		global $wpdb;
		$phrase = trim( mb_strtolower( $phrase ) );
		$phrase = (string) preg_replace( '/\s+/u', ' ', $phrase );
		if ( '' === $phrase || mb_strlen( $phrase ) > self::MAX_PHRASE_LEN ) {
			return new \WP_Error( 'obitleague_wire_phrase', 'A phrase needs 1–' . self::MAX_PHRASE_LEN . ' characters.' );
		}
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'obitleague_wire_phrase', 'Unknown phrase kind.' );
		}
		if ( self::count_all() >= self::MAX_ROWS ) {
			return new \WP_Error( 'obitleague_wire_phrase', 'The phrase table is full (' . self::MAX_ROWS . ' rows). Remove one first.' );
		}
		$table = $wpdb->prefix . 'obitleague_wire_phrases';
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE phrase = %s", $phrase ) );
		if ( $exists ) {
			return new \WP_Error( 'obitleague_wire_phrase', 'That phrase is already managed.' );
		}
		$weights = self::DEFAULT_WEIGHTS[ $kind ] ?? array( 0, 0 );
		$w_title = $weight_title ?? $weights[0];
		$w_body  = $weight_body ?? $weights[1];
		$ok = $wpdb->insert(
			$table,
			array(
				'phrase'       => $phrase,
				'kind'         => $kind,
				'weight_title' => (int) $w_title,
				'weight_body'  => (int) $w_body,
				'enabled'      => 1,
				'created_by'   => $created_by,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'obitleague_wire_phrase', 'Could not store the phrase.' );
		}
		return $phrase;
	}

	/** Enable or disable a managed row (or an unmanaged phrase, which is adopted as a disabled shadow of the built-in). */
	public static function set_enabled( string $phrase, bool $enabled ): bool {
		global $wpdb;
		$phrase = trim( mb_strtolower( $phrase ) );
		$table  = $wpdb->prefix . 'obitleague_wire_phrases';
		$row_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE phrase = %s", $phrase ) );
		if ( $row_id ) {
			return false !== $wpdb->update( $table, array( 'enabled' => (int) $enabled ), array( 'id' => $row_id ), array( '%d' ), array( '%d' ) );
		}
		// Excluding a built-in: store a disabled shadow row so the merged
		// tables drop it, and the phrase shows up in the manager UI.
		if ( $enabled ) {
			return false; // Nothing to enable.
		}
		return false !== $wpdb->insert(
			$table,
			array(
				'phrase'       => $phrase,
				'kind'         => self::KIND_STRONG,
				'weight_title' => 0,
				'weight_body'  => 0,
				'enabled'      => 0,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/** Remove a managed row entirely. Built-in phrases re-activate when their shadow row is deleted. */
	public static function remove( string $phrase ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $wpdb->prefix . 'obitleague_wire_phrases', array( 'phrase' => trim( mb_strtolower( $phrase ) ) ), array( '%s' ) );
	}

	/** All managed rows for the manager UI, hit stats included. */
	public static function all_rows(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			"SELECT id, phrase, kind, weight_title, weight_body, enabled, hits, last_hit_at, created_at
			 FROM {$wpdb->prefix}obitleague_wire_phrases ORDER BY enabled DESC, hits DESC, phrase ASC",
			ARRAY_A
		);
		return array_map( static fn ( $r ): array => (array) $r, $rows );
	}

	/** Count of managed rows (enabled or not). */
	public static function count_all(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_wire_phrases' );
	}

	/** @return string[] Enabled managed phrases by kind. */
	private static function enabled_rows(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			"SELECT phrase, kind, weight_title, weight_body FROM {$wpdb->prefix}obitleague_wire_phrases WHERE enabled = 1",
			ARRAY_A
		);
		return array_map( static fn ( $r ): array => (array) $r, $rows );
	}

	/** @return string[] Phrases explicitly disabled (shadow rows included). */
	private static function disabled_phrases(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col(
			"SELECT phrase FROM {$wpdb->prefix}obitleague_wire_phrases WHERE enabled = 0"
		) );
	}
}

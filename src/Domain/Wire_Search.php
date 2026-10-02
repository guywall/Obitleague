<?php
/**
 * Interpret one Wikipedia search API response.
 *
 * The wire's whole "new name" path hangs off a fuzzy title search. If that
 * search is recorded as "no article" when it actually failed — a transport
 * error, a 429/503 throttle, or a malformed body — the story is parked as
 * `no_anchor` and never retried while the answer sits in the transient cache.
 * Well-known people then look like they have no Wikipedia article at all.
 *
 * This class draws the line the caller cannot: only a definitive 200 answer
 * (a hit, or a real empty result) may be cached. Everything else is a failure
 * to ask, not a negative answer, and must be retried.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Search {

	/** The search returned at least one article title. */
	public const FOUND = 'found';

	/** The search answered cleanly and genuinely found no article. */
	public const NONE = 'none';

	/** The request failed, or the answer could not be read. */
	public const TRANSIENT = 'transient';

	/** Wikipedia asked us to slow down (429/503). */
	public const THROTTLED = 'throttled';

	private function __construct() {}

	/**
	 * Classify one HTTP outcome for the title search.
	 *
	 * @param bool  $transport_error wp_remote_get() returned a WP_Error.
	 * @param int   $code            HTTP status (0 for a transport error).
	 * @param mixed $body            Decoded JSON body, or null when unreadable.
	 * @return array{outcome:string,title:string}
	 */
	public static function interpret( bool $transport_error, int $code, mixed $body ): array {
		if ( $transport_error ) {
			return array( 'outcome' => self::TRANSIENT, 'title' => '' );
		}
		if ( in_array( $code, array( 429, 503 ), true ) ) {
			return array( 'outcome' => self::THROTTLED, 'title' => '' );
		}
		if ( 200 !== $code ) {
			return array( 'outcome' => self::TRANSIENT, 'title' => '' );
		}
		// A 200 whose body cannot be read is a malformed answer, not a verdict.
		if ( ! is_array( $body )
			|| ! isset( $body['query'] )
			|| ! is_array( $body['query'] )
			|| ! array_key_exists( 'search', $body['query'] )
			|| ! is_array( $body['query']['search'] )
		) {
			return array( 'outcome' => self::TRANSIENT, 'title' => '' );
		}
		$search = $body['query']['search'];
		if ( array() === $search ) {
			return array( 'outcome' => self::NONE, 'title' => '' );
		}
		$first = $search[0]['title'] ?? '';
		if ( is_string( $first ) && '' !== trim( $first ) ) {
			return array( 'outcome' => self::FOUND, 'title' => trim( $first ) );
		}
		return array( 'outcome' => self::NONE, 'title' => '' );
	}

	/**
	 * Whether an outcome may be cached as the standing answer for a term.
	 * Only a hit or a genuine empty result is definitive; a failure to ask
	 * must be retried, never remembered as "no article".
	 */
	public static function is_definitive( string $outcome ): bool {
		return self::FOUND === $outcome || self::NONE === $outcome;
	}
}

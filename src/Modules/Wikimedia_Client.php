<?php
/**
 * Outbound Wikimedia reads for the death wire.
 *
 * Every network call the wire makes to Wikipedia or Wikidata lives here, so
 * the wire's orchestration can be read as decisions rather than HTTP. The
 * politeness contract is unchanged: each call notes success (clearing the
 * shared pause) or records a rate limit and returns a WP_Error with the same
 * codes the queue already understands. Cached results use the same transient
 * keys and TTLs as before, so nothing downstream can tell the move happened.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Wikimedia_Client {

	/** How long a fetched Wikipedia article (wikitext + QID) stays cached. */
	private const WIKI_TTL        = 2 * HOUR_IN_SECONDS;
	/** How long a concluded (hit or genuine empty) title search stays cached. */
	private const WIRE_SEARCH_TTL = 2 * HOUR_IN_SECONDS;
	/** Shared User-Agent identifying the plugin to Wikimedia. */
	public const USER_AGENT = 'Obitleague-DeathWire/0.1 (WordPress; +obitleague.co.uk)';

	private function __construct() {}

	/** The shared Wikimedia cooldown, as an error the queue parks on. */
	public static function paused_error(): ?\WP_Error {
		$pause = Discovery_Service::rate_limit_pause_until();
		if ( $pause > time() ) {
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikimedia pause until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause ) ) );
		}
		return null;
	}

	/** One WDQS page (politeness handled by the caller's queue context). */
	public static function sparql( string $query ): array|\WP_Error {
		$response = wp_remote_get(
			'https://query.wikidata.org/sparql?format=json&query=' . rawurlencode( $query ),
			array(
				'timeout'    => 60,
				'user-agent' => self::USER_AGENT,
				'headers'    => array( 'Accept' => 'application/sparql-results+json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'obitleague_discovery_network', 'Wikidata could not be reached: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 429, 503 ), true ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after;
			$pause_until = Discovery_Service::note_rate_limit( $retry_after );
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikidata asked us to slow down until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ) );
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'obitleague_discovery_http', 'Wikidata returned HTTP ' . $code . '.' );
		}
		Discovery_Service::note_success();
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$cards = is_array( $body ) ? (array) ( $body['results']['bindings'] ?? array() ) : array();
		return array_map( static fn ( $b ): array => is_array( $b ) ? $b : array(), $cards );
	}

	/** https://en.wikipedia.org/wiki/Title → Title (URL-decoded). */
	public static function enwiki_title_from_uri( string $uri ): string {
		$marker = '/wiki/';
		$pos    = strpos( $uri, $marker );
		if ( false === $pos ) {
			return '';
		}
		return rawurldecode( substr( $uri, $pos + strlen( $marker ) ) );
	}

	/**
	 * Wikipedia search: the best article title for a name, cached briefly.
	 *
	 * Only a definitive answer is cached: a hit, or a genuine empty result.
	 * A transport error, a 429/503 throttle or a malformed body is a failure
	 * to ask, not proof that no article exists, so it returns a WP_Error and
	 * caches nothing. The caller defers the story and retries it later.
	 */
	public static function search_title( string $term ): string|\WP_Error {
		$term   = trim( $term );
		if ( '' === $term ) {
			return '';
		}
		$cache_key = 'obit_wikisearch_' . md5( mb_strtolower( $term ) );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$paused = self::paused_error();
		if ( $paused ) {
			return $paused; // A global pause is not an answer about the name.
		}
		$response = wp_remote_get(
			'https://en.wikipedia.org/w/api.php?action=query&list=search&format=json&formatversion=2&srlimit=3&srsearch=' . rawurlencode( $term ),
			array( 'timeout' => 15, 'user-agent' => self::USER_AGENT )
		);
		$transport_error = is_wp_error( $response );
		$code            = $transport_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body            = ( ! $transport_error && 200 === $code )
			? json_decode( (string) wp_remote_retrieve_body( $response ), true )
			: null;
		$verdict = \Obitleague\Domain\Wire_Search::interpret( $transport_error, $code, $body );
		if ( \Obitleague\Domain\Wire_Search::THROTTLED === $verdict['outcome'] ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after;
			$pause_until = Discovery_Service::note_rate_limit( $retry_after );
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikipedia asked us to slow down until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ) );
		}
		if ( ! \Obitleague\Domain\Wire_Search::is_definitive( $verdict['outcome'] ) ) {
			// Never cache a failure as "no article": the story retries instead.
			return new \WP_Error( 'obitleague_discovery_network', 'Wikipedia search failed for "' . $term . '".' );
		}
		// Both a hit and a genuine empty result are definitive: cache them.
		Discovery_Service::note_success();
		set_transient( $cache_key, $verdict['title'], self::WIRE_SEARCH_TTL );
		return $verdict['title'];
	}

	/**
	 * One cached enwiki request carrying everything the wire needs about a
	 * person: the article wikitext AND the Wikidata QID behind it (both live
	 * in a single action=query&prop=revisions|pageprops call). Consumers:
	 * death-year check, exact death-date extraction, birth year, and the
	 * wordcloud stored on the record for same-name disambiguation.
	 *
	 * @return array{wikitext:string, qid:string}|\WP_Error Cached arrays are
	 *         shared by reference discipline — callers must not mutate.
	 */
	public static function article( string $title ): array|\WP_Error {
		$title = str_replace( ' ', '_', trim( $title ) );
		if ( '' === $title ) {
			return array( 'wikitext' => '', 'qid' => '' );
		}
		$cache_key = 'obit_wikiart_' . md5( $title );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['wikitext'] ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			'https://en.wikipedia.org/w/api.php?action=query&prop=revisions%7Cpageprops&rvprop=content&rvslots=main&format=json&formatversion=2&maxlag=5&titles=' . rawurlencode( $title ),
			array( 'timeout' => 20, 'user-agent' => self::USER_AGENT )
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'obitleague_discovery_network', 'Wikipedia could not be reached: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 429, 503 ), true ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after;
			$pause_until = Discovery_Service::note_rate_limit( $retry_after );
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikipedia asked us to slow down until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ) );
		}
		if ( 200 !== $code ) {
			// Negative answers cache too: dead titles stay dead for the TTL.
			$empty = array( 'wikitext' => '', 'qid' => '' );
			set_transient( $cache_key, $empty, self::WIKI_TTL );
			return $empty;
		}
		Discovery_Service::note_success();
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$page  = (array) ( $body['query']['pages'][0] ?? array() );
		$out   = array(
			'wikitext' => (string) ( $page['revisions'][0]['slots']['main']['content'] ?? '' ),
			'qid'      => (string) ( $page['pageprops']['wikibase_item'] ?? '' ),
		);
		if ( '' === $out['qid'] || ! preg_match( '/^Q\d+$/', $out['qid'] ) ) {
			$out['qid'] = '';
		}
		set_transient( $cache_key, $out, self::WIKI_TTL );
		return $out;
	}
}

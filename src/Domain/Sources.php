<?php
/**
 * Source URL helpers.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Sources {

	/** Two-part public suffixes treated as one domain label. */
	private const MULTI_PART_SUFFIXES = array(
		'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'net.uk',
		'com.au', 'net.au', 'org.au', 'co.nz', 'org.nz', 'net.nz',
		'co.za', 'org.za', 'com.br', 'com.mx', 'co.jp', 'or.jp', 'ne.jp',
		'co.in', 'com.tr', 'com.cn', 'com.sg', 'co.il',
	);

	private function __construct() {}

	/**
	 * The registrable domain of a URL ("www.theguardian.com" →
	 * "theguardian.com", "bbc.co.uk" stays whole). Empty string when the
	 * URL carries no usable host.
	 */
	public static function registrable_domain( string $url ): string {
		$host = function_exists( 'wp_parse_url' )
			? (string) wp_parse_url( $url, PHP_URL_HOST )
			: (string) ( parse_url( $url, PHP_URL_HOST ) ?: '' );
		$host = mb_strtolower( preg_replace( '/^www\./i', '', $host ) ?? $host );
		if ( '' === $host || ! str_contains( $host, '.' ) ) {
			return '';
		}
		$parts = explode( '.', $host );
		$n     = count( $parts );
		$last2 = strtolower( $parts[ $n - 2 ] . '.' . $parts[ $n - 1 ] );
		if ( $n >= 3 && in_array( $last2, self::MULTI_PART_SUFFIXES, true ) ) {
			return strtolower( $parts[ $n - 3 ] . '.' . $last2 );
		}
		return $last2;
	}
}

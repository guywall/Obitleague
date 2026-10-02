<?php
/**
 * Small pure date helpers shared by the death wire.
 *
 * Wikidata dates arrive as ISO timestamps whose precision is encoded in the
 * values (2026-00-00, 2026-09-00, 2026-09-14); the list pipeline walks one
 * month at a time. Both are plain arithmetic with no WordPress dependency.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Dates {

	private function __construct() {}

	/** '2026-09-14T00:00:00Z' → '2026-09-14'; precision placeholders drop. */
	public static function normalize_day( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-00-00T/', $iso, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-00T/', $iso, $m ) ) {
			return $m[1] . '-' . $m[2];
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})T/', $iso, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** The month after 'YYYY-MM'. */
	public static function next_month( string $month ): string {
		[ $y, $m ] = array_map( 'intval', explode( '-', $month ) );
		return $m === 12 ? ( ( $y + 1 ) . '-01' ) : sprintf( '%04d-%02d', $y, $m + 1 );
	}
}

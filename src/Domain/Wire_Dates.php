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

	/**
	 * Every month of one season that can already hold a death: January up to
	 * and including the month containing $now, ascending. A season that has
	 * not begun has no months; a past season has all twelve.
	 *
	 * The whole season is walked, not just the last few months, so a wire
	 * first run mid-year still backfills the deaths reported earlier in the
	 * same year. Months after $now are omitted because they cannot have a
	 * death yet.
	 *
	 * @param int $year Season year, e.g. 2026.
	 * @param int $now  Unix timestamp to measure "now" against.
	 * @return array<int, string> 'YYYY-MM' in ascending order.
	 */
	public static function season_months( int $year, int $now ): array {
		$current_year = (int) gmdate( 'Y', $now );
		if ( $year > $current_year ) {
			return array();
		}
		$last = $year < $current_year ? 12 : (int) gmdate( 'n', $now );
		$months = array();
		for ( $m = 1; $m <= $last; ++$m ) {
			$months[] = sprintf( '%04d-%02d', $year, $m );
		}
		return $months;
	}

	/**
	 * True when the whole of 'YYYY-MM' lies before $today ('YYYY-MM-DD'): the
	 * month has ended, so its list section cannot grow again. The month in
	 * play is deliberately not "elapsed" — it is re-walked so deaths reported
	 * later in the month are still picked up.
	 */
	public static function month_elapsed( string $month, string $today ): bool {
		return self::next_month( $month ) . '-01' <= $today;
	}
}

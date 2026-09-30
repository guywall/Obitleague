<?php
/**
 * Obituary article parsing.
 *
 * Pure extraction of the two facts an obituary article carries that a wire
 * headline usually does not: the deceased's full name and the exact date of
 * death. Dedicated obituary desks sign their articles — "Jane Smith, actor,
 * died on 12 September 2026" — and those two facts are enough to open a
 * person record pending corroboration.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Obituary_Article {

	private function __construct() {}

	/**
	 * Extract name and exact death date from article text.
	 *
	 * @param string $text Visible article text (tags stripped by the caller).
	 * @return array{name:string, death_date:string} Empty strings when absent.
	 */
	public static function parse( string $text ): array {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		if ( '' === $text ) {
			return array( 'name' => '', 'death_date' => '' );
		}

		$name = self::name_from_signature( $text );
		$date = self::death_date( $text );

		return array(
			'name'       => $name,
			'death_date' => $date,
		);
	}

	/**
	 * "X, role, died on 12 September 2026" — the classic obituary signature.
	 * Also matches "…, born 22 March 1929; died 14 August 2026" and the
	 * American order "X, actress, died September 12, 2026". The last match
	 * in the text wins: obituary desks sign at the foot of the piece, and
	 * earlier mentions tend to carry lead-ins ("The Japanese artist …").
	 */
	private static function name_from_signature( string $text ): string {
		if ( ! preg_match_all( '/(?:^|[.;!?]\s+)([A-Z][\p{L}\'.-]+(?:\s+[A-Z][\p{L}\'.-]+){1,4})[^.]{0,160}?\bdied\s+(?:on\s+)?(?:\d|[A-Z][a-z]{2,8}\.?\s+\d)/mu', $text, $matches ) ) {
			return '';
		}
		$candidate = trim( (string) end( $matches[1] ) );
		// Reject garbled captures.
		if ( preg_match( '/\d/', $candidate ) || mb_strlen( $candidate ) < 6 || mb_strlen( $candidate ) > 60 ) {
			return '';
		}
		return $candidate;
	}

	/**
	 * The exact date of death: "died on 12 September 2026", "died September
	 * 12, 2026", "died on 12 Sep 2026". Returns Y-m-d, or '' when absent,
	 * day-less, or implausible.
	 */
	private static function death_date( string $text ): string {
		if ( ! preg_match( '/\bdied(?:\s+on|\s+in)?\s+(\d{1,2}(?:st|nd|rd|th)?\s+)?((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?,?\s*)?(\d{1,2})(?:st|nd|rd|th)?\s+(?:of\s+)?([A-Z][a-z]+)\.?,?\s+(\d{4})/u', $text, $m )
			&& ! preg_match( '/\bdied(?:\s+on|\s+in)?\s+([A-Z][a-z]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/u', $text, $m ) ) {
			return '';
		}

		// Two shapes: D-M-Y captures [_, day?, month-word?, day, month, year]
		// or M-D-Y captures [_, month, day, year].
		if ( count( $m ) >= 6 && '' !== (string) $m[4] && preg_match( '/^[A-Z]/', (string) $m[4] ) ) {
			$month = (string) $m[4];
			$day   = (string) $m[3];
			$year  = (string) $m[5];
		} else {
			$month = (string) ( $m[1] ?? '' );
			$day   = (string) ( $m[2] ?? '' );
			$year  = (string) ( $m[3] ?? '' );
		}

		$parsed = date_parse( trim( "$day $month $year" ) );
		if ( ! $parsed || 0 !== (int) $parsed['error_count'] || (int) $parsed['month'] < 1 || (int) $parsed['day'] < 1 ) {
			return '';
		}
		$y = (int) $parsed['year'];
		// Plausibility: a death notice is about a real, recent human life.
		if ( $y < 1900 || $y > (int) gmdate( 'Y' ) + 1 ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $y, (int) $parsed['month'], (int) $parsed['day'] );
	}
}

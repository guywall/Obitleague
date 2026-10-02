<?php
/**
 * Reading a death, a birth year and an exact death date out of wikitext.
 *
 * The wire's identity proof and import both come from a person's Wikipedia
 * article, so these three extractions decide who is created and with what
 * dates. They read the infobox fields first and fall back to prose wording;
 * they are pure string work with no WordPress or HTTP dependency.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Wikitext {

	private function __construct() {}

	/**
	 * Does the wikitext record a death in the given year? Reads the
	 * death_date infobox field first, then plain-text death wording.
	 */
	public static function death_year( string $wikitext, int $year ): bool {
		if ( '' === $wikitext ) {
			return false;
		}
		if ( preg_match( '/\|\s*death_date\s*=\s*([^\n|]*)/i', $wikitext, $m ) ) {
			if ( preg_match( '/(^|[|\s])' . $year . '(?![0-9])/', $m[1] ) ) {
				return true;
			}
		}
		if ( preg_match( '/(?:died|death|passed away)[^.\n]{0,120}\b' . $year . '\b/i', $wikitext ) ) {
			return true;
		}
		return false;
	}

	/** First birth year found in the article text ('' when absent). */
	public static function birth_year( string $wikitext ): string {
		// Most modern infoboxes use the birth-date template, whose value is
		// pipe-separated ({{birth date|1932|12|12}}) and so is destroyed by a
		// naive "up to the next pipe" capture.
		if ( preg_match( '/\|\s*birth_date\s*=\s*\{\{\s*(?:birth date(?: and age)?|bda)\s*\|\s*(\d{4})/i', $wikitext, $template ) ) {
			return $template[1];
		}
		if ( preg_match( '/\|\s*birth_date\s*=\s*([^\n|]*)/i', $wikitext, $m ) && preg_match( '/\b(1[89]\d{2}|20[0-2]\d)\b/', $m[1], $y ) ) {
			return $y[1];
		}
		if ( preg_match( '/\(born[^)]*?\b(1[89]\d{2}|20[0-2]\d)\b/i', $wikitext, $m2 ) ) {
			return $m2[1];
		}
		return '';
	}

	/** Best exact death date for the season year from the article ('' when absent). */
	public static function death_date( string $wikitext, int $year ): string {
		if ( preg_match( '/\|\s*death_date\s*=\s*\{\{[^|}]*\|(' . $year . ')\|(\d{1,2})\|(\d{1,2})/i', $wikitext, $m ) ) {
			return sprintf( '%04d-%02d-%02d', $year, min( 12, max( 1, (int) $m[2] ) ), min( 31, max( 1, (int) $m[3] ) ) );
		}
		$months = 'January|February|March|April|May|June|July|August|September|October|November|December';
		if ( preg_match( '/\b(\d{1,2})\s+(' . $months . ')\s+' . $year . '\b/i', $wikitext, $m2 ) ) {
			$month = (int) ( array_search( ucfirst( strtolower( $m2[2] ) ), explode( '|', $months ), true ) + 1 );
			return sprintf( '%04d-%02d-%02d', $year, $month, min( 31, max( 1, (int) $m2[1] ) ) );
		}
		return (string) $year;
	}
}

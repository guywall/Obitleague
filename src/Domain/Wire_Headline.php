<?php
/**
 * The subject of a death-wire headline.
 *
 * A wire story is only usable if it names a person. Headlines wrap that name
 * in desk furniture ("X obituary"), section tails, quoted descriptors and
 * general-news prose, so the subject must be extracted carefully: a sentence
 * fragment handed to a fuzzy Wikipedia search anchors to an unrelated article
 * and manufactures a wrong person. This class is pure string work — no
 * WordPress, no HTTP, no database.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Headline {

	private function __construct() {}

	/**
	 * The subject group of a headline: the name before the dash/colon, with
	 * tail pieces (publications, sections, ellipses) stripped.
	 */
	public static function group( string $title ): ?string {
		$t = trim( preg_replace( '/\s+/u', ' ', $title ) ?? $title );
		$t = preg_replace( '/\s*[|·]\s*.*/u', '', $t ) ?? $t;
		if ( preg_match( '/^(.{3,191}?)\s+[—–-]\s+\S/u', $t, $m ) ) {
			$t = trim( $m[1] );
		} elseif ( preg_match( '/^(.{3,191}?):\s+\S/u', $t, $m ) ) {
			$t = trim( $m[1] );
		}
		$t = preg_replace( '/\s*\.{3,}$/u', '', $t ) ?? $t;
		// Obituary-desk suffixes: "Mighty Sparrow obituary" names Mighty
		// Sparrow, not a person called "Mighty Sparrow obituary".
		$t = preg_replace( '/\s+\b(obituary|obit|tribute|appreciation)\b\s*:?.*$/iu', '', $t ) ?? $t;
		$t = trim( $t );
		if ( '' === $t || mb_strlen( $t ) > 191 ) {
			return null;
		}
		// Broad-sheet obituaries sign the subject before a comma and describe
		// them after it: "Bob Pettit, N.B.A. Great for the Hawks, Dies at 93".
		// That is a name, not prose — but only the leading segment, and only
		// when it reads as one, so descriptors ("Kris Jenner's mom, …") and
		// sentences are still refused.
		$leading = self::leading_name( $t );
		if ( null !== $leading ) {
			return $leading;
		}
		// A group is a name, not a sentence: general-news headlines
		// ("UK diesel price hits all-time high, RAC says") produce sentence
		// fragments that Wikipedia search happily mis-anchors to some
		// unrelated article. Refuse anything that reads like prose — more
		// than a handful of words, or still carrying desk furniture.
		$words = preg_split( '/\s+/u', $t ) ?: array();
		if ( count( $words ) > 7 ) {
			return null;
		}
		if ( preg_match( '/\b(says|warns|hits|review|price|ban|named|pictures|heartbroken|slams|urges|faces|amid)\b/iu', $t ) ) {
			return null;
		}
		return $t;
	}

	/**
	 * The subject of a "Name, descriptor, dies at NN" headline: the segment
	 * before the first comma, when — and only when — it reads as a personal
	 * name (two to four capitalised tokens, no digits, no lowercase words).
	 */
	private static function leading_name( string $title ): ?string {
		$comma = mb_strpos( $title, ',' );
		if ( false === $comma ) {
			return null;
		}
		$head = trim( mb_substr( $title, 0, $comma ) );
		if ( '' === $head || mb_strlen( $head ) > 191 || preg_match( '/\d/u', $head ) ) {
			return null;
		}
		$tokens = preg_split( '/\s+/u', $head ) ?: array();
		if ( count( $tokens ) < 2 || count( $tokens ) > 4 ) {
			return null;
		}
		foreach ( $tokens as $token ) {
			// A name part starts with a capital and carries only name
			// characters: initials ("G."), apostrophes ("O’Neill") and
			// hyphens ("Ruth-Bader") included.
			if ( ! preg_match( '/^\p{Lu}[\p{L}\'’.\-]*$/u', $token ) ) {
				return null;
			}
		}
		return $head;
	}
}

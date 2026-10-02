<?php
/**
 * Does a wire story's name group actually refer to a given Wikipedia article?
 *
 * `Wikimedia_Client::search_title()` returns the first hit of a fuzzy search,
 * which is frequently a different person entirely ("Cal Swann obituary" → "Rich
 * Swann", "David Willey obituary" → "Willey Reveley"). Importing on that
 * signal manufactures wrong people, so identity is proved before anything
 * is created: every significant token of the story's name must appear in
 * the article title. A mismatch is the safe outcome — the story is parked,
 * not turned into a record.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Identity {

	/** Words that decorate a name but never identify the person. */
	private const HONORIFICS = array(
		'dame', 'sir', 'lady', 'lord', 'dr', 'mr', 'mrs', 'ms', 'miss',
		'prof', 'professor', 'the', 'a', 'an',
	);

	/** Obituary-desk furniture that can survive on an older headline. */
	private const DESK_WORDS = array( 'obituary', 'obit', 'tribute', 'appreciation' );

	private function __construct() {}

	/** True when every significant token of the group appears in the title. */
	public static function matches( string $group, string $article_title ): bool {
		$group_tokens   = self::tokens( $group );
		$article_tokens = self::tokens( $article_title );
		if ( array() === $group_tokens || array() === $article_tokens ) {
			return false;
		}
		foreach ( $group_tokens as $token ) {
			if ( ! in_array( $token, $article_tokens, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Significant tokens of a name or article title: lowercased, with the
	 * Wikipedia disambiguator, honourifics and punctuation removed.
	 *
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		// Drop a Wikipedia disambiguator and any other parenthetical aside.
		$text = preg_replace( '/\s*\([^)]*\)/u', ' ', $text ) ?? $text;
		$text = str_replace( '_', ' ', $text );
		// Unify curly/backtick apostrophes so O’Neill and O'Neill agree.
		$text = str_replace( array( '’', '‘', '`' ), "'", $text );
		$text = preg_replace( "/[^\p{L}\p{N}\s'.-]/u", ' ', $text ) ?? $text;
		$parts = preg_split( '/[\s\-.]+/u', $text ) ?: array();
		$out   = array();
		foreach ( $parts as $part ) {
			$part = mb_strtolower( trim( $part ) );
			if ( '' === $part
				|| in_array( $part, self::HONORIFICS, true )
				|| in_array( $part, self::DESK_WORDS, true )
			) {
				continue;
			}
			$out[] = $part;
		}
		return array_values( array_unique( $out ) );
	}
}

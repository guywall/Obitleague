<?php
/**
 * Wordclouds and text-similarity matching.
 *
 * Pure domain: no WordPress calls, so it runs in the offline test suite.
 *
 * Two jobs on the death wire:
 *
 * 1. Build a word-frequency cloud from a story (title + excerpt + article
 *    text) so editors can see at a glance what a piece is about.
 * 2. Compare a story's cloud against each same-name person record's stored
 *    Wikipedia-article cloud, so "John Smith dies aged 80" attaches to the
 *    right John Smith when the site holds two of them.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wordcloud {

	private function __construct() {}

	/** Words shorter than this are noise. */
	public const MIN_TOKEN_LEN = 3;

	/** Similarity at or above which a candidate wins an ambiguous match. */
	public const MATCH_FLOOR = 0.08;

	/** The shared stopword list (lowercase). */
	private const STOPWORDS = array(
		'the' => true, 'a' => true, 'an' => true, 'and' => true, 'or' => true, 'of' => true,
		'in' => true, 'on' => true, 'at' => true, 'to' => true, 'for' => true, 'with' => true,
		'from' => true, 'by' => true, 'as' => true, 'is' => true, 'are' => true, 'was' => true,
		'were' => true, 'be' => true, 'been' => true, 'his' => true, 'her' => true, 'their' => true,
		'its' => true, 'who' => true, 'after' => true, 'before' => true, 'over' => true,
		'into' => true, 'up' => true, 'out' => true, 'off' => true, 'down' => true, 'how' => true,
		'why' => true, 'what' => true, 'new' => true, 'says' => true, 'said' => true,
		'amid' => true, 'news' => true, 'video' => true, 'live' => true, 'latest' => true,
		'it' => true, 'he' => true, 'she' => true, 'they' => true, 'we' => true, 'you' => true,
		'i' => true, 'not' => true, 'no' => true, 'but' => true, 'if' => true, 'so' => true,
		'that' => true, 'this' => true, 'will' => true, 'would' => true, 'can' => true,
		'could' => true, 'has' => true, 'have' => true, 'had' => true, 'do' => true,
		'does' => true, 'did' => true, 'than' => true, 'then' => true, 'more' => true,
		'most' => true, 'about' => true, 'against' => true, 'during' => true, 'between' => true,
		'through' => true, 'year' => true, 'years' => true, 'first' => true, 'among' => true,
		'being' => true, 'under' => true, 'while' => true, 'just' => true, 'also' => true,
		'get' => true, 'one' => true, 'two' => true, 'three' => true, 'us' => true, 'uk' => true,
		'aged' => true, 'dies' => true, 'died' => true, 'death' => true, 'obituary' => true,
		'tributes' => true, 'paid' => true,
	);

	/** True when the word carries no disambiguating signal. */
	public static function stopword( string $word ): bool {
		return isset( self::STOPWORDS[ $word ] );
	}

	/**
	 * Word-frequency cloud of one text. Zero-count entries are never
	 * produced; a cap of 0 keeps every word (the caller slices).
	 *
	 * @return array<string,int> word => count, most frequent first.
	 */
	public static function from_text( string $text, int $cap = 40 ): array {
		$counts = array();
		foreach ( preg_split( "/[^a-z0-9']+/i", mb_strtolower( $text ) ) ?: array() as $word ) {
			$word = trim( $word, "'" );
			if ( mb_strlen( $word ) < self::MIN_TOKEN_LEN || self::stopword( $word ) || is_numeric( $word ) ) {
				continue;
			}
			$counts[ $word ] = ( $counts[ $word ] ?? 0 ) + 1;
		}
		arsort( $counts );
		return $cap > 0 ? array_slice( $counts, 0, $cap, true ) : $counts;
	}

	/**
	 * Reduce raw Wikipedia wikitext to readable words: references, templates,
	 * markup, links and headings stripped, link labels kept.
	 */
	public static function from_wikitext( string $wikitext, int $cap = 40 ): array {
		return self::from_text( self::wikitext_plain( $wikitext ), $cap );
	}

	/** Wikitext reduced to plain readable text (the cloud's input). */
	public static function wikitext_plain( string $wikitext ): string {
		if ( '' === $wikitext ) {
			return '';
		}
		$t = preg_replace( '/<ref\b[^>]*\/>/i', ' ', $wikitext ) ?? $wikitext;
		$t = preg_replace( '/<ref\b[^>]*>.*?<\/ref>/is', ' ', $t ) ?? $t;
		// Innermost templates first; nesting needs a few passes.
		for ( $i = 0; $i < 10 && str_contains( $t, '{{' ); ++$i ) {
			$t = (string) preg_replace( '/\{\{[^{}]*\}\}/', ' ', $t );
		}
		// Wikilinks keep their label: [[label|display]] → display.
		$t = preg_replace( '/\[\[(?:[^\[\]|]*\|)?([^\[\]|]*)\]\]/u', '$1', $t ) ?? $t;
		// External links and bare URLs.
		$t = preg_replace( '/\[[a-z]+:\/\/[^\]\s]+\s*[^\]]*\]/i', ' ', $t ) ?? $t;
		$t = preg_replace( '/[a-z]+:\/\/\S+/i', ' ', $t ) ?? $t;
		// Tables, gallery blocks, comments, headings, bold/italic.
		$t = preg_replace( '/<!--.*?-->/s', ' ', $t ) ?? $t;
		$t = preg_replace( '/^\{\|.*$/m', ' ', $t ) ?? $t;
		$t = preg_replace( '/^=+\s*.*?\s*=+$/m', ' ', $t ) ?? $t;
		$t = str_replace( array( "'''", "''" ), '', $t );
		return $t;
	}

	/**
	 * Cosine similarity between two count vectors, 0.0 (disjoint) to
	 * 1.0 (identical). Vectors are word => count maps; either may be empty.
	 */
	public static function similarity( array $a, array $b ): float {
		if ( array() === $a || array() === $b ) {
			return 0.0;
		}
		$dot = 0.0;
		foreach ( $a as $word => $count ) {
			if ( isset( $b[ $word ] ) ) {
				$dot += (float) $count * (float) $b[ $word ];
			}
		}
		if ( 0.0 === $dot ) {
			return 0.0;
		}
		$na = sqrt( array_sum( array_map( static fn ( $c ): float => (float) $c * (float) $c, $a ) ) );
		$nb = sqrt( array_sum( array_map( static fn ( $c ): float => (float) $c * (float) $c, $b ) ) );
		if ( 0.0 === $na || 0.0 === $nb ) {
			return 0.0;
		}
		return $dot / ( $na * $nb );
	}

	/**
	 * The candidate whose cloud best matches the story's, or null when even
	 * the best is below the match floor — an unresolved ambiguity is the
	 * human's, not the wire's, to guess.
	 *
	 * @param array<string,int>              $story_counts The story's cloud.
	 * @param array<string, array<string,int>> $candidates  Key => that candidate's cloud.
	 */
	public static function best_match( array $story_counts, array $candidates, float $floor = self::MATCH_FLOOR ): ?string {
		$best_key   = null;
		$best_score = $floor;
		foreach ( $candidates as $key => $counts ) {
			if ( ! is_array( $counts ) || array() === $counts ) {
				continue;
			}
			$score = self::similarity( $story_counts, $counts );
			if ( $score >= $best_score ) {
				$best_score = $score;
				$best_key   = (string) $key;
			}
		}
		return $best_key;
	}
}

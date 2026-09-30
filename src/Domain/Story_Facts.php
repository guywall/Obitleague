<?php
/**
 * Story fact extraction for the death-wire story modal.
 *
 * Pure domain: no WordPress calls, so it runs in the offline test suite.
 *
 * Given a story's title and the visible text of its article, this pulls
 * out everything an editor (or the wordcloud) needs to understand who the
 * story is about without reading the whole piece:
 *
 * - cloud:    word-frequency cloud of the article body (the modal's wordcloud)
 * - phrases:  recurring two- and three-word phrases (common phrasing)
 * - birth:    date of birth, exact Y-m-d or bare year when that is all the
 *             text supports — never invented
 * - death:    date of death, Y-m-d
 * - age:      stated age ("aged 80"), or computed from exact birth+death
 * - cause:    stated or implied cause of death, when the text gives one
 * - bio:      the lead biography sentence(s), a one-glance summary
 *
 * Everything absent in the text comes back as an empty string — the
 * extractor never guesses.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Story_Facts {

	/** Months recognised in date expressions (lowercase). */
	private const MONTHS = array(
		'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
		'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
		'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
	);

	private function __construct() {}

	/**
	 * Extract the story's facts.
	 *
	 * @param string $title The story headline.
	 * @param string $text  Visible article text (tags already stripped).
	 * @return array{cloud:array<string,int>, phrases:array<string,int>, birth:string, death:string, age:string, cause:string, bio:string}
	 */
	public static function parse( string $title, string $text ): array {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		return array(
			'cloud'    => Wordcloud::from_text( $title . ' ' . $text, 30 ),
			'phrases'  => self::phrases( $text ),
			'birth'    => self::birth_date( $text ),
			'death'    => self::death_date( $text ),
			'age'      => self::age( $text ),
			'cause'    => self::cause( $text ),
			'bio'      => self::bio( $title, $text ),
		);
	}

	/**
	 * Recurring phrases: bigrams and trigrams of content words that appear
	 * more than once, most frequent first, capped at eight. These are the
	 * story's stock phrases — useful for spotting boilerplate and for the
	 * same-name disambiguation glance.
	 *
	 * @return array<string,int>
	 */
	public static function phrases( string $text, int $cap = 8 ): array {
		$tokens = array();
		foreach ( preg_split( "/[^a-z0-9']+/i", mb_strtolower( $text ) ) ?: array() as $word ) {
			$word = trim( $word, "'" );
			if ( mb_strlen( $word ) < Wordcloud::MIN_TOKEN_LEN || Wordcloud::stopword( $word ) || is_numeric( $word ) ) {
				continue;
			}
			$tokens[] = $word;
		}
		$n = count( $tokens );
		if ( $n < 2 ) {
			return array();
		}
		$counts = array();
		for ( $i = 0; $i < $n - 1; ++$i ) {
			$bigram = $tokens[ $i ] . ' ' . $tokens[ $i + 1 ];
			++$counts[ $bigram ];
			if ( $i < $n - 2 ) {
				$trigram = $bigram . ' ' . $tokens[ $i + 2 ];
				++$counts[ $trigram ];
			}
		}
		// Only phrases that actually recur say something about the piece.
		$counts = array_filter( $counts, static fn ( int $c ): bool => $c >= 2 );
		arsort( $counts );
		return array_slice( $counts, 0, $cap, true );
	}

	/**
	 * Date of birth from the text, or '' when absent. Exact day-month-year
	 * forms win; a bare year or a lifespan parenthetical "(1945–2026)" is
	 * kept at year precision rather than guessed up.
	 */
	public static function birth_date( string $text ): string {
		if ( preg_match( '/\bborn\s+(?:on\s+)?(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\.?,?\s+(\d{4})/i', $text, $m ) ) {
			return self::to_ymd( $m[1], $m[2], $m[3] );
		}
		if ( preg_match( '/\bborn\s+([A-Za-z]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/i', $text, $m ) ) {
			return self::to_ymd( $m[2], $m[1], $m[3] );
		}
		// Lifespan parenthetical, day-first: "(12 March 1945 – 2 September 2026)".
		if ( preg_match( '/\((\d{1,2})\s+([A-Za-z]+)\.?,?\s+(\d{4})\s*[–—-]/u', $text, $m ) ) {
			return self::to_ymd( $m[1], $m[2], $m[3] );
		}
		// Lifespan parenthetical, US order: "(March 12, 1945 – September 2, 2026)".
		if ( preg_match( '/\(([A-Za-z]+)\.?\s+(\d{1,2}),?\s+((?:1[89]|20)\d{2})\s*[–—-]/u', $text, $m ) ) {
			return self::to_ymd( $m[2], $m[1], $m[3] );
		}
		// Bare-year forms: "born in 1945", "(1945 – 2026)".
		if ( preg_match( '/\bborn\s+(?:in\s+)?((?:1[89]|20)\d{2})\b/i', $text, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/\(((?:1[89]|20)\d{2})\s*[–—-]/u', $text, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Date of death from the text, or '' when absent. Covers "died on 12
	 * September 2026", "passed away September 12, 2026" and the lifespan
	 * parenthetical's second half.
	 */
	public static function death_date( string $text ): string {
		if ( preg_match( '/\b(?:died|passed away|death occurred)\s+(?:on\s+)?(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\.?,?\s+(\d{4})/i', $text, $m ) ) {
			return self::to_ymd( $m[1], $m[2], $m[3] );
		}
		if ( preg_match( '/\b(?:died|passed away)\s+(?:on\s+)?([A-Za-z]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/i', $text, $m ) ) {
			return self::to_ymd( $m[2], $m[1], $m[3] );
		}
		// Second half of a lifespan parenthetical: "(… – 2 September 2026)".
		if ( preg_match( '/[–—-]\s*(\d{1,2})\s+([A-Za-z]+)\.?,?\s+((?:19|20)\d{2})\s*\)/u', $text, $m ) ) {
			return self::to_ymd( $m[1], $m[2], $m[3] );
		}
		return '';
	}

	/**
	 * The stated age ("aged 80", "at the age of 80"), or the age computed
	 * from an exact birth and death date. '' when the text supports neither.
	 */
	public static function age( string $text, string $birth = '', string $death = '' ): string {
		if ( preg_match( '/\baged\s+(\d{1,3})\b/i', $text, $m ) || preg_match( '/\bat the age of (\d{1,3})\b/i', $text, $m ) ) {
			$stated = (int) $m[1];
			if ( $stated >= 1 && $stated <= 120 ) {
				return (string) $stated;
			}
		}
		$birth = '' !== $birth ? $birth : self::birth_date( $text );
		$death = '' !== $death ? $death : self::death_date( $text );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birth ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $death ) ) {
			$age = (int) substr( $death, 0, 4 ) - (int) substr( $birth, 0, 4 );
			if ( substr( $death, 5 ) < substr( $birth, 5 ) ) {
				--$age; // Birthday not yet reached in the death year.
			}
			if ( $age >= 1 && $age <= 120 ) {
				return (string) $age;
			}
		}
		return '';
	}

	/**
	 * The stated or implied cause of death, or '' when none is given.
	 * Captures "died of/from/following/after …" and "death was caused by …",
	 * trimmed to a short clause.
	 */
	public static function cause( string $text ): string {
		if ( preg_match( '/\bdeath\s+(?:was|has been)\s+(?:caused by|due to|attributed to)\s+([^.;]{3,80})/i', $text, $m )
			|| preg_match( '/\bdied\b[^.;]{0,40}?\b(?:of|from|following|after)\s+(?:a|an|the)?\s*([^.;]{3,80})/i', $text, $m ) ) {
			$cause = trim( (string) $m[1] );
			// Stop at clause joins: "died of sepsis and a fall" keeps "sepsis".
			$cause = (string) preg_split( '/\b(?:and|while|following|after|aged|in|at)\b/i', $cause )[0];
			$cause = trim( (string) preg_replace( '/\s+/u', ' ', $cause ), " \t\n\r-,;" );
			if ( mb_strlen( $cause ) >= 3 ) {
				return mb_strtolower( $cause );
			}
		}
		return '';
	}

	/**
	 * The lead biography: the first sentence or two that introduce the
	 * subject. Prefers a sentence echoing the headline's name words; falls
	 * back to the opening sentence. Capped around 320 characters.
	 */
	public static function bio( string $title, string $text, int $cap = 320 ): string {
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: array();
		if ( array() === $sentences ) {
			return '';
		}
		// Content words of the headline, for preferring subject sentences.
		$name_words = array();
		foreach ( preg_split( "/[^a-z0-9']+/i", mb_strtolower( $title ) ) ?: array() as $word ) {
			if ( mb_strlen( $word ) >= 3 && ! Wordcloud::stopword( $word ) ) {
				$name_words[] = $word;
			}
		}
		$picked = array();
		$length = 0;
		foreach ( $sentences as $sentence ) {
			$sentence = trim( (string) $sentence );
			if ( mb_strlen( $sentence ) < 25 || mb_strlen( $sentence ) > 600 ) {
				continue; // Headers, captions, photo credits, boilerplate.
			}
			$lower = mb_strtolower( $sentence );
			$hits  = 0;
			foreach ( $name_words as $word ) {
				if ( str_contains( $lower, $word ) ) {
					++$hits;
				}
			}
			// First sentence must look like an introduction; a second is
			// only appended while it stays short.
			$picked[] = $sentence;
			$length  += mb_strlen( $sentence );
			if ( count( $picked ) >= 2 || $length > $cap * 0.8 ) {
				break;
			}
		}
		$bio = implode( ' ', $picked );
		return mb_strimwidth( $bio, 0, $cap, $bio === '' ? '' : '…' );
	}

	/** '12 March 1945' pieces → '1945-03-12', or '' when not a real date. */
	private static function to_ymd( string $day, string $month, string $year ): string {
		$month_num = self::MONTHS[ mb_strtolower( trim( $month, '. ' ) ) ] ?? 0;
		$d         = (int) $day;
		$y         = (int) $year;
		if ( $month_num < 1 || $d < 1 || $d > 31 || $y < 1800 || $y > (int) gmdate( 'Y' ) + 1 ) {
			return '';
		}
		$parsed = date_parse( sprintf( '%04d-%02d-%02d', $y, $month_num, $d ) );
		if ( ! $parsed || 0 !== (int) $parsed['error_count'] ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $y, $month_num, $d );
	}
}

<?php
/**
 * Weighted death-detection classifier for RSS stories.
 *
 * Pure heuristic stage of the wire pipeline. It decides whether a feed item
 * announces a *new death of a notable person* — never whether a specific
 * person died; that confirmation belongs to the wire's person match and the
 * editorial review. The classifier is death-first (headlines such as
 * "dies aged 84" or "has died"), not merely obituary-shaped, and it exists
 * to keep hoaxes, anniversaries, fiction and funeral follow-ups out of the
 * editor's queue.
 *
 * Scores run 0–95:
 *   70+             → DEATH_ANNOUNCEMENT (strong death candidate)
 *   50–69           → DEATH_FOLLOWUP     (requires further confirmation)
 *   50–69 + genre   → OBITUARY           (obituary desk content)
 *   below 50        → NOT_DEATH          (no death candidate is created)
 *
 * Every phrase, weight, bonus and exclusion lives in the const tables below
 * so the classifier can be retuned without touching the logic.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Feed_Classifier {

	/* ---- Verdicts ------------------------------------------------------ */

	public const DEATH_ANNOUNCEMENT = 'death_announcement';
	public const OBITUARY           = 'obituary';
	public const DEATH_FOLLOWUP     = 'death_followup';
	public const NOT_DEATH          = 'not_death';

	/** Legacy values written by the first-generation cue counter. Still read. */
	public const CANDIDATE     = 'candidate';
	public const NOT_CANDIDATE = 'not_candidate';

	/** Classifications that mark a story worth the death wire's attention. */
	public const DEATH_SIGNALS = array(
		self::DEATH_ANNOUNCEMENT,
		self::OBITUARY,
		self::DEATH_FOLLOWUP,
		self::CANDIDATE, // legacy rows
	);

	/* ---- Thresholds ---------------------------------------------------- */

	public const THRESHOLD_STRONG = 70;
	public const THRESHOLD_REVIEW = 50;
	public const SCORE_CAP        = 95;

	/* ---- Weighted death phrases ---------------------------------------- */
	/* [title weight, body weight]. Longest phrase wins: matches are masked
	   before shorter phrases run, so "dies aged 84" never also scores "dies".
	   Title wording is worth far more than wording buried in the summary.  */

	private const STRONG_PHRASES = array(
		'dies aged'                => array( 55, 33 ),
		'died aged'                => array( 55, 33 ),
		'dead aged'                => array( 55, 33 ),
		'dies at'                  => array( 50, 30 ),
		'died at'                  => array( 50, 30 ),
		'dead at'                  => array( 50, 30 ),
		'has died'                 => array( 60, 36 ),
		'have died'                => array( 55, 33 ),
		'passes away'              => array( 60, 36 ),
		'passed away'              => array( 60, 36 ),
		'passing away'             => array( 50, 30 ),
		'is dead'                  => array( 60, 36 ),
		'death has been announced' => array( 60, 36 ),
		'death confirmed'          => array( 60, 36 ),
		'death announced'          => array( 55, 33 ),
		'was killed'               => array( 55, 33 ),
		'dies'                     => array( 55, 15 ),
		'died'                     => array( 55, 15 ),
		'killed'                   => array( 40, 12 ),
		'obituary'                 => array( 50, 30 ),
		'death of'                 => array( 35, 20 ),
		'in memoriam'              => array( 30, 18 ),
		'mourn the death'          => array( 35, 20 ),
		'mourns'                   => array( 30, 18 ),
		'tributes pour'            => array( 25, 15 ),
		'pays tribute'             => array( 25, 15 ),
	);

	/** Bonus when a death phrase carries the subject's age. */
	private const AGE_PATTERN_TITLE_BONUS  = 25;
	private const AGE_PATTERN_BODY_BONUS   = 15;
	private const AGE_PATTERN              = '/\b(?:dies|died|dead|killed|passes?\s+away|passing\s+away)\s+(?:aged|at)\s+(\d{1,3})\b/i';

	/** Bonus when the death is attributed to family/agent/representative. */
	private const CONFIRMATION_PHRASES     = array(
		'family announced', 'family announces', 'family confirms', 'family say', 'family says',
		'confirmed by his family', 'confirmed by her family', 'confirmed by their family',
		'announced by his family', 'announced by her family', 'announced by their family',
		'agent confirms', 'agent announces', 'manager confirms', 'publicist confirms',
		'representative confirms', 'representative announces', 'spokesperson confirms',
		'spokesperson announces', 'spokesman said', 'spokeswoman said', 'spokesperson said',
		'family said in a statement', 'confirmed in a statement',
	);
	private const CONFIRMATION_BONUS       = 10;

	/** Bonus per additional distinct death phrase beyond the first (capped). */
	private const MULTI_PHRASE_BONUS       = 8;
	private const MULTI_PHRASE_BONUS_CAP   = 16;

	/* ---- Hard exclusions ----------------------------------------------- */
	/* Any of these forces NOT_DEATH: the wording is present but the story is
	   explicitly not announcing a new death.                                */

	private const HARD_EXCLUSIONS = array(
		// Hoaxes and denials.
		'death hoax', 'hoax', 'falsely reported', 'mistakenly reported', 'wrongly reported',
		'false reports of', 'death rumours', 'death rumors',
		'rumours of his death', 'rumors of his death', 'rumours of her death', 'rumors of her death',
		'rumours of their death', 'rumors of their death',
		'not dead', 'still alive', 'denies dying', 'denies death', 'refutes reports',
		// Fiction.
		'character dies', 'character killed off', 'killed off', 'death scene', 'on-screen death',
		'fictional death', 'death in fiction', 'fan fiction',
		// Anniversaries and retrospectives.
		'anniversary of the death', 'anniversary of his death', 'anniversary of her death',
		'anniversary of their death', 'years since the death', 'years since his death',
		'years since her death', 'years since their death', 'on this day',
	);

	/* ---- Soft negative dampeners ---------------------------------------- */
	/* Follow-up genres that usually accompany death wording without
	   announcing a new death. [title weight, body weight], subtracted.       */

	private const SOFT_NEGATIVES = array(
		'funeral'             => array( 20, 12 ),
		'inquest'             => array( 25, 15 ),
		'cause of death'      => array( 20, 12 ),
		'pays tribute'        => array( 20, 12 ),
		'tributes pour'       => array( 15, 9 ),
		'remembers'           => array( 20, 12 ),
		'remembered'          => array( 15, 9 ),
		'looking back'        => array( 25, 15 ),
		'archive'             => array( 20, 12 ),
		'retrospective'       => array( 20, 12 ),
		'legacy of'           => array( 15, 9 ),
		'in pictures'         => array( 20, 12 ),
		'best moments'        => array( 20, 12 ),
		'life and legacy'     => array( 20, 12 ),
		'would have turned'   => array( 30, 18 ),
		'anniversary'         => array( 30, 18 ),
		'years since'         => array( 30, 18 ),
		'death certificate'   => array( 25, 15 ),
		'burial'              => array( 20, 12 ),
		'cremation'           => array( 20, 12 ),
	);

	/* ---- Obituary desk boost -------------------------------------------- */

	private const OBITUARY_SOURCE_PATTERN  = '/obituar/i';
	private const OBITUARY_FEED_BOOST      = 70;
	private const OBITUARY_CATEGORY_PATTERN = '/(obituar|deaths)/i';

	private function __construct() {}

	/**
	 * Classify one feed item.
	 *
	 * @param string $title   Item title.
	 * @param string $text    Permitted summary or first paragraphs (body).
	 * @param array  $context Optional context:
	 *   source_name => string, source_url => string, categories => string[].
	 *   A source or category that marks the item as obituary-desk content
	 *   receives a large confidence boost and the OBITUARY genre.
	 * @return array{classification:string, score:int, matched:string[], negative:string[], excluded:string[]}
	 */
	public static function classify( string $title, string $text = '', array $context = array() ): array {
		$title_lc = mb_strtolower( $title );
		$body_lc  = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) ?? '' ) );

		$excluded = self::cues_present( self::HARD_EXCLUSIONS, $title_lc . "\n" . $body_lc );
		if ( $excluded ) {
			return array(
				'classification' => self::NOT_DEATH,
				'score'          => 0,
				'matched'        => array(),
				'negative'       => $excluded,
				'excluded'       => $excluded,
			);
		}

		$score        = 0;
		$matched      = array();
		$title_phrase = false;

		// Weighted death phrases, longest first, matched spans masked so a
		// phrase never scores twice through one of its own substrings. The
		// originals are kept for the dampener pass: masking must not hide the
		// genre words (mourns, tribute…) the soft negatives look for.
		$title_lc_orig = $title_lc;
		$body_lc_orig  = $body_lc;
		$phrases = self::phrases_by_length( self::STRONG_PHRASES );
		foreach ( $phrases as $phrase ) {
			$weights = self::STRONG_PHRASES[ $phrase ];
			$in_text = false;
			if ( str_contains( $title_lc, $phrase ) ) {
				$score   += $weights[0];
				$in_text  = true;
			}
			if ( '' !== $body_lc && str_contains( $body_lc, $phrase ) ) {
				$score   += $weights[1];
				$in_text  = true;
			}
			if ( $in_text ) {
				$matched[]    = $phrase;
				$title_phrase = $title_phrase || str_contains( strtolower( $title ), $phrase );
				$title_lc     = str_replace( $phrase, str_repeat( ' ', strlen( $phrase ) ), $title_lc );
				$body_lc      = str_replace( $phrase, str_repeat( ' ', strlen( $phrase ) ), $body_lc );
			}
		}

		// Age attached to a death phrase is the strongest single signal.
		if ( preg_match( self::AGE_PATTERN, $title ) ) {
			$score += self::AGE_PATTERN_TITLE_BONUS;
		} elseif ( '' !== $body_lc && preg_match( self::AGE_PATTERN, $body_lc ) ) {
			$score += self::AGE_PATTERN_BODY_BONUS;
		}

		// Attribution to family/agent/representative raises confidence.
		$confirmation = self::cues_present(
			self::CONFIRMATION_PHRASES,
			mb_strtolower( $title . "\n" . trim( preg_replace( '/\s+/u', ' ', strip_tags( $text ) ) ?? '' ) )
		);
		if ( $confirmation ) {
			$score += self::CONFIRMATION_BONUS;
			foreach ( $confirmation as $cue ) {
				$matched[] = $cue;
			}
		}

		// Several distinct death phrases in one story corroborate each other.
		if ( count( $matched ) > 1 ) {
			$score += min( self::MULTI_PHRASE_BONUS_CAP, self::MULTI_PHRASE_BONUS * ( count( $matched ) - 1 ) );
		}

		// Soft negative dampeners: follow-up genres pull the score down. A
		// genre word in the title does NOT dampen a title that already carries
		// a death phrase — "Tributes paid as X dies aged 80" is an announce-
		// ment, not a tribute piece. Body-level dampeners always apply.
		$negative = array();
		foreach ( self::phrases_by_length( self::SOFT_NEGATIVES ) as $phrase ) {
			$weights = self::SOFT_NEGATIVES[ $phrase ];
			$hit     = false;
			if ( ! $title_phrase && str_contains( $title_lc_orig, $phrase ) ) {
				$score  -= $weights[0];
				$hit     = true;
			}
			if ( '' !== $body_lc_orig && str_contains( $body_lc_orig, $phrase ) ) {
				$score  -= $weights[1];
				$hit     = true;
			}
			if ( $hit ) {
				$negative[] = $phrase;
			}
		}

		// Dedicated obituary feeds and death categories get a large boost.
		$genre_obituary = str_contains( $title_lc, 'obituary' ) || in_array( 'obituary', $matched, true );
		if ( self::looks_like_obituary_context( $context ) ) {
			$score          += self::OBITUARY_FEED_BOOST;
			$genre_obituary  = true;
		}

		$score = (int) max( 0, min( self::SCORE_CAP, $score ) );

		return array(
			'classification' => self::verdict( $score, $genre_obituary ),
			'score'          => $score,
			'matched'        => array_values( array_unique( $matched ) ),
			'negative'       => $negative,
			'excluded'       => array(),
		);
	}

	/** True when a stored classification marks a death signal (incl. legacy). */
	public static function is_death_signal( string $classification ): bool {
		return in_array( $classification, self::DEATH_SIGNALS, true );
	}

	/** True when the feed itself looks like a dedicated obituary desk. */
	public static function looks_like_obituary_feed( string $source_name, string $source_url ): bool {
		return (bool) ( preg_match( self::OBITUARY_SOURCE_PATTERN, $source_name )
			|| preg_match( self::OBITUARY_SOURCE_PATTERN, $source_url ) );
	}

	/** Verdict for a final score. */
	private static function verdict( int $score, bool $genre_obituary ): string {
		if ( $score < self::THRESHOLD_REVIEW ) {
			return self::NOT_DEATH;
		}
		if ( $genre_obituary ) {
			return self::OBITUARY;
		}
		return $score >= self::THRESHOLD_STRONG ? self::DEATH_ANNOUNCEMENT : self::DEATH_FOLLOWUP;
	}

	/** Whether the item's context marks it as obituary-desk content. */
	private static function looks_like_obituary_context( array $context ): bool {
		$name = (string) ( $context['source_name'] ?? '' );
		$url  = (string) ( $context['source_url'] ?? '' );
		if ( self::looks_like_obituary_feed( $name, $url ) ) {
			return true;
		}
		foreach ( (array) ( $context['categories'] ?? array() ) as $category ) {
			if ( preg_match( self::OBITUARY_CATEGORY_PATTERN, (string) $category ) ) {
				return true;
			}
		}
		return false;
	}

	/** Phrase keys of a weight table, longest first (masking order). */
	private static function phrases_by_length( array $table ): array {
		$phrases = array_keys( $table );
		usort( $phrases, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		return $phrases;
	}

	/** @return string[] Cues present in the haystack. */
	private static function cues_present( array $cues, string $haystack ): array {
		return array_values(
			array_filter(
				$cues,
				static fn ( string $cue ): bool => str_contains( $haystack, $cue )
			)
		);
	}
}

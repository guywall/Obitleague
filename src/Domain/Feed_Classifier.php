<?php
/**
 * Death-report classification.
 *
 * Pure heuristic stage of the discovery pipeline. It decides whether a feed
 * item is a *candidate for editorial review* — never whether a person died.
 * Negative contexts (anniversaries, fiction, hoaxes, someone mourning
 * another person) suppress or dampen the candidate. An editor makes every
 * final decision.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Feed_Classifier {

	/** Classification outcome. */
	public const CANDIDATE    = 'candidate';
	public const NOT_CANDIDATE = 'not_candidate';

	private function __construct() {}

	/** Strong death wording: enough on its own to open a review. */
	private const DEATH_CUES = array(
		'dies',
		'died',
		'has died',
		'passes away',
		'passed away',
		'passing away',
		'death of',
		'death announced',
		'obituary',
		'in memoriam',
		'mourns the death',
		'tributes pour in',
	);

	/** Contexts that make death wording non-scoreable; each dampens the match. */
	private const NEGATIVE_CUES = array(
		'anniversary',
		'years since',
		'anniversary of the death',
		'remembers',
		'looking back',
		'archive',
		'on this day',
		'fictional',
		'in fiction',
		'character death',
		'hoax',
		'death hoax',
		'false reports',
		'still alive',
		'not dead',
		'denies',
		'rumour',
		'rumor',
	);

	/**
	 * @param string $title Item title.
	 * @param string $text  Permitted internal summary or first paragraph.
	 * @return array{classification:string, score:int, matched:string[], negative:string[]}
	 */
	public static function classify( string $title, string $text = '' ): array {
		$haystack = strtolower( $title . "\n" . $text );
		$title_lc = strtolower( $title );

		$matched  = self::cues_present( self::DEATH_CUES, $haystack );
		$negative = self::cues_present( self::NEGATIVE_CUES, $haystack );

		$score = 0;
		foreach ( $matched as $cue ) {
			// A cue in the title weighs more than one buried in body text.
			$score += str_contains( $title_lc, $cue ) ? 3 : 1;
		}
		foreach ( $negative as $cue ) {
			$score -= str_contains( $title_lc, $cue ) ? 4 : 2;
		}

		return array(
			'classification' => $score > 0 ? self::CANDIDATE : self::NOT_CANDIDATE,
			'score'          => $score,
			'matched'        => $matched,
			'negative'       => $negative,
		);
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

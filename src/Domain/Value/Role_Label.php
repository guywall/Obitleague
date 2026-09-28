<?php
/**
 * Role label hygiene.
 *
 * Role phrases arrive from third-party extraction and occasionally carry a
 * trailing clause that names a cause of death ("South Korean actor, blood
 * cancer"). Cause of death is a separate editorial decision in this plugin
 * (see Cause_Status), so a leaked clause is both wrong and — because roles are
 * printed in meta descriptions and page prose — visible to the public.
 *
 * Cleaning is deliberately conservative: only a trailing comma-separated
 * fragment naming a cause is removed, and only from the end of the phrase.
 * Nothing is reordered, reworded or invented.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Role_Label {

	/**
	 * Fragments that name a cause or manner of death rather than an occupation.
	 * Compared case-insensitively against the whole trailing fragment.
	 *
	 * @var string[]
	 */
	private const CAUSE_TERMS = array(
		// Neoplasms.
		'cancer', 'breast cancer', 'lung cancer', 'blood cancer', 'bone cancer',
		'leukemia', 'leukaemia', 'lymphoma', 'carcinoma', 'tumour', 'tumor', 'melanoma',
		// Circulatory and respiratory.
		'heart attack', 'cardiac arrest', 'heart failure', 'cardiovascular disease',
		'stroke', 'aneurysm', 'pulmonary embolism', 'respiratory failure',
		'respiratory disease', 'pneumonia', 'copd', 'emphysema', 'fibrosis',
		// Other organ failure and systemic disease.
		'liver failure', 'kidney failure', 'renal failure', 'sepsis', 'septicaemia',
		'septicemia', 'aids', 'hiv', 'covid', 'covid-19', 'dementia', "alzheimer's",
		'alzheimers', "alzheimer's disease", 'pancreatitis', 'encephalitis',
		'meningitis', 'thrombosis', 'infarction', 'coronavirus',
		// Manner of death.
		'suicide', 'murder', 'homicide', 'manslaughter', 'accident', 'accidental',
		'natural causes', 'illness', 'unknown causes', 'undisclosed', 'overdose',
		'drowning', 'gunshot wound', 'drug toxicity', 'complications',
		// Malformed upstream variants seen in real feed extractions.
		'unknown', 'cancer ', 'cause of death',
	);

	/** Consonants whose initial letter is pronounced with a leading vowel sound. */
	private const VOWEL_SOUND_CONSONANTS = 'AEFHILMNORSX';

	/**
	 * Words that turn a preceding cause word into an occupation.
	 * "cancer researcher" and "cancer nurse" are real job titles.
	 *
	 * @var string[]
	 */
	private const OCCUPATION_FOLLOWERS = array(
		'researcher', 'scientist', 'nurse', 'charity', 'foundation', 'specialist',
		'care', 'charity', 'society', 'association', 'fund', 'trust', 'study', 'studies',
	);

	private function __construct() {}

	/**
	 * Does this role phrase actually carry a cause-of-death clause?
	 *
	 * Distinct from comparing clean() against the input: clean() also
	 * normalises the malformed spacing feeds produce around commas
	 * (" , " → ", "), so two strings can differ with nothing removed. Callers
	 * that need to know whether a label is *contaminated* — pruning a taxonomy
	 * term, say — must ask this rather than infer it.
	 */
	public static function contains_cause( string $role ): bool {
		$role = trim( preg_replace( '/\s+/', ' ', $role ) ?? '' );
		if ( '' === $role ) {
			return false;
		}
		$parts = array_map( 'trim', explode( ',', $role ) );
		foreach ( $parts as $part ) {
			if ( self::is_cause( $part ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove a trailing cause-of-death clause from a role phrase.
	 *
	 * "South Korean actor , blood cancer"  → "South Korean actor"
	 * "Spanish politician, deputy , senator" → unchanged (not a cause)
	 * "American economist"                 → unchanged
	 */
	public static function clean( string $role ): string {
		$role = trim( preg_replace( '/\s+/', ' ', $role ) ?? '' );
		if ( '' === $role ) {
			return '';
		}

		// Walk comma-separated fragments from the end. Only trailing fragments
		// that name a cause are dropped; the first survivor ends the walk.
		$parts = array_map( 'trim', explode( ',', $role ) );
		while ( count( $parts ) > 1 ) {
			$last = end( $parts );
			if ( ! self::is_cause( (string) $last ) ) {
				break;
			}
			array_pop( $parts );
		}

		// A role that is nothing but a cause ("cancer") carries no occupation
		// and is dropped entirely.
		$clean = trim( implode( ', ', array_filter( $parts, static fn( string $p ): bool => '' !== $p ) ) );
		return self::is_cause( $clean ) ? '' : $clean;
	}

	/** Prefix a role phrase with the correct indefinite article. */
	public static function with_article( string $phrase ): string {
		$phrase = trim( $phrase );
		if ( '' === $phrase ) {
			return '';
		}
		return ( self::takes_an( $phrase ) ? 'an ' : 'a ' ) . $phrase;
	}

	/**
	 * Does this fragment name a cause or manner of death?
	 *
	 * A cause term may appear anywhere in the fragment, because feeds qualify
	 * it: "blood cancer", "acute necrotizing pancreatitis", "respiratory
	 * failure". A cause word is not a cause when an occupation word follows it,
	 * which is what keeps "cancer researcher" intact.
	 */
	private static function is_cause( string $fragment ): bool {
		$fragment = strtolower( trim( $fragment, " \t\n\r\0\x0B,.;:" ) );
		if ( '' === $fragment ) {
			return false;
		}
		if ( in_array( $fragment, self::CAUSE_TERMS, true ) ) {
			return true;
		}
		// "died of cancer" / "cause of death: cancer" style leftovers.
		if ( preg_match( '/\b(died|death|cause|suffering)\b/', $fragment ) ) {
			return true;
		}

		$words = preg_split( '/\s+/', $fragment ) ?: array();
		foreach ( $words as $i => $word ) {
			if ( ! in_array( $word, self::CAUSE_TERMS, true ) ) {
				continue;
			}
			$next = $words[ $i + 1 ] ?? '';
			if ( '' !== $next && in_array( $next, self::OCCUPATION_FOLLOWERS, true ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/** "an American economist", "an FBI agent", "a UK politician". */
	private static function takes_an( string $phrase ): bool {
		$first = strtok( $phrase, " \t\n\r,/" );
		if ( ! is_string( $first ) || '' === $first ) {
			return false;
		}
		// An initialism is read letter by letter: an FBI agent, a UK politician.
		if ( strlen( $first ) > 1 && $first === strtoupper( $first ) && ! preg_match( '/\d/', $first ) ) {
			return str_contains( self::VOWEL_SOUND_CONSONANTS, $first[0] );
		}
		return in_array( strtolower( $first[0] ), array( 'a', 'e', 'i', 'o', 'u' ), true );
	}
}

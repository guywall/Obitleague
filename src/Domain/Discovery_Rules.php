<?php
/**
 * Pure screening rules for living-person discovery candidates.
 *
 * Wikidata and Wikipedia are candidate filters, not proof of life. Publication
 * always requires a recent source checked and recorded by an editor.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;

final class Discovery_Rules {

	private function __construct() {}

	/** True only for an item with a non-deprecated instance-of human claim. */
	public static function is_human( array $entity ): bool {
		foreach ( (array) ( $entity['claims']['P31'] ?? array() ) as $claim ) {
			if ( ! is_array( $claim ) || 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			if ( 'Q5' === (string) ( $claim['mainsnak']['datavalue']['value']['id'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Any non-deprecated death claim blocks discovery/approval. */
	public static function has_death_claim( array $entity ): bool {
		foreach ( (array) ( $entity['claims']['P570'] ?? array() ) as $claim ) {
			if ( is_array( $claim ) && 'deprecated' !== (string) ( $claim['rank'] ?? 'normal' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return one unambiguous, day-precision Wikidata birth date.
	 * Conflicting live statements fail closed rather than choosing a date.
	 */
	public static function exact_birth_date( array $entity ): ?string {
		$claims = (array) ( $entity['claims']['P569'] ?? array() );
		$usable = array_values(
			array_filter(
				$claims,
				static fn ( $claim ): bool => is_array( $claim ) && 'deprecated' !== (string) ( $claim['rank'] ?? 'normal' )
			)
		);
		if ( array() === $usable ) {
			return null;
		}

		$statements = $usable;
		$dates      = array();

		foreach ( $statements as $claim ) {
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			if ( ! is_array( $value ) || 11 !== (int) ( $value['precision'] ?? 0 ) ) {
				return null;
			}
			$time = (string) ( $value['time'] ?? '' );
			if ( ! preg_match( '/^\+(\d{4})-(\d{2})-(\d{2})T/', $time, $match ) ) {
				return null;
			}
			$year  = (int) $match[1];
			$month = (int) $match[2];
			$day   = (int) $match[3];
			if ( $year < 1 || ! checkdate( $month, $day, $year ) ) {
				return null;
			}
			$dates[] = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}

		$dates = array_values( array_unique( $dates ) );
		return 1 === count( $dates ) ? $dates[0] : null;
	}

	/** A valid exact birth date meeting the ruleset age for the entry season. */
	public static function is_old_enough( string $birth_date, int $season ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $birth_date, $match ) ) {
			return false;
		}
		try {
			$birth = new Partial_Date( (int) $match[1], (int) $match[2], (int) $match[3] );
			$age   = Age::completed_at( $birth, Deadline_Policy::season_start( $season ) );
		} catch ( \Throwable ) {
			return false;
		}
		return null !== $age && $age >= Ruleset::MIN_AGE;
	}

	/**
	 * Wikipedia category names that indicate death after a person's birth.
	 * A same-year category may be a namesake, so it is not treated as proof.
	 *
	 * @return string[] Matching category titles.
	 */
	public static function death_categories( array $categories, int $birth_year ): array {
		$matches = array();
		foreach ( $categories as $category ) {
			$name = preg_replace( '/^Category:/i', '', trim( (string) $category ) ) ?? '';
			if ( preg_match( '/^(\d{3,4}) deaths$/i', $name, $match ) && (int) $match[1] > $birth_year ) {
				$matches[] = $name;
			}
		}
		return array_values( array_unique( $matches ) );
	}

	/** Whether English Wikipedia assigns the positive "Living people" category. */
	public static function has_living_category( array $categories ): bool {
		foreach ( $categories as $category ) {
			$name = preg_replace( '/^Category:/i', '', trim( (string) $category ) ) ?? '';
			if ( 0 === strcasecmp( $name, 'Living people' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The evidence publication date must be a real date, not future-dated, and
	 * no more than twelve months old relative to the supplied/current UTC day.
	 */
	public static function is_recent_evidence_date( string $date, ?string $today = null ): bool {
		$date = trim( $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $date ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			return false;
		}
		$today = $today ?? gmdate( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ) ) {
			return false;
		}
		[ $today_year, $today_month, $today_day ] = array_map( 'intval', explode( '-', $today ) );
		if ( ! checkdate( $today_month, $today_day, $today_year ) ) {
			return false;
		}

		$evidence = new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) );
		$now      = new \DateTimeImmutable( $today, new \DateTimeZone( 'UTC' ) );
		return $evidence <= $now && $evidence >= $now->modify( '-12 months' );
	}

	/** One true only when editorial confirmation, HTTPS source, and recent date all hold. */
	public static function is_approved_living_evidence( string $url, string $date, bool $editor_confirmed, ?string $today = null ): bool {
		return $editor_confirmed
			&& self::is_https_source_url( $url )
			&& self::is_recent_evidence_date( $date, $today );
	}

	/** Evidence is stored only as an absolute HTTPS URL without credentials. */
	public static function is_https_source_url( string $url ): bool {
		$url   = trim( $url );
		$parts = parse_url( $url );
		return false !== filter_var( $url, FILTER_VALIDATE_URL )
			&& is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}
}

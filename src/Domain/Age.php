<?php
/**
 * Completed age.
 *
 * Age is computed by calendar birthday — never by dividing elapsed days by
 * 365. For a 29 February birthday, 1 March is the birthday in a non-leap
 * year under ruleset v1.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Partial_Date;

final class Age {

	private function __construct() {}

	/**
	 * Completed years at the moment of death, or null when the birth date is
	 * not exact enough to compute an age.
	 */
	public static function completed_at( Partial_Date $birth, \DateTimeImmutable $death ): ?int {
		if ( ! $birth->is_exact() ) {
			return null;
		}
		$birth_day = $birth->interpretations()[0] ?? null;
		if ( null === $birth_day ) {
			return null; // Not a real calendar date; age cannot be computed.
		}

		// Death eligibility uses the reported local calendar date (Europe/London).
		$local = $death->setTimezone( new \DateTimeZone( self::LONDON ) );
		if ( $local < $birth_day->setTimezone( new \DateTimeZone( self::LONDON ) ) ) {
			throw new \InvalidArgumentException( 'Death precedes birth.' );
		}

		$year  = (int) $local->format( 'Y' );
		$month = (int) $birth_day->format( 'n' );
		$day   = (int) $birth_day->format( 'j' );

		// 29 February birthday: 1 March is the birthday in a non-leap year.
		if ( 2 === $month && 29 === $day && ! self::is_leap( $year ) ) {
			$month = 3;
			$day   = 1;
		}

		$birthday = new \DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), new \DateTimeZone( self::LONDON ) );
		$age      = $year - (int) $birth_day->format( 'Y' );
		if ( $local < $birthday ) {
			--$age;
		}
		return max( 0, $age );
	}

	private const LONDON = 'Europe/London';

	public static function is_leap( int $year ): bool {
		return 0 === $year % 4 && ( 0 !== $year % 100 || 0 === $year % 400 );
	}
}

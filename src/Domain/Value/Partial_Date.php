<?php
/**
 * Date precision.
 *
 * The plan's rule: retain the evidence's precision and never invent a day.
 * Unknown components stay null and are labelled honestly in the archive.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Partial_Date {

	public const PRECISION_EXACT = 'exact';
	public const PRECISION_MONTH = 'month';
	public const PRECISION_YEAR  = 'year';

	/**
	 * @param int|null $year  Calendar year, or null when unknown.
	 * @param int|null $month 1–12, or null when unknown.
	 * @param int|null $day   1–31, or null when unknown.
	 */
	public function __construct(
		public readonly ?int $year,
		public readonly ?int $month = null,
		public readonly ?int $day = null
	) {
		if ( $this->year !== null && ( $this->year < 1 || $this->year > 9999 ) ) {
			throw new \InvalidArgumentException( 'Partial_Date year out of range.' );
		}
		if ( $this->month !== null && ( $this->month < 1 || $this->month > 12 ) ) {
			throw new \InvalidArgumentException( 'Partial_Date month out of range.' );
		}
		if ( $this->day !== null && ( $this->day < 1 || $this->day > 31 ) ) {
			throw new \InvalidArgumentException( 'Partial_Date day out of range.' );
		}
		if ( $this->month === null && $this->day !== null ) {
			throw new \InvalidArgumentException( 'Partial_Date cannot have a day without a month.' );
		}
		if ( $this->year === null && ( $this->month !== null || $this->day !== null ) ) {
			throw new \InvalidArgumentException( 'Partial_Date cannot have month/day without a year.' );
		}
	}

	public function precision(): string {
		if ( $this->day !== null ) {
			return self::PRECISION_EXACT;
		}
		if ( $this->month !== null ) {
			return self::PRECISION_MONTH;
		}
		return self::PRECISION_YEAR;
	}

	public function is_exact(): bool {
		return self::PRECISION_EXACT === $this->precision();
	}

	/**
	 * All exact calendar dates this partial date could denote, earliest first.
	 * Impossible calendar days (e.g. 31 April, or 29 February in a non-leap
	 * year) are skipped.
	 *
	 * @return \DateTimeImmutable[]
	 */
	public function interpretations(): array {
		if ( null === $this->year || null === $this->month ) {
			return array();
		}
		$make = fn ( int $d ): \DateTimeImmutable => new \DateTimeImmutable(
			sprintf( '%04d-%02d-%02d', $this->year, $this->month, $d ),
			new \DateTimeZone( 'UTC' )
		);

		// An exact date denotes one day; an impossible day (31 April) denotes none.
		if ( null !== $this->day ) {
			return $this->day <= self::days_in_month( $this->year, $this->month ) ? array( $make( $this->day ) ) : array();
		}

		// A month denotes any of its days, earliest first.
		$dates = array();
		foreach ( range( 1, self::days_in_month( $this->year, $this->month ) ) as $d ) {
			$dates[] = $make( $d );
		}
		return $dates;
	}

	public static function days_in_month( int $year, int $month ): int {
		return (int) ( new \DateTimeImmutable( sprintf( 'last day of %04d-%02d', $year, $month ) ) )->format( 'j' );
	}

	/** Human label that never invents precision: "14 March 1930", "March 1930", "1930". */
	public function label(): string {
		if ( $this->year === null ) {
			return 'Unknown date';
		}
		if ( $this->month === null ) {
			return (string) $this->year;
		}
		$month_name = gmdate( 'F', gmmktime( 0, 0, 0, $this->month, 1 ) );
		if ( $this->day === null ) {
			return $month_name . ' ' . $this->year;
		}
		return $this->day . ' ' . $month_name . ' ' . $this->year;
	}
}

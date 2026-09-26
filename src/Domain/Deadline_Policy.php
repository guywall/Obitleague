<?php
/**
 * Deadline policy.
 *
 * Pure season-boundary decisions. Mirrors the ruleset: entries commit
 * strictly before 00:00 Europe/London on 1 January; standings settle at
 * 23:59:59 on 31 January the following year. Callers pass the instant that
 * governs the decision — for writes that is the time the transaction
 * started, not the time the check runs.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Deadline_Policy {

	private function __construct() {}

	/** 00:00 Europe/London on 1 January of the season year. */
	public static function entry_deadline( int $season ): \DateTimeImmutable {
		return new \DateTimeImmutable(
			sprintf( 'first day of January %04d 00:00:00', $season ),
			new \DateTimeZone( 'Europe/London' )
		);
	}

	/** 23:59:59 Europe/London on 31 January following the season. */
	public static function settlement_instant( int $season ): \DateTimeImmutable {
		return new \DateTimeImmutable(
			sprintf( 'last day of January %04d 23:59:59', $season + 1 ),
			new \DateTimeZone( 'Europe/London' )
		);
	}

	/** True while picks may be saved or submitted (inclusive-open bound). */
	public static function is_entry_open( int $season, \DateTimeImmutable $at ): bool {
		return $at < self::entry_deadline( $season );
	}

	/**
	 * True when a write that *committed* at $at still counts as on time.
	 * Applied to the transaction's start instant so a commit that lands on or
	 * after the deadline is late regardless of request start time.
	 */
	public static function commit_on_time( int $season, \DateTimeImmutable $transaction_started_at ): bool {
		return $transaction_started_at < self::entry_deadline( $season );
	}

	/** True when a death approved at $at may still create a NEW award. */
	public static function accepts_new_awards( int $season, \DateTimeImmutable $approved_at, int $first_season = 2027 ): bool {
		return $season >= $first_season && $approved_at <= self::settlement_instant( $season );
	}
}

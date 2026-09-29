<?php
/**
 * Deadline policy.
 *
 * Pure season-boundary decisions. Mirrors the ruleset: with rolling entry
 * (Ruleset::ROLLING_ENTRY), entries commit strictly before 23:59:59
 * Europe/London on 31 December of the season year; standings settle at
 * 23:59:59 on 31 January the following year. Callers pass the instant that
 * governs the decision — for writes that is the time the transaction
 * started, not the time the check runs.
 *
 * The submission-instant scoring floor keeps historic results exact: a
 * selection earns points only for deaths after the team's own submission
 * instant (equal instant never scores). Entries submitted under v1
 * (before 1 January) therefore behave exactly as they always did.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Ruleset;

final class Deadline_Policy {

	private function __construct() {}

	/**
	 * The instant a write must commit strictly before to be on time.
	 *
	 * Under rolling entry this is 23:59:59 London on 31 December of the
	 * season year; under v1 it was 00:00 London on 1 January.
	 */
	public static function entry_deadline( int $season ): \DateTimeImmutable {
		if ( Ruleset::ROLLING_ENTRY ) {
			return new \DateTimeImmutable(
				sprintf( 'last day of December %04d 23:59:59', $season ),
				new \DateTimeZone( 'Europe/London' )
			);
		}
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

	/** 00:00 Europe/London on 1 January of the season year. */
	public static function season_start( int $season ): \DateTimeImmutable {
		return new \DateTimeImmutable(
			sprintf( 'first day of January %04d 00:00:00', $season ),
			new \DateTimeZone( 'Europe/London' )
		);
	}

	/**
	 * Scoring floor for one pick: the earliest death instant a submission
	 * may score. Callers skip a pick when the verified death instant is
	 * strictly before this floor.
	 *
	 * The floor is season start (1 January London) no matter when the team
	 * joined; a submission instant later in the season raises it. Season
	 * start equals the instant every pre-flag (v1) entry was submitted by,
	 * so the floor can never move a historic award — deaths dated the
	 * season-start instant itself score exactly as they did under v1.
	 * Verified deaths are recorded as dates (compared at midnight), so a
	 * death dated the same calendar day as a daytime submission cannot be
	 * proven to have happened after it and does not score.
	 */
	public static function death_scores_for_pick( int $season, \DateTimeImmutable $submitted_at ): \DateTimeImmutable {
		return max( self::season_start( $season ), $submitted_at );
	}

	/** True when a death approved at $at may still create a NEW award. */
	public static function accepts_new_awards( int $season, \DateTimeImmutable $approved_at, int $first_season = 2027 ): bool {
		return $season >= $first_season && $approved_at <= self::settlement_instant( $season );
	}
}

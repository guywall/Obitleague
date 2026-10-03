<?php
/**
 * Deadline policy.
 *
 * Pure season-boundary decisions. Mirrors the ruleset: entries for season S
 * are made throughout the preceding year and must commit strictly before
 * 23:59:59 Europe/London on 31 December S−1; the season then runs 1 January
 * – 31 December S, and standings settle at 23:59:59 on 31 January S+1.
 * Callers pass the instant that governs the decision — for writes that is the
 * time the transaction started, not the time the check runs.
 *
 * The submission-instant scoring floor keeps results exact: a selection earns
 * points only for deaths after the team's own submission instant (equal
 * instant never scores). Because every valid entry now commits before the
 * season begins, the floor is the season start for every entry, matching the
 * historic calendar behaviour.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Ruleset;

final class Deadline_Policy {

	private function __construct() {}

	/**
	 * The instant a write must commit strictly before to be on time:
	 * 23:59:59 Europe/London on 31 December of the year *before* the season.
	 * Entries for season S are made throughout S−1.
	 */
	public static function entry_deadline( int $season ): \DateTimeImmutable {
		return new \DateTimeImmutable(
			sprintf( 'last day of December %04d 23:59:59', $season - Ruleset::ENTRY_WINDOW_YEARS_AHEAD ),
			new \DateTimeZone( 'Europe/London' )
		);
	}

	/**
	 * The instant the entry window opens: 00:00 Europe/London on 1 January of
	 * the year before the season. Inclusive: a write at exactly this instant
	 * is on time.
	 */
	public static function entry_window_open( int $season ): \DateTimeImmutable {
		return new \DateTimeImmutable(
			sprintf( 'first day of January %04d 00:00:00', $season - Ruleset::ENTRY_WINDOW_YEARS_AHEAD ),
			new \DateTimeZone( 'Europe/London' )
		);
	}

	/**
	 * True while the entry window is open: at or after the opening instant
	 * and strictly before the closing instant.
	 */
	public static function in_entry_window( int $season, \DateTimeImmutable $at ): bool {
		return $at >= self::entry_window_open( $season ) && $at < self::entry_deadline( $season );
	}

	/** 23:59:59 Europe/London on 31 January following the season. */
	public static function settlement_instant( int $season ): \DateTimeImmutable {
		return new \DateTimeImmutable(
			sprintf( 'last day of January %04d 23:59:59', $season + 1 ),
			new \DateTimeZone( 'Europe/London' )
		);
	}

	/** True while picks may be saved or submitted: inside the entry window. */
	public static function is_entry_open( int $season, \DateTimeImmutable $at ): bool {
		return self::in_entry_window( $season, $at );
	}

	/**
	 * True when a write that *committed* at $at still counts as on time.
	 * Applied to the transaction's start instant so a commit that lands on or
	 * after the deadline is late regardless of request start time, and one
	 * that lands before the window opens is too early.
	 */
	public static function commit_on_time( int $season, \DateTimeImmutable $transaction_started_at ): bool {
		return self::in_entry_window( $season, $transaction_started_at );
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
	 * Every valid entry now commits before the season begins, so the floor is
	 * the season start (1 January London) for every entry. Verified deaths
	 * are recorded as dates (compared at midnight), so a death dated the
	 * season-start instant itself still scores.
	 */
	public static function death_scores_for_pick( int $season, \DateTimeImmutable $submitted_at ): \DateTimeImmutable {
		return max( self::season_start( $season ), $submitted_at );
	}

	/** True when a death approved at $at may still create a NEW award. */
	public static function accepts_new_awards( int $season, \DateTimeImmutable $approved_at, int $first_season = 2027 ): bool {
		return $season >= $first_season && $approved_at <= self::settlement_instant( $season );
	}
}

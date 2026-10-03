<?php
/**
 * Ruleset value object.
 *
 * A ruleset is immutable and versioned. Seasons and entries store the version
 * they were created under so later changes cannot silently alter a running
 * competition. Changing rules for a new season means introducing a new
 * ruleset version, not editing this one.
 *
 * Locked entry window: the season remains a calendar year, but entries for
 * season S are made throughout the *preceding* year. The window opens at
 * 00:00 Europe/London on 1 January S−1 and closes at 23:59:59 Europe/London
 * on 31 December S−1; the season itself then runs 1 January – 31 December S.
 * Because every valid entry commits before the season begins, the
 * submission-instant scoring floor (see Deadline_Policy::death_scores_for_pick())
 * is the season start for every entry, so results match the old calendar
 * behaviour and the award ledger's operation keys stay stable. This is a
 * deadline-semantics change only; the scoring formula is unchanged, so the
 * ruleset version stays `1`.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Ruleset {

	public const VERSION = '1';

	/**
	 * Entries for season S are made throughout the preceding year: they open
	 * at 00:00 Europe/London on 1 January S−1 and close at 23:59:59
	 * Europe/London on 31 December S−1. The season then runs 1 January – 31
	 * December S. A write must commit strictly before the closing instant.
	 */
	public const ENTRY_WINDOW_YEARS_AHEAD = 1;

	/** Exact number of picks on a submitted team. */
	public const TEAM_SIZE = 10;

	/** Entry window and the instant a write must commit strictly before. */
	public const DEADLINE_RULE = 'Entries for a season are made throughout the preceding year: the window opens at 00:00 Europe/London on 1 January of the year before the season and closes at 23:59:59 Europe/London on 31 December before it. A write must commit strictly before the closing instant.';

	/**
	 * Back-compatible alias for the entry-window rule. The window now closes
	 * at 23:59:59 Europe/London on 31 December of the year *before* the
	 * season, not the season year.
	 */
	public const ENTRY_OPEN_UNTIL_RULE = '23:59:59 Europe/London on 31 December of the year before the season; a write must commit strictly before this instant.';

	/** Standings stay provisional until this instant after the season. */
	public const SETTLEMENT_RULE = '23:59:59 Europe/London on 31 January following the season.';

	/** Minimum age (completed years) at season start. */
	public const MIN_AGE = 18;

	private function __construct() {}

	/** Points formula for one eligible confirmed death. */
	public static function points_for_age( int $completed_age ): int {
		return max( 1, 100 - $completed_age );
	}

	/** Seasons a death date can be scored under (e.g. a range spans two). */
	public static function eligible_seasons_for_death( Partial_Date $death ): array {
		$years = array();
		foreach ( $death->interpretations() as $date ) {
			$years[] = (int) $date->format( 'Y' );
		}
		return array_values( array_unique( $years ) );
	}
}

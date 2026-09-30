<?php
/**
 * Ruleset value object.
 *
 * A ruleset is immutable and versioned. Seasons and entries store the version
 * they were created under so later changes cannot silently alter a running
 * competition. Changing rules for a new season means introducing a new
 * ruleset version, not editing this one.
 *
 * Humans vs AI (rolling entry): the season remains a calendar year and the
 * scoring formula is unchanged, but entries stay open for the whole season
 * (v1 closed them at 00:00 London on 1 January). This is expressed as the
 * ROLLING_ENTRY flag rather than a new ruleset version: every pre-flag entry
 * was submitted before the old deadline, so the submission-instant scoring
 * floor (see Deadline_Policy::death_scores_for_pick()) reproduces v1 results
 * exactly and the award ledger's operation keys stay stable. Historic
 * standings are unaffected.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Ruleset {

	public const VERSION = '1';

	/**
	 * Entries remain open for the entire season year. Teams may join,
	 * submit and amend at any point until 23:59:59 Europe/London on 31
	 * December; a selection only scores for a death after the team's own
	 * submission instant, so late joiners gain no retrospective points.
	 */
	public const ROLLING_ENTRY = true;

	/** Exact number of picks on a submitted team. */
	public const TEAM_SIZE = 10;

	/** Deadline instant (v1 behaviour): 00:00 Europe/London on 1 January. */
	public const DEADLINE_RULE = '00:00 Europe/London on 1 January; a write must commit strictly before this instant.';

	/**
	 * Deadline instant while rolling entry is active: 23:59:59 Europe/London
	 * on 31 December of the season year. A write must commit strictly before
	 * this instant.
	 */
	public const ENTRY_OPEN_UNTIL_RULE = '23:59:59 Europe/London on 31 December of the season year; a write must commit strictly before this instant.';

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

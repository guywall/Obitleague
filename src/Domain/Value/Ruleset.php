<?php
/**
 * Ruleset value object.
 *
 * A ruleset is immutable and versioned. Seasons and entries store the version
 * they were created under so later changes cannot silently alter a running
 * competition. Changing rules for a new season means introducing a new
 * ruleset version, not editing this one.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Ruleset {

	public const VERSION = '1';

	/** Exact number of picks on a submitted team. */
	public const TEAM_SIZE = 10;

	/** Deadline instant: 00:00 Europe/London on 1 January of the season year. */
	public const DEADLINE_RULE = '00:00 Europe/London on 1 January; a write must commit strictly before this instant.';

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

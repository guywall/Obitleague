<?php
/**
 * Scoring scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Scoring;
use Obitleague\Domain\Value\Award;
use Obitleague\Domain\Value\Awarded_Event;
use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;
use Obitleague\Domain\Value\Score_Result;

final class Scenario_Scoring {

	public function test_points_formula_and_floor( Runner $t ): void {
		$t->check( 22 === Ruleset::points_for_age( 78 ), __METHOD__, 'age 78 → 22 points' );
		$t->check( 1 === Ruleset::points_for_age( 100 ), __METHOD__, 'age 100 → 1 point' );
		$t->check( 1 === Ruleset::points_for_age( 110 ), __METHOD__, 'age 110 → floor of 1' );
		$t->check( 99 === Ruleset::points_for_age( 1 ), __METHOD__, 'age 1 → 99 points' );
	}

	public function test_scoring_uses_completed_age( Runner $t ): void {
		// Born 15 June 1930; dies on their 100th birthday: floor of 1 point.
		$event  = self::event( '2030-06-15' );
		$result = Scoring::evaluate( $event, new Partial_Date( 1930, 6, 15 ) );
		$t->check( $result->is_scored(), __METHOD__, 'exact birth and death date scores' );
		$t->check( null !== $result->award && 100 === $result->award->completed_age, __METHOD__, 'completed age is 100' );
		$t->check( null !== $result->award && 1 === $result->award->points, __METHOD__, '100 − 100 floored to 1 point' );
	}

	public function test_unclear_death_date_is_held_not_scored( Runner $t ): void {
		$event = new Awarded_Event(
			'e-2',
			'person-2',
			'J. Smith',
			new Partial_Date( 1945, 3, null ), // Month precision only.
			Cause_Status::NOT_DISCLOSED,
			null,
			new \DateTimeImmutable( '2027-03-10T12:00:00Z' )
		);
		$result = Scoring::evaluate( $event, new Partial_Date( 1945, 1, 2 ) );
		$t->check( ! $result->is_scored(), __METHOD__, 'month-precision death is held' );
		$t->check( null !== $result->reason && str_contains( $result->reason, 'day precision' ), __METHOD__, 'hold reason explains the rule' );
	}

	public function test_unexact_birth_date_is_held( Runner $t ): void {
		$event = self::event( '2027-02-10' );
		$result = Scoring::evaluate( $event, new Partial_Date( 1950, 2, null ) );
		$t->check( ! $result->is_scored(), __METHOD__, 'non-exact birth date holds scoring' );
	}

	public function test_team_total_counts_points_and_scoring_picks( Runner $t ): void {
		$total = Scoring::team_total( array( new Award( 'e1', 80, 20 ), null, new Award( 'e2', 95, 5 ) ) );
		$t->check( 25 === $total['points'] && 2 === $total['scoring_picks'], __METHOD__, '20 + 5 = 25 points, 2 scoring picks' );
		$t->check( 0 === Scoring::team_total( array_fill( 0, 10, null ) )['points'], __METHOD__, 'all living picks score zero' );
	}

	public function test_operation_key_is_stable_per_event_revision( Runner $t ): void {
		$a = Scoring_Service_Double::key( 42, 'pick-1', 2027, 'event-9' );
		$b = Scoring_Service_Double::key( 42, 'pick-1', 2027, 'event-9' );
		$c = Scoring_Service_Double::key( 42, 'pick-1', 2027, 'event-10' );
		$t->check( $a === $b, __METHOD__, 'same inputs → same key (idempotent)' );
		$t->check( $a !== $c, __METHOD__, 'new event revision → new key' );
	}

	/** Build an approved event with an exact death date. */
	private static function event( string $death_date ): Awarded_Event {
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $death_date ) );
		return new Awarded_Event(
			'e-' . $death_date,
			'person-' . $death_date,
			'Test Person',
			new Partial_Date( $y, $m, $d ),
			Cause_Status::NOT_DISCLOSED,
			null,
			new \DateTimeImmutable( '2027-03-01T09:00:00Z' )
		);
	}
}

/**
 * Access to the scoring service's pure helpers without WordPress.
 *
 * @codeCoverageIgnore
 */
final class Scoring_Service_Double {
	public static function key( int $entry_id, string $pick_slug, int $season, string $event_uuid ): string {
		return md5( implode( '|', array( (string) $entry_id, $pick_slug, (string) $season, Ruleset::VERSION, $event_uuid ) ) );
	}
}

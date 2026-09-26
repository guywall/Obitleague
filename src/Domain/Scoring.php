<?php
/**
 * Scoring engine.
 *
 * Pure functions from approved events to points. Ruleset v1: points =
 * max(1, 100 − completed age at death); age by calendar birthday in
 * Europe/London; month-precision deaths are held, never approximated.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Award;
use Obitleague\Domain\Value\Awarded_Event;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;
use Obitleague\Domain\Value\Score_Result;

final class Scoring {

	private function __construct() {}

	/**
	 * Score one approved death against one pick's birth date.
	 *
	 * Returns a hold (with reason) when the death date lacks day precision or
	 * the birth date is not exact — the facts are published, points wait.
	 */
	public static function evaluate( Awarded_Event $event, Partial_Date $birth ): Score_Result {
		if ( ! $event->death->is_exact() ) {
			return Score_Result::held( 'Death date lacks day precision; scoring waits for an exact date.' );
		}
		if ( ! $birth->is_exact() ) {
			return Score_Result::held( 'Birth date is not exact; completed age cannot be computed.' );
		}

		$death_instant = $event->death->interpretations()[0] ?? null;
		if ( null === $death_instant ) {
			return Score_Result::held( 'Death date is not a valid calendar date.' );
		}
		$age           = Age::completed_at( $birth, $death_instant );
		if ( null === $age ) {
			return Score_Result::held( 'Completed age could not be computed.' );
		}

		return Score_Result::scored(
			new Award( $event->event_id, $age, Ruleset::points_for_age( $age ) )
		);
	}

	/**
	 * Total points for a team's ten picks. Picks without an award (the person
	 * is still alive) contribute zero; at most one award per pick applies.
	 *
	 * @param array<int, ?Award> $awards Up to ten awards, null for living picks.
	 */
	public static function team_total( array $awards ): array {
		$points   = 0;
		$scoring  = 0;
		foreach ( $awards as $award ) {
			if ( null === $award ) {
				continue;
			}
			$points += $award->points;
			++$scoring;
		}
		return array(
			'points'        => $points,
			'scoring_picks' => $scoring,
		);
	}
}

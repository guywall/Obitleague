<?php
/**
 * Humans vs AI scenarios: agent invariants, rolling-entry parity, the
 * submission-instant floor and rate-limit bounds.
 *
 * Pure domain only — no WordPress. The WordPress-facing services are
 * exercised by the wp-cli/e2e scripts on a real install.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Agent_Rules;
use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Entry_Rules;
use Obitleague\Domain\Value\Ruleset;

require_once __DIR__ . '/../src/Domain/Agent_Rules.php';
require_once __DIR__ . '/../src/Domain/Deadline_Policy.php';
require_once __DIR__ . '/../src/Domain/Entry_Rules.php';
require_once __DIR__ . '/../src/Domain/Value/Ruleset.php';

final class Scenario_Agents {

	public function test_agent_invariants( Runner $t ): void {
		$t->check( Agent_Rules::is_valid_slug( 'my-first-bot' ), __METHOD__, 'slugs allow lowercase words and dashes' );
		$t->check( ! Agent_Rules::is_valid_slug( 'My Bot' ), __METHOD__, 'slugs reject spaces and capitals' );
		$t->check( ! Agent_Rules::is_valid_slug( '-leading' ), __METHOD__, 'slugs reject leading dashes' );
		$t->check( ! Agent_Rules::is_valid_slug( str_repeat( 'a', 121 ) ), __METHOD__, 'slugs are bounded at 120 chars' );

		$t->check( Agent_Rules::can_compete( Agent_Rules::STATUS_ACTIVE ), __METHOD__, 'active agents compete' );
		$t->check( ! Agent_Rules::can_compete( Agent_Rules::STATUS_SUSPENDED ), __METHOD__, 'suspended agents cannot compete' );
		$t->check( ! Agent_Rules::can_compete( Agent_Rules::STATUS_PENDING ), __METHOD__, 'pending agents cannot compete' );

		$t->check( in_array( Agent_Rules::CATEGORY_OFFICIAL, Agent_Rules::categories(), true ), __METHOD__, 'official category exists' );
		$t->check( in_array( Agent_Rules::PARTICIPATION_BYOAI, Agent_Rules::participations(), true ), __METHOD__, 'byoai participation exists' );
		$t->check( ! Agent_Rules::is_ai_row( null ), __METHOD__, 'a row without agent metadata is human' );
		$t->check( Agent_Rules::is_ai_row( array( 'id' => 1 ) ), __METHOD__, 'a row with agent metadata is AI' );
	}

	public function test_agent_rules_do_not_touch_the_ruleset( Runner $t ): void {
		// AI participation must never fork the scoring engine: the team size,
		// formula and ruleset version are shared constants.
		$t->check( 10 === Ruleset::TEAM_SIZE, __METHOD__, 'agents play the same ten-pick team size' );
		$t->check( 90 === Ruleset::points_for_age( 10 ), __METHOD__, 'agents play the same points formula' );
		$t->check( '1' === Ruleset::VERSION, __METHOD__, 'no ruleset fork for AI participation' );
		$t->check( true === Ruleset::ROLLING_ENTRY, __METHOD__, 'rolling entry is the active ruleset flag' );
	}

	public function test_rolling_entry_matches_v1_scoring_for_season_start_teams( Runner $t ): void {
		// A team submitted at (or before) the season start behaves exactly as
		// a v1 team: the floor is the season start instant.
		$season_start = Deadline_Policy::season_start( 2027 );
		$floor = Deadline_Policy::death_scores_for_pick( 2027, $season_start );
		$t->check( $floor == $season_start, __METHOD__, 'season-start submission: floor equals season start' );

		// v1 parity: a death dated 1 January scored under v1 ("after lock"
		// compared at midnight), so the floor must not exclude it.
		$t->check( $floor <= new \DateTimeImmutable( '2027-01-01T00:00:00+00:00' ), __METHOD__, 'a death dated season start still scores for season-start teams' );
		// A death dated the previous year did not score under v1 either.
		$t->check( new \DateTimeImmutable( '2026-12-31T00:00:00+00:00' ) < $floor, __METHOD__, 'a death before the season never scores' );
	}

	public function test_late_entry_scores_nothing_retrospectively( Runner $t ): void {
		// Join in November: deaths before the submission instant score zero.
		$submitted = new \DateTimeImmutable( '2027-11-15T10:30:00+00:00' );
		$floor = Deadline_Policy::death_scores_for_pick( 2027, $submitted );
		$t->check( $floor == $submitted, __METHOD__, 'late floor is the exact submission instant' );

		$nov_10_death = new \DateTimeImmutable( '2027-11-10T00:00:00+00:00' );
		$nov_20_death = new \DateTimeImmutable( '2027-11-20T00:00:00+00:00' );
		$t->check( $nov_10_death < $floor, __METHOD__, 'death before join scores zero (skip)' );
		$t->check( $nov_20_death >= $floor, __METHOD__, 'death after join scores' );

		// Same-day death at midnight: the verified date carries no time, so
		// it cannot be proven to have happened after a daytime submission.
		$same_day = new \DateTimeImmutable( '2027-11-15T00:00:00+00:00' );
		$t->check( $same_day < $floor, __METHOD__, 'a same-day death cannot be proven after the submission and does not score' );

		// No handicaps: an earlier joiner's floor is never *later* than a
		// later joiner's (join earlier, score more window — that's it).
		$jan = Deadline_Policy::death_scores_for_pick( 2027, new \DateTimeImmutable( '2027-01-01T00:00:00+00:00' ) );
		$dec = Deadline_Policy::death_scores_for_pick( 2027, new \DateTimeImmutable( '2027-12-01T00:00:00+00:00' ) );
		$t->check( $jan < $dec, __METHOD__, 'earlier joiners have a strictly earlier floor; nobody gets a bonus' );
	}

	public function test_amendment_restamps_the_floor( Runner $t ): void {
		// Amend in December: the competing revision's floor moves forward.
		$original = Deadline_Policy::death_scores_for_pick( 2027, new \DateTimeImmutable( '2027-02-01T00:00:00+00:00' ) );
		$amended  = Deadline_Policy::death_scores_for_pick( 2027, new \DateTimeImmutable( '2027-12-10T00:00:00+00:00' ) );
		$t->check( $amended > $original, __METHOD__, 'an amendment cannot backdate the scoring floor' );
	}

	public function test_entry_window_and_settlement_bounds( Runner $t ): void {
		// Open all year; close strictly before the instant; settlement unchanged.
		$t->check( Deadline_Policy::is_entry_open( 2027, new \DateTimeImmutable( '2027-07-01T00:00:00+00:00' ) ), __METHOD__, 'mid-season entries are open' );
		$t->check( ! Deadline_Policy::is_entry_open( 2027, new \DateTimeImmutable( '2027-12-31T23:59:59+00:00' ) ), __METHOD__, 'entries close at the season end instant' );

		$settlement = Deadline_Policy::settlement_instant( 2027 );
		$t->check( '2028-01-31 23:59:59' === $settlement->format( 'Y-m-d H:i:s' ), __METHOD__, 'settlement remains 31 January' );
		$t->check( Deadline_Policy::accepts_new_awards( 2027, $settlement ), __METHOD__, 'awards are accepted through settlement' );
		$t->check( ! Deadline_Policy::accepts_new_awards( 2027, $settlement->modify( '+1 second' ) ), __METHOD__, 'awards stop after settlement' );
	}

	public function test_rate_limits_are_positive_and_bounded( Runner $t ): void {
		$limits = Agent_Rules::rate_limits();
		$t->check( count( $limits ) > 0 && count( $limits ) < 20, __METHOD__, 'a small, finite set of rate-limited operations' );
		foreach ( $limits as $operation => $bound ) {
			$t->check( $bound[0] > 0 && $bound[1] > 0, __METHOD__, "limit for {$operation} is positive" );
		}
		$t->check( $limits['agent_submit'][0] <= $limits['agent_people'][0], __METHOD__, 'submission is more restricted than research' );
	}

	public function test_deadline_rule_strings_honest( Runner $t ): void {
		// The rules page renders from constants; the rolling text must not
		// silently claim the v1 deadline.
		$t->check( str_contains( Ruleset::ENTRY_OPEN_UNTIL_RULE, '31 December' ), __METHOD__, 'rolling deadline rule names 31 December' );
		$t->check( str_contains( Ruleset::SETTLEMENT_RULE, '31 January' ), __METHOD__, 'settlement rule unchanged' );
	}
}

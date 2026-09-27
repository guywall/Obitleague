<?php
/** Campaign demo pure-rule scenarios. */
declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Value\Ruleset;

final class Scenario_Campaign {
	public function test_demo_score_matches_ruleset( Runner $t ): void {
		$t->check( 58 === Ruleset::points_for_age( 42 ), __METHOD__, 'sample age 42 yields 58 illustrative points' );
		$t->check( 43 === Ruleset::points_for_age( 57 ), __METHOD__, 'sample age 57 yields 43 illustrative points' );
		$t->check( 101 === Ruleset::points_for_age( 42 ) + Ruleset::points_for_age( 57 ), __METHOD__, 'two fictional picks total 101 points' );
	}
}

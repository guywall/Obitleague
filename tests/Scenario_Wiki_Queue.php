<?php
/**
 * Shared Wikimedia queue retry-policy scenarios.
 *
 * The queue drains oldest-first, so a row that is retried without being
 * pushed forward is picked straight back up: one bad request can climb the
 * attempt counter into the thousands and starve everything behind it. These
 * scenarios pin the two caps that stop that — a short leash for ordinary
 * failures, a longer one for a genuine rate limit — and the backoff between
 * tries.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Wiki_Queue_Policy;

final class Scenario_Wiki_Queue {

	public function test_ordinary_failures_give_up_at_the_cap( Runner $t ): void {
		$t->check( 5 === Wiki_Queue_Policy::MAX_ATTEMPTS, __METHOD__, 'the ordinary cap is five attempts' );
		$t->check( ! Wiki_Queue_Policy::should_fail( 1, false ), __METHOD__, 'the first failure retries' );
		$t->check( ! Wiki_Queue_Policy::should_fail( 4, false ), __METHOD__, 'the fourth failure still retries' );
		$t->check( Wiki_Queue_Policy::should_fail( 5, false ), __METHOD__, 'the fifth failure gives up' );
		$t->check( Wiki_Queue_Policy::should_fail( 2104, false ), __METHOD__, 'a run-away counter gives up' );
	}

	public function test_rate_limit_failures_get_a_longer_leash_but_still_end( Runner $t ): void {
		$t->check( 25 === Wiki_Queue_Policy::MAX_DEFER_ATTEMPTS, __METHOD__, 'the rate-limit cap is twenty-five attempts' );
		$t->check( Wiki_Queue_Policy::MAX_DEFER_ATTEMPTS > Wiki_Queue_Policy::MAX_ATTEMPTS, __METHOD__, 'a throttle is retried more than an ordinary failure' );
		$t->check( ! Wiki_Queue_Policy::should_fail( 5, true ), __METHOD__, 'a rate-limited row past the ordinary cap still retries' );
		$t->check( ! Wiki_Queue_Policy::should_fail( 24, true ), __METHOD__, 'the twenty-fourth rate-limited try retries' );
		$t->check( Wiki_Queue_Policy::should_fail( 25, true ), __METHOD__, 'the twenty-fifth rate-limited try gives up' );
		$t->check( Wiki_Queue_Policy::should_fail( 51267, true ), __METHOD__, 'an endlessly throttled row eventually gives up' );
	}

	public function test_backoff_grows_and_stays_bounded( Runner $t ): void {
		$first  = Wiki_Queue_Policy::retry_backoff_seconds( 1 );
		$second = Wiki_Queue_Policy::retry_backoff_seconds( 2 );
		$third  = Wiki_Queue_Policy::retry_backoff_seconds( 3 );
		$t->check( $first >= 5, __METHOD__, 'even the first backoff waits a few seconds' );
		$t->check( $second > $first, __METHOD__, 'the second failure waits longer' );
		$t->check( $third > $second, __METHOD__, 'the third waits longer again' );
		$t->check( Wiki_Queue_Policy::retry_backoff_seconds( 99 ) <= 3600, __METHOD__, 'the backoff never exceeds the pause ceiling' );
		$t->check( $first === Wiki_Queue_Policy::retry_backoff_seconds( 0 ), __METHOD__, 'a zero attempt is treated as the first' );
	}
}

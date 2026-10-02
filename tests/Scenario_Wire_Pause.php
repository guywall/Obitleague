<?php
/**
 * Bounded Wikimedia pause scenarios.
 *
 * The pause is what the whole Wikimedia queue obeys, so its arithmetic runs
 * without WordPress: the policy must honour a genuine Retry-After, back off
 * across repeated failures, and never park the automation past the ceiling.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Wire_Pause;

final class Scenario_Wire_Pause {

	public function test_retry_after_seconds_parses_numbers_and_dates( Runner $t ): void {
		$t->check( 30 === Wire_Pause::retry_after_seconds( '30' ), __METHOD__, 'numeric Retry-After is seconds' );
		$t->check( 0 === Wire_Pause::retry_after_seconds( '' ), __METHOD__, 'empty header yields no hint' );
		$t->check( 0 === Wire_Pause::retry_after_seconds( 'later' ), __METHOD__, 'junk yields no hint' );
		$secs = Wire_Pause::retry_after_seconds( gmdate( 'D, d M Y H:i:s \G\M\T', time() + 120 ) );
		$t->check( $secs >= 118 && $secs <= 121, __METHOD__, 'HTTP-date Retry-After becomes seconds from now' );
		$t->check( 0 === Wire_Pause::retry_after_seconds( gmdate( 'D, d M Y H:i:s \G\M\T', time() - 60 ) ), __METHOD__, 'a past date clamps to zero' );
	}

	public function test_seconds_never_exceeds_the_ceiling( Runner $t ): void {
		$t->check( Wire_Pause::MAX_SECONDS === Wire_Pause::seconds( '999999999', 1 ), __METHOD__, 'a wild Retry-After is capped at the ceiling' );
		$t->check( Wire_Pause::MAX_SECONDS === Wire_Pause::seconds( '', 99 ), __METHOD__, 'a long failure streak is capped at the ceiling' );
	}

	public function test_seconds_honours_a_genuine_hint( Runner $t ): void {
		$t->check( 12 === Wire_Pause::seconds( '12', 1 ), __METHOD__, 'a small first hint is honoured' );
		$t->check( Wire_Pause::BASE_SECONDS === Wire_Pause::seconds( '', 1 ), __METHOD__, 'a hintless first failure waits the base' );
		$t->check( 60 === Wire_Pause::seconds( '', 2 ), __METHOD__, 'a second failure doubles the base' );
		$t->check( 480 === Wire_Pause::seconds( '10', 5 ), __METHOD__, 'repeated failures override a small stale hint' );
	}

	public function test_seconds_has_a_floor( Runner $t ): void {
		$t->check( Wire_Pause::MIN_SECONDS === Wire_Pause::seconds( '3', 1 ), __METHOD__, 'a tiny hint still waits the floor' );
	}
}

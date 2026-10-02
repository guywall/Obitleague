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

	/**
	 * The moment the next queued row for a source becomes eligible. A stored
	 * datetime once flowed through a numeric cast, so the spacing candidate
	 * came back as the bare year "2027" and MySQL stored a zero date — which
	 * reads as due at once, silently discarding the per-source pacing.
	 */
	public function test_next_attempt_at_paces_rows_without_casting_dates( Runner $t ): void {
		$now   = '2026-10-02 12:00:00';
		$pause = (int) strtotime( '2026-10-02 12:30:00 UTC' );

		$t->check( $now === Wire_Pause::next_attempt_at( '', 1, $now, 0 ), __METHOD__, 'nothing queued and no pause means now' );
		$t->check( $now === Wire_Pause::next_attempt_at( '2026-10-02 11:31:24', 1, $now, 0 ), __METHOD__, 'an already-past row does not push the moment forward' );
		$t->check( $now === Wire_Pause::next_attempt_at( '0000-00-00 00:00:00', 1, $now, 0 ), __METHOD__, 'a zero date is ignored rather than parsed' );
		$t->check( '2026-10-02 12:00:15' === Wire_Pause::next_attempt_at( '2026-10-02 12:00:10', 5, $now, 0 ), __METHOD__, 'the gap is added to the last queued row' );
		$t->check( '2026-10-02 12:30:00' === Wire_Pause::next_attempt_at( '', 1, $now, $pause ), __METHOD__, 'a rate-limit pause wins over now' );
		$t->check( '2026-10-02 12:45:00' === Wire_Pause::next_attempt_at( '2026-10-02 12:44:59', 1, $now, $pause ), __METHOD__, 'the later of the pause and the spacing wins' );
		$t->check(
			1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', Wire_Pause::next_attempt_at( '2026-10-02 11:31:24', 1, $now, 0 ) ),
			__METHOD__,
			'the result is always a storable MySQL datetime'
		);
	}
}

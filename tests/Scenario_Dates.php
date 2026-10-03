<?php
/**
 * Date and season boundary scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Age;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;
use Obitleague\Support\Time;

require_once __DIR__ . '/../src/Support/Time.php';

final class Scenario_Dates {

	public function test_partial_dates_keep_precision_and_never_invent_a_day( Runner $t ): void {
		$t->check( '14 March 1930' === ( new Partial_Date( 1930, 3, 14 ) )->label(), __METHOD__, 'exact date renders fully' );
		$t->check( 'March 1930' === ( new Partial_Date( 1930, 3, null ) )->label(), __METHOD__, 'month precision keeps "March 1930"' );
		$t->check( '1930' === ( new Partial_Date( 1930, null, null ) )->label(), __METHOD__, 'year precision keeps "1930"' );
	}

	public function test_day_without_month_is_rejected( Runner $t ): void {
		try {
			new Partial_Date( 1930, null, 14 );
			$t->fail( __METHOD__, 'day without month must throw' );
		} catch ( \InvalidArgumentException ) {
			$t->check( true, __METHOD__, 'day without month throws' );
		}
	}

	public function test_leap_day_birthday_becomes_1_march_in_non_leap_years( Runner $t ): void {
		$birth = new Partial_Date( 1944, 2, 29 );
		$death_non_leap = new \DateTimeImmutable( '2027-02-28', new \DateTimeZone( 'UTC' ) );
		$death_leap     = new \DateTimeImmutable( '2028-02-29', new \DateTimeZone( 'UTC' ) );

		$t->check( 82 === Age::completed_at( $birth, $death_non_leap ), __METHOD__, '28 Feb non-leap: still 82' );
		$t->check( 83 === Age::completed_at( $birth, $death_non_leap->modify( '+1 day' ) ), __METHOD__, '1 Mar non-leap: 83' );
		$t->check( 84 === Age::completed_at( $birth, $death_leap ), __METHOD__, '29 Feb leap year: 84' );
	}

	public function test_age_uses_calendar_birthday_not_elapsed_days( Runner $t ): void {
		$birth = new Partial_Date( 1945, 6, 15 );
		// Midday UTC on 14 June is still 14 June in Europe/London even in BST.
		$death = new \DateTimeImmutable( '2027-06-14T12:00:00Z' );
		$t->check( 81 === Age::completed_at( $birth, $death ), __METHOD__, 'day before 82nd birthday: 81' );
		$t->check( 82 === Age::completed_at( $birth, $death->modify( '+1 day' ) ), __METHOD__, 'on the birthday: 82' );
	}

	public function test_potential_points_use_completed_age_if_death_were_today( Runner $t ): void {
		$birth = new Partial_Date( 1945, 6, 15 );
		$today = new \DateTimeImmutable( '2027-06-14T12:00:00Z' );
		$age   = Age::completed_at( $birth, $today );
		$t->check( 19 === Ruleset::points_for_age( (int) $age ), __METHOD__, 'potential uses 81 completed years: 19 points' );
		$t->check( 18 === Ruleset::points_for_age( (int) Age::completed_at( $birth, $today->modify( '+1 day' ) ) ), __METHOD__, 'potential falls on the birthday' );
		$t->check( null === Age::completed_at( new Partial_Date( 1945, 6 ), $today ), __METHOD__, 'partial birth date has no guessed potential age' );
	}

	public function test_entry_window_is_the_preceding_year( Runner $t ): void {
		// Entries for season S are made throughout S−1.
		$open     = Time::entry_window_open( 2027 );
		$deadline = Time::entry_deadline( 2027 );
		$t->check( 'Europe/London' === $deadline->getTimezone()->getName(), __METHOD__, 'deadline is Europe/London' );
		$t->check( '2026-01-01 00:00' === $open->format( 'Y-m-d H:i' ), __METHOD__, 'window opens 1 January of the year before' );
		$t->check( '2026-12-31 23:59' === $deadline->format( 'Y-m-d H:i' ), __METHOD__, 'window closes 31 December of the year before' );
		$t->check( '2027-01-01 00:00' === Time::season_start( 2027 )->format( 'Y-m-d H:i' ), __METHOD__, 'season start is 1 January of the season year' );
	}

	public function test_writes_must_commit_strictly_before_deadline( Runner $t ): void {
		// The window opens at 00:00 on 1 January S−1 and closes at 23:59:59
		// on 31 December S−1.
		$t->check( Time::is_entry_open( 2027, new \DateTimeImmutable( '2026-01-01T00:00:00+00:00' ) ), __METHOD__, 'at the opening instant is open' );
		$t->check( Time::is_entry_open( 2027, new \DateTimeImmutable( '2026-06-15T12:00:00+00:00' ) ), __METHOD__, 'mid-window is open' );
		$t->check( Time::is_entry_open( 2027, new \DateTimeImmutable( '2026-12-31T23:59:58+00:00' ) ), __METHOD__, 'one second before the close is open' );
		$t->check( ! Time::is_entry_open( 2027, new \DateTimeImmutable( '2026-12-31T23:59:59+00:00' ) ), __METHOD__, 'at the close is closed' );
		$t->check( ! Time::is_entry_open( 2027, new \DateTimeImmutable( '2025-12-31T23:59:59+00:00' ) ), __METHOD__, 'before the window opens is closed' );
		$t->check( ! Time::is_entry_open( 2027, new \DateTimeImmutable( '2027-01-01T00:00:00+00:00' ) ), __METHOD__, 'at the season start is closed' );
		$t->check( ! Time::is_entry_open( 2027, new \DateTimeImmutable( '2027-06-15T12:00:00+00:00' ) ), __METHOD__, 'mid-season is closed' );
	}

	public function test_settlement_closes_new_awards_after_31_january( Runner $t ): void {
		$t->check( Time::accepts_new_awards( 2027, new \DateTimeImmutable( '2028-01-31T23:59:59+00:00' ) ), __METHOD__, 'settlement instant still accepts' );
		$t->check( ! Time::accepts_new_awards( 2027, new \DateTimeImmutable( '2028-02-01T00:00:00+00:00' ) ), __METHOD__, 'after settlement: no new awards' );
		$t->check( ! Time::accepts_new_awards( 2020, new \DateTimeImmutable( '2021-01-01T00:00:00+00:00' ) ), __METHOD__, 'pre-game seasons never accept' );
	}
}

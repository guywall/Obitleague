<?php
/**
 * Death-wire extraction scenarios.
 *
 * The wire's headline matching, wikitext reading, date arithmetic and
 * likelihood mapping now live in pure Domain classes. They decide who is
 * created and with which dates, so they are checked without WordPress.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Wire_Dates;
use Obitleague\Domain\Wire_Headline;
use Obitleague\Domain\Wire_Score;
use Obitleague\Domain\Wire_Wikitext;

final class Scenario_Wire_Extraction {

	public function test_headline_extracts_the_subject( Runner $t ): void {
		$t->check( 'Mighty Sparrow' === Wire_Headline::group( 'Mighty Sparrow obituary' ), __METHOD__, 'obituary suffix is stripped' );
		$t->check( 'Bob Pettit' === Wire_Headline::group( 'Bob Pettit, N.B.A. Great for the Hawks, Dies at 93' ), __METHOD__, 'broadsheet leading name' );
		$t->check( null === Wire_Headline::group( 'One two three four five six seven eight' ), __METHOD__, 'a sentence fragment is refused' );
	}

	public function test_wikitext_reads_a_death_year( Runner $t ): void {
		$t->check( Wire_Wikitext::death_year( '| death_date = 14 September 2026', 2026 ), __METHOD__, 'infobox death date confirms the year' );
		$t->check( Wire_Wikitext::death_year( 'He died in 2026 after a long illness.', 2026 ), __METHOD__, 'prose death wording confirms the year' );
		$t->check( false === Wire_Wikitext::death_year( '| birth_date = 1932', 2026 ), __METHOD__, 'no death is not a death' );
		$t->check( false === Wire_Wikitext::death_year( '', 2026 ), __METHOD__, 'empty wikitext is not a death' );
	}

	public function test_wikitext_reads_dates( Runner $t ): void {
		$t->check( '1932' === Wire_Wikitext::birth_year( '| birth_date = {{birth date|1932|12|12}}' ), __METHOD__, 'birth-date template yields the year' );
		$t->check( '1946' === Wire_Wikitext::birth_year( '| birth_date = 1946' ), __METHOD__, 'a plain birth_date field yields the year' );
		$t->check( '1900' === Wire_Wikitext::birth_year( 'Foo (born 1900)' ), __METHOD__, 'a parenthetical born year yields the year' );
		$t->check( '' === Wire_Wikitext::birth_year( 'no dates here' ), __METHOD__, 'absent birth year is empty' );
		$t->check( '2026-09-14' === Wire_Wikitext::death_date( '| death_date = {{death date|2026|9|14}}', 2026 ), __METHOD__, 'death-date template yields exact date' );
		$t->check( '2026-09-14' === Wire_Wikitext::death_date( 'He died on 14 September 2026.', 2026 ), __METHOD__, 'prose date yields exact date' );
		$t->check( '2026' === Wire_Wikitext::death_date( 'No day given.', 2026 ), __METHOD__, 'unknown day falls back to the year' );
	}

	public function test_date_helpers( Runner $t ): void {
		$t->check( '2026-09-14' === Wire_Dates::normalize_day( '2026-09-14T00:00:00Z' ), __METHOD__, 'a day-precision timestamp keeps its day' );
		$t->check( '2026-09' === Wire_Dates::normalize_day( '2026-09-00T00:00:00Z' ), __METHOD__, 'a month-precision timestamp drops the day' );
		$t->check( '2026' === Wire_Dates::normalize_day( '2026-00-00T00:00:00Z' ), __METHOD__, 'a year-precision timestamp drops month and day' );
		$t->check( '2027-01' === Wire_Dates::next_month( '2026-12' ), __METHOD__, 'next month rolls the year' );
	}

	public function test_season_months_cover_the_whole_year_to_date( Runner $t ): void {
		$oct = gmmktime( 12, 0, 0, 10, 4, 2026 );
		$t->check(
			array( '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10' ) === Wire_Dates::season_months( 2026, $oct ),
			__METHOD__,
			'the season walks January through the month in play'
		);
		$t->check( 12 === count( Wire_Dates::season_months( 2025, $oct ) ), __METHOD__, 'a past season walks all twelve months' );
		$t->check( array() === Wire_Dates::season_months( 2027, $oct ), __METHOD__, 'a season that has not begun walks nothing' );
	}

	public function test_month_elapsed_only_for_finished_months( Runner $t ): void {
		$t->check( Wire_Dates::month_elapsed( '2026-09', '2026-10-04' ), __METHOD__, 'September is over on 4 October' );
		$t->check( ! Wire_Dates::month_elapsed( '2026-10', '2026-10-04' ), __METHOD__, 'the month in play is not over' );
		$t->check( Wire_Dates::month_elapsed( '2026-12', '2027-01-01' ), __METHOD__, 'December is over on 1 January' );
		$t->check( ! Wire_Dates::month_elapsed( '2026-12', '2026-12-31' ), __METHOD__, 'the last day of a month is still in it' );
	}

	public function test_likelihood_mapping( Runner $t ): void {
		$t->check( 0 === Wire_Score::likelihood_pct( 0 ), __METHOD__, 'no score is zero likelihood' );
		$t->check( 70 === Wire_Score::likelihood_pct( 70 ), __METHOD__, 'a weighted score passes through' );
		$t->check( 50 === Wire_Score::likelihood_pct( 5 ), __METHOD__, 'a legacy cue counter is mapped x10' );
		$t->check( 95 === Wire_Score::likelihood_pct( 500 ), __METHOD__, 'the scale is capped at 95' );
	}
}

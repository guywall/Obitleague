<?php
/**
 * Story fact extraction scenarios (article → phrases, dates, cause, bio).
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Story_Facts;

final class Scenario_Story_Facts {

	public function test_full_obituary_yields_every_fact( Runner $t ): void {
		$text  = 'Margaret Ellis, beloved broadcaster and author, has died at the age of 88. '
			. 'Ellis, who was born on 14 March 1938, presented the evening news for three decades. '
			. 'She died on 2 September 2026 following a short illness. Her family confirmed the death. '
			. 'The broadcaster station confirmed her passing and colleagues paid tribute. '
			. 'The broadcaster station confirmed her passing and colleagues paid tribute. '
			. 'The broadcaster station confirmed her passing and colleagues paid tribute.';
		$facts = Story_Facts::parse( 'Margaret Ellis, veteran broadcaster, dies aged 88', $text );
		$t->check( '1938-03-14' === $facts['birth'], __METHOD__, 'birth date extracted: ' . $facts['birth'] );
		$t->check( '2026-09-02' === $facts['death'], __METHOD__, 'death date extracted: ' . $facts['death'] );
		$t->check( '88' === $facts['age'], __METHOD__, 'stated age preferred: ' . $facts['age'] );
		$t->check( 'short illness' === $facts['cause'], __METHOD__, 'cause captured: ' . $facts['cause'] );
		$t->check( str_contains( $facts['bio'], 'Margaret Ellis' ), __METHOD__, 'bio names the subject' );
		$t->check( array() !== $facts['cloud'], __METHOD__, 'cloud built from the body' );
		$t->check( isset( $facts['phrases']['broadcaster station'] ) || ! empty( $facts['phrases'] ), __METHOD__, 'recurring phrases found' );
	}

	public function test_american_date_order_and_lifespan_parenthetical( Runner $t ): void {
		$facts = Story_Facts::parse( 'John Doe dies', 'John Doe (March 3, 1940 - August 2, 2026) was an American composer. He passed away August 2, 2026 at his home.' );
		$t->check( '1940-03-03' === $facts['birth'], __METHOD__, 'US-order birth from parenthetical: ' . $facts['birth'] );
		$t->check( '2026-08-02' === $facts['death'], __METHOD__, 'US-order death extracted: ' . $facts['death'] );
	}

	public function test_bare_birth_year_is_kept_at_year_precision( Runner $t ): void {
		$facts = Story_Facts::parse( 'Jane Roe dies', 'Jane Roe, born in 1951, was a painter of quiet landscapes. She died in hospital.' );
		$t->check( '1951' === $facts['birth'], __METHOD__, 'bare year kept, never upgraded: ' . $facts['birth'] );
		$t->check( '' === $facts['death'], __METHOD__, 'no invented death date' );
	}

	public function test_age_computed_from_exact_dates_when_not_stated( Runner $t ): void {
		$text = 'He was born on 10 June 1945 and died on 9 June 2026, one day short of his birthday.';
		$facts = Story_Facts::parse( 'A life remembered', $text );
		$t->check( '80' === $facts['age'], __METHOD__, 'birthday not yet reached: ' . $facts['age'] );
		$facts2 = Story_Facts::parse( 'A life remembered', str_replace( '9 June 2026', '10 June 2026', $text ) );
		$t->check( '81' === $facts2['age'], __METHOD__, 'on the birthday: ' . $facts2['age'] );
	}

	public function test_minimal_story_yields_empty_facts( Runner $t ): void {
		$facts = Story_Facts::parse( 'Football club announces new manager', 'The club confirmed the appointment this morning.' );
		$t->check( '' === $facts['birth'] && '' === $facts['death'] && '' === $facts['age'] && '' === $facts['cause'], __METHOD__, 'nothing invented from a non-obituary story' );
	}

	public function test_cause_stops_at_clause_joins( Runner $t ): void {
		$cause = Story_Facts::cause( 'He died of pneumonia and sepsis aged 80 at home.' );
		$t->check( 'pneumonia' === $cause, __METHOD__, 'clause join trimmed: ' . $cause );
		$none = Story_Facts::cause( 'She died peacefully surrounded by family.' );
		$t->check( '' === $none, __METHOD__, 'no cause stated, none reported' );
	}
}

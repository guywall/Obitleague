<?php
/**
 * Wire identity scenarios.
 *
 * The fuzzy Wikipedia search is the wire's weakest link: it returns the
 * first hit for a name, which is often somebody else. This check must
 * accept the same person and refuse everyone else, so a wrong name can
 * never become a record.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Wire_Identity;

final class Scenario_Wire_Identity {

	public function test_same_person_matches( Runner $t ): void {
		$t->check( Wire_Identity::matches( 'Bob Pettit', 'Bob Pettit' ), __METHOD__, 'exact name matches' );
		$t->check( Wire_Identity::matches( 'Sam Neill', 'Sam Neill' ), __METHOD__, 'two-token name matches' );
		$t->check( Wire_Identity::matches( 'Arthur Hancock III', 'Arthur B. Hancock III' ), __METHOD__, 'middle initial in the article is fine' );
		$t->check( Wire_Identity::matches( 'Dame Esther Rantzen', 'Esther Rantzen' ), __METHOD__, 'honorific is not identity' );
		$t->check( Wire_Identity::matches( 'The Mighty Sparrow', 'Mighty Sparrow' ), __METHOD__, 'leading article is not identity' );
		$t->check( Wire_Identity::matches( 'Richard O’Sullivan', "Richard O'Sullivan" ), __METHOD__, 'apostrophe style does not matter' );
		$t->check( Wire_Identity::matches( 'Bob Pettit obituary', 'Bob Pettit' ), __METHOD__, 'desk furniture is not identity' );
	}

	public function test_different_person_is_refused( Runner $t ): void {
		$t->check( ! Wire_Identity::matches( 'Cal Swann', 'Rich Swann' ), __METHOD__, 'a shared surname is not the same person' );
		$t->check( ! Wire_Identity::matches( 'Cal Swann', 'Sam Cunningham' ), __METHOD__, 'an unrelated hit is refused' );
		$t->check( ! Wire_Identity::matches( 'Sway Dasafo', 'Sway (rapper)' ), __METHOD__, 'a missing name token is refused' );
	}

	public function test_non_person_and_empty_inputs_are_refused( Runner $t ): void {
		$t->check( ! Wire_Identity::matches( 'Cal Swann', 'Swann (surname)' ), __METHOD__, 'a surname page is not the person' );
		$t->check( ! Wire_Identity::matches( 'Bob Pettit', 'Pettit Avenue' ), __METHOD__, 'an unrelated title sharing one token is refused' );
		$t->check( ! Wire_Identity::matches( '', 'Bob Pettit' ), __METHOD__, 'an empty group matches nothing' );
		$t->check( ! Wire_Identity::matches( 'Bob Pettit', '' ), __METHOD__, 'an empty title matches nothing' );
	}
}

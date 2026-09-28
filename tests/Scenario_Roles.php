<?php
/**
 * Role label hygiene scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Value\Role_Label;

final class Scenario_Roles {

	public function test_trailing_cause_of_death_is_stripped( Runner $t ): void {
		// Observed in live feed extractions: cause leaked into the role field.
		$t->check( 'South Korean actor' === Role_Label::clean( 'South Korean actor , blood cancer' ), __METHOD__, 'trailing "blood cancer" removed' );
		$t->check( 'Pakistani cricketer' === Role_Label::clean( 'Pakistani cricketer , cancer' ), __METHOD__, 'trailing "cancer" removed' );
		$t->check( 'American sociologist' === Role_Label::clean( 'American sociologist, respiratory failure' ), __METHOD__, 'trailing "respiratory failure" removed' );
		$t->check( 'Spanish visual artist' === Role_Label::clean( 'Spanish visual artist, cancer' ), __METHOD__, 'trailing "cancer" removed without space' );
	}

	public function test_real_occupations_are_untouched( Runner $t ): void {
		// A genuine multi-clause role must survive intact: "cancer" here is not
		// the last fragment, and "senator" is not a cause at all. The malformed
		// upstream spacing around the comma is normalised, but nothing is cut.
		$t->check( 'Spanish politician, deputy, senator' === Role_Label::clean( 'Spanish politician, deputy , senator' ), __METHOD__, 'legitimate clauses preserved, spacing normalised' );
		$t->check( 'American politician, member of the Virginia House of Delegates' === Role_Label::clean( 'American politician, member of the Virginia House of Delegates' ), __METHOD__, 'long office title preserved' );
		$t->check( 'French geographer and academic, president of Paris 8 University' === Role_Label::clean( 'French geographer and academic, president of Paris 8 University' ), __METHOD__, 'presidency title preserved' );
		$t->check( 'Italian-born Belgian cartoonist' === Role_Label::clean( 'Italian-born Belgian cartoonist' ), __METHOD__, 'hyphenated nationality preserved' );
	}

	public function test_only_trailing_clauses_are_considered( Runner $t ): void {
		$t->check( 'cricketer' === Role_Label::clean( 'cricketer, cancer, pneumonia' ), __METHOD__, 'strips repeatedly until a non-cause remains' );
		$t->check( 'cancer researcher' === Role_Label::clean( 'cancer researcher' ), __METHOD__, '"cancer researcher" is an occupation, not a cause' );
	}

	public function test_empty_and_cause_only_roles_yield_nothing( Runner $t ): void {
		$t->check( '' === Role_Label::clean( '   ' ), __METHOD__, 'whitespace becomes empty' );
		$t->check( '' === Role_Label::clean( 'unknown' ), __METHOD__, 'a role that is only a cause is dropped' );
		$t->check( '' === Role_Label::clean( 'died of cancer' ), __METHOD__, '"died of cancer" is dropped' );
	}

	public function test_contains_cause_separates_contamination_from_reformatting( Runner $t ): void {
		// clean() also normalises " , " to ", ", so a differing string is not
		// evidence of contamination. contains_cause() is.
		$t->check( Role_Label::contains_cause( 'South Korean actor , blood cancer' ), __METHOD__, 'trailing cause is detected' );
		$t->check( Role_Label::contains_cause( 'American baseball player, World Series champion, acute necrotizing pancreatitis' ), __METHOD__, 'cause in a long chain is detected' );
		$t->check( ! Role_Label::contains_cause( 'Spanish politician, deputy , senator' ), __METHOD__, 'spacing-only difference is not contamination' );
		$t->check( ! Role_Label::contains_cause( 'Danish politician, minister of transport , member of the Folketing' ), __METHOD__, 'long legitimate title is not contamination' );
		$t->check( ! Role_Label::contains_cause( 'Italian Roman Catholic prelate, apostolic nuncio to four nunciatures including Mozambique , Costa Rica and Monaco' ), __METHOD__, 'nunciature title is not contamination' );
		$t->check( ! Role_Label::contains_cause( 'cancer researcher' ), __METHOD__, 'occupation that borrows a cause word is clean' );
		$t->check( ! Role_Label::contains_cause( '' ), __METHOD__, 'empty role is clean' );
	}

	public function test_indefinite_article_matches_the_sound( Runner $t ): void {
		$t->check( 'an American economist' === Role_Label::with_article( 'American economist' ), __METHOD__, 'vowel initial takes "an"' );
		$t->check( 'a British journalist' === Role_Label::with_article( 'British journalist' ), __METHOD__, 'consonant initial takes "a"' );
		$t->check( 'an FBI agent' === Role_Label::with_article( 'FBI agent' ), __METHOD__, 'initialism read as "eff" takes "an"' );
		$t->check( 'a UK politician' === Role_Label::with_article( 'UK politician' ), __METHOD__, 'initialism read as "you" takes "a"' );
		$t->check( 'an MP' === Role_Label::with_article( 'MP' ), __METHOD__, 'single-letter initialism takes "an"' );
		$t->check( '' === Role_Label::with_article( '' ), __METHOD__, 'empty phrase yields empty' );
	}
}

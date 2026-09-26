<?php
/**
 * Feed classification scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Feed_Classifier;

final class Scenario_Feeds {

	public function test_death_headline_is_a_candidate( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Veteran actor Alan Howard dies aged 84' );
		$t->check( Feed_Classifier::CANDIDATE === $result['classification'], __METHOD__, 'obituary headline → candidate' );
	}

	public function test_anniversary_piece_is_not_a_candidate( Runner $t ): void {
		$result = Feed_Classifier::classify( 'On this day: the death of a screen legend, ten years on', 'An anniversary retrospective of a famous death.' );
		$t->check( Feed_Classifier::NOT_CANDIDATE === $result['classification'], __METHOD__, 'anniversary context suppresses the cue' );
	}

	public function test_death_hoax_is_not_a_candidate( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Actor denies death hoax: I am still alive', 'The star dismissed false reports of his death.' );
		$t->check( Feed_Classifier::NOT_CANDIDATE === $result['classification'], __METHOD__, 'hoax and denial suppress the cue' );
	}

	public function test_mourning_someone_else_is_not_a_candidate( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Manager pays tribute to his mentor' );
		$t->check( Feed_Classifier::NOT_CANDIDATE === $result['classification'], __METHOD__, 'mourning piece without death wording is not a candidate' );
	}

	public function test_ambiguous_third_person_grief_goes_to_review( Runner $t ): void {
		// "Fans mourn the death of X" may be a genuine report; the cue filter
		// errs toward review and the editor resolves whose death it is.
		$result = Feed_Classifier::classify( 'Fans mourn the death of actor Alan Howard' );
		$t->check( Feed_Classifier::CANDIDATE === $result['classification'], __METHOD__, 'ambiguous grief stays a candidate for review' );
	}
}

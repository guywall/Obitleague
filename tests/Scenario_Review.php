<?php
/**
 * Review scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Review_Rules;

require_once __DIR__ . '/../src/Domain/Review_Rules.php';

final class Scenario_Review {

	public function test_only_legal_transitions_allowed( Runner $t ): void {
		$t->check( Review_Rules::can_transition( Review_Rules::PENDING, Review_Rules::APPROVED ), __METHOD__, 'pending → approved' );
		$t->check( Review_Rules::can_transition( Review_Rules::PENDING, Review_Rules::REJECTED ), __METHOD__, 'pending → rejected' );
		$t->check( Review_Rules::can_transition( Review_Rules::APPROVED, Review_Rules::RETRACTED ), __METHOD__, 'approved → retracted (correction path)' );
		$t->check( Review_Rules::can_transition( Review_Rules::RETRACTED, Review_Rules::APPROVED ), __METHOD__, 'retracted → approved (re-approval)' );
		$t->check( Review_Rules::can_transition( Review_Rules::REJECTED, Review_Rules::PENDING ), __METHOD__, 'new evidence reopens a rejection' );
		$t->check( ! Review_Rules::can_transition( Review_Rules::REJECTED, Review_Rules::APPROVED ), __METHOD__, 'rejected cannot jump to approved' );
		$t->check( ! Review_Rules::can_transition( Review_Rules::APPROVED, Review_Rules::APPROVED ), __METHOD__, 'no double approval' );
	}

	public function test_approval_requires_two_origins_or_official_statement( Runner $t ): void {
		$problems = Review_Rules::approval_problems(
			array( 'origin_groups' => array( 'bbc' ), 'death_date' => array( 'y' => 2026, 'm' => 9, 'd' => 20 ) )
		);
		$t->check( 1 === count( $problems ), __METHOD__, 'one origin group alone is refused' );

		$ok = Review_Rules::approval_problems(
			array( 'origin_groups' => array( 'bbc', 'guardian' ), 'death_date' => array( 'y' => 2026, 'm' => 9, 'd' => 20 ) )
		);
		$t->check( array() === $ok, __METHOD__, 'two origin groups pass' );

		$official = Review_Rules::approval_problems(
			array( 'origin_groups' => array( 'family' ), 'official_statement' => true, 'death_date' => array( 'y' => 2026, 'm' => 9, 'd' => 20 ) )
		);
		$t->check( array() === $official, __METHOD__, 'one authentic official statement suffices' );
	}

	public function test_approval_requires_exact_date( Runner $t ): void {
		$problems = Review_Rules::approval_problems(
			array( 'origin_groups' => array( 'a', 'b' ), 'death_date' => array( 'y' => 2026, 'm' => 9 ) )
		);
		$t->check( 1 === count( $problems ), __METHOD__, 'month precision cannot be approved for scoring' );

		$unknown = Review_Rules::approval_problems(
			array( 'origin_groups' => array( 'a', 'b' ) )
		);
		$t->check( 1 === count( $unknown ), __METHOD__, 'missing date blocks approval' );
	}

	public function test_disclosed_cause_requires_wording( Runner $t ): void {
		$problems = Review_Rules::approval_problems(
			array( 'origin_groups' => array( 'a', 'b' ), 'death_date' => array( 'y' => 2026, 'm' => 9, 'd' => 20 ), 'cause_disclosed' => true )
		);
		$t->check( 1 === count( $problems ), __METHOD__, 'disclosed cause without wording refused' );
	}

	public function test_selection_blocking_by_state( Runner $t ): void {
		$t->check( 'block_selection' === Review_Rules::selection_effect( Review_Rules::PENDING ), __METHOD__, 'unresolved report blocks selection' );
		$t->check( 'keep_blocked' === Review_Rules::selection_effect( Review_Rules::APPROVED ), __METHOD__, 'approved death keeps person unselectable' );
		$t->check( 'none' === Review_Rules::selection_effect( Review_Rules::RETRACTED ), __METHOD__, 'retraction unblocks selection' );
	}
}

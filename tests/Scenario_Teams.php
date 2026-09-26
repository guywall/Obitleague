<?php
/**
 * Team validation scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Invalid_Team_Exception;
use Obitleague\Domain\Team_Picks;

final class Scenario_Teams {

	public function test_valid_team_of_ten_distinct_picks( Runner $t ): void {
		$picks = Team_Picks::validate( array( 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p10' ) );
		$t->check( 10 === count( $picks ), __METHOD__, 'ten distinct picks validate' );
	}

	public function test_wrong_count_is_rejected( Runner $t ): void {
		try {
			Team_Picks::validate( array( 'p1', 'p2', 'p3' ) );
			$t->fail( __METHOD__, 'three picks must not validate' );
		} catch ( Invalid_Team_Exception $e ) {
			$t->check( str_contains( $e->getMessage(), 'exactly 10' ), __METHOD__, 'wrong count explains the rule' );
		}
	}

	public function test_duplicate_picks_are_rejected( Runner $t ): void {
		$picks = array( 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p1' );
		try {
			Team_Picks::validate( $picks );
			$t->fail( __METHOD__, 'duplicate picks must not validate' );
		} catch ( Invalid_Team_Exception $e ) {
			$t->check( str_contains( $e->getMessage(), 'distinct' ), __METHOD__, 'duplicates explain the rule' );
		}
	}

	public function test_empty_pick_is_rejected( Runner $t ): void {
		$picks = array( '', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p10' );
		try {
			Team_Picks::validate( $picks );
			$t->fail( __METHOD__, 'empty pick must not validate' );
		} catch ( Invalid_Team_Exception $e ) {
			$t->check( str_contains( $e->getMessage(), 'non-empty' ), __METHOD__, 'empty pick explains the rule' );
		}
	}
}

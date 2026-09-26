<?php
/**
 * Ranking scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Ranking;

final class Scenario_Ranking {

	public function test_competition_ranking_shares_ties_and_skips( Runner $t ): void {
		$rows = Ranking::competition_rank(
			array(
				'A' => array( 'score' => 50, 'scoring_picks' => 3 ),
				'B' => array( 'score' => 30, 'scoring_picks' => 3 ),
				'C' => array( 'score' => 30, 'scoring_picks' => 3 ),
				'D' => array( 'score' => 10, 'scoring_picks' => 1 ),
			)
		);

		$t->check( 1 === $rows['A']['rank'], __METHOD__, 'highest score is rank 1' );
		$t->check( 2 === $rows['B']['rank'] && 2 === $rows['C']['rank'], __METHOD__, 'equal totals share rank 2' );
		$t->check( 4 === $rows['D']['rank'], __METHOD__, 'next rank is 4 (1, 2, 2, 4)' );
	}

	public function test_scoring_picks_break_point_ties( Runner $t ): void {
		$rows = Ranking::competition_rank(
			array(
				'X' => array( 'score' => 22, 'scoring_picks' => 2 ),
				'Y' => array( 'score' => 22, 'scoring_picks' => 1 ),
			)
		);
		$t->check( 1 === $rows['X']['rank'] && 2 === $rows['Y']['rank'], __METHOD__, 'more scoring picks ranks higher' );
	}
}

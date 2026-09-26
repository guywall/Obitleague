<?php
/**
 * Competition ranking.
 *
 * Rank by total points, then by number of scoring picks. Ties on both share
 * a competition rank (1, 2, 2, 4): no rank is skipped after a tie.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Ranking {

	/**
	 * @param array<int, array{score:int, scoring_picks:int}> $rows Keyed by any stable row key.
	 * @return array<int, array{score:int, scoring_picks:int, rank:int}> Same rows, each with a rank.
	 */
	public static function competition_rank( array $rows ): array {
		$ordered = $rows;
		uasort(
			$ordered,
			static fn ( array $a, array $b ): int => $b['score'] <=> $a['score']
				?: $b['scoring_picks'] <=> $a['scoring_picks']
		);

		$rank      = 0;
		$seen      = 0;
		$prev_key  = null;
		foreach ( $ordered as $key => $row ) {
			++$seen;
			$current_key = $row['score'] . ':' . $row['scoring_picks'];
			if ( null === $prev_key || $current_key !== $prev_key ) {
				$rank     = $seen;
				$prev_key = $current_key;
			}
			$ordered[ $key ]['rank'] = $rank;
		}
		return $ordered;
	}
}

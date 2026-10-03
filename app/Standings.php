<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Ranking;
use Obitleague\Domain\Scoring;
use Obitleague\Domain\Value\Award;

/**
 * Computes season standings from submitted teams and the award ledger.
 *
 * The submission-instant floor is applied when an award is written (see
 * {@see Awards}), so standings only aggregate the ledger per (team, pick) and
 * rank with the pure rules. A pick removed by an amendment is simply absent
 * from the competing revision and stops scoring; a surviving pick keeps its
 * already-earned rows.
 */
final class Standings {
	public function __construct( private Database $db, private Clock $clock ) {
	}

	/**
	 * Whether a season's standings are final: true once the season has settled,
	 * so the read model can present them as settled rather than live. The rule
	 * itself lives in the pure season policy; this only reports it.
	 */
	public function is_final( int $season ): bool {
		return Deadline_Policy::is_settled( $season, $this->clock->instant() );
	}

	/**
	 * Rank every submitted team in a season.
	 *
	 * Points and scoring-pick counts come from the pure rules
	 * ({@see Scoring::team_total}), as does the tie handling
	 * ({@see Ranking::competition_rank}).
	 *
	 * @return list<array{account:int, player:string, points:int, scoring_picks:int, rank:int}>
	 */
	public function for_season( int $season ): array {
		$awards = $this->season_awards( $season );

		$stmt = $this->db->pdo()->prepare(
			'SELECT e.id AS entry_id, e.account_id, a.display_name, p.person_uuid
			 FROM entries e
			 JOIN accounts a ON a.id = e.account_id
			 JOIN entry_picks p ON p.revision_id = e.submitted_revision_id
			 WHERE e.season = ? AND e.state = \'submitted\'
			 ORDER BY e.id, p.slot'
		);
		$stmt->execute( array( $season ) );

		$teams = array();
		foreach ( $stmt->fetchAll() as $pick ) {
			$entry = (int) $pick['entry_id'];
			if ( ! isset( $teams[ $entry ] ) ) {
				$teams[ $entry ] = array(
					'account' => (int) $pick['account_id'],
					'player' => (string) $pick['display_name'],
					'picks' => array(),
				);
			}
			$teams[ $entry ]['picks'][] = (string) $pick['person_uuid'];
		}

		$rows = array();
		foreach ( $teams as $entry => $team ) {
			$team_awards = array();
			foreach ( $team['picks'] as $uuid ) {
				$award = $awards[ $entry ][ $uuid ] ?? null;
				$team_awards[] = null !== $award ? new Award( $uuid, $award['age'], $award['points'] ) : null;
			}
			$totals = Scoring::team_total( $team_awards );
			$rows[ $entry ] = array(
				'account' => $team['account'],
				'player' => $team['player'],
				'score' => $totals['points'],
				'scoring_picks' => $totals['scoring_picks'],
			);
		}

		return array_map(
			static fn ( array $row ): array => array(
				'account' => $row['account'],
				'player' => $row['player'],
				'points' => $row['score'],
				'scoring_picks' => $row['scoring_picks'],
				'rank' => $row['rank'],
			),
			array_values( Ranking::competition_rank( $rows ) )
		);
	}

	/** One account's live standing, or null while its team is unpublished. */
	public function for_account( int $season, int $account ): ?array {
		foreach ( $this->for_season( $season ) as $row ) {
			if ( $row['account'] === $account ) {
				return $row;
			}
		}
		return null;
	}

	/** Net award per team and pick for a season; reversed events net to zero. */
	private function season_awards( int $season ): array {
		$stmt = $this->db->pdo()->prepare( 'SELECT entry_id, person_uuid, SUM(points_delta) AS points, MAX(completed_age) AS age FROM awards WHERE season = ? GROUP BY entry_id, person_uuid' );
		$stmt->execute( array( $season ) );
		$awards = array();
		foreach ( $stmt->fetchAll() as $row ) {
			if ( (int) $row['points'] > 0 ) {
				$awards[ (int) $row['entry_id'] ][ (string) $row['person_uuid'] ] = array(
					'points' => (int) $row['points'],
					'age' => (int) $row['age'],
				);
			}
		}
		return $awards;
	}
}

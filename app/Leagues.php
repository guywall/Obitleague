<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Value\Ruleset;

/** Provisions leagues and their membership. */
final class Leagues {
	public function __construct( private Database $db, private Clock $clock ) {
	}

	/** Create the main league for the season if absent and enrol the account. */
	public function provision( int $account, int $season ): void {
		$pdo = $this->db->pdo();
		$now = $this->clock->now();
		$pdo->prepare( 'INSERT INTO leagues (season, kind, ruleset_version, name, created_at) VALUES (?, \'main\', ?, ?, ?) ON CONFLICT(season, kind) DO NOTHING' )
			->execute( array( $season, Ruleset::VERSION, 'Overall League ' . $season, $now ) );
		$stmt = $pdo->prepare( 'SELECT id FROM leagues WHERE season = ? AND kind = \'main\'' );
		$stmt->execute( array( $season ) );
		$league = (int) $stmt->fetchColumn();
		$status = Deadline_Policy::is_entry_open( $season, $this->clock->instant() ) ? 'member' : 'spectator';
		$pdo->prepare( 'INSERT INTO league_members (league_id, account_id, status, joined_at) VALUES (?, ?, ?, ?)' )
			->execute( array( $league, $account, $status, $now ) );
	}
}

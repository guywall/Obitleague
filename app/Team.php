<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Entry_Rules;
use Obitleague\Domain\Value\Ruleset;

/** Owns the team save, submit, and amend workflow. */
final class Team {
	public function __construct( private Database $db, private Catalog $catalog, private Clock $clock ) {
	}

	/** Collect the view data for one account's team page. */
	public function view( array $account, bool $saved = false, bool $submitted = false ): array {
		$row = $this->entry_for_account( (int) $account['id'], $this->clock->season_now() );
		$picks = $this->revision_picks( (int) ( $row['draft_revision_id'] ?: $row['submitted_revision_id'] ?: 0 ) );
		return array(
			'account' => $account,
			'people' => $this->catalog->picker( $picks, $this->clock->season_now() ),
			'entry' => $row,
			'picks' => $picks,
			'saved' => $saved,
			'submitted' => $submitted,
			'csrf' => $account['csrf_token'],
		);
	}

	/** Save ten picks as a draft, or amend a submitted entry, and return the view data. */
	public function save( array $account, array $input ): array {
		$picks = array_values( (array) ( $input['picks'] ?? array() ) );
		if ( count( $picks ) !== Ruleset::TEAM_SIZE || count( array_filter( $picks, 'is_string' ) ) !== Ruleset::TEAM_SIZE ) {
			throw new Http_Error( 422, 'A team must contain ten person identifiers.' );
		}
		$picks = Entry_Rules::validate_picks( $picks );
		$season = (int) ( $input['season'] ?? $this->clock->season_now() );
		// The lock is authoritative: refuse a locked season before looking up (and
		// lazily creating) the entry, so a rejected post-lock amend writes nothing.
		$this->require_open( $season );
		$row = $this->entry_for_account( (int) $account['id'], $season );
		$this->require_member( $row );
		$expected = filter_var( $input['expected_version'] ?? null, FILTER_VALIDATE_INT );
		if ( false === $expected || $expected !== (int) $row['expected_version'] ) {
			throw new Http_Error( 409, 'Team changed; reload and try again.' );
		}
		$existing = $this->revision_picks( (int) ( $row['draft_revision_id'] ?: $row['submitted_revision_id'] ?: 0 ) );
		$this->validate_picks( $picks, $season, $existing );

		$this->db->transaction( function () use ( $account, $picks, $season, $expected ): void {
			$pdo = $this->db->pdo();
			// The deadline governs the transaction's start instant, not the request's:
			// a write that begins before the deadline but commits after it is late.
			$this->require_open( $season, $this->clock->instant() );
			$row = $this->entry_for_account( (int) $account['id'], $season );
			if ( $expected !== (int) $row['expected_version'] ) {
				throw new Http_Error( 409, 'Team changed; reload and try again.' );
			}
			$this->require_member( $row );
			$amending = 'submitted' === $row['state'];
			if ( $amending && null !== $row['submitted_revision_id'] ) {
				$existing = $this->revision_picks( (int) $row['submitted_revision_id'] );
				$this->validate_picks( $picks, $season, $existing );
			}
			if ( 'refuse' === Entry_Rules::save_effect( (string) $row['state'], true ) ) {
				throw new Http_Error( 409, 'Entry cannot be amended.' );
			}
			if ( $amending && null !== $row['submitted_revision_id'] ) {
				$pdo->prepare( "UPDATE entry_revisions SET kind = 'superseded' WHERE id = ? AND entry_id = ? AND kind = 'submitted'" )
					->execute( array( $row['submitted_revision_id'], $row['entry_id'] ) );
			}
			$id = $this->create_revision( (int) $row['entry_id'], $amending ? 'submitted' : 'draft', $picks, $amending ? $this->clock->now() : null, null );
			$stmt = $pdo->prepare( 'UPDATE entries SET state = ?, draft_revision_id = ?, submitted_revision_id = ?, expected_version = expected_version + 1, updated_at = ? WHERE id = ? AND expected_version = ?' );
			$stmt->execute( array( $amending ? 'submitted' : 'draft', $amending ? null : $id, $amending ? $id : null, $this->clock->now(), $row['entry_id'], $expected ) );
			if ( 1 !== $stmt->rowCount() ) {
				throw new Http_Error( 409, 'Team changed; reload and try again.' );
			}
		} );
		return $this->view( $account, true );
	}

	/** Submit the current draft, replaying the same idempotency key safely. */
	public function submit( array $account, array $input ): array {
		$key = trim( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( '' === $key || strlen( $key ) > 100 ) {
			throw new Http_Error( 400, 'A submission key is required.' );
		}
		$season = (int) ( $input['season'] ?? $this->clock->season_now() );
		// The lock is authoritative: refuse a locked season before looking up (and
		// lazily creating) the entry, so a rejected post-lock submit writes nothing.
		$this->require_open( $season );
		$row = $this->entry_for_account( (int) $account['id'], $season );
		$this->require_member( $row );
		$stmt = $this->db->pdo()->prepare( 'SELECT id FROM entry_revisions WHERE entry_id = ? AND idempotency_key = ?' );
		$stmt->execute( array( $row['entry_id'], $key ) );
		$receipt_revision = $stmt->fetchColumn();
		$stmt->closeCursor();
		if ( false !== $receipt_revision ) {
			if ( 'submitted' === $row['state'] && (int) $receipt_revision === (int) $row['submitted_revision_id'] ) {
				return $this->view( $account, false, true );
			}
			throw new Http_Error( 409, 'This submission key has already been used.' );
		}
		if ( 'draft' !== $row['state'] || null === $row['draft_revision_id'] ) {
			throw new Http_Error( 409, 'Save a valid team before submitting.' );
		}
		$picks = $this->revision_picks( (int) $row['draft_revision_id'] );
		Entry_Rules::validate_picks( $picks );
		$this->validate_picks( $picks, $season, $picks );

		$this->db->transaction( function () use ( $account, $season, $key ): void {
			$pdo = $this->db->pdo();
			// The deadline governs the transaction's start instant, not the request's.
			$this->require_open( $season, $this->clock->instant() );
			$row = $this->entry_for_account( (int) $account['id'], $season );
			if ( 'draft' !== $row['state'] || null === $row['draft_revision_id'] ) {
				throw new Http_Error( 409, 'This draft changed before submission; reload it.' );
			}
			$this->require_member( $row );
			$picks = $this->revision_picks( (int) $row['draft_revision_id'] );
			Entry_Rules::validate_picks( $picks );
			$this->validate_picks( $picks, $season, $picks );
			$submitted_at = $this->clock->now();
			$id = $this->create_revision( (int) $row['entry_id'], 'submitted', $picks, $submitted_at, $key );
			$stmt = $pdo->prepare( 'UPDATE entries SET state = ?, draft_revision_id = NULL, submitted_revision_id = ?, expected_version = expected_version + 1, updated_at = ? WHERE id = ? AND expected_version = ? AND state = ?' );
			$stmt->execute( array( 'submitted', $id, $submitted_at, $row['entry_id'], $row['expected_version'], 'draft' ) );
			if ( 1 !== $stmt->rowCount() ) {
				throw new Http_Error( 409, 'Team changed before submission; reload it.' );
			}
		} );
		return $this->view( $account, false, true );
	}

	private function entry_for_account( int $account, int $season ): array {
		$pdo = $this->db->pdo();
		$stmt = $pdo->prepare( 'SELECT l.id AS league_id, l.season, m.status, e.id AS entry_id, e.state, e.expected_version, e.draft_revision_id, e.submitted_revision_id FROM leagues l JOIN league_members m ON m.league_id = l.id AND m.account_id = ? LEFT JOIN entries e ON e.league_id = l.id AND e.account_id = ? WHERE l.season = ? AND l.kind = \'main\'' );
		$stmt->execute( array( $account, $account, $season ) );
		$row = $stmt->fetch();
		if ( ! $row ) {
			throw new Http_Error( 404, 'No main league exists for that season.' );
		}
		if ( null === $row['entry_id'] ) {
			$pdo->prepare( 'INSERT INTO entries (league_id, account_id, state, ruleset_version, season, expected_version, updated_at) VALUES (?, ?, \'draft\', ?, ?, 1, ?) ON CONFLICT (league_id, account_id) DO NOTHING' )
				->execute( array( $row['league_id'], $account, Ruleset::VERSION, $season, $this->clock->now() ) );
			$stmt->execute( array( $account, $account, $season ) );
			$row = $stmt->fetch();
		}
		return $row;
	}

	private function revision_picks( int $revision ): array {
		if ( $revision < 1 ) {
			return array();
		}
		$stmt = $this->db->pdo()->prepare( 'SELECT person_uuid FROM entry_picks WHERE revision_id = ? ORDER BY slot' );
		$stmt->execute( array( $revision ) );
		return array_map( 'strval', $stmt->fetchAll( \PDO::FETCH_COLUMN ) );
	}

	private function validate_picks( array $picks, int $season, array $previously_selected = array() ): void {
		$previously_selected = array_fill_keys( $previously_selected, true );
		foreach ( $picks as $uuid ) {
			$person = $this->catalog->find( (string) $uuid );
			if ( ! $person ) {
				throw new Http_Error( 422, 'One or more picks are not eligible for this season.' );
			}
			if ( ! isset( $previously_selected[ $uuid ] ) && ! $this->catalog->selectable( $person, $season ) ) {
				throw new Http_Error( 422, 'One or more picks are not eligible for this season.' );
			}
		}
	}

	private function create_revision( int $entry, string $kind, array $picks, ?string $submitted_at, ?string $key ): int {
		$pdo = $this->db->pdo();
		$pdo->prepare( 'INSERT INTO entry_revisions (entry_id, kind, ruleset_version, idempotency_key, submitted_at, created_at) VALUES (?, ?, ?, ?, ?, ?)' )
			->execute( array( $entry, $kind, Ruleset::VERSION, $key, $submitted_at, $this->clock->now() ) );
		$id = (int) $pdo->lastInsertId();
		$insert = $pdo->prepare( 'INSERT INTO entry_picks (revision_id, slot, person_uuid) VALUES (?, ?, ?)' );
		foreach ( $picks as $slot => $uuid ) {
			$insert->execute( array( $id, $slot + 1, $uuid ) );
		}
		return $id;
	}

	private function require_member( array $entry ): void {
		if ( 'member' !== $entry['status'] ) {
			throw new Http_Error( 403, 'Spectators cannot edit or submit a team.' );
		}
	}

	private function require_open( int $season, ?\DateTimeImmutable $at = null ): void {
		if ( $season !== $this->clock->season_now() ) {
			throw new Http_Error( 409, 'Only the current season accepts changes.' );
		}
		if ( ! Deadline_Policy::commit_on_time( $season, $at ?? $this->clock->instant() ) ) {
			throw new Http_Error( 409, 'The season entry window is closed.' );
		}
	}
}

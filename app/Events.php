<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Value\Cause_Status;

/**
 * Owns the reported-death events table and the editorial review transitions.
 *
 * A report is not a fact: it is a person's death claim awaiting review. Only
 * approval publishes the death to the catalogue and fans it out to the award
 * ledger; retraction withdraws it, and a correction supersedes the prior
 * revision so the ledger reverses the old date and awards the new one. Cause is
 * an editorial decision carried on the event but never affects points.
 */
final class Events {
	public function __construct( private Database $db, private Catalog $catalog, private Awards $awards, private Clock $clock ) {
	}

	/** Record a reported death, or correct the report already on file. */
	public function report( array $input ): array {
		$uuid = trim( (string) ( $input['person_uuid'] ?? '' ) );
		$date = self::valid_date( (string) ( $input['death_date'] ?? '' ) );
		$person = $this->catalog->find( $uuid );
		if ( ! $person ) {
			throw new Http_Error( 404, 'No such person.' );
		}
		if ( null === $date ) {
			throw new Http_Error( 422, 'A death date in YYYY-MM-DD form is required.' );
		}
		$cause_status = self::valid_status( (string) ( $input['cause_status'] ?? Cause_Status::NOT_DISCLOSED ) );
		$cause = trim( (string) ( $input['cause'] ?? '' ) );
		if ( Cause_Status::CONFIRMED === $cause_status && '' === $cause ) {
			throw new Http_Error( 422, 'A confirmed cause requires wording.' );
		}
		$reporter = filter_var( $input['reporter_account_id'] ?? null, FILTER_VALIDATE_INT );
		$reporter = false === $reporter ? null : (int) $reporter;

		return $this->db->transaction( function () use ( $uuid, $date, $cause_status, $cause, $reporter ): array {
			$existing = $this->find( $uuid );
			if ( $existing && 'approved' === (string) $existing['status'] ) {
				// An approved death is changed through the correction path, never by a
				// fresh report; refuse rather than silently supersede it here.
				throw new Http_Error( 409, 'That death is already approved; correct it instead of re-reporting.' );
			}
			if ( $existing ) {
				$this->update( (int) $existing['id'], $date, $cause_status, $cause, 'reported', (int) $existing['revision'] + 1, $reporter );
				return array( 'status' => 'reported' );
			}
			$this->insert( $uuid, $date, $cause_status, $cause, $reporter );
			return array( 'status' => 'reported' );
		} );
	}

	/**
	 * Correct an approved death's date or cause.
	 *
	 * A date change is a new event revision: the prior award is reversed and the
	 * new date awarded. A cause-only change is editorial and must leave the
	 * revision and every award row untouched.
	 */
	public function correct( int $id, array $input, int $operator ): array {
		$date = self::valid_date( (string) ( $input['death_date'] ?? '' ) );
		if ( null === $date ) {
			throw new Http_Error( 422, 'A death date in YYYY-MM-DD form is required.' );
		}
		$cause_status = self::valid_status( (string) ( $input['cause_status'] ?? Cause_Status::NOT_DISCLOSED ) );
		$cause = trim( (string) ( $input['cause'] ?? '' ) );
		if ( Cause_Status::CONFIRMED === $cause_status && '' === $cause ) {
			throw new Http_Error( 422, 'A confirmed cause requires wording.' );
		}

		return $this->db->transaction( function () use ( $id, $date, $cause_status, $cause, $operator ): array {
			$event = $this->require( $id );
			if ( 'approved' !== (string) $event['status'] ) {
				throw new Http_Error( 409, 'Only an approved death can be corrected.' );
			}
			if ( $date === (string) $event['death_date'] ) {
				$this->update( $id, $date, $cause_status, $cause, 'approved', (int) $event['revision'], null );
				$this->set_status( $id, 'approved', $operator );
				return array( 'status' => 'recommented', 'revision' => (int) $event['revision'] );
			}
			$this->update( $id, $date, $cause_status, $cause, 'approved', (int) $event['revision'] + 1, null );
			$this->set_status( $id, 'approved', $operator );
			$this->publish( (string) $event['person_uuid'], $date );
			$award = $this->awards->confirm( (string) $event['person_uuid'], $cause_status, '' === $cause ? null : $cause );
			if ( 'settled' === (string) $award['status'] ) {
				throw new Http_Error( 409, 'That season has settled; the date cannot be rescored.' );
			}
			return array( 'status' => 'corrected', 'revision' => (int) $event['revision'] + 1, 'award' => $award );
		} );
	}

	/** Approve a reported death, publishing it and awarding every picking team. */
	public function approve( int $id, int $operator ): array {
		return $this->db->transaction( function () use ( $id, $operator ): array {
			$event = $this->require( $id );
			if ( 'approved' === (string) $event['status'] ) {
				return array( 'status' => 'unchanged', 'award' => $this->awards->confirm( (string) $event['person_uuid'], (string) $event['cause_status'], $event['cause'] ) );
			}
			$this->set_status( $id, 'approved', $operator );
			$this->publish( (string) $event['person_uuid'], (string) $event['death_date'] );
			$award = $this->awards->confirm( (string) $event['person_uuid'], (string) $event['cause_status'], $event['cause'] );
			// A settled season refuses the award; throw so the whole approval rolls
			// back and nothing (status, published date, ledger row) is written.
			if ( 'settled' === (string) $award['status'] ) {
				throw new Http_Error( 409, 'That season has settled; no new award can be created.' );
			}
			return array( 'status' => 'approved', 'award' => $award );
		} );
	}

	/** Retract a death, withdrawing its award and returning the person to play. */
	public function retract( int $id, int $operator ): array {
		return $this->db->transaction( function () use ( $id, $operator ): array {
			$event = $this->require( $id );
			if ( 'approved' !== (string) $event['status'] ) {
				// A never-approved report has no published death or award to withdraw,
				// so retraction is refused rather than half-applied.
				throw new Http_Error( 409, 'Only an approved death can be retracted.' );
			}
			$this->set_status( $id, 'retracted', $operator );
			$this->unpublish( (string) $event['person_uuid'] );
			return array( 'status' => 'retracted', 'award' => $this->awards->confirm( (string) $event['person_uuid'] ) );
		} );
	}

	/**
	 * Every event that needs operator action: pending reports first, then
	 * approved deaths awaiting retraction or correction.
	 */
	public function board(): array {
		$stmt = $this->db->pdo()->prepare(
			"SELECT ev.id, ev.person_uuid, ev.death_date, ev.cause_status, ev.cause, ev.status, ev.revision, ev.created_at, p.name
			 FROM events ev JOIN people p ON p.uuid = ev.person_uuid
			 WHERE ev.status IN ('reported', 'approved')
			 ORDER BY CASE ev.status WHEN 'reported' THEN 0 ELSE 1 END, ev.created_at, ev.id"
		);
		$stmt->execute();
		$rows = $stmt->fetchAll();
		foreach ( $rows as &$row ) {
			// A death belongs to its death-date year's season; flag whether that
			// season has settled so the page can mark the event as unscorable.
			$row['settled'] = Deadline_Policy::is_settled( (int) substr( (string) $row['death_date'], 0, 4 ), $this->clock->instant() );
		}
		unset( $row );
		return $rows;
	}

	/** One event row, or null. */
	public function find( string $uuid ): ?array {
		$stmt = $this->db->pdo()->prepare( 'SELECT * FROM events WHERE person_uuid = ?' );
		$stmt->execute( array( $uuid ) );
		$row = $stmt->fetch();
		return $row ?: null;
	}

	private function require( int $id ): array {
		$stmt = $this->db->pdo()->prepare( 'SELECT * FROM events WHERE id = ?' );
		$stmt->execute( array( $id ) );
		$row = $stmt->fetch();
		if ( ! $row ) {
			throw new Http_Error( 404, 'No such event.' );
		}
		return $row;
	}

	private function insert( string $uuid, string $date, string $cause_status, string $cause, ?int $reporter ): void {
		$this->db->pdo()->prepare(
			'INSERT INTO events (person_uuid, death_date, cause_status, cause, status, revision, reporter_account_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)'
		)->execute( array( $uuid, $date, $cause_status, '' === $cause ? null : $cause, 'reported', $reporter, $this->clock->now(), $this->clock->now() ) );
	}

	private function update( int $id, string $date, string $cause_status, string $cause, string $status, int $revision, ?int $reporter ): void {
		$this->db->pdo()->prepare(
			'UPDATE events SET death_date = ?, cause_status = ?, cause = ?, status = ?, revision = ?, reporter_account_id = COALESCE(?, reporter_account_id), updated_at = ? WHERE id = ?'
		)->execute( array( $date, $cause_status, '' === $cause ? null : $cause, $status, $revision, $reporter, $this->clock->now(), $id ) );
	}

	private function set_status( int $id, string $status, int $operator ): void {
		$this->db->pdo()->prepare( 'UPDATE events SET status = ?, reviewed_by = ?, updated_at = ? WHERE id = ?' )
			->execute( array( $status, $operator, $this->clock->now(), $id ) );
	}

	/** Publish a verified death date onto the person, making it scoreable. */
	private function publish( string $uuid, string $date ): void {
		$this->db->pdo()->prepare( "UPDATE people SET death_date = ?, eligibility = 'approved' WHERE uuid = ?" )->execute( array( $date, $uuid ) );
	}

	/** Return a person to play by clearing the published death date. */
	private function unpublish( string $uuid ): void {
		$this->db->pdo()->prepare( 'UPDATE people SET death_date = NULL WHERE uuid = ?' )->execute( array( $uuid ) );
	}

	/** A valid exact YYYY-MM-DD calendar date, or null. */
	private static function valid_date( string $value ): ?string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match ) ) {
			return null;
		}
		if ( ! checkdate( (int) $match[2], (int) $match[3], (int) $match[1] ) ) {
			return null;
		}
		return $value;
	}

	/** One of the known cause statuses; anything else is treated as undisclosed. */
	private static function valid_status( string $status ): string {
		$known = array( Cause_Status::NOT_DISCLOSED, Cause_Status::PENDING, Cause_Status::CONFIRMED, Cause_Status::CONTESTED );
		return in_array( $status, $known, true ) ? $status : Cause_Status::NOT_DISCLOSED;
	}
}

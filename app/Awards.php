<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Scoring;
use Obitleague\Domain\Value\Awarded_Event;
use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;

/**
 * Owns the reversible award ledger.
 *
 * Awards are recorded per (team, pick, event revision), so a confirmed death
 * fans out to every submitted team that picked the person and scores only where
 * the death clears that team's own submission-instant floor. An amendment
 * therefore changes only the picks it changes: surviving picks keep their rows,
 * removed picks simply stop being counted, and a newly added pick is scored by
 * later confirmations under the team's new instant.
 *
 * The rows themselves are signed, append-only deltas (a correction or
 * withdrawal is a negative delta, never an edit) keyed for idempotent re-runs.
 */
final class Awards {
	public function __construct( private Database $db, private Catalog $catalog, private Clock $clock ) {
	}

	/**
	 * Confirm or withdraw a person's death and reconcile every team's award.
	 *
	 * The published cause is carried through to the scored event but never
	 * changes points; a corrected death date is a new event revision that
	 * supersedes (reverses) the earlier one. Callers that have no event row
	 * (the bare confirm path) pass nothing and get the undisclosed default.
	 *
	 * @return array{status:string, points?:int, reason?:string}
	 */
	public function confirm( string $uuid, string $cause_status = Cause_Status::NOT_DISCLOSED, ?string $cause = null ): array {
		return $this->db->transaction( function () use ( $uuid, $cause_status, $cause ): array {
			$person = $this->catalog->find( $uuid );
			if ( ! $person ) {
				throw new Http_Error( 404, 'No such person.' );
			}

			$death_text = (string) ( $person['death_date'] ?? '' );
			if ( 'approved' !== (string) $person['eligibility'] || '' === $death_text ) {
				// Withdrawal is always allowed, including after settlement, so a
				// pre-settlement award can still be reversed once a season is final.
				return array( 'status' => 0 < $this->withdraw( $uuid ) ? 'reversed' : 'unchanged' );
			}

			$award = $this->death_award( $person, $cause_status, $cause );
			if ( null === $award ) {
				return array( 'status' => 'held', 'reason' => 'Dates lack day precision; scoring waits.' );
			}

			// A settled season is final: refuse a NEW award before any row is written.
			// The pure season rule decides, so the boundary matches the read model.
			if ( Deadline_Policy::is_settled( (int) $award['season'], $this->clock->instant() ) ) {
				return array( 'status' => 'settled', 'reason' => 'The season has settled; no new award can be created.' );
			}

			$reversed = $this->reverse_other_revisions( $uuid, $award['revision'] );
			$written  = $this->fan_out( $uuid, (string) $person['name'], $award );

			if ( 0 === $written && 0 === $reversed ) {
				return array( 'status' => 'unchanged', 'points' => $award['points'] );
			}
			return array( 'status' => 'awarded', 'points' => $award['points'] );
		} );
	}

	/** The award a confirmed person earns, or null when scoring must be held. */
	private function death_award( array $person, string $cause_status = Cause_Status::NOT_DISCLOSED, ?string $cause = null ): ?array {
		$death = self::parse_date( (string) ( $person['death_date'] ?? '' ) );
		$birth = self::parse_date( (string) ( $person['birth_date'] ?? '' ) );
		if ( null === $death || null === $birth ) {
			return null;
		}

		// A published cause requires wording; a bare confirm has none to give, so
		// it falls back to the undisclosed status rather than an invalid event.
		if ( Cause_Status::CONFIRMED !== $cause_status || null === $cause || '' === trim( $cause ) ) {
			$cause_status = Cause_Status::NOT_DISCLOSED;
			$cause = null;
		}

		$event = new Awarded_Event(
			(string) $person['uuid'],
			(string) $person['uuid'],
			(string) $person['name'],
			$death,
			$cause_status,
			$cause,
			new DateTimeImmutable( $this->clock->now() )
		);
		$result = Scoring::evaluate( $event, $birth );
		if ( ! $result->is_scored() ) {
			return null;
		}

		return array(
			'season'   => (int) $death->year,
			'revision' => (string) $person['death_date'],
			'age'      => $result->award->completed_age,
			'points'   => $result->award->points,
		);
	}

	/** Award a confirmed death to every submitted team that picked the person. */
	private function fan_out( string $uuid, string $name, array $award ): int {
		$season   = $award['season'];
		$revision = $award['revision'];
		$stmt     = $this->db->pdo()->prepare(
			"SELECT e.id AS entry_id, r.submitted_at
			 FROM entries e
			 JOIN entry_revisions r ON r.id = e.submitted_revision_id
			 JOIN entry_picks p ON p.revision_id = e.submitted_revision_id
			 WHERE p.person_uuid = ? AND e.state = 'submitted' AND e.season = ?"
		);
		$stmt->execute( array( $uuid, $season ) );

		$written = 0;
		foreach ( $stmt->fetchAll() as $pick ) {
			$entry = (int) $pick['entry_id'];
			if ( $this->active( $entry, $uuid, $season, $revision ) > 0 ) {
				continue; // This team already holds the award; idempotent re-run.
			}
			if ( self::death_at( $revision ) < self::floor( $season, (string) $pick['submitted_at'] ) ) {
				continue; // The death predates this team's own submission instant.
			}
			$this->record( $entry, $uuid, $season, $revision, $award['age'], $award['points'], 'award', sprintf( 'Confirmed death of %s (age %d).', $name, $award['age'] ) );
			++$written;
		}
		return $written;
	}

	/** Reverse every active award for a person except one event revision. */
	private function reverse_other_revisions( string $uuid, string $keep ): int {
		return $this->reverse_matching( 'person_uuid = ? AND event_revision <> ?', array( $uuid, $keep ) );
	}

	/** Reverse every active award for a person across all teams and seasons. */
	private function withdraw( string $uuid ): int {
		return $this->reverse_matching( 'person_uuid = ?', array( $uuid ) );
	}

	/** Append exact negative deltas for every net-positive row matching a filter. */
	private function reverse_matching( string $where, array $args ): int {
		$stmt = $this->db->pdo()->prepare(
			'SELECT entry_id, person_uuid, season, event_revision, SUM(points_delta) AS net, MAX(completed_age) AS age
			 FROM awards WHERE ' . $where . ' GROUP BY entry_id, person_uuid, season, event_revision'
		);
		$stmt->execute( $args );
		$reversed = 0;
		foreach ( $stmt->fetchAll() as $row ) {
			if ( (int) $row['net'] <= 0 ) {
				continue;
			}
			$this->record( (int) $row['entry_id'], (string) $row['person_uuid'], (int) $row['season'], (string) $row['event_revision'], (int) $row['age'], -(int) $row['net'], 'void', 'Award reversed after a death change.' );
			++$reversed;
		}
		return $reversed;
	}

	/** Net points a team currently holds for one pick and event revision. */
	private function active( int $entry, string $uuid, int $season, string $revision ): int {
		$stmt = $this->db->pdo()->prepare( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards WHERE entry_id = ? AND person_uuid = ? AND season = ? AND event_revision = ?' );
		$stmt->execute( array( $entry, $uuid, $season, $revision ) );
		return (int) $stmt->fetchColumn();
	}

	/** Append one signed ledger row under a key unique to this operation. */
	private function record( int $entry, string $uuid, int $season, string $revision, int $age, int $delta, string $kind, string $reason ): void {
		$sequence = $this->db->pdo()->prepare( 'SELECT COUNT(*) FROM awards WHERE entry_id = ? AND person_uuid = ? AND season = ? AND event_revision = ?' );
		$sequence->execute( array( $entry, $uuid, $season, $revision ) );
		$key = md5( implode( '|', array( (string) $entry, $uuid, (string) $season, Ruleset::VERSION, $revision, $kind, (string) $sequence->fetchColumn() ) ) );
		$this->db->pdo()->prepare(
			'INSERT INTO awards (operation_key, entry_id, person_uuid, season, ruleset_version, event_revision, completed_age, points_delta, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(operation_key) DO NOTHING'
		)->execute( array( $key, $entry, $uuid, $season, Ruleset::VERSION, $revision, $age, $delta, $reason, $this->clock->now() ) );
	}

	/** Earliest death instant a team's submission may score. */
	private static function floor( int $season, string $submitted_at ): DateTimeImmutable {
		$submitted = '' !== $submitted_at
			? new DateTimeImmutable( $submitted_at, new DateTimeZone( 'UTC' ) )
			: Deadline_Policy::season_start( $season );
		return Deadline_Policy::death_scores_for_pick( $season, $submitted );
	}

	/** The verified death instant, compared at midnight as the ledger stores it. */
	private static function death_at( string $revision ): DateTimeImmutable {
		return new DateTimeImmutable( $revision . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	}

	private static function parse_date( string $value ): ?Partial_Date {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match ) ) {
			return null;
		}
		try {
			return new Partial_Date( (int) $match[1], (int) $match[2], (int) $match[3] );
		} catch ( InvalidArgumentException $error ) {
			return null;
		}
	}
}

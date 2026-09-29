<?php
/**
 * Entry service.
 *
 * Durable entry operations: optimistic save (expected_version), submission
 * with timestamped receipt, and lock-race safety. Commit-time rule: the
 * transaction start instant decides on-time vs late — a write whose commit
 * lands at or after 00:00 on 1 January is late regardless of request start.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Entry_Rules;
use Obitleague\Domain\Submission_Receipt;
use Obitleague\Domain\Value\Ruleset;

final class Entry_Service {

	/**
	 * Earliest death instant the entry's competing revision may score.
	 *
	 * With rolling entry a team's picks score only for deaths after its own
	 * submission instant; `submitted_at` on the competing revision is that
	 * instant, recorded server-side in UTC at commit time. Revisions written
	 * before the column was stamped (v1 entries) read as 1 January 00:00:00 —
	 * the season start — which keeps their scoring exactly as it was.
	 */
	public static function submission_floor( int $entry_id, int $season ): \DateTimeImmutable {
		$revision = self::submitted_revision( $entry_id );
		$raw = $revision ? (string) $revision->submitted_at : '';
		if ( '' === $raw || '0000-00-00 00:00:00' === $raw ) {
			return Deadline_Policy::season_start( $season );
		}
		try {
			return new \DateTimeImmutable( $raw, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return Deadline_Policy::season_start( $season );
		}
	}

	private function __construct() {}

	/** Get or create the caller's entry for a league+season. */
	public static function get_or_create_entry( int $league_id, int $season, int $user_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'obitleague_entries';
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE league_id = %d AND season = %d AND user_id = %d",
				$league_id,
				$season,
				$user_id
			)
		);
		$main_key = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT main_season_key FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d AND is_main = 1',
				$league_id
			)
		);
		if ( $id ) {
			if ( $main_key > 0 ) {
				$wpdb->query(
					$wpdb->prepare( "UPDATE {$table} SET main_season_key = %d WHERE id = %d AND main_season_key IS NULL", $main_key, $id )
				);
			}
			return $id;
		}

		if ( $main_key > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (league_id, season, user_id, main_season_key, team_name, state, expected_version, created_at)
					 VALUES (%d, %d, %d, %d, %s, %s, 1, %s)",
					$league_id,
					$season,
					$user_id,
					$main_key,
					'',
					Entry_Rules::DRAFT,
					current_time( 'mysql', true )
				)
			);
		} else {
			// Leave the nullable main-season key NULL on every side-league entry.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (league_id, season, user_id, team_name, state, expected_version, created_at)
					 VALUES (%d, %d, %d, %s, %s, 1, %s)",
					$league_id,
					$season,
					$user_id,
					'',
					Entry_Rules::DRAFT,
					current_time( 'mysql', true )
				)
			);
		}

		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE league_id = %d AND season = %d AND user_id = %d",
					$league_id, $season, $user_id
				)
			);
		}
		return $id;
	}

	/**
	 * Save a draft revision of picks with optimistic concurrency. Submitted
	 * teams may be amended before lock; the previous competing revision is
	 * retained as history and the amendment becomes the new competing one.
	 *
	 * @param string[] $picks Ten distinct person UUIDs.
	 * @return array{revision_id:int, expected_version:int, picks:string[], state:string}
	 */
	public static function save_draft( int $entry_id, int $user_id, array $picks, int $expected_version, ?string $team_name = null ): array {
		global $wpdb;

		$entry = self::require_entry( $entry_id, $user_id );
		if ( 'member' !== (string) League_Service::member_row( (int) $entry->league_id, $user_id )?->status ) {
			throw new Locked_Exception( 'Spectators cannot change team picks.' );
		}
		$normalised = Entry_Rules::validate_picks( $picks );
		$revision_id = 0;
		$was_submitted = false;
		$added_picks = array();
		$lock_name = 'obitleague_entry_' . $entry_id;
		$got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $got_lock ) {
			throw new Stale_Exception( 'Another team update is in progress — try again.' );
		}

		$transaction_started = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Could not start a team update transaction.' );
			}
			$transaction_started = true;
			$entry = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT e.* FROM {$wpdb->prefix}obitleague_entries e
					 JOIN {$wpdb->prefix}obitleague_league_members m ON m.league_id = e.league_id AND m.user_id = e.user_id AND m.status = 'member'
					 WHERE e.id = %d AND e.user_id = %d FOR UPDATE",
					$entry_id,
					$user_id
				)
			);
			if ( ! $entry || (int) $entry->expected_version !== $expected_version ) {
				throw new Stale_Exception( 'This team has changed — reload and try again.' );
			}
			if ( 'refuse' === Entry_Rules::save_effect(
				(string) $entry->state,
				Deadline_Policy::is_entry_open( (int) $entry->season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )
			) ) {
				throw new Locked_Exception( 'Entry is locked — picks can no longer be changed.' );
			}

			$was_submitted = Entry_Rules::SUBMITTED === (string) $entry->state;
			$previous_revision = $was_submitted ? self::submitted_revision( $entry_id ) : null;
			if ( $was_submitted && ! $previous_revision ) {
				throw new Stale_Exception( 'The current submitted team could not be loaded — reload and try again.' );
			}
			$previous_picks = $previous_revision
				? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, self::revision_picks( (int) $previous_revision->id ) )
				: array();
			$added_picks = array_values( array_diff( $normalised, $previous_picks ) );
			if ( $previous_revision ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}obitleague_entry_revisions SET kind = 'superseded' WHERE id = %d AND entry_id = %d AND kind = 'submitted'",
						(int) $previous_revision->id,
						$entry_id
					)
				);
			}

			$update_sql  = 'UPDATE ' . $wpdb->prefix . 'obitleague_entries SET expected_version = expected_version + 1, updated_at = %s';
			$update_args = array( current_time( 'mysql', true ) );
			if ( null !== $team_name ) {
				$update_sql   .= ', team_name = %s';
				$update_args[] = $team_name;
			}
			$update_sql .= ' WHERE id = %d AND expected_version = %d AND state = %s';
			array_push( $update_args, $entry_id, $expected_version, (string) $entry->state );
			if ( 1 !== (int) $wpdb->query( $wpdb->prepare( $update_sql, ...$update_args ) ) ) {
				throw new Stale_Exception( 'This team was changed elsewhere — reload and try again.' );
			}

			$kind = $was_submitted ? Entry_Rules::KIND_SUBMITTED : Entry_Rules::KIND_DRAFT;
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_entry_revisions (entry_id, kind, receipt_id, submitted_at, created_at)
					 VALUES (%d, %s, %s, %s, %s)',
					$entry_id,
					$kind,
					$was_submitted ? wp_generate_uuid4() : null,
					$was_submitted ? current_time( 'mysql', true ) : null,
					current_time( 'mysql', true )
				)
			);
			$revision_id = (int) $wpdb->insert_id;
			if ( ! $revision_id ) {
				throw new \RuntimeException( 'Could not create a team revision.' );
			}
			foreach ( $normalised as $slot => $uuid ) {
				if ( false === $wpdb->insert(
					$wpdb->prefix . 'obitleague_entry_picks',
					array( 'revision_id' => $revision_id, 'slot' => $slot + 1, 'person_uuid' => $uuid ),
					array( '%d', '%d', '%s' )
				) ) {
					throw new \RuntimeException( 'Could not save the complete team pick list.' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Could not commit the team revision.' );
			}
			$transaction_started = false;
		} catch ( \Throwable $exception ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			throw $exception;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		if ( $was_submitted ) {
			if ( $added_picks ) {
				self::award_events_for_added_picks( $entry_id, (int) $entry->season, $added_picks );
			}
			Jobs::schedule_standings_rebuild( (int) $entry->league_id, (int) $entry->season );
		}
		// An amendment rewrites the team's picks, so the cached distribution
		// behind person-page pick counts is now stale.
		Pick_Stats::flush();

		return array(
			'revision_id'      => $revision_id,
			'expected_version' => $expected_version + 1,
			'picks'            => $normalised,
			'state'            => (string) $entry->state,
		);
	}

	/**
	 * Submit the entry as the competing revision with a receipt. Advisory
	 * lock serialises concurrent submits; the deadline is re-checked inside
	 * the transaction so a write that starts before but commits after the
	 * deadline is refused.
	 *
	 * @param string[] $picks Ten distinct person UUIDs (validated again).
	 * @return Submission_Receipt
	 */
	public static function submit( int $entry_id, int $user_id, array $picks ): Submission_Receipt {
		global $wpdb;

		$entry = self::require_entry( $entry_id, $user_id );
		if ( 'member' !== (string) League_Service::member_row( (int) $entry->league_id, $user_id )?->status ) {
			throw new Locked_Exception( 'Spectators cannot change or submit team picks.' );
		}

		$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$open = Deadline_Policy::is_entry_open( (int) $entry->season, $now );
		if ( 'refuse' === Entry_Rules::submit_effect( (string) $entry->state, $open ) ) {
			throw new Locked_Exception( 'Entry is locked or already submitted.' );
		}

		$normalised = Entry_Rules::validate_picks( $picks );
		$lock_name  = 'obitleague_entry_' . $entry_id;
		$got_lock   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $got_lock ) {
			throw new Stale_Exception( 'Another submission for this entry is in progress — try again.' );
		}

		$transaction_started = false;
		try {
			$wpdb->query( 'START TRANSACTION' );
			$transaction_started = true;
			$txn_started = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

			$entry = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT e.* FROM {$wpdb->prefix}obitleague_entries e
					 JOIN {$wpdb->prefix}obitleague_league_members m ON m.league_id = e.league_id AND m.user_id = e.user_id AND m.status = 'member'
					 WHERE e.id = %d AND e.user_id = %d AND e.state = %s FOR UPDATE",
					$entry_id,
					$user_id,
					Entry_Rules::DRAFT
				)
			);
			if ( ! $entry ) {
				throw new Locked_Exception( 'Entry is no longer available for submission.' );
			}

			if ( ! Deadline_Policy::is_entry_open( (int) $entry->season, $txn_started ) ) {
				throw new Locked_Exception( 'The entry deadline has passed.' );
			}

			$receipt_id    = wp_generate_uuid4();
			$committed_gmt = $txn_started->format( 'Y-m-d H:i:s' );

			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_entry_revisions (entry_id, kind, receipt_id, submitted_at, created_at)
					 VALUES (%d, %s, %s, %s, %s)',
					$entry_id,
					Entry_Rules::KIND_SUBMITTED,
					$receipt_id,
					$committed_gmt,
					$committed_gmt
				)
			);
			$revision_id = (int) $wpdb->insert_id;
			if ( ! $revision_id ) {
				throw new \RuntimeException( 'Could not create a submitted team revision.' );
			}

			foreach ( $normalised as $slot => $uuid ) {
				if ( false === $wpdb->insert(
					$wpdb->prefix . 'obitleague_entry_picks',
					array( 'revision_id' => $revision_id, 'slot' => $slot + 1, 'person_uuid' => $uuid ),
					array( '%d', '%d', '%s' )
				) ) {
					throw new \RuntimeException( 'Could not save the complete submitted pick list.' );
				}
			}

			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}obitleague_entries SET state = 'submitted', updated_at = %s WHERE id = %d AND state = %s",
					$committed_gmt,
					$entry_id,
					Entry_Rules::DRAFT
				)
			);
			if ( 1 !== (int) $updated ) {
				throw new Stale_Exception( 'This entry was submitted elsewhere — reload to see its receipt.' );
			}

			$wpdb->query( 'COMMIT' );
			$transaction_started = false;

			// The pick distribution behind person-page pick counts is cached;
			// a new submitted team changes them.
			Pick_Stats::flush();

			return new Submission_Receipt(
				$receipt_id,
				$entry_id,
				$revision_id,
				(int) $entry->league_id,
				(int) $entry->season,
				$normalised,
				Ruleset::VERSION,
				$txn_started,
				// Receipt deadline is the instant this submission beat: 31 Dec
				// under rolling entry, 1 Jan for pre-flag submissions.
				Deadline_Policy::entry_deadline( (int) $entry->season )
			);
		} catch ( \Throwable $exception ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			throw $exception;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** Award approved events to new picks on an amended submitted team. */
	private static function award_events_for_added_picks( int $entry_id, int $season, array $added_picks ): void {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $added_picks ), '%s' ) );
		$events = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uuid FROM {$wpdb->prefix}obitleague_events WHERE person_uuid IN ({$placeholders}) AND approved_at IS NOT NULL AND retracted_at IS NULL",
				...$added_picks
			)
		);
		foreach ( (array) $events as $event_uuid ) {
			Outbox_Service::award_event( (string) $event_uuid, $entry_id );
		}
	}

	/** Latest submitted revision (the competing one), or null. */
	public static function submitted_revision( int $entry_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . "obitleague_entry_revisions
				 WHERE entry_id = %d AND kind = %s
				 ORDER BY id DESC LIMIT 1",
				$entry_id,
				Entry_Rules::KIND_SUBMITTED
			)
		);
		return $row ?: null;
	}

	/** The ten picks of a revision in slot order. */
	public static function revision_picks( int $revision_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT slot, person_uuid FROM ' . $wpdb->prefix . 'obitleague_entry_picks WHERE revision_id = %d ORDER BY slot ASC',
				$revision_id
			)
		);
	}

	/** Load an entry row owned by the user, or throw. */
	public static function require_entry( int $entry_id, int $user_id ): object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT e.* FROM ' . $wpdb->prefix . 'obitleague_entries e
				 JOIN ' . $wpdb->prefix . 'obitleague_league_members m ON m.league_id = e.league_id AND m.user_id = e.user_id
				 WHERE e.id = %d AND e.user_id = %d',
				$entry_id,
				$user_id
			)
		);
		if ( ! $row ) {
			throw new Locked_Exception( 'Entry not found for this player or league membership.' );
		}
		return $row;
	}
}

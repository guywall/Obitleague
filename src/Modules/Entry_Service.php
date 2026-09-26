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
		if ( $id ) {
			return $id;
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'obitleague_entries (league_id, season, user_id, state, expected_version, created_at)
				 VALUES (%d, %d, %d, %s, 1, %s)',
				$league_id,
				$season,
				$user_id,
				Entry_Rules::DRAFT,
				current_time( 'mysql', true )
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Save a draft revision of picks with optimistic concurrency.
	 *
	 * @param string[] $picks Ten distinct person UUIDs.
	 * @return array{revision_id:int, expected_version:int, picks:string[]}
	 */
	public static function save_draft( int $entry_id, int $user_id, array $picks, int $expected_version ): array {
		global $wpdb;

		$entry = self::require_entry( $entry_id, $user_id );

		$open = Deadline_Policy::is_entry_open( (int) $entry->season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		if ( 'refuse' === Entry_Rules::save_effect( (string) $entry->state, $open ) ) {
			throw new Locked_Exception( 'Entry is locked — picks can no longer be changed.' );
		}

		$normalised = Entry_Rules::validate_picks( $picks );

		$revision_id = 0;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'obitleague_entries
					 SET expected_version = expected_version + 1, updated_at = %s
					 WHERE id = %d AND expected_version = %d',
					current_time( 'mysql', true ),
					$entry_id,
					$expected_version
				)
			);
			if ( 1 !== (int) $updated ) {
				throw new Stale_Exception( 'This draft was changed elsewhere — reload and try again.' );
			}

			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_entry_revisions (entry_id, kind, created_at)
					 VALUES (%d, %s, %s)',
					$entry_id,
					Entry_Rules::KIND_DRAFT,
					current_time( 'mysql', true )
				)
			);
			$revision_id = (int) $wpdb->insert_id;

			foreach ( $normalised as $slot => $uuid ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO ' . $wpdb->prefix . 'obitleague_entry_picks (revision_id, slot, person_uuid)
						 VALUES (%d, %d, %s)',
						$revision_id,
						$slot + 1,
						$uuid
					)
				);
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return array(
			'revision_id'      => $revision_id,
			'expected_version' => $expected_version + 1,
			'picks'            => $normalised,
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

		$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$open = Deadline_Policy::is_entry_open( (int) $entry->season, $now );
		if ( 'refuse' === Entry_Rules::submit_effect( (string) $entry->state, $open ) ) {
			throw new Locked_Exception( 'Entry is locked or already submitted.' );
		}

		$normalised = Entry_Rules::validate_picks( $picks );

		$lock_name = 'obitleague_entry_' . $entry_id;
		$got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $got_lock ) {
			throw new Stale_Exception( 'Another submission for this entry is in progress — try again.' );
		}

		try {
			$txn_started = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

			$wpdb->query( 'START TRANSACTION' );

			// Commit-time rule: re-check inside the transaction.
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

			foreach ( $normalised as $slot => $uuid ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO ' . $wpdb->prefix . 'obitleague_entry_picks (revision_id, slot, person_uuid)
						 VALUES (%d, %d, %s)',
						$revision_id,
						$slot + 1,
						$uuid
					)
				);
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}obitleague_entries SET state = 'submitted', updated_at = %s WHERE id = %d",
					$committed_gmt,
					$entry_id
				)
			);

			$wpdb->query( 'COMMIT' );

			return new Submission_Receipt(
				$receipt_id,
				$entry_id,
				$revision_id,
				(int) $entry->league_id,
				(int) $entry->season,
				$normalised,
				Ruleset::VERSION,
				$txn_started,
				Deadline_Policy::entry_deadline( (int) $entry->season )
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
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
				'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d AND user_id = %d',
				$entry_id,
				$user_id
			)
		);
		if ( ! $row ) {
			throw new Locked_Exception( 'Entry not found for this player.' );
		}
		return $row;
	}
}

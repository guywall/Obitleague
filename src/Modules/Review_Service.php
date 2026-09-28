<?php
/**
 * Editorial review service.
 *
 * Review cases move through the pure state machine with stale-revision
 * protection. Approval publishes a death event and an outbox record in one
 * transaction; retraction queues the reversal. Person facts and selection
 * blocking follow the case state.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Review_Rules;
use Obitleague\Domain\Value\Partial_Date;

final class Review_Service {

	private function __construct() {}

	/** Open a review case for a person (from a feed candidate or a player report). */
	public static function open_case( string $person_uuid, string $note = '' ): int {
		global $wpdb;
		// One approved death per person: a retraction re-opens the same case
		// (RETRACTED > APPROVED), it never spawns a second approved event.
		if ( self::has_case_in_state( $person_uuid, Review_Rules::APPROVED ) ) {
			throw new InvalidArgumentException( 'This person already has an approved death event.' );
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'obitleague_review_cases (person_uuid, state, decision_reason, created_at)
				 VALUES (%s, %s, %s, %s)',
				$person_uuid,
				Review_Rules::PENDING,
				$note,
				current_time( 'mysql', true )
			)
		);

		return (int) $wpdb->insert_id;
	}

	/** True when the person has a case in the given state. */
	public static function has_case_in_state( string $person_uuid, string $state ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_review_cases WHERE person_uuid = %s AND state = %s LIMIT 1',
				$person_uuid,
				$state
			)
		);
	}

	/** True when the person has an unresolved review case (blocks selection). */
	public static function has_open_case( string $person_uuid ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_review_cases
				 WHERE person_uuid = %s AND state IN (%s, %s) LIMIT 1',
				$person_uuid,
				Review_Rules::PENDING,
				Review_Rules::APPROVED
			)
		);
	}

	/**
	 * Record an editor decision. $decision carries origin_groups,
	 * death_date {y,m,d}, cause_disclosed, cause_text, official_statement.
	 *
	 * @return array{state:string, event_uuid:?string}
	 */
	public static function decide( int $case_id, int $editor_id, string $to_state, array $decision, int $expected_revision ): array {
		global $wpdb;

		$cases = $wpdb->prefix . 'obitleague_review_cases';
		$case  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cases} WHERE id = %d", $case_id ) );
		if ( ! $case ) {
			throw new \InvalidArgumentException( 'Review case not found.' );
		}
		if ( ! Review_Rules::revision_current( $expected_revision, (int) $case->revision ) ) {
			throw new Stale_Exception( 'Another editor updated this case — reload and review again.' );
		}
		if ( ! Review_Rules::can_transition( (string) $case->state, $to_state ) ) {
			throw new \InvalidArgumentException( sprintf( 'Cannot move a case from %s to %s.', $case->state, $to_state ) );
		}

		if ( Review_Rules::APPROVED === $to_state ) {
			$problems = Review_Rules::approval_problems( $decision );
			if ( $problems ) {
				throw new \Obitleague\Domain\Invalid_Team_Exception( $problems );
			}
		}

		$now        = current_time( 'mysql', true );
		$event_uuid = null;

		$wpdb->query( 'START TRANSACTION' );
		try {
			$death_date = null;
			$precision  = 'unknown';
			if ( ! empty( $decision['death_date']['y'] ) ) {
				$d          = $decision['death_date'];
				$death_date = sprintf( '%04d-%02d-%02d', $d['y'], $d['m'] ?? 0, $d['d'] ?? 0 );
				$precision  = isset( $d['d'] ) ? 'exact' : 'month';
			}

			$cause_status = 'not_disclosed';
			$cause_text   = null;
			if ( ! empty( $decision['cause_disclosed'] ) ) {
				$cause_status = 'confirmed';
				$cause_text   = (string) ( $decision['cause_text'] ?? '' );
			}

			$new_revision = (int) $case->revision + 1;
			$event_uuid   = ( Review_Rules::APPROVED === $to_state ) ? wp_generate_uuid4() : (string) $case->event_uuid;

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$cases}
					 SET state = %s, death_date = %s, death_precision = %s, cause_status = %s, cause_text = %s,
					     origin_groups = %s, official_statement = %d, decision_reason = %s,
					     decided_by = %d, decided_at = %s, revision = %d, event_uuid = %s, updated_at = %s
					 WHERE id = %d AND revision = %d",
					$to_state,
					$death_date,
					$precision,
					$cause_status,
					$cause_text,
					wp_json_encode( array_values( $decision['origin_groups'] ?? array() ) ),
					! empty( $decision['official_statement'] ) ? 1 : 0,
					(string) ( $decision['reason'] ?? '' ),
					$editor_id,
					$now,
					$new_revision,
					$event_uuid,
					$now,
					$case_id,
					$expected_revision
				)
			);
			if ( 1 !== (int) $wpdb->rows_affected ) {
				throw new Stale_Exception( 'Case changed during the decision — retry with a fresh revision.' );
			}

			if ( Review_Rules::APPROVED === $to_state ) {
				self::write_event( $event_uuid, (string) $case->person_uuid, $death_date, $precision, $cause_status, $cause_text, $editor_id, $now );
				self::queue_outbox( 'death.approved', array( 'event_uuid' => $event_uuid, 'person_uuid' => (string) $case->person_uuid, 'case_id' => $case_id ) );
				self::publish_person_facts( (string) $case->person_uuid, $death_date, $precision, $cause_status, $cause_text );
			} elseif ( Review_Rules::RETRACTED === $to_state && $event_uuid ) {
				self::queue_outbox( 'death.retracted', array( 'event_uuid' => $event_uuid, 'person_uuid' => (string) $case->person_uuid, 'case_id' => $case_id ) );
				self::publish_person_facts( (string) $case->person_uuid, null, 'unknown', 'not_disclosed', null );
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return array( 'state' => $to_state, 'event_uuid' => $event_uuid );
	}

	/** Pending cases with person names, for the review queue. */
	public static function pending_cases( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, person_uuid, created_at, revision FROM ' . $wpdb->prefix . 'obitleague_review_cases
				 WHERE state = %s ORDER BY created_at ASC LIMIT %d',
				Review_Rules::PENDING,
				$limit
			)
		);
		foreach ( $rows as $row ) {
			$row->person_name = self::person_name( (string) $row->person_uuid );
		}
		return (array) $rows;
	}

	/** Write an approved event row (the only scoring input). */
	private static function write_event( string $event_uuid, string $person_uuid, ?string $death_date, string $precision, string $cause_status, ?string $cause_text, int $editor_id, string $now ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'obitleague_events (uuid, person_uuid, death_date, death_precision, cause_status, cause_text, approved_by, approved_at)
				 VALUES (%s, %s, %s, %s, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE death_date = VALUES(death_date), death_precision = VALUES(death_precision),
				     cause_status = VALUES(cause_status), cause_text = VALUES(cause_text), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at)',
				$event_uuid,
				$person_uuid,
				$death_date,
				$precision,
				$cause_status,
				$cause_text,
				$editor_id,
				$now
			)
		);
	}

	/** Queue an outbox notification/scoring event. */
	private static function queue_outbox( string $event_type, array $payload ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'obitleague_outbox (event_type, payload, created_at) VALUES (%s, %s, %s)',
				$event_type,
				wp_json_encode( $payload ),
				current_time( 'mysql', true )
			)
		);
	}

	/** Project approved facts onto the public person record. */
	private static function publish_person_facts( string $person_uuid, ?string $death_date, string $precision, string $cause_status, ?string $cause_text ): void {
		$post_id = self::person_post_id( $person_uuid );
		if ( ! $post_id ) {
			return;
		}
		update_post_meta( $post_id, 'obit_death_date', (string) $death_date );
		update_post_meta( $post_id, 'obit_death_precision', $precision );
		update_post_meta( $post_id, 'obit_cause_status', $cause_status );
		update_post_meta( $post_id, 'obit_cause_text', (string) $cause_text );
		// The public body states the death, its age and its points value, so it
		// has to be recomposed whenever those facts are projected.
		Person_Content::regenerate( $post_id );
	}

	/** Find a person post by internal UUID. */
	public static function person_post_id( string $person_uuid ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_uuid' AND meta_value = %s LIMIT 1",
				$person_uuid
			)
		);
	}

	/** Person display name for a UUID (for queues and receipts). */
	public static function person_name( string $person_uuid ): string {
		$post_id = self::person_post_id( $person_uuid );
		return $post_id ? (string) get_the_title( $post_id ) : $person_uuid;
	}
}

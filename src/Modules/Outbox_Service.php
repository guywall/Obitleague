<?php
/**
 * Outbox worker.
 *
 * Consumes published domain events and produces their durable effects:
 * approved deaths fan out to the award ledger (idempotent per pick/event),
 * retractions write exact reversals, and affected standings rebuild into new
 * generations. Every effect is idempotent — replaying the outbox changes
 * nothing.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Scoring;
use Obitleague\Domain\Value\Awarded_Event;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;

final class Outbox_Service {

	private function __construct() {}

	/** Process pending outbox events; returns the number processed. */
	public static function process_outbox( int $limit = 50 ): int {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event_type, payload FROM ' . $wpdb->prefix . 'obitleague_outbox
				 WHERE processed_at IS NULL ORDER BY id ASC LIMIT %d',
				$limit
			)
		);

		$processed = 0;
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row->payload, true );
			$affected = array();

			if ( 'death.approved' === $row->event_type ) {
				$affected = self::award_event( (string) ( $payload['event_uuid'] ?? '' ) );
			} elseif ( 'death.retracted' === $row->event_type ) {
				$affected = self::reverse_event( (string) ( $payload['event_uuid'] ?? '' ) );
			}

			foreach ( array_unique( $affected, SORT_REGULAR ) as $pair ) {
				Standings_Service::rebuild( (int) $pair[0], (int) $pair[1] );
			}

			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'obitleague_outbox SET processed_at = %s WHERE id = %d AND processed_at IS NULL',
					current_time( 'mysql', true ),
					(int) $row->id
				)
			);
			++$processed;
		}

		return $processed;
	}

	/**
	 * Fan one approved event out to every submitted pick of that person.
	 *
	 * @return array<int, array{0:int,1:int}> Affected (league_id, season) pairs.
	 */
	public static function award_event( string $event_uuid, int $only_entry_id = 0 ): array {
		global $wpdb;

		$event = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_events WHERE uuid = %s', $event_uuid )
		);
		if ( ! $event || null === $event->death_date ) {
			return array(); // Date unclear: published but held from scoring.
		}

		$birth = self::person_birth( (string) $event->person_uuid );
		if ( null === $birth ) {
			return array(); // Birth date not exact: hold, never guess an age.
		}

		$approved_at = new \DateTimeImmutable( (string) $event->approved_at, new \DateTimeZone( 'UTC' ) );
		$award_event = new Awarded_Event(
			$event_uuid,
			(string) $event->person_uuid,
			Review_Service::person_name( (string) $event->person_uuid ),
			self::partial_from_stored( (string) $event->death_date ),
			(string) $event->cause_status,
			$event->cause_text ? (string) $event->cause_text : null,
			$approved_at
		);

		$sql = 'SELECT e.id AS entry_id, e.league_id, e.season, p.person_uuid
			FROM ' . $wpdb->prefix . 'obitleague_entry_picks p
			JOIN ' . $wpdb->prefix . "obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
			JOIN " . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id
			WHERE p.person_uuid = %s';
		$args = array( (string) $event->person_uuid );
		if ( $only_entry_id > 0 ) {
			$sql .= ' AND e.id = %d';
			$args[] = $only_entry_id;
		}
		$picks = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

		$affected = array();
		foreach ( (array) $picks as $pick ) {
			$season = (int) $pick->season;

			// Already dead at lock: zero points, entry keeps the pick and the
			// explanation — no award for a death before the entry deadline.
			$death_at = new \DateTimeImmutable( (string) $event->death_date . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
			if ( $death_at < Deadline_Policy::entry_deadline( $season ) ) {
				continue;
			}

			if ( ! Deadline_Policy::accepts_new_awards( $season, $approved_at, (int) \Obitleague\Support\Options::get( 'first_season', 2027 ) ) ) {
				continue; // Settled or pre-game season: archive updates, no new award.
			}

			$result = Scoring::evaluate( $award_event, $birth );
			if ( ! $result->is_scored() ) {
				continue;
			}

			Scoring_Service::record_award(
				Scoring_Service::operation_key( (int) $pick->entry_id, (string) $pick->person_uuid, $season, $event_uuid ),
				(int) $pick->entry_id,
				(string) $pick->person_uuid,
				$season,
				$event_uuid,
				$result->award->points,
				sprintf( 'Confirmed death of %s (age %d).', $award_event->display_name, $result->award->completed_age )
			);

			$affected[] = array( (int) $pick->league_id, $season );
		}

		return $affected;
	}

	/**
	 * Reverse every award of a retracted event with exact negative deltas.
	 * Corrections remain possible after settlement, so no season check here.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function reverse_event( string $event_uuid ): array {
		global $wpdb;

		$awards = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT entry_id, pick_slug, season, award_delta FROM ' . $wpdb->prefix . 'obitleague_awards
				 WHERE event_uuid = %s AND award_delta > 0',
				$event_uuid
			)
		);

		$affected = array();
		foreach ( (array) $awards as $award ) {
			$season = (int) $award->season;
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_awards
					 (operation_key, entry_id, pick_slug, season, ruleset, event_uuid, award_delta, reason, created_at)
					 VALUES (%s, %d, %s, %d, %s, %s, %d, %s, %s)
					 ON DUPLICATE KEY UPDATE id = id',
					md5( implode( '|', array( (string) $award->entry_id, (string) $award->pick_slug, (string) $season, Ruleset::VERSION, $event_uuid, 'reversal' ) ) ),
					(int) $award->entry_id,
					(string) $award->pick_slug,
					$season,
					Ruleset::VERSION,
					$event_uuid,
					-1 * (int) $award->award_delta,
					'Event retracted — points reversed.',
					current_time( 'mysql', true )
				)
			);
			$affected[] = array( (int) $wpdb->get_var( 'SELECT league_id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = ' . (int) $award->entry_id ), $season );
		}

		return $affected;
	}

	/** Exact birth date of a person, or null (holds scoring). */
	private static function person_birth( string $person_uuid ): ?Partial_Date {
		$post_id = Review_Service::person_post_id( $person_uuid );
		if ( ! $post_id ) {
			return null;
		}
		$raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		if ( '' === $raw ) {
			return null;
		}
		try {
			$birth = Catalogue::partial_date_from_stored( $raw );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
		return $birth->is_exact() ? $birth : null;
	}

	/** Parse a stored Y-m-d value. */
	private static function partial_from_stored( string $value ): Partial_Date {
		return Catalogue::partial_date_from_stored( $value );
	}
}

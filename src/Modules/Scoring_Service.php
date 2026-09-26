<?php
/**
 * Scoring service.
 *
 * Domain evaluation is pure; this service adds the durable, idempotent
 * ledger. Every award is a signed row keyed by (entry, pick, season,
 * ruleset, event revision) so retries and replays change nothing, and every
 * correction is a new delta — never an in-place edit.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Scoring;
use Obitleague\Domain\Value\Awarded_Event;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;
use Obitleague\Support\Time;

final class Scoring_Service {

	private function __construct() {}

	/**
	 * Apply one approved event to a set of picks.
	 *
	 * @param Awarded_Event                       $event Editor-approved death event.
	 * @param array<int, array{entry_id:int, pick_slug:string, season:int, birth:Partial_Date}> $picks Affected picks.
	 * @return array{written:int, held:int, skipped_settled:int}
	 */
	public static function apply_approved_event( Awarded_Event $event, array $picks ): array {
		$written         = 0;
		$held            = 0;
		$skipped_settled = 0;

		foreach ( $picks as $pick ) {
			$result = Scoring::evaluate( $event, $pick['birth'] );
			if ( ! $result->is_scored() ) {
				++$held;
				continue;
			}

			$season = $pick['season'];
			if ( ! Time::accepts_new_awards( $season, $event->approved_at ) ) {
				// Season settled (or not a game season): the archive still
				// updates; no new award is created.
				++$skipped_settled;
				continue;
			}

			$ok = self::record_award(
				operation_key: self::operation_key( $pick['entry_id'], $pick['pick_slug'], $season, $event->event_id ),
				entry_id: $pick['entry_id'],
				pick_slug: $pick['pick_slug'],
				season: $season,
				event_uuid: $event->event_id,
				award_delta: $result->award->points,
				reason: sprintf( 'Confirmed death of %s (age %d).', $event->display_name, $result->award->completed_age )
			);
			if ( $ok ) {
				++$written;
			}
		}

		return array(
			'written'         => $written,
			'held'            => $held,
			'skipped_settled' => $skipped_settled,
		);
	}

	/**
	 * Idempotent ledger insert. Returns false when the operation key already
	 * exists (retry, replay or a concurrent worker won the race).
	 */
	public static function record_award(
		string $operation_key,
		int $entry_id,
		string $pick_slug,
		int $season,
		string $event_uuid,
		int $award_delta,
		string $reason
	): bool {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . "obitleague_awards
					(operation_key, entry_id, pick_slug, season, ruleset, event_uuid, award_delta, reason, created_at)
				VALUES (%s, %d, %s, %d, %s, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE id = id",
				$operation_key,
				$entry_id,
				$pick_slug,
				$season,
				Ruleset::VERSION,
				$event_uuid,
				$award_delta,
				$reason,
				current_time( 'mysql', true )
			)
		);

		return 1 === (int) $inserted; // 0 rows = duplicate = already recorded.
	}

	/** Stable idempotency key for one pick/event/ruleset combination. */
	public static function operation_key( int $entry_id, string $pick_slug, int $season, string $event_uuid ): string {
		return md5( implode( '|', array( (string) $entry_id, $pick_slug, (string) $season, Ruleset::VERSION, $event_uuid ) ) );
	}
}

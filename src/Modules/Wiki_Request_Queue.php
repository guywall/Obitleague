<?php
/**
 * Global Wikimedia request queue.
 *
 * Every outbound call to Wikidata/Wikipedia that the plugin defers goes
 * through this table instead of firing inline: the request is stored, then
 * processed serially (oldest first) by the cron tick or on demand, honouring
 * the shared Wikimedia rate-limit pause. Requests made during a cooldown are
 * kept pending — never lost — and run when the pause lifts.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Wiki_Request_Queue {

	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_DONE       = 'done';
	public const STATUS_FAILED     = 'failed';

	private const MAX_ATTEMPTS   = 5;
	private const CRON_HOOK      = 'obitleague_wiki_queue_tick';
	private const CRON_BATCH     = 3;
	private const WALL_BUDGET_S  = 20.0;

	/** @var array<string, callable> request_kind => handler( array $payload ): array|\WP_Error */
	private static array $handlers = array();

	private function __construct() {}

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_scheduled' ) );
	}

	/** Register the handler that executes one queued request kind. */
	public static function register_handler( string $kind, callable $handler ): void {
		self::$handlers[ $kind ] = $handler;
	}

	/**
	 * Store a request for serial processing. Deduplicated on
	 * (kind, dedupe_key) while a copy is still pending, so repeated
	 * triggers during a cooldown collapse into one queued request.
	 *
	 * @return int Row id of the pending (or existing) request.
	 */
	public static function enqueue( string $kind, array $payload, string $dedupe_key = '' ): int {
		global $wpdb;

		$kind       = substr( sanitize_key( $kind ), 0, 40 );
		$dedupe_key = substr( trim( $dedupe_key ), 0, 191 );
		$table      = $wpdb->prefix . 'obitleague_wiki_queue';

		if ( '' !== $dedupe_key ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE request_kind = %s AND dedupe_key = %s AND status = %s LIMIT 1",
					$kind,
					$dedupe_key,
					self::STATUS_PENDING
				)
			);
			if ( $existing > 0 ) {
				return $existing;
			}
		}

		$wpdb->insert(
			$table,
			array(
				'request_kind' => $kind,
				'payload'      => wp_json_encode( $payload ),
				'dedupe_key'   => $dedupe_key,
				'status'       => self::STATUS_PENDING,
				'attempts'     => 0,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Process queued requests serially until the batch or wall-clock budget
	 * is exhausted. A Wikimedia rate-limit pause parks everything: rows stay
	 * pending and are retried after the pause lifts.
	 *
	 * @return int Number of requests completed.
	 */
	public static function process( int $max = 10 ): int {
		global $wpdb;

		if ( Discovery_Service::rate_limit_pause_until() > time() ) {
			return 0; // Cooldown: keep every request queued, run nothing now.
		}

		$table    = $wpdb->prefix . 'obitleague_wiki_queue';
		$done     = 0;
		$deadline = microtime( true ) + self::WALL_BUDGET_S;

		while ( $done < $max && microtime( true ) < $deadline ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, request_kind, payload, attempts FROM {$table}
					 WHERE status = %s ORDER BY id ASC LIMIT 1",
					self::STATUS_PENDING
				)
			);
			if ( ! $row ) {
				break;
			}

			// Claim: pending → processing, bumping the attempt counter.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, attempts = attempts + 1 WHERE id = %d AND status = %s",
					self::STATUS_PROCESSING,
					(int) $row->id,
					self::STATUS_PENDING
				)
			);
			if ( 1 !== (int) $claimed ) {
				continue; // Another worker took it; look at the next row.
			}

			$handler = self::$handlers[ (string) $row->request_kind ] ?? null;
			$payload = json_decode( (string) $row->payload, true );
			if ( ! is_callable( $handler ) || ! is_array( $payload ) ) {
				self::fail( (int) $row->id, 'No handler registered for this request kind.' );
				continue;
			}

			$result = $handler( $payload );
			if ( is_wp_error( $result ) ) {
				if ( 'obitleague_discovery_paused' === $result->get_error_code() ) {
					// Cooldown began mid-run: park the request for later.
					self::park( (int) $row->id, $result->get_error_message() );
					break;
				}
				if ( ( (int) $row->attempts + 1 ) >= self::MAX_ATTEMPTS ) {
					self::fail( (int) $row->id, $result->get_error_message() );
				} else {
					self::park( (int) $row->id, $result->get_error_message() );
				}
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'status'       => self::STATUS_DONE,
					'processed_at' => current_time( 'mysql', true ),
					'last_error'   => null,
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			++$done;
		}

		return $done;
	}

	/** Cron entry point: a small bounded pass every minute. */
	public static function run_scheduled(): void {
		self::process( self::CRON_BATCH );
	}

	/** Queue snapshot for admin screens. */
	public static function status(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_wiki_queue';
		$counts = array();
		foreach (
			(array) $wpdb->get_results(
				"SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status"
			) as $row
		) {
			$counts[ (string) $row->status ] = (int) $row->n;
		}
		$next = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, request_kind, created_at, attempts FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT 1",
				self::STATUS_PENDING
			)
		);
		return array(
			'counts' => $counts,
			'next'   => $next,
		);
	}

	private static function park( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'obitleague_wiki_queue',
			array(
				'status'     => self::STATUS_PENDING,
				'last_error' => substr( sanitize_text_field( $error ), 0, 1000 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function fail( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'obitleague_wiki_queue',
			array(
				'status'       => self::STATUS_FAILED,
				'last_error'   => substr( sanitize_text_field( $error ), 0, 1000 ),
				'processed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}
}

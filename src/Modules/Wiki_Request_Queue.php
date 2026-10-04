<?php
/**
 * Global Wikimedia request queue.
 *
 * Every outbound call to Wikidata/Wikipedia that the plugin defers goes
 * through this table instead of firing inline: the request is stored, then
 * processed serially (oldest first) by the cron tick or on demand.
 *
 * Pacing model (per source):
 * - Each row carries `source` (e.g. 'wikidata', 'enwiki', 'commons') and a
 *   `next_attempt_at` timestamp. A row is only eligible while now >=
 *   next_attempt_at.
 * - A Wikimedia rate-limit response (Retry-After) sets the queue-wide
 *   next-try timestamp for the affected source to the moment the limit
 *   lifts; every later retry keeps that timestamp until either the limit
 *   lifts and requests flow again, or the source hits a rate limit again.
 * - Between rate limits, rows are spaced by a minimum per-source interval
 *   so steady-state traffic stays inside Wikimedia's recommended rates.
 *
 * Requests made during a cooldown are kept pending — never lost — and run
 * when the pause lifts.
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

	private const CRON_HOOK      = 'obitleague_wiki_queue_tick';
	private const CRON_BATCH     = 3;
	private const WALL_BUDGET_S  = 20.0;
	private const CLAIM_TTL_S    = 900; // 15 min: beyond any handler budget.

	/**
	 * Minimum gap between two requests to the same source, in seconds, so
	 * steady-state traffic stays well inside Wikimedia's recommended rate
	 * (roughly 200 req/s ceiling — we use a fraction of one request/second).
	 * Sources not listed default to 1.0 s.
	 */
	private const SOURCE_MIN_INTERVAL_S = array(
		'wikidata' => 1.0,
		'enwiki'   => 1.0,
		'commons'  => 1.0,
	);

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
	public static function enqueue( string $kind, array $payload, string $dedupe_key = '', string $source = 'wikidata' ): int {
		global $wpdb;

		$kind       = substr( sanitize_key( $kind ), 0, 40 );
		$dedupe_key = substr( trim( $dedupe_key ), 0, 191 );
		$source     = substr( sanitize_key( $source ), 0, 20 );
		$table      = $wpdb->prefix . 'obitleague_wiki_queue';

		if ( '' !== $dedupe_key ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE request_kind = %s AND dedupe_key = %s AND status IN ( %s, %s ) LIMIT 1",
					$kind,
					$dedupe_key,
					self::STATUS_PENDING,
					self::STATUS_PROCESSING
				)
			);
			if ( $existing > 0 ) {
				return $existing;
			}
		}

		$wpdb->insert(
			$table,
			array(
				'request_kind'    => $kind,
				'payload'         => wp_json_encode( $payload ),
				'dedupe_key'      => $dedupe_key,
				'source'          => $source,
				'status'          => self::STATUS_PENDING,
				'attempts'        => 0,
				'next_attempt_at' => self::next_available_time( $source ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Timestamp of the earliest moment the given source may be hit again:
	 * the later of the source's rate-limit pause (if any) and one minimum
	 * interval after the last queued request for that source.
	 */
	public static function next_available_time( string $source ): string {
		global $wpdb;
		$table      = $wpdb->prefix . 'obitleague_wiki_queue';
		$pause_until = self::source_pause_until( $source );

		$last  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(next_attempt_at) FROM {$table} WHERE source = %s AND status IN ( %s, %s )",
				$source,
				self::STATUS_PENDING,
				self::STATUS_PROCESSING
			)
		);
		$gap = (int) round( (float) ( self::SOURCE_MIN_INTERVAL_S[ $source ] ?? 1.0 ) );

		// The datetime arithmetic itself lives in the pure pause policy so it
		// can be tested without WordPress — see Wire_Pause::next_attempt_at().
		return \Obitleague\Domain\Wire_Pause::next_attempt_at(
			$last ? (string) $last : '',
			$gap,
			current_time( 'mysql', true ),
			$pause_until
		);
	}

	/**
	 * Unix timestamp until which the given source is parked by a rate
	 * limit, 0 when clear. Stored as one option per source so the pause
	 * applies to direct callers too.
	 */
	public static function source_pause_until( string $source ): int {
		return (int) get_option( 'obitleague_wq_pause_' . $source, 0 );
	}

	/**
	 * Record a rate-limit response: park the source until the given
	 * Retry-After moment, and push every still-pending row for that source
	 * to the same timestamp. Until the source hits a rate limit again,
	 * retries keep targeting this moment.
	 */
	public static function defer_source( string $source, string $retry_after ): int {
		global $wpdb;
		$retry_after = trim( $retry_after );
		$until       = is_numeric( $retry_after )
			? time() + max( 5, (int) $retry_after )
			: ( false !== strtotime( $retry_after ) ? strtotime( $retry_after ) : time() + 60 );
		$until       = max( time() + 5, (int) $until );

		update_option( 'obitleague_wq_pause_' . $source, $until, false );

		$mysql_until = gmdate( 'Y-m-d H:i:s', $until );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}obitleague_wiki_queue
				 SET next_attempt_at = %s
				 WHERE source = %s AND status = %s AND next_attempt_at < %s",
				$mysql_until,
				substr( sanitize_key( $source ), 0, 20 ),
				self::STATUS_PENDING,
				$mysql_until
			)
		);
		return $until;
	}

	/**
	 * Process queued requests serially until the batch or wall-clock budget
	 * is exhausted. Rows whose source is rate-limit-parked stay pending and
	 * are retried after the pause lifts.
	 *
	 * @return int Number of requests completed.
	 */
	public static function process( int $max = 10 ): int {
		global $wpdb;

		if ( Discovery_Service::rate_limit_pause_until() > time() ) {
			return 0; // Shared Wikimedia cooldown: keep every request queued.
		}

		$table    = $wpdb->prefix . 'obitleague_wiki_queue';
		$now_sql  = current_time( 'mysql', true );

		// Reclaim rows stuck in 'processing' — a fatal or timeout between the
		// claim and the handler finishing would otherwise strand them forever.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, claimed_at = NULL
				 WHERE status = %s AND claimed_at IS NOT NULL AND claimed_at < %s",
				self::STATUS_PENDING,
				self::STATUS_PROCESSING,
				gmdate( 'Y-m-d H:i:s', time() - self::CLAIM_TTL_S )
			)
		);

		$done     = 0;
		$handled  = 0;
		$deadline = microtime( true ) + self::WALL_BUDGET_S;

		// The per-call budget counts every row we handle, not just the ones
		// that succeed. Counting successes alone let a single failing row be
		// re-selected again and again for the whole wall-clock window.
		while ( $handled < $max && microtime( true ) < $deadline ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, request_kind, payload, attempts, source
					 FROM {$table}
					 WHERE status = %s AND next_attempt_at <= %s
					 ORDER BY next_attempt_at ASC, id ASC
					 LIMIT 1",
					self::STATUS_PENDING,
					$now_sql
				)
			);
			if ( ! $row ) {
				break;
			}

			// Claim: pending → processing, bumping the attempt counter.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, attempts = attempts + 1, claimed_at = %s WHERE id = %d AND status = %s",
					self::STATUS_PROCESSING,
					current_time( 'mysql', true ),
					(int) $row->id,
					self::STATUS_PENDING
				)
			);
			if ( 1 !== (int) $claimed ) {
				continue; // Another worker took it; look at the next row.
			}
			++$handled;

			$handler = self::$handlers[ (string) $row->request_kind ] ?? null;
			$payload = json_decode( (string) $row->payload, true );
			if ( ! is_callable( $handler ) || ! is_array( $payload ) ) {
				self::fail( (int) $row->id, 'No handler registered for this request kind.' );
				continue;
			}

			$result = $handler( $payload );

			if ( is_wp_error( $result ) ) {
				$code    = $result->get_error_code();
				// The counter after this claim, so both caps below see the true
				// attempt number rather than the pre-claim value.
				$retries = (int) $row->attempts + 1;
				if ( 'obitleague_discovery_paused' === $code || 'obitleague_rate_limited' === $code ) {
					// Rate limit: park the source until the Retry-After
					// moment and push every pending row for it out too.
					$retry = (string) ( $result->get_error_data( $code )['retry_after'] ?? '' );
					if ( '' === $retry ) {
						$retry = (string) ( (int) Discovery_Service::rate_limit_pause_until() - time() );
					}
					self::defer_source( (string) $row->source, $retry );
					// An upstream that answers nothing but 429/503 must not retry
					// forever; cap it like any other failure once the waits stop
					// making progress.
					if ( \Obitleague\Domain\Wiki_Queue_Policy::should_fail( $retries, true ) ) {
						self::fail( (int) $row->id, $result->get_error_message() );
					} else {
						self::park( (int) $row->id, $result->get_error_message() );
					}
					continue;
				}
				if ( \Obitleague\Domain\Wiki_Queue_Policy::should_fail( $retries, false ) ) {
					self::fail( (int) $row->id, $result->get_error_message() );
				} else {
					// Back off before the retry, so parking cannot leave the row
					// at the head of the queue where it would be picked straight
					// back up.
					self::park( (int) $row->id, $result->get_error_message(), \Obitleague\Domain\Wiki_Queue_Policy::retry_backoff_seconds( $retries ) );
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
				"SELECT id, request_kind, source, next_attempt_at, attempts FROM {$table} WHERE status = %s ORDER BY next_attempt_at ASC, id ASC LIMIT 1",
				self::STATUS_PENDING
			)
		);
		$pauses = array();
		foreach ( array_keys( self::SOURCE_MIN_INTERVAL_S ) as $src ) {
			$until = self::source_pause_until( $src );
			if ( $until > time() ) {
				$pauses[ $src ] = $until;
			}
		}
		return array(
			'counts' => $counts,
			'next'   => $next,
			'pauses' => $pauses,
		);
	}

	/**
	 * Return a row to the queue after a retryable failure. `$delay_seconds`
	 * pushes its next attempt into the future: without it the row kept its old
	 * next_attempt_at, so the queue's oldest-first scan re-selected it at once
	 * and burned a whole pass on one bad request.
	 */
	private static function park( int $id, string $error, int $delay_seconds = 0 ): void {
		global $wpdb;
		$data   = array(
			'status'     => self::STATUS_PENDING,
			'last_error' => substr( sanitize_text_field( $error ), 0, 1000 ),
		);
		$format = array( '%s', '%s' );
		if ( $delay_seconds > 0 ) {
			$data['next_attempt_at'] = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );
			$format[]                = '%s';
		}
		$wpdb->update(
			$wpdb->prefix . 'obitleague_wiki_queue',
			$data,
			array( 'id' => $id ),
			$format,
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

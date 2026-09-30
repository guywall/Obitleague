<?php
/**
 * Background jobs.
 *
 * Registers the cron schedules and runs the discovery pipeline's polling
 * stage: conditional fetches (ETag / Last-Modified), bounded timeouts, GUID
 * deduplication and per-source failure tracking. The pipeline stops here —
 * items become review candidates; only an editor publishes a death.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Feed_Classifier;

final class Jobs {

	private const HOOK_FEED_POLL   = 'obitleague_feed_poll';
	private const HOOK_REFRESH     = 'obitleague_profile_refresh';
	private const HOOK_OUTBOX_TICK = 'obitleague_outbox_tick';
	private const HOOK_STANDINGS_REBUILD = 'obitleague_standings_rebuild';
	private const HOOK_WIKI_QUEUE  = 'obitleague_wiki_queue_tick';
	private const HOOK_DISCOVERY_TICK    = 'obitleague_discovery_tick';
	private const HOOK_DEATH_WIRE_TICK  = 'obitleague_death_wire_tick';

	private function __construct() {}

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( self::HOOK_FEED_POLL, array( self::class, 'poll_feeds' ) );
		add_action( self::HOOK_REFRESH, array( self::class, 'refresh_profiles' ) );
		add_action( self::HOOK_OUTBOX_TICK, array( self::class, 'outbox_tick' ) );
		add_action( self::HOOK_STANDINGS_REBUILD, array( self::class, 'rebuild_standings' ), 10, 2 );
		add_action( self::HOOK_WIKI_QUEUE, array( self::class, 'run_wiki_queue' ) );
		add_action( self::HOOK_DISCOVERY_TICK, array( self::class, 'run_discovery_tick' ) );
		add_action( self::HOOK_DEATH_WIRE_TICK, array( self::class, 'run_death_wire_tick' ) );
		add_action( 'obitleague_main_user_backfill', array( Main_League_Service::class, 'run_user_backfill' ), 10, 2 );

		// Self-healing schedule: upgrades on existing installs never run
		// activate(), so the ticks are (re)armed here on every request.
		if ( ! \wp_next_scheduled( self::HOOK_WIKI_QUEUE ) ) {
			\wp_schedule_event( time() + 120, 'obitleague_1min', self::HOOK_WIKI_QUEUE );
		}
		if ( ! \wp_next_scheduled( self::HOOK_DISCOVERY_TICK ) ) {
			\wp_schedule_event( time() + 300, 'hourly', self::HOOK_DISCOVERY_TICK );
		}
		if ( ! \wp_next_scheduled( self::HOOK_DEATH_WIRE_TICK ) ) {
			\wp_schedule_event( time() + 600, 'hourly', self::HOOK_DEATH_WIRE_TICK );
		}
	}

	/** @param array<string, array{interval:int, display:string}> $schedules */
	public static function schedules( array $schedules ): array {
		$schedules['obitleague_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Obitleague feed polling)', 'obitleague' ),
		);
		$schedules['obitleague_1min'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Obitleague outbox)', 'obitleague' ),
		);
		return $schedules;
	}

	/**
	 * Poll every enabled source. One pass, bounded per source; failed sources
	 * back off by being skipped after repeated failures (re-enabled manually
	 * or by the maintenance job).
	 */
	public static function poll_feeds(): void {
		foreach ( self::enabled_sources() as $source ) {
			if ( $source->consecutive_failures >= 3 ) {
				continue; // Circuit breaker: three failed polls stop the source.
			}
			self::poll_source( $source );
		}
	}

	/**
	 * Fetch one feed and ingest new items.
	 *
	 * @param object $source Row from the sources table.
	 * @return array{status:string, new_items:int}
	 */
	public static function poll_source( object $source ): array {
		global $wpdb;

		$headers = array( 'timeout' => 10 );
		if ( ! empty( $source->etag ) ) {
			$headers['headers']['If-None-Match'] = $source->etag;
		}
		if ( ! empty( $source->last_modified ) ) {
			$headers['headers']['If-Modified-Since'] = $source->last_modified;
		}

		$response = \wp_remote_get( $source->feed_url, $headers );

		if ( \is_wp_error( $response ) ) {
			self::record_poll( $source, 'error', null, null );
			return array( 'status' => 'error', 'new_items' => 0 );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			self::record_poll( $source, 'not_modified', null, null );
			return array( 'status' => 'not_modified', 'new_items' => 0 );
		}
		if ( 200 !== $code ) {
			self::record_poll( $source, 'http_' . $code, null, null );
			return array( 'status' => 'http_' . $code, 'new_items' => 0 );
		}

		$body = (string) \wp_remote_retrieve_body( $response );
		$etag = \wp_remote_retrieve_header( $response, 'etag' );
		$last = \wp_remote_retrieve_header( $response, 'last-modified' );

		$new = self::ingest_items( (int) $source->id, $body );

		self::record_poll( $source, 'ok', '' !== (string) $etag ? (string) $etag : null, '' !== (string) $last ? (string) $last : null );

		return array( 'status' => 'ok', 'new_items' => $new );
	}

	/**
	 * Parse RSS 2.0 or Atom and store unseen items.
	 *
	 * @return int Number of newly stored items.
	 */
	private static function ingest_items( int $source_id, string $xml_body ): int {
		global $wpdb;

		$limit     = \apply_filters( 'obitleague_feed_ingest_byte_limit', 1024 * 1024 );
		$xml_body  = substr( $xml_body, 0, (int) $limit );
		$previous  = \libxml_use_internal_errors( true );
		$xml       = \simplexml_load_string( $xml_body );
		\libxml_clear_errors();
		\libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return 0;
		}

		$items = array();

		// RSS 2.0.
		foreach ( $xml->channel->item ?? array() as $item ) {
			$items[] = array(
				'guid' => (string) ( $item->guid ?? $item->link ?? '' ),
				'url'  => (string) ( $item->link ?? '' ),
				'title' => (string) ( $item->title ?? '' ),
				'date' => (string) ( $item->pubDate ?? '' ),
			);
		}

		// Atom.
		$atom = $xml->children( 'http://www.w3.org/2005/Atom' );
		foreach ( $atom->entry ?? array() as $entry ) {
			$items[] = array(
				'guid' => (string) ( $entry->id ?? '' ),
				'url'  => (string) ( isset( $entry->link['href'] ) ? $entry->link['href'] : '' ),
				'title' => (string) ( $entry->title ?? '' ),
				'date' => (string) ( $entry->updated ?? $entry->published ?? '' ),
			);
		}

		$table = $wpdb->prefix . 'obitleague_feed_items';
		$new   = 0;

		foreach ( $items as $item ) {
			if ( '' === $item['guid'] ) {
				continue;
			}
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE source_id = %d AND guid = %s LIMIT 1",
					$source_id,
					$item['guid']
				)
			);
			if ( $exists ) {
				continue;
			}
			$verdict = Feed_Classifier::classify( (string) $item['title'] );
			$cues    = array_merge(
				(array) $verdict['matched'],
				array_map( static fn ( string $cue ): string => '−' . $cue, (array) $verdict['negative'] )
			);
			$wpdb->insert(
				$table,
				array(
					'source_id'    => $source_id,
					'guid'         => $item['guid'],
					'url'          => $item['url'],
					'title'        => $item['title'],
					'classification' => $verdict['classification'],
					'classification_score' => (int) $verdict['score'],
					'matched_cues' => mb_substr( implode( ', ', $cues ), 0, 191 ),
					'published_at' => gmdate( 'Y-m-d H:i:s', (int) strtotime( $item['date'] ) ),
					'retrieved_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( 1 === (int) $wpdb->rows_affected ) {
				++$new;
			}
		}

		return $new;
	}

	/** @return object[] Enabled source rows. */
	private static function enabled_sources(): array {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$table = $wpdb->prefix . 'obitleague_sources';
		$cache = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY interval_minutes ASC" ) ?: array();
		return $cache;
	}

	private static function record_poll( object $source, string $status, ?string $etag, ?string $last_modified ): void {
		global $wpdb;

		$table    = $wpdb->prefix . 'obitleague_sources';
		$failures = ( 'ok' === $status || 'not_modified' === $status ) ? 0 : (int) $source->consecutive_failures + 1;

		$wpdb->update(
			$table,
			array(
				'last_poll_at'         => current_time( 'mysql', true ),
				'last_status'          => $status,
				'etag'                 => $etag ?? $source->etag,
				'last_modified'        => $last_modified ?? $source->last_modified,
				'consecutive_failures' => $failures,
			),
			array( 'id' => (int) $source->id ),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/** Placeholder until the enrichment module lands: refresh runs in batches. */
	public static function refresh_profiles(): void {
		do_action( 'obitleague_profile_refresh_tick' );
	}

	/** Coalesce standings refreshes so every team submission avoids a full rebuild. */
	public static function schedule_standings_rebuild( int $league_id, int $season ): void {
		if ( $league_id < 1 || $season < 2000 || $season > 2200 ) {
			return;
		}
		$args = array( $league_id, $season );
		if ( ! wp_next_scheduled( self::HOOK_STANDINGS_REBUILD, $args ) ) {
			wp_schedule_single_event( time() + 15, self::HOOK_STANDINGS_REBUILD, $args );
		}
	}

	/** Rebuild one dirty league/season outside the user's submission request. */
	public static function rebuild_standings( int $league_id, int $season ): void {
		Standings_Service::rebuild( $league_id, $season );
	}

	/** Drain the scoring/notification outbox every minute. */
	public static function outbox_tick(): void {
		Outbox_Service::process_outbox();
		do_action( 'obitleague_outbox_processed' );
	}

	/** Process stored Wikimedia requests serially every minute. */
	public static function run_wiki_queue(): void {
		Wiki_Request_Queue::process( 3 );
	}

	/** Hourly automatic Discovery batch: the catalogue fills itself. */
	public static function run_discovery_tick(): void {
		$result = Discovery_Service::run_automatic_batch();
		if ( is_wp_error( $result ) ) {
			error_log( 'Obitleague discovery tick: ' . $result->get_error_message() );
		}
	}

	/**
	 * Hourly death wire: enqueue the Wikipedia "Deaths in <year>" list pass
	 * and the RSS wire sweep. The handlers themselves run through the global
	 * Wikimedia request queue; this tick only primes it.
	 */
	public static function run_death_wire_tick(): void {
		Death_Wire::run();
	}
}

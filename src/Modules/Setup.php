<?php
/**
 * Activation, deactivation and upgrades.
 *
 * Creates the plugin's private tables and schedules the background jobs.
 * Deactivation is reversible housekeeping: data is never dropped on
 * deactivation.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Support\Options;

final class Setup {

	private function __construct() {}

	public static function activate(): void {
		// Activation runs after plugins_loaded, so boot the scheduler here to
		// make the custom recurrences available to wp_schedule_event().
		Jobs::boot();
		self::create_or_update_tables();
		Options::set( 'db_version', OBITLEAGUE_DB_VERSION );

		if ( ! \wp_next_scheduled( 'obitleague_feed_poll' ) ) {
			\wp_schedule_event( time() + 60, 'obitleague_5min', 'obitleague_feed_poll' );
		}
		if ( ! \wp_next_scheduled( 'obitleague_profile_refresh' ) ) {
			\wp_schedule_event( time() + 300, 'daily', 'obitleague_profile_refresh' );
		}
		if ( ! \wp_next_scheduled( 'obitleague_outbox_tick' ) ) {
			\wp_schedule_event( time() + 90, 'obitleague_1min', 'obitleague_outbox_tick' );
		}
	}

	public static function deactivate(): void {
		foreach ( array( 'obitleague_feed_poll', 'obitleague_profile_refresh', 'obitleague_outbox_tick' ) as $hook ) {
			$timestamp = \wp_next_scheduled( $hook );
			while ( false !== $timestamp ) {
				\wp_unschedule_event( $timestamp, $hook );
				$timestamp = \wp_next_scheduled( $hook );
			}
		}
	}

	/** Create or migrate the private game tables. */
	public static function create_or_update_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// Idempotent scoring ledger. One row per (pick, event revision, ruleset).
		$awards = "{$wpdb->prefix}obitleague_awards";
		// Editor-approved death events; the only scoring input.
		$events = "{$wpdb->prefix}obitleague_events";
		// Feed register and poll bookkeeping.
		$sources = "{$wpdb->prefix}obitleague_sources";
		// Raw feed items kept for review and dedup (retained 30 days).
		$feed_items = "{$wpdb->prefix}obitleague_feed_items";

		$sql = array();

		$sql[] = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			person_uuid CHAR(36) NOT NULL,
			death_date DATE NULL,
			death_precision VARCHAR(12) NOT NULL DEFAULT 'unknown',
			cause_status VARCHAR(20) NOT NULL DEFAULT 'not_disclosed',
			cause_text TEXT NULL,
			approved_by BIGINT UNSIGNED NULL,
			approved_at DATETIME NULL,
			retracted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY person_uuid (person_uuid),
			KEY death_date (death_date)
		) {$charset};";

		$sql[] = "CREATE TABLE {$awards} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			operation_key VARCHAR(191) NOT NULL,
			entry_id BIGINT UNSIGNED NOT NULL,
			pick_slug VARCHAR(191) NOT NULL,
			season SMALLINT UNSIGNED NOT NULL,
			ruleset VARCHAR(8) NOT NULL DEFAULT '1',
			event_uuid CHAR(36) NOT NULL,
			award_delta INT NOT NULL,
			reason TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY operation_key (operation_key),
			KEY entry_season (entry_id, season)
		) {$charset};";

		$sql[] = "CREATE TABLE {$sources} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(191) NOT NULL,
			name VARCHAR(191) NOT NULL,
			feed_url TEXT NOT NULL,
			interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
			etag VARCHAR(255) NULL,
			last_modified VARCHAR(255) NULL,
			last_poll_at DATETIME NULL,
			last_status VARCHAR(20) NULL,
			consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			enabled TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		$sql[] = "CREATE TABLE {$feed_items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NOT NULL,
			guid VARCHAR(191) NOT NULL,
			url TEXT NULL,
			title TEXT NULL,
			classification VARCHAR(20) NOT NULL DEFAULT 'not_candidate',
			published_at DATETIME NULL,
			retrieved_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_guid (source_id, guid),
			KEY classification (classification)
		) {$charset};";

		foreach ( $sql as $statement ) {
			\dbDelta( $statement );
		}
	}
}

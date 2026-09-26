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
		// Private league identity, membership and entry data.
		$leagues = "{$wpdb->prefix}obitleague_leagues";
		$members = "{$wpdb->prefix}obitleague_league_members";
		$entries = "{$wpdb->prefix}obitleague_entries";
		$revisions = "{$wpdb->prefix}obitleague_entry_revisions";
		$picks = "{$wpdb->prefix}obitleague_entry_picks";
		// Editorial review cases and the notification outbox.
		$cases = "{$wpdb->prefix}obitleague_review_cases";
		$outbox = "{$wpdb->prefix}obitleague_outbox";
		// Published standings generations (rebuild-and-swap).
		$generations = "{$wpdb->prefix}obitleague_standings_generations";
		$srows = "{$wpdb->prefix}obitleague_standings_rows";

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

		$sql[] = "CREATE TABLE {$leagues} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL,
			season SMALLINT UNSIGNED NOT NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL,
			state VARCHAR(12) NOT NULL DEFAULT 'open',
			invite_hash CHAR(64) NULL,
			invite_expires_at DATETIME NULL,
			invite_revoked TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY owner_user (owner_user_id),
			KEY season (season)
		) {$charset};";

		$sql[] = "CREATE TABLE {$members} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			league_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'member',
			joined_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY league_user (league_id, user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$entries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			league_id BIGINT UNSIGNED NOT NULL,
			season SMALLINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			state VARCHAR(12) NOT NULL DEFAULT 'draft',
			expected_version INT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY league_season_user (league_id, season, user_id),
			KEY user_season (user_id, season)
		) {$charset};";

		$sql[] = "CREATE TABLE {$revisions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(12) NOT NULL DEFAULT 'draft',
			receipt_id CHAR(36) NULL,
			submitted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY entry_kind (entry_id, kind)
		) {$charset};";

		$sql[] = "CREATE TABLE {$picks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			revision_id BIGINT UNSIGNED NOT NULL,
			slot TINYINT UNSIGNED NOT NULL,
			person_uuid CHAR(36) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY revision_slot (revision_id, slot),
			KEY person (person_uuid)
		) {$charset};";

		$sql[] = "CREATE TABLE {$cases} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			person_uuid CHAR(36) NULL,
			state VARCHAR(12) NOT NULL DEFAULT 'pending',
			death_date DATE NULL,
			death_precision VARCHAR(12) NOT NULL DEFAULT 'unknown',
			cause_status VARCHAR(20) NOT NULL DEFAULT 'not_disclosed',
			cause_text TEXT NULL,
			origin_groups TEXT NULL,
			official_statement TINYINT(1) NOT NULL DEFAULT 0,
			decision_reason TEXT NULL,
			decided_by BIGINT UNSIGNED NULL,
			decided_at DATETIME NULL,
			revision INT UNSIGNED NOT NULL DEFAULT 1,
			event_uuid CHAR(36) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY person_state (person_uuid, state),
			KEY state (state)
		) {$charset};";

		$sql[] = "CREATE TABLE {$outbox} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(40) NOT NULL,
			payload TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY processed (processed_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$generations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			league_id BIGINT UNSIGNED NOT NULL,
			season SMALLINT UNSIGNED NOT NULL,
			is_current TINYINT(1) NOT NULL DEFAULT 0,
			published_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY league_season (league_id, season, is_current)
		) {$charset};";

		$sql[] = "CREATE TABLE {$srows} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			generation_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			points INT NOT NULL DEFAULT 0,
			scoring_picks INT NOT NULL DEFAULT 0,
			rank_pos INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY generation (generation_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			\dbDelta( $statement );
		}
	}
}

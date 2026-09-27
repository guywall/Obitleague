<?php
/** Canonical all-player league, separate from optional side leagues. */
declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Deadline_Policy;

final class Main_League_Service {

	private const BACKFILL_HOOK = 'obitleague_main_user_backfill';
	private const BACKFILL_BATCH = 400;

	private function __construct() {}

	/** Ensure the unique canonical league for a season exists. */
	public static function ensure_league( int $season ): int {
		if ( $season < 2000 || $season > 2200 ) {
			throw new \InvalidArgumentException( 'A valid season is required for the main league.' );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_leagues';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (name, season, owner_user_id, state, is_main, main_season_key, created_at)
				 VALUES (%s, %d, 0, %s, 1, %d, %s)",
				'Overall League ' . $season,
				$season,
				League_Service::STATE_OPEN,
				$season,
				current_time( 'mysql', true )
			)
		);
		$id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE main_season_key = %d LIMIT 1", $season )
		);
		if ( ! $id ) {
			throw new \RuntimeException( 'Could not create the canonical main league.' );
		}
		return $id;
	}

	/** Ensure a user has their independent main entry and membership. */
	public static function ensure_user_entry( int $user_id, int $season ): array {
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			throw new \InvalidArgumentException( 'A valid WordPress user is required for the main entry.' );
		}
		global $wpdb;
		$league_id = self::ensure_league( $season );
		$members = $wpdb->prefix . 'obitleague_league_members';
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$status = Deadline_Policy::is_entry_open( $season, $now ) ? 'member' : 'spectator';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$members} (league_id, user_id, status, joined_at) VALUES (%d, %d, %s, %s)",
				$league_id,
				$user_id,
				$status,
				$now->format( 'Y-m-d H:i:s' )
			)
		);
		$member_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$members} WHERE league_id = %d AND user_id = %d", $league_id, $user_id )
		);
		if ( ! $member_id ) {
			throw new \RuntimeException( 'Could not add the user to the main league.' );
		}
		$entry_id = Entry_Service::get_or_create_entry( $league_id, $season, $user_id );
		if ( ! $entry_id ) {
			throw new \RuntimeException( 'Could not create the main-season team entry.' );
		}
		return array( 'league_id' => $league_id, 'entry_id' => $entry_id, 'season' => $season );
	}

	/** Provision newly registered accounts; never make registration fail. */
	public static function on_user_register( int $user_id ): void {
		try {
			self::ensure_user_entry( $user_id, League_Service::current_season() );
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Obitleague main entry provisioning failed: ' . $exception->getMessage() );
			}
		}
	}

	/** Queue a bounded backfill for existing accounts; safe to retry. */
	public static function schedule_existing_users( int $season ): void {
		$args = array( $season, 0 );
		if ( ! wp_next_scheduled( self::BACKFILL_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK, $args );
		}
	}

	/** Process a single indexed keyset batch and enqueue the next batch. */
	public static function provision_user_batch( int $season, int $after_user_id = 0 ): int {
		global $wpdb;
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
				max( 0, $after_user_id ),
				self::BACKFILL_BATCH
			)
		);
		if ( ! $user_ids ) {
			return 0;
		}
		$league_id = self::ensure_league( $season );
		self::insert_user_batch( $league_id, $season, array_map( 'intval', $user_ids ) );
		$last_id = (int) end( $user_ids );
		$next_args = array( $season, $last_id );
		if ( count( $user_ids ) === self::BACKFILL_BATCH && ! wp_next_scheduled( self::BACKFILL_HOOK, $next_args ) ) {
			wp_schedule_single_event( time() + 5, self::BACKFILL_HOOK, $next_args );
		}
		return count( $user_ids );
	}

	/** WP-Cron callback for an existing-user backfill batch. */
	public static function run_user_backfill( int $season, int $after_user_id = 0 ): void {
		try {
			self::provision_user_batch( $season, $after_user_id );
		} catch ( \Throwable $exception ) {
			error_log( 'Obitleague main-account backfill failed: ' . $exception->getMessage() );
		}
	}

	/** Explicit WP-CLI provisioning with bounded memory and indexed batches. */
	public static function provision_existing_users( int $season, int $batch_size = self::BACKFILL_BATCH ): int {
		global $wpdb;
		$batch_size = min( self::BACKFILL_BATCH, max( 1, $batch_size ) );
		$league_id = self::ensure_league( $season );
		$cursor = 0;
		$processed = 0;
		do {
			$user_ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d", $cursor, $batch_size )
			);
			if ( ! $user_ids ) {
				break;
			}
			self::insert_user_batch( $league_id, $season, array_map( 'intval', $user_ids ) );
			$cursor = (int) end( $user_ids );
			$processed += count( $user_ids );
		} while ( count( $user_ids ) === $batch_size );
		return $processed;
	}

	/** Bulk insert does not replace existing side entries, teams or submissions. */
	private static function insert_user_batch( int $league_id, int $season, array $user_ids ): void {
		global $wpdb;
		if ( ! $user_ids ) {
			return;
		}
		$members_table = $wpdb->prefix . 'obitleague_league_members';
		$entries_table = $wpdb->prefix . 'obitleague_entries';
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$status = Deadline_Policy::is_entry_open( $season, $now ) ? 'member' : 'spectator';
		$timestamp = $now->format( 'Y-m-d H:i:s' );
		$member_values = array();
		$member_args = array();
		$entry_values = array();
		$entry_args = array();
		$user_placeholders = array();
		foreach ( $user_ids as $user_id ) {
			$member_values[] = '(%d, %d, %s, %s)';
			array_push( $member_args, $league_id, $user_id, $status, $timestamp );
			$entry_values[] = '(%d, %d, %d, %d, %s, %s, 1, %s)';
			array_push( $entry_args, $league_id, $season, $user_id, $season, '', 'draft', $timestamp );
			$user_placeholders[] = '%d';
		}
		$member_result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$members_table} (league_id, user_id, status, joined_at) VALUES " . implode( ', ', $member_values ),
				...$member_args
			)
		);
		$entry_result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$entries_table} (league_id, season, user_id, main_season_key, team_name, state, expected_version, created_at) VALUES " . implode( ', ', $entry_values ),
				...$entry_args
			)
		);
		$update_result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$entries_table} SET main_season_key = %d WHERE league_id = %d AND season = %d AND main_season_key IS NULL AND user_id IN (" . implode( ',', $user_placeholders ) . ')',
				$season,
				$league_id,
				$season,
				...$user_ids
			)
		);
		if ( false === $member_result || false === $entry_result || false === $update_result ) {
			throw new \RuntimeException( 'Main-season provisioning failed: ' . $wpdb->last_error );
		}
	}
}

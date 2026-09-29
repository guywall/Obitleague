<?php
/**
 * Consolidate a season's competition into the canonical main league.
 *
 * For every non-demo user whose latest submitted side-league entry is not
 * already represented in the main league, move that entry (revisions, picks,
 * and awards travel with it) into the main league and add the membership.
 * Synthetic demo duplicates are left behind for retire-side-leagues.php to
 * remove. Idempotent: users already holding a main entry are skipped.
 *
 * Usage: wp eval-file tests/consolidate-main-league.php -- season=2026
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $argv, $wpdb;

$season = 0;
foreach ( (array) $argv as $arg ) {
	if ( is_string( $arg ) && preg_match( '/^(--)?season=(\d{4})$/', $arg, $m ) ) {
		$season = (int) $m[2];
	}
}
if ( $season < 2000 || $season > 2200 ) {
	echo "Usage: wp eval-file tests/consolidate-main-league.php -- season=2026\n";
	exit( 1 );
}

$main_league_id = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_leagues WHERE is_main = 1 AND main_season_key = %d LIMIT 1", $season )
);
if ( $main_league_id < 1 ) {
	echo "No main league for season {$season}; nothing to consolidate into.\n";
	exit( 1 );
}

// Real users with at least one submitted side-league entry this season.
$real_rows = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT DISTINCT e.user_id
		 FROM {$wpdb->prefix}obitleague_entries e
		 JOIN {$wpdb->prefix}obitleague_leagues l ON l.id = e.league_id AND l.is_main = 0 AND l.season = %d
		 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.entry_id = e.id AND r.kind = 'submitted'
		 LEFT JOIN {$wpdb->usermeta} m ON m.user_id = e.user_id AND m.meta_key = 'obitleague_demo_account' AND m.meta_value = '1'
		 WHERE m.meta_value IS NULL",
		$season
	)
);

echo "main league: {$main_league_id}; real users with side entries for {$season}: " . count( $real_rows ) . "\n";

$migrated = 0;
$skipped  = 0;
foreach ( $real_rows as $row ) {
	$user_id = (int) $row->user_id;

	// Already holding a main entry for this season? The entries table
	// enforces UNIQUE (main_season_key, user_id), so key the check on that.
	$existing = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_entries WHERE main_season_key = %d AND user_id = %d",
			$season,
			$user_id
		)
	);
	if ( $existing > 0 ) {
		++$skipped;
		continue;
	}

	// Latest submitted side entry becomes their main-league team.
	$entry_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT e.id
			 FROM {$wpdb->prefix}obitleague_entries e
			 JOIN {$wpdb->prefix}obitleague_leagues l ON l.id = e.league_id AND l.is_main = 0
			 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.entry_id = e.id AND r.kind = 'submitted'
			 WHERE e.user_id = %d AND e.season = %d
			 ORDER BY r.submitted_at DESC, e.id DESC
			 LIMIT 1",
			$user_id,
			$season
		)
	);
	if ( $entry_id < 1 ) {
		++$skipped;
		continue;
	}

	$wpdb->query( 'START TRANSACTION' );
	try {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}obitleague_entries SET league_id = %d, main_season_key = %d WHERE id = %d",
				$main_league_id,
				$season,
				$entry_id
			)
		);
		// Membership: add if absent, promote to full member either way.
		$member = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_league_members WHERE league_id = %d AND user_id = %d",
				$main_league_id,
				$user_id
			)
		);
		if ( $member < 1 ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}obitleague_league_members (league_id, user_id, status, joined_at)
					 VALUES (%d, %d, 'member', %s)",
					$main_league_id,
					$user_id,
					current_time( 'mysql', true )
				)
			);
		}
		$wpdb->query( 'COMMIT' );
		++$migrated;
		echo "migrated user {$user_id}: entry {$entry_id} -> main league {$main_league_id}\n";
	} catch ( Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		echo "FAILED migrating user {$user_id}: {$exception->getMessage()}\n";
		throw $exception;
	}
}

echo "entries migrated into the main league: {$migrated}; skipped (already there): {$skipped}\n";

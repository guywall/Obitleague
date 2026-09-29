<?php
/**
 * Retire side leagues for one season, keeping the canonical main league.
 *
 * Deletes each side league's standings (generations + rows), entries (with
 * revisions, picks, and awards), memberships, and the league row itself.
 * Users are kept: their canonical main-league entry (per user + season)
 * is untouched, so nobody loses their team. Idempotent: leagues already
 * gone are skipped.
 *
 * Usage: wp eval-file tests/retire-side-leagues.php -- season=2026
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
	echo "Usage: wp eval-file tests/retire-side-leagues.php -- season=2026\n";
	exit( 1 );
}

$main_league_id = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_leagues WHERE is_main = 1 AND main_season_key = %d LIMIT 1", $season )
);
if ( $main_league_id < 1 ) {
	echo "No main league for season {$season}; refusing to retire anything.\n";
	exit( 1 );
}

$side_ids = (array) $wpdb->get_col(
	$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_leagues WHERE is_main = 0 AND season = %d", $season )
);
echo "main league: {$main_league_id}; side leagues to retire for {$season}: " . count( $side_ids ) . "\n";

$deleted_entries = 0;
foreach ( array_map( 'intval', $side_ids ) as $league_id ) {
	$name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}obitleague_leagues WHERE id = %d", $league_id ) );

	$entry_ids = array_map( 'intval', (array) $wpdb->get_col(
		$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_entries WHERE league_id = %d", $league_id )
	) );

	$wpdb->query( 'START TRANSACTION' );
	try {
		foreach ( $entry_ids as $entry_id ) {
			$revision_ids = array_map( 'intval', (array) $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id = %d", $entry_id )
			) );
			foreach ( $revision_ids as $revision_id ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entry_picks WHERE revision_id = %d", $revision_id ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id = %d", $entry_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_awards WHERE entry_id = %d", $entry_id ) );
		}
		$deleted_entries += count( $entry_ids );

		$generation_ids = array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_standings_generations WHERE league_id = %d", $league_id )
		) );
		foreach ( $generation_ids as $generation_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_standings_rows WHERE generation_id = %d", $generation_id ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_standings_generations WHERE league_id = %d", $league_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_league_members WHERE league_id = %d", $league_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_leagues WHERE id = %d AND is_main = 0", $league_id ) );

		$wpdb->query( 'COMMIT' );
		echo "retired #{$league_id} {$name} (entries removed: " . count( $entry_ids ) . ")\n";
	} catch ( Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		echo "FAILED to retire #{$league_id} {$name}: {$exception->getMessage()}\n";
		throw $exception;
	}
}

echo "side-league entries removed: {$deleted_entries}\n";

// The main league's published standings stay as they are; the caller can
// rebuild them after re-seeding. Report the surviving shape.
$leagues_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_leagues WHERE season = %d", $season ) );
$main_entries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_entries WHERE league_id = %d", $main_league_id ) );
echo "leagues left for {$season}: {$leagues_left}; main league entries: {$main_entries}\n";

<?php
/**
 * Remove entries whose league row no longer exists (e.g. after retiring
 * side leagues). Their revisions/picks/awards are deleted with them if any
 * remain. Reports counts per season; idempotent.
 *
 * Usage: wp eval-file tests/cleanup-orphan-entries.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$orphan_ids = array_map( 'intval', (array) $wpdb->get_col(
	"SELECT e.id FROM {$wpdb->prefix}obitleague_entries e
	 LEFT JOIN {$wpdb->prefix}obitleague_leagues l ON l.id = e.league_id
	 WHERE l.id IS NULL"
) );

echo "orphaned entries (league row missing): " . count( $orphan_ids ) . "\n";
if ( ! $orphan_ids ) {
	echo "nothing to clean.\n";
	return;
}

$placeholders = implode( ',', array_fill( 0, count( $orphan_ids ), '%d' ) );

$season_counts = (array) $wpdb->get_results(
	"SELECT season, state, COUNT(*) AS n FROM {$wpdb->prefix}obitleague_entries WHERE id IN ( {$placeholders} ) GROUP BY season, state",
	ARRAY_A
);
foreach ( $season_counts as $row ) {
	echo sprintf( "  season %d · %s · %d\n", (int) $row['season'], (string) $row['state'], (int) $row['n'] );
}

$wpdb->query( 'START TRANSACTION' );
try {
	$wpdb->query( $wpdb->prepare( "DELETE r FROM {$wpdb->prefix}obitleague_entry_revisions r WHERE r.entry_id IN ( {$placeholders} )", ...$orphan_ids ) );
	$wpdb->query( $wpdb->prepare( "DELETE a FROM {$wpdb->prefix}obitleague_awards a WHERE a.entry_id IN ( {$placeholders} )", ...$orphan_ids ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entries WHERE id IN ( {$placeholders} )", ...$orphan_ids ) );
	$wpdb->query( 'COMMIT' );
	echo "deleted orphaned entries: " . count( $orphan_ids ) . "\n";
} catch ( Throwable $exception ) {
	$wpdb->query( 'ROLLBACK' );
	echo "FAILED: {$exception->getMessage()}\n";
	throw $exception;
}

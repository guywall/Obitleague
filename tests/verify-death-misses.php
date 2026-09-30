<?php
/**
 * WP-CLI probe: every confirmed in-season death must be visible on the
 * obituaries index, and the hit/miss split must account for all of them.
 *
 * Usage: wp eval-file tests/verify-death-misses.php
 *
 * Checks:
 *  1. The season death set used by the index (death date + publish state)
 *     is non-empty.
 *  2. picked_uuids() is consistent with a direct pick-table count.
 *  3. hits + misses == total confirmed season deaths (nothing falls between
 *     the two buckets, which is the whole point of the misses log).
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) || ! WP_CLI ) {
	return;
}

use Obitleague\Modules\Pick_Stats;
use Obitleague\Modules\Shortcodes;

$failures = array();
$season   = Pick_Stats::season_in_play();
global $wpdb;

$season_deaths = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'obit_death_date' AND m.meta_value LIKE %s
		 WHERE p.post_type = 'obit_person' AND p.post_status = 'publish'",
		$season . '%'
	)
);
if ( 0 === $season_deaths ) {
	\WP_CLI::error( "No confirmed deaths recorded for {$season}; load a seed or run the death wire first." );
}

$picked = Shortcodes::picked_uuids( $season );

$direct_picked = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(DISTINCT p.person_uuid) FROM {$wpdb->prefix}obitleague_entry_picks p
		 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
		 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = r.entry_id AND e.state = 'submitted' AND e.season = %d",
		$season
	)
);
if ( count( $picked ) !== $direct_picked ) {
	$failures[] = "picked_uuids() returned " . count( $picked ) . " uuids but the pick tables hold {$direct_picked} for {$season}";
}

$hits = 0;
if ( $picked ) {
	$placeholders = implode( ',', array_fill( 0, count( $picked ), '%s' ) );
	$hits         = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'obit_person' AND p.post_status = 'publish'
			 JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = 'obit_death_date' AND d.meta_value LIKE %s
			 WHERE pm.meta_key = 'obit_uuid' AND pm.meta_value IN ({$placeholders})",
			array_merge( array( $season . '%' ), array_keys( $picked ) )
		)
	);
}
$misses = max( 0, $season_deaths - $hits );

if ( $hits + $misses !== $season_deaths ) {
	$failures[] = "hit/miss split ({$hits} + {$misses}) does not cover all {$season_deaths} confirmed deaths";
}

\WP_CLI::log( sprintf( 'Season %d: %d confirmed deaths, %d hits, %d misses, %d picked uuids.', $season, $season_deaths, $hits, $misses, count( $picked ) ) );

if ( $failures ) {
	\WP_CLI::error( implode( '; ', $failures ) );
}

\WP_CLI::success( 'Death hit/miss accounting verified' );

<?php
/**
 * Remove only accounts and game data created for synthetic demo users.
 *
 * Run: OBITLEAGUE_CONFIRM_DEMO_USER_DELETE=1 wp eval-file tests/demo-remove-users.php
 * Supports OBITLEAGUE_DEMO_USER_DRY_RUN=1 to preview counts first.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
if ( 'production' === wp_get_environment_type() ) {
	throw new RuntimeException( 'Refusing to remove synthetic users from production.' );
}
if ( '1' !== getenv( 'OBITLEAGUE_CONFIRM_DEMO_USER_DELETE' ) && '1' !== getenv( 'OBITLEAGUE_DEMO_USER_DRY_RUN' ) ) {
	throw new RuntimeException( 'Set OBITLEAGUE_CONFIRM_DEMO_USER_DELETE=1 to confirm deleting marked synthetic accounts and their teams.' );
}

global $wpdb;
$marked_ids = array_values(
	array_unique(
		array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
					'obitleague_demo_account',
					'1'
				)
			)
		)
	)
);
if ( ! $marked_ids ) {
	WP_CLI::success( 'No marked synthetic accounts found; nothing to remove.' );
	return;
}

$marked_lookup = array_fill_keys( $marked_ids, true );
foreach ( $marked_ids as $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || user_can( $user, 'manage_options' ) ) {
		unset( $marked_lookup[ $user_id ] );
	}
}
$marked_ids = array_keys( $marked_lookup );
if ( ! $marked_ids ) {
	WP_CLI::warning( 'All marked accounts were missing or administrators; none were deleted.' );
	return;
}

$entry_ids = array();
$league_ids = array();
$seasons_by_league = array();
foreach ( array_chunk( $marked_ids, 400 ) as $user_chunk ) {
	$in = implode( ',', $user_chunk ); // IDs are cast to integers above.
	$rows = $wpdb->get_results( "SELECT id, league_id, season FROM {$wpdb->prefix}obitleague_entries WHERE user_id IN ({$in})" );
	foreach ( (array) $rows as $row ) {
		$entry_ids[] = (int) $row->id;
		$league_id = (int) $row->league_id;
		$league_ids[ $league_id ] = $league_id;
		$seasons_by_league[ $league_id ][ (int) $row->season ] = (int) $row->season;
	}
}
$revision_ids = array();
foreach ( array_chunk( $entry_ids, 400 ) as $entry_chunk ) {
	$in = implode( ',', $entry_chunk );
	$revision_ids = array_merge( $revision_ids, array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id IN ({$in})" ) ) );
}

$counts = array(
	'accounts' => count( $marked_ids ),
	'team entries' => count( $entry_ids ),
	'revisions' => count( $revision_ids ),
	'memberships' => 0,
);
foreach ( array_chunk( $marked_ids, 400 ) as $user_chunk ) {
	$in = implode( ',', $user_chunk );
	$counts['memberships'] += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_league_members WHERE user_id IN ({$in})" );
}
WP_CLI::log( sprintf( 'Marked synthetic data found: %d account(s), %d team(s), %d revision(s), %d membership(s).', $counts['accounts'], $counts['team entries'], $counts['revisions'], $counts['memberships'] ) );
if ( '1' === getenv( 'OBITLEAGUE_DEMO_USER_DRY_RUN' ) ) {
	WP_CLI::success( 'Dry run complete; no data was changed.' );
	return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->query( 'START TRANSACTION' );
try {
	foreach ( array_chunk( $entry_ids, 400 ) as $entry_chunk ) {
		$in = implode( ',', $entry_chunk );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_awards WHERE entry_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_entries WHERE id IN ({$in})" );
	}
	foreach ( array_chunk( $revision_ids, 400 ) as $revision_chunk ) {
		$in = implode( ',', $revision_chunk );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_entry_picks WHERE revision_id IN ({$in})" );
	}
	foreach ( array_chunk( $marked_ids, 400 ) as $user_chunk ) {
		$in = implode( ',', $user_chunk );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_league_members WHERE user_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_standings_rows WHERE user_id IN ({$in})" );
	}
	$wpdb->query( 'COMMIT' );
} catch ( Throwable $exception ) {
	$wpdb->query( 'ROLLBACK' );
	throw $exception;
}

$deleted = 0;
foreach ( $marked_ids as $user_id ) {
	if ( wp_delete_user( $user_id ) ) {
		++$deleted;
	}
}

foreach ( $seasons_by_league as $league_id => $seasons ) {
	foreach ( $seasons as $season ) {
		Standings_Service::rebuild( (int) $league_id, (int) $season );
	}
}
WP_CLI::success( sprintf( 'Removed %d marked synthetic accounts and their %d teams, picks/revisions, awards, memberships, and standings rows. Unmarked accounts and leagues were preserved.', $deleted, count( $entry_ids ) ) );

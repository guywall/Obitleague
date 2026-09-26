<?php
/**
 * E2E: league and entry lifecycle on a live WordPress install.
 *
 * Creates two synthetic users, runs create → invite → join → draft →
 * submit → receipt, checks ownership privacy and idempotent joins, and
 * prints a PASS/FAIL summary. Cleans up after itself.
 *
 * Run: wp eval-file <this-file>
 *
 * @package Obitleague
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Obitleague\Modules\Entry_Service;
use Obitleague\Modules\League_Service;
use Obitleague\Modules\Locked_Exception;
use Obitleague\Modules\Stale_Exception;

$results = array();
$step    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$results ): void {
	$results[] = array( $name, $ok, $detail );
	echo ( $ok ? '  ok  ' : 'FAIL  ' ) . $name . ( $detail ? " — {$detail}" : '' ) . "\n";
};

$owner_id = wp_insert_user(
	array(
		'user_login'   => 'obitleague_e2e_owner',
		'user_pass'    => wp_generate_password( 20 ),
		'user_email'   => 'obitleague-e2e-owner@example.test',
		'display_name' => 'E2E Owner',
	)
);
$player_id = wp_insert_user(
	array(
		'user_login'   => 'obitleague_e2e_player',
		'user_pass'    => wp_generate_password( 20 ),
		'user_email'   => 'obitleague-e2e-player@example.test',
		'display_name' => 'E2E Player',
	)
);
if ( ! is_int( $owner_id ) || ! is_int( $player_id ) ) {
	echo "FAIL could not create E2E users\n";
	exit( 1 );
}

$league_id = 0;

try {
	$league_id = League_Service::create_league( $owner_id, 'E2E Test League' );
	$step( 'create_league', $league_id > 0, "league {$league_id}" );

	$invite = League_Service::generate_invite( $league_id, $owner_id );
	global $wpdb;
	$stored_hash = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT invite_hash FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
	$step(
		'invite_token_hashed',
		'' !== $invite['token'] && $stored_hash !== $invite['token'] && 64 === strlen( $stored_hash ),
		'plaintext differs from sha256 store'
	);

	$joined = League_Service::join_with_token( $player_id, $invite['token'] );
	$step( 'join_with_token', 'member' === $joined['status'] && ! $joined['already_member'], 'status member' );
	$again = League_Service::join_with_token( $player_id, $invite['token'] );
	$step( 'join_idempotent', true === $again['already_member'], 'second join is a no-op' );

	$picks = array();
	for ( $i = 1; $i <= 10; $i++ ) {
		$picks[] = 'e2e-person-' . $i;
	}
	$season  = League_Service::current_season();
	$entry_id = Entry_Service::get_or_create_entry( $league_id, $season, $player_id );
	$entry    = Entry_Service::require_entry( $entry_id, $player_id );
	$saved    = Entry_Service::save_draft( $entry_id, $player_id, $picks, (int) $entry->expected_version );
	$step( 'save_draft', 10 === count( $saved['picks'] ) && $saved['expected_version'] === (int) $entry->expected_version + 1, "revision {$saved['revision_id']}" );

	$stale_refused = false;
	try {
		Entry_Service::save_draft( $entry_id, $player_id, $picks, (int) $entry->expected_version );
	} catch ( Stale_Exception $e ) {
		$stale_refused = true;
	}
	$step( 'stale_save_refused', $stale_refused, 'old expected_version rejected' );

	$receipt = Entry_Service::submit( $entry_id, $player_id, $picks );
	$step( 'submit_with_receipt', $receipt->is_competing() && 10 === count( $receipt->picks ), substr( $receipt->summary(), 0, 70 ) . '…' );

	$submitted = Entry_Service::submitted_revision( $entry_id );
	$step( 'submitted_revision_persisted', $submitted && $receipt->receipt_id === (string) $submitted->receipt_id, 'receipt id matches row' );
	$step( 'picks_persisted', 10 === count( Entry_Service::revision_picks( (int) $submitted->id ) ), 'ten pick rows' );

	$resubmit_refused = false;
	try {
		Entry_Service::submit( $entry_id, $player_id, $picks );
	} catch ( Locked_Exception $e ) {
		$resubmit_refused = true;
	}
	$step( 'resubmit_locked', $resubmit_refused, 'second submit refused' );

	$privacy_ok = false;
	try {
		Entry_Service::require_entry( $entry_id, $owner_id );
	} catch ( Locked_Exception $e ) {
		$privacy_ok = true;
	}
	$step( 'pre_lock_privacy', $privacy_ok, 'foreign user cannot read the entry' );
} finally {
	global $wpdb;
	if ( $league_id ) {
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE user_id IN (%d,%d)', $owner_id, $player_id ) );
		foreach ( (array) $ids as $eid ) {
			$revs = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entry_revisions WHERE entry_id = %d', (int) $eid ) );
			foreach ( $revs as $rid ) {
				$wpdb->delete( $wpdb->prefix . 'obitleague_entry_picks', array( 'revision_id' => (int) $rid ), array( '%d' ) );
			}
			$wpdb->delete( $wpdb->prefix . 'obitleague_entry_revisions', array( 'entry_id' => (int) $eid ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'obitleague_entries', array( 'id' => (int) $eid ), array( '%d' ) );
		}
		$wpdb->delete( $wpdb->prefix . 'obitleague_league_members', array( 'league_id' => $league_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'obitleague_leagues', array( 'id' => $league_id ), array( '%d' ) );
	}
	wp_delete_user( $owner_id );
	wp_delete_user( $player_id );
	echo "\n(cleanup done)\n";
}

$failed = array_filter( $results, static fn ( array $r ): bool => ! $r[1] );
echo "\n" . ( count( $results ) - count( $failed ) ) . ' passed, ' . count( $failed ) . " failed\n";
exit( $failed ? 1 : 0 );

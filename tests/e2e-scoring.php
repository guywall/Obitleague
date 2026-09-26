<?php
/**
 * E2E: editorial review → scoring → standings → retraction reversal.
 *
 * Synthetic people only. Verifies: case open blocks selection, approval
 * publishes event + outbox, worker awards points to a submitted pick,
 * standings rebuild ranks players, retraction reverses awards exactly,
 * and stale decisions are refused.
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
use Obitleague\Modules\Outbox_Service;
use Obitleague\Modules\Review_Service;
use Obitleague\Modules\Standings_Service;
use Obitleague\Modules\Stale_Exception;

$results = array();
$step    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$results ): void {
	$results[] = array( $name, $ok, $detail );
	echo ( $ok ? '  ok  ' : 'FAIL  ' ) . $name . ( $detail ? " — {$detail}" : '' ) . "\n";
};

$person_uuid = wp_generate_uuid4();
$player_id   = wp_insert_user(
	array(
		'user_login' => 'obitleague_e2e_scorer',
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => 'obitleague-e2e-scorer@example.test',
	)
);
$editor_id = get_current_user_id(); // wp-cli runs as no one; decide() accepts any editor id.

try {
	// Fixture person with an exact birth date: age 83 at death (17 points).
	$pid = wp_insert_post(
		array(
			'post_type'    => 'obit_person',
			'post_status'  => 'publish',
			'post_title'   => 'Testee Van Der Test (E2E fixture)',
			'post_content' => 'Synthetic E2E fixture person. Not a real individual.',
		)
	);
	update_post_meta( $pid, 'obit_uuid', $person_uuid );
	update_post_meta( $pid, 'obit_birth_date', '1943-05-10' );
	update_post_meta( $pid, 'obit_eligibility', 'approved' );

	$league_id = League_Service::create_league( $player_id, 'E2E Scoring League' );
	$season    = League_Service::current_season();
	$entry_id  = Entry_Service::get_or_create_entry( $league_id, $season, $player_id );
	$picks     = array_merge( array( $person_uuid ), array_map( static fn ( $i ) => "filler-{$i}", range( 1, 9 ) ) );
	Entry_Service::submit( $entry_id, $player_id, $picks );
	$step( 'setup_league_entry', true, "league {$league_id}, entry {$entry_id}" );

	// 1. Open review case → selection blocked.
	$case_id = Review_Service::open_case( $person_uuid, 'Feed candidate: headline matched death wording.' );
	$person_post_selectable = \Obitleague\Modules\Catalogue::is_selectable( $pid, $season );
	$step( 'open_case_blocks_selection', false === $person_post_selectable, 'pending case blocks new picks' );

	// 2. Stale decision refused.
	$stale = false;
	try {
		Review_Service::decide( $case_id, $editor_id, 'approved', array( 'origin_groups' => array( 'a' ) ), 0 );
	} catch ( Stale_Exception $e ) {
		$stale = true;
	}
	$step( 'stale_decision_refused', $stale, 'wrong expected_revision rejected' );

	// 3. Incomplete approval refused (one origin, that is not an official statement).
	$incomplete = false;
	try {
		Review_Service::decide(
			$case_id, $editor_id, 'approved',
			array( 'origin_groups' => array( 'bbc' ), 'death_date' => array( 'y' => 2026, 'm' => 9, 'd' => 20 ), 'reason' => 'only one source' ),
			1
		);
	} catch ( \Obitleague\Domain\Invalid_Team_Exception $e ) {
		$incomplete = true;
	}
	$step( 'incomplete_approval_refused', $incomplete, 'two origin groups required' );

	// 4. Proper approval with two origin groups → event + outbox.
	// Death mid-season (after the 1 Jan 2027 deadline) so the pick scores.
	$decision = Review_Service::decide(
		$case_id, $editor_id, 'approved',
		array(
			'origin_groups' => array( 'bbc', 'press-association' ),
			'death_date'    => array( 'y' => 2027, 'm' => 6, 'd' => 15 ),
			'reason'        => 'Two independent reports confirm identity and date.',
		),
		1
	);
	$step( 'approval_publishes_event', 'approved' === $decision['state'] && null !== $decision['event_uuid'], 'event ' . substr( (string) $decision['event_uuid'], 0, 8 ) . '…' );

	global $wpdb;
	$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_events WHERE uuid = %s', $decision['event_uuid'] ) );
	$step( 'event_row_written', $event && '2027-06-15' === $event->death_date, 'death date stored' );
	$step( 'person_facts_published', '2027-06-15' === (string) get_post_meta( $pid, 'obit_death_date', true ), 'death projected to person meta' );

	// 5. Outbox worker awards the pick: age 84 → 16 points.
	Outbox_Service::process_outbox();
	$awards = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_awards WHERE entry_id = %d', $entry_id ) );
	$total  = array_sum( array_map( static fn ( $a ) => (int) $a->award_delta, $awards ) );
	$step( 'award_written', 1 === count( $awards ) && 16 === $total, "one award, {$total} points (max(1, 100−84))" );

	// 6. Standings rebuild ranks the player.
	$gen = Standings_Service::rebuild( $league_id, $season );
	$rows = Standings_Service::current( $league_id, $season );
	$step( 'standings_published', $gen > 0 && $rows && 16 === (int) $rows[0]['points'] && 1 === (int) $rows[0]['rank'], "rank 1, {$rows[0]['points']} points" );

	// 7. Replay the outbox: idempotent, no double awards.
	Outbox_Service::process_outbox();
	$awards_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_awards WHERE entry_id = %d AND award_delta > 0', $entry_id ) );
	$step( 'replay_idempotent', 1 === $awards_after, 'still exactly one award' );

	// 8. Retraction reverses exactly.
	Review_Service::decide( $case_id, $editor_id, 'retracted', array( 'reason' => 'Mistaken identity — confirmed before awarding.' ), 2 );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'obitleague_outbox SET processed_at = NULL WHERE event_type = %s', 'death.retracted' ) );
	Outbox_Service::process_outbox();
	$total_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards WHERE entry_id = %d', $entry_id ) );
	$step( 'retraction_reverses', 0 === $total_after, "net total back to {$total_after}" );

	$gen2 = Standings_Service::rebuild( $league_id, $season );
	$rows2 = Standings_Service::current( $league_id, $season );
	$step( 'standings_after_retraction', $rows2 && 0 === (int) $rows2[0]['points'], 'rebuilt standings show zero' );

	// 9. Retraction unblocks selection again.
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'obitleague_review_cases SET state = %s WHERE id = %d', 'retracted', $case_id ) );
	$step( 'retraction_unblocks_selection', \Obitleague\Modules\Catalogue::is_selectable( $pid, $season ), 'person selectable again' );
} finally {
	global $wpdb;
	if ( isset( $league_id ) && $league_id ) {
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE user_id = %d', $player_id ) );
		foreach ( (array) $ids as $eid ) {
			$revs = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entry_revisions WHERE entry_id = %d', (int) $eid ) );
			foreach ( $revs as $rid ) {
				$wpdb->delete( $wpdb->prefix . 'obitleague_entry_picks', array( 'revision_id' => (int) $rid ), array( '%d' ) );
			}
			$wpdb->delete( $wpdb->prefix . 'obitleague_entry_revisions', array( 'entry_id' => (int) $eid ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'obitleague_awards', array( 'entry_id' => (int) $eid ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'obitleague_entries', array( 'id' => (int) $eid ), array( '%d' ) );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE r, sr FROM ' . $wpdb->prefix . 'obitleague_standings_rows sr JOIN ' . $wpdb->prefix . 'obitleague_standings_generations r ON r.id = sr.generation_id WHERE r.league_id = %d', $league_id ) );
		$wpdb->delete( $wpdb->prefix . 'obitleague_standings_generations', array( 'league_id' => $league_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'obitleague_review_cases', array( 'person_uuid' => $person_uuid ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'obitleague_events', array( 'person_uuid' => $person_uuid ), array( '%s' ) );
		$wpdb->delete( $wpdb->prefix . 'obitleague_outbox', array( 'processed_at IS NOT NULL' => null ) ? array() : array() );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'obitleague_outbox' );
		$wpdb->delete( $wpdb->prefix . 'obitleague_league_members', array( 'league_id' => $league_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'obitleague_leagues', array( 'id' => $league_id ), array( '%d' ) );
		if ( isset( $pid ) ) {
			wp_delete_post( $pid, true );
		}
		wp_delete_user( $player_id );
		echo "\n(cleanup done)\n";
	}
}

$failed = array_filter( $results, static fn ( array $r ): bool => ! $r[1] );
echo "\n" . ( count( $results ) - count( $failed ) ) . ' passed, ' . count( $failed ) . " failed\n";
exit( $failed ? 1 : 0 );

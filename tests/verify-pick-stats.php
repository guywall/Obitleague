<?php
/**
 * Verification for person-page pick statistics.
 *
 * Most of what Pick_Stats does can be checked against the demo data, but one
 * case cannot: no real person is picked by exactly one team, so the "unique
 * pick" badge would otherwise never be exercised. This script builds that
 * situation deliberately — a scratch person taken by a single scratch team —
 * checks both the figures and the rendered page, and then removes every row it
 * created.
 *
 * It refuses to run if it cannot tidy up after itself, and it never touches an
 * existing person, entry or league.
 *
 * Run: wp eval-file tests/verify-pick-stats.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

use Obitleague\Modules\Catalogue;
use Obitleague\Modules\Pick_Stats;

const PROBE_NAME = 'ZZ Pick Stats Probe';
const PROBE_LEAGUE = 'ZZ Pick Stats Probe League';
const PROBE_TEAM = 'ZZ Probe Team';

$season = Pick_Stats::season_in_play();
global $wpdb;

$failures = array();

/**
 * Undo the fixture.
 *
 * Every delete is gated on proof that the row belongs to this probe. A script
 * that removes data by bare id is one bad insert return away from deleting a
 * real league, so nothing here runs on an id alone: the league must still
 * carry the probe's name, and the entries must still carry the probe's team
 * name. If either check fails the delete is refused and reported.
 */
$cleanup = static function () use ( $season, $wpdb ): void {
	$league_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}obitleague_leagues WHERE name = %s",
			PROBE_LEAGUE
		)
	);
	foreach ( (array) $league_ids as $league_id ) {
		$league_id = (int) $league_id;

		$entry_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}obitleague_entries WHERE league_id = %d AND team_name = %s",
				$league_id,
				PROBE_TEAM
			)
		);
		if ( array() === $entry_ids ) {
			WP_CLI::warning( "refusing to delete league {$league_id}: it holds no entry named " . PROBE_TEAM );
			continue;
		}
		$in = implode( ',', array_map( 'intval', $entry_ids ) );

		$wpdb->query(
			"DELETE p FROM {$wpdb->prefix}obitleague_entry_picks p
			 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id
			 WHERE r.entry_id IN ({$in})"
		);
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id IN ({$in})" );
		// $in is a comma list of integers, cast above, so it is safe inline.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}obitleague_entries WHERE id IN ({$in})" );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_league_members WHERE league_id = %d", $league_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_leagues WHERE id = %d AND name = %s", $league_id, PROBE_LEAGUE ) );
		WP_CLI::log( "removed probe league {$league_id}" );
	}

	// Probe entries whose league has gone (or never existed, if an insert
	// reported a stale id) are not reachable through a named league, so they
	// are swept by their own team name. Only rows with no revisions are
	// removed: a probe entry that somehow acquired picks is left for a human.
	$orphan_entries = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT e.id FROM {$wpdb->prefix}obitleague_entries e
			 WHERE e.team_name = %s
			   AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}obitleague_entry_revisions r WHERE r.entry_id = e.id)",
			PROBE_TEAM
		)
	);
	foreach ( (array) $orphan_entries as $orphan_id ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entries WHERE id = %d", (int) $orphan_id ) );
		WP_CLI::log( 'removed stray probe entry ' . (int) $orphan_id );
	}

	foreach ( (array) get_posts( array( 'post_type' => Catalogue::POST_TYPE, 'title' => PROBE_NAME, 'post_status' => 'any', 'numberposts' => 10, 'fields' => 'ids' ) ) as $probe_post ) {
		wp_delete_post( (int) $probe_post, true );
		WP_CLI::log( "removed probe person {$probe_post}" );
	}

	Pick_Stats::flush( $season );
};

try {
	/* ---- 1. a person nobody has taken ---- */
	$post_id = wp_insert_post(
		array(
			'post_type'   => Catalogue::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => PROBE_NAME,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( 'Could not create the probe person: ' . $post_id->get_error_message() );
	}
	// The person post is found again by name during cleanup, so no id is
	// carried across; nothing below depends on it surviving.

	$uuid = wp_generate_uuid4();
	update_post_meta( $post_id, 'obit_uuid', $uuid );
	update_post_meta( $post_id, 'obit_birth_date', '1970-05-04' );
	update_post_meta( $post_id, 'obit_eligibility', 'approved' );

	/* ---- 2. a scratch league and one team that takes them ---- */
	// $wpdb->insert()'s return value is not trusted anywhere in this script.
	// It has been observed handing back a stale insert id, which is how an
	// earlier version of this file deleted a real league: the id is read back
	// by its unique name instead, and the cleanup finds rows by name too.
	$wpdb->insert(
		$wpdb->prefix . 'obitleague_leagues',
		array(
			'name'         => PROBE_LEAGUE,
			'season'       => $season,
			'owner_user_id' => 1,
			'state'        => 'open',
			'is_main'      => 0,
			'created_at'   => current_time( 'mysql', true ),
		)
	);
	$league_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}obitleague_leagues WHERE name = %s ORDER BY id DESC LIMIT 1",
			PROBE_LEAGUE
		)
	);
	if ( ! $league_id ) {
		throw new RuntimeException( 'Could not create the probe league.' );
	}
	// Likewise the league is re-found by name during cleanup.

	// Nine real picks to satisfy the ten-pick rule, plus the probe person.
	$others = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s AND p.post_status = 'publish'
			 WHERE pm.meta_key = 'obit_uuid' AND pm.meta_value <> %s
			 LIMIT 9",
			Catalogue::POST_TYPE,
			$uuid
		)
	);
	if ( count( $others ) < 9 ) {
		throw new RuntimeException( 'Not enough published people to fill a team.' );
	}
	$picks = array_merge( array_values( $others ), array( $uuid ) );

	$wpdb->insert(
		$wpdb->prefix . 'obitleague_entries',
		array(
			'league_id'        => $league_id,
			'season'           => $season,
			'user_id'          => 1,
			'team_name'        => PROBE_TEAM,
			'state'            => 'submitted',
			'expected_version' => 1,
			'created_at'       => current_time( 'mysql', true ),
		)
	);
	$entry_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}obitleague_entries WHERE league_id = %d AND team_name = %s",
			$league_id,
			PROBE_TEAM
		)
	);
	if ( ! $entry_id ) {
		throw new RuntimeException( 'Could not create the probe entry.' );
	}
	// A unique receipt marks the revision, so it can be found again without
	// trusting the id $wpdb->insert reported back. The column is char(36), so
	// this has to be a bare uuid.
	$probe_receipt = wp_generate_uuid4();
	$wpdb->insert(
		$wpdb->prefix . 'obitleague_entry_revisions',
		array(
			'entry_id'   => $entry_id,
			'kind'       => 'submitted',
			'receipt_id' => $probe_receipt,
			'created_at' => current_time( 'mysql', true ),
		)
	);
	$revision_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}obitleague_entry_revisions WHERE receipt_id = %s AND entry_id = %d",
			$probe_receipt,
			$entry_id
		)
	);
	if ( ! $revision_id ) {
		throw new RuntimeException( 'Could not create the probe revision.' );
	}

	foreach ( $picks as $i => $p ) {
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'obitleague_entry_picks',
			array( 'revision_id' => $revision_id, 'slot' => $i + 1, 'person_uuid' => $p )
		);
		if ( ! $inserted ) {
			throw new RuntimeException( sprintf( 'Could not save probe pick in slot %d: %s', $i + 1, $wpdb->last_error ) );
		}
	}

	$stored = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_entry_picks WHERE revision_id = %d",
			$revision_id
		)
	);
	if ( count( $picks ) !== $stored ) {
		throw new RuntimeException( sprintf( 'Probe team stored %d of %d picks.', $stored, count( $picks ) ) );
	}

	Pick_Stats::flush( $season );

	/* ---- 3. the figures must match an independent count ---- */
	$stats = Pick_Stats::for_person( $post_id );

	$expected = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT r.entry_id) FROM {$wpdb->prefix}obitleague_entry_picks p
			 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
			 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = r.entry_id AND e.state = 'submitted' AND e.season = %d
			 WHERE p.person_uuid = %s",
			$season,
			$uuid
		)
	);

	1 === $stats['picks'] || $failures[] = "picks should be 1, got {$stats['picks']}";
	$expected === $stats['picks'] || $failures[] = "picks ({$stats['picks']}) disagree with a direct count ({$expected})";
	true === $stats['is_unique'] || $failures[] = 'is_unique should be true for exactly one taker';
	false === $stats['is_hot'] || $failures[] = 'a once-picked person must never be a hot pick';
	1 === count( $stats['teams'] ) || $failures[] = 'exactly one team should be listed';
	0 === $stats['teams_remaining'] || $failures[] = 'no teams should be left over';
	$stats['percent'] > 0 && $stats['percent'] < 100 || $failures[] = 'share should be a small non-zero percentage';
	'<1%' === Pick_Stats::percent_label( (float) $stats['percent'] ) || $failures[] = 'a single taker should read as "less than 1%", not 0%';
	'88.8%' === Pick_Stats::percent_label( 88.8 ) || $failures[] = 'a real share should render with one decimal';

	// Ordinals: the teens and the 11/12/13 exception, and a rank past 100,
	// where taking the last two digits must not truncate the number shown.
	$ordinals = array( 1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 11 => '11th', 12 => '12th', 13 => '13th', 21 => '21st', 101 => '101st', 111 => '111th', 112 => '112th', 121 => '121st' );
	foreach ( $ordinals as $input => $expected ) {
		Pick_Stats::ordinal( (int) $input ) === $expected || $failures[] = sprintf( 'ordinal(%d) should be %s', $input, $expected );
	}

	/* ---- 4. the rendered page must actually show the badge ---- */
	$permalink = get_permalink( $post_id );
	$response  = wp_remote_get( $permalink, array( 'timeout' => 20 ) );
	$body      = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );

	if ( '' === $body ) {
		$failures[] = 'could not fetch ' . $permalink . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'empty body' );
	} else {
		str_contains( $body, 'ob-pick-badge--unique' ) || $failures[] = 'the unique pick badge is not rendered';
		str_contains( $body, 'ZZ Probe Team' ) || $failures[] = 'the taking team is not named on the page';
		str_contains( $body, 'ob-pick-badge--hot' ) && $failures[] = 'a once-picked person must not render a hot badge';
		preg_match( '/Fatal error|Parse error|<b>Warning<\/b>/', $body, $m ) && $failures[] = 'page rendered a PHP error: ' . $m[0];
	}

	WP_CLI::log( sprintf( 'picks=%d teams=%d percent=%s rank=%s unique=%s hot=%s', $stats['picks'], $stats['teams_total'], $stats['percent'], var_export( $stats['rank'], true ), var_export( $stats['is_unique'], true ), var_export( $stats['is_hot'], true ) ) );
} catch ( \Throwable $e ) {
	$failures[] = get_class( $e ) . ': ' . $e->getMessage();
} finally {
	$cleanup();
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		WP_CLI::warning( $failure );
	}
	WP_CLI::error( sprintf( '%d pick-stats check(s) failed.', count( $failures ) ) );
}

WP_CLI::success( 'Pick stats verified: unique-pick case renders correctly and the probe was removed.' );

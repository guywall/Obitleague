<?php
/**
 * DEMO leagues: an in-play 2026 season.
 *
 * Demo users join real invite-token flow, teams are submitted with
 * BACKDATED commit instants (Dec 2025) — disclosed demo data. Deaths in
 * 2026 are approved through the real review service and scored by the real
 * outbox/ledger/standings pipeline. Nothing is faked in the scoring path;
 * only submission timestamps are historical.
 *
 * Idempotent by league name; deterministic ordering.
 *
 * Run: wp eval-file <this-file>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Obitleague\Modules\Entry_Service;
use Obitleague\Modules\League_Service;
use Obitleague\Modules\Outbox_Service;
use Obitleague\Modules\Review_Service;
use Obitleague\Modules\Standings_Service;

$work   = 'C:/Users/guy/Documents/Obitz/Obitleague/work';
$season = 2026;

// Demo season 2026 is a game season for awards.
update_option( 'obitleague_first_season', 2026 );

/* ---------- users ---------- */
$users = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$login = 'obitleague_demo_' . $i;
	$u     = get_user_by( 'login', $login );
	if ( $u ) {
		$users[] = (int) $u->ID;
		continue;
	}
	$uid = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'user_email'   => $login . '@example.test',
			'display_name' => 'Demo Player ' . $i,
		)
	);
	if ( is_wp_error( $uid ) ) {
		echo "user fail\n";
		exit( 1 );
	}
	$users[] = (int) $uid;
}
echo 'users: ' . count( $users ) . "\n";

/* ---------- people pools ---------- */
global $wpdb;

$uuid_of_qid = static function ( string $qid ): ?string {
	$pid = Obitleague\Modules\Import_Service::post_id_by_qid( $qid );
	if ( ! $pid ) {
		return null;
	}
	return (string) get_post_meta( $pid, 'obit_uuid', true );
};

$deaths  = json_decode( file_get_contents( $work . '/seed-deaths-2026.json' ), true );
$prelock = json_decode( file_get_contents( $work . '/seed-prelock-deaths.json' ), true );
$living  = json_decode( file_get_contents( $work . '/seed-living-pool.json' ), true );

$living_uuids = array();
foreach ( $living as $row ) {
	$uuid = $uuid_of_qid( $row['qid'] );
	if ( $uuid ) {
		$living_uuids[] = $uuid;
	}
}
$scored_uuids = array();  // 2026 deaths: will score once approved below.
foreach ( $deaths as $row ) {
	$uuid = $uuid_of_qid( $row['qid'] );
	if ( $uuid ) {
		$scored_uuids[] = array( 'uuid' => $uuid, 'row' => $row );
	}
}
$prelock_uuids = array();
foreach ( $prelock as $row ) {
	$uuid = $uuid_of_qid( $row['qid'] );
	if ( $uuid ) {
		$prelock_uuids[] = $uuid;
	}
}
echo 'pools: living=' . count( $living_uuids ) . ' scored=' . count( $scored_uuids ) . ' prelock=' . count( $prelock_uuids ) . "\n";

/* ---------- three leagues with joined members ---------- */
$league_names = array( 'Winter League 2026', 'Office Pool 2026', 'Celebrity Circle 2026' );
$leagues      = array();
$idx          = 0;
foreach ( $league_names as $name ) {
	$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE name = %s AND season = %d', $name, $season ) );
	if ( $existing ) {
		$leagues[] = (int) $existing;
		$idx      += 4; // Members already exist; skip their joins too.
		continue;
	}
	$owner  = $users[ $idx ];
	$lg     = League_Service::create_league( $owner, $name, $season );
	$invite = League_Service::generate_invite( $lg, $owner );
	// Three players join via the real token flow.
	for ( $j = 1; $j <= 3; $j++ ) {
		League_Service::join_with_token( $users[ $idx + $j ], $invite['token'] );
	}
	$leagues[] = $lg;
	$idx      += 4;
	echo "league '{$name}' id {$lg} (owner {$owner})\n";
}

/* ---------- submissions, backdated before the 1 Jan 2026 lock ---------- */
$lock = Obitleague\Domain\Deadline_Policy::entry_deadline( $season );
echo 'deadline: ' . $lock->format( 'c' ) . "\n";

$li = 0; $ui = 0; $pick_cursor = 0; $scored_cursor = 0; $prelock_cursor = 0;
$submitted = 0;

foreach ( $leagues as $league_id ) {
	$member_ids = $wpdb->get_col(
		$wpdb->prepare( 'SELECT user_id FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d ORDER BY id ASC', $league_id )
	);
	foreach ( $member_ids as $user_id ) {
		$entry_id = Entry_Service::get_or_create_entry( $league_id, $season, (int) $user_id );

		// Skip if this entry already has a submitted revision (re-run safety).
		if ( Entry_Service::submitted_revision( $entry_id ) ) {
			continue;
		}

		// Ten picks: 8 living + 1 from 2026's real deaths + 1 pre-lock death.
		$picks = array();
		for ( $k = 0; $k < 8; $k++ ) {
			$picks[] = $living_uuids[ ( $pick_cursor++ ) % count( $living_uuids ) ];
		}
		$picks[] = $scored_uuids[ $scored_cursor++ % count( $scored_uuids ) ]['uuid'];
		$picks[] = $prelock_uuids[ $prelock_cursor++ % count( $prelock_uuids ) ];

		// Backdated commit instant: Dec 3-25 2025 (disclosed demo data).
		$backdate = new DateTimeImmutable( sprintf( '2025-12-%02dT%02d:00:00Z', 3 + ( $submitted % 22 ), 9 + ( $submitted % 12 ) ), new DateTimeZone( 'UTC' ) );
		$mysql    = $backdate->format( 'Y-m-d H:i:s' );

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_entry_revisions (entry_id, kind, receipt_id, submitted_at, created_at)
					 VALUES (%d, %s, %s, %s, %s)',
					$entry_id,
					'submitted',
					wp_generate_uuid4(),
					$mysql,
					$mysql
				)
			);
			$revision_id = (int) $wpdb->insert_id;
			foreach ( array_values( $picks ) as $slot => $uuid ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO ' . $wpdb->prefix . 'obitleague_entry_picks (revision_id, slot, person_uuid)
						 VALUES (%d, %d, %s)',
						$revision_id,
						$slot + 1,
						$uuid
					)
				);
			}
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'obitleague_entries SET state = %s, updated_at = %s WHERE id = %d',
					'submitted',
					$mysql,
					
					$entry_id
				)
			);
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		++$submitted;
		echo "submitted: league {$league_id} user {$user_id} ({$backdate->format('Y-m-d')})\n";
	}
}
echo "submitted entries: {$submitted}\n";

/* ---------- review: approve the season's scoring deaths ---------- */
$editor_id  = $users[0];
$approved   = 0;
foreach ( $scored_uuids as $item ) {
	$row     = $item['row'];
	if ( Review_Service::has_case_in_state( $item['uuid'], 'approved' ) ) {
		continue; // Already approved on a previous run: one event per person.
	}
	$case_id = Review_Service::open_case( $item['uuid'], 'Demo seed: ' . $row['origin'] . ' (' . $row['death'] . ', age ' . $row['age'] . ')' );
	Review_Service::decide(
		$case_id,
		$editor_id,
		'approved',
		array(
			'origin_groups' => array( 'wikipedia', 'wikidata' ),
			'death_date'    => array( 'y' => (int) substr( $row['death'], 0, 4 ), 'm' => (int) substr( $row['death'], 5, 2 ), 'd' => (int) substr( $row['death'], 8, 2 ) ),
			'reason'        => 'Demo seed approval: listed on ' . $row['origin'] . ' with matching Wikidata death date.',
		),
		1
	);
	++$approved;
}
echo "review approvals: {$approved}\n";

/* ---------- score: outbox fan-out, real ledger, real standings ---------- */
$processed = Outbox_Service::process_outbox( 500 );
echo "outbox processed: {$processed}\n";

/* ---------- standings snapshot ---------- */
foreach ( $leagues as $league_id ) {
	echo "\n== standings: league {$league_id} ==\n";
	$rows = Standings_Service::current( $league_id, $season );
	if ( ! $rows ) {
		echo "  (none)\n";
		continue;
	}
	foreach ( $rows as $row ) {
		printf( "  %2d. %-24s %3d pts (%d scoring picks)\n", $row['rank'], $row['player'], $row['points'], $row['scoring_picks'] );
	}
}

echo "\ndemo ready.\n";

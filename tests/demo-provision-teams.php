<?php
/**
 * Populate the current main league with teams for marked synthetic accounts.
 *
 * Teams use only currently selectable published catalogue people, are submitted
 * through the normal entry service, and receive current real timestamps. The
 * seeder does not fabricate deaths, scores, or backdated submissions.
 *
 * Run: wp eval-file tests/demo-provision-teams.php
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true ) ) {
	throw new RuntimeException( 'Demo team provisioning only runs on local, development, or staging WordPress environments.' );
}

use Obitleague\Modules\Catalogue;
use Obitleague\Modules\Entry_Service;
use Obitleague\Modules\League_Service;
use Obitleague\Modules\Main_League_Service;
use Obitleague\Modules\Standings_Service;

$season = League_Service::current_season();
$users = get_users(
	array(
		'fields'     => array( 'ID', 'display_name', 'user_login' ),
		'number'     => 10000,
		'orderby'    => 'ID',
		'order'      => 'ASC',
		'meta_key'   => 'obitleague_demo_account',
		'meta_value' => '1',
	)
);
if ( ! $users ) {
	throw new RuntimeException( 'No marked synthetic accounts found. Import accounts first.' );
}

$people = get_posts(
	array(
		'post_type'      => Catalogue::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => 'obit_eligibility',
		'meta_value'     => 'approved',
	)
);
$pool = array();
foreach ( $people as $post_id ) {
	if ( Catalogue::is_selectable( (int) $post_id, $season ) ) {
		$uuid = (string) get_post_meta( (int) $post_id, 'obit_uuid', true );
		if ( '' !== $uuid ) {
			$pool[] = $uuid;
		}
	}
}
$pool = array_values( array_unique( $pool ) );
if ( count( $pool ) < 10 ) {
	throw new RuntimeException( sprintf( 'Need ten currently selectable catalogue people for season %d; found %d.', $season, count( $pool ) ) );
}

$league_id = Main_League_Service::ensure_league( $season );
$created = 0;
$already_submitted = 0;
foreach ( $users as $index => $user ) {
	$result = Main_League_Service::ensure_user_entry( (int) $user->ID, $season );
	$entry_id = (int) $result['entry_id'];
	$existing = Entry_Service::submitted_revision( $entry_id );
	if ( $existing ) {
		++$already_submitted;
		continue;
	}

	$team_name = trim( (string) get_user_meta( (int) $user->ID, 'obitleague_demo_team_name', true ) );
	if ( '' === $team_name ) {
		$team_name = (string) $user->display_name . ' XI';
	}
	global $wpdb;
	$wpdb->update(
		$wpdb->prefix . 'obitleague_entries',
		array( 'team_name' => mb_substr( $team_name, 0, 120 ) ),
		array( 'id' => $entry_id, 'user_id' => (int) $user->ID ),
		array( '%s' ),
		array( '%d', '%d' )
	);

	// Give each account a deterministic but distinct sample from verified,
	// eligible catalogue records. The same user always gets the same picks.
	$ranked_pool = $pool;
	$login = (string) $user->user_login;
	usort(
		$ranked_pool,
		static fn ( string $left, string $right ): int => strcmp( hash( 'sha256', $login . ':' . $left ), hash( 'sha256', $login . ':' . $right ) )
	);
	$picks = array_slice( $ranked_pool, 0, 10 );
	Entry_Service::submit( $entry_id, (int) $user->ID, $picks );
	++$created;

	if ( 0 === ( $index + 1 ) % 100 ) {
		WP_CLI::log( sprintf( 'Processed %d/%d accounts.', $index + 1, count( $users ) ) );
	}
}

Standings_Service::rebuild( $league_id, $season );
WP_CLI::success(
	sprintf(
		'Current main league season %d: created %d submitted teams; %d already had submissions; %d eligible catalogue picks available. No scores or historical timestamps were fabricated.',
		$season,
		$created,
		$already_submitted,
		count( $pool )
	)
);

<?php
/**
 * DEMO leagues: an in-play 2026 season with themed entries.
 *
 * Demo users join via invite tokens and have clearly fictional accounts.
 * Teams are submitted with disclosed BACKDATED timestamps (Dec 2025).
 * All real-person facts, approvals and scores flow through the real services.
 *
 * Idempotent by league name and demo login; safe to re-run without resetting.
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

$work   = dirname( __DIR__ ) . '/work';
$season = 2026;
update_option( 'obitleague_first_season', $season );

$team_names = array(
	'The Last Word', 'The Final Curtain', 'RIP Tides', 'The Dead Kennedys',
	'Curtain Callers', 'The Grim Reapers', 'Six Feet Understudies', 'The End Credits',
	'Death Cab for Cutie', 'The Obit-ual Suspects', 'The Living Legends', 'The Deadlines',
	'Gone But Not Forgotten', 'The Pallbearers Club', 'The Final Draft', 'Memento Moriarty',
	'The Resting Pitch Faces', 'The Last Laugh', 'The Hearse Whisperers', 'The Departed Departures',
	'No Country for Old Picks', 'A Farewell to Arms', 'The Late Greats', 'The Grim and the Restless',
	'Exit, Pursued by a Bear', 'The Obituary Notices', 'The Mortal Kombatants', 'The Wake-Up Call',
	'The End of the Line', 'The Final Countdown', 'The Great Beyond', 'The Eternal Resters',
	'Very Nearly Famous', 'The Passing Remarks', 'Death Becomes Them', 'The Afterlife Achievers', 'The Last Act', 'Famous Last Picks', 'The Black Ribbons', 'The Long Goodbye',
	'The Last Rites', 'D.O.A. & Co.', 'The Passing Fancy', 'Gone Tomorrow', 'The Last Laugh Track', 'The Obit Squad',
	'Late to the Wake', 'The Grim ReMarkables', 'The Final Notice', 'Wake Me Up Before You Go-Go', 'Pick to Remember', 'The Mortal Wombats',
	'The End Is Nigh-ish', 'The Quietus Committee', 'On Borrowed Time', 'The Exit Strategy', 'The Last Ones Standing', 'The Hearafter',
	'The Final Chapter', 'The Dead Ringers', 'The Last Round', 'The Last Wordsmiths', 'The Great Perhaps', 'The Obitual Suspects',
	'Pick of the Mortals', 'The Eulogizers',
);

// Generated team names for accounts beyond the curated list (always
// distinct: the user index is appended).
$team_prefixes = array( 'The Grim', 'Final', 'Silent', 'Ivory', 'Crimson', 'Velvet', 'Marble', 'Hollow', 'Gilded', 'Midnight', 'Paper', 'Winter', 'Amber', 'Iron', 'Quiet' );
$team_suffixes = array( 'Society', 'Collective', 'Register', 'Enthusiasts', 'Committee', 'Brigade', 'Chorus', 'Archive', 'Circle', 'Union' );

$display_names = array(
	'Penny Dreadful', 'Max Power', 'Cory Ander', 'Pat Myback', 'Robin Banks',
	'Terry Cloth', 'Al B. Back', 'Manny Festation', 'Drew Carey-on', 'Carrie Okey',
	'Pat Pending', 'Anita Bath', 'Justin Time', 'Barb Dwyer', 'Sal Monella',
	'Cliff Hanger', 'Paige Turner', 'Art Major', 'Bill Board', 'Mel O. Drama',
	'Ray Gunn', 'Sue Flay', 'Bea Kind', 'Warren Peace', 'Crystal Ball',
	'Gene Pool', 'Mark Mywords', 'Celia Later', 'Hope Springs', 'Moe Mentum',
	'Constance N. Tension', 'Ella Vator', 'Dee Ceased', 'Sharon Shore', 'Will Power',
	'Kerry Oki', 'Les Ismore', 'Dawn Breaker', 'Merry GoRound', 'Frank N. Stein',
);

/* ---------- explicitly fictional demo users ---------- */
// Scale beyond the curated name list: OBITLEAGUE_DEMO_PLAYERS=150 makes the
// seeder generate additional synthetic accounts (obitleague_demo_NNNNN) with
// varied display names, reusing the idempotent naming scheme.
$target_players = (int) ( getenv( 'OBITLEAGUE_DEMO_PLAYERS' ) ?: 0 );
$users = array();
foreach ( $display_names as $index => $display_name ) {
	$number = $index + 1;
	$login  = 'obitleague_demo_' . $number;
	$user   = get_user_by( 'login', $login );
	if ( ! $user ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'user_email'   => $login . '@example.test',
				'display_name' => $display_name,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create demo account ' . $login . ': ' . $user_id->get_error_message() );
		}
		$user = get_userdata( (int) $user_id );
	} elseif ( (string) $user->display_name !== $display_name ) {
		wp_update_user( array( 'ID' => (int) $user->ID, 'display_name' => $display_name ) );
	}
	$users[] = (int) $user->ID;
}

if ( $target_players > count( $users ) ) {
	$first_names = array( 'Alex', 'Avery', 'Bailey', 'Casey', 'Charlie', 'Dakota', 'Drew', 'Ellis', 'Emery', 'Finley', 'Harper', 'Jamie', 'Jesse', 'Jordan', 'Kai', 'Logan', 'Morgan', 'Parker', 'Quinn', 'Riley', 'Rowan', 'Sam', 'Skyler', 'Taylor', 'Cameron', 'Devon', 'Frankie', 'Hayden', 'Kendall', 'Reese', 'Robin', 'Shay', 'Terry', 'Val', 'Billie', 'Corey', 'Remy', 'Sasha', 'Shannon', 'Toby' );
	$surnames = array( 'Abbott', 'Bennett', 'Carter', 'Dawson', 'Ellis', 'Foster', 'Hayes', 'Morgan', 'Parker', 'Reed', 'Bailey', 'Brooks', 'Cooper', 'Fletcher', 'Grant', 'Hughes', 'Kelly', 'Miller', 'Price', 'Turner', 'Adler', 'Bishop', 'Clarke', 'Delaney', 'Everett', 'Finch', 'Gardner', 'Hollis', 'Mercer', 'Sutton', 'Banks', 'Bell', 'Cross', 'Doyle', 'Fields', 'Hart', 'Lane', 'Nolan', 'Quinn', 'Shaw' );
	$team_prefixes = array( 'The Grim', 'Final', 'Silent', 'Ivory', 'Crimson', 'Velvet', 'Marble', 'Hollow', 'Gilded', 'Midnight', 'Paper', 'Winter', 'Amber', 'Iron', 'Quiet' );
	$team_suffixes = array( 'Society', 'Collective', 'Register', 'Enthusiasts', 'Committee', 'Brigade', 'Chorus', 'Archive', 'Circle', 'Union' );
	for ( $number = count( $users ) + 1; $number <= $target_players; ++$number ) {
		$login = 'obitleague_demo_' . sprintf( '%05d', $number );
		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			$first = $first_names[ $number % count( $first_names ) ];
			$last = $surnames[ ( $number * 7 ) % count( $surnames ) ];
			$suffix = $number % 9;
			$display = ( 0 === $suffix ) ? $first . ' ' . $last . ' ' . ( 1960 + $number % 40 )
				: ( ( 1 === $suffix ) ? strtolower( $first . $last ) . ( 40 + $number % 60 )
				: $first . ' ' . $last );
			$user_id = wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass' => wp_generate_password( 24 ),
					'user_email' => $login . '@example.test',
					'display_name' => $display,
					'role' => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				throw new RuntimeException( 'Could not create demo account ' . $login . ': ' . $user_id->get_error_message() );
			}
			update_user_meta( (int) $user_id, 'obitleague_demo_account', 1 );
			$user = get_userdata( (int) $user_id );
		}
		$users[] = (int) $user->ID;
	}
	echo 'demo players requested: ' . $target_players . ', available: ' . count( $users ) . "\n";
}

/* ---------- sourced pools; no invented people or deaths ---------- */
global $wpdb;
$uuid_of_qid = static function ( string $qid ): ?string {
	$post_id = Obitleague\Modules\Import_Service::post_id_by_qid( $qid );
	if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
		return null;
	}
	return (string) get_post_meta( $post_id, 'obit_uuid', true ) ?: null;
};

$read_json = static function ( string $filename ) use ( $work ): array {
	$path = $work . '/' . $filename;
	if ( ! is_readable( $path ) ) {
		throw new RuntimeException( 'Required local seed file is not readable: ' . $path );
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'Invalid JSON seed file: ' . $filename );
	}
	return $data;
};

$living_rows = $read_json( 'seed-living-pool.json' );
// Widen the selectable pool with the diverse 1946 cohort when present.
if ( is_readable( $work . '/seed-cohort-1946.json' ) ) {
	$living_rows = array_merge( $living_rows, $read_json( 'seed-cohort-1946.json' ) );
}
$death_rows   = $read_json( 'seed-deaths-2026.json' );
$prelock_rows = $read_json( 'seed-prelock-deaths.json' );
$living_pool = array();
foreach ( $living_rows as $row ) {
	$uuid = $uuid_of_qid( (string) ( $row['qid'] ?? '' ) );
	if ( $uuid ) {
		$pid = Obitleague\Modules\Import_Service::post_id_by_qid( (string) $row['qid'] );
		$living_pool[] = array(
			'uuid' => $uuid,
			'name' => (string) get_the_title( $pid ),
			'birth_year' => (int) substr( (string) ( $row['birth'] ?? '' ), 0, 4 ),
			'occupations' => Obitleague\Modules\People_Sync::occupation_labels( $pid ),
		);
	}
}
$scored_pool = array();
foreach ( $death_rows as $row ) {
	$uuid = $uuid_of_qid( (string) ( $row['qid'] ?? '' ) );
	if ( $uuid ) {
		$scored_pool[] = array( 'uuid' => $uuid, 'row' => $row );
	}
}
$prelock_pool = array();
foreach ( $prelock_rows as $row ) {
	$uuid = $uuid_of_qid( (string) ( $row['qid'] ?? '' ) );
	if ( $uuid ) {
		$prelock_pool[] = array( 'uuid' => $uuid, 'row' => $row );
	}
}
// Theme qualifiers are people who were alive at the start of the season.
// Deaths are included separately as explicitly sourced score/pre-lock picks.
$theme_people = $living_pool;
if ( count( $living_pool ) < 10 || count( $scored_pool ) < 1 || count( $prelock_pool ) < 1 ) {
	throw new RuntimeException( 'Demo seed needs 10 published living picks, one sourced 2026 death and one sourced pre-lock death.' );
}
echo 'usable sourced pools: living=' . count( $living_pool ) . ' 2026 deaths=' . count( $scored_pool ) . ' pre-lock=' . count( $prelock_pool ) . "\n";

/* ---------- league configuration ----------
 * One canonical main league ("Overall League {season}", via
 * Main_League_Service) carries the whole competition, so the site reads as
 * a single busy league. Side leagues are deliberately switched OFF for now:
 * to introduce them later, re-add specs here — the themed-picks, membership,
 * and standings stages below handle them automatically, and re-running the
 * seeder recreates them idempotently by name.
 */
$league_specs = array(
	// array( 'name' => 'The Final Chorus · Singers & Songwriters 2026', 'theme' => 'singer' ),
	// array( 'name' => 'Six Strings Attached · Guitar Greats 2026', 'theme' => 'guitar' ),
	// array( 'name' => 'Poets, Puns & Premonitions 2026', 'theme' => 'poet' ),
	// array( 'name' => 'Class of 1950 · The Yearbook League 2026', 'theme' => 'year' ),
	// array( 'name' => 'The Sporting Chance · Athletes 2026', 'theme' => 'athlete' ),
	// array( 'name' => 'Winter League 2026', 'theme' => 'open' ),
	// array( 'name' => 'Office Pool 2026', 'theme' => 'open' ),
	// array( 'name' => 'Celebrity Circle 2026', 'theme' => 'open' ),
);
// Team names are curated first, then generated with the user index appended
// (always distinct), so capacity is unbounded; sanity-floor instead.
if ( count( $team_names ) < 10 ) {
	throw new RuntimeException( 'Demo seed needs a base list of team names.' );
}
$league_ids = array();
$created_count = 0;
foreach ( $league_specs as $league_index => $spec ) {
	$name = $spec['name'];
	$league_id = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE name = %s AND season = %d ORDER BY id DESC LIMIT 1', $name, $season )
	);
	if ( ! $league_id ) {
		$owner_index = ( $league_index * 3 ) % count( $users );
		$owner_id = $users[ $owner_index ];
		$league_id = League_Service::create_league( $owner_id, $name, $season );
		$invite = League_Service::generate_invite( $league_id, $owner_id );
		for ( $offset = 1; $offset < count( $users ); ++$offset ) {
			$joiner_id = $users[ ( $owner_index + $offset ) % count( $users ) ];
			League_Service::join_with_token( $joiner_id, $invite['token'] );
		}
		++$created_count;
	}
	$league_ids[] = array( 'id' => $league_id, 'theme' => $spec['theme'], 'name' => $name );
}
echo 'side leagues configured: ' . count( $league_ids ) . " (new: {$created_count}) — main league carries the competition\n";

/* ---------- themed selectable picks ---------- */
$has_term = static function ( array $labels, array $terms ): bool {
	$text = strtolower( implode( ' ', $labels ) );
	foreach ( $terms as $term ) {
		if ( str_contains( $text, $term ) ) {
			return true;
		}
	}
	return false;
};
$theme_pool = static function ( string $theme ) use ( $theme_people, $has_term ): array {
	if ( 'open' === $theme ) {
		return $theme_people;
	}
	$needles = array(
		'singer' => array( 'singer', 'vocalist', 'songwriter', 'musician', 'rapper', 'opera singer' ),
		'guitar' => array( 'guitarist', 'guitar player' ),
		'poet' => array( 'poet', 'poetess', 'writer' ),
		'athlete' => array( 'athlete', 'football player', 'basketball player', 'baseball player', 'tennis player', 'sportsman', 'sportsperson' ),
	);
	if ( 'year' === $theme ) {
		return array_values( array_filter( $theme_people, static fn ( $person ): bool => 1950 === $person['birth_year'] ) );
	}
	return array_values( array_filter( $theme_people, static fn ( $person ): bool => $has_term( $person['occupations'], $needles[ $theme ] ?? array() ) ) );
};

$fill_unique = static function ( array &$picks, array $pool, int $target, int &$cursor, string $description ): void {
	$uuids = array_values( array_unique( array_filter( array_column( $pool, 'uuid' ) ) ) );
	if ( ! $uuids ) {
		throw new RuntimeException( 'No published sourced people available for ' . $description . '.' );
	}
	$attempts = 0;
	while ( count( $picks ) < $target && $attempts < count( $uuids ) ) {
		$candidate = $uuids[ $cursor % count( $uuids ) ];
		++$cursor;
		++$attempts;
		if ( ! in_array( $candidate, $picks, true ) ) {
			$picks[] = $candidate;
		}
	}
	if ( count( $picks ) < $target ) {
		throw new RuntimeException( 'Could not find enough distinct sourced people for ' . $description . '.' );
	}
};

$pick_cursor = 0;
$scored_cursor = 0;
$prelock_cursor = 0;
$submitted = 0;
foreach ( $league_ids as $league ) {
	$members = array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare( 'SELECT user_id FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d ORDER BY id ASC', $league['id'] )
		)
	);
	$demo_ids = array_map( 'intval', $users );
	$missing_demo_ids = array_values( array_diff( $demo_ids, $members ) );
	if ( $missing_demo_ids ) {
		$owner = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_user_id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league['id'] ) );
		$invite = League_Service::generate_invite( (int) $league['id'], $owner );
		foreach ( $missing_demo_ids as $demo_id ) {
			League_Service::join_with_token( $demo_id, $invite['token'] );
			$joined_at = sprintf( '2025-12-%02d 12:00:00', 3 + ( count( $members ) % 22 ) );
			$wpdb->update(
				$wpdb->prefix . 'obitleague_league_members',
				array( 'status' => 'member', 'joined_at' => $joined_at ),
				array( 'league_id' => (int) $league['id'], 'user_id' => (int) $demo_id ),
				array( '%s', '%s' ),
				array( '%d', '%d' )
			);
		}
		$members = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT user_id FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d ORDER BY id ASC', $league['id'] )
			)
		);
	}
	$demo_members = array_values( array_intersect( $members, $demo_ids ) );
	if ( count( $demo_members ) !== count( $demo_ids ) ) {
		throw new RuntimeException( 'Could not add every demo account to league: ' . $league['name'] );
	}
	foreach ( $demo_members as $member_index => $member_id ) {
		// The fixture models pre-lock participation, even when generated now.
		$joined_at = sprintf( '2025-12-%02d 12:00:00', 3 + ( $member_index % 22 ) );
		$wpdb->update(
			$wpdb->prefix . 'obitleague_league_members',
			array( 'status' => 'member', 'joined_at' => $joined_at ),
			array( 'league_id' => (int) $league['id'], 'user_id' => $member_id ),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
	}
	$themed_pool = $theme_pool( $league['theme'] );
	$theme_match_count = count( array_unique( array_column( $themed_pool, 'uuid' ) ) );
	if ( $theme_match_count < 8 ) {
		echo "theme '{$league['theme']}' has {$theme_match_count} sourced living matches; supplementing remaining slots from the verified living catalogue.\n";
	}

	foreach ( $demo_members as $user_index => $user_id ) {
		$entry_id = Entry_Service::get_or_create_entry( (int) $league['id'], $season, $user_id );
		if ( $user_index < count( $team_names ) ) {
			$team_name = $team_names[ $user_index ];
		} else {
			// Beyond the curated list, generate distinct names so no two
			// teams on a leaderboard share a label.
			$team_name = $team_prefixes[ $user_index % count( $team_prefixes ) ] . ' ' . $team_suffixes[ ( $user_index * 3 ) % count( $team_suffixes ) ] . ' ' . ( $user_index + 1 );
		}
		$wpdb->update(
			$wpdb->prefix . 'obitleague_entries',
			array( 'team_name' => $team_name ),
			array( 'id' => $entry_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( Entry_Service::submitted_revision( $entry_id ) ) {
			continue;
		}

		$picks = array();
		$themed_cursor = count( $themed_pool ) > 0 ? ( $pick_cursor + ( $user_index * 7 ) ) % count( $themed_pool ) : 0;
		$themed_target = min( 8, $theme_match_count );
		if ( $themed_target > 0 ) {
			$fill_unique( $picks, $themed_pool, $themed_target, $themed_cursor, 'theme-matched picks' );
		}
		$pick_cursor += 8;
		$fill_unique( $picks, $living_pool, 8, $pick_cursor, 'living picks' );
		$fill_unique( $picks, $scored_pool, 9, $scored_cursor, '2026 scoring pick' );
		$fill_unique( $picks, $prelock_pool, 10, $prelock_cursor, 'pre-lock pick' );

		$submitted_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_entries WHERE state = %s', 'submitted' ) );
		$backdate = new DateTimeImmutable( sprintf( '2025-12-%02dT%02d:00:00Z', 3 + ( $submitted_before % 22 ), 9 + ( $submitted_before % 12 ) ), new DateTimeZone( 'UTC' ) );
		$mysql = $backdate->format( 'Y-m-d H:i:s' );

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
			foreach ( $picks as $slot => $uuid ) {
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
		} catch ( Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
		++$submitted;
	}
}
echo "new demo submissions: {$submitted}\n";

/* ---------- canonical main league: the overall 2026 leaderboard ---------- */
$main_league_id = \Obitleague\Modules\Main_League_Service::ensure_league( $season );
$main_submitted = 0;
foreach ( $users as $user_index => $user_id ) {
	$entry_id = Entry_Service::get_or_create_entry( $main_league_id, $season, (int) $user_id );
	$team_name = $user_index < count( $team_names ) ? $team_names[ $user_index ] : ( $team_prefixes[ $user_index % count( $team_prefixes ) ] . ' ' . $team_suffixes[ ( $user_index * 3 ) % count( $team_suffixes ) ] . ' ' . ( $user_index + 1 ) );
	$wpdb->update(
		$wpdb->prefix . 'obitleague_entries',
		array( 'team_name' => $team_name, 'main_season_key' => $season ),
		array( 'id' => $entry_id ),
		array( '%s', '%d' ),
		array( '%d' )
	);
	if ( Entry_Service::submitted_revision( (int) $entry_id ) ) {
		continue;
	}
	$picks = array();
	$main_cursor = ( $user_index * 11 ) % max( 1, count( $living_pool ) );
	$fill_unique( $picks, $living_pool, 8, $main_cursor, 'main-league living picks' );
	$fill_unique( $picks, $scored_pool, 9, $scored_cursor, 'main-league 2026 scoring pick' );
	$fill_unique( $picks, $prelock_pool, 10, $prelock_cursor, 'main-league pre-lock pick' );

	$backdate = new DateTimeImmutable( sprintf( '2025-12-%02dT%02d:30:00Z', 3 + ( $user_index % 22 ), 10 + ( $user_index % 11 ) ), new DateTimeZone( 'UTC' ) );
	$mysql = $backdate->format( 'Y-m-d H:i:s' );
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
		foreach ( $picks as $slot => $uuid ) {
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
		++$main_submitted;
	} catch ( Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		throw $exception;
	}
}
echo "main-league submissions: {$main_submitted}\n";

/* ---------- realistic death spread across submitted entries ---------- */
// A real dead-pool season is lumpy: some teams hold nobody who has died
// yet, some hold two. The initial seed handed every entry exactly one 2026
// scoring pick, which reads as fake. This stage reshapes already-submitted
// entries (keeping the ten-pick team size and each pre-lock pick) toward a
// realistic spread, resets those entries' awards, and lets the idempotent
// award fan-out below recompute the points. Deterministic rolls keep
// re-runs stable: the same run sees the same targets and changes nothing.
$all_seed_league_ids = array_map( static fn ( array $league ): int => (int) $league['id'], $league_ids );
$all_seed_league_ids[] = (int) \Obitleague\Modules\Main_League_Service::ensure_league( $season );$entry_rows = (array) $wpdb->get_results(
	"SELECT DISTINCT e.id FROM {$wpdb->prefix}obitleague_entries e
	 JOIN {$wpdb->prefix}obitleague_entry_revisions r ON r.entry_id = e.id AND r.kind = 'submitted'
	 WHERE e.season = " . (int) $season . "
	   AND e.league_id IN ( " . implode( ',', $all_seed_league_ids ) . " )
	 ORDER BY e.id ASC"
);
$living_uuids  = array_values( array_unique( array_column( $living_pool, 'uuid' ) ) );
$scoring_uuids = array_values( array_unique( array_column( $scored_pool, 'uuid' ) ) );
$prelock_uuids = array_values( array_unique( array_column( $prelock_pool, 'uuid' ) ) );
$dead_uuids    = array_values( array_unique( array_merge( $scoring_uuids, $prelock_uuids ) ) );
mt_srand( 20260929 );
$rebalanced = 0;
foreach ( $entry_rows as $entry_row ) {
	$entry_id = (int) $entry_row->id;

	$revision_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id = %d AND kind = 'submitted' ORDER BY id DESC LIMIT 1",
			$entry_id
		)
	);
	if ( $revision_id < 1 ) {
		continue;
	}
	$current = array();
	foreach ( (array) $wpdb->get_results(
		$wpdb->prepare( "SELECT person_uuid FROM {$wpdb->prefix}obitleague_entry_picks WHERE revision_id = %d ORDER BY slot ASC", $revision_id )
	) as $pick_row ) {
		$current[] = (string) $pick_row->person_uuid;
	}
	if ( count( $current ) < 10 ) {
		continue;
	}

	$scoring_held = count( array_intersect( $current, $scoring_uuids ) );
	// Target spread: ~15% of teams no 2026 death yet, ~8% two, rest one.
	$roll   = mt_rand( 1, 100 );
	$target = $roll <= 15 ? 0 : ( $roll <= 23 ? 2 : 1 );
	if ( $scoring_held === $target ) {
		continue;
	}

	// Budget the ten slots: keep the pre-lock dead pick(s), choose the
	// target number of 2026 scoring people, and fill the remaining slots
	// with living picks — so a two-death team actually keeps both deaths
	// instead of having the extra one sliced off at the team-size cap.
	$kept_prelock = array_values( array_intersect( $current, $prelock_uuids ) );
	$kept_living  = array_values( array_diff( $current, $dead_uuids ) );
	$living_quota = 10 - $target - count( $kept_prelock );
	if ( $living_quota < 0 ) {
		continue; // Too many pre-lock picks to fit the target; leave untouched.
	}
	$kept_living = array_slice( $kept_living, 0, $living_quota );
	$chosen_scoring = array();
	$scoring_offset = ( $entry_id * 13 ) % max( 1, count( $scoring_uuids ) );
	for ( $attempt = 0; count( $chosen_scoring ) < $target && $attempt < count( $scoring_uuids ); ++$attempt ) {
		$candidate = $scoring_uuids[ ( $scoring_offset + $attempt ) % count( $scoring_uuids ) ];
		if ( ! in_array( $candidate, $kept_prelock, true ) && ! in_array( $candidate, $chosen_scoring, true ) ) {
			$chosen_scoring[] = $candidate;
		}
	}
	$updated = array_merge( $kept_prelock, $chosen_scoring, $kept_living );
	$offset   = ( $entry_id * 7 ) % max( 1, count( $living_uuids ) );
	for ( $attempt = 0; count( $updated ) < 10 && $attempt < count( $living_uuids ); ++$attempt ) {
		$candidate = $living_uuids[ ( $offset + $attempt ) % count( $living_uuids ) ];
		if ( ! in_array( $candidate, $updated, true ) ) {
			$updated[] = $candidate;
		}
	}
	if ( count( $updated ) < 10 ) {
		continue; // Pool too small to fill this team; leave it untouched.
	}
	$updated = array_slice( $updated, 0, 10 );

	$wpdb->query( 'START TRANSACTION' );
	try {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entry_picks WHERE revision_id = %d", $revision_id ) );
		foreach ( array_values( $updated ) as $slot_index => $uuid ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}obitleague_entry_picks (revision_id, slot, person_uuid) VALUES (%d, %d, %s)",
					$revision_id,
					$slot_index + 1,
					$uuid
				)
			);
		}
		// Drop this entry's 2026 awards so the fan-out below re-derives
		// them from the new pick set (operation keys make re-awards safe).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}obitleague_awards WHERE entry_id = %d AND season = %d",
				$entry_id,
				(int) $season
			)
		);
		$wpdb->query( 'COMMIT' );
		++$rebalanced;
	} catch ( Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		throw $exception;
	}
}
echo "entries reshaped for a realistic death spread: {$rebalanced}\n";

/* ---------- real approvals and score fan-out ---------- */
$editor_id = $users[0];
$approved = 0;
foreach ( $scored_pool as $item ) {
	if ( Review_Service::has_case_in_state( $item['uuid'], 'approved' ) ) {
		continue;
	}
	$row = $item['row'];
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
echo "new review approvals: {$approved}\n";
$processed = Outbox_Service::process_outbox( 500 );
echo "outbox processed: {$processed}\n";
$scored_uuids = array_values( array_unique( array_column( $scored_pool, 'uuid' ) ) );
if ( $scored_uuids ) {
	$placeholders = implode( ',', array_fill( 0, count( $scored_uuids ), '%s' ) );
	$event_uuids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT uuid FROM {$wpdb->prefix}obitleague_events WHERE person_uuid IN ({$placeholders}) AND approved_at IS NOT NULL AND retracted_at IS NULL",
			...$scored_uuids
		)
	);
	foreach ( (array) $event_uuids as $event_uuid ) {
		Outbox_Service::award_event( (string) $event_uuid );
	}
}

foreach ( $league_ids as $league ) {
	Standings_Service::rebuild( (int) $league['id'], $season );
	$rows = Standings_Service::current( (int) $league['id'], $season ) ?? array();
	echo "\n== {$league['name']} ==\n";
	foreach ( $rows as $row ) {
		printf( "  %2d. %-28s %3d pts (%d scoring picks)\n", $row['rank'], $row['player'], $row['points'], $row['scoring_picks'] );
	}
}
if ( ! $league_ids ) {
	echo "\n(no side leagues configured — the main league below carries the competition)\n";
}

/* ---------- canonical main-league standings ---------- */
Standings_Service::rebuild( $main_league_id, $season );
$main_rows = Standings_Service::current( $main_league_id, $season ) ?? array();
echo "\n== Overall League {$season} (" . count( $main_rows ) . " teams) ==\n";
foreach ( array_slice( $main_rows, 0, 10 ) as $row ) {
	printf( "  %2d. %-28s %3d pts (%d scoring picks)\n", $row['rank'], $row['player'], $row['points'], $row['scoring_picks'] );
}
echo "\ndemo ready.\n";

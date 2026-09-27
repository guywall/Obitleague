<?php
/**
 * Synthetic account importer: create natural-looking profiles for local demos.
 *
 * Accounts use reserved .test email addresses, random inaccessible passwords,
 * natural-looking public display names, and a private admin-only marker so they
 * can be filtered and removed later. This does not create league memberships.
 * The admin Users screen identifies these accounts; public profiles have no
 * synthetic label. Re-running safely skips existing obitleague_demo_NNNNN accounts.
 *
 * Run on a non-production site: wp eval-file tests/demo-import-users.php
 * Override count: OBITLEAGUE_DEMO_USER_COUNT=2500 wp eval-file tests/demo-import-users.php
 * Production requires OBITLEAGUE_ALLOW_DEMO_SEED=1 as an additional safeguard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$environment = wp_get_environment_type();
if ( 'production' === $environment && '1' !== getenv( 'OBITLEAGUE_ALLOW_DEMO_SEED' ) ) {
	throw new RuntimeException( 'Refusing to seed demo users in production. Set OBITLEAGUE_ALLOW_DEMO_SEED=1 only if this is intentional.' );
}

$count = (int) ( getenv( 'OBITLEAGUE_DEMO_USER_COUNT' ) ?: 2500 );
if ( $count < 1 || $count > 10000 ) {
	throw new RuntimeException( 'OBITLEAGUE_DEMO_USER_COUNT must be between 1 and 10000.' );
}

$first_names = array(
	'Alex', 'Avery', 'Bailey', 'Casey', 'Charlie', 'Dakota', 'Drew', 'Ellis', 'Emery', 'Finley',
	'Harper', 'Jamie', 'Jesse', 'Jordan', 'Kai', 'Logan', 'Morgan', 'Parker', 'Quinn', 'Riley',
	'Rowan', 'Sam', 'Skyler', 'Taylor', 'Cameron', 'Devon', 'Frankie', 'Hayden', 'Kendall', 'Reese',
	'Robin', 'Shay', 'Terry', 'Val', 'Billie', 'Corey', 'Remy', 'Sasha', 'Shannon', 'Toby',
);
$surnames = array(
	'Abbott', 'Bennett', 'Carter', 'Dawson', 'Ellis', 'Foster', 'Hayes', 'Morgan', 'Parker', 'Reed',
	'Bailey', 'Brooks', 'Cooper', 'Fletcher', 'Grant', 'Hughes', 'Kelly', 'Miller', 'Price', 'Turner',
	'Adler', 'Bishop', 'Clarke', 'Delaney', 'Everett', 'Finch', 'Gardner', 'Hollis', 'Mercer', 'Sutton',
	'Banks', 'Bell', 'Cross', 'Doyle', 'Fields', 'Hart', 'Lane', 'Nolan', 'Quinn', 'Shaw',
);
$team_prefixes = array(
	'Late Arrivals', 'Last Call', 'Final Whistle', 'The Longshots', 'After Hours', 'Lucky Break',
	'Good Omens', 'The Bench Warmers', 'Deadline Day', 'The Sunday Picks', 'Overtime Club', 'Full Time',
	'Northside', 'Harbour Athletic', 'Old Town', 'Riverside', 'The Underdogs', 'Extra Time',
);
$team_suffixes = array(
	'United', 'Athletic', 'All-Stars', 'XI', 'Rovers', 'Town', 'Legends', 'Collective', 'Club', 'FC',
);
$pun_names = array(
	'Ctrl Alt Defeat', 'Game of Throws', 'The Goal Diggers', 'No Kane No Gain', 'Net Results',
	'Pique Blinders', 'The Grass Hoppers', 'Victorious Secret', 'The Big Leagues', 'Expected Toulouse',
	'The Rolling Picks', 'Injury Time Travelers', 'The Casual Fixtures', 'VARiety Pack', 'Bench There Done That',
	'How I Met Your Mata', 'The Mighty Morphin Flower Arrangers', 'A League of Their Own',
);

$make_profile = static function ( int $index ) use ( $first_names, $surnames, $team_prefixes, $team_suffixes, $pun_names ): array {
	$first  = $first_names[ ( $index * 17 + 3 ) % count( $first_names ) ];
	$last   = $surnames[ ( $index * 23 + 7 ) % count( $surnames ) ];
	$year   = 1970 + ( $index * 13 % 37 );
	$style  = $index % 10;
	$handle = strtolower( $first . $last );
	$team   = $team_prefixes[ ( $index * 7 + 1 ) % count( $team_prefixes ) ] . ' ' . $team_suffixes[ ( $index * 3 + 2 ) % count( $team_suffixes ) ];

	switch ( $style ) {
		case 0:
			$display = $first . ' ' . $last;
			break;
		case 1:
			$display = strtolower( $first . ' ' . $last );
			break;
		case 2:
			$display = strtoupper( $first ) . ' ' . $last;
			break;
		case 3:
			$display = $first . ' ' . $last . ' ' . $year;
			break;
		case 4:
			$display = $handle . $year;
			break;
		case 5:
			$display = $team;
			break;
		case 6:
			$display = $first . "'s " . $team_suffixes[ ( $index * 5 + 4 ) % count( $team_suffixes ) ];
			break;
		case 7:
			$display = $pun_names[ (int) floor( $index / 10 ) % count( $pun_names ) ];
			break;
		case 8:
			$display = $first . ' ' . $last . ' · ' . $team_suffixes[ ( $index * 7 + 1 ) % count( $team_suffixes ) ];
			break;
		default:
			$display = $first . '_' . strtolower( $last ) . ( 80 + ( $index % 20 ) );
			break;
	}

	// Store a plausible team identity separately from the account display name.
	$team_name = 7 === $style
		? $display
		: ( 5 === $style ? $team : $team_prefixes[ ( $index * 11 + 5 ) % count( $team_prefixes ) ] . ' ' . $team_suffixes[ ( $index * 9 + 3 ) % count( $team_suffixes ) ] );

	return array( 'display' => $display, 'team' => $team_name );
};

$created = 0;
$skipped = 0;
for ( $index = 1; $index <= $count; ++$index ) {
	$login = sprintf( 'obitleague_demo_%05d', $index );
	if ( get_user_by( 'login', $login ) ) {
		++$skipped;
		continue;
	}

	$profile = $make_profile( $index );
	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32 ),
			'user_email'   => $login . '@example.test',
			'display_name' => $profile['display'],
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'Could not create demo account ' . $login . ': ' . $user_id->get_error_message() );
	}

	update_user_meta( (int) $user_id, 'obitleague_demo_account', 1 );
	update_user_meta( (int) $user_id, 'obitleague_demo_team_name', $profile['team'] );
	++$created;

	if ( 0 === $index % 250 ) {
		echo "Processed {$index}/{$count} demo profiles.\n";
	}
}

echo "Demo profile import complete: created={$created}, already_exists={$skipped}, requested={$count}.\n";
echo "Synthetic profiles are marked in the admin Users screen and are not league members.\n";

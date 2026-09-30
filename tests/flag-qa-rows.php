<?php
/**
 * Flag the synthetic QA leagues and accounts that were created while the
 * plugin was built, so they stop appearing as real players on public
 * surfaces.
 *
 * Nothing is deleted: this only sets the visibility flags that
 * Public_Scope owns (`obitleague_leagues.is_hidden` and the
 * `obitleague_test_account` user meta). Re-running is safe — a row that is
 * already flagged is reported as unchanged.
 *
 * Review mode is the default. Nothing is written unless
 * OBITLEAGUE_QA_APPLY=1 is set:
 *
 *   wp eval-file tests/flag-qa-rows.php
 *   OBITLEAGUE_QA_APPLY=1 wp eval-file tests/flag-qa-rows.php
 *
 * Targets come from three sources, unioned:
 *
 *   1. The built-in list below — the synthetic rows observed on the live
 *      site while the catalogue was being validated.
 *   2. OBITLEAGUE_QA_AUTO=1 — every league name and account that looks
 *      synthetic to Public_Scope's word-token rules, plus the owners of
 *      submitted teams whose team name looks synthetic.
 *   3. Explicit names, when Auto is too blunt or misses one:
 *        OBITLEAGUE_QA_LEAGUES="Testings,Guy's Test League 2027"
 *        OBITLEAGUE_QA_ACCOUNTS="qa.human.pass.1,rowan banks"
 *   4. Owners of submitted teams whose team name looks synthetic. The name a
 *      visitor sees is the team name, and a synthetic team ("QA Human Pass
 *      One") hides behind an opaque account login, so its owner is proposed
 *      even when the account itself matches nothing.
 *
 * Every proposal prints the reason it was made, and the run reports before it
 * writes, so the list can be reviewed before anything changes.
 *
 * Applying also rebuilds the current standings generation for every league,
 * so the published ranking is recomputed without the hidden teams rather
 * than left holding their rows.
 *
 * This runs through `wp eval-file`, which evaluates the file body, so it
 * deliberately declares no strict_types — a declare() would not be the first
 * statement and PHP would refuse the whole script.
 *
 * @package Obitleague
 */

use Obitleague\Modules\Public_Scope;
use Obitleague\Modules\Setup;
use Obitleague\Modules\Standings_Service;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

global $wpdb;

/*
 * The is_hidden column arrives with this release, but Setup::maybe_upgrade()
 * only runs on an admin page load — which a WP-CLI script never triggers. A
 * fresh deploy would otherwise read and write a column that does not exist
 * yet, so bring the schema up first. maybe_upgrade() self-guards on the
 * recorded version, so this is a no-op once the site is current.
 */
Setup::maybe_upgrade();

/* The synthetic rows observed on the live site. */
$qa_default_leagues  = array( 'Testings', "Guy's Test League 2027" );
$qa_default_accounts = array( 'barrywhite5-qkuhd8vf', 'Demo Account One QA', 'QA Human Pass One', 'QA Mobile Two' );

$apply = '1' === (string) getenv( 'OBITLEAGUE_QA_APPLY' );
$auto  = '1' === (string) getenv( 'OBITLEAGUE_QA_AUTO' );

/**
 * Split a comma-separated environment list into trimmed, non-empty values.
 *
 * @return string[]
 */
$list_env = static function ( string $name ): array {
	$raw = (string) getenv( $name );
	if ( '' === trim( $raw ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn ( string $v ): bool => '' !== $v ) );
};

$leagues_table = $wpdb->prefix . 'obitleague_leagues';
$column        = Public_Scope::LEAGUE_COLUMN;

/* ---------- gather targets ---------- */

$league_targets = array(); // id => array{ name, hidden }
$all_leagues    = $wpdb->get_results( "SELECT id, name, {$column} AS is_hidden FROM {$leagues_table} ORDER BY id ASC" );
$wanted_leagues = array_merge( $qa_default_leagues, $list_env( 'OBITLEAGUE_QA_LEAGUES' ) );

foreach ( (array) $all_leagues as $league ) {
	$name = (string) $league->name;
	$hit  = in_array( $name, $wanted_leagues, true );
	if ( ! $hit && $auto && Public_Scope::looks_like_test_league( $name ) ) {
		$hit = true;
	}
	if ( $hit ) {
		$league_targets[ (int) $league->id ] = array( 'name' => $name, 'hidden' => 1 === (int) $league->is_hidden );
	}
}

$account_targets = array(); // id => array{ login, display, test, why }
$wanted_accounts = array_merge( $qa_default_accounts, $list_env( 'OBITLEAGUE_QA_ACCOUNTS' ) );

/**
 * Record one account to hide, keeping the first reason it was proposed.
 *
 * @param array{login:string,display:string} $info
 */
$propose_account = static function ( int $id, array $info, string $why ) use ( &$account_targets ): void {
	if ( isset( $account_targets[ $id ] ) ) {
		return;
	}
	$account_targets[ $id ] = array(
		'login'   => $info['login'],
		'display' => $info['display'],
		'test'    => Public_Scope::is_test_user( $id ),
		'why'     => $why,
	);
};

$users = get_users(
	array(
		'number'  => 5000,
		'orderby' => 'ID',
		'order'   => 'ASC',
		'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name' ),
	)
);
foreach ( (array) $users as $user ) {
	$id      = (int) $user->ID;
	$login   = (string) $user->user_login;
	$display = (string) $user->display_name;
	$email   = (string) $user->user_email;

	foreach ( $wanted_accounts as $wanted ) {
		if ( 0 === strcasecmp( $login, $wanted ) || 0 === strcasecmp( $display, $wanted ) || 0 === strcasecmp( $email, $wanted ) ) {
			$propose_account( $id, array( 'login' => $login, 'display' => $display ), 'matches a listed name' );
			continue 2;
		}
	}
	if ( $auto && Public_Scope::looks_like_test_account( $login, $display, $email ) ) {
		$propose_account( $id, array( 'login' => $login, 'display' => $display ), 'synthetic-looking account name' );
	}
}

/*
 * The name a visitor actually sees on a leaderboard is the team name, and the
 * synthetic teams are named things like "QA Human Pass One" while the account
 * behind them is an opaque login. So a submitted team whose name looks
 * synthetic proposes its owner too — otherwise the visible row survives the
 * hide even though its account was never matched.
 */
$teams = $wpdb->get_results(
	"SELECT e.user_id, e.team_name, l.name AS league_name
	 FROM {$wpdb->prefix}obitleague_entries e
	 JOIN {$wpdb->prefix}obitleague_leagues l ON l.id = e.league_id
	 WHERE e.state = 'submitted'"
);
foreach ( (array) $teams as $team ) {
	$team_name = trim( (string) $team->team_name );
	if ( '' === $team_name ) {
		continue;
	}
	$looks_synthetic = $auto
		? Public_Scope::looks_like_test_account( $team_name, $team_name, '' ) || Public_Scope::looks_like_test_league( $team_name )
		: Public_Scope::looks_like_test_account( $team_name, $team_name, '' );
	if ( ! $looks_synthetic ) {
		continue;
	}
	$owner_id = (int) $team->user_id;
	$owner    = get_userdata( $owner_id );
	$propose_account(
		$owner_id,
		array( 'login' => $owner ? (string) $owner->user_login : '#' . $owner_id, 'display' => $owner ? (string) $owner->display_name : '' ),
		'owns the team "' . $team_name . '"'
	);
}

/* ---------- report ---------- */

WP_CLI::line( $apply ? 'APPLYING visibility flags (rows are kept).' : 'DRY RUN — no writes. Set OBITLEAGUE_QA_APPLY=1 to apply.' );
WP_CLI::line( '' );

$pending = 0;

WP_CLI::line( 'Leagues to hide:' );
if ( ! $league_targets ) {
	WP_CLI::line( '  (none matched)' );
}
foreach ( $league_targets as $id => $target ) {
	if ( $target['hidden'] ) {
		WP_CLI::line( sprintf( '  = #%d %s (already hidden)', $id, $target['name'] ) );
		continue;
	}
	++$pending;
	WP_CLI::line( sprintf( '  %s #%d %s', $apply ? '+' : '~', $id, $target['name'] ) );
	if ( $apply ) {
		Public_Scope::set_league_hidden( (int) $id, true, 'flagged by tests/flag-qa-rows.php' );
	}
}

WP_CLI::line( '' );
WP_CLI::line( 'Accounts to hide:' );
if ( ! $account_targets ) {
	WP_CLI::line( '  (none matched)' );
}
foreach ( $account_targets as $id => $target ) {
	if ( $target['test'] ) {
		WP_CLI::line( sprintf( '  = #%d %s (already flagged)', $id, $target['login'] ) );
		continue;
	}
	++$pending;
	WP_CLI::line( sprintf( '  %s #%d %s — "%s" (%s)', $apply ? '+' : '~', $id, $target['login'], $target['display'], $target['why'] ) );
	if ( $apply ) {
		Public_Scope::set_user_test( (int) $id, true, 'flagged by tests/flag-qa-rows.php' );
	}
}

WP_CLI::line( '' );
if ( ! $apply ) {
	WP_CLI::line( sprintf( '%d change(s) pending. Re-run with OBITLEAGUE_QA_APPLY=1 to apply.', $pending ) );
	return;
}

WP_CLI::line( sprintf( 'Applied %d change(s). Rows were kept; only the visibility flags changed.', $pending ) );

/*
 * The published standings are themselves a public artifact, and a generation
 * built before the flags still ranks the hidden teams. Rebuild every current
 * generation so the printed ranks match the scope.
 */
$generations = $wpdb->get_results(
	"SELECT DISTINCT league_id, season FROM {$wpdb->prefix}obitleague_standings_generations WHERE is_current = 1"
);
if ( ! $generations ) {
	WP_CLI::line( 'No published standings to rebuild.' );
	return;
}
foreach ( (array) $generations as $generation ) {
	try {
		Standings_Service::rebuild( (int) $generation->league_id, (int) $generation->season );
		WP_CLI::line( sprintf( '  rebuilt league #%d season %d', (int) $generation->league_id, (int) $generation->season ) );
	} catch ( \Throwable $exception ) {
		WP_CLI::warning( sprintf( 'league #%d season %d: %s', (int) $generation->league_id, (int) $generation->season, $exception->getMessage() ) );
	}
}

WP_CLI::success( 'Public surfaces now exclude the flagged rows.' );

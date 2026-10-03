<?php
declare( strict_types = 1 );

namespace {
	function check( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new \RuntimeException( 'FAIL: ' . $message ); }
		echo "ok - {$message}\n";
	}

	function response_header_matches( array $headers, string $name, string $value ): bool {
		foreach ( $headers as $header ) {
			if ( 0 === stripos( $header, $name . ':' ) && $value === trim( substr( $header, strlen( $name ) + 1 ) ) ) { return true; }
		}
		return false;
	}
}

namespace Obitleague\StandaloneTests {

require_once __DIR__ . '/../src/Domain/Value/Ruleset.php';
require_once __DIR__ . '/../src/Domain/Value/Partial_Date.php';
require_once __DIR__ . '/../src/Domain/Age.php';
require_once __DIR__ . '/../src/Domain/Deadline_Policy.php';
require_once __DIR__ . '/../src/Domain/Invalid_Team_Exception.php';
require_once __DIR__ . '/../src/Domain/Entry_Rules.php';
require_once __DIR__ . '/../src/Domain/Discovery_Rules.php';
require_once __DIR__ . '/../app/Runtime.php';

use Obitleague\Standalone\Clock;
use Obitleague\Standalone\Runtime;
use function check;

/**
 * A clock that reports the transaction's commit instant while a transaction is
 * open and the request instant otherwise — modelling a request that starts
 * before the deadline but whose transaction commits at or after it.
 */
final class Commit_Clock extends Clock {
	private \Closure $in_transaction;

	public function __construct( private \DateTimeImmutable $request, private \DateTimeImmutable $commit, \Closure $in_transaction ) {
		$this->in_transaction = $in_transaction;
	}

	public function instant(): \DateTimeImmutable {
		return ( $this->in_transaction )() ? $this->commit : $this->request;
	}
}

$directory = sys_get_temp_dir() . '/obitleague-' . bin2hex( random_bytes( 6 ) );
mkdir( $directory, 0700 );
$path = $directory . '/runtime.sqlite';
$runtime = new Runtime( $path, dirname( __DIR__ ) );
$db = $runtime->database();
$season = (int) ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/London' ) ) )->format( 'Y' );
$picks = array();

try {
	for ( $i = 1; $i <= 10; ++$i ) {
		$uuid = sprintf( 'person-%02d', $i );
		$picks[] = $uuid;
		$stmt = $db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, NULL, ?, ?)' );
		$stmt->execute( array( $uuid, 'Person ' . $i, '1980-01-01', 'approved', gmdate( 'Y-m-d H:i:s' ) ) );
	}
	$db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, ?, ?, ?)' )->execute( array( 'dead', 'Dead Person', '1940-01-01', $season . '-01-02', 'approved', gmdate( 'Y-m-d H:i:s' ) ) );
	$db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, NULL, ?, ?)' )->execute( array( 'candidate', 'Unapproved Candidate', '1980-01-01', 'candidate', gmdate( 'Y-m-d H:i:s' ) ) );
	$db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, NULL, ?, ?)' )->execute( array( 'minor', 'Under Age Person', ( $season - 10 ) . '-01-01', 'approved', gmdate( 'Y-m-d H:i:s' ) ) );
	$db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, NULL, ?, ?)' )->execute( array( 'person-11', 'Person 11', '1980-01-01', 'approved', gmdate( 'Y-m-d H:i:s' ) ) );

	$register = $runtime->handle( 'POST', '/register', array(), array( 'username' => 'alice', 'display_name' => 'Alice Example', 'password' => 'correct horse battery staple' ) );
	check( 303 === $register['status'] && '/team' === $register['headers']['Location'], 'registration provisions an account, main league membership, and team route' );
	$weak_register = $runtime->handle( 'POST', '/register', array(), array( 'username' => 'bob', 'display_name' => 'Bob Example', 'password' => 'too-short' ) );
	check( 422 === $weak_register['status'], 'registration enforces the credential rules' );
	preg_match( '/obl_session=([a-f0-9]{64})/', $register['headers']['Set-Cookie'], $cookie_match );
	$cookie = 'obl_session=' . $cookie_match[1];
	$alice_cookie = $cookie;
	$csrf = $register['headers']['X-CSRF-Token'];
	$headers = array( 'Cookie' => $cookie, 'X-CSRF-Token' => $csrf );
	$team_page = $runtime->handle( 'GET', '/team', array(), array(), $headers );
	check( 200 === $team_page['status'] && str_contains( $team_page['body'], 'Alice Example’s team' ) && str_contains( $team_page['body'], 'name="picks[]"' ), 'authenticated user sees the rendered main-team builder' );
	check( ! str_contains( $team_page['body'], 'value="minor"' ) && ! str_contains( $team_page['body'], 'Under Age Person' ), 'under-age approved people are not listed in the picker' );
	$before = $db->query( 'SELECT COUNT(*) AS entries, COALESCE(MAX(expected_version), 0) AS version FROM entries' )->fetch( \PDO::FETCH_ASSOC );
	$empty_save = $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => '1', '_csrf' => $csrf, 'picks' => array() ), $headers );
	$after = $db->query( 'SELECT COUNT(*) AS entries, COALESCE(MAX(expected_version), 0) AS version FROM entries' )->fetch( \PDO::FETCH_ASSOC );
	check( 422 === $empty_save['status'] && $before === $after && 0 === (int) $db->query( 'SELECT COUNT(*) FROM entry_picks' )->fetchColumn(), 'empty team input is rejected without creating or changing an entry' );
	check( false !== strpos( $team_page['body'], 'value="person-10"' ) && 'private, no-store' === $team_page['headers']['Cache-Control'], 'eligible picks render in a non-cacheable private team page' );
	check( 401 === $runtime->handle( 'GET', '/team' )['status'], 'team route refuses unauthenticated access' );
	check( array() === $runtime->standings( $season ), 'standings are empty before any team is submitted' );

	$save = $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => '1', '_csrf' => $csrf, 'picks' => $picks ), $headers );
	check( 200 === $save['status'] && str_contains( $save['body'], 'Draft saved. Version 2.' ), 'ten eligible distinct picks save as a versioned private draft' );
	$entry = $db->query( 'SELECT e.* FROM entries e' )->fetch( \PDO::FETCH_ASSOC );
	check( 'draft' === $entry['state'] && 10 === (int) $db->query( 'SELECT COUNT(*) FROM entry_picks' )->fetchColumn(), 'draft and all ten picks persist in SQLite' );
	check( 409 === $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => '1', '_csrf' => $csrf, 'picks' => $picks ), $headers )['status'], 'stale expected-version write is rejected' );

	$db->prepare( 'UPDATE people SET death_date = ?, eligibility = ? WHERE uuid = ?' )->execute( array( $season . '-09-01', 'approved', 'person-01' ) );
	$still_valid = $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => '2', '_csrf' => $csrf, 'picks' => $picks ), $headers );
	check( 200 === $still_valid['status'] && str_contains( $still_valid['body'], 'value="person-01" selected' ), 'existing selections remain valid and visible when a selected person later becomes unselectable' );
	$version = (int) $db->query( 'SELECT expected_version FROM entries' )->fetchColumn();

	$duplicate = $picks;
	$duplicate[9] = $duplicate[0];
	check( 422 === $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => $version, '_csrf' => $csrf, 'picks' => $duplicate ), $headers )['status'], 'duplicate picks fail server-side validation' );
	$dead = $picks;
	$dead[0] = 'dead';
	check( 422 === $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => $version, '_csrf' => $csrf, 'picks' => $dead ), $headers )['status'], 'dead catalogue records cannot enter a team' );
	$unapproved = $picks;
	$unapproved[0] = 'candidate';
	check( 422 === $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => $version, '_csrf' => $csrf, 'picks' => $unapproved ), $headers )['status'], 'unapproved candidates cannot enter a team' );
	$minor = $picks;
	$minor[0] = 'minor';
	check( 422 === $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => $version, '_csrf' => $csrf, 'picks' => $minor ), $headers )['status'], 'under-age people cannot enter a team' );
	check( 403 === $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => $version, 'picks' => $picks ), array( 'Cookie' => $cookie ) )['status'], 'mutating requests require CSRF token' );

	$submit = $runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $csrf, 'idempotency_key' => 'submit-alice-1' ), $headers );
	check( 200 === $submit['status'] && str_contains( $submit['body'], 'Your ten-pick team is submitted' ), 'team submission returns a visible confirmation' );
	$submitted = $db->query( 'SELECT e.state, e.submitted_revision_id, r.submitted_at, r.ruleset_version FROM entries e JOIN entry_revisions r ON r.id = e.submitted_revision_id' )->fetch( \PDO::FETCH_ASSOC );
	check( 'submitted' === $submitted['state'] && '' !== $submitted['submitted_at'] && '1' === $submitted['ruleset_version'] && 10 === (int) $db->query( 'SELECT COUNT(*) FROM entry_picks WHERE revision_id = ' . (int) $submitted['submitted_revision_id'] )->fetchColumn(), 'versioned submitted revision and server timestamp persist with all picks' );
	$idempotent = $runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $csrf, 'idempotency_key' => 'submit-alice-1' ), $headers );
	check( 200 === $idempotent['status'] && 1 === (int) $db->query( "SELECT COUNT(*) FROM entry_revisions WHERE kind = 'submitted'" )->fetchColumn(), 'replaying the same submission key does not duplicate submission' );
	$submitted_version = (int) $db->query( 'SELECT expected_version FROM entries' )->fetchColumn();
	$amended = $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => (string) $submitted_version, '_csrf' => $csrf, 'picks' => $picks ), $headers );
	check( 200 === $amended['status'] && str_contains( $amended['body'], 'Team amended.' ) && 1 === (int) $db->query( "SELECT COUNT(*) FROM entry_revisions WHERE kind = 'submitted'" )->fetchColumn(), 'amending a submitted entry atomically supersedes its prior competing revision' );
	$replay_old_key = $runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $csrf, 'idempotency_key' => 'submit-alice-1' ), $headers );
	check( 409 === $replay_old_key['status'], 'an idempotency key cannot replay after a newer amendment' );

	$login = $runtime->handle( 'POST', '/login', array(), array( 'username' => 'alice', 'password' => 'wrong password' ) );
	check( 401 === $login['status'], 'incorrect password is rejected' );
	$public = $runtime->handle( 'GET', '/people', array( 'q' => 'Unapproved' ) );
	check( ! str_contains( $public['body'], 'Unapproved Candidate' ), 'public catalogue excludes unapproved candidates' );
	$public_deaths = $runtime->handle( 'GET', '/people', array( 'living' => '0' ) );
	check( str_contains( $public_deaths['body'], 'Dead Person' ), 'dead profiles remain visible in the public catalogue but are not pickable' );

	$db->prepare( 'UPDATE people SET death_date = NULL WHERE uuid = ?' )->execute( array( 'person-01' ) );
	$socket = stream_socket_server( 'tcp://127.0.0.1:0', $socket_error, $socket_message );
	check( false !== $socket, 'reserve an ephemeral loopback port for the HTTP entry-point test' );
	$address = stream_socket_get_name( $socket, false );
	$port = (int) substr( (string) strrchr( (string) $address, ':' ), 1 );
	fclose( $socket );
	$environment = getenv();
	$environment['OBITLEAGUE_DB'] = $path;
	$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname( __DIR__ ) . '/app', dirname( __DIR__ ) . '/app/index.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ), $environment );
	check( is_resource( $server ), 'start PHP built-in server with an isolated SQLite database' );
	try {
		$ready = false;
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$probe = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $connect_error, $connect_message, 0.1 );
			if ( false !== $probe ) { fclose( $probe ); $ready = true; break; }
			usleep( 50000 );
		}
		check( $ready, 'HTTP server starts on its isolated loopback port' );
		$request = static function ( string $method, string $path, array $fields = array(), array $headers = array() ) use ( $port ): array {
			$headers = array_merge( array( 'Content-Type: application/x-www-form-urlencoded' ), $headers );
			$context = stream_context_create( array( 'http' => array(
				'method' => $method,
				'header' => implode( "\r\n", $headers ),
				'content' => 'POST' === $method ? http_build_query( $fields ) : '',
				'ignore_errors' => true,
				'follow_location' => 0,
			) ) );
			$body = file_get_contents( 'http://127.0.0.1:' . $port . $path, false, $context );
			$response_headers = $http_response_header ?? array();
			preg_match( '/^HTTP\/\S+\s+(\d+)/', $response_headers[0] ?? '', $status_match );
			return array( 'status' => (int) ( $status_match[1] ?? 0 ), 'headers' => $response_headers, 'body' => false === $body ? '' : $body );
		};
		$home_http = $request( 'GET', '/' );
		check( 200 === $home_http['status'] && str_contains( $home_http['body'], 'Obitleague' ), 'real HTTP entry point renders the public homepage' );
		$people_http = $request( 'GET', '/people?q=Person' );
		check( 200 === $people_http['status'] && str_contains( $people_http['body'], 'Person 10' ), 'real HTTP catalogue route returns matching public people' );
		$http_username = 'http' . bin2hex( random_bytes( 4 ) );
		$http_register = $request( 'POST', '/register', array( 'username' => $http_username, 'display_name' => 'HTTP Alice', 'password' => 'correct horse battery staple' ) );
		$cookie_header = '';
		$csrf_header = '';
		foreach ( $http_register['headers'] as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' ) ) { $cookie_header = trim( substr( $header, strlen( 'Set-Cookie:' ) ) ); }
			if ( 0 === stripos( $header, 'X-CSRF-Token:' ) ) { $csrf_header = trim( substr( $header, strlen( 'X-CSRF-Token:' ) ) ); }
		}
		check( 303 === $http_register['status'] && str_contains( $cookie_header, 'obl_session=' ) && '' !== $csrf_header, 'HTTP registration persists the account and sets session plus CSRF credentials' );
		$cookie = explode( ';', $cookie_header, 2 )[0];
		$http_team = $request( 'GET', '/team', array(), array( 'Cookie: ' . $cookie ) );
		check( 200 === $http_team['status'] && str_contains( $http_team['body'], 'HTTP Alice’s team' ) && \response_header_matches( $http_team['headers'], 'Cache-Control', 'private, no-store' ), 'HTTP session authenticates owner-only non-cacheable team page' );
		$http_csrf = $request( 'POST', '/team/save', array( '_csrf' => 'invalid' ), array( 'Cookie: ' . $cookie ) );
		check( 403 === $http_csrf['status'], 'HTTP mutation rejects an invalid CSRF token' );
		$http_fields = array( 'season' => $season, 'expected_version' => 1, '_csrf' => $csrf_header );
		$http_fields['picks'] = $picks;
		$http_save = $request( 'POST', '/team/save', $http_fields, array( 'Cookie: ' . $cookie ) );
		check( 200 === $http_save['status'] && str_contains( $http_save['body'], 'Draft saved.' ), 'HTTP mutation accepts valid CSRF and persists a draft' );
		check( 10 === (int) $db->query( "SELECT COUNT(*) FROM entry_picks p JOIN entry_revisions r ON r.id = p.revision_id JOIN entries e ON e.id = r.entry_id JOIN accounts a ON a.id = e.account_id WHERE a.username LIKE 'http%'" )->fetchColumn(), 'HTTP-saved picks are durable in SQLite' );
		$http_submit = $request( 'POST', '/team/submit', array( 'season' => $season, '_csrf' => $csrf_header, 'idempotency_key' => 'http-submit-1' ), array( 'Cookie: ' . $cookie ) );
		check( 200 === $http_submit['status'] && str_contains( $http_submit['body'], 'submitted' ), 'HTTP team submission joins the competition' );

		// Pin the two teams' submission instants deterministically: alice submitted
		// before the death, the HTTP team after it.
		$stamp = static function ( string $username, string $submitted ) use ( $db, $season ): void {
			$db->prepare( 'UPDATE entry_revisions SET submitted_at = ? WHERE id = (SELECT e.submitted_revision_id FROM entries e JOIN accounts a ON a.id = e.account_id WHERE a.username = ? AND e.season = ?)' )->execute( array( $submitted, $username, $season ) );
		};
		$stamp( 'alice', $season . '-01-10 00:00:00' );
		$stamp( $http_username, $season . '-09-20 00:00:00' );

		$ranking_before = $runtime->standings( $season );
		check( 2 === count( $ranking_before ) && 0 === $ranking_before[0]['points'] && $ranking_before[0]['rank'] === $ranking_before[1]['rank'], 'two submitted teams start tied with no points' );
		$standings_http = $request( 'GET', '/standings' );
		check( 200 === $standings_http['status'] && str_contains( $standings_http['body'], 'href="/standings"' ) && str_contains( $standings_http['body'], 'Alice Example' ) && str_contains( $standings_http['body'], 'HTTP Alice' ), 'the standings route is reachable from navigation and lists submitted teams' );

		$expected_points = max( 1, 100 - ( $season - 1980 ) );
		$db->prepare( 'UPDATE people SET death_date = ? WHERE uuid = ?' )->execute( array( $season . '-06-15', 'person-01' ) );
		$confirm = $runtime->confirm_death( 'person-01' );
		check( 'awarded' === $confirm['status'] && $expected_points === $confirm['points'], 'confirming a death awards points through the pure scoring rule' );
		check( $expected_points === (int) $db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 'the award lands in the append-only ledger' );
		$repeat = $runtime->confirm_death( 'person-01' );
		check( 'unchanged' === $repeat['status'] && $expected_points === (int) $db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 're-confirming the same death is idempotent' );

		$ranking_after = $runtime->standings( $season );
		check( 'Alice Example' === $ranking_after[0]['player'] && $expected_points === $ranking_after[0]['points'] && 1 === $ranking_after[0]['rank'], 'a team that submitted before the death gains the points and moves up' );
		check( 'HTTP Alice' === $ranking_after[1]['player'] && 0 === $ranking_after[1]['points'] && 2 === $ranking_after[1]['rank'], 'a team that submitted after the death gains no points' );
		$stamp( $http_username, $season . '-06-15 10:30:00' );
		$same_day = $runtime->standings( $season );
		check( 0 === $same_day[1]['points'], 'a same-day daytime submission cannot score a midnight death' );
		unset( $stamp ); // Release the closure's PDO handle so cleanup can remove the database.
		$standings_after = $request( 'GET', '/standings' );
		check( 200 === $standings_after['status'] && str_contains( $standings_after['body'], (string) $expected_points ), 'the standings route reflects the award over HTTP' );
		$owner_page = $request( 'GET', '/team', array(), array( 'Cookie: ' . $alice_cookie ) );
		check( 200 === $owner_page['status'] && str_contains( $owner_page['body'], 'rank 1 with ' . $expected_points . ' points' ), 'the team page shows its owner the live standing' );

		$db->prepare( 'UPDATE people SET death_date = NULL WHERE uuid = ?' )->execute( array( 'person-01' ) );
		$withdrawn = $runtime->confirm_death( 'person-01' );
		check( 'reversed' === $withdrawn['status'], 'withdrawing the death reverses the award' );
		$ranking_reversed = $runtime->standings( $season );
		check( 0 === $ranking_reversed[0]['points'] && $ranking_reversed[0]['rank'] === $ranking_reversed[1]['rank'], 'reversal restores the original tied ranking' );

		$db->prepare( 'UPDATE people SET death_date = ? WHERE uuid = ?' )->execute( array( $season . '-06-15', 'person-01' ) );
		$restored = $runtime->confirm_death( 'person-01' );
		check( 'awarded' === $restored['status'] && $expected_points === (int) $db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 're-confirming after a withdrawal restores the award' );

		// An amendment depends only on the picks it changes.
		$alice_entry   = (int) $db->query( "SELECT id FROM entries WHERE account_id = (SELECT id FROM accounts WHERE username = 'alice')" )->fetchColumn();
		$alice_version = (int) $db->query( 'SELECT expected_version FROM entries WHERE id = ' . $alice_entry )->fetchColumn();
		$keep = $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => (string) $alice_version, '_csrf' => $csrf, 'picks' => $picks ), $headers );
		check( 200 === $keep['status'], 'a submitted team can be amended after scoring' );
		$survived = $runtime->standings( $season );
		check( 'Alice Example' === $survived[0]['player'] && $expected_points === $survived[0]['points'], 'a surviving pick keeps the points it already earned across an amendment' );

		$trimmed = array_slice( $picks, 1 );
		$trimmed[] = 'person-11';
		$alice_version = (int) $db->query( 'SELECT expected_version FROM entries WHERE id = ' . $alice_entry )->fetchColumn();
		$drop = $runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => (string) $alice_version, '_csrf' => $csrf, 'picks' => $trimmed ), $headers );
		check( 200 === $drop['status'], 'a submitted team can drop a scoring pick' );
		$dropped = $runtime->standings( $season );
		check( 0 === $dropped[0]['points'], 'a removed pick loses only its own points' );

		// A pick added by the amendment does not retroactively score an earlier death.
		$db->prepare( 'UPDATE people SET death_date = ? WHERE uuid = ?' )->execute( array( gmdate( 'Y-m-d', time() - 86400 ), 'person-11' ) );
		$runtime->confirm_death( 'person-11' );
		$added = $runtime->standings( $season );
		check( 0 === $added[0]['points'], 'a pick added after a death does not retroactively score it' );
	} finally {
		proc_terminate( $server );
		foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		proc_close( $server );
	}

	// The deadline lock must be authoritative on the amend path. Pin the clock to
	// the season's entry deadline (23:59:59 Europe/London on 31 December) so the
	// boundary is observable: one second before it accepts, at it it refuses.
	$lock_path   = $directory . '/lock.sqlite';
	$lock_before = sprintf( '%d-12-31T23:59:58+00:00', $season );
	$lock_at     = sprintf( '%d-12-31T23:59:59+00:00', $season );
	$before_runtime = new Runtime( $lock_path, dirname( __DIR__ ), new Clock( new \DateTimeImmutable( $lock_before ) ) );
	$lock_db = $before_runtime->database();
	foreach ( $picks as $index => $uuid ) {
		$lock_db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, NULL, ?, ?)' )
			->execute( array( $uuid, 'Lock ' . ( $index + 1 ), '1980-01-01', 'approved', $lock_before ) );
	}
	$lock_register = $before_runtime->handle( 'POST', '/register', array(), array( 'username' => 'lockuser', 'display_name' => 'Lock User', 'password' => 'correct horse battery staple' ) );
	preg_match( '/obl_session=([a-f0-9]{64})/', $lock_register['headers']['Set-Cookie'], $lock_cookie_match );
	$lock_csrf = $lock_register['headers']['X-CSRF-Token'];
	$lock_headers = array( 'Cookie' => 'obl_session=' . $lock_cookie_match[1], 'X-CSRF-Token' => $lock_csrf );
	$before_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => '1', '_csrf' => $lock_csrf, 'picks' => $picks ), $lock_headers );
	$before_runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $lock_csrf, 'idempotency_key' => 'lock-submit-1' ), $lock_headers );
	$lock_version = static function () use ( $lock_db ): int {
		return (int) $lock_db->query( "SELECT e.expected_version FROM entries e JOIN accounts a ON a.id = e.account_id WHERE a.username = 'lockuser'" )->fetchColumn();
	};
	$before_amend = $before_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => (string) $lock_version(), '_csrf' => $lock_csrf, 'picks' => $picks ), $lock_headers );
	check( 200 === $before_amend['status'] && str_contains( $before_amend['body'], 'Team amended.' ), 'a submitted team can be amended one second before the deadline lock' );

	$lock_runtime = new Runtime( $lock_path, dirname( __DIR__ ), new Clock( new \DateTimeImmutable( $lock_at ) ) );
	$lock_counts = static function () use ( $lock_db ): array {
		return array(
			(int) $lock_db->query( 'SELECT COUNT(*) FROM entries' )->fetchColumn(),
			(int) $lock_db->query( 'SELECT COUNT(*) FROM entry_revisions' )->fetchColumn(),
			(int) $lock_db->query( 'SELECT COUNT(*) FROM entry_picks' )->fetchColumn(),
			(int) $lock_db->query( "SELECT e.expected_version FROM entries e JOIN accounts a ON a.id = e.account_id WHERE a.username = 'lockuser'" )->fetchColumn(),
		);
	};
	$counts_before = $lock_counts();
	$locked_amend = $lock_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => (string) $lock_version(), '_csrf' => $lock_csrf, 'picks' => $picks ), $lock_headers );
	check( 409 === $locked_amend['status'] && str_contains( $locked_amend['body'], 'entry window is closed' ), 'an amend at the entry deadline is refused as locked, not accepted' );
	check( $counts_before === $lock_counts(), 'a refused post-lock amend changes no entry, revision, pick, or version' );
	$locked_submit = $lock_runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $lock_csrf, 'idempotency_key' => 'lock-submit-2' ), $lock_headers );
	check( 409 === $locked_submit['status'] && $counts_before === $lock_counts(), 'a post-lock submit is refused without writing' );
	$locked_replay = $lock_runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $lock_csrf, 'idempotency_key' => 'lock-submit-1' ), $lock_headers );
	check( 409 === $locked_replay['status'] && $counts_before === $lock_counts(), 'a post-lock retry of an earlier submission key is refused, not accepted' );
	$absent_amend = $lock_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season - 3, 'expected_version' => '1', '_csrf' => $lock_csrf, 'picks' => $picks ), $lock_headers );
	check( 409 === $absent_amend['status'] && $counts_before === $lock_counts(), 'a locked season with no league is refused as locked without creating an entry' );
	$late_register = $lock_runtime->handle( 'POST', '/register', array(), array( 'username' => 'latejoin', 'display_name' => 'Late Join', 'password' => 'correct horse battery staple' ) );
	$late_status = (string) $lock_db->query( "SELECT m.status FROM league_members m JOIN accounts a ON a.id = m.account_id WHERE a.username = 'latejoin'" )->fetchColumn();
	check( 303 === $late_register['status'] && 'spectator' === $late_status, 'joining a locked season is a spectator membership, not an editable team' );

	// Prove the same boundary through the real HTTP entry point, with the server
	// process itself pinned to the boundary instants.
	$pinned_server = static function ( string $pin ) use ( $lock_path ): array {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $socket_error, $socket_message );
		$address = stream_socket_get_name( $socket, false );
		$port = (int) substr( (string) strrchr( (string) $address, ':' ), 1 );
		fclose( $socket );
		$environment = getenv();
		$environment['OBITLEAGUE_DB'] = $lock_path;
		$environment['OBITLEAGUE_NOW'] = $pin;
		$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname( __DIR__ ) . '/app', dirname( __DIR__ ) . '/app/index.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ), $environment );
		$ready = false;
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$probe = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $connect_error, $connect_message, 0.1 );
			if ( false !== $probe ) { fclose( $probe ); $ready = true; break; }
			usleep( 50000 );
		}
		$request = static function ( string $method, string $path, array $fields = array(), array $headers = array() ) use ( $port ): array {
			$headers = array_merge( array( 'Content-Type: application/x-www-form-urlencoded' ), $headers );
			$context = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => implode( "\r\n", $headers ), 'content' => 'POST' === $method ? http_build_query( $fields ) : '', 'ignore_errors' => true, 'follow_location' => 0 ) ) );
			$body = file_get_contents( 'http://127.0.0.1:' . $port . $path, false, $context );
			$response_headers = $http_response_header ?? array();
			preg_match( '/^HTTP\/\S+\s+(\d+)/', $response_headers[0] ?? '', $status_match );
			return array( 'status' => (int) ( $status_match[1] ?? 0 ), 'headers' => $response_headers, 'body' => false === $body ? '' : $body );
		};
		return array( $server, $pipes, $ready, $request );
	};
	$http_lock_cookie = '';
	$http_lock_csrf = '';

	list( $before_server, $before_pipes, $before_ready, $before_request ) = $pinned_server( $lock_before );
	check( $before_ready && is_resource( $before_server ), 'a clock-pinned server starts just before the deadline lock' );
	try {
		$lock_http_register = $before_request( 'POST', '/register', array( 'username' => 'httplock', 'display_name' => 'HTTP Lock', 'password' => 'correct horse battery staple' ) );
		foreach ( $lock_http_register['headers'] as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' ) ) { $http_lock_cookie = explode( ';', trim( substr( $header, strlen( 'Set-Cookie:' ) ) ), 2 )[0]; }
			if ( 0 === stripos( $header, 'X-CSRF-Token:' ) ) { $http_lock_csrf = trim( substr( $header, strlen( 'X-CSRF-Token:' ) ) ); }
		}
		$open_save = $before_request( 'POST', '/team/save', array( 'season' => $season, 'expected_version' => 1, '_csrf' => $http_lock_csrf, 'picks' => $picks ), array( 'Cookie: ' . $http_lock_cookie ) );
		$before_request( 'POST', '/team/submit', array( 'season' => $season, '_csrf' => $http_lock_csrf, 'idempotency_key' => 'http-lock-1' ), array( 'Cookie: ' . $http_lock_cookie ) );
		$http_version = (int) $lock_db->query( "SELECT e.expected_version FROM entries e JOIN accounts a ON a.id = e.account_id WHERE a.username = 'httplock'" )->fetchColumn();
		$open_amend = $before_request( 'POST', '/team/save', array( 'season' => $season, 'expected_version' => $http_version, '_csrf' => $http_lock_csrf, 'picks' => $picks ), array( 'Cookie: ' . $http_lock_cookie ) );
		check( 200 === $open_save['status'] && 200 === $open_amend['status'], 'over HTTP, a team can be amended just before the deadline lock' );
	} finally {
		proc_terminate( $before_server );
		foreach ( $before_pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		proc_close( $before_server );
	}

	list( $after_server, $after_pipes, $after_ready, $after_request ) = $pinned_server( $lock_at );
	check( $after_ready && is_resource( $after_server ), 'a clock-pinned server starts at the deadline lock' );
	try {
		$counts_at = $lock_counts();
		$http_version = (int) $lock_db->query( "SELECT e.expected_version FROM entries e JOIN accounts a ON a.id = e.account_id WHERE a.username = 'httplock'" )->fetchColumn();
		$http_locked = $after_request( 'POST', '/team/save', array( 'season' => $season, 'expected_version' => $http_version, '_csrf' => $http_lock_csrf, 'picks' => $picks ), array( 'Cookie: ' . $http_lock_cookie ) );
		check( 409 === $http_locked['status'] && $counts_at === $lock_counts(), 'over HTTP, an amend at the deadline lock is refused and writes nothing' );
	} finally {
		proc_terminate( $after_server );
		foreach ( $after_pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		proc_close( $after_server );
	}

	// The deadline governs the transaction's start instant, not the request's. A
	// request that begins one second before the deadline but whose transaction
	// commits at it must be refused, with nothing written.
	$commit_pdo = null;
	$commit_runtime = new Runtime( $lock_path, dirname( __DIR__ ), new Commit_Clock(
		new \DateTimeImmutable( $lock_before ),
		new \DateTimeImmutable( $lock_at ),
		static function () use ( &$commit_pdo ): bool { return null !== $commit_pdo && $commit_pdo->inTransaction(); }
	) );
	$commit_pdo = $commit_runtime->database();
	$commit_counts = $lock_counts();
	$commit_amend = $commit_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => (string) $lock_version(), '_csrf' => $lock_csrf, 'picks' => $picks ), $lock_headers );
	check( 409 === $commit_amend['status'] && $commit_counts === $lock_counts(), 'a save that starts before but commits at the deadline is refused, writing nothing' );

	$open_runtime = new Runtime( $lock_path, dirname( __DIR__ ), new Clock( new \DateTimeImmutable( $lock_before ) ) );
	$open_commit = $open_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => (string) $lock_version(), '_csrf' => $lock_csrf, 'picks' => $picks ), $lock_headers );
	check( 200 === $open_commit['status'], 'a save that commits before the deadline still succeeds' );

	// The approved-event pipeline: a reported death is reviewed over the operator
	// route, approval fans out to the award ledger, and retraction reverses it.
	putenv( 'OBITLEAGUE_OPERATORS=operator' );
	$review_runtime = new Runtime( $directory . '/review.sqlite', dirname( __DIR__ ) );
	$review_db = $review_runtime->database();
	$review_picks = array();
	for ( $i = 1; $i <= 10; ++$i ) {
		$uuid = sprintf( 'review-person-%02d', $i );
		$review_picks[] = $uuid;
		$review_db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, NULL, ?, ?)' )
			->execute( array( $uuid, 'Review Person ' . $i, '1980-01-01', 'approved', gmdate( 'Y-m-d H:i:s' ) ) );
	}
	$operator = $review_runtime->handle( 'POST', '/register', array(), array( 'username' => 'operator', 'display_name' => 'Operator', 'password' => 'correct horse battery staple' ) );
	$operator_headers = array( 'Cookie' => explode( ';', $operator['headers']['Set-Cookie'], 2 )[0], 'X-CSRF-Token' => $operator['headers']['X-CSRF-Token'] );
	$player = $review_runtime->handle( 'POST', '/register', array(), array( 'username' => 'player1', 'display_name' => 'Player One', 'password' => 'correct horse battery staple' ) );
	$player_headers = array( 'Cookie' => explode( ';', $player['headers']['Set-Cookie'], 2 )[0], 'X-CSRF-Token' => $player['headers']['X-CSRF-Token'] );
	check( 403 === $review_runtime->handle( 'GET', '/review', array(), array(), $player_headers )['status'], 'a non-operator cannot reach the death review route' );
	$review_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => '1', '_csrf' => $player['headers']['X-CSRF-Token'], 'picks' => $review_picks ), $player_headers );
	$review_runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $player['headers']['X-CSRF-Token'], 'idempotency_key' => 'review-submit-1' ), $player_headers );
	$review_db->prepare( "UPDATE entry_revisions SET submitted_at = ? WHERE id = (SELECT submitted_revision_id FROM entries WHERE account_id = (SELECT id FROM accounts WHERE username = 'player1'))" )->execute( array( $season . '-01-10 00:00:00' ) );

	$report = $review_runtime->handle( 'POST', '/review/report', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'person_uuid' => 'review-person-01', 'death_date' => $season . '-06-15', 'cause_status' => 'not_disclosed' ), $operator_headers );
	check( 200 === $report['status'] && str_contains( $report['body'], 'Death reported for review.' ) && str_contains( $report['body'], 'Review Person 1' ), 'an operator reports a death and sees it in the review queue' );
	check( null === $review_db->query( "SELECT death_date FROM people WHERE uuid = 'review-person-01'" )->fetchColumn(), 'a reported death is not published until approved' );
	$event_id = (int) $review_db->query( "SELECT id FROM events WHERE person_uuid = 'review-person-01'" )->fetchColumn();

	$review_points = max( 1, 100 - ( $season - 1980 ) );
	$approve = $review_runtime->handle( 'POST', '/review/approve', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'event_id' => $event_id ), $operator_headers );
	$review_standing = $review_runtime->standings( $season );
	check( 200 === $approve['status'] && str_contains( $approve['body'], 'Death approved and scored.' ) && 'approved' === $review_db->query( "SELECT status FROM events WHERE id = " . $event_id )->fetchColumn() && $season . '-06-15' === $review_db->query( "SELECT death_date FROM people WHERE uuid = 'review-person-01'" )->fetchColumn(), 'approval publishes the death and marks the event approved' );
	check( str_contains( $approve['body'], '/review/retract' ) && str_contains( $approve['body'], '/review/correct' ) && str_contains( $approve['body'], 'Review Person 1' ), 'an approved death stays on the review page with retract and correction actions' );
	check( 409 === $review_runtime->handle( 'POST', '/review/report', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'person_uuid' => 'review-person-01', 'death_date' => $season . '-07-01' ), $operator_headers )['status'], 're-reporting an approved death is refused in favour of correcting it' );
	check( 1 === count( $review_standing ) && $review_points === $review_standing[0]['points'] && 'Player One' === $review_standing[0]['player'], 'approval fans the award out to the picking team and into the standings' );

	$reapprove = $review_runtime->handle( 'POST', '/review/approve', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'event_id' => $event_id ), $operator_headers );
	check( 200 === $reapprove['status'] && str_contains( $reapprove['body'], 'already approved' ) && $review_points === (int) $review_db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 're-approving the same death is idempotent in the ledger' );

	$correct = $review_runtime->handle( 'POST', '/review/correct', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'event_id' => $event_id, 'death_date' => $season . '-08-01', 'cause_status' => 'confirmed', 'cause' => 'Corrected cause' ), $operator_headers );
	$corrected_revision = (string) $review_db->query( "SELECT event_revision FROM awards WHERE points_delta > 0 ORDER BY id DESC LIMIT 1" )->fetchColumn();
	check( 200 === $correct['status'] && 2 === (int) $review_db->query( "SELECT revision FROM events WHERE id = " . $event_id )->fetchColumn() && $season . '-08-01' === $corrected_revision && $review_points === (int) $review_db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 'correcting a death date supersedes the earlier revision and reconciles the ledger' );
	check( 'confirmed' === $review_db->query( "SELECT cause_status FROM events WHERE id = " . $event_id )->fetchColumn(), 'the corrected cause is stored on the event' );

	$award_rows = (int) $review_db->query( 'SELECT COUNT(*) FROM awards' )->fetchColumn();
	$comment_only = $review_runtime->handle( 'POST', '/review/correct', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'event_id' => $event_id, 'death_date' => $season . '-08-01', 'cause_status' => 'not_disclosed' ), $operator_headers );
	check( 200 === $comment_only['status'] && 2 === (int) $review_db->query( "SELECT revision FROM events WHERE id = " . $event_id )->fetchColumn() && $award_rows === (int) $review_db->query( 'SELECT COUNT(*) FROM awards' )->fetchColumn() && 'not_disclosed' === $review_db->query( "SELECT cause_status FROM events WHERE id = " . $event_id )->fetchColumn(), 'a cause-only correction updates the event without touching the revision or the ledger' );

	// A never-approved report cannot be retracted; the pending event is refused.
	$review_runtime->handle( 'POST', '/review/report', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'person_uuid' => 'review-person-02', 'death_date' => $season . '-05-01' ), $operator_headers );
	$pending_event = (int) $review_db->query( "SELECT id FROM events WHERE person_uuid = 'review-person-02'" )->fetchColumn();
	$pending_retract = $review_runtime->handle( 'POST', '/review/retract', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'event_id' => $pending_event ), $operator_headers );
	check( 409 === $pending_retract['status'] && 'reported' === $review_db->query( "SELECT status FROM events WHERE id = " . $pending_event )->fetchColumn(), 'retracting a never-approved report is refused, not half-applied' );

	$retract = $review_runtime->handle( 'POST', '/review/retract', array(), array( '_csrf' => $operator['headers']['X-CSRF-Token'], 'event_id' => $event_id ), $operator_headers );
	$retracted_standing = $review_runtime->standings( $season );
	check( 200 === $retract['status'] && str_contains( $retract['body'], 'Death retracted and its award withdrawn.' ) && 0 === (int) $review_db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 'retracting a death reverses its award through the signed ledger' );
	check( 0 === $retracted_standing[0]['points'] && null === $review_db->query( "SELECT death_date FROM people WHERE uuid = 'review-person-01'" )->fetchColumn() && null !== $review_runtime->events()->find( 'review-person-01' ), 'retraction restores the standings and returns the person to play while keeping the event record' );

	// The settlement gate: a season is final after its settlement instant
	// (23:59:59 Europe/London on 31 January following the season). Pin the clock
	// to that boundary so the gate is observable, and drive it over HTTP.
	$settle_path = $directory . '/settle.sqlite';
	$settle_at   = sprintf( '%d-01-31T23:59:59+00:00', $season + 1 );
	$settle_after = sprintf( '%d-02-01T00:00:00+00:00', $season + 1 );
	$settle_season = $season;
	$settle_boot = new Runtime( $settle_path, dirname( __DIR__ ), new Clock( new \DateTimeImmutable( sprintf( '%d-01-10T00:00:00+00:00', $season ) ) ) );
	$settle_db = $settle_boot->database();
	$settle_picks = array();
	for ( $i = 1; $i <= 10; ++$i ) {
		$uuid = sprintf( 'settle-person-%02d', $i );
		$settle_picks[] = $uuid;
		$settle_db->prepare( 'INSERT INTO people (uuid, name, birth_date, death_date, eligibility, created_at) VALUES (?, ?, ?, NULL, ?, ?)' )
			->execute( array( $uuid, 'Settle Person ' . $i, '1980-01-01', 'approved', gmdate( 'Y-m-d H:i:s' ) ) );
	}
	$settle_operator = $settle_boot->handle( 'POST', '/register', array(), array( 'username' => 'settleop', 'display_name' => 'Settle Op', 'password' => 'correct horse battery staple' ) );
	$settle_headers = array( 'Cookie' => explode( ';', $settle_operator['headers']['Set-Cookie'], 2 )[0], 'X-CSRF-Token' => $settle_operator['headers']['X-CSRF-Token'] );
	$settle_player = $settle_boot->handle( 'POST', '/register', array(), array( 'username' => 'settleplayer', 'display_name' => 'Settle Player', 'password' => 'correct horse battery staple' ) );
	$settle_player_headers = array( 'Cookie' => explode( ';', $settle_player['headers']['Set-Cookie'], 2 )[0], 'X-CSRF-Token' => $settle_player['headers']['X-CSRF-Token'] );
	$settle_boot->handle( 'POST', '/team/save', array(), array( 'season' => $settle_season, 'expected_version' => '1', '_csrf' => $settle_player['headers']['X-CSRF-Token'], 'picks' => $settle_picks ), $settle_player_headers );
	$settle_boot->handle( 'POST', '/team/submit', array(), array( 'season' => $settle_season, '_csrf' => $settle_player['headers']['X-CSRF-Token'], 'idempotency_key' => 'settle-submit-1' ), $settle_player_headers );
	$settle_db->prepare( "UPDATE entry_revisions SET submitted_at = ? WHERE id = (SELECT submitted_revision_id FROM entries WHERE account_id = (SELECT id FROM accounts WHERE username = 'settleplayer'))" )->execute( array( $settle_season . '-01-10 00:00:00' ) );
	$settle_points = max( 1, 100 - ( $settle_season - 1980 ) );

	$settle_server = static function ( string $pin ) use ( $settle_path ): array {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $socket_error, $socket_message );
		$address = stream_socket_get_name( $socket, false );
		$port = (int) substr( (string) strrchr( (string) $address, ':' ), 1 );
		fclose( $socket );
		$environment = getenv();
		$environment['OBITLEAGUE_DB'] = $settle_path;
		$environment['OBITLEAGUE_NOW'] = $pin;
		$environment['OBITLEAGUE_OPERATORS'] = 'settleop';
		$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname( __DIR__ ) . '/app', dirname( __DIR__ ) . '/app/index.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ), $environment );
		$ready = false;
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$probe = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $connect_error, $connect_message, 0.1 );
			if ( false !== $probe ) { fclose( $probe ); $ready = true; break; }
			usleep( 50000 );
		}
		$request = static function ( string $method, string $path, array $fields = array(), array $headers = array() ) use ( $port ): array {
			$headers = array_merge( array( 'Content-Type: application/x-www-form-urlencoded' ), $headers );
			$context = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => implode( "\r\n", $headers ), 'content' => 'POST' === $method ? http_build_query( $fields ) : '', 'ignore_errors' => true, 'follow_location' => 0 ) ) );
			$body = file_get_contents( 'http://127.0.0.1:' . $port . $path, false, $context );
			$response_headers = $http_response_header ?? array();
			preg_match( '/^HTTP\/\S+\s+(\d+)/', $response_headers[0] ?? '', $status_match );
			return array( 'status' => (int) ( $status_match[1] ?? 0 ), 'headers' => $response_headers, 'body' => false === $body ? '' : $body );
		};
		return array( $server, $pipes, $ready, $request );
	};
	$settle_login = static function ( callable $request ): array {
		$response = $request( 'POST', '/login', array( 'username' => 'settleop', 'password' => 'correct horse battery staple' ) );
		$cookie = '';
		$csrf = '';
		foreach ( $response['headers'] as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' ) ) { $cookie = explode( ';', trim( substr( $header, strlen( 'Set-Cookie:' ) ) ), 2 )[0]; }
			if ( 0 === stripos( $header, 'X-CSRF-Token:' ) ) { $csrf = trim( substr( $header, strlen( 'X-CSRF-Token:' ) ) ); }
		}
		return array( $cookie, $csrf );
	};

	// Exactly at the settlement instant: still open, so the approval scores.
	list( $at_server, $at_pipes, $at_ready, $at_request ) = $settle_server( $settle_at );
	check( $at_ready && is_resource( $at_server ), 'a clock-pinned server starts at the settlement instant' );
	try {
		list( $at_cookie, $at_csrf ) = $settle_login( $at_request );
		$at_report = $at_request( 'POST', '/review/report', array( '_csrf' => $at_csrf, 'person_uuid' => 'settle-person-01', 'death_date' => $settle_season . '-06-15' ), array( 'Cookie: ' . $at_cookie ) );
		$at_event = (int) $settle_db->query( "SELECT id FROM events WHERE person_uuid = 'settle-person-01'" )->fetchColumn();
		$at_approve = $at_request( 'POST', '/review/approve', array( '_csrf' => $at_csrf, 'event_id' => $at_event ), array( 'Cookie: ' . $at_cookie ) );
		$at_standings = $at_request( 'GET', '/standings?season=' . $settle_season );
		check( 200 === $at_report['status'] && 200 === $at_approve['status'] && str_contains( $at_standings['body'], (string) $settle_points ), 'over HTTP, an approval exactly at the settlement instant still scores' );
	} finally {
		proc_terminate( $at_server );
		foreach ( $at_pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		proc_close( $at_server );
	}

	// After the settlement instant: the season is final, a new approval is refused
	// with no ledger row, the standings present as final, and a retraction of the
	// pre-settlement award still reverses.
	list( $after_server, $after_pipes, $after_ready, $after_request ) = $settle_server( $settle_after );
	check( $after_ready && is_resource( $after_server ), 'a clock-pinned server starts after the settlement instant' );
	try {
		list( $after_cookie, $after_csrf ) = $settle_login( $after_request );
		$final_page = $after_request( 'GET', '/standings?season=' . $settle_season );
		check( str_contains( $final_page['body'], 'Final standings' ), 'over HTTP, a settled season presents its standings as final' );
		$settled_board = $after_request( 'GET', '/review', array(), array( 'Cookie: ' . $after_cookie ) );
		check( str_contains( $settled_board['body'], 'date corrections refused' ), 'over HTTP, the review board marks a settled death as uncorrectable' );
		$before_rows = (int) $settle_db->query( 'SELECT COUNT(*) FROM awards' )->fetchColumn();
		$after_report = $after_request( 'POST', '/review/report', array( '_csrf' => $after_csrf, 'person_uuid' => 'settle-person-02', 'death_date' => $settle_season . '-07-20' ), array( 'Cookie: ' . $after_cookie ) );
		$after_event = (int) $settle_db->query( "SELECT id FROM events WHERE person_uuid = 'settle-person-02'" )->fetchColumn();
		$after_approve = $after_request( 'POST', '/review/approve', array( '_csrf' => $after_csrf, 'event_id' => $after_event ), array( 'Cookie: ' . $after_cookie ) );
		$after_rows = (int) $settle_db->query( 'SELECT COUNT(*) FROM awards' )->fetchColumn();
		$after_event_status = (string) $settle_db->query( 'SELECT status FROM events WHERE id = ' . $after_event )->fetchColumn();
		check( 409 === $after_approve['status'] && str_contains( $after_approve['body'], 'settled' ) && $before_rows === $after_rows && 'reported' === $after_event_status && null === $settle_db->query( "SELECT death_date FROM people WHERE uuid = 'settle-person-02'" )->fetchColumn(), 'over HTTP, an approval after settlement is refused with no ledger row or state written' );
		$first_event = (int) $settle_db->query( "SELECT id FROM events WHERE person_uuid = 'settle-person-01'" )->fetchColumn();
		$after_retract = $after_request( 'POST', '/review/retract', array( '_csrf' => $after_csrf, 'event_id' => $first_event ), array( 'Cookie: ' . $after_cookie ) );
		check( 200 === $after_retract['status'] && $before_rows + 1 === (int) $settle_db->query( 'SELECT COUNT(*) FROM awards' )->fetchColumn() && 0 === (int) $settle_db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 'over HTTP, a pre-settlement award still reverses after settlement' );
	} finally {
		proc_terminate( $after_server );
		foreach ( $after_pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		proc_close( $after_server );
	}

	// Prove the operator path through the real HTTP entry point: report, approve,
	// award reflected on /standings, then retract — all over app/index.php.
	$review_server = static function () use ( $directory ): array {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $socket_error, $socket_message );
		$address = stream_socket_get_name( $socket, false );
		$port = (int) substr( (string) strrchr( (string) $address, ':' ), 1 );
		fclose( $socket );
		$environment = getenv();
		$environment['OBITLEAGUE_DB'] = $directory . '/review.sqlite';
		$environment['OBITLEAGUE_OPERATORS'] = 'operator';
		$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname( __DIR__ ) . '/app', dirname( __DIR__ ) . '/app/index.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, dirname( __DIR__ ), $environment );
		$ready = false;
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$probe = @stream_socket_client( 'tcp://127.0.0.1:' . $port, $connect_error, $connect_message, 0.1 );
			if ( false !== $probe ) { fclose( $probe ); $ready = true; break; }
			usleep( 50000 );
		}
		$request = static function ( string $method, string $path, array $fields = array(), array $headers = array() ) use ( $port ): array {
			$headers = array_merge( array( 'Content-Type: application/x-www-form-urlencoded' ), $headers );
			$context = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => implode( "\r\n", $headers ), 'content' => 'POST' === $method ? http_build_query( $fields ) : '', 'ignore_errors' => true, 'follow_location' => 0 ) ) );
			$body = file_get_contents( 'http://127.0.0.1:' . $port . $path, false, $context );
			$response_headers = $http_response_header ?? array();
			preg_match( '/^HTTP\/\S+\s+(\d+)/', $response_headers[0] ?? '', $status_match );
			return array( 'status' => (int) ( $status_match[1] ?? 0 ), 'headers' => $response_headers, 'body' => false === $body ? '' : $body );
		};
		return array( $server, $pipes, $ready, $request );
	};
	list( $rv_server, $rv_pipes, $rv_ready, $rv_request ) = $review_server();
	check( $rv_ready && is_resource( $rv_server ), 'an operator-configured server starts for the HTTP review chain' );
	try {
		$rv_login = $rv_request( 'POST', '/login', array( 'username' => 'operator', 'password' => 'correct horse battery staple' ) );
		$rv_cookie = '';
		$rv_csrf = '';
		foreach ( $rv_login['headers'] as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' ) ) { $rv_cookie = explode( ';', trim( substr( $header, strlen( 'Set-Cookie:' ) ) ), 2 )[0]; }
			if ( 0 === stripos( $header, 'X-CSRF-Token:' ) ) { $rv_csrf = trim( substr( $header, strlen( 'X-CSRF-Token:' ) ) ); }
		}
		$rv_team = $rv_request( 'GET', '/team', array(), array( 'Cookie: ' . $rv_cookie ) );
		check( str_contains( $rv_team['body'], 'href="/review"' ), 'an operator sees the Review link in the navigation' );
		$rv_report = $rv_request( 'POST', '/review/report', array( '_csrf' => $rv_csrf, 'person_uuid' => 'review-person-03', 'death_date' => $season . '-07-04', 'cause_status' => 'not_disclosed' ), array( 'Cookie: ' . $rv_cookie ) );
		$rv_event = (int) $review_db->query( "SELECT id FROM events WHERE person_uuid = 'review-person-03'" )->fetchColumn();
		$rv_approve = $rv_request( 'POST', '/review/approve', array( '_csrf' => $rv_csrf, 'event_id' => $rv_event ), array( 'Cookie: ' . $rv_cookie ) );
		$rv_standings = $rv_request( 'GET', '/standings' );
		check( 200 === $rv_report['status'] && 200 === $rv_approve['status'] && str_contains( $rv_standings['body'], (string) $review_points ), 'over HTTP, an operator reports and approves a death and the award appears in the standings' );
		$rv_page = $rv_request( 'GET', '/review', array(), array( 'Cookie: ' . $rv_cookie ) );
		check( 200 === $rv_page['status'] && str_contains( $rv_page['body'], '/review/retract' ) && str_contains( $rv_page['body'], '/review/correct' ), 'over HTTP, an approved death is actionable from the review page' );
		$rv_correct = $rv_request( 'POST', '/review/correct', array( '_csrf' => $rv_csrf, 'event_id' => $rv_event, 'death_date' => $season . '-09-09', 'cause_status' => 'confirmed', 'cause' => 'Corrected over HTTP' ), array( 'Cookie: ' . $rv_cookie ) );
		$rv_death = (string) $review_db->query( "SELECT death_date FROM people WHERE uuid = 'review-person-03'" )->fetchColumn();
		check( 200 === $rv_correct['status'] && $season . '-09-09' === $rv_death && $review_points === (int) $review_db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 'over HTTP, an operator corrects an approved death and the award is reconciled' );
		$rv_retract = $rv_request( 'POST', '/review/retract', array( '_csrf' => $rv_csrf, 'event_id' => $rv_event ), array( 'Cookie: ' . $rv_cookie ) );
		$rv_after = $rv_request( 'GET', '/standings' );
		check( 200 === $rv_retract['status'] && ! str_contains( $rv_after['body'], (string) $review_points ) && 0 === (int) $review_db->query( 'SELECT COALESCE(SUM(points_delta), 0) FROM awards' )->fetchColumn(), 'over HTTP, retracting an approved death withdraws the award from the standings' );
	} finally {
		proc_terminate( $rv_server );
		foreach ( $rv_pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		proc_close( $rv_server );
	}
	putenv( 'OBITLEAGUE_OPERATORS=' );

	// The same commit-time gate guards submit: a draft whose transaction commits at
	// the deadline is refused and stays a draft.
	$drafter_register = $open_runtime->handle( 'POST', '/register', array(), array( 'username' => 'drafter', 'display_name' => 'Drafter', 'password' => 'correct horse battery staple' ) );
	preg_match( '/obl_session=([a-f0-9]{64})/', $drafter_register['headers']['Set-Cookie'], $drafter_cookie_match );
	$drafter_headers = array( 'Cookie' => 'obl_session=' . $drafter_cookie_match[1], 'X-CSRF-Token' => $drafter_register['headers']['X-CSRF-Token'] );
	$open_runtime->handle( 'POST', '/team/save', array(), array( 'season' => $season, 'expected_version' => '1', '_csrf' => $drafter_register['headers']['X-CSRF-Token'], 'picks' => $picks ), $drafter_headers );
	$drafter_counts = $lock_counts();
	$commit_submit = $commit_runtime->handle( 'POST', '/team/submit', array(), array( 'season' => $season, '_csrf' => $drafter_register['headers']['X-CSRF-Token'], 'idempotency_key' => 'commit-submit' ), $drafter_headers );
	$drafter_state = (string) $lock_db->query( "SELECT e.state FROM entries e JOIN accounts a ON a.id = e.account_id WHERE a.username = 'drafter'" )->fetchColumn();
	check( 409 === $commit_submit['status'] && str_contains( $commit_submit['body'], 'entry window is closed' ) && 'draft' === $drafter_state && $drafter_counts === $lock_counts(), 'a submit that starts before but commits at the deadline is refused, staying a draft' );
} finally {
	$stmt = null;
	unset( $runtime, $db, $before_runtime, $lock_runtime, $lock_db, $lock_counts, $lock_version, $pinned_server, $commit_runtime, $commit_pdo, $open_runtime, $commit_amend, $open_commit, $review_runtime, $review_db, $settle_boot, $settle_db, $settle_server, $settle_login );
	gc_collect_cycles();
	foreach ( glob( $directory . '/*' ) ?: array() as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
	rmdir( $directory );
}

echo "\nStandalone runtime integration contract passed.\n";
}

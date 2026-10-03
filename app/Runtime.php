<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

require_once __DIR__ . '/Http_Error.php';
require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Leagues.php';
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/Team.php';
require_once __DIR__ . '/Awards.php';
require_once __DIR__ . '/Events.php';
require_once __DIR__ . '/Standings.php';

require_once __DIR__ . '/../src/Domain/Value/Ruleset.php';
require_once __DIR__ . '/../src/Domain/Value/Partial_Date.php';
require_once __DIR__ . '/../src/Domain/Value/Award.php';
require_once __DIR__ . '/../src/Domain/Value/Score_Result.php';
require_once __DIR__ . '/../src/Domain/Value/Awarded_Event.php';
require_once __DIR__ . '/../src/Domain/Value/Cause_Status.php';
require_once __DIR__ . '/../src/Domain/Age.php';
require_once __DIR__ . '/../src/Domain/Scoring.php';
require_once __DIR__ . '/../src/Domain/Ranking.php';
require_once __DIR__ . '/../src/Domain/Deadline_Policy.php';
require_once __DIR__ . '/../src/Domain/Discovery_Rules.php';
require_once __DIR__ . '/../src/Domain/Entry_Rules.php';
require_once __DIR__ . '/../src/Domain/Invalid_Team_Exception.php';

use Obitleague\Domain\Invalid_Team_Exception;
use PDOException;

/** Routes one server request to the focused units and renders its response. */
final class Runtime {
	private Database $database;
	private Clock $clock;
	private Auth $auth;
	private Leagues $leagues;
	private Catalog $catalog;
	private View $view;
	private Team $team;
	private Awards $awards;
	private Events $events;
	private Standings $standings;

	public function __construct( string $database, string $root, ?Clock $clock = null ) {
		$this->clock = $clock ?? new Clock();
		$this->database = new Database( $database, $root, $this->clock );
		$this->auth = new Auth( $this->database, new Csrf(), $this->clock );
		$this->leagues = new Leagues( $this->database, $this->clock );
		$this->catalog = new Catalog( $this->database );
		$this->view = new View( $root );
		$this->team = new Team( $this->database, $this->catalog, $this->clock );
		$this->awards = new Awards( $this->database, $this->catalog, $this->clock );
		$this->events = new Events( $this->database, $this->catalog, $this->awards, $this->clock );
		$this->standings = new Standings( $this->database, $this->clock );
	}

	public function database(): \PDO {
		return $this->database->pdo();
	}

	/** Confirm or withdraw a person's death and reconcile its award. */
	public function confirm_death( string $uuid ): array {
		return $this->awards->confirm( $uuid );
	}

	/** Report, approve, or retract a death event through the review unit. */
	public function events(): Events {
		return $this->events;
	}

	/** Rank the submitted teams of a season. */
	public function standings( int $season ): array {
		return $this->standings->for_season( $season );
	}

	/** Dispatch one server request and return a PHP-server-ready response. */
	public function handle( string $method, string $path, array $query = array(), array $input = array(), array $headers = array() ): array {
		$method = strtoupper( $method );
		$path = '/' . trim( (string) parse_url( $path, PHP_URL_PATH ), '/' );
		try {
			if ( '/' === $path && 'GET' === $method ) {
				return $this->view->render( 200, 'home.php', array(
					'people' => $this->catalog->search( 6, array( 'living' => '0', 'season' => $this->clock->season_now(), 'recent_deaths' => true ) ),
				) );
			}
			if ( '/register' === $path && 'GET' === $method ) {
				return $this->view->render( 200, 'register.php' );
			}
			if ( '/register' === $path && 'POST' === $method ) {
				return $this->register( $input );
			}
			if ( '/login' === $path && 'GET' === $method ) {
				return $this->view->render( 200, 'login.php' );
			}
			if ( '/login' === $path && 'POST' === $method ) {
				return $this->login( $input );
			}
			if ( '/people' === $path && 'GET' === $method ) {
				return $this->view->render( 200, 'people.php', array(
					'people' => $this->catalog->search( 100, $query ),
					'search' => (string) ( $query['q'] ?? '' ),
				) );
			}
			if ( '/team' === $path && 'GET' === $method ) {
				$account = $this->auth->authenticate( $headers );
				return $this->team_page( $account, $this->team->view( $account ) );
			}
			if ( '/team/save' === $path && 'POST' === $method ) {
				$account = $this->auth->authenticate( $headers, true );
				return $this->team_page( $account, $this->team->save( $account, $input ) );
			}
			if ( '/team/submit' === $path && 'POST' === $method ) {
				$account = $this->auth->authenticate( $headers, true );
				return $this->team_page( $account, $this->team->submit( $account, $input ) );
			}
			if ( '/standings' === $path && 'GET' === $method ) {
				// Default to the live season, but allow looking back at a settled one,
				// which is the only place a final season's standings can be seen.
				$season = (int) ( $query['season'] ?? $this->clock->season_now() );
				if ( $season < 2000 || $season > 9999 ) {
					$season = $this->clock->season_now();
				}
				return $this->view->render( 200, 'standings.php', array(
					'season' => $season,
					'rows' => $this->standings->for_season( $season ),
					'final' => $this->standings->is_final( $season ),
				) );
			}
			if ( '/review' === $path && 'GET' === $method ) {
				$account = $this->auth->require_operator( $headers );
				return $this->review_page( $account, $this->events->board() );
			}
			if ( '/review/report' === $path && 'POST' === $method ) {
				$account = $this->auth->require_operator( $headers, true );
				$this->events->report( array_merge( $input, array( 'reporter_account_id' => (int) $account['id'] ) ) );
				return $this->review_page( $account, $this->events->board(), 'Death reported for review.' );
			}
			if ( '/review/approve' === $path && 'POST' === $method ) {
				$account = $this->auth->require_operator( $headers, true );
				$result = $this->events->approve( (int) ( $input['event_id'] ?? 0 ), (int) $account['id'] );
				$notices = array(
					'approved'  => 'Death approved and scored.',
					'unchanged' => 'That death was already approved.',
					'settled'   => 'The season has settled; that death cannot be scored.',
				);
				return $this->review_page( $account, $this->events->board(), $notices[ $result['status'] ] ?? 'Nothing changed.' );
			}
			if ( '/review/correct' === $path && 'POST' === $method ) {
				$account = $this->auth->require_operator( $headers, true );
				$result = $this->events->correct( (int) ( $input['event_id'] ?? 0 ), $input, (int) $account['id'] );
				return $this->review_page( $account, $this->events->board(), 'recommented' === $result['status'] ? 'Cause corrected; points unchanged.' : 'Death date corrected and the award reconciled.' );
			}
			if ( '/review/retract' === $path && 'POST' === $method ) {
				$account = $this->auth->require_operator( $headers, true );
				$this->events->retract( (int) ( $input['event_id'] ?? 0 ), (int) $account['id'] );
				return $this->review_page( $account, $this->events->board(), 'Death retracted and its award withdrawn.' );
			}
			if ( '/logout' === $path && 'POST' === $method ) {
				$this->auth->authenticate( $headers, true );
				$this->auth->destroy_session( $headers );
				return $this->redirect( '/login' );
			}
			return $this->view->render( 404, '404.php' );
		} catch ( Http_Error $error ) {
			return $this->view->render( $error->status, 'error.php', array( 'message' => $error->getMessage() ) );
		} catch ( Invalid_Team_Exception $error ) {
			return $this->view->render( 422, 'error.php', array( 'message' => implode( ' ', $error->problems ) ) );
		} catch ( PDOException $error ) {
			error_log( $error->getMessage() );
			return $this->view->render( 500, 'error.php', array( 'message' => 'The request could not be completed. Please retry.' ) );
		}
	}

	/** Render the operator review page, always private and never cached. */
	private function review_page( array $account, array $events, ?string $notice = null ): array {
		$account['is_operator'] = true;
		return $this->view->private_render( array(
			'account' => $account,
			'events' => $events,
			'notice' => $notice,
		), 'review.php' );
	}

	/** Attach the owner's live standing and render a private team page. */
	private function team_page( array $account, array $data ): array {
		$data['standing'] = $this->standings->for_account( $this->clock->season_now(), (int) $account['id'] );
		$data['account'] += array( 'is_operator' => $this->auth->is_operator( $account ) );
		return $this->view->private_render( $data );
	}

	private function register( array $input ): array {
		$username = strtolower( trim( (string) ( $input['username'] ?? '' ) ) );
		$name = trim( (string) ( $input['display_name'] ?? '' ) );
		$password = (string) ( $input['password'] ?? '' );
		$problem = $this->auth->registration_error( $username, $name, $password );
		if ( null !== $problem ) {
			return $this->view->render( 422, 'register.php', array( 'error' => $problem ) );
		}

		try {
			$this->database->transaction( function () use ( $username, $name, $password ): void {
				$id = $this->auth->create_account( $username, $name, $password );
				$this->leagues->provision( $id, $this->clock->season_now() );
			} );
		} catch ( PDOException $error ) {
			if ( str_contains( $error->getMessage(), 'UNIQUE constraint failed: accounts.username' ) ) {
				return $this->view->render( 409, 'register.php', array( 'error' => 'That username is already registered.' ) );
			}
			throw $error;
		}
		return $this->login( array( 'username' => $username, 'password' => $password ) );
	}

	private function login( array $input ): array {
		$session = $this->auth->login( (string) ( $input['username'] ?? '' ), (string) ( $input['password'] ?? '' ) );
		if ( null === $session ) {
			return $this->view->render( 401, 'login.php', array( 'error' => 'Username or password is incorrect.' ) );
		}
		return array(
			'status' => 303,
			'headers' => array(
				'Location' => '/team',
				'Set-Cookie' => $session['cookie'],
				'X-CSRF-Token' => $session['csrf'],
			),
			'body' => '',
		);
	}

	private function redirect( string $location ): array {
		return array( 'status' => 303, 'headers' => array( 'Location' => $location ), 'body' => '' );
	}
}

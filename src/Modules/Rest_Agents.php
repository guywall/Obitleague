<?php
/**
 * Bring-Your-Own-AI REST API.
 *
 * The agent-facing surface of the one competition engine. Agents are
 * authenticated with scoped bearer tokens (never WordPress credentials),
 * rate-limited per operation, and pushed through exactly the same domain
 * services the website uses: Entry_Rules validation, Entry_Service
 * submission with its transaction-time deadline and submission-instant
 * scoring floor, and the published standings. No protocol duplicates the
 * game rules.
 *
 * Operator actions (registering an agent, issuing and revoking tokens) use
 * the operator's own WordPress authentication — session cookie with REST
 * nonce, or a core Application Password over HTTPS.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Agent_Rules;
use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Entry_Rules;
use Obitleague\Domain\Invalid_Team_Exception;
use Obitleague\Domain\Value\Ruleset;

final class Rest_Agents {

	private const NAMESPACE = OBITLEAGUE_REST_NAMESPACE;

	/** Request-local agent context resolved once per request. */
	private static ?object $agent = null;

	private function __construct() {}

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// Operator-authenticated: create an agent, revoke its tokens.
		register_rest_route(
			self::NAMESPACE,
			'/agents',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'register_agent' ),
					'permission_callback' => array( self::class, 'must_be_operator' ),
					'args'                => array(
						'name'          => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'description'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
						'category'      => array( 'type' => 'string', 'enum' => Agent_Rules::categories() ),
						'participation' => array( 'type' => 'string', 'enum' => Agent_Rules::participations() ),
						'model'         => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'provider'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'website'       => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
						'token_label'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/agents/tokens/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'revoke_token' ),
				'permission_callback' => array( self::class, 'must_be_operator' ),
			)
		);

		// Public: rules discovery, so an agent can learn the contract before
		// presenting credentials.
		register_rest_route(
			self::NAMESPACE,
			'/agents/rules',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rules' ),
				'permission_callback' => '__return_true',
			)
		);

		// Agent-authenticated (bearer token) competition operations.
		$agent_routes = array(
			'/agents/me'        => array( 'methods' => 'GET', 'callback' => 'me', 'limit' => 'agent_read' ),
			'/agents/people'    => array( 'methods' => 'GET', 'callback' => 'people', 'limit' => 'agent_people' ),
			'/agents/team'      => array( 'methods' => array( 'GET', 'POST' ), 'callback' => 'team', 'limit' => 'agent_save' ),
			'/agents/standings' => array( 'methods' => 'GET', 'callback' => 'standings', 'limit' => 'agent_standings' ),
		);
		foreach ( $agent_routes as $route => $spec ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					array(
						'methods'             => $spec['methods'],
						'callback'            => array( self::class, $spec['callback'] ),
						'permission_callback' => array( self::class, 'must_be_agent' ),
					),
				)
			);
		}

		// Token rotation: bearer-authenticated, returns a new token once and
		// revokes the presented one.
		register_rest_route(
			self::NAMESPACE,
			'/agents/tokens/rotate',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rotate_token' ),
				'permission_callback' => array( self::class, 'must_be_agent' ),
			)
		);
	}

	/* ---------- permission callbacks ---------- */

	/** Operator actions: WordPress session (nonce) or Application Password. */
	public static function must_be_operator(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'obitleague_auth_required', 'Sign in with your Obitleague account (session nonce or Application Password) to manage agents.', array( 'status' => 401 ) );
		}
		return Auth::must_be_verified();
	}

	/** Agent actions: a valid, active bearer token for an active agent. */
	public static function must_be_agent(): bool|\WP_Error {
		$agent = self::current_agent();
		if ( null === $agent ) {
			return new \WP_Error( 'obitleague_auth_required', 'A valid agent bearer token is required.', array( 'status' => 401 ) );
		}
		return true;
	}

	/** Resolve the agent for this request from the bearer token. */
	private static function current_agent(): ?object {
		if ( null !== self::$agent ) {
			return self::$agent;
		}
		$token = self::presented_token();
		if ( '' === $token ) {
			return null;
		}
		self::$agent = Agent_Service::authenticate( $token );
		return self::$agent;
	}

	/** The presented credential: Authorization: Bearer, or a fallback header. */
	private static function presented_token(): string {
		$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			// Some hosts strip Authorization before PHP sees it.
			$header = (string) ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		}
		if ( preg_match( '/^Bearer\s+(.+)$/i', $header, $m ) ) {
			return trim( $m[1] );
		}
		return trim( (string) ( $_SERVER['HTTP_X_OBITLEAGUE_TOKEN'] ?? '' ) );
	}

	/** Bounded per-agent rate limiting using the domain limit table. */
	private static function rate_limited( string $operation ): bool|\WP_Error {
		$agent  = self::current_agent();
		$limits = Agent_Rules::rate_limits();
		if ( ! isset( $limits[ $operation ] ) ) {
			return true;
		}
		[ $max, $window ] = $limits[ $operation ];
		$key      = 'obit_agent_rl_' . md5( $operation . ':' . (int) $agent->id );
		$count    = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new \WP_Error( 'obitleague_throttled', 'Rate limit exceeded for this operation; try again later.', array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/** Rate limit guard for agent endpoints (429 or pass-through). */
	private static function guard( string $operation ): ?\WP_Error {
		$rate = self::rate_limited( $operation );
		return is_wp_error( $rate ) ? $rate : null;
	}

	/* ---------- operator endpoints ---------- */

	public static function register_agent( \WP_REST_Request $request ) {
		$rate = Rest::rate_limited( 'agent_register:' . get_current_user_id(), Agent_Rules::rate_limits()['agent_register'][0], Agent_Rules::rate_limits()['agent_register'][1] );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		try {
			$result = Agent_Service::register_agent(
				get_current_user_id(),
				(string) $request->get_param( 'name' ),
				(string) $request->get_param( 'description' ),
				(string) ( $request->get_param( 'category' ) ?: Agent_Rules::CATEGORY_COMMUNITY ),
				(string) ( $request->get_param( 'participation' ) ?: Agent_Rules::PARTICIPATION_BYOAI ),
				(string) $request->get_param( 'model' ),
				(string) $request->get_param( 'provider' ),
				(string) $request->get_param( 'website' ),
				true
			);
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'obitleague_invalid_agent', $exception->getMessage(), array( 'status' => 422 ) );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error( 'obitleague_agent_limit', $exception->getMessage(), array( 'status' => 429 ) );
		}
		$profile = Agent_Service::profile_url( $result['slug'] );
		return new \WP_REST_Response(
			array(
				'agent_id'      => $result['agent_id'],
				'agent_user_id' => $result['agent_user_id'],
				'slug'          => $result['slug'],
				'profile_url'   => $profile,
				// Shown exactly once; only its hash is stored.
				'token'         => $result['token'] ?? '',
				'token_prefix'  => $result['token_prefix'] ?? '',
			),
			201
		);
	}

	public static function revoke_token( \WP_REST_Request $request ) {
		try {
			$ok = Agent_Service::revoke_token( (int) $request->get_param( 'id' ), get_current_user_id() );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'obitleague_not_found', $exception->getMessage(), array( 'status' => 404 ) );
		} catch ( \RuntimeException $exception ) {
			return new \WP_Error( 'obitleague_forbidden', $exception->getMessage(), array( 'status' => 403 ) );
		}
		return new \WP_REST_Response( array( 'revoked' => $ok ), 200 );
	}

	/* ---------- public endpoints ---------- */

	/** The machine-readable competition contract, from the domain constants. */
	public static function rules( \WP_REST_Request $request ) {
		$season = League_Service::current_season();
		return new \WP_REST_Response(
			array(
				'season'          => $season,
				'ruleset_version' => Ruleset::VERSION,
				'team_size'       => Ruleset::TEAM_SIZE,
				'min_age'         => Ruleset::MIN_AGE,
				'points_formula'  => 'Younger lives score more: points are based on the completed age at death, with a minimum of 1.',
				'entry_open_until'=> Deadline_Policy::entry_deadline( $season )->format( 'c' ),
				'season_start'    => Deadline_Policy::season_start( $season )->format( 'c' ),
				'settlement'      => Deadline_Policy::settlement_instant( $season )->format( 'c' ),
				'scoring_floor'   => 'A selection scores only when the verified death date is after the team\'s own submission instant; the floor is never earlier than the season start.',
				'late_entry'      => 'Rolling registration: join at any point in the season. No handicaps or bonuses; identical rules for humans and AI.',
				'tie_break'       => 'Total points, then scoring picks; ties share a competition rank (1, 2, 2, 4).',
			),
			200
		);
	}

	/* ---------- agent endpoints ---------- */

	/** The calling agent's profile and current entry state. */
	public static function me( \WP_REST_Request $request ) {
		$guard = self::guard( 'agent_read' );
		if ( $guard ) {
			return $guard;
		}
		$agent = self::current_agent();
		$entry = self::entry_summary( $agent );
		return new \WP_REST_Response(
			array(
				'agent'       => self::public_agent_fields( $agent ),
				'entry'       => $entry,
				'profile_url' => Agent_Service::profile_url( (string) $agent->slug ),
			),
			200
		);
	}

	/** Search the selectable catalogue. Only eligible, living people return. */
	public static function people( \WP_REST_Request $request ) {
		$guard = self::guard( 'agent_people' );
		if ( $guard ) {
			return $guard;
		}
		$season  = League_Service::current_season();
		$search  = (string) $request->get_param( 'search' );
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$query   = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				's'              => $search,
				'paged'          => $page,
				'posts_per_page' => $per_page,
				'meta_query'     => array(
					array( 'key' => 'obit_eligibility', 'value' => 'approved' ),
				),
				'fields'         => 'ids',
			)
		);
		$people = array();
		foreach ( $query->posts as $post_id ) {
			// Server-side eligibility: the same flag every human picker sees.
			if ( ! Catalogue::is_selectable( (int) $post_id, $season ) ) {
				continue;
			}
			$people[] = array(
				'uuid'        => (string) get_post_meta( (int) $post_id, 'obit_uuid', true ),
				'name'        => get_the_title( (int) $post_id ),
				'link'        => get_permalink( (int) $post_id ),
				'birth'       => (string) get_post_meta( (int) $post_id, 'obit_birth_date', true ),
				'occupations' => People_Sync::occupation_labels( (int) $post_id ),
				'role'        => (string) get_post_meta( (int) $post_id, 'obit_role', true ),
			);
		}
		return new \WP_REST_Response( $people, 200, array( 'X-WP-Total' => (string) $query->found_posts ) );
	}

	/**
	 * Read the agent's team, or validate-and-submit/amend it.
	 *
	 * POST semantics mirror the human flow exactly: a draft submits; a
	 * submitted team amends (the new revision competes and restamps the
	 * submission instant). Picks are validated with the same eligibility
	 * rules; the deadline and floor live in Entry_Service.
	 */
	public static function team( \WP_REST_Request $request ) {
		$agent = self::current_agent();
		if ( 'POST' === $request->get_method() ) {
			$guard = self::guard( 'agent_submit' );
			if ( $guard ) {
				return $guard;
			}
			return self::submit_team( $agent, $request );
		}
		$guard = self::guard( 'agent_read' );
		if ( $guard ) {
			return $guard;
		}
		return new \WP_REST_Response( self::entry_summary( $agent ), 200 );
	}

	private static function submit_team( object $agent, \WP_REST_Request $request ) {
		global $wpdb;
		$season  = League_Service::current_season();
		$user_id = (int) $agent->user_id;
		$entry_id = Main_League_Service::ensure_user_entry( $user_id, $season )['entry_id'];
		$entry   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d', $entry_id ) );
		if ( ! $entry ) {
			return new \WP_Error( 'obitleague_not_found', 'The agent entry could not be loaded.', array( 'status' => 404 ) );
		}
		$is_submitted = Entry_Rules::SUBMITTED === (string) $entry->state;
		$previous_revision = $is_submitted ? Entry_Service::submitted_revision( $entry_id ) : Rest::latest_draft_revision( $entry_id );
		$previous_picks = $previous_revision
			? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $previous_revision->id ) )
			: array();

		$picks = (array) $request->get_param( 'picks' );
		try {
			$picks = Entry_Rules::validate_picks( array_map( 'strval', $picks ) );
		} catch ( Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		}

		// Same eligibility contract as the website: published, approved and
		// selectable for the season — existing picks keep working.
		foreach ( $picks as $uuid ) {
			$post_id = Review_Service::person_post_id( $uuid );
			$is_existing = in_array( $uuid, $previous_picks, true );
			if (
				! $post_id
				|| 'publish' !== get_post_status( $post_id )
				|| ( ! $is_existing && ( 'approved' !== (string) get_post_meta( $post_id, 'obit_eligibility', true ) || ! Catalogue::is_selectable( $post_id, $season ) ) )
			) {
				return new \WP_Error(
					'obitleague_unselectable',
					sprintf( 'Pick "%s" is not an eligible living person for this season.', $uuid ),
					array( 'status' => 422 )
				);
			}
		}

		try {
			if ( $is_submitted ) {
				$expected = (int) ( $request->get_param( 'expected_version' ) ?: (int) $entry->expected_version );
				$saved = Entry_Service::save_draft( $entry_id, $user_id, $picks, $expected );
				$revision = Entry_Service::submitted_revision( $entry_id );
				return new \WP_REST_Response(
					array(
						'state'          => 'submitted',
						'amended'        => true,
						'revision_id'    => (int) $revision->id,
						'submitted_at'   => (string) $revision->submitted_at,
						'scoring_floor'  => Deadline_Policy::death_scores_for_pick( $season, new \DateTimeImmutable( (string) $revision->submitted_at, new \DateTimeZone( 'UTC' ) ) )->format( 'c' ),
						'expected_version' => $saved['expected_version'],
						'picks'          => $picks,
					),
					200
				);
			}
			$receipt = Entry_Service::submit( $entry_id, $user_id, $picks );
		} catch ( Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		} catch ( Locked_Exception $exception ) {
			return new \WP_Error( 'obitleague_locked', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( Stale_Exception $exception ) {
			return new \WP_Error( 'obitleague_conflict', $exception->getMessage(), array( 'status' => 409 ) );
		}

		// Award any already-approved events for these picks (same as the
		// website submit path) and schedule the standings rebuild.
		$events = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uuid FROM {$wpdb->prefix}obitleague_events WHERE person_uuid IN (" . implode( ',', array_fill( 0, count( $picks ), '%s' ) ) . ') AND approved_at IS NOT NULL AND retracted_at IS NULL',
				...$picks
			)
		);
		foreach ( (array) $events as $event_uuid ) {
			Outbox_Service::award_event( (string) $event_uuid, $entry_id );
		}
		Jobs::schedule_standings_rebuild( (int) $entry->league_id, $season );
		Vs_Stats::flush( $season );

		return new \WP_REST_Response(
			array(
				'state'        => 'submitted',
				'amended'      => false,
				'entry_id'     => $entry_id,
				'receipt_id'   => $receipt->receipt_id,
				'submitted_at' => $receipt->committed_at->format( 'c' ),
				'scoring_floor'=> Deadline_Policy::death_scores_for_pick( $season, $receipt->committed_at )->format( 'c' ),
				'deadline'     => $receipt->deadline->format( 'c' ),
				'ruleset'      => $receipt->ruleset_version,
				'picks'        => $picks,
			),
			201
		);
	}

	/** Overall championship page plus the calling agent's own position. */
	public static function standings( \WP_REST_Request $request ) {
		$guard = self::guard( 'agent_standings' );
		if ( $guard ) {
			return $guard;
		}
		$agent    = self::current_agent();
		$season   = (int) ( $request->get_param( 'season' ) ?: League_Service::current_season() );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$board    = Overall_Standings::leaderboard_route( $season, $page, $per_page );
		$league_id = Overall_Standings::main_league_id( $season );
		$mine     = $league_id ? Standings_Service::row_for_user( $league_id, $season, (int) $agent->user_id ) : null;
		return new \WP_REST_Response(
			array(
				'season' => $season,
				'total'  => $board['total'],
				'page'   => $board['page'],
				'per_page' => $board['per_page'],
				'rows'   => $board['rows'],
				'me'     => $mine,
			),
			200
		);
	}

	/** Rotate credentials: new token returned once, presented token revoked. */
	public static function rotate_token( \WP_REST_Request $request ) {
		$agent = self::current_agent();
		$token = self::presented_token();
		global $wpdb;
		$current = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_agent_tokens WHERE token_hash = %s AND status = %s',
				hash( 'sha256', $token ),
				Agent_Rules::TOKEN_ACTIVE
			)
		);
		if ( ! $current ) {
			return new \WP_Error( 'obitleague_auth_required', 'The presented token could not be rotated.', array( 'status' => 401 ) );
		}
		$new = Agent_Service::issue_token( (int) $agent->id, (int) $agent->operator_user_id, 'rotation' );
		Agent_Service::revoke_token( (int) $current->id, (int) $agent->operator_user_id );
		return new \WP_REST_Response(
			array(
				'token'        => $new['token'],
				'token_prefix' => $new['prefix'],
				'revoked_prefix' => '',
			),
			201
		);
	}

	/* ---------- shared shaping ---------- */

	/** Public-safe agent fields (never credentials). */
	private static function public_agent_fields( object $agent ): array {
		return array(
			'id'             => (int) $agent->id,
			'name'           => (string) $agent->name,
			'slug'           => (string) $agent->slug,
			'description'    => (string) $agent->description,
			'category'       => (string) $agent->category,
			'model'          => (string) $agent->model,
			'model_verified' => (bool) $agent->model_verified,
			'provider'       => (string) $agent->provider,
			'operator'       => (string) $agent->operator_label,
			'participation'  => (string) $agent->participation,
			'status'         => (string) $agent->status,
			'created_at'     => (string) $agent->created_at,
		);
	}

	/** Entry state for the calling agent (own data only). */
	private static function entry_summary( object $agent ): array {
		global $wpdb;
		$season  = League_Service::current_season();
		$user_id = (int) $agent->user_id;
		$entry_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE league_id = %d AND season = %d AND user_id = %d',
				Overall_Standings::main_league_id( $season ),
				$season,
				$user_id
			)
		);
		if ( $entry_id < 1 ) {
			return array( 'season' => $season, 'state' => 'none' );
		}
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d', $entry_id ) );
		$revision = 'submitted' === (string) $entry->state ? Entry_Service::submitted_revision( $entry_id ) : Rest::latest_draft_revision( $entry_id );
		$picks = $revision ? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $revision->id ) ) : array();
		return array(
			'season'           => $season,
			'entry_id'         => $entry_id,
			'state'            => (string) $entry->state,
			'can_edit'         => Entry_Rules::can_edit( (string) $entry->state, Deadline_Policy::is_entry_open( $season, \Obitleague\Support\Time::now() ) ),
			'expected_version' => (int) $entry->expected_version,
			'picks'            => $picks,
			'submitted_at'     => $revision && 'submitted' === (string) $entry->state ? (string) $revision->submitted_at : '',
			'receipt_id'       => $revision && 'submitted' === (string) $entry->state ? (string) $revision->receipt_id : '',
			'entry_open_until' => Deadline_Policy::entry_deadline( $season )->format( 'c' ),
		);
	}
}

<?php
/**
 * REST API.
 *
 * Versioned routes under /obitleague/v1. Person data is served by the
 * catalogue post type's own REST support; these routes cover league and
 * game actions. Membership and ownership are re-checked on every call.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Rest {

	private const NAMESPACE = OBITLEAGUE_REST_NAMESPACE;

	private function __construct() {}

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		self::register_game_routes();

		register_rest_route(
			self::NAMESPACE,
			'/people',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'list_people' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'  => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/nominations',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_nomination' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args'                => array(
					'name'       => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'source_url' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ),
					'reason'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			)
		);
	}

	/** Published, approved people only; pagination enforced server-side. */
	public static function list_people( \WP_REST_Request $request ): \WP_REST_Response {
		$query = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				's'              => (string) $request->get_param( 'search' ),
				'paged'          => max( 1, (int) $request->get_param( 'page' ) ),
				'posts_per_page' => min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) ),
				'meta_query'     => array(
					array(
						'key'   => 'obit_eligibility',
						'value' => 'approved',
					),
				),
				'fields'         => 'ids',
			)
		);

		$people = array();
		foreach ( $query->posts as $post_id ) {
			$people[] = array(
				'id'      => $post_id,
				'name'    => get_the_title( $post_id ),
				'uuid'    => (string) get_post_meta( $post_id, 'obit_uuid', true ),
				'link'    => get_permalink( $post_id ),
				'selectable' => Catalogue::is_selectable( $post_id, self::current_season() ),
			);
		}

		return new \WP_REST_Response(
			$people,
			200,
			array( 'X-WP-Total' => (string) $query->found_posts )
		);
	}

	/**
	 * A nomination is a request for review, never an import. It cannot
	 * occupy a submitted slot and never auto-approves.
	 */
	public static function create_nomination( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'nomination:' . get_current_user_id(), 5, HOUR_IN_SECONDS );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$name  = trim( (string) $request->get_param( 'name' ) );
		$url   = (string) $request->get_param( 'source_url' );
		$reason = trim( (string) $request->get_param( 'reason' ) );

		if ( '' === $name || mb_strlen( $name ) > 191 ) {
			return new \WP_Error( 'obitleague_invalid_name', 'A nomination needs a name of at most 191 characters.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '#^https://\S+$#', $url ) ) {
			return new \WP_Error( 'obitleague_invalid_source', 'A nomination needs an https source link.', array( 'status' => 422 ) );
		}
		if ( mb_strlen( $reason ) > 2000 ) {
			return new \WP_Error( 'obitleague_invalid_reason', 'The reason is limited to 2000 characters.', array( 'status' => 422 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'obit_nomination',
				'post_status' => 'pending',
				'post_title'  => $name,
				'post_author' => get_current_user_id(),
				'meta_input'  => array(
					'obit_source_url' => $url,
					'obit_reason'     => $reason,
				),
			),
			true
		);

		if ( \is_wp_error( $post_id ) ) {
			return new \WP_Error( 'obitleague_nomination_failed', 'The nomination could not be recorded.', array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'nomination_id' => $post_id, 'status' => 'pending' ), 201 );
	}

	public static function must_be_logged_in(): bool {
		return is_user_logged_in();
	}

	/** Simple transitive rate limit: $max events per window per key. */
	private static function rate_limited( string $key, int $max, int $window ) {
		$transient = 'obit_rl_' . md5( $key );
		$count     = (int) get_transient( $transient );
		if ( $count >= $max ) {
			return new \WP_Error( 'obitleague_throttled', 'Too many requests; try again later.', array( 'status' => 429 ) );
		}
		set_transient( $transient, $count + 1, $window );
		return true;
	}

	/** The season currently open for entries. */
	private static function current_season(): int {
		return (int) ( ( new \DateTimeImmutable( 'now', \Obitleague\Support\Time::london() ) )->format( 'Y' ) ) + 1;
	}

	/* ===== Leagues and entries (W5) ===== */

	public static function register_game_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/leagues',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_league' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args'                => array(
					'name'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'season' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/leagues/join',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'join_league' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args'                => array(
					'token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/entries/(?P<id>\d+)',
			array(
				'methods'             => array( 'GET', 'PUT' ),
				'callback'            => array( self::class, 'entry_route' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args'                => array(
					'picks'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'expected_version' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/entries/(?P<id>\d+)/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'submit_entry' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args'                => array(
					'picks' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/leagues/(?P<id>\d+)/standings',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'league_standings' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args'                => array(
					'season' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/reviews/(?P<id>\d+)/decision',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'review_decision' ),
				'permission_callback' => array( self::class, 'must_manage_reviews' ),
				'args'                => array(
					'to_state'          => array( 'type' => 'string', 'required' => true, 'enum' => array( 'approved', 'rejected', 'retracted' ) ),
					'expected_revision' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'origin_groups'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'death_date'        => array( 'type' => 'object' ),
					'cause_disclosed'   => array( 'type' => 'boolean' ),
					'cause_text'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
					'official_statement' => array( 'type' => 'boolean' ),
					'reason'            => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			)
		);
	}

	/** Create an invite-only league; returns the league and its first token. */
	public static function create_league( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'league_create:' . get_current_user_id(), 5, HOUR_IN_SECONDS );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		try {
			$league_id = League_Service::create_league( get_current_user_id(), (string) $request->get_param( 'name' ), (int) $request->get_param( 'season' ) );
			$invite    = League_Service::generate_invite( $league_id, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'obitleague_invalid_league', $e->getMessage(), array( 'status' => 422 ) );
		}

		return new \WP_REST_Response(
			array(
				'league_id' => $league_id,
				'season'    => League_Service::current_season(),
				'invite'    => $invite,
			),
			201
		);
	}

	/** Join with an invite token; idempotent for existing members. */
	public static function join_league( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'league_join:' . get_current_user_id(), 20, HOUR_IN_SECONDS );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		try {
			$result = League_Service::join_with_token( get_current_user_id(), (string) $request->get_param( 'token' ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'obitleague_join_refused', $e->getMessage(), array( 'status' => 403 ) );
		}

		return new \WP_REST_Response( $result, $result['already_member'] ? 200 : 201 );
	}

	/** GET own entry (with picks) or PUT a draft save; 404 hides foreign entries. */
	public static function entry_route( \WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		try {
			$entry = Entry_Service::require_entry( $entry_id, $user_id );
		} catch ( \RuntimeException ) {
			return new \WP_Error( 'obitleague_not_found', 'Entry not available.', array( 'status' => 404 ) );
		}

		if ( 'PUT' === $request->get_method() ) {
			$picks   = (array) $request->get_param( 'picks' );
			$version = (int) $request->get_param( 'expected_version' );
			try {
				$saved = Entry_Service::save_draft( $entry_id, $user_id, $picks, $version );
			} catch ( Stale_Exception $e ) {
				return new \WP_Error( 'obitleague_stale_revision', $e->getMessage(), array( 'status' => 409 ) );
			} catch ( Locked_Exception $e ) {
				return new \WP_Error( 'obitleague_locked', $e->getMessage(), array( 'status' => 409 ) );
			} catch ( \Obitleague\Domain\Invalid_Team_Exception $e ) {
				return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $e->problems ), array( 'status' => 422 ) );
			}
			return new \WP_REST_Response( $saved, 200 );
		}

		$submitted = Entry_Service::submitted_revision( $entry_id );
		$picks     = $submitted ? Entry_Service::revision_picks( (int) $submitted->id ) : array();

		return new \WP_REST_Response(
			array(
				'entry'            => $entry,
				'submitted'        => $submitted ? array( 'revision_id' => (int) $submitted->id, 'receipt_id' => (string) $submitted->receipt_id, 'submitted_at' => $submitted->submitted_at ) : null,
				'picks'            => $picks,
				'expected_version' => (int) $entry->expected_version,
			),
			200
		);
	}

	/** Submit the competing revision; returns a timestamped receipt. */
	public static function submit_entry( \WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'id' );
		$user_id  = get_current_user_id();

		try {
			$receipt = Entry_Service::submit( $entry_id, $user_id, (array) $request->get_param( 'picks' ) );
		} catch ( Locked_Exception $e ) {
			return new \WP_Error( 'obitleague_locked', $e->getMessage(), array( 'status' => 409 ) );
		} catch ( Stale_Exception $e ) {
			return new \WP_Error( 'obitleague_conflict', $e->getMessage(), array( 'status' => 409 ) );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $e ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $e->problems ), array( 'status' => 422 ) );
		}

		return new \WP_REST_Response(
			array(
				'receipt_id' => $receipt->receipt_id,
				'revision_id' => $receipt->revision_id,
				'committed_at' => $receipt->committed_at->format( 'c' ),
				'deadline'     => $receipt->deadline->format( 'c' ),
				'picks'        => $receipt->picks,
				'ruleset'      => $receipt->ruleset_version,
				'summary'      => $receipt->summary(),
			),
			201
		);
	}

	/** Published standings generation for members; pick detail respects lock. */
	public static function league_standings( \WP_REST_Request $request ) {
		$league_id = (int) $request->get_param( 'id' );
		$user_id   = get_current_user_id();
		$season    = (int) ( $request->get_param( 'season' ) ?: League_Service::current_season() );

		if ( ! League_Service::is_member( $league_id, $user_id ) ) {
			return new \WP_Error( 'obitleague_forbidden', 'Only league members can view standings.', array( 'status' => 403 ) );
		}

		$rows = Standings_Service::current( $league_id, $season );
		if ( null === $rows ) {
			return new \WP_REST_Response( array( 'status' => 'not_built_yet' ), 200 );
		}

		global $wpdb;
		$my_entry = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE league_id = %d AND season = %d AND user_id = %d',
				$league_id,
				$season,
				$user_id
			)
		);
		$lock_instant = \Obitleague\Domain\Deadline_Policy::entry_deadline( $season );
		$now          = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$locked       = $now >= $lock_instant;

		return new \WP_REST_Response(
			array(
				'rows'      => $rows,
				'locked'    => $locked,
				'my_entry'  => $my_entry,
				// Pre-lock privacy: other players' picks stay hidden until lock.
				'picks_visible' => $locked,
			),
			200
		);
	}

	/** Editor decision on a review case; 409 on stale revision. */
	public static function review_decision( \WP_REST_Request $request ) {
		$case_id  = (int) $request->get_param( 'id' );
		$decision = array(
			'origin_groups'      => (array) $request->get_param( 'origin_groups' ),
			'death_date'         => (array) $request->get_param( 'death_date' ),
			'cause_disclosed'    => (bool) $request->get_param( 'cause_disclosed' ),
			'cause_text'         => (string) $request->get_param( 'cause_text' ),
			'official_statement' => (bool) $request->get_param( 'official_statement' ),
			'reason'             => (string) $request->get_param( 'reason' ),
		);

		try {
			$result = Review_Service::decide(
				$case_id,
				get_current_user_id(),
				(string) $request->get_param( 'to_state' ),
				$decision,
				(int) $request->get_param( 'expected_revision' )
			);
		} catch ( Stale_Exception $e ) {
			return new \WP_Error( 'obitleague_stale_revision', $e->getMessage(), array( 'status' => 409 ) );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $e ) {
			return new \WP_Error( 'obitleague_approval_incomplete', implode( ' ', $e->problems ), array( 'status' => 422 ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'obitleague_invalid_decision', $e->getMessage(), array( 'status' => 422 ) );
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/** Editors (and admins) may decide review cases. */
	public static function must_manage_reviews(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'obitleague_review' );
	}
}

<?php
/** Versioned REST API for public catalogue and private game actions. */
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
		register_rest_route( self::NAMESPACE, '/people', array(
			'methods' => 'GET',
			'callback' => array( self::class, 'list_people' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'search' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
			),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/wikidata/people',
			array(
				'methods' => 'GET',
				'callback' => array( self::class, 'search_wikidata_people' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args' => array(
					'search' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/wikidata/people',
			array(
				'methods' => 'POST',
				'callback' => array( self::class, 'add_wikidata_person' ),
				'permission_callback' => array( self::class, 'must_be_logged_in' ),
				'args' => array(
					'qid' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/nominations', array(
			'methods' => 'POST',
			'callback' => array( self::class, 'create_nomination' ),
			'permission_callback' => array( self::class, 'must_be_logged_in' ),
			'args' => array(
				'name' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'source_url' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ),
				'reason' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );
	}

	/** Public approved catalogue with a server-side selectability flag. */
	public static function list_people( \WP_REST_Request $request ): \WP_REST_Response {
		$query = new \WP_Query( array(
			'post_type' => Catalogue::POST_TYPE,
			'post_status' => 'publish',
			's' => (string) $request->get_param( 'search' ),
			'paged' => max( 1, (int) $request->get_param( 'page' ) ),
			'posts_per_page' => min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => 'obit_eligibility', 'value' => 'approved' ),
			),
			'fields' => 'ids',
		) );
		$people = array();
		foreach ( $query->posts as $post_id ) {
			$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
			$birth = '' !== $birth_raw ? Catalogue::partial_date_from_stored( $birth_raw ) : null;
			$age = null;
			if ( $birth ) {
				try {
					$age = \Obitleague\Domain\Age::completed_at( $birth, \Obitleague\Support\Time::now() );
				} catch ( \InvalidArgumentException ) {
					$age = null;
				}
			}
			$people[] = array(
				'id' => $post_id,
				'name' => get_the_title( $post_id ),
				'uuid' => (string) get_post_meta( $post_id, 'obit_uuid', true ),
				'link' => get_permalink( $post_id ),
				'birth' => $birth ? $birth->label() : '',
				'age' => $age,
				'occupations' => People_Sync::occupation_labels( $post_id ),
				'role' => (string) get_post_meta( $post_id, 'obit_role', true ),
				'selectable' => Catalogue::is_selectable( $post_id, self::current_season() ),
			);
		}
		return new \WP_REST_Response( $people, 200, array( 'X-WP-Total' => (string) $query->found_posts ) );
	}

	/** A nomination is a request for review, never an import. */
	/** Search eligible Wikidata candidates for a signed-in player. */
	public static function search_wikidata_people( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'wikidata_search:' . get_current_user_id(), 20, HOUR_IN_SECONDS );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}
		return Wikidata_Search_Service::search( (string) $request->get_param( 'search' ), League_Service::current_season() );
	}

	/** Import only the Wikidata result explicitly chosen by the player. */
	public static function add_wikidata_person( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'wikidata_add:' . get_current_user_id(), 10, HOUR_IN_SECONDS );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}
		$post_id = Wikidata_Search_Service::import_selected( (string) $request->get_param( 'qid' ), League_Service::current_season() );
		if ( \is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return new \WP_REST_Response( array(
			'id' => (int) $post_id,
			'uuid' => (string) get_post_meta( (int) $post_id, 'obit_uuid', true ),
			'name' => get_the_title( (int) $post_id ),
			'selectable' => Catalogue::is_selectable( (int) $post_id, League_Service::current_season() ),
		), 201 );
	}

	public static function create_nomination( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'nomination:' . get_current_user_id(), 5, HOUR_IN_SECONDS );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}
		$name = trim( (string) $request->get_param( 'name' ) );
		$url = (string) $request->get_param( 'source_url' );
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
		$post_id = wp_insert_post( array(
			'post_type' => 'obit_nomination',
			'post_status' => 'pending',
			'post_title' => $name,
			'post_author' => get_current_user_id(),
			'meta_input' => array( 'obit_source_url' => $url, 'obit_reason' => $reason ),
		), true );
		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'obitleague_nomination_failed', 'The nomination could not be recorded.', array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( array( 'nomination_id' => $post_id, 'status' => 'pending' ), 201 );
	}

	public static function must_be_logged_in(): bool|\WP_Error {
		return Auth::must_be_verified();
	}

	private static function rate_limited( string $key, int $max, int $window ) {
		$transient = 'obit_rl_' . md5( $key );
		$count = (int) get_transient( $transient );
		if ( $count >= $max ) {
			return new \WP_Error( 'obitleague_throttled', 'Too many requests; try again later.', array( 'status' => 429 ) );
		}
		set_transient( $transient, $count + 1, $window );
		return true;
	}

	private static function current_season(): int {
		return (int) ( ( new \DateTimeImmutable( 'now', \Obitleague\Support\Time::london() ) )->format( 'Y' ) ) + 1;
	}

	public static function register_game_routes(): void {
		$private = array( 'permission_callback' => array( self::class, 'must_be_logged_in' ) );
		register_rest_route( self::NAMESPACE, '/leagues', array_merge( $private, array(
			'methods' => 'POST', 'callback' => array( self::class, 'create_league' ),
			'args' => array( 'name' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ), 'season' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/leagues/join', array_merge( $private, array(
			'methods' => 'POST', 'callback' => array( self::class, 'join_league' ),
			'args' => array( 'token' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/main-entry', array_merge( $private, array( 'methods' => 'GET', 'callback' => array( self::class, 'main_entry' ) ) ) );
		register_rest_route( self::NAMESPACE, '/main-entry', array_merge( $private, array(
			'methods' => 'PUT', 'callback' => array( self::class, 'save_main_entry' ),
			'args' => array( 'picks' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ), 'expected_version' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ), 'team_name' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/main-standings', array_merge( $private, array(
			'methods' => 'GET', 'callback' => array( self::class, 'main_standings' ),
			'args' => array( 'season' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ), 'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ), 'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/main-entry/submit', array_merge( $private, array(
			'methods' => 'POST', 'callback' => array( self::class, 'submit_main_entry' ),
			'args' => array( 'picks' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/entries/(?P<id>\d+)', array_merge( $private, array(
			'methods' => array( 'GET', 'PUT' ), 'callback' => array( self::class, 'entry_route' ),
			'args' => array( 'picks' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'expected_version' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ), 'team_name' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/entries/(?P<id>\d+)/submit', array_merge( $private, array(
			'methods' => 'POST', 'callback' => array( self::class, 'submit_entry' ),
			'args' => array( 'picks' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/leagues/(?P<id>\d+)/standings', array_merge( $private, array(
			'methods' => 'GET', 'callback' => array( self::class, 'league_standings' ),
			'args' => array( 'season' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ), 'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ), 'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ), 'main' => array( 'type' => 'boolean', 'default' => false ) ),
		) ) );
		register_rest_route( self::NAMESPACE, '/reviews/(?P<id>\d+)/decision', array(
			'methods' => 'POST', 'callback' => array( self::class, 'review_decision' ), 'permission_callback' => array( self::class, 'must_manage_reviews' ),
			'args' => array( 'to_state' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'approved', 'rejected', 'retracted' ) ), 'expected_revision' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ), 'origin_groups' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'death_date' => array( 'type' => 'object' ), 'cause_disclosed' => array( 'type' => 'boolean' ), 'cause_text' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ), 'official_statement' => array( 'type' => 'boolean' ), 'reason' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) ),
		) );
	}

	public static function main_standings( \WP_REST_Request $request ): \WP_REST_Response {
		$season = (int) ( $request->get_param( 'season' ) ?: League_Service::current_season() );
		return new \WP_REST_Response( Overall_Standings::leaderboard_route( $season, (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) ), 200 );
	}

	public static function main_entry( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( '0' === (string) get_user_meta( get_current_user_id(), 'obitleague_email_verified', true ) && '1' === (string) get_user_meta( get_current_user_id(), 'obitleague_campaign_signup', true ) ) {
			return new \WP_Error( 'obitleague_email_unverified', 'Verify your email before building your team.', array( 'status' => 403 ) );
		}
		$user_id = get_current_user_id();
		$season = League_Service::current_season();
		$entry_id = League_Service::ensure_main_entry( $user_id, $season );
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d AND user_id = %d', $entry_id, $user_id ) );
		$revision = $entry && 'submitted' === (string) $entry->state ? Entry_Service::submitted_revision( $entry_id ) : self::latest_draft_revision( $entry_id );
		return new \WP_REST_Response( array(
			'entry_id' => $entry_id, 'season' => $season, 'state' => $entry ? (string) $entry->state : 'draft',
			'can_edit' => $entry && \Obitleague\Domain\Entry_Rules::can_edit( (string) $entry->state, \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) ) && 'member' === (string) League_Service::member_row( (int) $entry->league_id, $user_id )?->status,
			'team_name' => $entry ? (string) $entry->team_name : '', 'expected_version' => $entry ? (int) $entry->expected_version : 1,
			'picks' => $revision ? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $revision->id ) ) : array(),
			'pick_details' => $revision ? array_map( static function ( $pick ) use ( $season ): array { $uuid = (string) $pick->person_uuid; $person = League_View_Service::person_card_by_uuid( $uuid ); $post_id = Review_Service::person_post_id( $uuid ); $person['selectable'] = $post_id > 0 && Catalogue::is_selectable( $post_id, $season ); return $person; }, Entry_Service::revision_picks( (int) $revision->id ) ) : array(),
		), 200 );
	}

	public static function save_main_entry( \WP_REST_Request $request ) {
		if ( '0' === (string) get_user_meta( get_current_user_id(), 'obitleague_email_verified', true ) && '1' === (string) get_user_meta( get_current_user_id(), 'obitleague_campaign_signup', true ) ) {
			return new \WP_Error( 'obitleague_email_unverified', 'Verify your email before saving your team.', array( 'status' => 403 ) );
		}
		$user_id = get_current_user_id();
		$season = League_Service::current_season();
		$entry_id = League_Service::ensure_main_entry( $user_id, $season );
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d AND user_id = %d', $entry_id, $user_id ) );
		if ( ! $entry || ! \Obitleague\Domain\Entry_Rules::can_edit( (string) $entry->state, \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) ) || 'member' !== (string) League_Service::member_row( (int) $entry->league_id, $user_id )?->status ) {
			return new \WP_Error( 'obitleague_locked', 'The main team is locked for the season.', array( 'status' => 409 ) );
		}
		$previous = 'submitted' === (string) $entry->state ? Entry_Service::submitted_revision( $entry_id ) : self::latest_draft_revision( $entry_id );
		$previous_picks = $previous ? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $previous->id ) ) : array();
		$picks = (array) $request->get_param( 'picks' );
		$team_name = $request->has_param( 'team_name' ) ? trim( sanitize_text_field( (string) $request->get_param( 'team_name' ) ) ) : (string) $entry->team_name;
		if ( '' !== $team_name && mb_strlen( $team_name ) > 120 ) {
			return new \WP_Error( 'obitleague_invalid_team_name', 'Team names may be up to 120 characters.', array( 'status' => 422 ) );
		}
		try {
			$picks = \Obitleague\Domain\Entry_Rules::validate_picks( $picks );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		}
		foreach ( $picks as $uuid ) {
			$post_id = Review_Service::person_post_id( $uuid );
			$is_existing = in_array( $uuid, $previous_picks, true );
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) || ( ! $is_existing && ( 'approved' !== (string) get_post_meta( $post_id, 'obit_eligibility', true ) || ! Catalogue::is_selectable( $post_id, $season ) ) ) ) {
				return new \WP_Error( 'obitleague_unselectable', 'Main-team picks must be published and selectable for this season.', array( 'status' => 422 ) );
			}
		}
		try {
			$saved = Entry_Service::save_draft( $entry_id, $user_id, $picks, (int) $request->get_param( 'expected_version' ), $team_name );
		} catch ( Stale_Exception $exception ) {
			return new \WP_Error( 'obitleague_stale_revision', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( Locked_Exception $exception ) {
			return new \WP_Error( 'obitleague_locked', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		}
		$saved['team_name'] = $team_name;
		return new \WP_REST_Response( $saved, 200 );
	}

	public static function submit_main_entry( \WP_REST_Request $request ) {
		if ( '0' === (string) get_user_meta( get_current_user_id(), 'obitleague_email_verified', true ) && '1' === (string) get_user_meta( get_current_user_id(), 'obitleague_campaign_signup', true ) ) {
			return new \WP_Error( 'obitleague_email_unverified', 'Verify your email before submitting your team.', array( 'status' => 403 ) );
		}
		$user_id = get_current_user_id();
		$season = League_Service::current_season();
		$entry_id = League_Service::ensure_main_entry( $user_id, $season );
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d AND user_id = %d', $entry_id, $user_id ) );
		if ( ! $entry || ! \Obitleague\Domain\Entry_Rules::can_submit( (string) $entry->state, \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) ) ) {
			return new \WP_Error( 'obitleague_locked', 'This team cannot be submitted now.', array( 'status' => 409 ) );
		}
		$revision = self::latest_draft_revision( $entry_id );
		$previous_picks = $revision ? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $revision->id ) ) : array();
		$picks = (array) $request->get_param( 'picks' );
		try {
			$picks = \Obitleague\Domain\Entry_Rules::validate_picks( $picks );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		}
		foreach ( $picks as $uuid ) {
			$post_id = Review_Service::person_post_id( (string) $uuid );
			$is_existing = in_array( (string) $uuid, $previous_picks, true );
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) || ( ! $is_existing && ( 'approved' !== (string) get_post_meta( $post_id, 'obit_eligibility', true ) || ! Catalogue::is_selectable( $post_id, $season ) ) ) ) {
				return new \WP_Error( 'obitleague_unselectable', 'Main-team picks must be published and selectable for this season.', array( 'status' => 422 ) );
			}
		}
		try {
			$receipt = Entry_Service::submit( $entry_id, $user_id, $picks );
		} catch ( Locked_Exception $exception ) {
			return new \WP_Error( 'obitleague_locked', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( Stale_Exception $exception ) {
			return new \WP_Error( 'obitleague_conflict', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		}
		global $wpdb;
		$revision = Entry_Service::submitted_revision( $entry_id );
		if ( ! $revision ) {
			return new \WP_Error( 'obitleague_submission_failed', 'The submitted team could not be loaded.', array( 'status' => 500 ) );
		}
		$person_ids = array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $revision->id ) );
		if ( $person_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $person_ids ), '%s' ) );
			$events = $wpdb->get_col( $wpdb->prepare( "SELECT uuid FROM {$wpdb->prefix}obitleague_events WHERE person_uuid IN ({$placeholders}) AND approved_at IS NOT NULL AND retracted_at IS NULL", ...$person_ids ) );
			foreach ( (array) $events as $event_uuid ) {
				Outbox_Service::award_event( (string) $event_uuid, $entry_id );
			}
		}
		$league_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT league_id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d', $entry_id ) );
		Jobs::schedule_standings_rebuild( $league_id, $season );
		return new \WP_REST_Response( array( 'entry_id' => $entry_id, 'receipt_id' => $receipt->receipt_id, 'submitted_at' => $receipt->committed_at->format( 'c' ), 'deadline' => $receipt->deadline->format( 'c' ), 'ruleset' => $receipt->ruleset_version ), 201 );
	}

	private static function latest_draft_revision( int $entry_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id = %d AND kind = 'draft' ORDER BY id DESC LIMIT 1", $entry_id ) ) ?: null;
	}

	public static function create_league( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'league_create:' . get_current_user_id(), 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		try {
			$league_id = League_Service::create_league( get_current_user_id(), (string) $request->get_param( 'name' ), (int) $request->get_param( 'season' ) );
			$invite = League_Service::generate_invite( $league_id, get_current_user_id() );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'obitleague_invalid_league', $exception->getMessage(), array( 'status' => 422 ) );
		}
		return new \WP_REST_Response( array( 'league_id' => $league_id, 'season' => League_Service::current_season(), 'invite' => $invite ), 201 );
	}

	public static function join_league( \WP_REST_Request $request ) {
		$rate = self::rate_limited( 'league_join:' . get_current_user_id(), 20, HOUR_IN_SECONDS );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		try {
			$result = League_Service::join_with_token( get_current_user_id(), (string) $request->get_param( 'token' ) );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'obitleague_join_refused', $exception->getMessage(), array( 'status' => 403 ) );
		}
		return new \WP_REST_Response( $result, $result['already_member'] ? 200 : 201 );
	}

	public static function entry_route( \WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		try {
			$entry = Entry_Service::require_entry( $entry_id, $user_id );
		} catch ( \RuntimeException ) {
			return new \WP_Error( 'obitleague_not_found', 'Entry not available.', array( 'status' => 404 ) );
		}
		if ( 'PUT' === $request->get_method() ) {
			$season = (int) $entry->season;
			$previous_revision = 'submitted' === (string) $entry->state ? Entry_Service::submitted_revision( $entry_id ) : self::latest_draft_revision( $entry_id );
			$previous_picks = $previous_revision ? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $previous_revision->id ) ) : array();
			$picks = (array) $request->get_param( 'picks' );
			foreach ( $picks as $uuid ) {
				$uuid = (string) $uuid;
				$post_id = Review_Service::person_post_id( $uuid );
				$is_existing = in_array( $uuid, $previous_picks, true );
				if ( ! $post_id || 'publish' !== get_post_status( $post_id ) || ( ! $is_existing && ( 'approved' !== (string) get_post_meta( $post_id, 'obit_eligibility', true ) || ! Catalogue::is_selectable( $post_id, $season ) ) ) ) {
					return new \WP_Error( 'obitleague_unselectable', 'Team picks must be published and selectable for this season.', array( 'status' => 422 ) );
				}
			}
			$team_name = $request->has_param( 'team_name' ) ? trim( sanitize_text_field( (string) $request->get_param( 'team_name' ) ) ) : null;
			if ( null !== $team_name && '' !== $team_name && mb_strlen( $team_name ) > 120 ) {
				return new \WP_Error( 'obitleague_invalid_team_name', 'Team names may be up to 120 characters.', array( 'status' => 422 ) );
			}
			try {
				$saved = Entry_Service::save_draft( $entry_id, $user_id, $picks, (int) $request->get_param( 'expected_version' ), $team_name );
			} catch ( Stale_Exception $exception ) {
				return new \WP_Error( 'obitleague_stale_revision', $exception->getMessage(), array( 'status' => 409 ) );
			} catch ( Locked_Exception $exception ) {
				return new \WP_Error( 'obitleague_locked', $exception->getMessage(), array( 'status' => 409 ) );
			} catch ( \Obitleague\Domain\Invalid_Team_Exception $exception ) {
				return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
			}
			return new \WP_REST_Response( $saved, 200 );
		}
		$revision = 'submitted' === (string) $entry->state ? Entry_Service::submitted_revision( $entry_id ) : self::latest_draft_revision( $entry_id );
		$season = (int) $entry->season;
		$member = League_Service::member_row( (int) $entry->league_id, $user_id );
		$picks = $revision ? Entry_Service::revision_picks( (int) $revision->id ) : array();
		$pick_details = array_map( static function ( $pick ) use ( $season ): array {
			$uuid = (string) $pick->person_uuid;
			$person = League_View_Service::person_card_by_uuid( $uuid );
			$post_id = Review_Service::person_post_id( $uuid );
			$person['selectable'] = $post_id > 0 && Catalogue::is_selectable( $post_id, $season );
			return $person;
		}, $picks );
		$can_edit = 'member' === (string) $member?->status && \Obitleague\Domain\Entry_Rules::can_edit( (string) $entry->state, \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) );
		return new \WP_REST_Response( array( 'entry_id' => $entry_id, 'season' => $season, 'state' => (string) $entry->state, 'team_name' => (string) $entry->team_name, 'can_edit' => $can_edit, 'expected_version' => (int) $entry->expected_version, 'picks' => array_map( static fn ( $pick ): string => (string) $pick->person_uuid, $picks ), 'pick_details' => $pick_details ), 200 );
	}

	public static function submit_entry( \WP_REST_Request $request ) {
		$entry_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		try {
			$entry = Entry_Service::require_entry( $entry_id, $user_id );
		} catch ( Locked_Exception $exception ) {
			return new \WP_Error( 'obitleague_not_found', 'Entry not available.', array( 'status' => 404 ) );
		}
		$picks = (array) $request->get_param( 'picks' );
		foreach ( $picks as $uuid ) {
			$post_id = Review_Service::person_post_id( (string) $uuid );
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) || 'approved' !== (string) get_post_meta( $post_id, 'obit_eligibility', true ) || ! Catalogue::is_selectable( $post_id, (int) $entry->season ) ) {
				return new \WP_Error( 'obitleague_unselectable', 'Team picks must be published and selectable for this season.', array( 'status' => 422 ) );
			}
		}
		try {
			$receipt = Entry_Service::submit( $entry_id, $user_id, $picks );
		} catch ( Locked_Exception $exception ) {
			return new \WP_Error( 'obitleague_locked', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( Stale_Exception $exception ) {
			return new \WP_Error( 'obitleague_conflict', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_invalid_selection', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		}
		return new \WP_REST_Response( array( 'receipt_id' => $receipt->receipt_id, 'revision_id' => $receipt->revision_id, 'committed_at' => $receipt->committed_at->format( 'c' ), 'deadline' => $receipt->deadline->format( 'c' ), 'picks' => $receipt->picks, 'ruleset' => $receipt->ruleset_version, 'summary' => $receipt->summary() ), 201 );
	}

	public static function league_standings( \WP_REST_Request $request ) {
		$league_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$season = (int) ( $request->get_param( 'season' ) ?: League_Service::current_season() );
		$main_request = (bool) $request->get_param( 'main' );
		$is_main = 1 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT is_main FROM ' . $GLOBALS['wpdb']->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
		if ( $main_request && ! $is_main ) {
			return new \WP_Error( 'obitleague_invalid_league', 'This is not the canonical main league.', array( 'status' => 404 ) );
		}
		if ( ! $is_main && ! League_Service::is_member( $league_id, $user_id ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'obitleague_forbidden', 'Only league members can view side-league standings.', array( 'status' => 403 ) );
		}
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$rows = Standings_Service::current( $league_id, $season, $per_page, ( $page - 1 ) * $per_page );
		if ( null === $rows ) {
			return new \WP_REST_Response( array( 'status' => 'not_built_yet' ), 200 );
		}
		$total = Standings_Service::count_current( $league_id, $season );
		$my_entry = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT id FROM ' . $GLOBALS['wpdb']->prefix . 'obitleague_entries WHERE league_id = %d AND season = %d AND user_id = %d', $league_id, $season, $user_id ) );
		$locked = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) >= \Obitleague\Domain\Deadline_Policy::entry_deadline( $season );
		return new \WP_REST_Response( array( 'rows' => $rows, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'locked' => $locked, 'my_entry' => $my_entry, 'picks_visible' => $locked ), 200 );
	}

	public static function review_decision( \WP_REST_Request $request ) {
		$case_id = (int) $request->get_param( 'id' );
		$decision = array(
			'origin_groups' => (array) $request->get_param( 'origin_groups' ), 'death_date' => (array) $request->get_param( 'death_date' ),
			'cause_disclosed' => (bool) $request->get_param( 'cause_disclosed' ), 'cause_text' => (string) $request->get_param( 'cause_text' ),
			'official_statement' => (bool) $request->get_param( 'official_statement' ), 'reason' => (string) $request->get_param( 'reason' ),
		);
		try {
			$result = Review_Service::decide( $case_id, get_current_user_id(), (string) $request->get_param( 'to_state' ), $decision, (int) $request->get_param( 'expected_revision' ) );
		} catch ( Stale_Exception $exception ) {
			return new \WP_Error( 'obitleague_stale_revision', $exception->getMessage(), array( 'status' => 409 ) );
		} catch ( \Obitleague\Domain\Invalid_Team_Exception $exception ) {
			return new \WP_Error( 'obitleague_approval_incomplete', implode( ' ', $exception->problems ), array( 'status' => 422 ) );
		} catch ( \InvalidArgumentException $exception ) {
			return new \WP_Error( 'obitleague_invalid_decision', $exception->getMessage(), array( 'status' => 422 ) );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	public static function must_manage_reviews(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'obitleague_review' );
	}
}

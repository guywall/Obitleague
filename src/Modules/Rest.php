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
}

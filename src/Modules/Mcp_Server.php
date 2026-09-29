<?php
/**
 * MCP (Model Context Protocol) endpoint.
 *
 * A thin JSON-RPC 2.0 adapter over the BYOAI REST surface. Tool calls
 * synthesize a REST request — bearer header included — and delegate to the
 * Rest_Agents callbacks, so the competition rules live in exactly one place.
 * The endpoint never implements scoring, eligibility or deadlines itself.
 *
 * Wire compatibility: `initialize`, `tools/list`, `tools/call` with JSON-RPC
 * 2.0 responses; `GET` returns a descriptive manifest for humans and
 * discovery crawlers.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Mcp_Server {

	private const ROUTE = '/mcp';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			OBITLEAGUE_REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'manifest' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle' ),
					'permission_callback' => '__return_true', // Auth happens per tool call via the bearer token.
				),
			)
		);
	}

	/** Human- and discovery-readable description of the tools. */
	public static function manifest( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'protocol'    => 'mcp',
				'version'     => '1.0',
				'name'        => 'Obitleague',
				'description' => 'Fantasy dead-pool competition: agents compete against humans under identical rules.',
				'transport'   => 'http-json-rpc',
				'endpoint'    => rest_url( OBITLEAGUE_REST_NAMESPACE . self::ROUTE ),
				'auth'        => 'Authorization: Bearer <agent token> on every tools/call; register an agent via POST /agents first (see /ai-integrate/).',
				'tools'       => array_map( static fn ( array $tool ): array => array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'inputSchema' => $tool['inputSchema'],
				), self::tools() ),
			),
			200
		);
	}

	/** Tool definitions (JSON-schema input). */
	private static function tools(): array {
		return array(
			array(
				'name'        => 'ob_rules',
				'description' => 'Get the competition rules: season, deadlines, team size, scoring formula, submission floor and tie-breaks.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'ob_people',
				'description' => 'Search the eligible people catalogue. Returns only living, selectable people with uuid, name, birth date and occupations.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Name or occupation text.' ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
					),
				),
			),
			array(
				'name'        => 'ob_me',
				'description' => 'Get this agent\'s profile, team state and submission instant.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'ob_team_get',
				'entry'       => 'team_get',
				'description' => 'Read the agent\'s current team (picks, state, expected_version, receipt).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new \stdClass() ),
			),
			array(
				'name'        => 'ob_team_submit',
				'description' => 'Submit (or amend) the agent\'s team of ten distinct eligible people. A pick scores only for a verified death after the submission instant.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'picks'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'minItems' => 10, 'maxItems' => 10 ),
						'expected_version' => array( 'type' => 'integer', 'description' => 'Concurrency guard from ob_team_get when amending.' ),
					),
					'required'   => array( 'picks' ),
				),
			),
			array(
				'name'        => 'ob_standings',
				'description' => 'Read the overall championship and this agent\'s own rank.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'season'   => array( 'type' => 'integer' ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					),
				),
			),
		);
	}

	/** JSON-RPC 2.0 dispatcher. */
	public static function handle( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$method  = (string) ( $body['method'] ?? '' );
		$id      = $body['id'] ?? null;
		$params  = is_array( $body['params'] ?? null ) ? $body['params'] : array();

		$respond = static function ( array $result ) use ( $id ): \WP_REST_Response {
			return new \WP_REST_Response(
				array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ),
				200
			);
		};
		$error = static function ( int $code, string $message ) use ( $id ): \WP_REST_Response {
			return new \WP_REST_Response(
				array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) ),
				200
			);
		};

		switch ( $method ) {
			case 'initialize':
				return $respond(
					array(
						'protocolVersion' => '2024-11-05',
						'capabilities'    => array( 'tools' => new \stdClass() ),
						'serverInfo'      => array(
							'name'    => 'Obitleague',
							'version' => OBITLEAGUE_VERSION,
						),
					)
				);

			case 'tools/list':
				return $respond(
					array( 'tools' => self::tools() )
				);

			case 'tools/call':
				$name = (string) ( $params['name'] ?? '' );
				$args = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
				try {
					$call = self::call_tool( $name, $args );
				} catch ( \Throwable $exception ) {
					return $error( -32603, $exception->getMessage() );
				}
				if ( null === $call ) {
					return $error( -32602, "Unknown tool: {$name}" );
				}
				if ( is_wp_error( $call ) ) {
					return $respond(
						array(
							'content' => array(
								array( 'type' => 'text', 'text' => sprintf( 'Error %s: %s', $call->get_error_code(), $call->get_error_message() ) ),
							),
							'isError' => true,
						)
					);
				}
				return $respond(
					array(
						'content' => array(
							array( 'type' => 'text', 'text' => (string) wp_json_encode( $call ) ),
						),
					)
				);

			case 'ping':
				return $respond( new \stdClass() );

			default:
				return $error( -32601, "Unknown method: {$method}" );
		}
	}

	/**
	 * Execute one tool by delegating to the shared REST callbacks with a
	 * synthesized request. The bearer header passes through unchanged, so
	 * agent authentication and rate limiting are the REST layer's own.
	 *
	 * @return mixed|\WP_Error|null WP_REST payload array, WP_Error, or null for unknown tools.
	 */
	private static function call_tool( string $name, array $args ) {
		$route_map = array(
			'ob_rules'      => array( 'rules', '/agents/rules', array() ),
			'ob_people'     => array( 'people', '/agents/people', array( 'search', 'page', 'per_page' ) ),
			'ob_me'         => array( 'me', '/agents/me', array() ),
			'ob_team_get'   => array( 'team', '/agents/team', array() ),
			'ob_team_submit'=> array( 'team', '/agents/team', array( 'picks', 'expected_version' ), 'POST' ),
			'ob_standings'  => array( 'standings', '/agents/standings', array( 'season', 'page', 'per_page' ) ),
		);
		if ( ! isset( $route_map[ $name ] ) ) {
			return null;
		}
		[ $callback, $route, $allowed, $http_method ] = array_pad( $route_map[ $name ], 4, null );

		$agent = Rest_Agents::must_be_agent();
		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		$synthesized = new \WP_REST_Request( $http_method ?? 'GET', $route );
		foreach ( $allowed as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$synthesized->set_param( $key, $args[ $key ] );
			}
		}
		if ( 'POST' === ( $http_method ?? 'GET' ) ) {
			$synthesized->set_body_params( $args );
		}

		$response = Rest_Agents::{$callback}( $synthesized );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( $response instanceof \WP_REST_Response ) {
			return $response->get_data();
		}
		return $response;
	}
}

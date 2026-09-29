<?php
/**
 * A2A (Agent2Agent) compatibility.
 *
 * Serves a machine-readable agent card at /.well-known/agent.json describing
 * Obitleague's capabilities, authentication and endpoint. The card points
 * machines at the same REST surface every other client uses; no capability
 * is implemented here.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class A2A {

	private function __construct() {}

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'init', array( self::class, 'rewrite' ) );
		add_filter( 'query_vars', array( self::class, 'query_var' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_serve' ), 0 );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^\.well-known/agent\.json$', 'index.php?ob_a2a_card=1', 'top' );
	}

	/** @param string[] $vars */
	public static function query_var( array $vars ): array {
		$vars[] = 'ob_a2a_card';
		return $vars;
	}

	/** Serve the card from the pretty URL when rewrites resolve to us. */
	public static function maybe_serve(): void {
		if ( '1' !== (string) get_query_var( 'ob_a2a_card' ) ) {
			return;
		}
		$card = self::card();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		status_header( 200 );
		echo wp_json_encode( $card, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	public static function register_routes(): void {
		register_rest_route(
			OBITLEAGUE_REST_NAMESPACE,
			'/a2a/agent-card',
			array(
				'methods'             => 'GET',
				'callback'            => static fn (): \WP_REST_Response => new \WP_REST_Response( self::card(), 200 ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** The A2A agent card (discovery document). */
	public static function card(): array {
		$season = League_Service::current_season();
		return array(
			'name'        => 'Obitleague',
			'description' => 'Fantasy dead-pool competition where autonomous AI agents compete against human players under identical rules. Agents pick ten people; verified deaths score max(1, 100 - age).',
			'url'         => home_url( '/' ),
			'version'     => OBITLEAGUE_VERSION,
			'capabilities' => array(
				'streaming'       => false,
				'pushNotifications' => false,
				'stateTransitionHistory' => false,
			),
			'defaultInputModes'  => array( 'application/json' ),
			'defaultOutputModes' => array( 'application/json' ),
			'provider'    => array(
				'organization' => 'Obitleague',
				'url'          => home_url( '/' ),
			),
			// A2A's auth field describes how agents authenticate to us.
			'authentication' => array(
				'schemes' => array( 'bearer' ),
				'credentials' => 'Register an agent via POST /wp-json/obitleague/v1/agents with an operator account, then send Authorization: Bearer <token>.',
			),
			// Obitleague's own contract surfaces, so an agent can act directly.
			'obitleague' => array(
				'season'          => $season,
				'rules_endpoint'  => rest_url( OBITLEAGUE_REST_NAMESPACE . '/agents/rules' ),
				'people_endpoint' => rest_url( OBITLEAGUE_REST_NAMESPACE . '/agents/people' ),
				'team_endpoint'   => rest_url( OBITLEAGUE_REST_NAMESPACE . '/agents/team' ),
				'standings_endpoint' => rest_url( OBITLEAGUE_REST_NAMESPACE . '/agents/standings' ),
				'mcp_endpoint'    => rest_url( OBITLEAGUE_REST_NAMESPACE . '/mcp' ),
				'rest_base'       => rest_url( OBITLEAGUE_REST_NAMESPACE ),
				'documentation'   => home_url( '/ai-integrate/' ),
				'entry_open_until' => \Obitleague\Domain\Deadline_Policy::entry_deadline( $season )->format( 'c' ),
			),
			// A2A skills: what an agent can ask this platform to do.
			'skills' => array(
				array(
					'id'          => 'obitleague-competition',
					'name'        => 'Join the death pool competition',
					'description' => 'Read rules, research eligible people, submit a team of ten and follow standings. Same rules as human players.',
					'tags'        => array( 'game', 'competition', 'prediction' ),
				),
			),
		);
	}
}

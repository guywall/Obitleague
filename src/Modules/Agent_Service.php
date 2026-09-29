<?php
/**
 * Agent service.
 *
 * AI competitors are participants, not a separate game. Every agent is an
 * ordinary WordPress account (subscriber role only) holding standard
 * main-league entries; this service adds the agent metadata and the scoped
 * API credentials that let an external program play on that account's
 * behalf. It never grants the agent user any administrative capability.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Agent_Rules;

final class Agent_Service {

	private const META_AGENT_USER = 'obitleague_agent_user';

	private function __construct() {}

	/**
	 * Register an agent for an operator account.
	 *
	 * Creates the agent's participant user, the metadata row and (optionally)
	 * the first API token. The agent user is the competitor: entries,
	 * standings and awards attach to it exactly as for a human player.
	 *
	 * @return array{agent_id:int, agent_user_id:int, token?:string, token_prefix?:string}
	 */
	public static function register_agent(
		int $operator_id,
		string $name,
		string $description = '',
		string $category = Agent_Rules::CATEGORY_COMMUNITY,
		string $participation = Agent_Rules::PARTICIPATION_BYOAI,
		string $model = '',
		string $provider = '',
		string $website = '',
		bool $issue_token = true
	): array {
		global $wpdb;

		if ( $operator_id < 1 || ! get_userdata( $operator_id ) ) {
			throw new \InvalidArgumentException( 'A valid operator account is required.' );
		}
		$name = trim( $name );
		if ( '' === $name || mb_strlen( $name ) > Agent_Rules::NAME_MAX ) {
			throw new \InvalidArgumentException( 'The agent needs a name of at most ' . Agent_Rules::NAME_MAX . ' characters.' );
		}
		if ( mb_strlen( $description ) > Agent_Rules::DESCRIPTION_MAX ) {
			throw new \InvalidArgumentException( 'The description is limited to ' . Agent_Rules::DESCRIPTION_MAX . ' characters.' );
		}
		if ( ! in_array( $category, Agent_Rules::categories(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown agent category.' );
		}
		if ( ! in_array( $participation, Agent_Rules::participations(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown participation method.' );
		}
		if ( '' !== $website && ! (bool) preg_match( '#^https://\S+$#', $website ) ) {
			throw new \InvalidArgumentException( 'The agent website must be an https URL.' );
		}
		if ( mb_strlen( $website ) > Agent_Rules::WEBSITE_MAX ) {
			throw new \InvalidArgumentException( 'The website URL is too long.' );
		}

		// Bounded agents per operator: prevent mass registration abuse.
		$agents_table = $wpdb->prefix . 'obitleague_agents';
		$existing_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$agents_table} WHERE operator_user_id = %d", $operator_id )
		);
		$cap = (int) apply_filters( 'obitleague_max_agents_per_operator', 5, $operator_id );
		if ( $existing_count >= $cap ) {
			throw new \RuntimeException( 'This account has reached its agent limit.' );
		}

		$slug = self::unique_slug( $name );
		$now  = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$agent_user_id = self::create_agent_user( $name, $slug, $operator_id );

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$agents_table}
					 (user_id, slug, name, description, category, model, model_verified, provider, operator_label, operator_user_id, participation, website, status, created_at, updated_at)
					 VALUES (%d, %s, %s, %s, %s, %s, %d, %s, %s, %d, %s, %s, %s, %s, %s)",
					$agent_user_id,
					$slug,
					$name,
					$description,
					$category,
					substr( trim( $model ), 0, Agent_Rules::MODEL_MAX ),
					0,
					substr( trim( $provider ), 0, Agent_Rules::MODEL_MAX ),
					self::operator_label( $operator_id ),
					$operator_id,
					$participation,
					$website,
					Agent_Rules::STATUS_ACTIVE,
					$now,
					$now
				)
			);
			$agent_id = (int) $wpdb->insert_id;
			if ( $agent_id < 1 ) {
				throw new \RuntimeException( 'Could not store the agent record.' );
			}

			// Give the agent user its canonical main-season entry, exactly as
			// every human account receives on registration.
			Main_League_Service::ensure_user_entry( $agent_user_id, League_Service::current_season() );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			if ( isset( $agent_user_id ) && $agent_user_id > 0 ) {
				// Best-effort cleanup so a failed registration leaves no
				// orphan participant account.
				\wp_delete_user( $agent_user_id );
			}
			throw $exception;
		}

		$result = array(
			'agent_id'      => $agent_id,
			'agent_user_id' => $agent_user_id,
			'slug'          => $slug,
		);

		if ( $issue_token ) {
			$token = self::issue_token( $agent_id, $operator_id, 'initial token' );
			$result['token']        = $token['token'];
			$result['token_prefix'] = $token['prefix'];
		}

		do_action( 'obitleague_agent_registered', $agent_id, $agent_user_id, $operator_id );

		return $result;
	}

	/** Fetch one agent row by id, slug or linked user id. */
	public static function get_agent( int $agent_id = 0, string $slug = '', int $user_id = 0 ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_agents';
		if ( $agent_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $agent_id ) );
		} elseif ( '' !== $slug ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) );
		} elseif ( $user_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
		} else {
			return null;
		}
		return $row ?: null;
	}

	/** True when this operator owns the agent. */
	public static function is_operator( int $agent_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_agents WHERE id = %d AND operator_user_id = %d',
				$agent_id,
				$user_id
			)
		);
	}

	/** Update operator-editable metadata. Model verification is admin-only. */
	public static function update_meta( int $agent_id, int $requester_id, array $fields ): bool {
		$agent = self::get_agent( $agent_id );
		if ( ! $agent ) {
			throw new \InvalidArgumentException( 'Unknown agent.' );
		}
		$is_admin   = current_user_can( 'manage_options' );
		$is_operator = self::is_operator( $agent_id, $requester_id );
		if ( ! $is_admin && ! $is_operator ) {
			throw new \RuntimeException( 'Only the operator or an administrator can update this agent.' );
		}

		global $wpdb;
		$updates = array();
		$args    = array();

		if ( isset( $fields['name'] ) ) {
			$name = trim( (string) $fields['name'] );
			if ( '' === $name || mb_strlen( $name ) > Agent_Rules::NAME_MAX ) {
				throw new \InvalidArgumentException( 'The agent needs a name of at most ' . Agent_Rules::NAME_MAX . ' characters.' );
			}
			$updates['name'] = $name;
		}
		if ( isset( $fields['description'] ) ) {
			$description = trim( (string) $fields['description'] );
			if ( mb_strlen( $description ) > Agent_Rules::DESCRIPTION_MAX ) {
				throw new \InvalidArgumentException( 'The description is limited to ' . Agent_Rules::DESCRIPTION_MAX . ' characters.' );
			}
			$updates['description'] = $description;
		}
		if ( isset( $fields['model'] ) ) {
			$model = trim( (string) $fields['model'] );
			if ( mb_strlen( $model ) > Agent_Rules::MODEL_MAX ) {
				throw new \InvalidArgumentException( 'The model string is too long.' );
			}
			$updates['model']          = $model;
			// A self-reported change resets independent verification.
			$updates['model_verified'] = 0;
		}
		if ( isset( $fields['provider'] ) && $is_admin ) {
			$updates['provider'] = substr( trim( (string) $fields['provider'] ), 0, Agent_Rules::MODEL_MAX );
		}
		if ( isset( $fields['website'] ) ) {
			$website = trim( (string) $fields['website'] );
			if ( '' !== $website && ! (bool) preg_match( '#^https://\S+$#', $website ) ) {
				throw new \InvalidArgumentException( 'The agent website must be an https URL.' );
			}
			$updates['website'] = $website;
		}

		if ( ! $updates ) {
			return false;
		}
		$updates['updated_at'] = current_time( 'mysql', true );
		$ok = (bool) $wpdb->update(
			$wpdb->prefix . 'obitleague_agents',
			$updates,
			array( 'id' => $agent_id )
		);
		if ( $ok ) {
			do_action( 'obitleague_agent_updated', $agent_id, $requester_id, array_keys( $updates ) );
		}
		return $ok;
	}

	/** Admin lifecycle control. */
	public static function set_status( int $agent_id, string $status, int $admin_id ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( 'Only administrators can change agent status.' );
		}
		if ( ! in_array( $status, Agent_Rules::statuses(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown agent status.' );
		}
		global $wpdb;
		$ok = (bool) $wpdb->update(
			$wpdb->prefix . 'obitleague_agents',
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $agent_id )
		);
		if ( $ok ) {
			do_action( 'obitleague_agent_status_changed', $agent_id, $status, $admin_id );
		}
		return $ok;
	}

	/** Admin-only: mark a declared model as independently verified. */
	public static function verify_model( int $agent_id, int $admin_id ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( 'Only administrators can verify a model.' );
		}
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . 'obitleague_agents',
			array(
				'model_verified' => 1,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $agent_id )
		);
	}

	/**
	 * Issue a scoped API token. The plaintext is returned exactly once;
	 * only its hash is stored.
	 *
	 * @return array{token:string, prefix:string, id:int}
	 */
	public static function issue_token( int $agent_id, int $requester_id, string $label = '' ): array {
		global $wpdb;
		if ( ! self::is_operator( $agent_id, $requester_id ) && ! current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( 'Only the operator or an administrator can issue tokens.' );
		}
		$raw    = 'obl_' . bin2hex( random_bytes( 24 ) );
		$prefix = substr( $raw, 0, 11 );
		$now    = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $wpdb->prefix . 'obitleague_agent_tokens (agent_id, token_hash, token_prefix, label, status, created_at)
				 VALUES (%d, %s, %s, %s, %s, %s)',
				$agent_id,
				hash( 'sha256', $raw ),
				$prefix,
				substr( trim( $label ), 0, 120 ),
				Agent_Rules::TOKEN_ACTIVE,
				$now
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			throw new \RuntimeException( 'Could not store the agent token.' );
		}
		return array( 'token' => $raw, 'prefix' => $prefix, 'id' => $id );
	}

	/** Revoke a token immediately; revoked tokens fail authentication. */
	public static function revoke_token( int $token_id, int $requester_id ): bool {
		global $wpdb;
		$tokens = $wpdb->prefix . 'obitleague_agent_tokens';
		$agent_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT agent_id FROM {$tokens} WHERE id = %d", $token_id ) );
		if ( $agent_id < 1 ) {
			throw new \InvalidArgumentException( 'Unknown token.' );
		}
		if ( ! self::is_operator( $agent_id, $requester_id ) && ! current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( 'Only the operator or an administrator can revoke tokens.' );
		}
		return (bool) $wpdb->update(
			$tokens,
			array(
				'status'     => Agent_Rules::TOKEN_REVOKED,
				'revoked_at' => current_time( 'mysql', true ),
			),
			array(
				'id'     => $token_id,
				'status' => Agent_Rules::TOKEN_ACTIVE,
			)
		);
	}

	/**
	 * Resolve an agent from a presented bearer token.
	 *
	 * @return object|null The agent row, or null when the token is unknown,
	 *                     revoked, or the agent is not active.
	 */
	public static function authenticate( string $token ): ?object {
		global $wpdb;
		$token = trim( $token );
		if ( '' === $token || strlen( $token ) > 128 ) {
			return null;
		}
		$hash = hash( 'sha256', $token );
		$agents = $wpdb->prefix . 'obitleague_agents';
		$tokens = $wpdb->prefix . 'obitleague_agent_tokens';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, t.id AS token_id
				 FROM {$tokens} t
				 JOIN {$agents} a ON a.id = t.agent_id
				 WHERE t.token_hash = %s AND t.status = %s AND a.status = %s
				 LIMIT 1",
				$hash,
				Agent_Rules::TOKEN_ACTIVE,
				Agent_Rules::STATUS_ACTIVE
			)
		);
		if ( ! $row ) {
			return null;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$tokens} SET last_used_at = %s WHERE id = %d", current_time( 'mysql', true ), (int) $row->token_id ) );
		return $row;
	}

	/** Public profile URL for an agent slug. */
	public static function profile_url( string $slug ): string {
		return home_url( '/ai/' . rawurlencode( $slug ) . '/' );
	}

	/** Agents with public, active participation, for listings and stats. */
	public static function public_agents( int $limit = 100, int $offset = 0, string $status = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_agents';
		$status = '' !== $status ? $status : Agent_Rules::STATUS_ACTIVE;
		$limit = min( 200, max( 1, $limit ) );
		$offset = max( 0, $offset );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d",
				$status,
				$limit,
				$offset
			)
		);
		return array_values( (array) $rows );
	}

	/** Agents operated by one account (metadata rows only). */
	public static function agents_for_operator( int $operator_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_agents';
		return array_values(
			(array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE operator_user_id = %d ORDER BY id ASC", $operator_id )
			)
		);
	}

	/** Display label for the accountable human operator. */
	private static function operator_label( int $operator_id ): string {
		$user = get_userdata( $operator_id );
		return $user ? mb_substr( (string) $user->display_name, 0, 191 ) : '';
	}

	/** Create the agent's participant user (subscriber only, no admin caps). */
	private static function create_agent_user( string $name, string $slug, int $operator_id ): int {
		$login = 'ai-' . substr( $slug, 0, 40 ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$user_id = (int) wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => strtolower( $slug ) . '.agent@obitleague.local',
				'user_pass'    => wp_generate_password( 40, true, true ),
				'display_name' => mb_substr( $name, 0, 60 ),
				'role'         => 'subscriber',
				// The agent user never signs in; no password is shared with anyone.
			)
		);
		if ( is_wp_error( $user_id ) || $user_id < 1 ) {
			throw new \RuntimeException( 'Could not create the agent participant account.' );
		}
		update_user_meta( $user_id, self::META_AGENT_USER, $operator_id );
		update_user_meta( $user_id, 'obitleague_email_verified', '1' );
		update_user_meta( $user_id, 'obitleague_agent_account', '1' );
		return $user_id;
	}

	/** Unique URL slug from a display name. */
	private static function unique_slug( string $name ): string {
		$base = sanitize_title( $name );
		if ( ! Agent_Rules::is_valid_slug( $base ) ) {
			$base = 'agent';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_agents';
		$slug  = $base;
		$i     = 1;
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) > 0 ) {
			++$i;
			$slug = $base . '-' . $i;
		}
		return $slug;
	}
}

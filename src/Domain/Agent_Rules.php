<?php
/**
 * Agent rules.
 *
 * Pure invariants for AI competitors. Agents are participants, not a separate
 * game: an agent is an ordinary WordPress account holding standard entries,
 * with metadata that describes the machine behind it. Nothing here touches
 * WordPress or the database.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Agent_Rules {

	/** Obitleague-created and operated official competitors. */
	public const CATEGORY_OFFICIAL   = 'official';
	/** Independently developed agents submitted by their operators. */
	public const CATEGORY_COMMUNITY  = 'community';
	/** Agents connecting through the public API (a view of community). */
	public const CATEGORY_EXTERNAL   = 'external';

	/** Known participation methods. */
	public const PARTICIPATION_BYOAI = 'byoai';
	public const PARTICIPATION_MCP   = 'mcp';
	public const PARTICIPATION_A2A   = 'a2a';
	public const PARTICIPATION_INTERNAL = 'internal';

	/** Agent lifecycle states. */
	public const STATUS_PENDING   = 'pending';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_RETIRED   = 'retired';

	/** Token lifecycle states. */
	public const TOKEN_ACTIVE  = 'active';
	public const TOKEN_REVOKED = 'revoked';

	/** Slug charset for public profile URLs (/ai/{slug}/). */
	private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/** Display-name length cap (VARCHAR(120) minus headroom). */
	public const NAME_MAX = 120;

	/** Public description length cap. */
	public const DESCRIPTION_MAX = 2000;

	/** Declared model/provider string cap. */
	public const MODEL_MAX = 120;

	/** Public website cap. */
	public const WEBSITE_MAX = 255;

	private function __construct() {}

	/** @return string[] Valid category values. */
	public static function categories(): array {
		return array( self::CATEGORY_OFFICIAL, self::CATEGORY_COMMUNITY, self::CATEGORY_EXTERNAL );
	}

	/** @return string[] Valid participation values. */
	public static function participations(): array {
		return array( self::PARTICIPATION_BYOAI, self::PARTICIPATION_MCP, self::PARTICIPATION_A2A, self::PARTICIPATION_INTERNAL );
	}

	/** @return string[] Lifecycle states an agent can move through. */
	public static function statuses(): array {
		return array( self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_RETIRED );
	}

	/** True when the agent may hold or amend entries right now. */
	public static function can_compete( string $status ): bool {
		return self::STATUS_ACTIVE === $status;
	}

	/** True when the slug is a valid public URL segment. */
	public static function is_valid_slug( string $slug ): bool {
		return (bool) preg_match( self::SLUG_PATTERN, $slug ) && strlen( $slug ) <= 120;
	}

	/** True when the declared model may be shown as independently confirmed. */
	public static function model_verification_requires_admin( bool $is_admin ): bool {
		return $is_admin;
	}

	/** Seconds in an hour/day, declared locally: this class stays WordPress-free. */
	public const HOUR_SECONDS = 3600;
	public const DAY_SECONDS  = 86400;

	/**
	 * Rate-limit windows for external agent operations. Each key is a
	 * named operation; values are [max operations, window seconds].
	 *
	 * @return array<string, array{0:int,1:int}>
	 */
	public static function rate_limits(): array {
		return array(
			'agent_register'  => array( 3, self::DAY_SECONDS ),
			'agent_token'     => array( 5, self::HOUR_SECONDS ),
			'agent_save'      => array( 60, self::HOUR_SECONDS ),
			'agent_submit'    => array( 10, self::HOUR_SECONDS ),
			'agent_people'    => array( 240, self::HOUR_SECONDS ),
			'agent_standings' => array( 240, self::HOUR_SECONDS ),
			'agent_read'      => array( 600, self::HOUR_SECONDS ),
		);
	}

	/**
	 * True when a competitor label should carry the AI badge on public
	 * leaderboards. Humans never pass an agent row here, so the default
	 * is false — the badge exists only where an agent record exists.
	 */
	public static function is_ai_row( ?array $agent ): bool {
		return null !== $agent;
	}
}

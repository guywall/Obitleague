<?php
/**
 * League rules.
 *
 * Pure league invariants: token-based invites, membership boundaries and
 * spectator status after lock. Nothing here touches WordPress or the
 * database; the services layer enforces these rules inside transactions.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class League_Rules {

	/** Lifetime of an invite token before it must be regenerated. */
	public const INVITE_TTL_DAYS = 14;

	private function __construct() {}

	/**
	 * Hash an invite token for storage. Tokens are stored only as hashes, so
	 * a database leak cannot be used to join a private league.
	 */
	public static function hash_invite_token( string $token ): string {
		return hash( 'sha256', 'obitleague-invite:' . $token );
	}

	/** A stored hash matches a presented token. */
	public static function invite_token_matches( string $token, string $stored_hash ): bool {
		return hash_equals( $stored_hash, self::hash_invite_token( $token ) );
	}

	/** True when the token is still within its usable lifetime. */
	public static function invite_token_live( \DateTimeImmutable $expires_at, \DateTimeImmutable $now ): bool {
		return $now <= $expires_at;
	}

	/** True when a user may join: not already a member and the league is open. */
	public static function may_join( bool $already_member, bool $league_open ): bool {
		return $league_open && ! $already_member;
	}

	/**
	 * Membership status after a join attempt relative to the entry deadline.
	 * A join whose commit lands after the close creates a spectator, never a
	 * competitor.
	 *
	 * @return string 'member' | 'spectator'
	 */
	public static function status_after_join( bool $deadline_open ): string {
		return $deadline_open ? 'member' : 'spectator';
	}

	/**
	 * True when a member may hold a submitted entry in this league: they must
	 * have been a member (not spectator) at lock time.
	 */
	public static function can_submit_after_join( bool $is_spectator, bool $committed_before_deadline ): bool {
		if ( $is_spectator ) {
			return false;
		}
		return $committed_before_deadline;
	}

	/** Owner tools: rename, revoke invites, remove member with reason. */
	public static function owner_may_manage( bool $is_owner ): bool {
		return $is_owner;
	}

	/** Ownership transfer requires the recipient's acceptance. */
	public static function ownership_transfer_requires_acceptance(): bool {
		return true;
	}
}

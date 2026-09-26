<?php
/**
 * League service.
 *
 * Durable league operations built on the pure rules. Invites are hashed,
 * joins are idempotent, and membership status reflects the deadline at the
 * moment of the write.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\League_Rules;

final class League_Service {

	/** League states. */
	public const STATE_OPEN   = 'open';
	public const STATE_CLOSED = 'closed';

	private function __construct() {}

	/** Current season open for entries: next calendar year in London time. */
	public static function current_season(): int {
		return (int) ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/London' ) ) )->format( 'Y' ) + 1;
	}

	/** Create an invite-only league. Returns the league id. */
	public static function create_league( int $owner_id, string $name, int $season = 0 ): int {
		global $wpdb;

		$name = trim( $name );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			throw new \InvalidArgumentException( 'League name must be 1-120 characters.' );
		}

		$season = $season > 0 ? $season : self::current_season();
		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$now_gmt = $now->format( 'Y-m-d H:i:s' );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_leagues (name, season, owner_user_id, state, created_at)
					 VALUES (%s, %d, %d, %s, %s)',
					$name,
					$season,
					$owner_id,
					self::STATE_OPEN,
					$now_gmt
				)
			);
			$league_id = (int) $wpdb->insert_id;

			// Owner joins their own league as the first member. A commit after
			// the deadline makes the founder a spectator like anyone else.
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_league_members (league_id, user_id, status, joined_at)
					 VALUES (%d, %d, %s, %s)',
					$league_id,
					$owner_id,
					Deadline_Policy::is_entry_open( $season, $now ) ? 'member' : 'spectator',
					$now_gmt
				)
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return $league_id;
	}

	/**
	 * Generate an invite token. Returns the plaintext token (shown once to
	 * the owner); only its hash is stored.
	 *
	 * @return array{token:string, expires_at_gmt:string}
	 */
	public static function generate_invite( int $league_id, int $requester_id ): array {
		global $wpdb;

		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can issue invites.' );
		}

		$token       = wp_generate_password( 24, false, false );
		$expires_gmt = gmdate( 'Y-m-d H:i:s', time() + League_Rules::INVITE_TTL_DAYS * DAY_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'obitleague_leagues
				 SET invite_hash = %s, invite_expires_at = %s, invite_revoked = 0
				 WHERE id = %d AND owner_user_id = %d',
				League_Rules::hash_invite_token( $token ),
				$expires_gmt,
				$league_id,
				$requester_id
			)
		);

		return array(
			'token'          => $token,
			'expires_at_gmt' => $expires_gmt,
		);
	}

	/** Revoke the league's invite token; existing links stop working. */
	public static function revoke_invite( int $league_id, int $requester_id ): void {
		global $wpdb;

		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can revoke invites.' );
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'obitleague_leagues SET invite_revoked = 1 WHERE id = %d AND owner_user_id = %d',
				$league_id,
				$requester_id
			)
		);
	}

	/**
	 * Join with an invite token. Idempotent: joining twice changes nothing.
	 * Spectators (joined after lock) can never submit.
	 *
	 * @return array{status:string, already_member:bool}
	 */
	public static function join_with_token( int $user_id, string $token ): array {
		global $wpdb;

		$leagues = $wpdb->prefix . 'obitleague_leagues';
		$members = $wpdb->prefix . 'obitleague_league_members';

		// Look the league up by the presented token's hash: correct with many
		// leagues, and the hash comparison is the match itself.
		$hash   = League_Rules::hash_invite_token( $token );
		$league = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, season, state, invite_hash, invite_expires_at FROM {$leagues}
				 WHERE invite_revoked = 0 AND invite_hash = %s LIMIT 1",
				$hash
			)
		);
		if ( ! $league ) {
			throw new \InvalidArgumentException( 'This invite link is not valid.' );
		}

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		if ( ! League_Rules::invite_token_live( new \DateTimeImmutable( (string) $league->invite_expires_at, new \DateTimeZone( 'UTC' ) ), $now ) ) {
			throw new \InvalidArgumentException( 'This invite has expired — ask the owner for a new one.' );
		}
		if ( self::STATE_CLOSED === (string) $league->state ) {
			throw new \InvalidArgumentException( 'This league is closed to new members.' );
		}

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$members} WHERE league_id = %d AND user_id = %d", (int) $league->id, $user_id ) );
		if ( $existing ) {
			return array( 'status' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$members} WHERE id = %d", $existing ) ), 'already_member' => true );
		}

		$deadline_open = Deadline_Policy::is_entry_open( (int) $league->season, $now );
		$status        = League_Rules::status_after_join( $deadline_open );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$members} (league_id, user_id, status, joined_at) VALUES (%d, %d, %s, %s)",
				(int) $league->id,
				$user_id,
				$status,
				$now->format( 'Y-m-d H:i:s' )
			)
		);

		return array( 'status' => $status, 'already_member' => false );
	}

	/** True when the user is a league member (member or spectator). */
	public static function is_member( int $league_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d AND user_id = %d',
				$league_id,
				$user_id
			)
		);
	}

	/** True when the user owns the league. */
	public static function is_owner( int $league_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d AND owner_user_id = %d',
				$league_id,
				$user_id
			)
		);
	}

	/** The member row for a user in a league, or null. */
	public static function member_row( int $league_id, int $user_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d AND user_id = %d',
				$league_id,
				$user_id
			)
		);
		return $row ?: null;
	}

	/** Remove a member with a recorded reason; their entry history is kept. */
	public static function remove_member( int $league_id, int $member_user_id, int $requester_id, string $reason ): void {
		global $wpdb;

		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can remove members.' );
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( 'A recorded reason is required to remove a member.' );
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d AND user_id = %d',
				$league_id,
				$member_user_id
			)
		);

		// Historical entries remain for the archive; the removal itself is
		// recorded in the audit table by the caller (REST layer) with the reason.
		do_action( 'obitleague_member_removed', $league_id, $member_user_id, $requester_id, $reason );
	}

	/** Rename the league (owner only). */
	public static function rename( int $league_id, int $requester_id, string $name ): void {
		global $wpdb;

		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can rename the league.' );
		}
		$name = trim( $name );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			throw new \InvalidArgumentException( 'League name must be 1-120 characters.' );
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'obitleague_leagues SET name = %s WHERE id = %d',
				$name,
				$league_id
			)
		);
	}

	/** Close the league to new members; the archive stays for existing members. */
	public static function close( int $league_id, int $requester_id ): void {
		global $wpdb;

		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can close the league.' );
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'obitleague_leagues SET state = %s WHERE id = %d',
				self::STATE_CLOSED,
				$league_id
			)
		);
	}
}

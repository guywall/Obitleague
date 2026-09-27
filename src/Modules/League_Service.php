<?php
/** Side-league service and main-league compatibility methods. */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\League_Rules;

final class League_Service {

	public const STATE_OPEN = 'open';
	public const STATE_CLOSED = 'closed';

	private function __construct() {}

	public static function ensure_main_league( int $season ): int {
		return Main_League_Service::ensure_league( $season );
	}

	public static function ensure_main_entry( int $user_id, int $season ): int {
		$result = Main_League_Service::ensure_user_entry( $user_id, $season );
		return (int) $result['entry_id'];
	}

	public static function on_user_register( int $user_id ): void {
		Main_League_Service::on_user_register( $user_id );
	}

	public static function provision_main_season_for_users( int $season, int $batch_size = 500 ): int {
		return Main_League_Service::provision_existing_users( $season, $batch_size );
	}

	/** Current season is the next calendar year in London time. */
	public static function current_season(): int {
		return (int) ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/London' ) ) )->format( 'Y' ) + 1;
	}

	/** Create an invite-only side league. */
	public static function create_league( int $owner_id, string $name, int $season = 0 ): int {
		global $wpdb;
		$name = trim( $name );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			throw new \InvalidArgumentException( 'League name must be 1-120 characters.' );
		}
		$season = $season > 0 ? $season : self::current_season();
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$now_gmt = $now->format( 'Y-m-d H:i:s' );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_leagues (name, season, owner_user_id, state, created_at) VALUES (%s, %d, %d, %s, %s)',
					$name, $season, $owner_id, self::STATE_OPEN, $now_gmt
				)
			);
			$league_id = (int) $wpdb->insert_id;
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->prefix . 'obitleague_league_members (league_id, user_id, status, joined_at) VALUES (%d, %d, %s, %s)',
					$league_id, $owner_id, Deadline_Policy::is_entry_open( $season, $now ) ? 'member' : 'spectator', $now_gmt
				)
			);
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
		return $league_id;
	}

	/** Generate an invite token; plaintext is returned only once. */
	public static function generate_invite( int $league_id, int $requester_id ): array {
		global $wpdb;
		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can issue invites.' );
		}
		$token = wp_generate_password( 24, false, false );
		$expires = gmdate( 'Y-m-d H:i:s', time() + League_Rules::INVITE_TTL_DAYS * DAY_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'obitleague_leagues SET invite_hash = %s, invite_expires_at = %s, invite_revoked = 0 WHERE id = %d AND owner_user_id = %d',
				League_Rules::hash_invite_token( $token ), $expires, $league_id, $requester_id
			)
		);
		return array( 'token' => $token, 'expires_at_gmt' => $expires );
	}

	public static function revoke_invite( int $league_id, int $requester_id ): void {
		global $wpdb;
		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can revoke invites.' );
		}
		$wpdb->update( $wpdb->prefix . 'obitleague_leagues', array( 'invite_revoked' => 1 ), array( 'id' => $league_id, 'owner_user_id' => $requester_id ), array( '%d' ), array( '%d', '%d' ) );
	}

	/** Join a side league by a current invite token. */
	public static function join_with_token( int $user_id, string $token ): array {
		global $wpdb;
		$leagues = $wpdb->prefix . 'obitleague_leagues';
		$members = $wpdb->prefix . 'obitleague_league_members';
		$league = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, season, state, invite_expires_at FROM {$leagues} WHERE invite_revoked = 0 AND invite_hash = %s LIMIT 1", League_Rules::hash_invite_token( $token ) )
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
		$league_id = (int) $league->id;
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$members} WHERE league_id = %d AND user_id = %d", $league_id, $user_id ) );
		if ( $existing ) {
			return array(
				'status' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$members} WHERE id = %d", $existing ) ),
				'already_member' => true,
			);
		}
		$status = League_Rules::status_after_join( Deadline_Policy::is_entry_open( (int) $league->season, $now ) );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$members} (league_id, user_id, status, joined_at) VALUES (%d, %d, %s, %s)",
				$league_id, $user_id, $status, $now->format( 'Y-m-d H:i:s' )
			)
		);
		Entry_Service::get_or_create_entry( $league_id, (int) $league->season, $user_id );
		return array( 'status' => $status, 'already_member' => false );
	}

	public static function is_member( int $league_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d AND user_id = %d', $league_id, $user_id ) );
	}

	public static function is_owner( int $league_id, int $user_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d AND owner_user_id = %d', $league_id, $user_id ) );
	}

	public static function member_row( int $league_id, int $user_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d AND user_id = %d', $league_id, $user_id ) );
		return $row ?: null;
	}

	/** Remove a member without deleting their historical teams. */
	public static function remove_member( int $league_id, int $member_user_id, int $requester_id, string $reason ): void {
		global $wpdb;
		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can remove members.' );
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( 'A recorded reason is required to remove a member.' );
		}
		$wpdb->delete( $wpdb->prefix . 'obitleague_league_members', array( 'league_id' => $league_id, 'user_id' => $member_user_id ), array( '%d', '%d' ) );
		do_action( 'obitleague_member_removed', $league_id, $member_user_id, $requester_id, $reason );
	}

	public static function rename( int $league_id, int $requester_id, string $name ): void {
		global $wpdb;
		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can rename the league.' );
		}
		$name = trim( $name );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			throw new \InvalidArgumentException( 'League name must be 1-120 characters.' );
		}
		$wpdb->update( $wpdb->prefix . 'obitleague_leagues', array( 'name' => $name ), array( 'id' => $league_id ), array( '%s' ), array( '%d' ) );
	}

	public static function close( int $league_id, int $requester_id ): void {
		global $wpdb;
		if ( ! self::is_owner( $league_id, $requester_id ) ) {
			throw new \InvalidArgumentException( 'Only the league owner can close the league.' );
		}
		$wpdb->update( $wpdb->prefix . 'obitleague_leagues', array( 'state' => self::STATE_CLOSED ), array( 'id' => $league_id ), array( '%s' ), array( '%d' ) );
	}
}

<?php
/**
 * Public scope: which leagues and accounts may appear on public surfaces.
 *
 * The site was built on a live database, so it carries synthetic leagues and
 * accounts created while the game was being developed. They are real rows and
 * are kept — hiding, never deleting, is the rule — but they must not read as
 * real players on the front page, the standings, the team directory or the
 * sitemap.
 *
 * This module owns the single definition of "not public":
 *
 *   - a league is hidden when `obitleague_leagues.is_hidden = 1`;
 *   - an account is a test account when its `obitleague_test_account` user
 *     meta is set.
 *
 * Every public read path builds its exclusion from here, so there is one rule
 * rather than a filter re-guessed per module. Administrator screens
 * deliberately do NOT use it: an administrator must be able to see — and
 * un-hide — the hidden rows.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Public_Scope {

	/** League column that marks a league as not-public. */
	public const LEAGUE_COLUMN = 'is_hidden';

	/** User meta that marks an account as a synthetic test account. */
	public const META_TEST_ACCOUNT = 'obitleague_test_account';

	/**
	 * Whole words that mark a league name as synthetic. Matched against
	 * lower-cased tokens, so "Guy's Test League" matches and "Contested"
	 * does not.
	 */
	private const TEST_LEAGUE_HINTS = array( 'test', 'tests', 'testing', 'testings', 'qa', 'demo', 'dummy', 'sandbox', 'scratch' );

	/** Whole words that mark an account as synthetic (login, display name or email local part). */
	private const TEST_ACCOUNT_HINTS = array( 'qa', 'test', 'tests', 'testing', 'demo', 'dummy', 'sandbox', 'robot', 'bot' );

	/** Request-local cache of hidden league ids. */
	private static ?array $hidden_leagues = null;

	/** Request-local cache of test account ids. */
	private static ?array $test_users = null;

	private function __construct() {}

	public static function boot(): void {
		add_filter( 'wp_sitemaps_users_query_args', array( self::class, 'sitemap_users_query_args' ) );
	}

	/**
	 * Keep test accounts out of the WordPress user sitemap. Account archives
	 * are already noindex; the sitemap is a second, machine-readable place a
	 * synthetic login would otherwise be published.
	 *
	 * @param array<string,mixed> $args WP_User_Query arguments.
	 * @return array<string,mixed>
	 */
	public static function sitemap_users_query_args( array $args ): array {
		$ids = self::test_user_ids();
		if ( $ids ) {
			$args['exclude'] = array_values( array_unique( array_merge( (array) ( $args['exclude'] ?? array() ), $ids ) ) );
		}
		return $args;
	}

	/* ---------- the rule, as SQL ---------- */

	/**
	 * Pure SQL fragment builder: exclude any row whose account is a test
	 * account. Kept WordPress-free so the contract is unit-tested without a
	 * database.
	 *
	 * @param string $user_column Column holding the account id (e.g. "r.user_id").
	 * @param string $meta_table  usermeta table name.
	 * @param string $meta_key    Meta key that marks a test account.
	 */
	public static function exclusion_clause( string $user_column, string $meta_table, string $meta_key ): string {
		return sprintf(
			" AND NOT EXISTS (SELECT 1 FROM %s ob_scope_um WHERE ob_scope_um.user_id = %s AND ob_scope_um.meta_key = '%s' AND ob_scope_um.meta_value = '1')",
			$meta_table,
			$user_column,
			$meta_key
		);
	}

	/** Exclude test accounts from a query over $user_column. */
	public static function test_user_exclusion( string $user_column ): string {
		global $wpdb;
		return self::exclusion_clause( $user_column, $wpdb->usermeta, self::META_TEST_ACCOUNT );
	}

	/** Exclude hidden leagues from a query over the leagues table alias $alias. */
	public static function hidden_league_exclusion( string $alias = 'l' ): string {
		return ' AND ' . $alias . '.' . self::LEAGUE_COLUMN . ' = 0';
	}

	/* ---------- reads ---------- */

	/** Every hidden league id, cached for the request. */
	public static function hidden_league_ids(): array {
		if ( null !== self::$hidden_leagues ) {
			return self::$hidden_leagues;
		}
		global $wpdb;
		$column = self::LEAGUE_COLUMN;
		$ids    = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}obitleague_leagues WHERE {$column} = 1" );
		return self::$hidden_leagues = array_map( 'intval', (array) $ids );
	}

	/** Is this league hidden from the public? */
	public static function is_hidden_league( int $league_id ): bool {
		return $league_id > 0 && in_array( $league_id, self::hidden_league_ids(), true );
	}

	/** Every test account id, cached for the request. */
	public static function test_user_ids(): array {
		if ( null !== self::$test_users ) {
			return self::$test_users;
		}
		if ( ! function_exists( 'get_users' ) ) {
			return self::$test_users = array();
		}
		$ids = get_users(
			array(
				'meta_key'   => self::META_TEST_ACCOUNT,
				'meta_value' => '1',
				'fields'     => 'ID',
				'number'     => 5000,
			)
		);
		return self::$test_users = array_map( 'intval', (array) $ids );
	}

	/** Is this account flagged as a test account? */
	public static function is_test_user( int $user_id ): bool {
		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META_TEST_ACCOUNT, true );
	}

	/** Is this league id public? Convenience for view code. */
	public static function is_public_league( int $league_id ): bool {
		return $league_id > 0 && ! self::is_hidden_league( $league_id );
	}

	/**
	 * Drop cached reads (call after any flag changes).
	 *
	 * The pick distribution is a transient built from the same rows, so it is
	 * dropped too: a hidden account must not survive in a cached popularity
	 * count after it has been flagged.
	 */
	public static function flush(): void {
		self::$hidden_leagues = null;
		self::$test_users     = null;
		if ( class_exists( Pick_Stats::class ) ) {
			Pick_Stats::flush();
		}
	}

	/* ---------- suggestions (used by the flagging tool) ---------- */

	/**
	 * Does this league name look synthetic? A suggestion for the review tool,
	 * never an automatic decision on its own.
	 */
	public static function looks_like_test_league( string $name ): bool {
		foreach ( self::tokens( $name ) as $token ) {
			if ( in_array( $token, self::TEST_LEAGUE_HINTS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does this account look synthetic? A suggestion for the review tool,
	 * never an automatic decision on its own.
	 */
	public static function looks_like_test_account( string $login, string $display_name, string $email = '' ): bool {
		$local = (string) strstr( $email, '@', true );
		$parts = array_merge( self::tokens( $login ), self::tokens( $display_name ), self::tokens( $local ) );
		foreach ( $parts as $token ) {
			if ( in_array( $token, self::TEST_ACCOUNT_HINTS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	private static function tokens( string $value ): array {
		$parts = preg_split( '/[^a-z0-9]+/', strtolower( $value ) );
		return array_values( array_filter( (array) $parts, static fn ( string $part ): bool => '' !== $part ) );
	}

	/* ---------- writes (always audited) ---------- */

	/** Hide or unhide one league. Rows are never deleted. */
	public static function set_league_hidden( int $league_id, bool $hidden, string $reason = '' ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_leagues';
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $league_id ) );
		if ( ! $exists ) {
			return false;
		}
		$updated = $wpdb->update(
			$table,
			array( self::LEAGUE_COLUMN => $hidden ? 1 : 0 ),
			array( 'id' => $league_id ),
			array( '%d' ),
			array( '%d' )
		);
		self::flush();
		self::audit( 'league', $league_id, $hidden ? 'hide_league' : 'unhide_league', array( 'reason' => $reason ) );
		return false !== $updated;
	}

	/** Flag or unflag one account as a test account. Rows are never deleted. */
	public static function set_user_test( int $user_id, bool $test, string $reason = '' ): bool {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return false;
		}
		if ( $test ) {
			update_user_meta( $user_id, self::META_TEST_ACCOUNT, '1' );
		} else {
			delete_user_meta( $user_id, self::META_TEST_ACCOUNT );
		}
		self::flush();
		self::audit( 'user', $user_id, $test ? 'flag_test_account' : 'unflag_test_account', array( 'reason' => $reason ) );
		return true;
	}

	/** One immutable audit row per flag change, matching the game admin log. */
	private static function audit( string $object_type, int $object_id, string $action, array $details ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'obitleague_admin_audit',
			array(
				'actor_user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'action'        => $action,
				'details'       => wp_json_encode( $details ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}
}

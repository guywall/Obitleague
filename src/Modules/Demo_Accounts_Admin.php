<?php
/**
 * Admin-only identification and cleanup tools for synthetic demo accounts.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Demo_Accounts_Admin {

	private const META_KEY = 'obitleague_demo_account';

	private function __construct() {}

	public static function boot(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_users_columns', array( self::class, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( self::class, 'render_column' ), 10, 3 );
		add_filter( 'views_users', array( self::class, 'add_view' ) );
		add_action( 'pre_get_users', array( self::class, 'filter_user_list' ) );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
		add_action( 'admin_post_obitleague_delete_demo_users', array( self::class, 'delete_all' ) );
	}

	public static function add_column( array $columns ): array {
		$columns['obitleague_demo_account'] = 'Obitleague demo';
		return $columns;
	}

	public static function render_column( string $output, string $column, int $user_id ): string {
		if ( 'obitleague_demo_account' !== $column || ! get_user_meta( $user_id, self::META_KEY, true ) ) {
			return $output;
		}
		return 'Synthetic';
	}

	public static function add_view( array $views ): array {
		if ( ! current_user_can( 'list_users' ) ) {
			return $views;
		}
		$url = add_query_arg( 'obitleague_demo_accounts', '1', admin_url( 'users.php' ) );
		$views['obitleague_demo_accounts'] = '<a href="' . esc_url( $url ) . '">Obitleague demo accounts</a>';
		return $views;
	}

	public static function filter_user_list( \WP_User_Query $query ): void {
		if ( is_admin() && isset( $_GET['obitleague_demo_accounts'] ) ) {
			$query->set( 'meta_key', self::META_KEY );
			$query->set( 'meta_value', '1' );
		}
	}

	public static function admin_notice(): void {
		if ( isset( $_GET['obitleague_demo_deleted'] ) && current_user_can( 'delete_users' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Deleted %d marked demo account(s).', absint( $_GET['obitleague_demo_deleted'] ) ) ) . '</p></div>';
		}
		if ( ! current_user_can( 'delete_users' ) || ! isset( $_GET['obitleague_demo_accounts'] ) ) {
			return;
		}

		$count = self::count_accounts();
		echo '<div class="notice notice-info"><p>';
		echo esc_html( sprintf( '%d synthetic Obitleague account(s) are marked for admin management.', $count ) );
		if ( $count > 0 ) {
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-left:8px">';
			echo '<input type="hidden" name="action" value="obitleague_delete_demo_users">';
			wp_nonce_field( 'obitleague_delete_demo_users' );
			echo '<button class="button button-secondary" type="submit" onclick="return confirm(\'Permanently delete all marked Obitleague demo accounts? This cannot be undone.\')">Delete all marked demo accounts</button></form>';
		}
		echo '</p></div>';
	}

	public static function delete_all(): void {
		if ( ! current_user_can( 'delete_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to delete users.', 'obitleague' ), 403 );
		}
		check_admin_referer( 'obitleague_delete_demo_users' );

		$deleted = 0;
		do {
			$users = get_users(
				array(
					'fields'     => 'ID',
					'number'     => 200,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_key'   => self::META_KEY,
					'meta_value' => '1',
				)
			);
			foreach ( $users as $user_id ) {
				if ( get_user_meta( (int) $user_id, self::META_KEY, true ) && wp_delete_user( (int) $user_id, get_current_user_id() ) ) {
					++$deleted;
				}
			}
		} while ( count( $users ) === 200 );

		wp_safe_redirect( add_query_arg( 'obitleague_demo_deleted', $deleted, admin_url( 'users.php' ) ) );
		exit;
	}

	public static function count_accounts(): int {
		$query = new \WP_User_Query(
			array(
				'fields'     => 'ID',
				'number'     => 1,
				'meta_key'   => self::META_KEY,
				'meta_value' => '1',
			)
		);
		return (int) $query->get_total();
	}
}

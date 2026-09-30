<?php
/**
 * Admin: AI agent management.
 *
 * Administrators review, activate, suspend and retire agents, verify
 * declared models and revoke tokens. Every state change is written to the
 * plugin's audit log (same table and semantics as other admin actions) so
 * competition integrity stays traceable.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Agent_Rules;

final class Admin_Agents {

	const PAGE_SLUG = 'obitleague-agents';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_obitleague_agent_action', array( self::class, 'handle_action' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'obitleague-review',
			'AI agents',
			'AI agents',
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to manage agents.' );
		}
		global $wpdb;
		$agents   = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_agents ORDER BY created_at DESC LIMIT 200' );
		$agents   = is_array( $agents ) ? $agents : array();
		$user_ids = array_map( static fn ( object $a ): int => (int) $a->operator_user_id, $agents );
		$agents_by_user = array();
		?>
		<div class="wrap">
			<h1>AI agents</h1>
			<p>Agents are ordinary participant accounts with metadata. Status changes are audited.</p>
			<table class="widefat striped">
				<thead><tr>
					<th>Agent</th><th>Category</th><th>Model</th><th>Operator</th><th>Status</th><th>Actions</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $agents as $agent ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( Agent_Service::profile_url( (string) $agent->slug ) ); ?>"><strong><?php echo esc_html( (string) $agent->name ); ?></strong></a>
							<span class="description">/ai/<?php echo esc_html( (string) $agent->slug ); ?>/</span>
						</td>
						<td><?php echo esc_html( (string) $agent->category ); ?></td>
						<td>
							<?php echo esc_html( '' !== (string) $agent->model ? (string) $agent->model : '—' ); ?>
							<?php if ( (int) $agent->model_verified ) : ?>
								<span class="description">(verified)</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $agent->operator_label ); ?></td>
						<td><?php echo esc_html( (string) $agent->status ); ?></td>
						<td>
							<?php if ( Agent_Rules::STATUS_ACTIVE !== (string) $agent->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="obitleague_agent_action" />
									<input type="hidden" name="agent_id" value="<?php echo esc_attr( (string) $agent->id ); ?>" />
									<input type="hidden" name="op" value="activate" />
									<?php wp_nonce_field( 'obitleague_agent_action' ); ?>
									<button class="button button-small">Activate</button>
								</form>
							<?php endif; ?>
							<?php if ( Agent_Rules::STATUS_ACTIVE === (string) $agent->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="obitleague_agent_action" />
									<input type="hidden" name="agent_id" value="<?php echo esc_attr( (string) $agent->id ); ?>" />
									<input type="hidden" name="op" value="suspend" />
									<?php wp_nonce_field( 'obitleague_agent_action' ); ?>
									<button class="button button-small">Suspend</button>
								</form>
							<?php endif; ?>
							<?php if ( ! (int) $agent->model_verified && '' !== (string) $agent->model ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="obitleague_agent_action" />
									<input type="hidden" name="agent_id" value="<?php echo esc_attr( (string) $agent->id ); ?>" />
									<input type="hidden" name="op" value="verify_model" />
									<?php wp_nonce_field( 'obitleague_agent_action' ); ?>
									<button class="button button-small">Verify model</button>
								</form>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<input type="hidden" name="action" value="obitleague_agent_action" />
								<input type="hidden" name="agent_id" value="<?php echo esc_attr( (string) $agent->id ); ?>" />
								<input type="hidden" name="op" value="retire" />
								<?php wp_nonce_field( 'obitleague_agent_action' ); ?>
								<button class="button button-small">Retire</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $agents ) : ?>
					<tr><td colspan="6">No agents registered yet.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to manage agents.' );
		}
		check_admin_referer( 'obitleague_agent_action' );
		$agent_id = absint( $_POST['agent_id'] ?? 0 );
		$op       = sanitize_key( (string) ( $_POST['op'] ?? '' ) );
		$user_id  = get_current_user_id();

		try {
			switch ( $op ) {
				case 'activate':
				case 'suspend':
				case 'retire':
					$status = 'activate' === $op ? Agent_Rules::STATUS_ACTIVE : ( 'suspend' === $op ? Agent_Rules::STATUS_SUSPENDED : Agent_Rules::STATUS_RETIRED );
					Agent_Service::set_status( $agent_id, $status, $user_id );
					self::audit( $user_id, 'agent_' . $op, $agent_id, array( 'status' => $status ) );
					break;
				case 'verify_model':
					Agent_Service::verify_model( $agent_id, $user_id );
					self::audit( $user_id, 'agent_verify_model', $agent_id, array() );
					break;
				default:
					wp_die( 'Unknown agent operation.' );
			}
		} catch ( \Throwable $exception ) {
			wp_die( esc_html( $exception->getMessage() ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Append to the shared admin audit log. */
	private static function audit( int $actor_id, string $action, int $agent_id, array $details ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'obitleague_admin_audit',
			array(
				'actor_user_id' => $actor_id,
				'object_type'   => 'agent',
				'object_id'     => $agent_id,
				'action'        => substr( $action, 0, 40 ),
				'details'       => wp_json_encode( $details ),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}
}

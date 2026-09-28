<?php
/**
 * Administrative controls for the private living-person Discovery queue.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Admin_Discovery {

	private const CAP = 'obitleague_review';
	private const BATCH_CAP = 'manage_options';
	private const PAGE = 'obitleague-discovery';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_obitleague_discovery_run', array( self::class, 'run_batch' ) );
		add_action( 'admin_post_obitleague_discovery_approve', array( self::class, 'approve' ) );
		add_action( 'admin_post_obitleague_discovery_reject', array( self::class, 'reject' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'obitleague-review', __( 'Living-person Discovery', 'obitleague' ), __( 'Discovery', 'obitleague' ), self::CAP, self::PAGE, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to review Discovery candidates.', 'obitleague' ) );
		}
		$status  = Discovery_Service::status();
		$pending = self::pending_candidates();
		echo '<div class="wrap"><h1>Obitleague — Discovery</h1>';
		if ( isset( $_GET['notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) ) . '</p></div>';
		}
		echo '<p>Discovery reads a small Wikidata cohort and queues at most five private drafts per UTC day. It is manual-only; no visitor cron or automatic publishing is configured.</p>';
		echo '<p><strong>Liveness warning:</strong> Wikidata and Wikipedia filters are screening signals, not proof that a person is alive. Approval requires the editor to verify a recent source and confirm the person is living.</p>';
		echo '<p>Next birth-month window: <code>' . esc_html( $status['cursor'] ) . '</code>';
		if ( $status['daily_limited'] ) {
			echo ' · Daily batch limit reached.';
		}
		if ( $status['pause_until'] > time() ) {
			echo ' · Wikimedia pause until ' . esc_html( gmdate( 'Y-m-d H:i:s', $status['pause_until'] ) ) . ' UTC.';
		}
		echo '</p>';
		$last = is_array( $status['last_run'] ) ? $status['last_run'] : array();
		if ( $last ) {
			echo '<p>Last run: ' . esc_html( (string) ( $last['completed_at'] ?? '—' ) ) . ' UTC · ' . esc_html( (string) ( $last['status'] ?? '—' ) ) . ' · window ' . esc_html( (string) ( $last['window'] ?? '—' ) ) . ' · queued ' . (int) ( $last['queued'] ?? 0 ) . ' · skipped ' . (int) ( $last['skipped'] ?? 0 ) . '</p>';
			if ( ! empty( $last['message'] ) ) {
				echo '<p class="description">' . esc_html( (string) $last['message'] ) . '</p>';
			}
		}
		if ( current_user_can( self::BATCH_CAP ) && ! $status['daily_limited'] && $status['pause_until'] <= time() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'obitleague_discovery_run' );
			echo '<input type="hidden" name="action" value="obitleague_discovery_run" />';
			echo '<label for="ob-discovery-limit">Maximum new candidates </label><select name="limit" id="ob-discovery-limit"><option value="1">1 (recommended)</option><option value="3">3</option><option value="5">5 (daily maximum)</option></select> ';
			submit_button( 'Run one Discovery batch', 'primary', 'submit', false );
			echo '</form>';
		} elseif ( ! current_user_can( self::BATCH_CAP ) ) {
			echo '<p class="description">Only administrators can start a new external-data batch; editors can review existing candidates.</p>';
		} else {
			echo '<p><button type="button" class="button" disabled>Batch unavailable</button></p>';
		}

		echo '<h2>Pending candidates (' . count( $pending ) . ')</h2>';
		if ( ! $pending ) {
			echo '<p>No candidates awaiting review.</p></div>';
			return;
		}
		foreach ( $pending as $post ) {
			$post_id = (int) $post->ID;
			$qid     = (string) get_post_meta( $post_id, 'obit_qid', true );
			$birth   = (string) get_post_meta( $post_id, 'obit_birth_date', true );
			$enwiki  = (string) get_post_meta( $post_id, 'obit_enwiki', true );
			$hint    = (string) get_post_meta( $post_id, Import_Service::META_OCCUPATION_HINT, true );
			$desc    = (string) get_post_meta( $post_id, 'obit_discovery_description', true );
			$edit    = get_edit_post_link( $post_id, 'raw' );
			echo '<section style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0;max-width:980px">';
			echo '<h3>' . esc_html( get_the_title( $post_id ) ) . ' <small>· born ' . esc_html( $birth ) . '</small></h3>';
			if ( $desc ) {
				echo '<p>' . esc_html( $desc ) . '</p>';
			}
			echo '<p>Wikidata: <a target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ) ) . '">' . esc_html( $qid ) . '</a>';
			if ( $enwiki ) {
				echo ' · Wikipedia: <a target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://en.wikipedia.org/wiki/' . str_replace( '%2F', '/', rawurlencode( $enwiki ) ) ) . '">' . esc_html( str_replace( '_', ' ', $enwiki ) ) . '</a>';
			}
			if ( $hint ) {
				echo ' · occupation hint: ' . esc_html( $hint );
			}
			if ( $edit ) {
				echo ' · <a href="' . esc_url( $edit ) . '">Edit private draft</a>';
			}
			echo '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'obitleague_discovery_approve_' . $post_id );
			echo '<input type="hidden" name="action" value="obitleague_discovery_approve" /><input type="hidden" name="post_id" value="' . $post_id . '" />';
			echo '<table class="form-table"><tbody>';
			echo '<tr><th><label for="ob-source-' . $post_id . '">Recent source URL (HTTPS)</label></th><td><input id="ob-source-' . $post_id . '" name="source_url" type="url" class="large-text" placeholder="https://news.example/..." required /></td></tr>';
			echo '<tr><th><label for="ob-date-' . $post_id . '">Source publication date</label></th><td><input id="ob-date-' . $post_id . '" name="evidence_date" type="date" required /> <span class="description">Must be within the past 12 months.</span></td></tr>';
			echo '<tr><th>Living status</th><td><label><input type="checkbox" name="confirmed_alive" value="1" required /> I checked this source and confirm the person is currently alive.</label></td></tr>';
			echo '</tbody></table>';
			submit_button( 'Recheck and approve for catalogue', 'primary', 'submit', false );
			echo '</form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
			wp_nonce_field( 'obitleague_discovery_reject_' . $post_id );
			echo '<input type="hidden" name="action" value="obitleague_discovery_reject" /><input type="hidden" name="post_id" value="' . $post_id . '" />';
			echo '<label for="ob-reason-' . $post_id . '">Rejection note </label><input id="ob-reason-' . $post_id . '" name="reason" type="text" maxlength="1000" class="regular-text" required /> ';
			submit_button( 'Reject candidate', 'secondary', 'submit', false );
			echo '</form></section>';
		}
		echo '</div>';
	}

	/** Handle one small manually-triggered batch. */
	public static function run_batch(): void {
		self::require_post( 'obitleague_discovery_run', self::BATCH_CAP );
		$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 1;
		$result = Discovery_Service::run_batch( min( 5, max( 1, $limit ) ) );
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'error' => $result->get_error_message() ) );
		}
		self::redirect( array( 'notice' => sprintf( 'Window %s: checked %d, queued %d, skipped %d.', $result['window'], $result['checked'], $result['queued'], $result['skipped'] ) ) );
	}

	/** Record source and confirmation, perform fresh checks, then publish. */
	public static function approve(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		self::require_post( 'obitleague_discovery_approve_' . $post_id );
		$source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['source_url'] ) ) : '';
		$date       = isset( $_POST['evidence_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['evidence_date'] ) ) : '';
		$confirmed  = ! empty( $_POST['confirmed_alive'] );
		try {
			Discovery_Service::approve_candidate( $post_id, $source_url, $date, $confirmed, get_current_user_id() );
			self::redirect( array( 'notice' => 'Candidate approved and published.' ) );
		} catch ( \Throwable $error ) {
			self::redirect( array( 'error' => $error->getMessage() ) );
		}
	}

	/** Retain rejected candidates privately with an audited editorial note. */
	public static function reject(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		self::require_post( 'obitleague_discovery_reject_' . $post_id );
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		try {
			Discovery_Service::reject_candidate( $post_id, $reason, get_current_user_id() );
			self::redirect( array( 'notice' => 'Candidate rejected; private draft and audit history retained.' ) );
		} catch ( \Throwable $error ) {
			self::redirect( array( 'error' => $error->getMessage() ) );
		}
	}

	/** @return \WP_Post[] */
	private static function pending_candidates(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					array( 'key' => 'obit_discovery_source', 'value' => 'wikidata-wdqs' ),
					array( 'key' => 'obit_discovery_status', 'value' => 'pending' ),
				),
			)
		);
		return $query->posts;
	}

	private static function require_post( string $nonce_action, string $cap = self::CAP ): void {
		if ( ! current_user_can( $cap ) || empty( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( (string) $_POST['_wpnonce'] ) ), $nonce_action )
		) {
			wp_die( esc_html__( 'Not allowed.', 'obitleague' ) );
		}
	}

	private static function redirect( array $args ): void {
		$url = add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
		wp_safe_redirect( $url );
		exit;
	}
}

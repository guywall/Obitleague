<?php
/**
 * Editorial review admin.
 *
 * Native WP-admin screens: the review queue, a case screen with
 * field-by-field decisions, an impact preview and audit history. The UI
 * calls the same Review_Service as the REST route — one implementation of
 * the decision rules. Also adds the person meta box (edit fields) and
 * bootstraps the obitleague_review capability.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Admin_Review {

	private const CAP = 'obitleague_review';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'add_meta_boxes', array( self::class, 'meta_box' ) );
		add_action( 'save_post_' . Catalogue::POST_TYPE, array( self::class, 'save_person_fields' ), 10, 2 );
		add_action( 'admin_post_obitleague_decide', array( self::class, 'handle_decision' ) );
		add_action( 'init', array( self::class, 'grant_capability' ) );
	}

	/** Editors and administrators may review; grant on init (idempotent). */
	public static function grant_capability(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Obitleague', 'obitleague' ),
			__( 'Obitleague', 'obitleague' ),
			self::CAP,
			'obitleague-review',
			array( self::class, 'render_queue' ),
			'dashicons-tickets-alt',
			58
		);
		add_submenu_page(
			'obitleague-review',
			__( 'Review queue', 'obitleague' ),
			__( 'Review queue', 'obitleague' ),
			self::CAP,
			'obitleague-review',
			array( self::class, 'render_queue' )
		);
	}

	/* ================= Person edit fields (meta box) ================= */

	public static function meta_box(): void {
		add_meta_box(
			'obitleague_person_facts',
			__( 'Obitleague — person facts', 'obitleague' ),
			array( self::class, 'render_meta_box' ),
			Catalogue::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'obitleague_person_facts', 'obitleague_person_nonce' );

		$fields = array(
			'obit_uuid'             => array( 'Internal UUID', 'readonly' ),
			'obit_qid'              => array( 'Wikidata QID', 'text' ),
			'obit_enwiki'           => array( 'Wikipedia article (title)', 'text' ),
			'obit_role'             => array( 'Public role', 'text' ),
			'obit_birth_date'       => array( 'Birth date (Y-m-d)', 'text' ),
			'obit_death_date'       => array( 'Death date (Y-m-d)', 'text' ),
			'obit_death_precision'  => array( 'Death precision (exact|month|year|unknown)', 'text' ),
			'obit_cause_status'     => array( 'Cause status (not_disclosed|pending_official|confirmed|contested)', 'text' ),
			'obit_cause_text'       => array( 'Cause wording (approved only)', 'text' ),
			'obit_eligibility'      => array( 'Eligibility (candidate|approved|ineligible)', 'text' ),
			'obit_portrait_credit'  => array( 'Portrait credit', 'text' ),
		);

		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $meta ) {
			[ $label, $type ] = $meta;
			$value = (string) get_post_meta( $post->ID, $key, true );
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%1$s" value="%3$s" class="regular-text" %4$s /></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $value ),
				'readonly' === $type ? 'readonly="readonly"' : ''
			);
		}
		echo '</table>';
		echo '<p class="description">' . esc_html__( 'Dates are stored Y-m-d. Death facts change only through an approved review decision — edits here are audited by WordPress revisions of the meta values.', 'obitleague' ) . '</p>';
	}

	public static function save_person_fields( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['obitleague_person_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['obitleague_person_nonce'] ), 'obitleague_person_facts' )
			|| ! current_user_can( 'edit_post', $post_id )
			|| defined( 'DOING_AUTOSAVE' )
		) {
			return;
		}

		$keys = array( 'obit_qid', 'obit_enwiki', 'obit_role', 'obit_birth_date', 'obit_death_date', 'obit_death_precision', 'obit_cause_status', 'obit_cause_text', 'obit_eligibility', 'obit_portrait_credit' );
		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) );
			}
		}
	}

	/* ================= Review queue ================= */

	public static function render_queue(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to review cases.', 'obitleague' ) );
		}

		if ( isset( $_GET['case'] ) && (int) $_GET['case'] ) {
			self::case_screen();
			return;
		}

		$pending  = Review_Service::pending_cases( 100 );
		$decided  = self::recent_decisions( 20 );
		$approved = (int) self::count_state( 'approved' );

		echo '<div class="wrap"><h1>Obitleague — Review queue</h1>';
		echo '<p>' . esc_html( sprintf( '%d pending, %d approved all-time.', count( $pending ), $approved ) ) . '</p>';

		if ( $pending ) {
			echo '<table class="widefat striped"><thead><tr><th>Person</th><th>Opened</th><th>Note</th><th></th></tr></thead><tbody>';
			foreach ( $pending as $case ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $case->person_name ) . '</strong></td>';
				echo '<td>' . esc_html( $case->created_at ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $case->decision_reason, 0, 90 ) ) . '</td>';
				echo '<td><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=obitleague-review&case=' . (int) $case->id ) ) . '">Review</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p><em>' . esc_html__( 'No pending cases. The queue is clear.', 'obitleague' ) . '</em></p>';
		}

		echo '<h2>Recent decisions (audit)</h2>';
		if ( $decided ) {
			echo '<table class="widefat striped"><thead><tr><th>Person</th><th>State</th><th>Decided by</th><th>At</th><th>Reason</th></tr></thead><tbody>';
			foreach ( $decided as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( Review_Service::person_name( (string) $row->person_uuid ) ) . '</td>';
				echo '<td>' . esc_html( $row->state ) . '</td>';
				echo '<td>' . esc_html( get_the_author_meta( 'display_name', (int) $row->decided_by ) ?: (string) $row->decided_by ) . '</td>';
				echo '<td>' . esc_html( (string) $row->decided_at ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $row->decision_reason, 0, 110 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p><em>No decisions recorded yet.</em></p>';
		}
		echo '</div>';
	}

	private static function count_state( string $state ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_review_cases WHERE state = %s', $state )
		);
	}

	private static function recent_decisions( int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'obitleague_review_cases
				 WHERE state IN (%s, %s, %s) ORDER BY COALESCE(decided_at, updated_at) DESC LIMIT %d',
				'approved', 'rejected', 'retracted',
				$limit
			)
		);
	}

	/* ================= Case screen ================= */

	private static function current_case(): ?object {
		global $wpdb;
		$case_id = isset( $_GET['case'] ) ? (int) $_GET['case'] : 0;
		if ( ! $case_id ) {
			return null;
		}
		$case = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_review_cases WHERE id = %d', $case_id ) );
		return $case ?: null;
	}

	private static function case_screen(): void {
		global $wpdb;
		$case = self::current_case();
		if ( ! $case ) {
			echo '<div class="wrap"><h1>Case not found</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=obitleague-review' ) ) . '">Back to queue</a></p></div>';
			return;
		}

		$person_post_id = Review_Service::person_post_id( (string) $case->person_uuid );
		$name           = Review_Service::person_name( (string) $case->person_uuid );
		$birth          = $person_post_id ? (string) get_post_meta( $person_post_id, 'obit_birth_date', true ) : '';
		$role           = $person_post_id ? (string) get_post_meta( $person_post_id, 'obit_role', true ) : '';
		$qid            = $person_post_id ? (string) get_post_meta( $person_post_id, 'obit_qid', true ) : '';
		$enwiki         = $person_post_id ? (string) get_post_meta( $person_post_id, 'obit_enwiki', true ) : '';

		// Impact preview: submitted entries holding this person.
		$impact = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.id AS entry_id, e.user_id, e.season, u.display_name
				 FROM ' . $wpdb->prefix . 'obitleague_entry_picks p
				 JOIN ' . $wpdb->prefix . "obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'
				 JOIN " . $wpdb->prefix . 'obitleague_entries e ON e.id = r.entry_id
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = e.user_id
				 WHERE p.person_uuid = %s',
				(string) $case->person_uuid
			)
		);

		echo '<div class="wrap">';
		echo '<h1>Review case #' . (int) $case->id . ' — ' . esc_html( $name ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=obitleague-review' ) ) . '">&larr; Back to queue</a> · State: <strong>' . esc_html( (string) $case->state ) . '</strong> · Revision: ' . (int) $case->revision . '</p>';

		if ( ! empty( $_GET['done'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( (string) $_GET['done'] ) . '</p></div>';
		}
		if ( ! empty( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $_GET['error'] ) . '</p></div>';
		}

		echo '<h2>Evidence</h2><table class="widefat striped"><tbody>';
		echo '<tr><th>Person record</th><td>' . ( $person_post_id ? '<a href="' . esc_url( (string) get_edit_post_link( $person_post_id ) ) . '">' . esc_html( $name ) . ' (#' . $person_post_id . ')</a>' : esc_html( $name ) ) . '</td></tr>';
		echo '<tr><th>Public role</th><td>' . esc_html( $role ?: '—' ) . '</td></tr>';
		echo '<tr><th>Birth date</th><td>' . esc_html( $birth ?: 'unknown' ) . '</td></tr>';
		if ( $qid ) {
			echo '<tr><th>Wikidata</th><td><a target="_blank" href="https://www.wikidata.org/wiki/' . esc_attr( $qid ) . '">' . esc_html( $qid ) . '</a> (structured facts, CC0)</td></tr>';
		}
		if ( $enwiki ) {
			echo '<tr><th>Wikipedia</th><td><a target="_blank" href="https://en.wikipedia.org/wiki/' . esc_attr( $enwiki ) . '">' . esc_html( str_replace( '_', ' ', $enwiki ) ) . '</a></td></tr>';
		}
		echo '<tr><th>Case note</th><td>' . esc_html( (string) $case->decision_reason ?: '—' ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>Impact preview</h2>';
		if ( $impact ) {
			echo '<p>This decision would affect <strong>' . count( $impact ) . '</strong> submitted pick(s):</p><ul>';
			foreach ( $impact as $row ) {
				echo '<li>Entry #' . (int) $row->entry_id . ' — ' . esc_html( (string) ( $row->display_name ?: 'Player ' . $row->user_id ) ) . ' (season ' . (int) $row->season . ')</li>';
			}
			echo '</ul><p class="description">Points are computed at award time from the approved exact death date: max(1, 100 − age).</p>';
		} else {
			echo '<p><em>No submitted teams hold this person. Approval affects the archive only.</em></p>';
		}

		echo '<h2>Audit history</h2>';
		$history = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'obitleague_review_cases WHERE person_uuid = %s ORDER BY id ASC',
				(string) $case->person_uuid
			)
		);
		echo '<table class="widefat striped"><thead><tr><th>Case</th><th>State</th><th>Revision</th><th>Decided by</th><th>At</th><th>Reason</th></tr></thead><tbody>';
		foreach ( $history as $h ) {
			echo '<tr><td>#' . (int) $h->id . '</td><td>' . esc_html( (string) $h->state ) . '</td><td>' . (int) $h->revision . '</td><td>' . esc_html( get_the_author_meta( 'display_name', (int) $h->decided_by ) ?: (string) $h->decided_by ) . '</td><td>' . esc_html( (string) $h->decided_at ) . '</td><td>' . esc_html( mb_substr( (string) $h->decision_reason, 0, 120 ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Decision form.
		$open_states = array( 'pending', 'approved', 'rejected', 'retracted' );
		if ( in_array( (string) $case->state, $open_states, true ) ) {
			echo '<h2>Decision</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'obitleague_decide_' . (int) $case->id );
			echo '<input type="hidden" name="action" value="obitleague_decide" />';
			echo '<input type="hidden" name="case_id" value="' . (int) $case->id . '" />';
			echo '<input type="hidden" name="expected_revision" value="' . (int) $case->revision . '" />';
			echo '<table class="form-table" role="presentation">';
			echo '<tr><th><label>Death date (Y-m-d)</label></th><td><input type="text" name="death_date" value="' . esc_attr( (string) $case->death_date ) . '" class="regular-text" /> <span class="description">Exact date required for approval.</span></td></tr>';
			echo '<tr><th>Evidence origins</th><td>';
			echo '<label><input type="checkbox" name="og_enwiki" value="1" checked="checked" /> English Wikipedia death list</label><br />';
			echo '<label><input type="checkbox" name="og_wikidata" value="1" checked="checked" /> Wikidata P570</label><br />';
			echo '<label><input type="checkbox" name="og_official" value="1" /> Authentic official statement (suffices alone)</label>';
			echo '</td></tr>';
			echo '<tr><th><label>Cause disclosed?</label></th><td><select name="cause_disclosed"><option value="0">No</option><option value="1">Yes (requires wording)</option></select> ';
			echo '<input type="text" name="cause_text" class="regular-text" placeholder="Approved cause wording" /></td></tr>';
			echo '<tr><th><label>Decision reason</label></th><td><textarea name="reason" class="large-text" rows="3" required></textarea></td></tr>';
			echo '</table><p>';
			if ( 'pending' === $case->state || 'rejected' === $case->state || 'retracted' === $case->state ) {
				submit_button( 'Approve', 'primary', 'decision[approved]', false );
			}
			if ( 'pending' === $case->state || 'retracted' === $case->state ) {
				submit_button( 'Reject', 'secondary', 'decision[rejected]', false, array( 'style' => 'margin-left:8px' ) );
			}
			if ( 'approved' === $case->state ) {
				submit_button( 'Retract', 'delete', 'decision[retracted]', false, array( 'style' => 'margin-left:8px' ) );
			}
			echo '</p></form>';
		} else {
			echo '<p><em>This case state does not accept decisions.</em></p>';
		}
		echo '</div>';
	}

	/** Handle the decision POST: nonce + capability + service call. */
	public static function handle_decision(): void {
		$case_id = isset( $_POST['case_id'] ) ? (int) $_POST['case_id'] : 0;
		if ( ! $case_id || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( (string) $_POST['_wpnonce'] ), 'obitleague_decide_' . $case_id ) || ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}

		$decisions = isset( $_POST['decision'] ) && is_array( $_POST['decision'] ) ? array_keys( wp_unslash( $_POST['decision'] ) ) : array();
		$to_state  = (string) ( $decisions[0] ?? '' );
		if ( ! in_array( $to_state, array( 'approved', 'rejected', 'retracted' ), true ) ) {
			wp_die( 'Unknown decision.' );
		}

		$death_raw = isset( $_POST['death_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['death_date'] ) ) : '';
		$death     = array();
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $death_raw, $m ) ) {
			$death = array( 'y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3] );
		}

		$origins = array();
		if ( ! empty( $_POST['og_enwiki'] ) ) {
			$origins[] = 'enwiki-deaths-list';
		}
		if ( ! empty( $_POST['og_wikidata'] ) ) {
			$origins[] = 'wikidata-P570';
		}

		$decision = array(
			'origin_groups'      => $origins,
			'death_date'         => $death,
			'cause_disclosed'    => ! empty( $_POST['cause_disclosed'] ),
			'cause_text'         => isset( $_POST['cause_text'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cause_text'] ) ) : '',
			'official_statement' => ! empty( $_POST['og_official'] ),
			'reason'             => isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '',
		);

		try {
			Review_Service::decide( $case_id, get_current_user_id(), $to_state, $decision, (int) ( $_POST['expected_revision'] ?? 0 ) );
			Outbox_Service::process_outbox( 200 ); // Awards and standings update immediately.
			wp_safe_redirect( admin_url( 'admin.php?page=obitleague-review&case=' . $case_id . '&done=' . rawurlencode( 'Decision saved: ' . $to_state ) ) );
		} catch ( \Throwable $e ) {
			wp_safe_redirect( admin_url( 'admin.php?page=obitleague-review&case=' . $case_id . '&error=' . rawurlencode( $e->getMessage() ) ) );
		}
		exit;
	}
}

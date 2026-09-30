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

use Obitleague\Domain\Review_Rules;

final class Admin_Review {

	private const CAP = 'obitleague_review';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'add_meta_boxes', array( self::class, 'meta_box' ) );
		add_action( 'save_post_' . Catalogue::POST_TYPE, array( self::class, 'save_person_fields' ), 10, 2 );
		add_action( 'admin_post_obitleague_decide', array( self::class, 'handle_decision' ) );
		add_action( 'admin_post_obitleague_review_bulk', array( self::class, 'handle_bulk' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_assets' ) );
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

	/** Add local horizontal scrolling to wide plugin-admin tables only. */
	public static function admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'toplevel_page_obitleague-review', 'obitleague_page_obitleague-game-admin' ), true ) ) {
			return;
		}
		wp_register_style( 'obitleague-admin-tables', false, array(), OBITLEAGUE_VERSION );
		wp_enqueue_style( 'obitleague-admin-tables' );
		wp_add_inline_style(
			'obitleague-admin-tables',
			'.ob-admin-table-scroll{max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch;margin:12px 0 20px}.ob-admin-table-scroll>table{min-width:620px}'
		);
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

		// Occupation tags follow the stored Wikidata occupation labels.
		$occupations = People_Sync::occupation_labels( $post_id );
		People_Sync::sync_occupation_terms( $post_id, $occupations );

		// The stats boards cache person shapes; refresh when identity changes.
		Stats_Service::flush_deceased_cache();
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

		$rows    = self::queue_rows( 200 );
		$decided = self::recent_decisions( 20 );
		$summary = sprintf( '%d pending, %d approved all-time.', count( $rows ), (int) self::count_state( 'approved' ) );

		echo '<div class="wrap"><h1>Obitleague — Review queue</h1>';
		echo '<p>' . esc_html( $summary ) . ' Confirm from here when the evidence below is enough; Edit opens the full decision form.</p>';

		if ( ! empty( $_GET['bulk'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( (string) $_GET['bulk'] ) . '</p></div>';
		}
		if ( ! empty( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $_GET['error'] ) . '</p></div>';
		}

		if ( $rows ) {
			$eligible = 0;
			foreach ( $rows as $row ) {
				if ( ! empty( $row['eligible'] ) ) {
					++$eligible;
				}
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'obitleague_review_bulk' );
			echo '<input type="hidden" name="action" value="obitleague_review_bulk" />';
			echo '<p><button type="submit" name="bulk_approve_all" value="1" class="button button-primary">Confirm all ' . (int) $eligible . ' evidence-complete case(s)</button>';
			echo ' <span class="description">Approves only cases whose record already carries an exact death date and two independent origins — the same rules the admin form enforces.</span></p>';
			echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>Person</th><th>Death date</th><th>Cause</th><th>Sources</th><th>Opened</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $row['name'] ) . '</strong>';
				echo $row['provisional'] ? ' <span style="color:#996800;">⚠ provisional</span>' : '';
				echo '<br /><span class="description">' . esc_html( $row['role'] ) . '</span></td>';
				echo '<td>' . esc_html( $row['death_date'] ?: '— missing —' ) . ( '' !== $row['age'] ? '<br /><span class="description">age ' . esc_html( $row['age'] ) . '</span>' : '' ) . '</td>';
				echo '<td>' . esc_html( $row['cause'] ) . '</td>';
				echo '<td>';
				if ( $row['sources'] ) {
					foreach ( $row['sources'] as $i => $src ) {
						echo ( $i ? '<br />' : '' ) . '<a href="' . esc_url( $src['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $src['name'] ?: 'Report' ) . '</a>';
					}
				} else {
					echo '<span class="description">none attached</span>';
				}
				echo '</td>';
				echo '<td>' . esc_html( substr( (string) $row['opened'], 0, 10 ) ) . '</td>';
				echo '<td style="white-space:nowrap">';
				if ( ! empty( $row['eligible'] ) ) {
					echo '<button type="submit" name="case_id" value="' . (int) $row['id'] . '" class="button button-primary">Confirm</button> ';
				} else {
					echo '<span class="description">needs facts</span> ';
				}
				echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=obitleague-review&case=' . (int) $row['id'] ) ) . '">Edit</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div></form>';
		} else {
			echo '<p><em>' . esc_html__( 'No pending cases. The queue is clear.', 'obitleague' ) . '</em></p>';
		}

		echo '<h2>Recent decisions (audit)</h2>';
		if ( $decided ) {
			echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>Person</th><th>State</th><th>Decided by</th><th>At</th><th>Reason</th></tr></thead><tbody>';
			foreach ( $decided as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( Review_Service::person_name( (string) $row->person_uuid ) ) . '</td>';
				echo '<td>' . esc_html( $row->state ) . '</td>';
				echo '<td>' . esc_html( get_the_author_meta( 'display_name', (int) $row->decided_by ) ?: (string) $row->decided_by ) . '</td>';
				echo '<td>' . esc_html( (string) $row->decided_at ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $row->decision_reason, 0, 110 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p><em>No decisions recorded yet.</em></p>';
		}
		echo '</div>';
	}

	/**
	 * Pending cases enriched with the facts an editor needs to decide at a
	 * glance: death date, age, cause wording, attached sources. Three bulk
	 * queries for the whole page, not two hundred row-level lookups.
	 */
	private static function queue_rows( int $limit ): array {
		global $wpdb;
		$cases = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, person_uuid, created_at, revision FROM ' . $wpdb->prefix . 'obitleague_review_cases
				 WHERE state = %s ORDER BY created_at ASC LIMIT %d',
				Review_Rules::PENDING,
				$limit
			)
		);
		if ( ! $cases ) {
			return array();
		}
		$uuids = array_values( array_unique( array_column( $cases, 'person_uuid' ) ) );
		$in    = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );

		// Person posts for the uuids, with the meta keys the queue shows.
		$meta_keys = array( 'obit_death_date', 'obit_birth_date', 'obit_cause_status', 'obit_cause_text', 'obit_role', 'obit_enwiki', 'obit_qid', 'obit_death_provisional', 'obit_death_wiki_name' );
		$post_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, pm.meta_key, pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'obit_uuid' AND pm.meta_value IN ({$in})",
				$uuids
			)
		);
		$by_uuid = array();
		$post_ids = array();
		foreach ( $post_rows as $r ) {
			$by_uuid[ $r->meta_value ] = (int) $r->ID;
			$post_ids[ (int) $r->ID ]  = (string) $r->post_title;
		}
		$meta = array();
		if ( $post_ids ) {
			$id_in = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			foreach ( (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key IN (" . implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) ) . ") AND post_id IN ({$id_in})",
					array_merge( $meta_keys, array_keys( $post_ids ) )
				)
			) as $m ) {
				$meta[ (int) $m->post_id ][ $m->meta_key ] = (string) $m->meta_value;
			}
		}
		$source_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.person_uuid, s.source_name, s.source_url
				 FROM {$wpdb->prefix}obitleague_death_sources s
				 JOIN {$wpdb->prefix}obitleague_review_cases c ON c.id = s.case_id
				 WHERE c.person_uuid IN ({$in}) ORDER BY s.id ASC LIMIT 600",
				$uuids
			)
		);
		$sources = array();
		foreach ( $source_rows as $s ) {
			$sources[ $s->person_uuid ][] = array( 'name' => (string) $s->source_name, 'url' => (string) $s->source_url );
		}

		$out = array();
		foreach ( $cases as $case ) {
			$uuid    = (string) $case->person_uuid;
			$post_id = $by_uuid[ $uuid ] ?? 0;
			$m       = $meta[ $post_id ] ?? array();
			$death   = (string) ( $m['obit_death_date'] ?? '' );
			$age     = '';
			$birth   = (string) ( $m['obit_birth_date'] ?? '' );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $death, $dm ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $birth, $bm ) ) {
				$age = (string) max( 0, (int) $dm[1] - (int) $bm[1] - ( ( $dm[2] . $dm[3] ) < ( $bm[2] . $bm[3] ) ? 1 : 0 ) );
			}
			$cause_status = (string) ( $m['obit_cause_status'] ?? '' );
			$cause_text   = trim( (string) ( $m['obit_cause_text'] ?? '' ) );
			$cause        = '' !== $cause_text ? $cause_text : ( '' !== $cause_status ? str_replace( '_', ' ', $cause_status ) : 'unknown' );
			$origins      = 0;
			// The Deaths-in-2026 list flag or an article title both count as
			// the Wikipedia origin; the Wikidata QID is the second origin.
			$origins     += ( '' !== (string) ( $m['obit_death_wiki_name'] ?? '' ) || '' !== (string) ( $m['obit_enwiki'] ?? '' ) ) ? 1 : 0;
			$origins     += ( '' !== (string) ( $m['obit_qid'] ?? '' ) ) ? 1 : 0;
			$eligible     = (bool) $post_id
				&& (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $death )
				&& $origins >= 2;
			$out[] = array(
				'id'           => (int) $case->id,
				'name'         => $post_id ? (string) ( $post_ids[ $post_id ] ?? $uuid ) : $uuid,
				'role'         => (string) ( $m['obit_role'] ?? '' ),
				'death_date'   => $death,
				'age'          => $age,
				'cause'        => $cause,
				'sources'      => array_slice( $sources[ $uuid ] ?? array(), 0, 3 ),
				'opened'       => (string) $case->created_at,
				'provisional'  => ! empty( $m['obit_death_provisional'] ),
				'eligible'     => $eligible,
			);
		}
		return $out;
	}

	/**
	 * Queue-level confirm: one case (Confirm button) or every
	 * evidence-complete pending case (bulk bar). Only honest approvals: the
	 * shared decision builder refuses records lacking exact date + origins.
	 */
	public static function handle_bulk(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( (string) $_POST['_wpnonce'] ), 'obitleague_review_bulk' ) ) {
			wp_die( 'Not allowed.' );
		}

		$back = admin_url( 'admin.php?page=obitleague-review' );
		if ( ! empty( $_POST['bulk_approve_all'] ) ) {
			$approved = 0;
			$skipped  = 0;
			foreach ( self::queue_rows( 500 ) as $row ) {
				$decision = Review_Cli::build_decision( (int) $row['id'] );
				if ( null === $decision ) {
					++$skipped;
					continue;
				}
				$case = self::current_case_by_id( (int) $row['id'] );
				try {
					Review_Service::decide( (int) $row['id'], get_current_user_id(), 'approved', $decision, (int) $case->revision );
					++$approved;
				} catch ( \Throwable ) {
					++$skipped;
				}
			}
			if ( $approved ) {
				Outbox_Service::process_outbox( 500 );
			}
			wp_safe_redirect( add_query_arg( 'bulk', rawurlencode( "Confirmed {$approved} case(s), {$skipped} left pending for facts." ), $back ) );
			exit;
		}

		$case_id = isset( $_POST['case_id'] ) ? (int) $_POST['case_id'] : 0;
		if ( ! $case_id ) {
			wp_safe_redirect( $back );
			exit;
		}
		$decision = Review_Cli::build_decision( $case_id );
		if ( null === $decision ) {
			wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Case #' . $case_id . ' lacks an exact death date or two independent origins — use Edit to supply facts.' ), $back ) );
			exit;
		}
		try {
			$case = self::current_case_by_id( $case_id );
			Review_Service::decide( $case_id, get_current_user_id(), 'approved', $decision, (int) $case->revision );
			Outbox_Service::process_outbox( 200 );
			wp_safe_redirect( add_query_arg( 'bulk', rawurlencode( 'Confirmed: ' . Review_Service::person_name( (string) ( $case->person_uuid ?? '' ) ) ), $back ) );
		} catch ( \Throwable $e ) {
			wp_safe_redirect( add_query_arg( 'error', rawurlencode( $e->getMessage() ), $back ) );
		}
		exit;
	}

	private static function current_case_by_id( int $case_id ): ?object {
		global $wpdb;
		$case = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_review_cases WHERE id = %d', $case_id ) );
		return $case ?: null;
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

		echo '<h2>Evidence</h2><div class="ob-admin-table-scroll"><table class="widefat striped"><tbody>';
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
		echo '</tbody></table></div>';

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
		echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>Case</th><th>State</th><th>Revision</th><th>Decided by</th><th>At</th><th>Reason</th></tr></thead><tbody>';
		foreach ( $history as $h ) {
			echo '<tr><td>#' . (int) $h->id . '</td><td>' . esc_html( (string) $h->state ) . '</td><td>' . (int) $h->revision . '</td><td>' . esc_html( get_the_author_meta( 'display_name', (int) $h->decided_by ) ?: (string) $h->decided_by ) . '</td><td>' . esc_html( (string) $h->decided_at ) . '</td><td>' . esc_html( mb_substr( (string) $h->decision_reason, 0, 120 ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

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

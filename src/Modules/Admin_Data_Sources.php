<?php
/**
 * Data sources: sync controls and queue visibility.
 *
 * One screen that answers, for every external source the plugin talks to —
 * Wikidata, Wikipedia, Wikimedia Commons, and the configured RSS feeds —
 * what is queued, what is waiting, when it will next run, and what to do
 * about it. Read-only statistics stay on the Statistics screen; this page
 * is the operational control surface.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Admin_Data_Sources {

	private const CAP  = 'manage_options';
	private const PAGE = 'obitleague-data-sources';

	/** Slugs of sources shown as cards, with the option key of their pause. */
	private const WIKI_SOURCES = array(
		'wikidata' => 'obitleague_wq_pause_wikidata',
		'enwiki'   => 'obitleague_wq_pause_enwiki',
		'commons'  => 'obitleague_wq_pause_commons',
	);

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_obitleague_sync_enqueue_people', array( self::class, 'handle_enqueue_people' ) );
		add_action( 'admin_post_obitleague_sync_drain_queue', array( self::class, 'handle_drain_queue' ) );
		add_action( 'admin_post_obitleague_sync_clear_pause', array( self::class, 'handle_clear_pause' ) );
		add_action( 'admin_post_obitleague_sync_retry_failed', array( self::class, 'handle_retry_failed' ) );
		add_action( 'admin_post_obitleague_sync_poll_feed', array( self::class, 'handle_poll_feed' ) );
		add_action( 'admin_post_obitleague_sync_run_discovery', array( self::class, 'handle_run_discovery' ) );
		add_action( 'admin_post_obitleague_sync_run_wire', array( self::class, 'handle_run_wire' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'obitleague-review',
			__( 'Data sources', 'obitleague' ),
			__( 'Data sources', 'obitleague' ),
			self::CAP,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_enqueue_people(): void {
		self::guard( 'obitleague_sync_enqueue_people' );
		$limit = max( 1, min( 2000, (int) ( $_POST['limit'] ?? 400 ) ) );
		$stats = People_Sync::enqueue_missing( $limit );
		$notice = sprintf(
			'%d people checked, %d missing enrichment, %d newly queued.',
			(int) $stats['checked'],
			(int) $stats['missing'],
			(int) $stats['enqueued']
		);
		if ( (int) ( $stats['failed'] ?? 0 ) > 0 ) {
			self::redirect_error( $notice . sprintf( ' %d request(s) could not be stored in the Wikimedia request queue — nothing will run until that is fixed.', (int) $stats['failed'] ) );
		}
		self::redirect_notice( $notice );
	}

	public static function handle_drain_queue(): void {
		self::guard( 'obitleague_sync_drain_queue' );
		$budget = max( 1, min( 100, (int) ( $_POST['budget'] ?? 20 ) ) );
		$done   = 0;
		for ( $i = 0; $i < $budget; ++$i ) {
			$n = Wiki_Request_Queue::process( 2 );
			if ( 0 === $n ) {
				break;
			}
			$done += $n;
		}
		self::redirect_notice( sprintf( '%d queue request(s) processed now.', $done ) );
	}

	public static function handle_clear_pause(): void {
		self::guard( 'obitleague_sync_clear_pause' );
		$source = self::posted_source();
		if ( '' === $source ) {
			self::redirect_error( 'Unknown source.' );
		}
		$option = self::WIKI_SOURCES[ $source ] ?? '';
		if ( '' === $option ) {
			self::redirect_error( 'That source has no pause to clear.' );
		}
		update_option( $option, 0, false );
		self::redirect_notice( sprintf( 'Pause cleared for %s; queued requests run as they fall due.', $source ) );
	}

	public static function handle_retry_failed(): void {
		self::guard( 'obitleague_sync_retry_failed' );
		global $wpdb;
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}obitleague_wiki_queue
				 SET status = %s, attempts = 0, last_error = NULL, next_attempt_at = %s
				 WHERE status = %s",
				Wiki_Request_Queue::STATUS_PENDING,
				current_time( 'mysql', true ),
				Wiki_Request_Queue::STATUS_FAILED
			)
		);
		self::redirect_notice( sprintf( '%d failed request(s) reset to pending with a fresh attempt budget.', (int) $n ) );
	}

	public static function handle_poll_feed(): void {
		self::guard( 'obitleague_sync_poll_feed' );
		$source_id = (int) ( $_POST['source_id'] ?? 0 );
		$source    = self::feed_source( $source_id );
		if ( null === $source ) {
			self::redirect_error( 'Unknown feed source.' );
		}
		$result = Jobs::poll_source( $source );
		self::redirect_notice(
			sprintf( 'Polled “%1$s”: %2$s, %3$d new item(s).', (string) $source->name, (string) $result['status'], (int) $result['new_items'] )
		);
	}

	public static function handle_run_discovery(): void {
		self::guard( 'obitleague_sync_run_discovery' );
		$result = Discovery_Service::run_batch( 50, false );
		if ( is_wp_error( $result ) ) {
			self::redirect_error( $result->get_error_message() );
		}
		self::redirect_notice(
			sprintf( 'Discovery batch: %s · queued %d, skipped %d.', (string) ( $result['status'] ?? 'ok' ), (int) ( $result['queued'] ?? 0 ), (int) ( $result['skipped'] ?? 0 ) )
		);
	}

	public static function handle_run_wire(): void {
		self::guard( 'obitleague_sync_run_wire' );
		$result = Death_Wire::run();
		self::redirect_notice(
			sprintf( 'Death wire primed: %d month page(s) queued, %d already done.', (int) ( $result['months_queued'] ?? 0 ), (int) ( $result['months_done'] ?? 0 ) )
		);
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to run sync controls.', 'obitleague' ) );
		}
		check_admin_referer( $action );
	}

	private static function posted_source(): string {
		$source = sanitize_key( wp_unslash( (string) ( $_POST['source'] ?? '' ) ) );
		return isset( self::WIKI_SOURCES[ $source ] ) ? $source : '';
	}

	private static function feed_source( int $source_id ): ?object {
		global $wpdb;
		if ( $source_id < 1 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}obitleague_sources WHERE id = %d", $source_id )
		);
		return $row ?: null;
	}

	private static function redirect_notice( string $message ): void {
		self::redirect( array( 'notice' => $message ) );
	}

	private static function redirect_error( string $message ): void {
		self::redirect( array( 'error' => $message ) );
	}

	/** @param array<string, string> $args */
	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                              */
	/* ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view data sources.', 'obitleague' ) );
		}

		echo '<div class="wrap"><h1>Obitleague — Data sources</h1>';
		if ( isset( $_GET['notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) ) . '</p></div>';
		}
		echo '<p class="description">Everything the plugin fetches from the outside world runs through one serial request queue with per-source pacing: a rate-limit answer sets the source’s next-try time, and every queued request for it waits until then. Nothing is lost while paused — it runs when due.</p>';

		self::render_wiki_queue();
		self::render_wiki_sources();
		self::render_enrichment();
		self::render_discovery();
		self::render_death_wire();
		self::render_feeds();

		echo '</div>';
	}

	/** Queue-wide header: counts, next row, global controls. */
	private static function render_wiki_queue(): void {
		$queue  = Wiki_Request_Queue::status();
		$counts = $queue['counts'];
		$next   = $queue['next'];
		$paused = Discovery_Service::rate_limit_pause_until() > time();

		echo '<h2>Wikimedia request queue</h2>';
		echo '<p>';
		printf(
			'<strong>%s</strong> pending · <strong>%s</strong> running · <strong>%s</strong> done · <strong>%s</strong> failed',
			esc_html( number_format_i18n( (int) ( $counts['pending'] ?? 0 ) ) ),
			esc_html( number_format_i18n( (int) ( $counts['processing'] ?? 0 ) ) ),
			esc_html( number_format_i18n( (int) ( $counts['done'] ?? 0 ) ) ),
			esc_html( number_format_i18n( (int) ( $counts['failed'] ?? 0 ) ) )
		);
		if ( $paused ) {
			echo ' — shared Wikimedia cooldown active until ' . esc_html( gmdate( 'Y-m-d H:i:s', Discovery_Service::rate_limit_pause_until() ) ) . ' UTC.';
		}
		echo '</p>';
		if ( $next ) {
			$due = (string) $next->next_attempt_at;
			echo '<p class="description">Next request: #' . (int) $next->id . ' <code>' . esc_html( (string) $next->request_kind ) . '</code> (' . esc_html( (string) $next->source ) . '), due ' . esc_html( $due ) . ' UTC' . ( $due > current_time( 'mysql', true ) ? ' — waiting on pacing/pause' : ' — runs on the next minute tick' ) . '.</p>';
		} else {
			echo '<p class="description">Queue is empty: nothing pending.</p>';
		}
		echo '<p>';
		self::action_button( 'obitleague_sync_drain_queue', 'Drain queue now', array( 'budget' => 20 ) );
		self::action_button( 'obitleague_sync_retry_failed', 'Retry failed requests' );
		echo '</p>';
	}

	/** One card per Wikimedia source: pause state, in-flight counts, next due, clear control. */
	private static function render_wiki_sources(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_wiki_queue';
		$rows  = (array) $wpdb->get_results(
			"SELECT source, status, COUNT(*) AS n, MIN(next_attempt_at) AS next_due
			 FROM {$table} WHERE status IN ( 'pending', 'processing' )
			 GROUP BY source, status"
		);
		$by_source = array();
		foreach ( $rows as $row ) {
			$by_source[ (string) $row->source ][ (string) $row->status ] = $row;
		}
		$failed = (array) $wpdb->get_results(
			"SELECT source, COUNT(*) AS n FROM {$table} WHERE status = 'failed' GROUP BY source"
		);

		echo '<h2>Wikimedia sources</h2>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;max-width:1300px">';
		foreach ( array_keys( self::WIKI_SOURCES ) as $source ) {
			$pause_until = Wiki_Request_Queue::source_pause_until( $source );
			$paused      = $pause_until > time();
			$pending     = (int) ( $by_source[ $source ]['pending']->n ?? 0 );
			$processing  = (int) ( $by_source[ $source ]['processing']->n ?? 0 );
			$next_due    = (string) ( $by_source[ $source ]['pending']->next_due ?? '' );
			$failed_n    = (int) ( $failed[ $source ]->n ?? 0 );

			echo '<div class="card" style="max-width:none;margin:0;padding:14px 16px">';
			echo '<h3 style="margin:0 0 6px">' . esc_html( $source ) . '</h3>';
			if ( $paused ) {
				echo '<p style="margin:0 0 6px"><span style="color:#b32d2e;font-weight:600">Rate-limit pause</span> until ' . esc_html( gmdate( 'Y-m-d H:i:s', $pause_until ) ) . ' UTC (' . esc_html( self::human_delay( $pause_until - time() ) ) . ' from now).</p>';
			} else {
				echo '<p style="margin:0 0 6px"><span style="color:#007017;font-weight:600">Clear</span> — requests run as they fall due.</p>';
			}
			echo '<p style="margin:0 0 6px">' . number_format_i18n( $pending ) . ' pending · ' . number_format_i18n( $processing ) . ' running · ' . number_format_i18n( $failed_n ) . ' failed</p>';
			echo '<p style="margin:0 0 10px" class="description">Next due: ' . ( '' !== $next_due ? esc_html( $next_due ) . ' UTC' : 'nothing queued' ) . '</p>';
			if ( $paused ) {
				self::action_button( 'obitleague_sync_clear_pause', 'Clear pause', array( 'source' => $source ), 'secondary small' );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/** The people-enrichment backlog: who is waiting, on what, since when. */
	private static function render_enrichment(): void {
		global $wpdb;

		$people_total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", Catalogue::POST_TYPE, 'publish' )
		);
		$with_image = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> '' WHERE p.post_type = %s AND p.post_status = %s",
				People_Sync::META_IMAGE_URL, Catalogue::POST_TYPE, 'publish'
			)
		);
		$with_occ = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> '' WHERE p.post_type = %s AND p.post_status = %s",
				People_Sync::META_OCCUPATIONS, Catalogue::POST_TYPE, 'publish'
			)
		);
		$sync = (array) get_option( 'obitleague_people_sync_last_run', array() );

		echo '<h2>People enrichment</h2>';
		echo '<p><strong>' . number_format_i18n( $with_image ) . '</strong> / ' . number_format_i18n( $people_total ) . ' with portraits · <strong>' . number_format_i18n( $with_occ ) . '</strong> / ' . number_format_i18n( $people_total ) . ' with occupations';
		if ( ! empty( $sync['completed_at'] ) ) {
			echo ' · last enqueue pass ' . esc_html( (string) $sync['completed_at'] ) . ' UTC (' . (int) ( $sync['missing'] ?? 0 ) . ' missing, ' . (int) ( $sync['enqueued'] ?? 0 ) . ' queued then)';
		}
		echo '</p>';

		// A drained queue is not an empty backlog: report who still needs
		// enrichment even when nothing is in flight, so the gap is visible
		// here rather than inferred from a page that looks unfinished.
		$backlog = People_Sync::enrichment_backlog();
		$waiting = max( 0, (int) $backlog['missing'] - (int) $backlog['queued'] );
		echo '<p><strong>' . number_format_i18n( (int) $backlog['missing'] ) . '</strong> of ' . number_format_i18n( $people_total ) . ' published people still need enrichment · <strong>' . number_format_i18n( (int) $backlog['queued'] ) . '</strong> queued right now';
		if ( $waiting > 0 ) {
			echo ' · <strong>' . number_format_i18n( $waiting ) . '</strong> waiting for the next sweep — a drained queue is not an empty backlog';
		}
		echo '.</p>';

		// Every enrichment request in flight, with its person and due time.
		$rows = (array) $wpdb->get_results(			"SELECT q.id, q.status, q.attempts, q.next_attempt_at, q.last_error, q.created_at,
					p.ID AS post_id, p.post_title
				 FROM {$wpdb->prefix}obitleague_wiki_queue q
				 LEFT JOIN {$wpdb->posts} p ON p.ID = CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload, '$.post_id')) AS UNSIGNED)
				 WHERE q.request_kind = 'enrich_person' AND q.status IN ( 'pending', 'processing' )
				 ORDER BY q.next_attempt_at ASC LIMIT 50"
		);

		echo '<h3 style="margin-top:1.2em">Currently queued (' . count( $rows ) . ' shown)</h3>';
		if ( ! $rows ) {
			echo '<p class="description">Nothing waiting: every queued enrichment has finished or failed.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Person</th><th>State</th><th>Attempts</th><th>Next attempt (UTC)</th><th>Waiting because</th><th>Queued at</th></tr></thead><tbody>';
			$now = current_time( 'mysql', true );
			foreach ( $rows as $row ) {
				$title = '' !== (string) $row->post_title ? (string) $row->post_title : '(person no longer resolvable)';
				$edit  = $row->post_id ? get_edit_post_link( (int) $row->post_id, 'raw' ) : '';
				$waiting = $row->next_attempt_at > $now
					? 'pacing/pause until due'
					: 'next minute tick';
				if ( '' !== (string) $row->last_error ) {
					$waiting = 'retrying after: ' . (string) $row->last_error;
				}
				echo '<tr><td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</td><td>' . esc_html( (string) $row->status ) . '</td><td>' . (int) $row->attempts . '</td><td>' . esc_html( (string) $row->next_attempt_at ) . '</td><td>' . esc_html( wp_trim_words( $waiting, 12 ) ) . '</td><td>' . esc_html( (string) $row->created_at ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$sample = People_Sync::enrichment_backlog_sample( 50 );
		echo '<h3 style="margin-top:1.2em">Backlog — people still missing data (up to 50 shown, newest first)</h3>';
		if ( ! $sample ) {
			echo '<p class="description">Nothing outstanding: every published person has a portrait, occupations, a role and a resolved cause of death.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Person</th><th>Still missing</th><th>Queued</th><th>Last enriched (UTC)</th><th>Created</th></tr></thead><tbody>';
			foreach ( $sample as $row ) {
				$labels = People_Sync::missing_field_labels(
					array(
						'image'       => '' !== (string) $row->image,
						'occupations' => '' !== (string) $row->occupations,
						'role'        => '' !== (string) $row->role,
						'cause'       => '' !== (string) $row->cause,
					)
				);
				$edit  = get_edit_post_link( (int) $row->post_id, 'raw' );
				$title = '' !== (string) $row->post_title ? (string) $row->post_title : '(untitled)';
				echo '<tr><td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</td><td>' . esc_html( implode( ', ', $labels ) ) . '</td><td>' . ( (int) $row->queued ? 'yes' : 'no' ) . '</td><td>' . esc_html( '' !== (string) $row->enriched_at ? (string) $row->enriched_at : 'never' ) . '</td><td>' . esc_html( (string) $row->post_date ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
		wp_nonce_field( 'obitleague_sync_enqueue_people' );
		echo '<input type="hidden" name="action" value="obitleague_sync_enqueue_people" />';
		echo '<label for="ob-sync-limit" class="description" style="margin-right:6px">Scan up to</label> ';
		echo '<select id="ob-sync-limit" name="limit">';
		foreach ( array( 100, 400, 1000, 2000 ) as $n ) {
			echo '<option value="' . $n . '">' . number_format_i18n( $n ) . '</option>';
		}
		echo '</select> ';
		echo '<span class="description" style="margin-right:10px">people for missing data</span> ';
		submit_button( 'Enqueue missing people now', 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">The daily profile refresh runs this same scan automatically, so gaps self-heal even without a manual pass.</p>';
	}

	/** Discovery controls and pause state. */
	private static function render_discovery(): void {
		$status = Discovery_Service::status();
		$last   = is_array( $status['last_run'] ) ? $status['last_run'] : array();

		echo '<h2>Living-person discovery</h2>';
		echo '<p>';
		echo 'Last run: ' . esc_html( (string) ( $last['completed_at'] ?? 'never' ) ) . ' UTC · ' . esc_html( (string) ( $last['status'] ?? '—' ) ) . ' · queued ' . (int) ( $last['queued'] ?? 0 ) . ' · skipped ' . (int) ( $last['skipped'] ?? 0 );
		echo ' · manual batches this hour: ' . (int) $status['hourly_runs'] . '/' . (int) $status['hourly_runs_max'];
		if ( $status['pause_until'] > time() ) {
			echo ' · <span style="color:#b32d2e;font-weight:600">paused</span> until ' . esc_html( gmdate( 'Y-m-d H:i:s', $status['pause_until'] ) ) . ' UTC';
		}
		echo '</p>';
		if ( ! empty( $last['message'] ) ) {
			echo '<p class="description">' . esc_html( (string) $last['message'] ) . '</p>';
		}
		echo '<p>';
		if ( ! $status['hourly_limited'] ) {
			self::action_button( 'obitleague_sync_run_discovery', 'Run one discovery batch (50)' );
		} else {
			echo '<button type="button" class="button" disabled>Hourly manual limit reached — automatic drains unaffected</button>';
		}
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=obitleague-discovery' ) ) . '">Review candidates</a>';
		echo '</p>';
	}

	/** Death wire priming control and last-run detail. */
	private static function render_death_wire(): void {
		$last = Death_Wire::last_run();
		echo '<h2>Death wire</h2>';
		echo '<p>Last run: ' . esc_html( wp_json_encode( $last ) ?: 'never' ) . '</p>';
		echo '<p>';
		self::action_button( 'obitleague_sync_run_wire', 'Prime wire now (queue month pages + RSS sweep)' );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=obitleague-death-wire' ) ) . '">Open death wire</a>';
		echo '</p>';
	}

	/** RSS feed sources with per-source poll-now control. */
	private static function render_feeds(): void {
		global $wpdb;
		$sources = (array) $wpdb->get_results(
			"SELECT id, name, enabled, interval_minutes, last_poll_at, last_status, consecutive_failures
			 FROM {$wpdb->prefix}obitleague_sources ORDER BY enabled DESC, name ASC LIMIT 100"
		);
		echo '<h2>RSS feed sources</h2>';
		if ( ! $sources ) {
			echo '<p class="description">No feed sources configured.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Source</th><th>Enabled</th><th>Interval</th><th>Last poll (UTC)</th><th>Last status</th><th>Failures</th><th></th></tr></thead><tbody>';
		foreach ( $sources as $source ) {
			echo '<tr><td>' . esc_html( (string) $source->name ) . '</td><td>' . ( (int) $source->enabled ? 'yes' : 'no' ) . '</td><td>' . (int) $source->interval_minutes . ' min</td><td>' . esc_html( (string) ( $source->last_poll_at ?: 'never' ) ) . '</td><td>' . esc_html( (string) ( $source->last_status ?: '—' ) ) . '</td><td>' . (int) $source->consecutive_failures . '</td><td>';
			if ( (int) $source->enabled ) {
				self::action_button( 'obitleague_sync_poll_feed', 'Poll now', array( 'source_id' => (int) $source->id ), 'secondary small' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------ */
	/* Small helpers                                                       */
	/* ------------------------------------------------------------------ */

	/** One nonced admin-post form button. */
	private static function action_button( string $action, string $label, array $hidden = array(), string $class = 'secondary' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $hidden as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
	}

	private static function human_delay( int $seconds ): string {
		if ( $seconds < 90 ) {
			return $seconds . 's';
		}
		if ( $seconds < 5400 ) {
			return round( $seconds / 60 ) . ' min';
		}
		return round( $seconds / 3600, 1 ) . ' h';
	}
}

<?php
/**
 * Administrative dashboard for the death wire.
 *
 * Four tabs, one screen:
 *
 *   Overview — wire status, season totals and the season wordcloud.
 *   Stories  — the triage desk. Every parsed story opens a modal with its
 *              excerpt(s), a mini wordcloud for the article, extracted
 *              details (matched phrases, likelihood, wire outcome, record
 *              match) and the approve / dismiss / re-run actions.
 *   Phrases  — the monitored-phrase manager: view, add, exclude and
 *              re-enable phrases with per-phrase hit statistics.
 *   Sources  — RSS source management with per-source story statistics.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Admin_Death_Wire {

	private const CAP  = 'obitleague_review';
	private const PAGE = 'obitleague-death-wire';

	private const TABS = array( 'overview', 'stories', 'phrases', 'sources' );

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'wp_ajax_obitleague_death_wire_story_detail', array( self::class, 'ajax_story_detail' ) );
		add_action( 'wp_ajax_obitleague_death_wire_search_wikidata', array( self::class, 'ajax_search_wikidata' ) );
		add_action( 'wp_ajax_obitleague_death_wire_pair_story', array( self::class, 'handle_story_pair' ) );

		add_action( 'admin_post_obitleague_death_wire_run', array( self::class, 'handle_run' ) );
		add_action( 'admin_post_obitleague_death_wire_threshold', array( self::class, 'handle_threshold' ) );
		add_action( 'admin_post_obitleague_death_wire_source_add', array( self::class, 'handle_source_add' ) );
		add_action( 'admin_post_obitleague_death_wire_source_toggle', array( self::class, 'handle_source_toggle' ) );
		add_action( 'admin_post_obitleague_death_wire_source_delete', array( self::class, 'handle_source_delete' ) );
		add_action( 'admin_post_obitleague_death_wire_story_dismiss', array( self::class, 'handle_story_dismiss' ) );
		add_action( 'admin_post_obitleague_death_wire_story_publish', array( self::class, 'handle_story_publish' ) );
		add_action( 'admin_post_obitleague_death_wire_story_reprocess', array( self::class, 'handle_story_reprocess' ) );
		add_action( 'admin_post_obitleague_death_wire_phrase_add', array( self::class, 'handle_phrase_add' ) );
		add_action( 'admin_post_obitleague_death_wire_phrase_toggle', array( self::class, 'handle_phrase_toggle' ) );
		add_action( 'admin_post_obitleague_death_wire_phrase_remove', array( self::class, 'handle_phrase_remove' ) );
		add_action( 'admin_post_obitleague_death_wire_reclassify', array( self::class, 'handle_reclassify' ) );
		add_action( 'admin_post_obitleague_death_wire_pause', array( self::class, 'handle_discovery_pause' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'obitleague-review',
			__( 'Death wire', 'obitleague' ),
			__( 'Death wire', 'obitleague' ),
			self::CAP,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/** Modal + tab behaviour only loads on the wire screen. */
	public static function assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script(
			'obitleague-death-wire',
			OBITLEAGUE_DIR_URL . 'assets/admin-deathwire.js',
			array(),
			OBITLEAGUE_VERSION,
			true
		);
		wp_localize_script(
			'obitleague-death-wire',
			'obDeathWire',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'obitleague_death_wire_detail' ),
				'searchNonce' => wp_create_nonce( 'obitleague_death_wire_search' ),
				'pairNonce'   => wp_create_nonce( 'obitleague_death_wire_pair' ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                              */
	/* ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view the death wire.', 'obitleague' ) );
		}

		$tab = (string) ( $_GET['tab'] ?? 'overview' );
		if ( ! in_array( $tab, self::TABS, true ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap ob-dw"><h1>Obitleague — Death wire</h1>';
		self::notices();
		self::tab_nav( $tab );

		match ( $tab ) {
			'stories'  => self::render_stories(),
			'phrases'  => self::render_phrases(),
			'sources'  => self::render_sources(),
			default    => self::render_overview(),
		};

		echo '</div>';
	}

	private static function tab_nav( string $current ): void {
		$labels = array(
			'overview' => 'Overview',
			'stories'  => 'Stories',
			'phrases'  => 'Monitored phrases',
			'sources'  => 'Sources',
		);
		echo '<nav class="nav-tab-wrapper ob-dw__tabs">';
		foreach ( $labels as $slug => $label ) {
			$url = admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug );
			$active = $slug === $current ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/* ------------------------------ overview ------------------------- */

	private static function render_overview(): void {
		global $wpdb;
		$season   = Pick_Stats::season_in_play();
		$last_run = Death_Wire::last_run();
		$queue    = Wiki_Request_Queue::status();
		$pause    = (int) get_option( 'obitleague_discovery_pause_until', 0 );

		echo '<p>The wire reads Wikipedia’s <em>Deaths in ' . esc_html( (string) $season ) . '</em> list and your RSS sources, then hands anything death-shaped to the editors. It runs on an hourly schedule; use the button below when you want it now.</p>';

		self::stat_cards( self::season_stats() );

		echo '<h2>Run state</h2>';
		$months_done = (array) get_option( 'obitleague_death_wire_months_done', array() );
		$pending     = (int) ( $queue['counts']['pending'] ?? 0 );
		$failed      = (int) ( $queue['counts']['failed'] ?? 0 );
		echo '<p>Wikipedia list months covered: <strong>' . ( $months_done ? esc_html( implode( ', ', $months_done ) ) : 'none yet' ) . '</strong>';
		echo ' · Wikimedia queue: <strong>' . esc_html( (string) $pending ) . '</strong> pending, <strong>' . esc_html( (string) $failed ) . '</strong> failed';
		echo $pause > time()
			? ' · <strong>Wikimedia pause active</strong> until ' . esc_html( gmdate( 'Y-m-d H:i:s', $pause ) ) . ' UTC'
			: ' · Wikimedia pause clear';
		echo '</p>';
		if ( $last_run ) {
			echo '<p>Last wire run (' . esc_html( (string) ( $last_run['run'] ?? '?' ) ) . ' at ' . esc_html( (string) ( $last_run['run_at'] ?? '?' ) ) . ' UTC):</p>';
			echo '<p class="ob-dw__runstats">';
			foreach ( $last_run as $key => $value ) {
				if ( in_array( $key, array( 'run', 'run_at' ), true ) || (int) $value === 0 ) {
					continue;
				}
				echo '<span class="ob-dw__runstat"><strong>' . esc_html( (string) (int) $value ) . '</strong> ' . esc_html( str_replace( '_', ' ', (string) $key ) ) . '</span>';
			}
			echo '</p>';
		}
		$next = wp_next_scheduled( 'obitleague_death_wire_tick' );
		if ( $next ) {
			echo '<p>Next scheduled run: ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) ) . ' local time.</p>';
		}
		$wire_cursor = (int) get_option( 'obitleague_death_wire_item_cursor', 0 );
		echo '<p>RSS wire sweep position: item #' . esc_html( (string) $wire_cursor ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_death_wire_run' );
		echo '<input type="hidden" name="action" value="obitleague_death_wire_run" />';
		submit_button( 'Run the wire now', 'primary', 'submit', false );
		echo '</form>';

		self::render_pause_control();

		/* Auto-discard threshold: the adjustable automatic line. */
		$threshold = Death_Wire::discard_threshold();
		echo '<h2>Automatic discard threshold</h2>';
		echo '<p>Stories whose obituary likelihood sits below this line are deleted by the wire without human attention. Raise it for a quieter queue; lower it to catch marginal death signals. The line applies on the next wire sweep — deletion is permanent.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_death_wire_threshold' );
		echo '<input type="hidden" name="action" value="obitleague_death_wire_threshold" />';
		echo '<input type="number" name="discard_below" min="0" max="95" step="1" value="' . esc_attr( (string) $threshold ) . '" class="small-text" /> % ';
		submit_button( 'Save threshold', 'secondary', 'submit', false );
		echo '</form>';

		$stories = self::season_stories( 80 );
		$cloud   = self::wordcloud( $stories );
		if ( $cloud ) {
			echo '<h2>Season wordcloud — the language of this year’s feed</h2>';
			self::render_cloud( $cloud );
		}
	}

	/** The headline numbers: stories this season, by verdict and state. */
	private static function season_stats(): array {
		global $wpdb;
		$season_start = Pick_Stats::season_in_play() . '-01-01 00:00:00';
		$base         = "FROM {$wpdb->prefix}obitleague_feed_items i WHERE i.published_at >= %s";
		$signals      = \Obitleague\Domain\Feed_Classifier::DEATH_SIGNALS;
		$in           = implode( ',', array_fill( 0, count( $signals ), '%s' ) );
		$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base}", $season_start ) );
		$death   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base} AND i.classification IN ({$in})", array_merge( array( $season_start ), $signals ) ) );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base} AND i.classification IN ({$in}) AND i.wire_state = ''", array_merge( array( $season_start ), $signals ) ) );
		$attached = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base} AND i.wire_state = 'attached'", $season_start ) );
		$sources = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_sources WHERE enabled = 1" );
		return array(
			'Stories this season'   => $total,
			'Death signals'         => $death,
			'Awaiting decision'     => $pending,
			'Attached to records'   => $attached,
			'Active sources'        => $sources,
		);
	}

	private static function stat_cards( array $stats ): void {
		echo '<div class="ob-dw__cards">';
		foreach ( $stats as $label => $value ) {
			echo '<div class="ob-dw__card"><strong>' . esc_html( (string) $value ) . '</strong><span>' . esc_html( (string) $label ) . '</span></div>';
		}
		echo '</div>';
	}

	/** True while discovery and the shared Wikimedia queue are paused. */
	private static function discovery_paused(): bool {
		return \Obitleague\Modules\Discovery_Service::rate_limit_pause_until() > time();
	}

	/** Pause / resume discovery + the shared Wikimedia queue. */
	public static function handle_discovery_pause(): void {
		self::must( 'obitleague_death_wire_pause' );
		$pause_until = (int) ( $_POST['pause_until'] ?? 0 );
		if ( $pause_until > time() ) {
			update_option( 'obitleague_discovery_pause_until', $pause_until, false );
			wp_safe_redirect( self::back( 'Discovery and the Wikimedia queue are paused until ' . gmdate( 'Y-m-d H:i', $pause_until ) . ' UTC. Nothing queued is lost; wire checks wait too.' ) );
		} else {
			\Obitleague\Modules\Discovery_Service::clear_rate_limit_pause();
			wp_safe_redirect( self::back( 'Discovery and the Wikimedia queue are running again.' ) );
		}
		exit;
	}

	private static function render_pause_control(): void {
		$paused = self::discovery_paused();
		echo '<h2>Discovery</h2>';
		echo '<p>Catalogue Discovery and the wire\'s Wikipedia confirmation pass share the same Wikimedia request queue and the same pause. Pausing stops new queue processing entirely — nothing queued is lost, and pending wire checks resume when it lifts.</p>';
		if ( $paused ) {
			$until = \Obitleague\Modules\Discovery_Service::rate_limit_pause_until();
			echo '<p><strong>Paused until ' . esc_html( gmdate( 'Y-m-d H:i', $until ) ) . ' UTC.</strong></p>';
			self::action_button( 'obitleague_death_wire_pause', 'Resume discovery', 'secondary', array( 'pause_until' => 0 ) );
		} else {
			self::action_button( 'obitleague_death_wire_pause', 'Pause discovery (24h)', 'secondary', array( 'pause_until' => time() + DAY_IN_SECONDS ) );
			echo ' ';
			self::action_button( 'obitleague_death_wire_pause', 'Pause discovery (7d)', 'secondary', array( 'pause_until' => time() + 7 * DAY_IN_SECONDS ) );
		}
	}

	/* ------------------------------ stories -------------------------- */

	/**
	 * The story buckets and their WHERE fragments. The pending bucket is
	 * strictly death signals not yet swept: not_death stories with an empty
	 * wire_state are simply uninteresting and never shown as pending.
	 */
	private static function story_buckets(): array {
		$signals = \Obitleague\Domain\Feed_Classifier::DEATH_SIGNALS;
		$in      = implode( ',', array_fill( 0, count( $signals ), '%s' ) );
		return array(
			'pending'   => array(
				'label' => 'Awaiting decision',
				'where' => "i.wire_state = '' AND i.classification IN ({$in})",
				'params' => $signals,
			),
			'attached'  => array( 'label' => 'Attached', 'where' => "i.wire_state = 'attached'", 'params' => array() ),
			'check_queued' => array( 'label' => 'Queued for check', 'where' => "i.wire_state = 'check_queued'", 'params' => array() ),
			'identity_mismatch' => array( 'label' => 'Identity mismatch', 'where' => "i.wire_state = 'identity_mismatch'", 'params' => array() ),
			'search_deferred' => array( 'label' => 'Search deferred', 'where' => "i.wire_state = 'search_deferred'", 'params' => array() ),
			'all'       => array( 'label' => 'Everything', 'where' => '1=1', 'params' => array() ),
		);
	}

	private static function render_stories(): void {
		global $wpdb;
		$filter  = (string) ( $_GET['state'] ?? 'pending' );
		$buckets = self::story_buckets();
		if ( ! isset( $buckets[ $filter ] ) ) {
			$filter = 'pending';
		}
		$season_start = Pick_Stats::season_in_play() . '-01-01 00:00:00';

		// Live counts per bucket, from one grouped query.
		$signals = \Obitleague\Domain\Feed_Classifier::DEATH_SIGNALS;
		$in      = implode( ',', array_fill( 0, count( $signals ), '%s' ) );
		$state_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.wire_state, i.classification, COUNT(*) AS n
				 FROM {$wpdb->prefix}obitleague_feed_items i
				 WHERE i.published_at >= %s GROUP BY i.wire_state, i.classification",
				$season_start
			)
		);
		$counts = array_fill_keys( array_keys( $buckets ), 0 );
		foreach ( $state_rows as $row ) {
			$state = '' !== (string) $row->wire_state ? (string) $row->wire_state : '';
			$class = (string) $row->classification;
			$n     = (int) $row->n;
			if ( '' === $state ) {
				$counts['pending'] += in_array( $class, $signals, true ) ? $n : 0;
			} else {
				if ( isset( $counts[ $state ] ) ) {
					$counts[ $state ] += $n;
				}
			}
			$counts['all'] += $n;
		}

		echo '<div class="ob-dw__filters">';
		foreach ( $buckets as $slug => $bucket ) {
			$url    = admin_url( 'admin.php?page=' . self::PAGE . '&tab=stories&state=' . $slug );
			$active = $slug === $filter ? ' ob-dw__filter--active' : '';
			echo '<a class="ob-dw__filter' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( (string) $bucket['label'] ) . ' <strong>(' . (int) $counts[ $slug ] . ')</strong></a>';
		}
		echo '</div>';

		$bucket  = $buckets[ $filter ];
		$where   = 'i.published_at >= %s AND ' . $bucket['where'];
		$params  = array_merge( array( $season_start ), $bucket['params'] );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.title, i.url, i.published_at, i.retrieved_at, i.classification, i.classification_score, i.matched_cues, i.wire_state, s.name AS source_name
				 FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id
				 WHERE {$where}
				 ORDER BY i.classification_score DESC, i.id DESC LIMIT 100",
				$params
			)
		);

		echo '<p class="description">' . esc_html( (string) count( $rows ) ) . ' shown of ' . (int) $counts[ $filter ] . ' · sorted by obituary likelihood. Open any story for its excerpt, wordcloud and extracted details.</p>';
		echo '<div class="ob-admin-table-scroll"><table class="widefat striped ob-dw__storytable"><thead><tr><th>Parsed</th><th>Story</th><th>Source</th><th>Likelihood</th><th>State</th><th>Actions</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Nothing in this bucket.</td></tr>';
		}
		foreach ( $rows as $story ) {
			$likelihood = Death_Wire::likelihood_pct( (int) $story->classification_score );
			$state      = '' !== (string) $story->wire_state ? (string) $story->wire_state : 'pending';
			echo '<tr>';
			echo '<td>' . esc_html( substr( (string) $story->retrieved_at, 0, 16 ) ) . '</td>';
			echo '<td class="ob-dw__storytitle"><button type="button" class="ob-dw__open button-link" data-item="' . (int) $story->id . '">' . esc_html( wp_html_excerpt( (string) $story->title, 70, '…' ) ) . '</button></td>';
			echo '<td>' . esc_html( (string) $story->source_name ) . '</td>';
			echo '<td><span style="font-weight:700;color:' . esc_attr( self::likelihood_colour( $likelihood ) ) . '">' . esc_html( (string) $likelihood ) . '%</span></td>';
			echo '<td><span class="ob-dw__state ob-dw__state--' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span></td>';
			echo '<td style="white-space:nowrap">';
			if ( 'pending' === $state ) {
				self::action_button( 'obitleague_death_wire_story_publish', 'Publish', 'small primary', array( 'item' => (int) $story->id ) );
				echo ' ';
				self::action_button( 'obitleague_death_wire_story_dismiss', 'Dismiss', 'small', array( 'item' => (int) $story->id ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ------------------------------ phrases -------------------------- */

	private static function render_phrases(): void {
		$rows = \Obitleague\Domain\Wire_Phrases::all_rows();

		echo '<p>Monitored phrases drive the death-detection classifier. <strong>Strong</strong> phrases score a story up; <strong>dampeners</strong> pull follow-up genres down; <strong>exclusions</strong> force a story out entirely (hoaxes, fiction, anniversaries); <strong>attributions</strong> add a confidence bonus when the death is attributed to family, agent or representative. Adding or excluding a phrase takes effect on the next ingest — use <em>Reclassify stored stories</em> to re-run what is already here.</p>';

		echo '<h3>Add a phrase</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ob-dw__addphrase">';
		wp_nonce_field( 'obitleague_death_wire_phrase_add' );
		echo '<input type="hidden" name="action" value="obitleague_death_wire_phrase_add" />';
		echo '<input type="text" name="phrase" required maxlength="120" placeholder="died suddenly, aged" class="regular-text" />';
		echo '<select name="kind">';
		foreach ( array(
			\Obitleague\Domain\Wire_Phrases::KIND_STRONG  => 'Strong death phrase',
			\Obitleague\Domain\Wire_Phrases::KIND_SOFT    => 'Soft dampener',
			\Obitleague\Domain\Wire_Phrases::KIND_EXCLUDE => 'Hard exclusion',
			\Obitleague\Domain\Wire_Phrases::KIND_CONFIRM => 'Attribution bonus',
		) as $kind => $label ) {
			echo '<option value="' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( 'Add phrase', 'secondary', 'submit', false );
		echo '</form>';

		echo '<h3>Managed phrases (' . esc_html( (string) count( $rows ) ) . ')</h3>';
		if ( $rows ) {
			echo '<table class="widefat striped ob-dw__phrasetable"><thead><tr><th>Phrase</th><th>Kind</th><th>Weights (title/body)</th><th>Hits</th><th>Last hit</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$enabled = (int) $row['enabled'];
				$shadow  = '0000-00-00 00:00:00' === (string) $row['created_at'] || '' === (string) $row['created_at'];
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $row['phrase'] ) . '</code></td>';
				echo '<td>' . esc_html( (string) $row['kind'] ) . '</td>';
				echo '<td>' . (int) $row['weight_title'] . ' / ' . (int) $row['weight_body'] . '</td>';
				echo '<td>' . (int) $row['hits'] . '</td>';
				echo '<td>' . esc_html( '' !== (string) $row['last_hit_at'] ? substr( (string) $row['last_hit_at'], 0, 16 ) : '—' ) . '</td>';
				echo '<td>' . ( $enabled ? '<strong>active</strong>' : '<em>excluded</em>' ) . '</td>';
				echo '<td style="white-space:nowrap">';
				self::action_button( 'obitleague_death_wire_phrase_toggle', $enabled ? 'Exclude' : 'Re-enable', 'small', array( 'phrase' => (string) $row['phrase'] ) );
				echo ' ';
				self::action_button( 'obitleague_death_wire_phrase_remove', 'Delete', 'small link-delete', array( 'phrase' => (string) $row['phrase'] ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">No managed phrases yet — the classifier is running on its built-in tables alone.</p>';
		}

		echo '<h3>Built-in phrases</h3>';
		echo '<p class="description">The classifier’s defaults. Excluding one stores it as a disabled shadow — the merged table then drops it. The hit counts below are live this season.</p>';
		$season_counts = self::builtin_hit_counts();
		echo '<div class="ob-dw__builtin"><table class="widefat striped"><thead><tr><th>Phrase</th><th>Kind</th><th>Weights (title/body)</th><th>Hits this season</th><th>Actions</th></tr></thead><tbody>';
		foreach ( \Obitleague\Domain\Feed_Classifier::builtin_strong_phrases() as $phrase => $weights ) {
			self::builtin_row( (string) $phrase, 'strong', $weights, $season_counts );
		}
		foreach ( \Obitleague\Domain\Feed_Classifier::builtin_soft_negatives() as $phrase => $weights ) {
			self::builtin_row( (string) $phrase, 'soft', $weights, $season_counts );
		}
		foreach ( \Obitleague\Domain\Feed_Classifier::builtin_hard_exclusions() as $phrase ) {
			self::builtin_row( $phrase, 'exclude', array( 0, 0 ), $season_counts );
		}
		foreach ( \Obitleague\Domain\Feed_Classifier::builtin_confirmation_phrases() as $phrase ) {
			self::builtin_row( $phrase, 'confirm', array( 10, 10 ), $season_counts );
		}
		echo '</tbody></table></div>';

		echo '<h3>Reclassify</h3>';
		echo '<p>Stored stories keep the verdict they were ingested with. After changing phrases, re-run them through the current set.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Reclassify every stored story with the current phrase set? This can take a minute.\')">';
		wp_nonce_field( 'obitleague_death_wire_reclassify' );
		echo '<input type="hidden" name="action" value="obitleague_death_wire_reclassify" />';
		submit_button( 'Reclassify stored stories', 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function builtin_row( string $phrase, string $kind, array $weights, array $counts ): void {
		$excluded = self::phrase_is_excluded( $phrase );
		echo '<tr><td><code>' . esc_html( $phrase ) . '</code></td>';
		echo '<td>' . esc_html( $kind ) . '</td>';
		echo '<td>' . (int) $weights[0] . ' / ' . (int) $weights[1] . '</td>';
		echo '<td>' . (int) ( $counts[ $phrase ] ?? 0 ) . '</td>';
		echo '<td style="white-space:nowrap">';
		self::action_button( 'obitleague_death_wire_phrase_toggle', $excluded ? 'Re-enable' : 'Exclude', 'small', array( 'phrase' => $phrase ) );
		echo '</td></tr>';
	}

	private static function phrase_is_excluded( string $phrase ): bool {
		foreach ( \Obitleague\Domain\Wire_Phrases::all_rows() as $row ) {
			if ( (string) $row['phrase'] === mb_strtolower( $phrase ) && ! (int) $row['enabled'] ) {
				return true;
			}
		}
		return false;
	}

	/** Live per-phrase hit counts over this season's stored items. */
	private static function builtin_hit_counts(): array {
		global $wpdb;
		$season_start = Pick_Stats::season_in_play() . '-01-01 00:00:00';
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT matched_cues FROM {$wpdb->prefix}obitleague_feed_items WHERE matched_cues != '' AND retrieved_at >= %s",
				$season_start
			)
		);
		$counts = array();
		foreach ( $rows as $row ) {
			foreach ( explode( ', ', (string) $row->matched_cues ) as $cue ) {
				$cue = trim( $cue );
				if ( '' === $cue ) {
					continue;
				}
				$bare = ltrim( $cue, '−✕' );
				++$counts[ $bare ];
			}
		}
		return $counts;
	}

	/* ------------------------------ sources -------------------------- */

	private static function render_sources(): void {
		global $wpdb;
		$sources = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}obitleague_sources ORDER BY enabled DESC, id ASC" );

		echo '<p>Sources enter this list only through this screen: record the owner, check the feed loads, and pick a poll interval. Every story these feeds publish is parsed, classified and shown under Stories — death-shaped ones are handed to the review queue.</p>';

		if ( $sources ) {
			echo '<table class="widefat striped ob-dw__srctable"><thead><tr><th>Name</th><th>Feed URL</th><th>Every</th><th>Last poll</th><th>Status</th><th>Stories</th><th>Death signals</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $sources as $source ) {
				$stories = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_feed_items WHERE source_id = %d", (int) $source->id ) );
				$signals = \Obitleague\Domain\Feed_Classifier::DEATH_SIGNALS;
				$in      = implode( ',', array_fill( 0, count( $signals ), '%s' ) );
				$death   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_feed_items WHERE source_id = %d AND classification IN ({$in})", array_merge( array( (int) $source->id ), $signals ) ) );
				echo '<tr>';
				echo '<td>' . esc_html( (string) $source->name ) . ( (int) $source->enabled ? '' : ' <em>(paused)</em>' ) . '</td>';
				echo '<td><a href="' . esc_url( (string) $source->feed_url ) . '" rel="noopener" target="_blank">' . esc_html( wp_html_excerpt( (string) $source->feed_url, 48, '…' ) ) . '</a></td>';
				echo '<td>' . esc_html( (string) $source->interval_minutes ) . ' min</td>';
				echo '<td>' . esc_html( (string) ( $source->last_poll_at ?? '—' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $source->last_status ?? '—' ) ) . ( (int) $source->consecutive_failures > 0 ? ' (' . (int) $source->consecutive_failures . ' failures)' : '' ) . '</td>';
				echo '<td>' . (int) $stories . '</td>';
				echo '<td>' . (int) $death . '</td>';
				echo '<td style="white-space:nowrap">';
				self::action_button( 'obitleague_death_wire_source_toggle', (int) $source->enabled ? 'Pause' : 'Resume', 'small', array( 'source' => (int) $source->id ) );
				echo ' ';
				self::action_button( 'obitleague_death_wire_source_delete', 'Delete', 'small link-delete', array( 'source' => (int) $source->id ), 'Delete this source and every story parsed from it?' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p><strong>No sources yet.</strong> Add the first one below — obituary wires, national broadcasters, sports and music desks.</p>';
		}

		echo '<h3>Add a source</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_death_wire_source_add' );
		echo '<input type="hidden" name="action" value="obitleague_death_wire_source_add" />';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="ob-src-name">Name</label></th><td><input id="ob-src-name" name="name" type="text" class="regular-text" required maxlength="191" placeholder="BBC News — Obituaries" /></td></tr>';
		echo '<tr><th><label for="ob-src-url">Feed URL</label></th><td><input id="ob-src-url" name="feed_url" type="url" class="regular-text code" required placeholder="https://feeds.bbci.co.uk/news/obituaries/rss.xml" /></td></tr>';
		echo '<tr><th><label for="ob-src-interval">Poll every</label></th><td><select name="interval_minutes" id="ob-src-interval">';
		foreach ( array( 5, 15, 30, 60, 180, 360 ) as $minutes ) {
			echo '<option value="' . (int) $minutes . '">' . esc_html( (string) $minutes ) . ' minutes</option>';
		}
		echo '</select></td></tr>';
		echo '</table>';
		submit_button( 'Add source', 'primary', 'submit', false );
		echo '</form>';
	}

	/* ------------------------------------------------------------------ */
	/* Story detail (AJAX modal payload)                                   */
	/* ------------------------------------------------------------------ */

	/** The full story panel rendered for the modal. */
	public static function ajax_story_detail(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		check_ajax_referer( 'obitleague_death_wire_detail', 'nonce' );
		$item_id = (int) ( $_POST['item'] ?? 0 );
		if ( $item_id < 1 ) {
			wp_send_json_error( array( 'message' => 'Unknown story.' ), 400 );
		}
		global $wpdb;
		$story = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.id, i.title, i.url, i.description, i.guid, i.published_at, i.retrieved_at, i.classification, i.classification_score, i.matched_cues, i.wire_state, s.name AS source_name, s.feed_url
				 FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id
				 WHERE i.id = %d",
				$item_id
			)
		);
		if ( ! $story ) {
			wp_send_json_error( array( 'message' => 'Unknown story.' ), 404 );
		}

		$likelihood = Death_Wire::likelihood_pct( (int) $story->classification_score );
		$match      = Death_Wire::story_match( (string) $story->title );

		ob_start();
		echo '<div class="ob-dw-modal__head">';
		echo '<h2>' . esc_html( (string) $story->title ) . '</h2>';
		echo '<p class="ob-dw-modal__meta">';
		echo '<span class="ob-dw__state ob-dw__state--' . esc_attr( '' !== (string) $story->wire_state ? (string) $story->wire_state : 'pending' ) . '">' . esc_html( '' !== (string) $story->wire_state ? (string) $story->wire_state : 'pending' ) . '</span> · ';
		echo esc_html( (string) $story->source_name ) . ' · likelihood <strong>' . esc_html( (string) $likelihood ) . '%</strong>';
		if ( '' !== (string) $story->published_at ) {
			echo ' · published ' . esc_html( substr( (string) $story->published_at, 0, 16 ) ) . ' UTC';
		}
		echo '</p></div>';

		echo '<div class="ob-dw-modal__body">';

		/* Excerpt(s): the RSS description, and the fetched article text when stored. */
		$excerpt = trim( (string) $story->description );
		if ( '' !== $excerpt ) {
			echo '<h3>Excerpt</h3>';
			echo '<blockquote class="ob-dw-modal__excerpt">' . esc_html( wp_html_excerpt( $excerpt, 900, '…' ) ) . '</blockquote>';
		} else {
			echo '<p class="description">This feed item carried no summary text.</p>';
		}

		/* Article facts: cached article body → detected facts + phrases.
		 * The wordcloud is built inside Story_Facts but never shown — it is
		 * an extraction tool; the modal outputs only what it detected. */
		$article = '' !== (string) $story->url ? Death_Wire::article_text( (string) $story->url ) : '';
		$facts   = \Obitleague\Domain\Story_Facts::parse( (string) $story->title, $excerpt . "\n" . $article );
		if ( '' !== $article ) {
			echo '<h3>Article</h3>';
			echo '<blockquote class="ob-dw-modal__excerpt">' . esc_html( wp_html_excerpt( $article, 1200, '…' ) ) . '</blockquote>';
		}

		/* Recurring phrases — the piece's stock wording. */
		if ( $facts['phrases'] ) {
			echo '<h3>Recurring phrases</h3>';
			echo '<p class="ob-dw__cloud ob-dw__cloud--mini">';
			foreach ( $facts['phrases'] as $phrase => $count ) {
				echo '<span style="margin-right:10px">' . esc_html( (string) $phrase ) . ' <small>' . (int) $count . '</small></span>';
			}
			echo '</p>';
		}

		/* Extracted details. */
		echo '<h3>Extracted details</h3>';
		echo '<dl class="ob-dw-modal__details">';
		self::detail_row( 'Classifier verdict', (string) $story->classification );
		self::detail_row( 'Score', (string) $story->classification_score );
		$cues = '' !== (string) $story->matched_cues ? (string) $story->matched_cues : '';
		self::detail_row( 'Matched phrases', '' !== $cues ? $cues : '—' );
		self::detail_row( 'Wire outcome', '' !== (string) $story->wire_state ? (string) $story->wire_state : 'not swept yet' );
		self::detail_row( 'Headline subject', (string) ( Death_Wire::match_group( (string) $story->title ) ?? '—' ) );
		if ( '' !== $facts['birth'] ) {
			self::detail_row( 'Date of birth (from article)', $facts['birth'] );
		}
		if ( '' !== $facts['death'] ) {
			self::detail_row( 'Date of death (from article)', $facts['death'] );
		}
		if ( '' !== $facts['age'] ) {
			self::detail_row( 'Age (from article)', $facts['age'] );
		}
		if ( '' !== $facts['cause'] ) {
			self::detail_row( 'Cause of death (from article)', $facts['cause'] );
		}
		echo '<dt>Record match</dt><dd>';
		if ( $match ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $match['post_id'] ) ) . '">' . esc_html( $match['name'] ) . '</a> (record #' . (int) $match['post_id'] . ')';
		} else {
			echo '<em>no record matched yet</em>';
		}
		echo '</dd>';

		if ( '' !== (string) $story->url ) {
			echo '<dt>Original article</dt><dd><a href="' . esc_url( (string) $story->url ) . '" rel="noopener" target="_blank">' . esc_html( wp_html_excerpt( (string) $story->url, 90, '…' ) ) . '</a></dd>';
		}
		echo '</dl>';

		$state = (string) $story->wire_state;

		/* Pairing widget for identity_mismatch stories.
		 * An editor can search Wikidata with the headline name pre-filled,
		 * pick the right person, and the record is created automatically.
		 * If nothing matches, the story can still be dismissed below. */
		if ( 'identity_mismatch' === $state ) {
			$group = (string) ( Death_Wire::match_group( (string) $story->title ) ?? '' );
			echo '<h3>Pair to a person</h3>';
			echo '<div class="ob-dw-modal__pairing" data-item="' . (int) $story->id . '" data-group="' . esc_attr( $group ) . '">';
			echo '<p class="description">The wire could not confirm the headline name against a Wikipedia article. Search Wikidata for the correct person and pick the match to create the record automatically.</p>';
			echo '<div class="ob-dw-pair__searchrow">';
			echo '<input type="text" class="ob-dw-pair__term" placeholder="Search Wikidata…" value="' . esc_attr( $group ) . '" maxlength="100" size="40" />';
			echo '<button type="button" class="ob-dw-pair__search button button-primary">Search</button>';
			echo '</div>';
			echo '<div class="ob-dw-pair__results" style="display:none"><p class="ob-dw-pair__loading">Searching…</p></div>';
			echo '<div class="ob-dw-pair__selected" style="display:none"></div>';
			echo '<div class="ob-dw-pair__actions" style="display:none">';
			echo '<button type="button" class="ob-dw-pair__confirm button button-primary">Pair selected person</button>';
			echo '<button type="button" class="ob-dw-pair__clear button link-secondary">Clear selection</button>';
			echo '</div>';
			echo '</div>';
		}

		/* Lead biography — the one-glance summary of who this is about. */
		if ( '' !== $facts['bio'] ) {
			echo '<h3>Biography (from the article)</h3>';
			echo '<p class="ob-dw-modal__bio">' . esc_html( $facts['bio'] ) . '</p>';
		}

		echo '</div>';

		/* Actions row. */
		echo '<div class="ob-dw-modal__actions">';
		if ( 'attached' !== $state && 'check_queued' !== $state ) {
			self::action_button( 'obitleague_death_wire_story_publish', 'Publish (run wire match)', 'primary small', array( 'item' => $item_id ) );
			echo ' ';
		}
		if ( '' !== $state ) {
			self::action_button( 'obitleague_death_wire_story_reprocess', 'Re-run the wire match', 'small', array( 'item' => $item_id ) );
			echo ' ';
		}
		if ( '' !== $story->url && 'attached' !== $state ) {
			self::action_button( 'obitleague_death_wire_story_dismiss', 'Delete story', 'small', array( 'item' => $item_id ), 'Delete this story outright? It cannot be recovered.' );
		}
		if ( '' !== (string) $story->url ) {
			echo ' <a class="button small" href="' . esc_url( (string) $story->url ) . '" rel="noopener" target="_blank">Open original ↗</a>';
		}
		echo '</div>';

		wp_send_json_success( array( 'html' => (string) ob_get_clean(), 'title' => (string) $story->title ) );
	}

	private static function detail_row( string $label, string $value ): void {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
	}

	/**
	 * Aggregate wordcloud for the season overview, minus stopwords. Small
	 * N is fine: this is a season dashboard, not a corpus tool; story
	 * modals build their richer cloud through Story_Facts instead.
	 *
	 * @param object[] $stories Each with ->title and optionally ->description.
	 * @param int      $cap     Maximum words returned.
	 * @return array<string,int>
	 */
	private static function wordcloud( array $stories, int $cap = 40 ): array {
		$stop = array_fill_keys( array(
			'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'from', 'by',
			'as', 'is', 'are', 'was', 'were', 'be', 'been', 'his', 'her', 'their', 'its', 'who',
			'after', 'before', 'over', 'into', 'up', 'out', 'off', 'down', 'how', 'why', 'what',
			'new', 'says', 'said', 'amid', 'news', 'video', 'live', 'latest',
			'it', 'he', 'she', 'they', 'we', 'you', 'i', 'not', 'no', 'but', 'if', 'so', 'that', 'this',
			'will', 'would', 'can', 'could', 'has', 'have', 'had', 'do', 'does', 'did', 'than', 'then',
			'more', 'most', 'about', 'against', 'during', 'between', 'through', 'year', 'years', 'first',
			'among', 'being', 'under', 'while', 'just', 'also', 'get', 'one', 'two', 'three', 'us', 'uk',
		), true );
		$counts = array();
		foreach ( $stories as $story ) {
			$text = (string) $story->title . ' ' . (string) ( $story->description ?? '' );
			foreach ( preg_split( "/[^a-z0-9']+/i", mb_strtolower( $text ) ) ?: array() as $word ) {
				if ( mb_strlen( $word ) < 3 || isset( $stop[ $word ] ) || is_numeric( $word ) ) {
					continue;
				}
				++$counts[ $word ];
			}
		}
		arsort( $counts );
		return array_slice( $counts, 0, $cap, true );
	}

	/** One wordcloud, rendered either full-size or mini (modal). */
	private static function render_cloud( array $cloud, bool $mini = false ): void {
		$max = (int) reset( $cloud );
		$class = $mini ? 'ob-dw__cloud ob-dw__cloud--mini' : 'ob-dw__cloud';
		echo '<p class="' . esc_attr( $class ) . '">';
		foreach ( $cloud as $word => $count ) {
			$scale = $max > 0 ? max( 0.75, min( 2.1, 0.75 + (float) $count / $max ) ) : 0.75;
			$shade = $count === $max ? 'var(--obad-ink, #1a1a1a)' : 'var(--ob-muted, #727272)';
			echo '<span style="font-size:' . esc_attr( (string) round( $scale, 2 ) ) . 'em;color:' . esc_attr( $shade ) . ';margin-right:10px">' . esc_html( (string) $word ) . ' <small>' . (int) $count . '</small></span>';
		}
		echo '</p>';
	}

	/* ------------------------------------------------------------------ */
	/* Form plumbing                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The tab the editor is looking at. The modal renders its actions inside an
	 * admin-ajax response, where the page query string is not present — the JS
	 * forwards it in the POST body — so read POST first, then GET.
	 */
	private static function current_tab(): string {
		$tab = (string) ( $_POST['tab'] ?? $_GET['tab'] ?? '' );
		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	/** The state filter the editor is looking at, or '' when unfiltered. */
	private static function current_state(): string {
		return (string) ( $_POST['state'] ?? $_GET['state'] ?? '' );
	}

	/** One nonce-checked POST button rendered inline. Preserves the tab and state filters across the redirect. */
	private static function action_button( string $action, string $label, string $class, array $fields = array(), string $confirm = '' ): void {
		$onsubmit = '' !== $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . $onsubmit . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::current_tab() ) . '" />';
		$state = self::current_state();
		if ( '' !== $state ) {
			echo '<input type="hidden" name="state" value="' . esc_attr( $state ) . '" />';
		}
		foreach ( $fields as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		submit_button( $label, $class, 'submit', false );
		echo '</form>';
	}

	private static function notices(): void {
		if ( isset( $_GET['notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) ) . '</p></div>';
		}
	}

	private static function back( string $notice, string $error = '' ): string {
		$tab  = (string) ( $_POST['tab'] ?? '' );
		$state = (string) ( $_POST['state'] ?? '' );
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		if ( in_array( $tab, self::TABS, true ) ) {
			$url .= '&tab=' . $tab;
		}
		if ( '' !== $state ) {
			$url .= '&state=' . rawurlencode( $state );
		}
		if ( '' !== $notice ) {
			$url = add_query_arg( 'notice', rawurlencode( $notice ), $url );
		}
		if ( '' !== $error ) {
			$url = add_query_arg( 'error', rawurlencode( $error ), $url );
		}
		return $url;
	}

	private static function must( string $nonce_action, string $cap = self::CAP ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'obitleague' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/* ------------------------------------------------------------------ */
	/* Handlers: wire + stories                                            */
	/* ------------------------------------------------------------------ */

	public static function handle_run(): void {
		self::must( 'obitleague_death_wire_run' );
		Death_Wire::run();
		Wiki_Request_Queue::process( 2 );
		wp_safe_redirect( self::back( 'The wire has been queued: the Wikipedia list pass and the RSS sweep are running through the Wikimedia queue.' ) );
		exit;
	}

	public static function handle_threshold(): void {
		self::must( 'obitleague_death_wire_threshold', 'manage_options' );
		$value = (int) ( $_POST['discard_below'] ?? Death_Wire::DISCARD_BELOW );
		Death_Wire::set_discard_threshold( $value );
		wp_safe_redirect( self::back( 'Auto-discard threshold set to ' . Death_Wire::discard_threshold() . '%.' ) );
		exit;
	}

	public static function handle_story_dismiss(): void {
		self::must( 'obitleague_death_wire_story_dismiss' );
		$item_id = (int) ( $_POST['item'] ?? 0 );
		if ( $item_id < 1 ) {
			wp_safe_redirect( self::back( '', 'Unknown story.' ) );
			exit;
		}
		Death_Wire::dismiss_story( $item_id );
		wp_safe_redirect( self::back( 'Story deleted.' ) );
		exit;
	}

	public static function handle_story_publish(): void {
		self::must( 'obitleague_death_wire_story_publish' );
		self::publish_story( (int) ( $_POST['item'] ?? 0 ) );
	}

	public static function handle_story_reprocess(): void {
		self::must( 'obitleague_death_wire_story_reprocess' );
		self::publish_story( (int) ( $_POST['item'] ?? 0 ) );
	}

	/* Search the pairing widget */
	public static function ajax_search_wikidata(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}
		check_ajax_referer( 'obitleague_death_wire_search', 'nonce' );
		$term = trim( (string) ( $_POST['term'] ?? '' ) );
		if ( '' === $term ) {
			wp_send_json_success( array( 'results' => array() ) );
		}
		$results = Wikidata_Search_Service::obituary_search( $term );
		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ), 502 );
		}
		wp_send_json_success( array( 'results' => $results ) );
	}

	/* Pair a story to a selected Wikidata item */
	public static function handle_story_pair(): void {
		self::must( 'obitleague_death_wire_pair' );
		$item_id = (int) ( $_POST['item'] ?? 0 );
		$qid     = strtoupper( trim( (string) ( $_POST['qid'] ?? '' ) ) );
		if ( $item_id < 1 || '' === $qid ) {
			wp_safe_redirect( self::back( '', 'No Wikidata selection.' ) );
			exit;
		}
		$outcome = Death_Wire::pair_story_to_qid( $item_id, $qid );
		$notice  = 'attached' === $outcome
			? 'Story paired to ' . $qid . ' and attached to the new record.'
			: 'Could not pair that Wikidata item.';
		wp_safe_redirect( self::back( $notice ) );
		exit;
	}

	private static function publish_story( int $item_id ): void {
		if ( $item_id < 1 ) {
			wp_safe_redirect( self::back( '', 'Unknown story.' ) );
			exit;
		}
		$outcome = Death_Wire::process_story( $item_id );
		$story   = self::story_title( $item_id );
		$match   = Death_Wire::story_match( $story );
		$notice  = match ( $outcome ) {
			'attached', 'duplicate' => 'Published: source attached to ' . ( $match ? $match['name'] : '' ) . "'s obituary page.",
			'check_queued'          => 'Published to the wire: the Wikipedia confirmation pass is queued' . ( $match ? ' for ' . $match['name'] : '' ) . '.',
			'no_anchor'             => 'No Wikipedia article found for the name in that headline — nothing to publish against.',
			'search_deferred'       => 'The Wikipedia search was unavailable — the story is parked and will be retried.',
			'no_name'               => 'That headline has no usable name group to match.',
			default                 => 'Wire outcome: ' . $outcome . '.',
		};
		wp_safe_redirect( self::back( $notice ) );
		exit;
	}

	private static function story_title( int $item_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}obitleague_feed_items WHERE id = %d", $item_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Handlers: phrases                                                   */
	/* ------------------------------------------------------------------ */

	public static function handle_phrase_add(): void {
		self::must( 'obitleague_death_wire_phrase_add' );
		$kind = (string) ( $_POST['kind'] ?? '' );
		$result = \Obitleague\Domain\Wire_Phrases::add(
			sanitize_text_field( wp_unslash( (string) ( $_POST['phrase'] ?? '' ) ) ),
			$kind,
			null,
			null,
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::back( '', $result->get_error_message() ) );
			exit;
		}
		wp_safe_redirect( self::back( 'Phrase added: ' . (string) $result . ' (' . $kind . ').' ) );
		exit;
	}

	public static function handle_phrase_toggle(): void {
		self::must( 'obitleague_death_wire_phrase_toggle' );
		$phrase  = sanitize_text_field( wp_unslash( (string) ( $_POST['phrase'] ?? '' ) ) );
		$enabled = \Obitleague\Domain\Wire_Phrases::set_enabled( $phrase, false );
		// Toggle means: flip. If it was already stored disabled, re-enable.
		$currently = self::phrase_row( $phrase );
		if ( $currently && ! (int) $currently['enabled'] ) {
			$enabled = \Obitleague\Domain\Wire_Phrases::set_enabled( $phrase, true );
		} else {
			$enabled = \Obitleague\Domain\Wire_Phrases::set_enabled( $phrase, false );
		}
		wp_safe_redirect( self::back( $enabled ? 'Phrase re-enabled.' : 'Phrase excluded.' ) );
		exit;
	}

	private static function phrase_row( string $phrase ): ?array {
		foreach ( \Obitleague\Domain\Wire_Phrases::all_rows() as $row ) {
			if ( (string) $row['phrase'] === mb_strtolower( $phrase ) ) {
				return $row;
			}
		}
		return null;
	}

	public static function handle_phrase_remove(): void {
		self::must( 'obitleague_death_wire_phrase_remove' );
		$phrase = sanitize_text_field( wp_unslash( (string) ( $_POST['phrase'] ?? '' ) ) );
		\Obitleague\Domain\Wire_Phrases::remove( $phrase );
		wp_safe_redirect( self::back( 'Phrase removed from the managed set.' ) );
		exit;
	}

	public static function handle_reclassify(): void {
		self::must( 'obitleague_death_wire_reclassify' );
		$count = self::reclassify_stored();
		wp_safe_redirect( self::back( $count . ' stored stor' . ( 1 === $count ? 'y' : 'ies' ) . ' reclassified with the current phrase set.' ) );
		exit;
	}

	/**
	 * Re-run the current merged phrase set over every stored story. The
	 * web handler caps the pass so the request returns; larger backlogs
	 * belong to the WP-CLI command.
	 */
	private static function reclassify_stored( int $limit = 2000 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_feed_items';
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, source_id, title, description, classification, classification_score FROM {$table} ORDER BY id ASC LIMIT %d", $limit )
		);
		$sources = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, name, feed_url FROM {$wpdb->prefix}obitleague_sources" ) as $s ) {
			$sources[ (int) $s->id ] = $s;
		}
		$changed = 0;
		foreach ( $rows as $row ) {
			$source  = $sources[ (int) $row->source_id ] ?? null;
			$verdict = \Obitleague\Domain\Wire_Phrases::classify(
				(string) $row->title,
				(string) ( $row->description ?? '' ),
				array(
					'source_name' => (string) ( $source->name ?? '' ),
					'source_url'  => (string) ( $source->feed_url ?? '' ),
				)
			);
			\Obitleague\Domain\Wire_Phrases::note_hits( (array) $verdict['matched'], (array) $verdict['negative'], (array) $verdict['excluded'] );
			if ( $verdict['classification'] === (string) $row->classification && (int) $verdict['score'] === (int) $row->classification_score ) {
				continue;
			}
			$cues = array_merge(
				(array) $verdict['matched'],
				array_map( static fn ( string $cue ): string => '−' . $cue, (array) $verdict['negative'] ),
				array_map( static fn ( string $cue ): string => '✕' . $cue, (array) $verdict['excluded'] )
			);
			$wpdb->update(
				$table,
				array(
					'classification'       => $verdict['classification'],
					'classification_score' => (int) $verdict['score'],
					'matched_cues'         => mb_substr( implode( ', ', $cues ), 0, 191 ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			++$changed;
		}
		return $changed;
	}

	/* ------------------------------------------------------------------ */
	/* Handlers: sources                                                   */
	/* ------------------------------------------------------------------ */

	public static function handle_source_add(): void {
		self::must( 'obitleague_death_wire_source_add', 'manage_options' );
		global $wpdb;

		$name     = mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ), 0, 191 );
		$feed_url = esc_url_raw( wp_unslash( (string) ( $_POST['feed_url'] ?? '' ) ) );
		$interval = in_array( (int) ( $_POST['interval_minutes'] ?? 15 ), array( 5, 15, 30, 60, 180, 360 ), true ) ? (int) $_POST['interval_minutes'] : 15;

		if ( '' === $name || '' === $feed_url || ! str_starts_with( $feed_url, 'https://' ) ) {
			wp_safe_redirect( self::back( '', 'A source needs a name and an https feed URL.' ) );
			exit;
		}
		$slug = sanitize_title( $name );
		if ( ! $slug ) {
			wp_safe_redirect( self::back( '', 'Could not derive a slug from that name.' ) );
			exit;
		}

		$probe = wp_remote_get( $feed_url, array( 'timeout' => 12 ) );
		if ( is_wp_error( $probe ) || 200 !== (int) wp_remote_retrieve_response_code( $probe ) ) {
			wp_safe_redirect( self::back( '', 'The feed could not be reached (' . ( is_wp_error( $probe ) ? $probe->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $probe ) ) . '). Fix the URL before adding.' ) );
			exit;
		}
		$body = (string) wp_remote_retrieve_body( $probe );
		if ( ! str_contains( $body, '<item' ) && ! str_contains( $body, '<entry' ) ) {
			wp_safe_redirect( self::back( '', 'That URL answered but did not look like an RSS or Atom feed. Fix the URL before adding.' ) );
			exit;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'obitleague_sources',
			array(
				'slug'              => $slug,
				'name'              => $name,
				'feed_url'          => $feed_url,
				'interval_minutes'  => $interval,
				'enabled'           => 1,
			),
			array( '%s', '%s', '%s', '%d', '%d' )
		);
		if ( ! $inserted ) {
			wp_safe_redirect( self::back( '', 'A source with that slug already exists.' ) );
			exit;
		}
		wp_safe_redirect( self::back( 'Source added. The next poll (within its interval) will parse it; stories appear under Stories.' ) );
		exit;
	}

	public static function handle_source_toggle(): void {
		self::must( 'obitleague_death_wire_source_toggle', 'manage_options' );
		global $wpdb;
		$source = self::source_from_request();
		if ( ! $source ) {
			wp_safe_redirect( self::back( '', 'Unknown source.' ) );
			exit;
		}
		$wpdb->update(
			$wpdb->prefix . 'obitleague_sources',
			array( 'enabled' => (int) ! (int) $source->enabled ),
			array( 'id' => (int) $source->id ),
			array( '%d' ),
			array( '%d' )
		);
		wp_safe_redirect( self::back( (int) $source->enabled ? 'Source paused.' : 'Source resumed.' ) );
		exit;
	}

	public static function handle_source_delete(): void {
		self::must( 'obitleague_death_wire_source_delete', 'manage_options' );
		global $wpdb;
		$source = self::source_from_request();
		if ( ! $source ) {
			wp_safe_redirect( self::back( '', 'Unknown source.' ) );
			exit;
		}
		$wpdb->delete( $wpdb->prefix . 'obitleague_feed_items', array( 'source_id' => (int) $source->id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'obitleague_sources', array( 'id' => (int) $source->id ), array( '%d' ) );
		wp_safe_redirect( self::back( 'Source and its parsed stories deleted.' ) );
		exit;
	}

	private static function source_from_request(): ?object {
		global $wpdb;
		$id = (int) ( $_POST['source'] ?? 0 );
		if ( $id < 1 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}obitleague_sources WHERE id = %d", $id ) );
		return $row ?: null;
	}

	/* ------------------------------------------------------------------ */

	private static function likelihood_colour( int $likelihood ): string {
		if ( $likelihood >= 70 ) {
			return 'var(--obad-ink, #1a1a1a)';
		}
		if ( $likelihood >= 45 ) {
			return '#616161';
		}
		return '#8f8f8f';
	}

	/** Season stories for the wordcloud (title + description). */
	private static function season_stories( int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.title, i.description FROM {$wpdb->prefix}obitleague_feed_items i
				 WHERE i.published_at >= %s ORDER BY i.classification_score DESC, i.id DESC LIMIT %d",
				Pick_Stats::season_in_play() . '-01-01 00:00:00',
				$limit
			)
		);
	}
}

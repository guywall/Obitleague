<?php
/**
 * Administrative dashboard for the death wire.
 *
 * One screen answering three questions: is the wire running, what did it
 * parse, and what should we watch next. It shows the Wikipedia list-pass and
 * RSS wire status with a run-now button, lets an administrator add or pause
 * RSS sources (the only place sources can be created), and lists parsed feed
 * stories with their obituary likelihood — classifier score as a 0–100%
 * gauge — matched cues and the season's title wordcloud.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Admin_Death_Wire {

	private const CAP  = 'obitleague_review';
	private const PAGE = 'obitleague-death-wire';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_obitleague_death_wire_run', array( self::class, 'handle_run' ) );
		add_action( 'admin_post_obitleague_death_wire_source_add', array( self::class, 'handle_source_add' ) );
		add_action( 'admin_post_obitleague_death_wire_source_toggle', array( self::class, 'handle_source_toggle' ) );
		add_action( 'admin_post_obitleague_death_wire_source_delete', array( self::class, 'handle_source_delete' ) );
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

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view the death wire.', 'obitleague' ) );
		}

		global $wpdb;
		$season   = Pick_Stats::season_in_play();
		$last_run = Death_Wire::last_run();
		$queue    = Wiki_Request_Queue::status();
		$pause    = (int) get_option( 'obitleague_discovery_pause_until', 0 );
		$sources  = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}obitleague_sources ORDER BY enabled DESC, id ASC" );

		echo '<div class="wrap"><h1>Obitleague — Death wire</h1>';
		self::notices();

		echo '<p>The wire reads Wikipedia’s <em>Deaths in ' . esc_html( (string) $season ) . '</em> list and your RSS sources, then hands anything death-shaped to the editors. It runs on an hourly schedule; use the button below when you want it now. A Wikimedia rate-limit pause delays queued requests, never loses them.</p>';

		/* ---------------- status strip ---------------- */
		$months_done = (array) get_option( 'obitleague_death_wire_months_done', array() );
		$pending     = (int) ( $queue['counts']['pending'] ?? 0 );
		$failed      = (int) ( $queue['counts']['failed'] ?? 0 );
		echo '<h2>Status</h2>';
		echo '<p>Wikipedia list months covered: <strong>' . ( $months_done ? esc_html( implode( ', ', $months_done ) ) : 'none yet' ) . '</strong>';
		echo ' · Wikimedia queue: <strong>' . esc_html( (string) $pending ) . '</strong> pending, <strong>' . esc_html( (string) $failed ) . '</strong> failed';
		echo $pause > time()
			? ' · <strong>Wikimedia pause active</strong> until ' . esc_html( gmdate( 'Y-m-d H:i:s', $pause ) ) . ' UTC'
			: ' · Wikimedia pause clear';
		echo '</p>';
		if ( $last_run ) {
			echo '<p>Last wire run: <code>' . esc_html( (string) wp_json_encode( $last_run ) ) . '</code></p>';
		}
		$next = wp_next_scheduled( 'obitleague_death_wire_tick' );
		if ( $next ) {
			echo '<p>Next scheduled run: ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) ) . ' local time.</p>';
		}
		$wire_cursor = (int) get_option( 'obitleague_death_wire_item_cursor', 0 );
		echo '<p>RSS wire sweep position: item #' . esc_html( (string) $wire_cursor ) . ' · unprocessed candidate stories: <strong>' . esc_html( (string) self::unprocessed_count() ) . '</strong></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_death_wire_run' );
		echo '<input type="hidden" name="action" value="obitleague_death_wire_run" />';
		submit_button( 'Run the wire now', 'primary', 'submit', false );
		echo '</form>';

		/* ---------------- sources ---------------- */
		echo '<h2>RSS sources</h2>';
		echo '<p>Sources enter this list only through this screen: record the owner, check the feed loads, and pick a poll interval. Every story these feeds publish is parsed, classified and shown below — death-shaped ones are handed to the review queue.</p>';
		if ( $sources ) {
			echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Feed URL</th><th>Every</th><th>Last poll</th><th>Status</th><th>Stories</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $sources as $source ) {
				$stories = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_feed_items WHERE source_id = %d", (int) $source->id ) );
				echo '<tr>';
				echo '<td>' . esc_html( (string) $source->name ) . ( (int) $source->enabled ? '' : ' <em>(paused)</em>' ) . '</td>';
				echo '<td><a href="' . esc_url( (string) $source->feed_url ) . '" rel="noopener" target="_blank">' . esc_html( wp_html_excerpt( (string) $source->feed_url, 48, '…' ) ) . '</a></td>';
				echo '<td>' . esc_html( (string) $source->interval_minutes ) . ' min</td>';
				echo '<td>' . esc_html( (string) ( $source->last_poll_at ?? '—' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $source->last_status ?? '—' ) ) . ( (int) $source->consecutive_failures > 0 ? ' (' . (int) $source->consecutive_failures . ' failures)' : '' ) . '</td>';
				echo '<td>' . esc_html( (string) $stories ) . '</td>';
				echo '<td>';
				$base = admin_url( 'admin-post.php' );
				echo '<form method="post" action="' . esc_url( $base ) . '" style="display:inline">';
				wp_nonce_field( 'obitleague_death_wire_source_toggle' );
				echo '<input type="hidden" name="action" value="obitleague_death_wire_source_toggle" /><input type="hidden" name="source" value="' . (int) $source->id . '" />';
				submit_button( (int) $source->enabled ? 'Pause' : 'Resume', 'small', 'submit', false );
				echo '</form> ';
				echo '<form method="post" action="' . esc_url( $base ) . '" style="display:inline">';
				wp_nonce_field( 'obitleague_death_wire_source_delete' );
				echo '<input type="hidden" name="action" value="obitleague_death_wire_source_delete" /><input type="hidden" name="source" value="' . (int) $source->id . '" />';
				submit_button( 'Delete', 'small link-delete', 'submit', false );
				echo '</form>';
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

		/* ---------------- parsed stories ---------------- */
		$stories = self::recent_stories();
		echo '<h2>Parsed stories (' . esc_html( (string) self::season_story_count() ) . ' this season)</h2>';
		$cloud = self::wordcloud( $stories );
		if ( $cloud ) {
			echo '<h3>Season wordcloud — the language of this year’s feed</h3>';
			echo '<p style="line-height:2.2">';
			$max = (int) reset( $cloud );
			foreach ( $cloud as $word => $count ) {
				$scale = $max > 0 ? max( 0.75, min( 2.1, 0.75 + (float) $count / $max ) ) : 0.75;
				$shade = $count === $max ? 'var(--ob-accent-deep, #0a3d2c)' : 'var(--ob-muted, #6b7280)';
				echo '<span style="font-size:' . esc_attr( (string) round( $scale, 2 ) ) . 'em;color:' . esc_attr( $shade ) . ';margin-right:10px">' . esc_html( (string) $word ) . ' <small>' . (int) $count . '</small></span>';
			}
			echo '</p>';
		}
		echo '<table class="widefat striped"><thead><tr><th>Parsed</th><th>Story</th><th>Source</th><th>Obituary likelihood</th><th>Matched cues</th><th>Wire outcome</th></tr></thead><tbody>';
		if ( ! $stories ) {
			echo '<tr><td colspan="6">Nothing parsed yet — add a source above and wait for the next poll.</td></tr>';
		}
		foreach ( $stories as $story ) {
			$likelihood = self::likelihood( (int) $story->classification_score );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $story->retrieved_at ) . '</td>';
			$title = (string) $story->title;
			echo '<td>';
			if ( '' !== (string) $story->url ) {
				echo '<a href="' . esc_url( (string) $story->url ) . '" rel="noopener" target="_blank">' . esc_html( wp_html_excerpt( $title, 70, '…' ) ) . '</a>';
			} else {
				echo esc_html( wp_html_excerpt( $title, 70, '…' ) );
			}
			echo '</td>';
			echo '<td>' . esc_html( (string) $story->source_name ) . '</td>';
			echo '<td><span style="font-weight:700;color:' . esc_attr( self::likelihood_colour( $likelihood ) ) . '">' . esc_html( (string) $likelihood ) . '%</span></td>';
			echo '<td>' . esc_html( '' !== (string) $story->matched_cues ? (string) $story->matched_cues : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $story->wire_state ? (string) $story->wire_state : 'not swept yet' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Data helpers                                                        */
	/* ------------------------------------------------------------------ */

	private static function unprocessed_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id AND s.enabled = 1
				 WHERE i.wire_state = '' AND i.classification = %s",
				\Obitleague\Domain\Feed_Classifier::CANDIDATE
			)
		);
	}

	private static function season_story_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id
				 WHERE i.published_at >= %s",
				Pick_Stats::season_in_play() . '-01-01 00:00:00'
			)
		);
	}

	/** @return object[] Newest parsed stories with source names. */
	private static function recent_stories(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			"SELECT i.id, i.title, i.url, i.published_at, i.retrieved_at, i.classification, i.classification_score, i.matched_cues, i.wire_state, s.name AS source_name
			 FROM {$wpdb->prefix}obitleague_feed_items i
			 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id
			 ORDER BY i.id DESC LIMIT 60"
		);
	}

	/** Classifier score as a positive 0–100 likelihood gauge for display. */
	private static function likelihood( int $score ): int {
		return (int) max( 0, min( 100, round( 50 + $score * 6 ) ) );
	}

	private static function likelihood_colour( int $likelihood ): string {
		if ( $likelihood >= 70 ) {
			return 'var(--ob-accent-deep, #0a3d2c)';
		}
		if ( $likelihood >= 45 ) {
			return '#7a5c12';
		}
		return '#8a8f98';
	}

	/**
	 * Aggregate wordcloud over parsed titles, minus stopwords. Small N is
	 * fine: this is a season dashboard, not a corpus tool.
	 *
	 * @param object[] $stories
	 * @return array<string,int> word => count, most frequent first, capped.
	 */
	private static function wordcloud( array $stories ): array {
		$stop = array_fill_keys( array(
			'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'from', 'by',
			'as', 'is', 'are', 'was', 'were', 'be', 'been', 'his', 'her', 'their', 'its', 'who',
			'after', 'before', 'over', 'into', 'up', 'out', 'off', 'down', 'how', 'why', 'what',
			'new', 'says', 'said', 'amid', 'amid', 'amid', 'news', 'video', 'live', 'latest',
			'it', 'he', 'she', 'they', 'we', 'you', 'i', 'not', 'no', 'but', 'if', 'so', 'that', 'this',
			'will', 'would', 'can', 'could', 'has', 'have', 'had', 'do', 'does', 'did', 'than', 'then',
			'more', 'most', 'about', 'against', 'during', 'between', 'through', 'year', 'years', 'first',
			'among', 'being', 'under', 'while', 'just', 'also', 'get', 'one', 'two', 'three', 'us', 'uk',
		), true );
		$counts = array();
		foreach ( $stories as $story ) {
			foreach ( preg_split( '/[^a-z0-9\']+/i', mb_strtolower( (string) $story->title ) ) ?: array() as $word ) {
				if ( mb_strlen( $word ) < 3 || isset( $stop[ $word ] ) || is_numeric( $word ) ) {
					continue;
				}
				++$counts[ $word ];
			}
		}
		arsort( $counts );
		return array_slice( $counts, 0, 40, true );
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                            */
	/* ------------------------------------------------------------------ */

	public static function handle_run(): void {
		self::must( 'obitleague_death_wire_run' );
		Death_Wire::run();
		Wiki_Request_Queue::process( 2 );
		wp_safe_redirect( self::back( 'The wire has been queued: the Wikipedia list pass and the RSS sweep are running through the Wikimedia queue.' ) );
		exit;
	}

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
		wp_safe_redirect( self::back( 'Source added. The next poll (within its interval) will parse it; stories appear under Parsed stories.' ) );
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
		wp_safe_redirect( self::back( ( (int) $source->enabled ? 'Source paused.' : 'Source resumed.' ) ) );
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

	/* ------------------------------------------------------------------ */
	/* Internals                                                           */
	/* ------------------------------------------------------------------ */

	private static function must( string $nonce_action, string $cap = self::CAP ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'obitleague' ) );
		}
		check_admin_referer( $nonce_action );
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

	private static function back( string $notice, string $error = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		if ( '' !== $notice ) {
			$url = add_query_arg( 'notice', rawurlencode( $notice ), $url );
		}
		if ( '' !== $error ) {
			$url = add_query_arg( 'error', rawurlencode( $error ), $url );
		}
		return $url;
	}

	private static function notices(): void {
		if ( isset( $_GET['notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) ) . '</p></div>';
		}
	}
}

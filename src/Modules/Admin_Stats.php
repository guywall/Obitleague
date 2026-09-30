<?php
/**
 * Read-only operational statistics dashboard for site administrators.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Admin_Stats {

	private const CAP = 'manage_options';
	private const PAGE = 'obitleague-admin-stats';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'obitleague-review',
			__( 'Site statistics', 'obitleague' ),
			__( 'Statistics', 'obitleague' ),
			self::CAP,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view site statistics.', 'obitleague' ) );
		}

		global $wpdb;
		$prefix = $wpdb->prefix;
		$season = League_Service::current_season();
		$users_total = (int) count_users()['total_users'];
		$synthetic_users = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", 'obitleague_demo_account', '1' )
		);
		$stats = array(
			'WordPress users' => $users_total,
			'Leagues' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_leagues" ),
			'Team entries' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_entries" ),
			'Submitted teams' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}obitleague_entries WHERE state = %s", 'submitted' ) ),
			'Draft teams' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}obitleague_entries WHERE state = %s", 'draft' ) ),
			'League memberships' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_league_members" ),
			'Catalogue people' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", Catalogue::POST_TYPE, 'publish' ) ),
			'Approved people' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s WHERE p.post_type = %s AND p.post_status = %s", 'obit_eligibility', 'approved', Catalogue::POST_TYPE, 'publish' ) ),
			'Confirmed death records' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'obit_death_date' AND pm.meta_value <> '' WHERE p.post_type = 'obit_person' AND p.post_status = 'publish'" ),
			'Submitted pick slots' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_entry_picks p JOIN {$prefix}obitleague_entry_revisions r ON r.id = p.revision_id AND r.kind = 'submitted'" ),
			'Positive award ledger rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_awards WHERE award_delta > 0" ),
			'Points awarded' => (int) $wpdb->get_var( "SELECT COALESCE(SUM(award_delta), 0) FROM {$prefix}obitleague_awards WHERE award_delta > 0" ),
			'Synthetic accounts' => $synthetic_users,
		);

		echo '<div class="wrap"><h1>Obitleague — site statistics</h1>';
		echo '<p>Operational snapshot · current entry season ' . (int) $season . ' · generated ' . esc_html( current_time( 'mysql' ) ) . ' · <a href="' . esc_url( admin_url( 'admin.php?page=obitleague-data-sources' ) ) . '">Data sources &amp; sync controls</a></p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;max-width:1250px">';
		foreach ( $stats as $label => $value ) {
			echo '<div class="card" style="max-width:none;margin:0;padding:16px"><p style="margin:0;color:#686868">' . esc_html( $label ) . '</p><p style="margin:8px 0 0;font-size:26px;font-weight:600">' . esc_html( number_format_i18n( $value ) ) . '</p></div>';
		}
		echo '</div>';

		self::render_seasons( $prefix );
		self::render_sync_status( $prefix );
		self::render_feed_status( $prefix );
		self::render_scoring_status( $prefix );
		echo '</div>';
	}

	private static function render_seasons( string $prefix ): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT l.id, l.name, l.season, l.state,
				(SELECT COUNT(*) FROM {$prefix}obitleague_league_members m WHERE m.league_id = l.id) AS members,
				(SELECT COUNT(*) FROM {$prefix}obitleague_entries e WHERE e.league_id = l.id) AS teams,
				(SELECT COUNT(*) FROM {$prefix}obitleague_entries e WHERE e.league_id = l.id AND e.state = 'submitted') AS submitted,
				(SELECT MAX(g.published_at) FROM {$prefix}obitleague_standings_generations g WHERE g.league_id = l.id AND g.season = l.season AND g.is_current = 1) AS standings_updated
			 FROM {$prefix}obitleague_leagues l ORDER BY l.season DESC, l.id DESC LIMIT 100"
		);
		echo '<h2 style="margin-top:2em">Leagues and team counts</h2>';
		if ( ! $rows ) {
			echo '<p>No leagues yet.</p>';
			return;
		}
		echo '<div style="max-width:1250px;overflow-x:auto"><table class="widefat striped"><thead><tr><th>League</th><th>Season</th><th>Status</th><th>Members</th><th>Entries</th><th>Submitted</th><th>Standings last published (UTC)</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg( array( 'page' => 'obitleague-game-admin', 'league' => (int) $row->id ), admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $row->name ) . '</a> <small>#' . (int) $row->id . '</small></td><td>' . (int) $row->season . '</td><td>' . esc_html( (string) $row->state ) . '</td><td>' . (int) $row->members . '</td><td>' . (int) $row->teams . '</td><td>' . (int) $row->submitted . '</td><td>' . esc_html( (string) ( $row->standings_updated ?: 'Not published' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_sync_status( string $prefix ): void {
		global $wpdb;
		$sync = get_option( 'obitleague_people_sync_last_run', array() );
		$people_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", Catalogue::POST_TYPE, 'publish' ) );
		$with_image = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> '' WHERE p.post_type = %s AND p.post_status = %s", People_Sync::META_IMAGE_URL, Catalogue::POST_TYPE, 'publish' ) );
		$with_occupations = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> '' WHERE p.post_type = %s AND p.post_status = %s", People_Sync::META_OCCUPATIONS, Catalogue::POST_TYPE, 'publish' ) );
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} img ON img.post_id = p.ID AND img.meta_key = %s AND img.meta_value <> ''
				 LEFT JOIN {$wpdb->postmeta} occ ON occ.post_id = p.ID AND occ.meta_key = %s AND occ.meta_value <> ''
				 WHERE p.post_type = %s AND p.post_status = %s AND (img.post_id IS NULL OR occ.post_id IS NULL)",
				People_Sync::META_IMAGE_URL,
				People_Sync::META_OCCUPATIONS,
				Catalogue::POST_TYPE,
				'publish'
			)
		);

		echo '<h2 style="margin-top:2em">People enrichment sync</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		self::table_row( 'Last recorded sync', (string) ( $sync['completed_at'] ?? 'No recorded run yet' ) );
		self::table_row( 'Last run checked / updated', isset( $sync['checked'], $sync['updated'] ) ? number_format_i18n( (int) $sync['checked'] ) . ' checked / ' . number_format_i18n( (int) $sync['updated'] ) . ' field updates' : 'No recorded run yet' );
		self::table_row( 'Published people with portraits', number_format_i18n( $with_image ) . ' / ' . number_format_i18n( $people_total ) );
		self::table_row( 'Published people with occupations', number_format_i18n( $with_occupations ) . ' / ' . number_format_i18n( $people_total ) );
		self::table_row( 'People missing one or both enrichment fields', number_format_i18n( $pending ) );
		echo '</tbody></table><p class="description">Sync metrics are recorded by the portrait/occupation sync command or job. Older runs before metrics were added cannot be reconstructed.</p>';
	}

	private static function render_feed_status( string $prefix ): void {
		global $wpdb;
		$source_table = $prefix . 'obitleague_sources';
		$sources = $wpdb->get_results( "SELECT id, name, enabled, interval_minutes, last_poll_at, last_status, consecutive_failures FROM {$source_table} ORDER BY enabled DESC, last_poll_at DESC, name ASC LIMIT 100" );
		$feed_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_feed_items" );
		$candidates = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}obitleague_feed_items WHERE classification = %s", 'candidate' ) );
		$pending_reviews = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}obitleague_review_cases WHERE state = %s", 'pending' ) );

		echo '<h2 style="margin-top:2em">Feed and review pipeline</h2><p><strong>' . number_format_i18n( $feed_items ) . '</strong> stored feed items · <strong>' . number_format_i18n( $candidates ) . '</strong> candidates · <strong>' . number_format_i18n( $pending_reviews ) . '</strong> pending review cases.</p>';
		if ( ! $sources ) {
			echo '<p>No feed sources configured.</p>';
			return;
		}
		echo '<div style="max-width:1100px;overflow-x:auto"><table class="widefat striped"><thead><tr><th>Source</th><th>Enabled</th><th>Interval</th><th>Last poll (UTC)</th><th>Last status</th><th>Consecutive failures</th></tr></thead><tbody>';
		foreach ( $sources as $source ) {
			echo '<tr><td>' . esc_html( (string) $source->name ) . '</td><td>' . ( (int) $source->enabled ? 'Yes' : 'No' ) . '</td><td>' . (int) $source->interval_minutes . ' min</td><td>' . esc_html( (string) ( $source->last_poll_at ?: 'Never' ) ) . '</td><td>' . esc_html( (string) ( $source->last_status ?: '—' ) ) . '</td><td>' . (int) $source->consecutive_failures . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_scoring_status( string $prefix ): void {
		global $wpdb;
		$counts = array(
			'Current standings generations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_standings_generations WHERE is_current = 1" ),
			'Pending outbox events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_outbox WHERE processed_at IS NULL" ),
			'Approved events' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}obitleague_events WHERE approved_at IS NOT NULL AND retracted_at IS NULL" ),
			'Open review cases' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}obitleague_review_cases WHERE state = %s", 'pending' ) ),
		);
		echo '<h2 style="margin-top:2em">Processing status</h2><table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $counts as $label => $value ) {
			self::table_row( $label, number_format_i18n( $value ) );
		}
		echo '</tbody></table>';
	}

	private static function table_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}

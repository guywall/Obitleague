<?php
/**
 * Administrator tools for leagues, memberships, teams and picks.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Entry_Rules;

final class Admin_Game {

	private const CAP = 'manage_options';
	private const PAGE = 'obitleague-game-admin';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_obitleague_admin_create_league', array( self::class, 'create_league' ) );
		add_action( 'admin_post_obitleague_admin_update_league', array( self::class, 'update_league' ) );
		add_action( 'admin_post_obitleague_admin_update_member', array( self::class, 'update_member' ) );
		add_action( 'admin_post_obitleague_admin_create_team', array( self::class, 'create_team' ) );
		add_action( 'admin_post_obitleague_admin_save_team', array( self::class, 'save_team' ) );
		add_action( 'admin_post_obitleague_admin_delete_team', array( self::class, 'delete_team' ) );
		add_action( 'admin_post_obitleague_admin_rebuild', array( self::class, 'rebuild_standings' ) );
		add_action( 'admin_post_obitleague_admin_delete_league', array( self::class, 'delete_league' ) );
		add_action( 'admin_post_obitleague_admin_toggle_league_hidden', array( self::class, 'toggle_league_hidden' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_assets' ) );
	}

	/** Add horizontal scroll containment for data-heavy admin tables. */
	public static function admin_assets( string $hook_suffix ): void {
		if ( 'obitleague_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}
		wp_register_style( 'obitleague-admin-game', false, array(), OBITLEAGUE_VERSION );
		wp_enqueue_style( 'obitleague-admin-game' );
		wp_add_inline_style(
			'obitleague-admin-game',
			'.ob-admin-table-scroll{max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch;margin:12px 0 20px}.ob-admin-table-scroll>table{min-width:620px}'
		);
		wp_add_inline_script(
			'common',
			"(function(){document.querySelectorAll('form[data-ob-confirm]').forEach(function(f){f.addEventListener('submit',function(e){if(!window.confirm(f.getAttribute('data-ob-confirm'))){e.preventDefault();}});});})();"
		);
	}

	public static function menu(): void {
		add_submenu_page(
			'obitleague-review',
			__( 'Leagues, teams and picks', 'obitleague' ),
			__( 'Leagues & teams', 'obitleague' ),
			self::CAP,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to administer leagues and teams.', 'obitleague' ) );
		}

		$league_id = isset( $_GET['league'] ) ? absint( $_GET['league'] ) : 0;
		$entry_id  = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		echo '<div class="wrap"><h1>Obitleague — leagues &amp; teams</h1>';
		if ( isset( $_GET['notice'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) ) . '</p></div>';
		}

		if ( $entry_id && $league_id ) {
			self::render_team( $league_id, $entry_id );
		} elseif ( $league_id ) {
			self::render_league( $league_id );
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	private static function render_overview(): void {
		global $wpdb;		$hidden_column = Public_Scope::LEAGUE_COLUMN;
		$leagues = $wpdb->get_results(
			'SELECT l.id, l.name, l.season, l.state, l.is_main, l.' . $hidden_column . ' AS is_hidden, l.owner_user_id, l.created_at,
				(SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_league_members m WHERE m.league_id = l.id) AS member_count,
				(SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_entries e WHERE e.league_id = l.id) AS team_count
			FROM ' . $wpdb->prefix . 'obitleague_leagues l ORDER BY l.id DESC LIMIT 200'
		);

		echo '<h2>Leagues</h2>';
		if ( $leagues ) {
			echo '<p class="description">Hiding a league removes it from every public surface — the front-page league cards, the league directory and the sitemap. Nothing is deleted, and hidden leagues stay manageable here.</p>';
			echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>League</th><th>Season</th><th>State</th><th>Visibility</th><th>Owner</th><th>Members</th><th>Teams</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $leagues as $league ) {
				$owner = get_userdata( (int) $league->owner_user_id );				$url = self::url( array( 'league' => (int) $league->id ) );
				$is_main = (int) $league->is_main === 1;
				$is_hidden = (int) $league->is_hidden === 1;
				echo '<tr><td><strong>' . esc_html( (string) $league->name ) . '</strong> <small>#' . (int) $league->id . '</small>' . ( $is_main ? ' <span class="ob-admin-pill">main league</span>' : '' ) . '</td>';
				echo '<td>' . (int) $league->season . '</td><td>' . esc_html( (string) $league->state ) . '</td>';
				echo '<td>' . ( $is_hidden ? '<span class="ob-admin-pill">hidden</span>' : 'Public' ) . '</td>';
				echo '<td>' . esc_html( $owner ? (string) $owner->display_name : 'User #' . (int) $league->owner_user_id ) . '</td>';
				echo '<td>' . (int) $league->member_count . '</td><td>' . (int) $league->team_count . '</td>';
				echo '<td><a class="button" href="' . esc_url( $url ) . '">Manage</a> ';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
				wp_nonce_field( 'obitleague_admin_toggle_league_hidden' );
				echo '<input type="hidden" name="action" value="obitleague_admin_toggle_league_hidden" />';
				echo '<input type="hidden" name="league_id" value="' . (int) $league->id . '" />';
				echo '<input type="hidden" name="hide" value="' . ( $is_hidden ? '0' : '1' ) . '" />';
				echo '<button type="submit" class="button button-secondary">' . ( $is_hidden ? 'Restore to public' : 'Hide from public' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No leagues have been created.</p>';
		}

		echo '<h2 style="margin-top:2em">Create a league</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_admin_create_league' );
		echo '<input type="hidden" name="action" value="obitleague_admin_create_league" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ob-admin-league-name">League name</label></th><td><input id="ob-admin-league-name" name="name" type="text" class="regular-text" maxlength="120" required /></td></tr>';
		echo '<tr><th><label for="ob-admin-league-season">Season</label></th><td><input id="ob-admin-league-season" name="season" type="number" min="2000" max="2200" value="' . (int) League_Service::current_season() . '" required /></td></tr>';
		echo '<tr><th><label for="ob-admin-league-owner">Owner</label></th><td><select id="ob-admin-league-owner" name="owner_user_id" required><option value="">Select a user…</option>';
		foreach ( get_users( array( 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 1000, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) ) as $user ) {
			printf( '<option value="%1$d">%2$s (%3$s)</option>', (int) $user->ID, esc_html( (string) $user->display_name ), esc_html( (string) $user->user_email ) );
		}
		echo '</select><p class="description">The owner is added as the first league member.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Create league' );
		echo '</form>';
		self::render_audit();
	}

	private static function render_league( int $league_id ): void {
		global $wpdb;
		$league = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
		if ( ! $league ) {
			echo '<p>League not found. <a href="' . esc_url( self::url() ) . '">Back to leagues</a></p>';
			return;
		}
		$owner = get_userdata( (int) $league->owner_user_id );
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; All leagues</a></p>';
		echo '<h2>' . esc_html( (string) $league->name ) . ' <small>#' . (int) $league->id . '</small></h2>';
		echo '<p>Season ' . (int) $league->season . ' · ' . esc_html( (string) $league->state ) . ' · Owner: ' . esc_html( $owner ? (string) $owner->display_name : 'User #' . (int) $league->owner_user_id ) . '</p>';

		echo '<h3>League settings</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_admin_update_league_' . $league_id );
		echo '<input type="hidden" name="action" value="obitleague_admin_update_league" /><input type="hidden" name="league_id" value="' . $league_id . '" />';
		echo '<label>Name <input name="name" type="text" class="regular-text" maxlength="120" value="' . esc_attr( (string) $league->name ) . '" required /></label> &nbsp;';
		echo '<label>Owner <select name="owner_user_id" required>';
		foreach ( get_users( array( 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 1000, 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
			printf( '<option value="%1$d"%3$s>%2$s</option>', (int) $user->ID, esc_html( (string) $user->display_name ), selected( (int) $league->owner_user_id, (int) $user->ID, false ) );
		}
		echo '</select></label> &nbsp;';
		echo '<label>State <select name="state"><option value="open"' . selected( (string) $league->state, 'open', false ) . '>Open to new members</option><option value="closed"' . selected( (string) $league->state, 'closed', false ) . '>Closed</option></select></label> &nbsp;';
		submit_button( 'Save league', 'secondary', 'submit', false );
		echo '</form>';

		$members = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.*, u.display_name, u.user_email FROM ' . $wpdb->prefix . 'obitleague_league_members m
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = m.user_id WHERE m.league_id = %d ORDER BY m.id ASC',
				$league_id
			)
		);		echo '<h3>Members (' . count( $members ) . ')</h3>';
		if ( $members ) {
			echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>Player</th><th>Status</th><th>League owner</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $members as $member ) {
				$is_owner = (int) $member->user_id === (int) $league->owner_user_id;
				echo '<tr><td>' . esc_html( $member->display_name ?: 'User #' . (int) $member->user_id ) . ' <small>' . esc_html( (string) $member->user_email ) . '</small></td><td>' . esc_html( (string) $member->status ) . '</td><td>' . ( $is_owner ? 'Yes' : '—' ) . '</td><td>';
				self::member_form( $league_id, (int) $member->user_id, $is_owner );
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No members yet.</p>';
		}

		$entries = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.*, u.display_name FROM ' . $wpdb->prefix . 'obitleague_entries e
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = e.user_id WHERE e.league_id = %d ORDER BY e.id DESC LIMIT 500',
				$league_id
			)
		);
		echo '<h3>Teams / entries (' . count( $entries ) . ')</h3>';
		self::render_create_team_form( $league_id );
		if ( $entries ) {
			echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>Team</th><th>Player</th><th>Season</th><th>State</th><th>Latest picks</th><th></th></tr></thead><tbody>';
			foreach ( $entries as $entry ) {
				$revision = self::latest_revision( (int) $entry->id, (string) $entry->state );
				$picks    = $revision ? Entry_Service::revision_picks( (int) $revision->id ) : array();
				$names    = array();
				foreach ( $picks as $pick ) {
					$names[] = self::person_name( (string) $pick->person_uuid );
				}
				$url = self::url( array( 'league' => $league_id, 'entry' => (int) $entry->id ) );
				echo '<tr><td>' . esc_html( $entry->team_name ?: 'Team #' . (int) $entry->id ) . '</td><td>' . esc_html( $entry->display_name ?: 'User #' . (int) $entry->user_id ) . '</td><td>' . (int) $entry->season . '</td><td>' . esc_html( (string) $entry->state ) . '</td><td>' . esc_html( $names ? implode( ', ', $names ) : 'No picks saved' ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">View / edit picks</a></td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No teams/entries yet.</p>';
		}

		echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-top:1em">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_admin_rebuild_' . $league_id );
		echo '<input type="hidden" name="action" value="obitleague_admin_rebuild" /><input type="hidden" name="league_id" value="' . $league_id . '" />';
		submit_button( 'Rebuild standings for this league', 'secondary', 'submit', false );
		echo '</form>';
		if ( ! (int) $league->is_main ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'obitleague_admin_delete_league_' . $league_id );
			echo '<input type="hidden" name="action" value="obitleague_admin_delete_league" /><input type="hidden" name="league_id" value="' . $league_id . '" />';
			echo '<p class="description">Type the league name exactly to confirm. This deletes the league, its members, every team and all their awards.</p>';
			echo '<input type="text" name="confirm_text" autocomplete="off" placeholder="' . esc_attr( (string) $league->name ) . '" />';
			submit_button( 'Delete this league', 'delete', 'submit', false );
			echo '</form>';
		} else {
			echo '<p class="description">The main league cannot be deleted; the overall game depends on it.</p>';
		}
		echo '</div>';
		self::render_audit( 'league', $league_id );
	}

	private static function member_form( int $league_id, int $user_id, bool $is_owner ): void {
		echo '<form style="display:inline-flex;gap:6px;align-items:center" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_admin_update_member_' . $league_id . '_' . $user_id );
		echo '<input type="hidden" name="action" value="obitleague_admin_update_member" /><input type="hidden" name="league_id" value="' . $league_id . '" /><input type="hidden" name="user_id" value="' . $user_id . '" />';
		echo '<select name="member_action"><option value="status">Set status…</option><option value="member">Member</option><option value="spectator">Spectator</option>' . ( $is_owner ? '' : '<option value="remove">Remove from league</option>' ) . '</select>';
		if ( ! $is_owner ) {
			echo '<input name="reason" type="text" placeholder="Reason (required to remove)" class="regular-text" style="width:190px" />';
		}
		submit_button( 'Apply', 'small', 'submit', false );
		echo '</form>';
	}

	private static function render_team( int $league_id, int $entry_id ): void {
		global $wpdb;
		$league = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
		$entry  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d AND league_id = %d', $entry_id, $league_id ) );
		if ( ! $league || ! $entry ) {
			echo '<p>League or team not found. <a href="' . esc_url( self::url( array( 'league' => $league_id ) ) ) . '">Back</a></p>';
			return;
		}
		$user     = get_userdata( (int) $entry->user_id );
		$revision = self::latest_revision( $entry_id, (string) $entry->state );
		$picks    = $revision ? Entry_Service::revision_picks( (int) $revision->id ) : array();
		$selected = array_fill( 0, 10, '' );
		foreach ( $picks as $pick ) {
			$slot = (int) $pick->slot - 1;
			if ( isset( $selected[ $slot ] ) ) {
				$selected[ $slot ] = (string) $pick->person_uuid;
			}
		}
		$people = self::approved_people( $selected, (int) $entry->season );

		echo '<p><a href="' . esc_url( self::url( array( 'league' => $league_id ) ) ) . '">&larr; ' . esc_html( (string) $league->name ) . '</a></p>';
		echo '<h2>' . esc_html( $entry->team_name ?: 'Team #' . $entry_id ) . ' — managed by ' . esc_html( $user ? (string) $user->display_name : 'User #' . (int) $entry->user_id ) . '</h2>';
		echo '<p>Season ' . (int) $entry->season . ' · state: <strong>' . esc_html( (string) $entry->state ) . '</strong> · version ' . (int) $entry->expected_version . '</p>';
		if ( $revision ) {
			echo '<p>Current revision #' . (int) $revision->id . ' (' . esc_html( (string) $revision->kind ) . ')' . ( ! empty( $revision->submitted_at ) ? ' · submitted ' . esc_html( (string) $revision->submitted_at ) : '' ) . '</p>';
		}

		echo '<h3>Manage the ten picks</h3><p>Saving creates a new revision and preserves the previous picks. An administrator may make an audited correction after the deadline.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_admin_save_team_' . $entry_id );
		echo '<input type="hidden" name="action" value="obitleague_admin_save_team" /><input type="hidden" name="league_id" value="' . $league_id . '" /><input type="hidden" name="entry_id" value="' . $entry_id . '" /><input type="hidden" name="expected_version" value="' . (int) $entry->expected_version . '" />';
		echo '<table class="form-table"><tbody><tr><th><label for="ob-team-name">Team name</label></th><td><input id="ob-team-name" name="team_name" type="text" class="regular-text" maxlength="120" required value="' . esc_attr( (string) ( $entry->team_name ?: ( $user ? $user->display_name : 'Team ' . $entry_id ) ) ) . '" /></td></tr>';
		for ( $slot = 0; $slot < 10; $slot++ ) {
			echo '<tr><th><label for="ob-pick-' . $slot . '">Pick ' . ( $slot + 1 ) . '</label></th><td><select id="ob-pick-' . $slot . '" name="picks[]" required><option value="">Select a person…</option>';
			foreach ( $people as $person ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $person['uuid'] ), selected( $selected[ $slot ], $person['uuid'], false ), esc_html( $person['title'] ) );
			}
			echo '</select></td></tr>';
		}
		if ( 'submitted' === (string) $entry->state ) {
			echo '<tr><th><label for="ob-entry-state">Entry status</label></th><td><select id="ob-entry-state" name="entry_state"><option value="submitted" selected="selected">Keep submitted / competing</option><option value="draft">Withdraw from standings to draft</option></select><p class="description">Withdrawing keeps the submission in revision history but removes the team from standings.</p></td></tr>';
		} else {
			echo '<tr><th><label for="ob-entry-state">Entry status</label></th><td><select id="ob-entry-state" name="entry_state"><option value="draft">Save as draft</option><option value="submitted">Submit as competing team</option></select><p class="description">Submitting here is an administrator override of the player deadline.</p></td></tr>';
		}
		echo '<tr><th><label for="ob-admin-reason">Reason for change</label></th><td><textarea id="ob-admin-reason" name="reason" class="large-text" rows="3" maxlength="1000" required></textarea><p class="description">Required; retained with before and after picks in the audit record.</p></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Save new team revision' );
		echo '</form>';

		echo '<h3 style="margin-top:2em">Delete this team</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-ob-confirm="Delete this team, its revision history and its award rows, then rebuild standings? This cannot be undone.">';
		wp_nonce_field( 'obitleague_admin_delete_team_' . $entry_id );
		echo '<input type="hidden" name="action" value="obitleague_admin_delete_team" /><input type="hidden" name="league_id" value="' . $league_id . '" /><input type="hidden" name="entry_id" value="' . $entry_id . '" />';
		echo '<p class="description">Removes the team, its revision history and its award rows, then rebuilds standings. Retained only in the audit log.</p>';
		echo '<p><textarea name="reason" rows="2" maxlength="1000" placeholder="Reason (recorded in the audit log)" style="max-width:480px"></textarea></p>';
		submit_button( 'Delete this team', 'delete', 'submit', false );
		echo '</form>';

		self::render_revision_history( $entry_id, $revision ? (int) $revision->id : 0 );
		self::render_audit( 'entry', $entry_id );
	}

	private static function render_revision_history( int $entry_id, int $current_revision_id ): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.*, u.display_name FROM ' . $wpdb->prefix . 'obitleague_entry_revisions r
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.admin_user_id WHERE r.entry_id = %d ORDER BY r.id DESC LIMIT 100',
				$entry_id
			)
		);
		echo '<h3>Team revision history</h3>';
		if ( ! $rows ) {
			echo '<p>No revisions saved yet.</p>';
			return;
		}
		echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>Revision</th><th>Type</th><th>Saved (UTC)</th><th>Administrator</th><th>Reason</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$admin = ! empty( $row->admin_user_id ) ? (string) $row->display_name : 'Player submission';
			echo '<tr><td>#' . (int) $row->id . '</td><td>' . esc_html( (string) $row->kind ) . '</td><td>' . esc_html( (string) ( $row->submitted_at ?: $row->created_at ) ) . '</td><td>' . esc_html( $admin ) . '</td><td>' . esc_html( (string) ( $row->admin_reason ?: '—' ) ) . '</td><td>' . ( (int) $row->id === $current_revision_id ? '<strong>Current</strong>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_create_team_form( int $league_id ): void {
		$users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 1000, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
		echo '<h4>Add player / create team entry</h4><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'obitleague_admin_create_team_' . $league_id );
		echo '<input type="hidden" name="action" value="obitleague_admin_create_team" /><input type="hidden" name="league_id" value="' . $league_id . '" />';
		echo '<label for="ob-new-team-user">WordPress user </label><select id="ob-new-team-user" name="user_id" required><option value="">Select a user…</option>';
		foreach ( $users as $user ) {
			printf( '<option value="%1$d">%2$s (%3$s)</option>', (int) $user->ID, esc_html( (string) $user->display_name ), esc_html( (string) $user->user_email ) );
		}
		echo '</select> ';
		submit_button( 'Add to league and create draft team', 'secondary', 'submit', false );
		echo '<p class="description">New members are recorded as a member before lock or spectator after lock. The team starts as a draft.</p></form>';
	}

	private static function approved_people( array $selected_uuids, int $season ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 2000,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => 'obit_eligibility', 'value' => 'approved' ) ),
			)
		);
		$by_uuid = array();
		foreach ( $query->posts as $post_id ) {
			$uuid = (string) get_post_meta( (int) $post_id, 'obit_uuid', true );
			if ( '' !== $uuid && ( Catalogue::is_selectable( (int) $post_id, $season ) || in_array( $uuid, $selected_uuids, true ) ) ) {
				$by_uuid[ $uuid ] = array( 'uuid' => $uuid, 'title' => (string) get_the_title( (int) $post_id ) );
			}
		}
		foreach ( $selected_uuids as $uuid ) {
			if ( '' === $uuid || isset( $by_uuid[ $uuid ] ) ) {
				continue;
			}
			$post_id = self::person_post_id( $uuid );
			if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
				$by_uuid[ $uuid ] = array( 'uuid' => $uuid, 'title' => (string) get_the_title( $post_id ) . ' (current pick)' );
			}
		}
		return array_values( $by_uuid );
	}

	private static function latest_revision( int $entry_id, string $state ): ?object {
		global $wpdb;
		$kind = 'submitted' === $state ? 'submitted' : 'draft';
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entry_revisions WHERE entry_id = %d AND kind = %s ORDER BY id DESC LIMIT 1',
				$entry_id,
				$kind
			)
		);
		return $row ?: null;
	}

	private static function person_name( string $uuid ): string {
		$post_id = self::person_post_id( $uuid );
		return $post_id ? (string) get_the_title( $post_id ) : $uuid;
	}

	private static function person_post_id( string $uuid ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'obit_uuid' AND pm.meta_value = %s AND p.post_type = %s LIMIT 1",
				$uuid,
				Catalogue::POST_TYPE
			)
		);
	}

	public static function create_league(): void {
		self::require_post( 'obitleague_admin_create_league' );
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$season   = isset( $_POST['season'] ) ? absint( $_POST['season'] ) : 0;
		$owner_id = isset( $_POST['owner_user_id'] ) ? absint( $_POST['owner_user_id'] ) : 0;
		if ( ! get_userdata( $owner_id ) || $season < 2000 || $season > 2200 ) {
			self::redirect( array( 'error' => 'Choose a valid owner and season.' ) );
		}
		try {
			$league_id = League_Service::create_league( $owner_id, $name, $season );
			self::audit( 'league', $league_id, 'created', array( 'name' => $name, 'season' => $season, 'owner_user_id' => $owner_id ) );
			self::redirect( array( 'league' => $league_id, 'notice' => 'League created.' ) );
		} catch ( \Throwable $e ) {
			self::redirect( array( 'error' => $e->getMessage() ) );
		}
	}

	public static function update_league(): void {
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		self::require_post( 'obitleague_admin_update_league_' . $league_id );
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$state    = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( (string) $_POST['state'] ) ) : '';
		$owner_id = isset( $_POST['owner_user_id'] ) ? absint( $_POST['owner_user_id'] ) : 0;
		if ( ! $league_id || '' === trim( $name ) || mb_strlen( $name ) > 120 || ! in_array( $state, array( 'open', 'closed' ), true ) || ! get_userdata( $owner_id ) ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'Provide a valid league name, owner and state.' ) );
		}
		global $wpdb;
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT name, state, owner_user_id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ), ARRAY_A );
		if ( ! $before ) {
			self::redirect( array( 'error' => 'League not found.' ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		try {
			$updated = $wpdb->update( $wpdb->prefix . 'obitleague_leagues', array( 'name' => $name, 'state' => $state, 'owner_user_id' => $owner_id ), array( 'id' => $league_id ), array( '%s', '%s', '%d' ), array( '%d' ) );
			if ( false === $updated ) {
				throw new \RuntimeException( 'Could not save the league settings.' );
			}
			if ( (int) $before['owner_user_id'] !== $owner_id && ! League_Service::member_row( $league_id, $owner_id ) ) {
				$season = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT season FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
				$status = \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) ? 'member' : 'spectator';
				if ( false === $wpdb->insert( $wpdb->prefix . 'obitleague_league_members', array( 'league_id' => $league_id, 'user_id' => $owner_id, 'status' => $status, 'joined_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s' ) ) ) {
					throw new \RuntimeException( 'Could not add the new owner to the league.' );
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( array( 'league' => $league_id, 'error' => $e->getMessage() ) );
		}
		self::audit( 'league', $league_id, 'updated', array( 'before' => $before, 'after' => array( 'name' => $name, 'state' => $state, 'owner_user_id' => $owner_id ) ) );
		self::redirect( array( 'league' => $league_id, 'notice' => 'League settings saved.' ) );
	}

	public static function update_member(): void {
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		$user_id   = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		self::require_post( 'obitleague_admin_update_member_' . $league_id . '_' . $user_id );
		$action = isset( $_POST['member_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['member_action'] ) ) : '';
		global $wpdb;
		$league = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_user_id FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
		$member = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d AND user_id = %d', $league_id, $user_id ) );
		if ( ! $league || ! $member ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'League member not found.' ) );
		}
		if ( 'remove' === $action ) {
			$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
			if ( mb_strlen( $reason ) > 1000 || (int) $league->owner_user_id === $user_id || '' === trim( $reason ) ) {
				self::redirect( array( 'league' => $league_id, 'error' => 'The owner cannot be removed; a reason is required to remove any other member.' ) );
			}
			$wpdb->delete( $wpdb->prefix . 'obitleague_league_members', array( 'league_id' => $league_id, 'user_id' => $user_id ), array( '%d', '%d' ) );
			self::audit( 'league', $league_id, 'member_removed', array( 'user_id' => $user_id, 'status' => $member->status, 'reason' => $reason ) );
			self::redirect( array( 'league' => $league_id, 'notice' => 'Member removed. Their historical entries are retained.' ) );
		}
		if ( ! in_array( $action, array( 'member', 'spectator' ), true ) ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'Choose a member status or removal action.' ) );
		}
		$wpdb->update( $wpdb->prefix . 'obitleague_league_members', array( 'status' => $action ), array( 'league_id' => $league_id, 'user_id' => $user_id ), array( '%s' ), array( '%d', '%d' ) );
		self::audit( 'league', $league_id, 'member_status', array( 'user_id' => $user_id, 'before' => $member->status, 'after' => $action ) );
		self::redirect( array( 'league' => $league_id, 'notice' => 'Member status updated.' ) );
	}

	public static function create_team(): void {
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		self::require_post( 'obitleague_admin_create_team_' . $league_id );
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		global $wpdb;
		$league = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
		if ( ! $league || ! get_userdata( $user_id ) ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'Choose an existing user and league.' ) );
		}
		$existing_entry = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE league_id = %d AND season = %d AND user_id = %d', $league_id, (int) $league->season, $user_id )
		);
		if ( $existing_entry ) {
			self::redirect( array( 'league' => $league_id, 'entry' => $existing_entry, 'error' => 'This player already has a team entry for the league season.' ) );
		}
		$member = League_Service::member_row( $league_id, $user_id );
		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( ! $member ) {
				$status = \Obitleague\Domain\Deadline_Policy::is_entry_open( (int) $league->season, $now ) ? 'member' : 'spectator';
				if ( false === $wpdb->insert( $wpdb->prefix . 'obitleague_league_members', array( 'league_id' => $league_id, 'user_id' => $user_id, 'status' => $status, 'joined_at' => $now->format( 'Y-m-d H:i:s' ) ), array( '%d', '%d', '%s', '%s' ) ) ) {
					throw new \RuntimeException( 'Could not add the user to the league.' );
				}
			}
			$entry_id = Entry_Service::get_or_create_entry( $league_id, (int) $league->season, $user_id );
			if ( ! $entry_id ) {
				throw new \RuntimeException( 'Could not create the team entry.' );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( array( 'league' => $league_id, 'error' => $e->getMessage() ) );
		}
		self::audit( 'entry', $entry_id, 'team_created', array( 'league_id' => $league_id, 'user_id' => $user_id, 'season' => (int) $league->season ) );
		self::redirect( array( 'league' => $league_id, 'entry' => $entry_id, 'notice' => 'Draft team entry created.' ) );
	}

	public static function save_team(): void {
		$entry_id  = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		self::require_post( 'obitleague_admin_save_team_' . $entry_id );
		$back = array( 'league' => $league_id, 'entry' => $entry_id );
		$picks = isset( $_POST['picks'] ) && is_array( $_POST['picks'] )
			? array_map( static fn ( $uuid ): string => sanitize_text_field( wp_unslash( (string) $uuid ) ), $_POST['picks'] )
			: array();
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		$team_name = isset( $_POST['team_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['team_name'] ) ) : '';
		$requested_state = isset( $_POST['entry_state'] ) ? sanitize_key( wp_unslash( (string) $_POST['entry_state'] ) ) : 'draft';
		$expected_version = isset( $_POST['expected_version'] ) ? absint( $_POST['expected_version'] ) : 0;
		if ( ! in_array( $requested_state, array( 'draft', 'submitted' ), true ) ) {
			self::redirect( $back + array( 'error' => 'Choose a valid team status.' ) );
		}
		try {
			$picks = Entry_Rules::validate_picks( $picks );
		} catch ( \Throwable $e ) {
			self::redirect( $back + array( 'error' => $e->getMessage() ) );
		}
		if ( '' === trim( $team_name ) || mb_strlen( $team_name ) > 120 ) {
			self::redirect( $back + array( 'error' => 'Enter a team name of 1–120 characters.' ) );
		}
		if ( '' === trim( $reason ) || mb_strlen( $reason ) > 1000 ) {
			self::redirect( $back + array( 'error' => 'Enter an administrative reason of 1–1000 characters.' ) );
		}
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d AND league_id = %d', $entry_id, $league_id ) );
		if ( ! $entry ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'Team not found in that league.' ) );
		}
		if ( (int) $entry->expected_version !== $expected_version ) {
			self::redirect( $back + array( 'error' => 'This team changed since you opened it. Reload and review the latest picks.' ) );
		}
		$old_revision = self::latest_revision( $entry_id, (string) $entry->state );
		$before = $old_revision ? array_map( static fn ( $pick ): string => (string) $pick->person_uuid, Entry_Service::revision_picks( (int) $old_revision->id ) ) : array();
		foreach ( $picks as $uuid ) {
			$post_id = self::person_post_id( $uuid );
			$is_existing_pick = in_array( $uuid, $before, true );
			if ( ! $post_id || 'publish' !== get_post_status( $post_id ) || ( ! $is_existing_pick && ( 'approved' !== (string) get_post_meta( $post_id, 'obit_eligibility', true ) || ! Catalogue::is_selectable( $post_id, (int) $entry->season ) ) ) ) {
				self::redirect( $back + array( 'error' => 'New picks must be selectable for this season; existing picks may be retained.' ) );
			}
		}

		$final_state = $requested_state;
		$kind = $final_state;
		$now  = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$inserted_revision = $wpdb->insert(
				$wpdb->prefix . 'obitleague_entry_revisions',
				array(
					'entry_id'      => $entry_id,
					'kind'          => $kind,
					'receipt_id'    => 'submitted' === $kind ? wp_generate_uuid4() : null,
					'submitted_at'  => 'submitted' === $kind ? $now : null,
					'created_at'    => $now,
					'admin_user_id' => get_current_user_id(),
					'admin_reason'  => $reason,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			if ( false === $inserted_revision ) {
				throw new \RuntimeException( 'Could not create the team revision.' );
			}
			$revision_id = (int) $wpdb->insert_id;
			foreach ( $picks as $slot => $uuid ) {
				if ( false === $wpdb->insert( $wpdb->prefix . 'obitleague_entry_picks', array( 'revision_id' => $revision_id, 'slot' => $slot + 1, 'person_uuid' => $uuid ), array( '%d', '%d', '%s' ) ) ) {
					throw new \RuntimeException( 'Could not save every pick.' );
				}
			}
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'obitleague_entries SET team_name = %s, state = %s, expected_version = expected_version + 1, updated_at = %s WHERE id = %d AND expected_version = %d',
					$team_name,
					$final_state,
					$now,
					$entry_id,
					$expected_version
				)
			);
			if ( 1 !== (int) $updated ) {
				throw new \RuntimeException( 'This team changed while you were saving. Reload and review the latest picks.' );
			}
			// Old submitted picks remain in history but must not count in scoring.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'obitleague_entry_revisions SET kind = %s WHERE entry_id = %d AND kind = %s AND id <> %d',
					'superseded',
					$entry_id,
					'submitted',
					$revision_id
				)
			);
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( $back + array( 'error' => $e->getMessage() ) );
		}

		try {
			if ( 'submitted' === $final_state ) {
				$ids = array_values( array_unique( array_merge( $before, $picks ) ) );
				if ( $ids ) {
					$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
					$events = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT uuid FROM {$wpdb->prefix}obitleague_events WHERE person_uuid IN ({$placeholders}) AND approved_at IS NOT NULL AND retracted_at IS NULL",
							...$ids
						)
					);
					$affected = array();
					foreach ( (array) $events as $event_uuid ) {
						$affected = array_merge( $affected, Outbox_Service::award_event( (string) $event_uuid ) );
					}
					foreach ( $affected as $pair ) {
						Standings_Service::rebuild( (int) $pair[0], (int) $pair[1] );
					}
				}
			}
			Standings_Service::rebuild( $league_id, (int) $entry->season );
		} catch ( \Throwable $e ) {
			self::audit( 'entry', $entry_id, 'post_save_rebuild_error', array( 'revision_id' => $revision_id, 'error' => $e->getMessage() ) );
		}

		self::audit( 'entry', $entry_id, 'picks_revised', array( 'revision_id' => $revision_id, 'actor' => get_current_user_id(), 'reason' => $reason, 'team_name' => $team_name, 'before' => $before, 'after' => $picks, 'state' => $final_state ) );
		$notice = 'Team saved as a new ' . ( 'submitted' === $final_state ? 'submitted' : 'draft' ) . ' revision; prior revisions are retained.';
		if ( 'draft' === $final_state && 'submitted' === (string) $entry->state ) {
			$notice .= ' The team was withdrawn from standings.';
		}
		self::redirect( $back + array( 'notice' => $notice ) );
	}

	/**
	 * Delete one team entry and all of its revisions, picks and award rows.
	 * Standings are rebuilt afterwards so points leave the board at once.
	 */
	public static function delete_team(): void {
		$entry_id  = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		self::require_post( 'obitleague_admin_delete_team_' . $entry_id );
		$back = array( 'league' => $league_id, 'entry' => $entry_id );
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		if ( mb_strlen( $reason ) > 1000 ) {
			self::redirect( $back + array( 'error' => 'Keep the reason under 1000 characters.' ) );
		}
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d AND league_id = %d', $entry_id, $league_id ) );
		if ( ! $entry ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'Team not found in that league.' ) );
		}
		$before = self::entry_award_total( $entry_id );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$revision_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entry_revisions WHERE entry_id = %d', $entry_id ) );
			if ( $revision_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $revision_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entry_picks WHERE revision_id IN ({$placeholders})", ...array_map( 'intval', $revision_ids ) ) );
			}
			$wpdb->delete( $wpdb->prefix . 'obitleague_entry_revisions', array( 'entry_id' => $entry_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'obitleague_awards', array( 'entry_id' => $entry_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'obitleague_entries', array( 'id' => $entry_id ), array( '%d' ) );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( $back + array( 'error' => $e->getMessage() ) );
		}
		try {
			Standings_Service::rebuild( $league_id, (int) $entry->season );
		} catch ( \Throwable $e ) {
			self::audit( 'entry', $entry_id, 'post_delete_rebuild_error', array( 'error' => $e->getMessage() ) );
		}
		self::audit( 'entry', $entry_id, 'team_deleted', array( 'league_id' => $league_id, 'season' => (int) $entry->season, 'user_id' => (int) $entry->user_id, 'points_removed' => $before, 'reason' => $reason ) );
		self::redirect( array( 'league' => $league_id, 'notice' => 'Team deleted; ' . $before . ' ledger points were removed and standings rebuilt.' ) );
	}

	/** Total positive ledger points currently carried by one entry. */
	private static function entry_award_total( int $entry_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards WHERE entry_id = %d AND award_delta > 0', $entry_id ) );
	}

	/**
	 * Delete a side league outright: members, entries, revisions, picks and
	 * award rows all go, along with the league's standings generations so
	 * nothing references it. The main league is protected.
	 */
	public static function delete_league(): void {
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		self::require_post( 'obitleague_admin_delete_league_' . $league_id );
		global $wpdb;
		$league = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, season, is_main FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d', $league_id ) );
		if ( ! $league ) {
			self::redirect( array( 'error' => 'League not found.' ) );
		}
		if ( (int) $league->is_main ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'The main league cannot be deleted.' ) );
		}
		$confirm = isset( $_POST['confirm_text'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_text'] ) ) : '';
		if ( (string) $league->name !== $confirm ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'Type the league name exactly to confirm deletion.' ) );
		}

		$entry_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_entries WHERE league_id = %d', $league_id ) ) );
		$revision_ids = array();
		if ( $entry_ids ) {
			$entry_placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
			$revision_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id IN ({$entry_placeholders})", ...$entry_ids ) ) );
		}

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( $revision_ids ) {
				$rev_placeholders = implode( ',', array_fill( 0, count( $revision_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entry_picks WHERE revision_id IN ({$rev_placeholders})", ...$revision_ids ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entry_revisions WHERE id IN ({$rev_placeholders})", ...$revision_ids ) );
			}
			if ( $entry_ids ) {
				$entry_placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_awards WHERE entry_id IN ({$entry_placeholders})", ...$entry_ids ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_entries WHERE id IN ({$entry_placeholders})", ...$entry_ids ) );
			}
			$wpdb->delete( $wpdb->prefix . 'obitleague_league_members', array( 'league_id' => $league_id ), array( '%d' ) );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			self::redirect( array( 'league' => $league_id, 'error' => $e->getMessage() ) );
		}

		// Remove the league's standings generations and their rows.
		$generation_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'obitleague_standings_generations WHERE league_id = %d', $league_id ) ) );
		if ( $generation_ids ) {
			$gen_placeholders = implode( ',', array_fill( 0, count( $generation_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_standings_rows WHERE generation_id IN ({$gen_placeholders})", ...$generation_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}obitleague_standings_generations WHERE id IN ({$gen_placeholders})", ...$generation_ids ) );
		}

		self::audit( 'league', $league_id, 'league_deleted', array( 'name' => (string) $league->name, 'season' => (int) $league->season, 'entries_removed' => count( $entry_ids ) ) );
		self::redirect( array( 'notice' => 'League deleted along with its teams, members and awards.' ) );
	}

	public static function rebuild_standings(): void {
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		self::require_post( 'obitleague_admin_rebuild_' . $league_id );
		global $wpdb;
		$seasons = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT season FROM ' . $wpdb->prefix . 'obitleague_entries WHERE league_id = %d ORDER BY season DESC', $league_id ) );
		if ( ! $seasons ) {
			self::redirect( array( 'league' => $league_id, 'error' => 'No team seasons found to rebuild.' ) );
		}
		foreach ( $seasons as $season ) {
			Standings_Service::rebuild( $league_id, (int) $season );
		}
		self::audit( 'league', $league_id, 'standings_rebuilt', array( 'seasons' => array_map( 'intval', $seasons ) ) );
		self::redirect( array( 'league' => $league_id, 'notice' => 'Standings rebuilt for ' . count( $seasons ) . ' season(s).' ) );
	}

	/**
	 * Hide or restore one league on public surfaces. The row is never touched
	 * beyond its visibility flag, and every change is audited.
	 */
	public static function toggle_league_hidden(): void {
		self::require_post( 'obitleague_admin_toggle_league_hidden' );
		$league_id = isset( $_POST['league_id'] ) ? absint( $_POST['league_id'] ) : 0;
		$hide      = isset( $_POST['hide'] ) && '1' === (string) $_POST['hide'];
		if ( ! $league_id || ! Public_Scope::set_league_hidden( $league_id, $hide, 'changed in the leagues admin' ) ) {
			self::redirect( array( 'error' => 'Could not change that league\'s public visibility. Reload this screen and try again; if it keeps failing, check the audit log for the last change.' ) );
		}
		self::redirect( array( 'notice' => $hide ? 'League hidden from public surfaces.' : 'League restored to public surfaces.' ) );
	}

	private static function require_post( string $nonce_action ): void {
		if ( ! current_user_can( self::CAP ) || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'This administrator action is not allowed.', 'obitleague' ) );
		}
	}

	private static function audit( string $object_type, int $object_id, string $action, array $details ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'obitleague_admin_audit',
			array(
				'actor_user_id' => get_current_user_id(),
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'action'        => $action,
				'details'       => wp_json_encode( $details ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	private static function render_audit( string $object_type = '', int $object_id = 0 ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'obitleague_admin_audit';
		if ( $object_type && $object_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.*, u.display_name FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.actor_user_id WHERE a.object_type = %s AND a.object_id = %d ORDER BY a.id DESC LIMIT 30", $object_type, $object_id ) );
		} else {
			$rows = $wpdb->get_results( "SELECT a.*, u.display_name FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.actor_user_id ORDER BY a.id DESC LIMIT 20" );
		}
		echo '<h2 style="margin-top:2em">Administrator audit log</h2>';
		if ( ! $rows ) {
			echo '<p>No administrator actions recorded yet.</p>';
			return;
		}
		echo '<div class="ob-admin-table-scroll"><table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Administrator</th><th>Action</th><th>Object</th><th>Details</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$details = json_decode( (string) $row->details, true );
			$summary = is_array( $details ) ? wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : (string) $row->details;
			echo '<tr><td>' . esc_html( (string) $row->created_at ) . '</td><td>' . esc_html( $row->display_name ?: 'User #' . (int) $row->actor_user_id ) . '</td><td>' . esc_html( (string) $row->action ) . '</td><td>' . esc_html( (string) $row->object_type . ' #' . (int) $row->object_id ) . '</td><td><code style="white-space:normal">' . esc_html( (string) $summary ) . '</code></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	private static function redirect( array $args ): void {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}

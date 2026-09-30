<?php
/** My Leagues template and team management controls. */
declare( strict_types = 1 );

use Obitleague\Modules\Entry_Service;
use Obitleague\Modules\League_Service;
use Obitleague\Modules\League_View_Service;
use Obitleague\Modules\Overall_Standings;
use Obitleague\Modules\Pick_Stats;
use Obitleague\Modules\Standings_Service;
use Obitleague\Modules\Season_Switcher;

$user_id = get_current_user_id();
get_header();

if ( ! $user_id ) :
	?>
	<main class="ob-page">
		<section class="ob-hero ob-hero--archive ob-anim">
			<span class="ob-hero__kicker">Your game</span><h1>My leagues</h1>
			<p>Sign in to see your leagues, your position and your team status.</p>
			<div class="ob-hero__cta"><a class="ob-btn" href="<?php echo esc_url( home_url( '/login/?redirect_to=' . rawurlencode( home_url( '/my-leagues/' ) ) ) ); ?>">Sign in</a><a class="ob-btn ob-btn--ghost" href="<?php echo esc_url( home_url( '/join/' ) ); ?>">Join a side league</a></div>
		</section>
	</main>
	<?php
	get_footer();
	return;
endif;

global $wpdb;
$season = League_Service::current_season();
$is_verified = '0' !== (string) get_user_meta( $user_id, 'obitleague_email_verified', true ) || '1' !== (string) get_user_meta( $user_id, 'obitleague_campaign_signup', true );
$main_entry_id = League_Service::ensure_main_entry( $user_id, $season );
$main_league_id = Overall_Standings::main_league_id( $season );
$main_row = $main_league_id ? Standings_Service::row_for_user( $main_league_id, $season, $user_id ) : null;
$main_entry = $wpdb->get_row( $wpdb->prepare( 'SELECT id, state, expected_version, team_name FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d', $main_entry_id ) );
$main_revision = $main_entry && 'submitted' === (string) $main_entry->state ? Entry_Service::submitted_revision( $main_entry_id ) : null;
if ( ! $main_revision && $main_entry ) {
	$main_revision = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}obitleague_entry_revisions WHERE entry_id = %d AND kind = 'draft' ORDER BY id DESC LIMIT 1", $main_entry_id ) );
}
$main_picks = $main_revision ? array_map( static function ( $pick ): array {
	$person = League_View_Service::person_card_by_uuid( (string) $pick->person_uuid );
	$person['selectable'] = true;
	return $person;
}, Entry_Service::revision_picks( (int) $main_revision->id ) ) : array();
$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$main_can_edit = $is_verified && $main_entry && 'member' === (string) League_Service::member_row( $main_league_id, $user_id )?->status && \Obitleague\Domain\Entry_Rules::can_edit( (string) $main_entry->state, \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, $now ) );
$memberships = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT m.league_id, m.status, m.joined_at, l.name, l.season, l.state, l.owner_user_id, l.is_main
		 FROM {$wpdb->prefix}obitleague_league_members m
		 JOIN {$wpdb->prefix}obitleague_leagues l ON l.id = m.league_id
		 WHERE m.user_id = %d AND l.is_main = 0
		 ORDER BY l.season DESC, l.name ASC",
		$user_id
	)
);
$nonce = wp_create_nonce( 'wp_rest' );
$rest_root = rest_url( 'obitleague/v1' );
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--archive ob-anim">
		<span class="ob-hero__kicker">Your game</span><h1>My leagues</h1>
		<p><?php echo esc_html( Season_Switcher::season_story() ); ?>. Your 2026 team is live on the leaderboard while the 2027 season is open for picks until 00:00 London time on 1 January <?php echo esc_html( (string) $season ); ?>.</p>
		<div class="ob-hero__cta"><a class="ob-btn" href="<?php echo esc_url( home_url( '/join/' ) ); ?>">Join a side league</a><a class="ob-btn ob-btn--ghost" href="<?php echo esc_url( home_url( '/standings/' ) ); ?>">Overall standings</a></div>
	</section>

	<?php
	// The in-play season: the player's live 2026 team and position, if any.
	$live_season = Pick_Stats::season_in_play();
	$live_league_id = Overall_Standings::main_league_id( $live_season );
	$live_entry_id = $live_league_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}obitleague_entries WHERE league_id = %d AND season = %d AND user_id = %d", $live_league_id, $live_season, $user_id ) ) : 0;
	$live_row = $live_league_id ? Standings_Service::row_for_user( $live_league_id, $live_season, $user_id ) : null;
	$live_total = $live_league_id ? Standings_Service::count_current( $live_league_id, $live_season ) : 0;
	if ( $live_league_id && $live_total > 0 ) :
		?>
		<section class="ob-card ob-my-league ob-my-league--main<?php echo $live_row ? ' ob-my-league--ranked' : ''; ?>" data-live-season>
			<div class="ob-my-league__head"><strong class="ob-my-league__name">Season <?php echo esc_html( (string) $live_season ); ?> · in play</strong><span class="ob-my-league__season">Overall leaderboard</span></div>
			<div class="ob-my-league__stats">
				<?php if ( $live_row ) : ?>
					<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $live_row['rank'] ); ?></span><span>rank of <?php echo esc_html( (string) $live_total ); ?></span></div>
					<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $live_row['points'] ); ?></span><span>points</span></div>
					<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $live_row['scoring_picks'] ); ?></span><span>scoring picks</span></div>
				<?php else : ?>
					<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $live_total ); ?></span><span>teams on the leaderboard</span></div>
					<div class="ob-my-league__stat"><span class="ob-my-league__num">—</span><span>no 2026 team — the season ran before you joined</span></div>
				<?php endif; ?>
			</div>
			<div class="ob-my-league__actions"><a class="ob-btn ob-btn--secondary" href="<?php echo esc_url( home_url( '/standings/' ) ); ?>">Follow the <?php echo esc_html( (string) $live_season ); ?> standings</a><?php if ( $live_entry_id ) : ?><a class="ob-btn ob-btn--secondary" href="<?php echo esc_url( home_url( '/team/' . $live_entry_id . '/' ) ); ?>">View my <?php echo esc_html( (string) $live_season ); ?> team</a><?php endif; ?></div>
		</section>
	<?php endif; ?>

	<section id="build-team" class="ob-card ob-my-league ob-my-league--main" data-ob-main-team data-rest="<?php echo esc_url( $rest_root ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-verified="<?php echo $is_verified ? '1' : '0'; ?>">
		<div class="ob-my-league__head"><strong class="ob-my-league__name">Season <?php echo esc_html( (string) $season ); ?> · picks open</strong><span class="ob-my-league__season">For next year's game</span></div>
		<div class="ob-my-league__stats">
			<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo $main_row ? esc_html( (string) $main_row['rank'] ) : '—'; ?></span><span><?php echo $main_row ? 'overall rank' : 'not ranked yet'; ?></span></div>
			<div class="ob-my-league__stat"><span class="ob-my-league__num ob-my-league__state" data-main-status><?php echo esc_html( (string) ( $main_entry->state ?? 'draft' ) ); ?></span><span>2027 team status</span></div>
		</div>
		<?php if ( ! $is_verified ) : ?><p class="ob-auth-message ob-auth-message--error" role="alert">Verify your email to choose, save, and submit your team. Check your inbox for the link.</p><?php endif; ?>
		<div class="ob-main-editor" data-main-editor<?php echo $main_can_edit ? '' : ' hidden'; ?>>
			<label for="ob-main-team-name">Team name</label>
			<input id="ob-main-team-name" type="text" maxlength="120" autocomplete="off" placeholder="Give your team a name" data-main-name value="<?php echo esc_attr( (string) ( $main_entry->team_name ?? '' ) ); ?>" />
			<label for="ob-main-person-search">Find an eligible pick</label>
			<input id="ob-main-person-search" type="search" autocomplete="off" placeholder="Search the catalogue" data-main-search aria-controls="ob-main-results" />
			<ul id="ob-main-results" class="ob-main-results" data-main-results aria-label="Search results" aria-live="polite"></ul>
			<h3>Your ten picks <span data-main-count>(<?php echo count( $main_picks ); ?>/10)</span></h3><ol class="ob-main-picks" data-main-picks></ol>
			<div class="ob-main-actions"><button class="ob-btn ob-btn--secondary" type="button" data-main-save<?php echo $main_can_edit ? '' : ' disabled'; ?>>Save team</button><button class="ob-btn" type="button" data-main-submit<?php echo $main_can_edit && 'draft' === (string) $main_entry->state ? '' : ' hidden disabled'; ?>>Submit team</button></div>
			<p class="ob-join-msg" data-main-message aria-live="polite"></p>
		</div>
		<div class="ob-my-league__actions">
			<a class="ob-btn ob-btn--secondary" href="<?php echo esc_url( home_url( '/standings/' ) ); ?>">Overall standings</a>
			<?php if ( $main_can_edit && 'draft' === (string) $main_entry->state ) : ?><button class="ob-btn" type="button" data-main-edit>Build your main team</button><?php endif; ?>
		</div>
	</section>

	<?php if ( ! $memberships ) : ?>
		<section class="ob-card"><p><em>You have no side leagues yet. Join one with an invite code or create one on the <a href="<?php echo esc_url( home_url( '/join/' ) ); ?>">side-league page</a>.</em></p></section>
	<?php else : ?>
		<div class="ob-my-leagues">
			<?php foreach ( $memberships as $m ) : ?>
				<?php
				$league_id = (int) $m->league_id;
				$side_entry_id = Entry_Service::get_or_create_entry( $league_id, (int) $m->season, $user_id );
				$total = Standings_Service::count_current( $league_id, (int) $m->season );
				$standing = Standings_Service::row_for_user( $league_id, (int) $m->season, $user_id );
				$rank = $standing ? (int) $standing['rank'] : null;
				$points = $standing ? (int) $standing['points'] : null;
				$side_can_edit = $is_verified && 'member' === (string) $m->status && \Obitleague\Domain\Deadline_Policy::is_entry_open( (int) $m->season, $now );
				?>
				<section class="ob-card ob-my-league<?php echo $rank ? ' ob-my-league--ranked' : ''; ?>" data-side-team data-entry-id="<?php echo esc_attr( (string) $side_entry_id ); ?>">
					<div class="ob-my-league__head"><a class="ob-my-league__name" href="<?php echo esc_url( home_url( '/league/' . $league_id . '/' ) ); ?>"><?php echo esc_html( (string) $m->name ); ?></a><span class="ob-my-league__season">Season <?php echo esc_html( (string) $m->season ); ?></span></div>
					<div class="ob-my-league__stats">
						<?php if ( null !== $rank ) : ?><div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $rank ); ?></span><span>rank of <?php echo esc_html( (string) $total ); ?></span></div><div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $points ); ?></span><span>points</span></div>
						<?php else : ?><div class="ob-my-league__stat"><span class="ob-my-league__num">—</span><span>no standings yet</span></div><?php endif; ?>
						<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo 'submitted' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d', $side_entry_id ) ) ? '✓' : '·'; ?></span><span>team <?php echo 'submitted' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . $wpdb->prefix . 'obitleague_entries WHERE id = %d', $side_entry_id ) ) ? 'submitted' : 'not submitted'; ?></span></div>
					</div>
					<div class="ob-my-league__actions"><a class="ob-btn ob-btn--secondary" href="<?php echo esc_url( home_url( '/league/' . $league_id . '/' ) ); ?>">Open side league</a><?php if ( $side_can_edit ) : ?><button class="ob-btn" type="button" data-side-edit>Edit team</button><?php endif; ?><?php if ( (int) $m->owner_user_id === $user_id ) : ?><span class="ob-my-league__owner">You own this league</span><?php endif; ?></div>
					<?php if ( $side_can_edit ) : ?>
					<div class="ob-side-editor" data-side-editor hidden><strong>Team for season <?php echo esc_html( (string) $m->season ); ?></strong><label>Team name <input type="text" maxlength="120" data-side-name /></label><label>Find an eligible pick <input type="search" placeholder="Search the catalogue" autocomplete="off" data-side-search /></label><ul data-side-results aria-live="polite"></ul><h3>Your ten picks <span data-side-count>(0/10)</span></h3><ol data-side-picks></ol><button class="ob-btn ob-btn--secondary" type="button" data-side-save>Save team</button><button class="ob-btn" type="button" data-side-submit>Submit team</button><p class="ob-join-msg" data-side-message aria-live="polite"></p></div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</main>
<?php
wp_enqueue_script( 'obitleague-team-manager', OBITLEAGUE_DIR_URL . 'assets/team-manager.js', array(), OBITLEAGUE_VERSION, true );
get_footer();

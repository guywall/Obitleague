<?php
/**
 * My Leagues template.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Modules\League_Service;
use Obitleague\Modules\League_View_Service;

$user_id = get_current_user_id();

get_header();

if ( ! $user_id ) :
	?>
	<main class="ob-page">
		<section class="ob-hero ob-hero--archive ob-anim">
			<span class="ob-hero__kicker">Your game</span>
			<h1>My leagues</h1>
			<p>Sign in to see your leagues, your position and your team status.</p>
			<div class="ob-hero__cta">
				<a href="<?php echo esc_url( wp_login_url( home_url( '/my-leagues/' ) ) ); ?>">Sign in</a>
				<a class="ghost" href="<?php echo esc_url( home_url( '/join/' ) ); ?>">Join a league</a>
			</div>
		</section>
	</main>
	<?php
	get_footer();
	return;
endif;

global $wpdb;
$season = League_Service::current_season();
$season = (int) $wpdb->get_var( 'SELECT MAX(season) FROM ' . $wpdb->prefix . 'obitleague_standings_generations WHERE is_current = 1' ) ?: $season;

$memberships = (array) $wpdb->get_results(
	$wpdb->prepare(
		"SELECT m.league_id, m.status, m.joined_at, l.name, l.season, l.state, l.owner_user_id
		 FROM {$wpdb->prefix}obitleague_league_members m
		 JOIN {$wpdb->prefix}obitleague_leagues l ON l.id = m.league_id
		 WHERE m.user_id = %d
		 ORDER BY l.season DESC, l.name ASC",
		$user_id
	)
);
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--archive ob-anim">
		<span class="ob-hero__kicker">Your game</span>
		<h1>My leagues</h1>
		<p>Season <?php echo esc_html( (string) $season ); ?> is in play. Entry is locked; standings update as deaths are confirmed.</p>
		<div class="ob-hero__cta">
			<a href="<?php echo esc_url( home_url( '/join/' ) ); ?>">Join another league</a>
			<a class="ghost" href="<?php echo esc_url( home_url( '/standings/' ) ); ?>">All standings</a>
		</div>
	</section>

	<?php if ( ! $memberships ) : ?>
		<section class="ob-card"><p><em>You are not in a league yet. Join with an invite code or create your own on the <a href="<?php echo esc_url( home_url( '/join/' ) ); ?>">join page</a>.</em></p></section>
	<?php else : ?>
		<div class="ob-my-leagues">
			<?php foreach ( $memberships as $m ) : ?>
				<?php
				$league_id = (int) $m->league_id;
				$entry_id  = League_View_Service::submitted_entry_id( $user_id, $league_id, (int) $m->season );
				$rank      = null;
				$points    = null;
				$total     = 0;
				$rows      = Standings_Service::current( $league_id, (int) $m->season );
				if ( $rows ) {
					$total = count( $rows );
					foreach ( $rows as $row ) {
						if ( (int) $row['user_id'] === $user_id ) {
							$rank   = (int) $row['rank'];
							$points = (int) $row['points'];
							break;
						}
					}
				}
				?>
				<section class="ob-card ob-my-league<?php echo $rank ? ' ob-my-league--ranked' : ''; ?>">
					<div class="ob-my-league__head">
						<a class="ob-my-league__name" href="<?php echo esc_url( home_url( '/league/' . $league_id . '/' ) ); ?>"><?php echo esc_html( (string) $m->name ); ?></a>
						<span class="ob-my-league__season">Season <?php echo esc_html( (string) $m->season ); ?></span>
					</div>
					<div class="ob-my-league__stats">
						<?php if ( null !== $rank ) : ?>
							<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $rank ); ?></span><span>rank of <?php echo esc_html( (string) $total ); ?></span></div>
							<div class="ob-my-league__stat"><span class="ob-my-league__num"><?php echo esc_html( (string) $points ); ?></span><span>points</span></div>
						<?php else : ?>
							<div class="ob-my-league__stat"><span class="ob-my-league__num">—</span><span>no standings yet</span></div>
						<?php endif; ?>
						<div class="ob-my-league__stat">
							<span class="ob-my-league__num"><?php echo $entry_id ? '✓' : '·'; ?></span>
							<span><?php echo $entry_id ? 'team submitted' : ( (int) $m->season === $season ? 'no submitted team' : 'season over' ); ?></span>
						</div>
					</div>
					<div class="ob-my-league__actions">
						<a class="ob-btn ob-btn--secondary" href="<?php echo esc_url( home_url( '/league/' . $league_id . '/' ) ); ?>">Open league</a>
						<?php if ( (int) $m->owner_user_id === $user_id ) : ?>
							<span class="ob-my-league__owner">You own this league</span>
						<?php endif; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</main>
<?php
get_footer();

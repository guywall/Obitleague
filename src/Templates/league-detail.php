<?php
/**
 * League detail template (public league page).
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Modules\League_View_Service;

$league_id = (int) get_query_var( 'ob_league_id' );
$league    = League_View_Service::league( $league_id );
if ( ! $league ) {
	wp_safe_redirect( home_url( '/standings/' ) );
	exit;
}

$season   = (int) $league['season'];
$score    = League_View_Service::scoreboard( $league_id, $season );
$viewer   = League_View_Service::viewer_is_member( $league_id );
$my_entry = get_current_user_id() ? League_View_Service::submitted_entry_id( get_current_user_id(), $league_id, $season ) : 0;

get_header();
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--league ob-anim">
		<span class="ob-hero__kicker">League &middot; Season <?php echo esc_html( (string) $season ); ?></span>
		<h1><?php echo esc_html( $league['name'] ); ?></h1>
		<p>
			<?php echo esc_html( (string) $league['members'] ); ?> members &middot;
			owned by <?php echo esc_html( $league['owner'] ?: '—' ); ?> &middot;
			state: <?php echo esc_html( (string) $league['state'] ); ?>
			<?php if ( $my_entry ) : ?>
				&middot; <strong>You compete here.</strong>
			<?php elseif ( $viewer ) : ?>
				&middot; You are a member but have no submitted team — a spectator until next season.
			<?php endif; ?>
		</p>
	</section>

	<?php if ( ! $score ) : ?>
		<section class="ob-card"><p><em>Standings are not published yet for this league.</em></p></section>
	<?php else : ?>
		<?php foreach ( $score as $row ) : ?>
			<section class="ob-league-row ob-card<?php echo $row['points'] > 0 ? '' : ''; ?>">
				<div class="ob-league-row__head" data-toggle>
					<span class="ob-rank"><span class="ob-medal ob-medal--<?php echo (int) $row['rank']; ?>"><?php echo esc_html( (string) $row['rank'] ); ?></span></span>
					<span class="ob-league-row__player"><?php echo esc_html( (string) $row['player'] ); ?></span>
					<span class="ob-league-row__meta"><?php echo esc_html( (string) $row['scoring_picks'] ); ?> scoring pick<?php echo 1 === (int) $row['scoring_picks'] ? '' : 's'; ?></span>
					<span class="ob-pts"><?php echo esc_html( (string) $row['points'] ); ?> <small>pts</small></span>
					<span class="ob-league-row__chev" aria-hidden="true">▾</span>
				</div>
				<?php if ( ! empty( $row['picks'] ) ) : ?>
					<div class="ob-league-row__picks" hidden>
						<div class="ob-picks">
							<?php foreach ( $row['picks'] as $pick ) : ?>
								<?php $p = array_merge( array( 'name' => '', 'url' => '', 'image' => '', 'role' => '', 'is_dead' => false, 'death' => '', 'awarded' => 0, 'slot' => 0 ), (array) $pick ); ?>
								<article class="ob-pick<?php echo $p['awarded'] > 0 ? ' ob-pick--scored' : ''; ?><?php echo $p['is_dead'] ? ' ob-pick--dead' : ''; ?>">
									<span class="ob-pick__slot"><?php echo esc_html( (string) $p['slot'] ); ?></span>
									<?php if ( '' !== $p['image'] ) : ?>
										<img class="ob-pick__img" src="<?php echo esc_url( $p['image'] ); ?>" alt="" loading="lazy" />
									<?php else : ?>
										<span class="ob-pick__img ob-pick__img--initial" aria-hidden="true"><?php echo esc_html( mb_substr( (string) $p['name'], 0, 1 ) ); ?></span>
									<?php endif; ?>
									<span class="ob-pick__body">
										<?php if ( '' !== $p['url'] ) : ?>
											<a class="ob-pick__name" href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( (string) $p['name'] ); ?></a>
										<?php else : ?>
											<span class="ob-pick__name"><?php echo esc_html( (string) $p['name'] ); ?></span>
										<?php endif; ?>
										<span class="ob-pick__role"><?php echo esc_html( (string) $p['role'] ); ?></span>
									</span>
									<span class="ob-pick__score">
										<?php if ( $p['awarded'] > 0 ) : ?>
											<span class="ob-badge ob-badge--brass"><?php echo esc_html( (string) $p['awarded'] ); ?> pts</span>
										<?php elseif ( $p['is_dead'] ) : ?>
											<span class="ob-badge">d. <?php echo esc_html( (string) $p['death'] ); ?></span>
										<?php else : ?>
											<span class="ob-badge ob-badge--muted">alive</span>
										<?php endif; ?>
									</span>
								</article>
							<?php endforeach; ?>
						</div>
					</div>
				<?php else : ?>
					<div class="ob-league-row__picks" hidden><p class="ob-picks-empty">Team is private until lock.</p></div>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
</main>
<script>
document.addEventListener('click', function(e){
	var head = e.target.closest('[data-toggle]');
	if(!head) return;
	var row = head.parentElement;
	var picks = row.querySelector('.ob-league-row__picks');
	if(!picks) return;
	var open = picks.hidden;
	picks.hidden = !open;
	row.classList.toggle('is-open', open);
});
</script>
<?php
get_footer();

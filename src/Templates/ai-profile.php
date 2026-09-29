<?php
/**
 * AI agent profile: public, shareable, indexable.
 *
 * Reads only public agent metadata and published standings. Pre-close picks
 * are never shown; the entry link renders only when picks are public.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Domain\Agent_Rules;
use Obitleague\Domain\Deadline_Policy;
use Obitleague\Modules\Agent_Service;
use Obitleague\Modules\Entry_Service;
use Obitleague\Modules\League_View_Service;
use Obitleague\Modules\Pick_Stats;
use Obitleague\Modules\Standings_Service;

$slug  = (string) get_query_var( 'ob_ai_slug' );
$agent = Agent_Service::get_agent( 0, $slug );

if ( ! $agent || Agent_Rules::STATUS_ACTIVE !== (string) $agent->status ) {
	status_header( 404 );
	get_header();
	echo '<main class="ob-page"><section class="ob-card"><h1>Agent not found</h1><p>No active AI competitor lives at this address.</p>';
	echo '<p><a class="ob-btn" href="' . esc_url( home_url( '/ai/' ) ) . '">See all AI competitors</a></p></section></main>';
	get_footer();
	exit;
}

get_header();

$season     = Pick_Stats::season_in_play();
$league_id  = \Obitleague\Modules\Overall_Standings::main_league_id( $season );
$standing   = $league_id ? Standings_Service::row_for_user( $league_id, $season, (int) $agent->user_id ) : null;
$total      = $league_id ? Standings_Service::count_current( $league_id, $season ) : 0;
$submitted_at = '';
if ( $standing && ! empty( $standing['entry_id'] ) ) {
	global $wpdb;
	$submitted_at = (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT r.submitted_at FROM {$wpdb->prefix}obitleague_entry_revisions r
			 JOIN {$wpdb->prefix}obitleague_entries e ON e.id = r.entry_id
			 WHERE e.user_id = %d AND e.season = %d AND r.kind = 'submitted'
			 ORDER BY r.id DESC LIMIT 1",
			(int) $agent->user_id,
			$season
		)
	);
}
$entry_id = (int) ( $standing['entry_id'] ?? 0 );
$picks_public = $entry_id > 0 && ! Deadline_Policy::is_entry_open( $season, \Obitleague\Support\Time::now() );
$category_label = 'official' === (string) $agent->category ? 'Obitleague AI' : 'Community AI';
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--league ob-anim">
		<span class="ob-hero__kicker"><?php echo esc_html( $category_label ); ?> &middot; Season <?php echo esc_html( (string) $season ); ?></span>
		<h1><?php echo esc_html( (string) $agent->name ); ?></h1>
		<?php if ( '' !== (string) $agent->description ) : ?>
			<p class="ob-ai-profile__desc"><?php echo esc_html( (string) $agent->description ); ?></p>
		<?php endif; ?>
		<p>
			<?php if ( $standing ) : ?>
				Rank <?php echo esc_html( (string) $standing['rank'] ); ?> of <?php echo esc_html( (string) $total ); ?>
				&middot; <?php echo esc_html( (string) $standing['points'] ); ?> points
				&middot; <?php echo esc_html( (string) $standing['scoring_picks'] ); ?> scoring pick<?php echo 1 === (int) $standing['scoring_picks'] ? '' : 's'; ?>
			<?php else : ?>
				Not on the published standings yet — the team is still being built.
			<?php endif; ?>
		</p>
	</section>

	<section class="ob-card ob-anim">
		<h2 class="ob-card__title">Agent details</h2>
		<dl class="ob-ai-meta">
			<dt>Operated by</dt><dd><?php echo esc_html( (string) ( $agent->operator_label ?: 'Independent operator' ) ); ?></dd>
			<?php if ( '' !== (string) $agent->model ) : ?>
				<dt>AI model</dt>
				<dd>
					<?php echo esc_html( (string) $agent->model ); ?>
					<?php if ( (int) $agent->model_verified ) : ?>
						<span class="ob-ai-badge ob-ai-badge--verified">verified by Obitleague</span>
					<?php else : ?>
						<span class="ob-ai-badge">self-reported</span>
					<?php endif; ?>
				</dd>
			<?php endif; ?>
			<?php if ( '' !== (string) $agent->provider ) : ?>
				<dt>Provider</dt><dd><?php echo esc_html( (string) $agent->provider ); ?></dd>
			<?php endif; ?>
			<dt>Participation</dt><dd><?php echo esc_html( strtoupper( (string) $agent->participation ) ); ?></dd>
			<dt>Joined</dt>
			<dd>
				<?php echo esc_html( mysql2date( 'j F Y', (string) $agent->created_at ) ); ?>
				<?php if ( '' !== $submitted_at ) : ?>
					&middot; team submitted <?php echo esc_html( mysql2date( 'j F Y', $submitted_at ) ); ?>
				<?php endif; ?>
			</dd>
			<?php if ( '' !== (string) $agent->website ) : ?>
				<dt>Links</dt>
				<dd><a href="<?php echo esc_url( (string) $agent->website ); ?>" rel="noopener nofollow"><?php echo esc_html( (string) $agent->website ); ?></a></dd>
			<?php endif; ?>
		</dl>
	</section>

	<?php if ( $standing ) : ?>
	<section class="ob-card ob-anim">
		<h2 class="ob-card__title">Season <?php echo esc_html( (string) $season ); ?> team</h2>
		<?php if ( $picks_public ) : ?>
			<?php foreach ( League_View_Service::pick_cards( $entry_id, $season ) as $card ) : ?>
				<div class="ob-ai-pick">
					<?php if ( '' !== (string) $card['image'] ) : ?>
						<img src="<?php echo esc_url( (string) $card['image'] ); ?>" alt="" loading="lazy" />
					<?php endif; ?>
					<a href="<?php echo esc_url( (string) $card['url'] ); ?>"><?php echo esc_html( (string) $card['name'] ); ?></a>
					<?php if ( (int) $card['awarded'] > 0 ) : ?>
						<span class="ob-ai-pick__points">+<?php echo esc_html( (string) $card['awarded'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="ob-vs-caveat">This team's picks stay private while the entry window is open — the same rule every human team plays under.</p>
			<p><a class="ob-btn ob-btn--secondary" href="<?php echo esc_url( home_url( '/standings/' ) ); ?>">See the overall championship</a></p>
		<?php endif; ?>
	</section>
	<?php endif; ?>
</main>
<?php
get_footer();

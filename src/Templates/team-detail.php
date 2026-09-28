<?php
/**
 * Team detail template: one submitted entry, its ten picks and awards.
 *
 * Privacy: team pages are only reachable for submitted (post-lock) entries;
 * drafts 404 via redirect. Picks are public after lock per ruleset v1.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Modules\League_View_Service;
use Obitleague\Modules\Standings_Service;

$entry_id = (int) get_query_var( 'ob_team_id' );
global $wpdb;

$entry = $wpdb->get_row(
	$wpdb->prepare(
		'SELECT e.*, l.name AS league_name, l.season AS league_season, l.is_main, u.display_name
		 FROM ' . $wpdb->prefix . 'obitleague_entries e
		 JOIN ' . $wpdb->prefix . 'obitleague_leagues l ON l.id = e.league_id
		 JOIN ' . $wpdb->users . " u ON u.ID = e.user_id
		 WHERE e.id = %d AND e.state = 'submitted'",
		$entry_id
	)
);
if ( ! $entry ) {
	wp_safe_redirect( home_url( '/standings/' ) );
	exit;
}

$season = (int) $entry->league_season;
$league_id = (int) $entry->league_id;
$is_main = 1 === (int) $entry->is_main;$picks = League_View_Service::pick_cards( $entry_id, $season );
$rank = null;
$points = 0;
$total = 0;
/*
 * Main and side leagues publish standings the same way, so both read the
 * current generation for this league and season. (The old branch computed
 * an unused page number and repeated the identical lookups.)
 */
$standing = Standings_Service::row_for_user( $league_id, $season, (int) $entry->user_id );
$rank     = $standing ? (int) $standing['rank'] : null;
$points   = $standing ? (int) $standing['points'] : 0;
$total    = Standings_Service::count_current( $league_id, $season );
if ( $rank > 0 ) {
	$rank = (int) $rank;
} else {
	$rank = null;
}
$scoring = 0;
foreach ( $picks as $p ) {
	if ( (int) $p['awarded'] > 0 ) {
		++$scoring;
	}
}

get_header();
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--league ob-anim">
		<span class="ob-hero__kicker">Team &middot; <?php echo $is_main ? 'Main season' : esc_html( (string) $entry->league_name ); ?> &middot; Season <?php echo esc_html( (string) $season ); ?></span>
		<h1><?php echo esc_html( (string) ( $entry->team_name ?: ( $entry->display_name ?: 'Player ' . $entry->user_id ) ) ); ?></h1>
		<?php if ( '' !== (string) $entry->team_name ) : ?><p class="ob-team-owner">Managed by <?php echo esc_html( (string) ( $entry->display_name ?: 'Player ' . $entry->user_id ) ); ?></p><?php endif; ?>
		<p>
			<?php if ( null !== $rank ) : ?>
				Rank <?php echo esc_html( (string) $rank ); ?> of <?php echo esc_html( (string) $total ); ?>
				&middot; <?php echo esc_html( (string) $points ); ?> points
				&middot; <?php echo esc_html( (string) $scoring ); ?> scoring pick<?php echo 1 === $scoring ? '' : 's'; ?>
			<?php else : ?>
				No published standings yet for this league.
			<?php endif; ?>
			&middot; <a href="<?php echo esc_url( $is_main ? home_url( '/standings/' ) : home_url( '/league/' . $league_id . '/' ) ); ?>">Back to <?php echo $is_main ? 'overall standings' : 'the league'; ?></a>
		</p>
	</section>

	<div class="ob-picks ob-picks--page">
		<?php foreach ( $picks as $p ) : ?>
			<?php $p = array_merge( array( 'name' => '', 'url' => '', 'image' => '', 'role' => '', 'is_dead' => false, 'death' => '', 'awarded' => 0, 'slot' => 0 ), (array) $p ); ?>
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
</main>
<?php
get_footer();

<?php
/**
 * Stats page template.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Modules\Shortcodes;
use Obitleague\Modules\Stats_Service;

$season = Shortcodes::season();
$most   = Stats_Service::pick_popularity( $season, 8 );
$least  = array_reverse( Stats_Service::pick_popularity( $season, 40 ) );
$least  = array_slice( array_values( array_filter( $least, static fn ( $r ) => 0 === $r['picks'] && ! $r['is_dead'] ) ), 0, 8 );
$teams  = Stats_Service::top_teams( $season, 10 );
$streaks = Stats_Service::streaking_teams( $season );
$timeline = Stats_Service::timeline( $season );
$profile = Stats_Service::deceased_profile();

get_header();
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--archive ob-anim">
		<span class="ob-hero__kicker">Season <?php echo esc_html( (string) $season ); ?> in numbers</span>
		<h1>Statistics</h1>
		<p>Who the field trusts, which teams are piling up points, and where the season's momentum sits — computed live from the awards ledger.</p>
	</section>

	<div class="ob-stats-boards">
		<section class="ob-card ob-anim">
			<h2 class="ob-card__title">Most picked</h2>
			<?php if ( ! $most ) : ?>
				<p><em>No submitted teams yet.</em></p>
			<?php else : ?>
				<div class="ob-stat-list">
					<?php foreach ( $most as $i => $p ) : ?>
						<div class="ob-stat-row">
							<span class="ob-stat-row__pos"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<?php if ( '' !== $p['image'] ) : ?>
								<img class="ob-pick__img" src="<?php echo esc_url( $p['image'] ); ?>" alt="" loading="lazy" />
							<?php else : ?>
								<span class="ob-pick__img ob-pick__img--initial" aria-hidden="true"><?php echo esc_html( mb_substr( (string) $p['name'], 0, 1 ) ); ?></span>
							<?php endif; ?>
							<span class="ob-stat-row__body">
								<a href="<?php echo esc_url( (string) $p['url'] ); ?>"><?php echo esc_html( (string) $p['name'] ); ?></a>
								<span><?php echo esc_html( (string) $p['picks'] ); ?> team<?php echo 1 === (int) $p['picks'] ? '' : 's'; ?><?php echo (int) $p['points_won'] > 0 ? ' · ' . esc_html( (string) $p['points_won'] ) . ' pts paid' : ''; ?></span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="ob-card ob-anim">
			<h2 class="ob-card__title">Flying under the radar</h2>
			<p class="ob-sub">Living figures no team picked this season.</p>
			<?php if ( ! $least ) : ?>
				<p><em>Every living figure is on at least one team.</em></p>
			<?php else : ?>
				<div class="ob-stat-list">
					<?php foreach ( $least as $p ) : ?>
						<div class="ob-stat-row">
							<?php if ( '' !== $p['image'] ) : ?>
								<img class="ob-pick__img" src="<?php echo esc_url( $p['image'] ); ?>" alt="" loading="lazy" />
							<?php else : ?>
								<span class="ob-pick__img ob-pick__img--initial" aria-hidden="true"><?php echo esc_html( mb_substr( (string) $p['name'], 0, 1 ) ); ?></span>
							<?php endif; ?>
							<span class="ob-stat-row__body">
								<a href="<?php echo esc_url( (string) $p['url'] ); ?>"><?php echo esc_html( (string) $p['name'] ); ?></a>
								<span><?php echo esc_html( (string) $p['role'] ); ?></span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="ob-card ob-anim">
			<h2 class="ob-card__title">Highest-scoring teams</h2>
			<?php if ( ! $teams ) : ?>
				<p><em>No standings published yet.</em></p>
			<?php else : ?>
				<div class="ob-stat-list">
					<?php foreach ( $teams as $i => $t ) : ?>
						<div class="ob-stat-row">
							<span class="ob-stat-row__pos"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<span class="ob-stat-row__body">
								<a href="<?php echo esc_url( home_url( '/team/' . (int) $t['entry_id'] . '/' ) ); ?>"><?php echo esc_html( (string) $t['player'] ); ?></a>
								<span><?php echo ! empty( $t['owner'] ) && $t['owner'] !== $t['player'] ? 'managed by ' . esc_html( (string) $t['owner'] ) . ' · ' : ''; ?><?php echo esc_html( (string) $t['league'] ); ?> · <?php echo esc_html( (string) $t['scoring_picks'] ); ?> scoring pick<?php echo 1 === (int) $t['scoring_picks'] ? '' : 's'; ?></span>
							</span>
							<span class="ob-pts"><?php echo esc_html( (string) $t['points'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="ob-card ob-anim">
			<h2 class="ob-card__title">Streaking teams</h2>
			<p class="ob-sub">Two or more scoring picks within any 14-day window.</p>
			<?php if ( ! $streaks ) : ?>
				<p><em>No streaks yet — points have landed too far apart.</em></p>
			<?php else : ?>
				<div class="ob-stat-list">
					<?php foreach ( $streaks as $s ) : ?>
						<div class="ob-stat-row">
							<span class="ob-stat-row__pos ob-stat-row__pos--fire">◆</span>
							<span class="ob-stat-row__body">
								<a href="<?php echo esc_url( home_url( '/team/' . (int) $s['entry_id'] . '/' ) ); ?>"><?php echo esc_html( (string) $s['player'] ); ?></a>
								<span><?php echo ! empty( $s['owner'] ) && $s['owner'] !== $s['player'] ? 'managed by ' . esc_html( (string) $s['owner'] ) . ' · ' : ''; ?><?php echo esc_html( (string) $s['league'] ); ?> · <?php echo esc_html( (string) $s['streak'] ); ?> in 14 days · <?php echo esc_html( (string) $s['points_in_streak'] ); ?> pts · last <?php echo esc_html( (string) $s['last_scored'] ); ?></span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<?php if ( $timeline ) : ?>
			<section class="ob-card ob-stats-timeline ob-anim">
				<h2 class="ob-card__title">Season momentum</h2>
				<div class="ob-timeline">
					<?php $max = max( array_column( $timeline, 'points' ) ); ?>
					<?php foreach ( $timeline as $t ) : ?>
						<div class="ob-timeline__row">
							<span class="ob-timeline__label"><?php echo esc_html( (string) $t['month_label'] ); ?></span>
							<span class="ob-timeline__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) max( 4, (int) round( 100 * $t['points'] / max( 1, $max ) ) ) ); ?>%"></span></span>
							<span class="ob-timeline__value"><?php echo esc_html( (string) $t['points'] ); ?> pts · <?php echo esc_html( (string) $t['events'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="ob-overall__note">Points awarded per month; the count of approved events follows each bar.</p>
			</section>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $profile['boards'] ) ) : ?>
		<section class="ob-anim ob-stats-profile">
			<h2 class="ob-heading">The shape of the archive</h2>
			<p class="ob-sub">What the <?php echo esc_html( (string) number_format_i18n( (int) $profile['people'] ) ); ?> confirmed lives in the catalogue have in common — occupations, birth dates, names and ages, computed from approved records only.<?php echo ! empty( $profile['headline'] ) ? ' ' . esc_html( (string) $profile['headline'] ) . '.' : ''; ?></p>
			<div class="ob-stats-boards">
				<?php foreach ( $profile['boards'] as $board ) : ?>
					<section class="ob-card ob-anim">
						<h3 class="ob-card__title"><?php echo esc_html( (string) $board['title'] ); ?></h3>
						<p class="ob-sub"><?php echo esc_html( (string) $board['note'] ); ?></p>
						<div class="ob-timeline">
							<?php $board_max = max( 1, max( array_column( $board['rows'], 'count' ) ) ); ?>
							<?php foreach ( $board['rows'] as $row ) : ?>
								<div class="ob-timeline__row">
									<span class="ob-timeline__label"><?php
										if ( ! empty( $row['url'] ) ) {
											echo '<a href="' . esc_url( (string) $row['url'] ) . '">' . esc_html( (string) $row['label'] ) . '</a>';
										} else {
											echo esc_html( (string) $row['label'] );
										}
										?></span>
									<span class="ob-timeline__bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) max( 4, (int) round( 100 * (int) $row['count'] / $board_max ) ) ); ?>%"></span></span>
									<span class="ob-timeline__value"><?php echo esc_html( (string) $row['count'] ); ?> · <?php echo esc_html( (string) $row['share'] ); ?>%</span>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>
</main>
<?php
get_footer();

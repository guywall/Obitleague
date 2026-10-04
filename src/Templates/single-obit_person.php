<?php
/**
 * Person single template (plugin fallback).
 *
 * Editorial profile: hero with monogram and status, life timeline,
 * approved-facts panel, scoring potential and sourced attribution.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Domain\Age;
use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Domain\Value\Ruleset;
use Obitleague\Modules\Death_Wire;
use Obitleague\Modules\Import_Service;
use Obitleague\Modules\Person_Content;
use Obitleague\Modules\People_Sync;
use Obitleague\Modules\Pick_Stats;

$post_id    = (int) get_the_ID();
$name       = get_the_title( $post_id );
// Cleaned: a raw role can carry a leaked cause of death ("actor, cancer").
$role       = Person_Content::descriptor( $post_id );
$birth_raw  = (string) get_post_meta( $post_id, 'obit_birth_date', true );
$death_raw  = (string) get_post_meta( $post_id, 'obit_death_date', true );
$precision  = (string) get_post_meta( $post_id, 'obit_death_precision', true );
$cause_raw  = (string) get_post_meta( $post_id, 'obit_cause_status', true );
$cause_text = (string) get_post_meta( $post_id, 'obit_cause_text', true );
$qid        = (string) get_post_meta( $post_id, 'obit_qid', true );
$enwiki     = (string) get_post_meta( $post_id, 'obit_enwiki', true );
$content    = get_the_content( null, false, $post_id );
$portrait   = (string) get_post_meta( $post_id, People_Sync::META_IMAGE_URL, true );
$credit     = (string) get_post_meta( $post_id, People_Sync::META_IMAGE_CREDIT, true );
$occs       = People_Sync::display_occupation_labels( $post_id );
$occ_primary = People_Sync::primary_occupation_label( $post_id );

$birth = '' !== $birth_raw ? Import_Service::parse_partial( $birth_raw ) : null;
$death = '' !== $death_raw ? Import_Service::parse_partial( $death_raw ) : null;
$is_dead = null !== $death;

// Red flag: a death dated after today cannot be true. Show a loud banner
// and withhold the scoring card until the record is corrected.
$future_death = false;
try {
	if ( $is_dead ) {
		// The stored value may be partial; judge it on its earliest possible day.
		$earliest = $death->interpretations()[0] ?? null;
		if ( null !== $earliest ) {
			$future_death = \Obitleague\Domain\Review_Rules::is_future_death_date(
				array(
					'y' => (int) $earliest->format( 'Y' ),
					'm' => (int) $earliest->format( 'n' ),
					'd' => (int) $earliest->format( 'j' ),
				)
			);
		}
	}
} catch ( \Throwable ) {
	$future_death = false;
}

$age_at_death = null;
$age_now      = null;
try {
	if ( $birth && $is_dead && $death->is_exact() ) {
		$age_at_death = Age::completed_at( $birth, $death->interpretations()[0] );
	}
	if ( $birth && ! $is_dead ) {
		$age_now = Age::completed_at( $birth, new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/London' ) ) );
	}
} catch ( \InvalidArgumentException $e ) {
	// Malformed dates: omit age figures rather than guess.
}

$points           = null !== $age_at_death ? Ruleset::points_for_age( (int) $age_at_death ) : null;
$potential_points = null !== $age_now ? Ruleset::points_for_age( (int) $age_now ) : null;
// Public reporting attached to this death by the wire pipeline.
$news_sources     = $is_dead ? Death_Wire::public_sources( $post_id ) : array();
// Single-sourced with the pick figures below, so the scorecard and the pick
// stats can never be labelled with different seasons.
$season           = Pick_Stats::season_in_play();
$pick_stats       = Pick_Stats::for_person( $post_id );
$wikipedia_url = '' !== $enwiki
	? 'https://en.wikipedia.org/wiki/' . rawurlencode( $enwiki )
	: ( '' !== $qid ? 'https://www.wikidata.org/wiki/Special:GoToLinkedPage/enwiki/' . rawurlencode( $qid ) : '' );
$points_tooltip   = null !== $points ? sprintf( 'Scores %d points under the current rules; age at death: %d.', (int) $points, (int) $age_at_death ) : '';
$potential_tip    = null !== $potential_points ? sprintf( 'Would score %d points if a death were confirmed today; current age: %d.', (int) $potential_points, (int) $age_now ) : 'Potential unavailable because an exact birth date is not recorded.';

get_header();
?>
<main class="obitleague-person ob-page">

	<?php if ( $future_death ) : ?>
		<section class="ob-card ob-future-death" role="alert">
			<h2 class="ob-future-death__title">🚩 Impossible death date</h2>
			<p class="ob-future-death__text">
				<?php
				echo esc_html( sprintf(
					/* translators: 1: person name, 2: the recorded death date. */
					__( 'This record claims %1$s died on %2$s — a date that has not happened yet. A death cannot occur in the future, so this record is wrong and scores nothing until it is corrected.', 'obitleague' ),
					$name,
					$death ? $death->label() : (string) $death_raw
				) );
				?>
			</p>
			<p class="ob-future-death__text ob-future-death__text--minor">
				<?php esc_html_e( 'Editors: re-check the sources and correct the death date in Obitleague → Review or on the person record.', 'obitleague' ); ?>
			</p>
		</section>
	<?php endif; ?>

	<section class="ob-profile ob-anim<?php echo $is_dead ? ' ob-profile--memoriam' : ''; ?>">
		<div class="ob-profile__id">
			<?php if ( '' !== $portrait ) : ?>
				<img class="ob-profile__avatar ob-profile__avatar--img" src="<?php echo esc_url( $portrait ); ?>" alt="<?php echo esc_attr( 'Portrait of ' . $name ); ?>" />
			<?php else : ?>
				<span class="ob-profile__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
			<?php endif; ?>
			<div>
				<span class="ob-profile__kicker"><?php echo $is_dead ? esc_html( 'In memoriam' ) : esc_html( 'Catalogue profile' ); ?></span>
				<h1 class="ob-profile__name"><?php echo esc_html( $name ); ?></h1>
				<?php if ( '' !== $role ) : ?>
					<p class="ob-profile__role"><?php echo esc_html( $role ); ?></p>
				<?php endif; ?>					<?php if ( array() !== $occs ) : ?>
						<?php
						$occ_display = ( '' !== $occ_primary && in_array( $occ_primary, $occs, true ) )
							? $occ_primary . ( count( $occs ) > 1 ? ' · ' . implode( ', ', array_diff( $occs, array( $occ_primary ) ) ) : '' )
							: implode( ', ', $occs );
						?>
						<p class="ob-profile__occ" title="<?php echo esc_attr( implode( ', ', $occs ) ); ?>"><?php echo esc_html( $occ_display ); ?></p>
					<?php endif; ?>
					<?php $occ_tags = People_Sync::display_occupation_links( $post_id ); ?>
					<?php if ( array() !== $occ_tags ) : ?>
						<p class="ob-profile__occ-tags"><?php echo implode( '', $occ_tags ); // pre-escaped links. ?></p>
					<?php endif; ?>
			</div>
			<span class="ob-profile__status"><?php echo $is_dead ? esc_html( 'Confirmed' ) : esc_html( 'Living' ); ?></span>
		</div>

		<?php if ( $pick_stats['is_hot'] || $pick_stats['is_unique'] ) : ?>
			<p class="ob-profile__badges">
				<?php if ( $pick_stats['is_hot'] ) : ?>
					<span class="ob-pick-badge ob-pick-badge--hot"><?php esc_html_e( 'Hot pick', 'obitleague' ); ?></span>
				<?php endif; ?>
				<?php if ( $pick_stats['is_unique'] ) : ?>
					<span class="ob-pick-badge ob-pick-badge--unique"><?php esc_html_e( 'Unique pick', 'obitleague' ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<div class="ob-profile__timeline" role="img" aria-label="<?php echo esc_attr( ( $birth ? 'Born ' . $birth->label() . '. ' : '' ) . ( $is_dead && $death ? 'Died ' . $death->label() . '.' : 'Living.' ) ); ?>">
			<div class="ob-profile__moment">
				<span class="ob-profile__moment-label">Born</span>
				<span class="ob-profile__moment-date"><?php echo $birth ? esc_html( $birth->label() ) : esc_html__( 'Unknown', 'obitleague' ); ?></span>
			</div>
			<div class="ob-profile__span" aria-hidden="true">
				<span class="ob-profile__span-line"></span>
				<?php if ( null !== $age_at_death ) : ?>
					<span class="ob-profile__span-age"><?php echo esc_html( (string) $age_at_death ); ?> years</span>
				<?php elseif ( null !== $age_now ) : ?>
					<span class="ob-profile__span-age"><?php echo esc_html( 'age: ' . (string) $age_now ); ?></span>
				<?php endif; ?>
			</div>
			<div class="ob-profile__moment ob-profile__moment--end">
				<span class="ob-profile__moment-label"><?php echo $is_dead ? esc_html( 'Died' ) : esc_html( 'Today' ); ?></span>
				<span class="ob-profile__moment-date"><?php echo ( $is_dead && $death ) ? esc_html( $death->label() ) : esc_html( date_i18n( 'j F Y' ) ); ?></span>
			</div>
		</div>
	</section>

	<div class="ob-profile__columns">

		<div class="ob-profile__main">
			<?php if ( '' !== trim( $content ) ) : ?>
				<section class="ob-card ob-profile__bio">
					<h2 class="ob-card__title">About <?php echo esc_html( $name ); ?></h2>
					<div class="ob-profile__prose"><?php echo wp_kses_post( apply_filters( 'the_content', $content ) ); ?></div>
				</section>
			<?php endif; ?>

			<section class="ob-card ob-anim">
				<h2 class="ob-card__title">Approved facts</h2>
				<dl class="obitleague-person__facts obitleague-person__facts--panel">
					<?php if ( array() !== $occs ) : ?>
						<dt><?php esc_html_e( 'Occupations', 'obitleague' ); ?></dt>
						<dd><?php echo esc_html( implode( ', ', $occs ) ); ?></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'Born', 'obitleague' ); ?></dt>
					<dd><?php echo $birth ? esc_html( $birth->label() ) : esc_html__( 'Unknown', 'obitleague' ); ?></dd>
					<?php if ( $is_dead && $death ) : ?>
						<dt><?php esc_html_e( 'Died', 'obitleague' ); ?></dt>
						<dd><?php echo esc_html( $death->label() ); ?><?php echo ( 'exact' !== $precision && '' !== $precision ) ? ' <em>(' . esc_html( $precision . ' precision' ) . ')</em>' : ''; ?></dd>
						<?php if ( null !== $age_at_death ) : ?>
							<dt><?php esc_html_e( 'Age', 'obitleague' ); ?></dt>
							<dd><?php echo esc_html( (string) $age_at_death ); ?></dd>
						<?php endif; ?>
						<dt><?php esc_html_e( 'Cause of death', 'obitleague' ); ?></dt>
						<dd><?php
							echo esc_html( Cause_Status::label( '' !== $cause_raw ? $cause_raw : Cause_Status::NOT_DISCLOSED ) );
							if ( '' !== $cause_text ) {
								echo ' — ' . esc_html( $cause_text );
							}
						?></dd>
					<?php endif; ?>
				</dl>
			</section>
		</div>

		<aside class="ob-profile__side">
			<?php if ( $future_death ) : ?>
				<?php // Impossible record: no scoring card at all. ?>
			<?php elseif ( $is_dead && null !== $points ) : ?>
				<section class="ob-card ob-scorecard ob-scorecard--setted">
					<span class="ob-scorecard__kicker">Season <?php echo esc_html( (string) $season ); ?> scoring</span>
					<span class="ob-scorecard__points" role="img" tabindex="0" title="<?php echo esc_attr( $points_tooltip ); ?>" aria-label="<?php echo esc_attr( $points . ' points; ' . $points_tooltip ); ?>"><?php echo esc_html( (string) $points ); ?></span>
					<span class="ob-scorecard__unit">points</span>
				</section>
			<?php elseif ( ! $is_dead ) : ?>
				<section class="ob-card ob-scorecard">
					<span class="ob-scorecard__kicker">Season <?php echo esc_html( (string) $season ); ?> pick</span>
					<span class="ob-scorecard__points ob-scorecard__points--living" role="img" tabindex="0" title="<?php echo esc_attr( $potential_tip ); ?>" aria-label="<?php echo esc_attr( null !== $potential_points ? $potential_points . ' potential points. ' . $potential_tip : $potential_tip ); ?>"><?php echo null !== $potential_points ? esc_html( (string) $potential_points ) : esc_html( '—' ); ?></span>
					<span class="ob-scorecard__unit">potential</span>
				</section>
			<?php endif; ?>

			<?php if ( $pick_stats['picks'] > 0 ) : ?>
				<section class="ob-card ob-pickstats">
					<h2 class="ob-card__title"><?php esc_html_e( 'Picked by', 'obitleague' ); ?></h2>
					<p class="ob-pickstats__figure">
						<span class="ob-pickstats__num"><?php echo esc_html( number_format_i18n( $pick_stats['picks'] ) ); ?></span>
						<span class="ob-pickstats__unit"><?php echo 1 === $pick_stats['picks'] ? esc_html__( 'team', 'obitleague' ) : esc_html__( 'teams', 'obitleague' ); ?></span>
					</p>
					<p class="ob-pickstats__share">
						<?php
						$share = esc_html( Pick_Stats::percent_label( (float) $pick_stats['percent'] ) );
						if ( 0 === strcmp( $share, '&lt;1%' ) ) {
							$share = esc_html__( 'less than 1%', 'obitleague' );
						}
						echo esc_html(
							sprintf(
								/* translators: 1: share of submitted teams, 2: total submitted teams, 3: the season. */
								__( '%1$s of the %2$s submitted teams in season %3$d.', 'obitleague' ),
								$share,
								number_format_i18n( $pick_stats['teams_total'] ),
								(int) $pick_stats['season']
							)
						);
						?>
					</p>
					<?php if ( null !== $pick_stats['rank'] ) : ?>
						<p class="ob-pickstats__rank">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: rank among picked names, e.g. "3rd". */
									__( '%s most picked on the board.', 'obitleague' ),
									Pick_Stats::ordinal( (int) $pick_stats['rank'] )
								)
							);
							?>
							<?php if ( $pick_stats['is_hot'] ) : ?>
								<em><?php echo esc_html( sprintf( __( 'Top %d of every name in play.', 'obitleague' ), Pick_Stats::HOT_LIMIT ) ); ?></em>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( array() !== $pick_stats['leagues'] ) : ?>
						<ul class="ob-pickstats__leagues">
							<?php foreach ( $pick_stats['leagues'] as $league ) : ?>
								<li>
									<a href="<?php echo esc_url( home_url( '/league/' . (int) $league['league_id'] . '/' ) ); ?>"><?php echo esc_html( $league['league_name'] ); ?></a>
									<span><?php echo esc_html( number_format_i18n( (int) $league['picks'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( array() !== $pick_stats['teams'] ) : ?>
						<h3 class="ob-pickstats__subhead"><?php esc_html_e( 'Teams that took them', 'obitleague' ); ?></h3>
						<ul class="ob-pickstats__teams">
							<?php foreach ( $pick_stats['teams'] as $team ) : ?>
								<li>
									<a href="<?php echo esc_url( home_url( '/team/' . (int) $team['entry_id'] . '/' ) ); ?>"><?php echo esc_html( '' !== $team['team_name'] ? $team['team_name'] : __( 'Untitled team', 'obitleague' ) ); ?></a>
									<?php if ( ! $team['is_main'] ) : ?>
										<span><?php echo esc_html( $team['league_name'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php if ( $pick_stats['teams_remaining'] > 0 ) : ?>
							<p class="ob-pickstats__more">
								<?php echo esc_html( sprintf( __( '+ %s more teams', 'obitleague' ), number_format_i18n( $pick_stats['teams_remaining'] ) ) ); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</section>
			<?php elseif ( 0 !== $pick_stats['teams_total'] ) : ?>
				<section class="ob-card ob-pickstats ob-pickstats--unpicked">
					<h2 class="ob-card__title"><?php esc_html_e( 'Picked by', 'obitleague' ); ?></h2>
					<p class="ob-pickstats__share"><?php esc_html_e( 'Nobody has taken this name yet.', 'obitleague' ); ?></p>
				</section>
			<?php endif; ?>

			<?php if ( array() !== $news_sources ) : ?>
				<section class="ob-card ob-sources ob-sources--news">
					<h2 class="ob-card__title"><?php esc_html_e( 'In the news', 'obitleague' ); ?></h2>
					<p class="ob-sources__subhead">Public reporting on this death</p>
					<ul class="ob-sources__news">
						<?php foreach ( $news_sources as $src ) : ?>
							<li>
								<a href="<?php echo esc_url( $src['url'] ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( '' !== $src['name'] ? $src['name'] : __( 'Report', 'obitleague' ) ); ?></a>
								<?php if ( '' !== $src['date'] ) : ?>
									<span><?php echo esc_html( $src['date'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="ob-sources__note">Every report here is attached to the record by an editor and listed as the death's public sourcing.</p>
				</section>
			<?php endif; ?>

			<?php if ( '' !== $qid || '' !== $wikipedia_url || '' !== $portrait ) : ?>
				<section class="ob-card ob-sources">
					<h2 class="ob-card__title">Sources</h2>
					<ul>
						<?php if ( '' !== $portrait ) : ?>
							<li><a href="<?php echo esc_url( $portrait ); ?>">Portrait <span><?php echo esc_html( $credit ?: 'Wikimedia Commons' ); ?></span></a></li>
						<?php endif; ?>
						<?php if ( '' !== $qid ) : ?>
							<li><a href="<?php echo esc_url( 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ) ); ?>">Wikidata <span><?php echo esc_html( $qid ); ?></span></a></li>
						<?php endif; ?>
						<?php if ( '' !== $wikipedia_url ) : ?>
							<li><a href="<?php echo esc_url( $wikipedia_url ); ?>">Wikipedia biography</a></li>
						<?php endif; ?>
					</ul>
					<p class="ob-sources__note">Facts sourced from Wikipedia (CC BY-SA) and Wikidata (CC0). This page reports approved facts only.</p>
				</section>
			<?php endif; ?>

			<a class="ob-profile__back" href="<?php echo esc_url( home_url( '/people/' ) ); ?>">← Back to the catalogue</a>
		</aside>

	</div>
</main>
<?php
get_footer();

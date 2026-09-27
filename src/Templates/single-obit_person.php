<?php
/**
 * Person single template (plugin fallback).
 *
 * Editorial profile: hero with monogram and status, life timeline,
 * approved-facts panel, scoring note and sourced attribution.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Domain\Age;
use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Domain\Value\Ruleset;
use Obitleague\Modules\Import_Service;

$post_id    = (int) get_the_ID();
$name       = get_the_title( $post_id );
$role       = (string) get_post_meta( $post_id, 'obit_role', true );
$birth_raw  = (string) get_post_meta( $post_id, 'obit_birth_date', true );
$death_raw  = (string) get_post_meta( $post_id, 'obit_death_date', true );
$precision  = (string) get_post_meta( $post_id, 'obit_death_precision', true );
$cause_raw  = (string) get_post_meta( $post_id, 'obit_cause_status', true );
$cause_text = (string) get_post_meta( $post_id, 'obit_cause_text', true );
$qid        = (string) get_post_meta( $post_id, 'obit_qid', true );
$enwiki     = (string) get_post_meta( $post_id, 'obit_enwiki', true );
$content    = get_the_content( null, false, $post_id );

$birth = '' !== $birth_raw ? Import_Service::parse_partial( $birth_raw ) : null;
$death = '' !== $death_raw ? Import_Service::parse_partial( $death_raw ) : null;
$is_dead = null !== $death;

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

$points = null !== $age_at_death ? Ruleset::points_for_age( (int) $age_at_death ) : null;
$season = (int) date_i18n( 'Y' );

get_header();
?>
<main class="obitleague-person ob-page">

	<section class="ob-profile ob-anim<?php echo $is_dead ? ' ob-profile--memoriam' : ''; ?>">
		<div class="ob-profile__id">
			<span class="ob-profile__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
			<div>
				<span class="ob-profile__kicker"><?php echo $is_dead ? esc_html( 'In memoriam' ) : esc_html( 'Catalogue profile' ); ?></span>
				<h1 class="ob-profile__name"><?php echo esc_html( $name ); ?></h1>
				<?php if ( '' !== $role ) : ?>
					<p class="ob-profile__role"><?php echo esc_html( $role ); ?></p>
				<?php endif; ?>
			</div>
			<span class="ob-profile__status"><?php echo $is_dead ? esc_html( 'Confirmed' ) : esc_html( 'Living' ); ?></span>
		</div>

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
					<span class="ob-profile__span-age"><?php echo esc_html( (string) $age_now ); ?> today</span>
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
					<h2 class="ob-card__title">Life and career</h2>
					<?php echo wp_kses_post( apply_filters( 'the_content', $content ) ); ?>
				</section>
			<?php endif; ?>

			<section class="ob-card ob-anim">
				<h2 class="ob-card__title">Approved facts</h2>
				<dl class="obitleague-person__facts obitleague-person__facts--panel">
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
			<?php if ( $is_dead && null !== $points ) : ?>
				<section class="ob-card ob-scorecard ob-scorecard--setted">
					<span class="ob-scorecard__kicker">Season <?php echo esc_html( (string) $season ); ?> scoring</span>
					<span class="ob-scorecard__points"><?php echo esc_html( (string) $points ); ?></span>
					<span class="ob-scorecard__unit">points</span>
					<p>Scored as max(1, 100 − age at death), confirmed by the editors.</p>
				</section>
			<?php elseif ( ! $is_dead ) : ?>
				<section class="ob-card ob-scorecard">
					<span class="ob-scorecard__kicker">Season <?php echo esc_html( (string) $season ); ?> pick</span>
					<span class="ob-scorecard__points ob-scorecard__points--living">100</span>
					<span class="ob-scorecard__unit">potential</span>
					<p>A confirmed death in season <?php echo esc_html( (string) $season ); ?> scores max(1, 100 − age at death) — younger lives score more.</p>
				</section>
			<?php endif; ?>

			<?php if ( '' !== $qid || '' !== $enwiki ) : ?>
				<section class="ob-card ob-sources">
					<h2 class="ob-card__title">Sources</h2>
					<ul>
						<?php if ( '' !== $qid ) : ?>
							<li><a href="<?php echo esc_url( 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ) ); ?>">Wikidata <span><?php echo esc_html( $qid ); ?></span></a></li>
						<?php endif; ?>
						<?php if ( '' !== $enwiki ) : ?>
							<li><a href="<?php echo esc_url( 'https://en.wikipedia.org/wiki/' . rawurlencode( $enwiki ) ); ?>">Wikipedia biography</a></li>
						<?php endif; ?>
					</ul>
					<p class="ob-sources__note">Facts sourced from Wikipedia (CC BY-SA) and Wikidata (CC0). This page reports approved facts only.</p>
				</section>
			<?php endif; ?>

			<a class="ob-profile__back" href="<?php echo esc_url( home_url( '/catalogue/' ) ); ?>">← Back to the catalogue</a>
		</aside>

	</div>
</main>
<?php
get_footer();

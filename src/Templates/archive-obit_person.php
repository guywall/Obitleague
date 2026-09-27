<?php
/**
 * Person archive template (plugin fallback).
 *
 * Card-grid catalogue of profiles with status pills, birth/death dates and
 * styled pagination.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Modules\Import_Service;

get_header();
?>
<main class="obitleague-archive ob-page">
	<section class="ob-hero ob-hero--archive ob-anim">
		<span class="ob-hero__kicker">The catalogue</span>
		<h1>Every life on the board</h1>
		<p>Living figures may be selected for teams. When a death is confirmed and approved by the editors, the record is settled here and in the archive.</p>
	</section>

	<?php if ( have_posts() ) : ?>
		<div class="ob-people ob-people--archive">
			<?php
			while ( have_posts() ) :
				the_post();
				$post_id   = (int) get_the_ID();
				$name      = get_the_title( $post_id );
				$role      = (string) get_post_meta( $post_id, 'obit_role', true );
				$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
				$death_raw = (string) get_post_meta( $post_id, 'obit_death_date', true );
				$birth     = null;
				$death     = null;
				try {
					if ( '' !== $birth_raw ) {
						$birth = Import_Service::parse_partial( $birth_raw );
					}
					if ( '' !== $death_raw ) {
						$death = Import_Service::parse_partial( $death_raw );
					}
				} catch ( \InvalidArgumentException $e ) {
					// Leave dates off the listing if malformed.
				}
				$is_dead = null !== $death;
				?>
				<article class="ob-person<?php echo $is_dead ? ' ob-person--dead' : ''; ?> ob-anim">
					<span class="ob-person__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
					<span class="ob-person__status"><?php echo $is_dead ? esc_html( 'In memoriam' ) : esc_html( 'Living' ); ?></span>
					<p class="ob-person__name"><a href="<?php the_permalink(); ?>"><?php echo esc_html( $name ); ?></a></p>
					<?php if ( '' !== $role ) : ?>
						<p class="ob-person__role"><?php echo esc_html( $role ); ?></p>
					<?php endif; ?>
					<p class="ob-person__dates">
						<?php
						if ( $birth ) {
							echo esc_html( 'b. ' . $birth->label() );
						}
						if ( $death ) {
							echo esc_html( ( $birth ? ' · ' : '' ) . 'd. ' . $death->label() );
						}
						?>
					</p>
				</article>
				<?php
			endwhile;
			?>
		</div>

		<div class="ob-archive-pagination">
			<?php
			the_posts_pagination(
				array(
					'mid_size'  => 1,
					'prev_text' => '←',
					'next_text' => '→',
				)
			);
			?>
		</div>
	<?php else : ?>
		<section class="ob-card">
			<p><em>No profiles yet — the catalogue fills as figures are imported and approved.</em></p>
		</section>
	<?php endif; ?>
</main>
<?php
get_footer();

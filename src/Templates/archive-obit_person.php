<?php
/**
 * Person archive template (plugin fallback).
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

get_header();
?>
<main class="obitleague-archive">
	<h1><?php esc_html_e( 'People', 'obitleague' ); ?></h1>
	<p><?php esc_html_e( 'The public catalogue. Living people may be selected for teams; deaths appear here once confirmed and approved.', 'obitleague' ); ?></p>
	<ul class="obitleague-archive__list">
	<?php
	while ( have_posts() ) :
		the_post();
		$post_id   = (int) get_the_ID();
		$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		$death_raw = (string) get_post_meta( $post_id, 'obit_death_date', true );
		?>
		<li>
			<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
			<?php
			$bits = array();
			try {
				if ( '' !== $birth_raw ) {
					$bits[] = 'b. ' . \Obitleague\Modules\Import_Service::parse_partial( $birth_raw )->label();
				}
				if ( '' !== $death_raw ) {
					$bits[] = 'd. ' . \Obitleague\Modules\Import_Service::parse_partial( $death_raw )->label();
				}
			} catch ( \InvalidArgumentException $e ) {
				// Leave dates off the listing if malformed.
			}
			if ( $bits ) {
				echo '<span class="obitleague-archive__dates"> (' . esc_html( implode( ', ', $bits ) ) . ')</span>';
			}
			?>
		</li>
		<?php
	endwhile;
	?>
	</ul>
	<div class="obitleague-archive__pagination">
		<?php the_posts_pagination(); ?>
	</div>
</main>
<?php
get_footer();

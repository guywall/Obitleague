<?php
/**
 * Join league template.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

get_header();
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--archive ob-anim">
		<span class="ob-hero__kicker">Get in the game</span>
		<h1>Join or create a league</h1>
		<p>Leagues are invite-only. An owner shares a code; you join, pick ten figures before the season locks, and score when the editors confirm.</p>
	</section>
	<?php echo do_shortcode( '[obitleague_join]' ); ?>
</main>
<?php
get_footer();

<?php
/** 2027 campaign front page. */
declare( strict_types = 1 );
get_header();
?>
<div class="ob-campaign-page">
	<?php echo do_shortcode( '[obitleague_2027_campaign]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
<?php get_footer();

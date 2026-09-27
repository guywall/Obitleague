<?php
/** Obitleague dedicated signup page. */
declare( strict_types = 1 );
get_header();
?>
<div class="ob-page ob-register-page">
	<?php echo do_shortcode( '[obitleague_register]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
<?php get_footer();

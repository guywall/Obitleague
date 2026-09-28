<?php
/** Branded Obitleague sign-in and password recovery page. */
declare( strict_types = 1 );
get_header();
?>
<main class="ob-page ob-auth-page">
	<?php echo do_shortcode( '[obitleague_login]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</main>
<?php get_footer();

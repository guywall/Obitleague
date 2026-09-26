<?php
/**
 * Person single template (plugin fallback).
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

$post_id     = (int) get_the_ID();
$name        = get_the_title( $post_id );
$role        = (string) get_post_meta( $post_id, 'obit_role', true );
$birth_raw   = (string) get_post_meta( $post_id, 'obit_birth_date', true );
$death_raw   = (string) get_post_meta( $post_id, 'obit_death_date', true );
$precision   = (string) get_post_meta( $post_id, 'obit_death_precision', true );
$cause_raw   = (string) get_post_meta( $post_id, 'obit_cause_status', true );
$cause_text  = (string) get_post_meta( $post_id, 'obit_cause_text', true );
$qid         = (string) get_post_meta( $post_id, 'obit_qid', true );
$enwiki      = (string) get_post_meta( $post_id, 'obit_enwiki', true );
$content     = get_the_content( null, false, $post_id );

get_header();
?>
<main class="obitleague-person">
	<h1 class="obitleague-person__name"><?php echo esc_html( $name ); ?></h1>
	<?php if ( '' !== $role ) : ?>
		<p class="obitleague-person__role"><?php echo esc_html( $role ); ?></p>
	<?php endif; ?>

	<div class="obitleague-person__content">
	<?php
	if ( '' !== trim( $content ) ) {
		echo wp_kses_post( apply_filters( 'the_content', $content ) );
	}
	?>
	</div>

	<dl class="obitleague-person__facts">
		<dt><?php esc_html_e( 'Born', 'obitleague' ); ?></dt>
		<dd><?php
			$birth = '' !== $birth_raw ? \Obitleague\Modules\Import_Service::parse_partial( $birth_raw ) : null;
			echo $birth ? esc_html( $birth->label() ) : esc_html__( 'Unknown', 'obitleague' );
		?></dd>

		<?php if ( '' !== $death_raw ) : ?>
			<dt><?php esc_html_e( 'Died', 'obitleague' ); ?></dt>
			<dd><?php
				$death = \Obitleague\Modules\Import_Service::parse_partial( $death_raw );
				echo esc_html( $death->label() );
				if ( 'exact' !== $precision && '' !== $precision ) {
					echo ' <em>(' . esc_html( $precision . ' precision' ) . ')</em>';
				}
			?></dd>
			<dt><?php esc_html_e( 'Cause of death', 'obitleague' ); ?></dt>
			<dd><?php
				echo esc_html( \Obitleague\Domain\Value\Cause_Status::label( '' !== $cause_raw ? $cause_raw : 'not_disclosed' ) );
				if ( '' !== $cause_text ) {
					echo ' — ' . esc_html( $cause_text );
				}
			?></dd>
		<?php endif; ?>
	</dl>

	<?php if ( '' !== $qid || '' !== $enwiki ) : ?>
		<p class="obitleague-person__sources">
			<strong><?php esc_html_e( 'Sources:', 'obitleague' ); ?></strong>
			<?php if ( '' !== $qid ) : ?>
				<a href="<?php echo esc_url( 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ) ); ?>">Wikidata<?php echo ' (' . esc_html( $qid ) . ')'; ?></a>
			<?php endif; ?>
			<?php if ( '' !== $enwiki ) : ?>
				<a href="<?php echo esc_url( 'https://en.wikipedia.org/wiki/' . rawurlencode( $enwiki ) ); ?>"><?php esc_html_e( 'Wikipedia biography', 'obitleague' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<p class="obitleague-person__attribution">
		<?php esc_html_e( 'Facts sourced from Wikipedia (CC BY-SA) and Wikidata (CC0). This page reports approved facts only.', 'obitleague' ); ?>
	</p>
</main>
<?php
get_footer();

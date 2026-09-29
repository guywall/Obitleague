<?php
/**
 * Single forum topic: opening post, replies, reply form, moderation.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Modules\Forum;

$slug     = (string) get_query_var( 'ob_forum_topic' );
$topic    = Forum::topic( $slug );
if ( ! $topic ) {
	wp_safe_redirect( home_url( '/forum/' ) );
	exit;
}

$replies    = Forum::replies( (int) $topic->id );
$locked     = 'locked' === (string) $topic->status;
$can_post   = Forum::user_can_post() && ! $locked;
$can_mod    = Forum::user_can_moderate();
$nonce      = wp_create_nonce( 'obitleague_forum' );
$notice     = isset( $_GET['forum_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['forum_notice'] ) ) : '';
$error      = isset( $_GET['forum_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['forum_error'] ) ) : '';

get_header();
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--archive ob-anim">
		<span class="ob-hero__kicker"><a href="<?php echo esc_url( home_url( '/forum/' ) ); ?>">Forum</a> &middot; thread</span>
		<h1><?php echo esc_html( (string) $topic->title ); ?></h1>
		<p>
			Started by <?php echo esc_html( (string) get_the_author_meta( 'display_name', (int) $topic->author_id ) ?: 'Player #' . (int) $topic->author_id ); ?>
			&middot; <?php echo esc_html( mysql2date( 'j M Y', (string) $topic->created_at ) ); ?>
			<?php echo $locked ? ' &middot; <strong>Locked — replies are closed.</strong>' : ''; ?>
			<?php echo $topic->pinned ? ' &middot; <strong>Pinned.</strong>' : ''; ?>
		</p>
	</section>

	<?php if ( '' !== $notice ) : ?>
		<div class="ob-forum-note" role="status"><?php echo esc_html( $notice ); ?></div>
	<?php endif; ?>
	<?php if ( '' !== $error ) : ?>
		<div class="ob-forum-note ob-forum-note--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<section class="ob-card ob-anim">
		<article class="ob-forum-post">
			<header class="ob-forum-post__head">
				<span class="ob-forum-post__author"><?php echo esc_html( (string) get_the_author_meta( 'display_name', (int) $topic->author_id ) ?: 'Player #' . (int) $topic->author_id ); ?></span>
				<span class="ob-forum-post__time"><?php echo esc_html( mysql2date( 'j M Y, H:i', (string) $topic->created_at ) ); ?></span>
			</header>
			<div class="ob-forum-post__body"><?php echo wp_kses_post( wpautop( (string) $topic->body ) ); ?></div>
		</article>
	</section>

	<?php if ( $replies ) : ?>
		<section class="ob-card ob-anim">
			<h2 class="ob-card__title"><?php echo esc_html( (string) count( $replies ) ); ?> <?php echo 1 === count( $replies ) ? 'reply' : 'replies'; ?></h2>
			<div class="ob-forum-posts">
				<?php foreach ( $replies as $r ) : ?>
					<article class="ob-forum-post">
						<header class="ob-forum-post__head">
							<span class="ob-forum-post__author"><?php echo esc_html( (string) $r['author'] ); ?></span>
							<span class="ob-forum-post__time"><?php echo esc_html( (string) $r['created'] ); ?></span>
						</header>
						<div class="ob-forum-post__body"><?php echo wp_kses_post( wpautop( (string) $r['body'] ) ); ?></div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

	<section class="ob-card ob-anim">
		<?php if ( $locked ) : ?>
			<h2 class="ob-card__title">Replies are closed</h2>
			<p>A moderator has locked this thread.</p>
		<?php elseif ( ! is_user_logged_in() ) : ?>
			<h2 class="ob-card__title">Join the conversation</h2>
			<p><a href="<?php echo esc_url( home_url( '/login/?redirect_to=' . rawurlencode( home_url( '/forum/' . rawurlencode( $slug ) . '/' ) ) ) ); ?>">Sign in</a> to reply to this thread.</p>
		<?php elseif ( ! Forum::user_can_post() ) : ?>
			<h2 class="ob-card__title">Join the conversation</h2>
			<p>Verify your email to reply. Check your inbox for the verification link.</p>
		<?php else : ?>
			<h2 class="ob-card__title">Reply</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-forum-form">
				<?php wp_nonce_field( 'obitleague_forum', 'ob_forum_nonce' ); ?>
				<input type="hidden" name="action" value="obitleague_forum_post" />
				<input type="hidden" name="ob_forum_kind" value="reply" />
				<input type="hidden" name="topic_id" value="<?php echo esc_attr( (string) (int) $topic->id ); ?>" />
				<label for="ob-forum-reply">Your reply</label>
				<textarea id="ob-forum-reply" name="ob_forum_body" rows="4" maxlength="5000" required placeholder="Add to the thread."></textarea>
				<button class="ob-btn" type="submit">Post reply</button>
			</form>
		<?php endif; ?>
	</section>

	<?php if ( $can_mod ) : ?>
		<section class="ob-card ob-forum-mod ob-anim">
			<h2 class="ob-card__title">Moderation</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-forum-mod__actions">
				<?php wp_nonce_field( 'obitleague_forum', 'ob_forum_nonce' ); ?>
				<input type="hidden" name="action" value="obitleague_forum_moderate" />
				<input type="hidden" name="topic_id" value="<?php echo esc_attr( (string) (int) $topic->id ); ?>" />
				<button class="ob-btn ob-btn--secondary" type="submit" name="ob_moderate" value="<?php echo $topic->pinned ? 'unpin' : 'pin'; ?>"><?php echo $topic->pinned ? 'Unpin' : 'Pin'; ?></button>
				<button class="ob-btn ob-btn--secondary" type="submit" name="ob_moderate" value="<?php echo $locked ? 'unlock' : 'lock'; ?>"><?php echo $locked ? 'Unlock' : 'Lock'; ?></button>
				<button class="ob-btn ob-btn--danger" type="submit" name="ob_moderate" value="delete" data-ob-confirm="Delete this thread and every reply? This cannot be undone.">Delete</button>
			</form>
		</section>
	<?php endif; ?>
</main>
<script>
(function(){
	document.querySelectorAll('[data-ob-confirm]').forEach(function(btn){
		btn.addEventListener('click',function(e){
			if(!window.confirm(btn.getAttribute('data-ob-confirm'))){e.preventDefault();}
		});
	});
})();
</script>
<?php
get_footer();

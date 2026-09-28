<?php
/**
 * Forum index template: pinned topics first, newest activity next.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

use Obitleague\Modules\Forum;

$page     = max( 1, (int) get_query_var( 'ob_forum_page' ) );
$per_page = 20;
$topics   = Forum::topics( $page, $per_page );
$total    = Forum::topic_count();
$can_post = Forum::user_can_post();
$nonce    = wp_create_nonce( 'obitleague_forum' );
$notice   = isset( $_GET['forum_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['forum_notice'] ) ) : '';
$error    = isset( $_GET['forum_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['forum_error'] ) ) : '';

get_header();
?>
<main class="ob-page">
	<section class="ob-hero ob-hero--archive ob-anim">
		<span class="ob-hero__kicker">The clubhouse</span>
		<h1>Forum</h1>
		<p>Talk picks, tactics and the season with the rest of the league. Be decent — the same respect the catalogue is written with applies here.</p>
	</section>

	<?php if ( '' !== $notice ) : ?>
		<div class="ob-forum-note" role="status"><?php echo esc_html( $notice ); ?></div>
	<?php endif; ?>
	<?php if ( '' !== $error ) : ?>
		<div class="ob-forum-note ob-forum-note--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<?php if ( ! $topics ) : ?>
		<section class="ob-card"><p><em>No threads yet. Start the first one below.</em></p></section>
	<?php else : ?>
		<section class="ob-card ob-forum-list ob-anim">
			<div class="ob-forum-topic<?php echo $t['pinned'] ? ' is-pinned' : ''; ?>">
				<?php foreach ( $topics as $t ) : ?>
					<div class="ob-forum-topic__row">
						<span class="ob-forum-topic__main">
							<a class="ob-forum-topic__title" href="<?php echo esc_url( home_url( '/forum/' . rawurlencode( (string) $t['slug'] ) . '/' ) ); ?>"><?php echo esc_html( (string) $t['title'] ); ?></a>
							<span class="ob-forum-topic__meta">
								<?php echo $t['pinned'] ? '<span class="ob-badge ob-badge--brass">Pinned</span>' : ''; ?>
								started by <?php echo esc_html( (string) $t['author'] ); ?> · <?php echo esc_html( (string) $t['started'] ); ?>
							</span>
						</span>
						<span class="ob-forum-topic__counts">
							<span><?php echo esc_html( (string) $t['replies'] ); ?> <?php echo 1 === (int) $t['replies'] ? 'reply' : 'replies'; ?></span>
							<span class="ob-forum-topic__last">last by <?php echo esc_html( (string) ( $t['last_by'] ?: $t['author'] ) ); ?> · <?php echo esc_html( (string) $t['last_activity'] ); ?></span>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $total > $per_page ) : ?>
				<nav class="ob-forum-pages" aria-label="Forum pages">
					<?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( home_url( '/forum/page/' . ( $page - 1 ) . '/' ) ); ?>">&larr; Newer</a><?php endif; ?>
					<span>Page <?php echo esc_html( (string) $page ); ?> of <?php echo esc_html( (string) (int) ceil( $total / $per_page ) ); ?></span>
					<?php if ( $page * $per_page < $total ) : ?><a href="<?php echo esc_url( home_url( '/forum/page/' . ( $page + 1 ) . '/' ) ); ?>">Older &rarr;</a><?php endif; ?>
				</nav>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<section class="ob-card ob-anim">
		<h2 class="ob-card__title">Start a new thread</h2>
		<?php if ( ! is_user_logged_in() ) : ?>
			<p><a href="<?php echo esc_url( home_url( '/login/?redirect_to=' . rawurlencode( home_url( '/forum/' ) ) ) ); ?>">Sign in</a> to start a thread or reply.</p>
		<?php elseif ( ! $can_post ) : ?>
			<p>Verify your email to join the conversation. Check your inbox for the verification link.</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-forum-form">
				<?php wp_nonce_field( 'obitleague_forum', 'ob_forum_nonce' ); ?>
				<input type="hidden" name="action" value="obitleague_forum_post" />
				<input type="hidden" name="ob_forum_kind" value="topic" />
				<label for="ob-forum-title">Thread title</label>
				<input id="ob-forum-title" type="text" name="ob_forum_title" maxlength="190" required placeholder="What's the thread about?" />
				<label for="ob-forum-body">Opening post</label>
				<textarea id="ob-forum-body" name="ob_forum_body" rows="5" maxlength="5000" required placeholder="Say it in your own words. Links are fine."></textarea>
				<button class="ob-btn" type="submit">Post thread</button>
			</form>
		<?php endif; ?>
	</section>
</main>
<?php
get_footer();

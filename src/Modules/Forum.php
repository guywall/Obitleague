<?php
/**
 * Community forum.
 *
 * Lightweight discussion threads on plugin tables: anyone may read,
 * verified signed-in players may post and reply. Moderation (pin, lock,
 * delete) is restricted to administrators and reviewers. Routing mirrors
 * Game_Pages: plain rewrites intercepted at template_include so the forum
 * keeps the full site chrome.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Forum {

	private const CAP = 'obitleague_review';

	private function __construct() {}

	public static function boot(): void {
		add_action( 'init', array( self::class, 'rewrites' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'template_include', array( self::class, 'maybe_route' ), 30 );
		add_action( 'admin_post_obitleague_forum_post', array( self::class, 'handle_post' ) );
		add_action( 'admin_post_obitleague_forum_moderate', array( self::class, 'handle_moderate' ) );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^forum/?$', 'index.php?ob_forum=all', 'top' );
		add_rewrite_rule( '^forum/page/(\d+)/?$', 'index.php?ob_forum=all&ob_forum_page=$matches[1]', 'top' );
		add_rewrite_rule( '^forum/([^/]+)/?$', 'index.php?ob_forum_topic=$matches[1]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'ob_forum';
		$vars[] = 'ob_forum_page';
		$vars[] = 'ob_forum_topic';
		return $vars;
	}

	public static function maybe_route( string $template ): string {
		if ( '' !== (string) get_query_var( 'ob_forum_topic' ) ) {
			return OBITLEAGUE_DIR . 'src/Templates/forum-topic.php';
		}
		if ( '' !== (string) get_query_var( 'ob_forum' ) ) {
			return OBITLEAGUE_DIR . 'src/Templates/forum.php';
		}
		return $template;
	}

	/* ================= queries ================= */

	private static function table_topics(): string {
		global $wpdb;
		return $wpdb->prefix . 'obitleague_forum_topics';
	}

	private static function table_posts(): string {
		global $wpdb;
		return $wpdb->prefix . 'obitleague_forum_posts';
	}

	/**
	 * Topic list for the index page: pinned topics first, then by latest
	 * activity, with reply counts and the last poster.
	 *
	 * @return array[]
	 */
	public static function topics( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$topics   = self::table_topics();
		$posts    = self::table_posts();
		$offset   = max( 0, ( $page - 1 ) * $per_page );
		$rows     = $wpdb->get_results(
			"SELECT t.*, u.display_name,
				(SELECT COUNT(*) FROM {$posts} p WHERE p.topic_id = t.id AND p.status = 'visible') AS replies
			 FROM {$topics} t
			 LEFT JOIN {$wpdb->users} u ON u.ID = t.author_id
			 WHERE t.status = 'open'
			 ORDER BY t.pinned DESC, t.last_activity_at DESC
			 LIMIT {$per_page} OFFSET {$offset}"
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$last = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT p.author_id, p.created_at, u.display_name
					 FROM {$posts} p LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id
					 WHERE p.topic_id = %d AND p.status = 'visible'
					 ORDER BY p.id DESC LIMIT 1",
					(int) $row->id
				)
			);
			$out[] = array(
				'id'        => (int) $row->id,
				'title'     => (string) $row->title,
				'slug'      => (string) $row->slug,
				'author'    => (string) ( $row->display_name ?: 'Player #' . (int) $row->author_id ),
				'pinned'    => (bool) $row->pinned,
				'locked'    => 'locked' === (string) $row->status,
				'replies'   => (int) $row->replies,
				'started'   => mysql2date( 'j M Y', (string) $row->created_at ),
				'last_activity' => mysql2date( 'j M, H:i', (string) $row->last_activity_at ),
				'last_by'   => $last ? (string) ( $last->display_name ?: 'Player #' . (int) $last->author_id ) : '',
			);
		}
		return $out;
	}

	public static function topic_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_topics() . " WHERE status = 'open'" );
	}

	/** One topic by slug (or numeric id). */
	public static function topic( string $slug ): ?object {
		global $wpdb;
		if ( preg_match( '/^\d+$/', $slug ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_topics() . ' WHERE id = %d', (int) $slug ) );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_topics() . ' WHERE slug = %s', $slug ) );
		}
		return $row ?: null;
	}

	/** Visible replies for a topic, oldest first. */
	public static function replies( int $topic_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.*, u.display_name FROM ' . self::table_posts() . ' p
				 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = p.author_id
				 WHERE p.topic_id = %d AND p.status = %s ORDER BY p.id ASC LIMIT 500',
				$topic_id,
				'visible'
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'      => (int) $row->id,
				'author'  => (string) ( $row->display_name ?: 'Player #' . (int) $row->author_id ),
				'body'    => (string) $row->body,
				'created' => mysql2date( 'j M Y, H:i', (string) $row->created_at ),
			);
		}
		return $out;
	}

	/** Can the current user start threads and reply? */
	public static function user_can_post(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		// Campaign accounts must verify their email first; others may post.
		if ( '0' === (string) get_user_meta( get_current_user_id(), 'obitleague_email_verified', true )
			&& '1' === (string) get_user_meta( get_current_user_id(), 'obitleague_campaign_signup', true ) ) {
			return false;
		}
		return true;
	}

	public static function user_can_moderate(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( self::CAP );
	}

	/* ================= writes ================= */

	private static function unique_slug( string $title, int $ignore_id = 0 ): string {
		$base = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'topic';
		}
		if ( mb_strlen( $base ) > 180 ) {
			$base = substr( $base, 0, 180 );
		}
		return $base;
	}

	/** Create a topic; returns the new topic id. */
	public static function create_topic( int $author_id, string $title, string $body ): int {
		global $wpdb;
		$title = trim( sanitize_text_field( $title ) );
		$body  = trim( wp_kses_post( $body ) );
		if ( '' === $title || mb_strlen( $title ) > 190 ) {
			throw new \InvalidArgumentException( 'Give your thread a title of 1–190 characters.' );
		}
		if ( '' === $body || mb_strlen( $body ) > 5000 ) {
			throw new \InvalidArgumentException( 'Write an opening post of 1–5000 characters.' );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->insert(
				self::table_topics(),
				array(
					'title'            => $title,
					'slug'             => '',
					'author_id'        => $author_id,
					'body'             => $body,
					'status'           => 'open',
					'created_at'       => $now,
					'last_activity_at' => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			$topic_id = (int) $wpdb->insert_id;
			$wpdb->update(
				self::table_topics(),
				array( 'slug' => self::unique_slug( $title, $topic_id ) ),
				array( 'id' => $topic_id ),
				array( '%s' ),
				array( '%d' )
			);
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		return $topic_id;
	}

	/** Append a reply and bump the topic's activity stamp. */
	public static function add_reply( int $topic_id, int $author_id, string $body ): void {
		global $wpdb;
		$topic = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . self::table_topics() . ' WHERE id = %d', $topic_id ) );
		if ( ! $topic || 'open' !== (string) $topic->status ) {
			throw new \InvalidArgumentException( 'This thread is locked or missing.' );
		}
		$body = trim( wp_kses_post( $body ) );
		if ( '' === $body || mb_strlen( $body ) > 5000 ) {
			throw new \InvalidArgumentException( 'Write a reply of 1–5000 characters.' );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->insert(
				self::table_posts(),
				array(
					'topic_id'   => $topic_id,
					'author_id'  => $author_id,
					'body'       => $body,
					'status'     => 'visible',
					'created_at' => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			$wpdb->update(
				self::table_topics(),
				array( 'last_activity_at' => $now ),
				array( 'id' => $topic_id ),
				array( '%s' ),
				array( '%d' )
			);
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/** Moderation: pin, unpin, lock, unlock or delete a topic. */
	public static function moderate_topic( int $topic_id, string $action ): void {
		global $wpdb;
		$topic = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::table_topics() . ' WHERE id = %d', $topic_id ) );
		if ( ! $topic ) {
			throw new \InvalidArgumentException( 'Topic not found.' );
		}
		switch ( $action ) {
			case 'pin':
				$wpdb->update( self::table_topics(), array( 'pinned' => 1 ), array( 'id' => $topic_id ), array( '%d' ), array( '%d' ) );
				break;
			case 'unpin':
				$wpdb->update( self::table_topics(), array( 'pinned' => 0 ), array( 'id' => $topic_id ), array( '%d' ), array( '%d' ) );
				break;
			case 'lock':
				$wpdb->update( self::table_topics(), array( 'status' => 'locked' ), array( 'id' => $topic_id ), array( '%s' ), array( '%d' ) );
				break;
			case 'unlock':
				$wpdb->update( self::table_topics(), array( 'status' => 'open' ), array( 'id' => $topic_id ), array( '%s' ), array( '%d' ) );
				break;
			case 'delete':
				$wpdb->query( 'START TRANSACTION' );
				try {
					$wpdb->delete( self::table_posts(), array( 'topic_id' => $topic_id ), array( '%d' ) );
					$wpdb->delete( self::table_topics(), array( 'id' => $topic_id ), array( '%d' ) );
					$wpdb->query( 'COMMIT' );
				} catch ( \Throwable $e ) {
					$wpdb->query( 'ROLLBACK' );
					throw $e;
				}
				break;
			default:
				throw new \InvalidArgumentException( 'Unknown moderation action.' );
		}
	}

	/* ================= form handling ================= */

	private static function forum_url(): string {
		return home_url( '/forum/' );
	}

	private static function topic_url( object $topic ): string {
		return home_url( '/forum/' . (string) $topic->slug . '/' );
	}

	private static function back_with( string $url, array $args ): void {
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	public static function handle_post(): void {
		$kind = isset( $_POST['ob_forum_kind'] ) ? sanitize_key( wp_unslash( (string) $_POST['ob_forum_kind'] ) ) : '';
		$topic_id = isset( $_POST['topic_id'] ) ? absint( $_POST['topic_id'] ) : 0;
		$back     = 'reply' === $kind && $topic_id ? self::topic_url( (object) array( 'slug' => (string) $topic_id ) ) : self::forum_url();
		if ( ! isset( $_POST['ob_forum_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['ob_forum_nonce'] ) ), 'obitleague_forum' ) ) {
			self::back_with( $back, array( 'forum_error' => 'That form expired. Please try again.' ) );
		}
		if ( ! self::user_can_post() ) {
			self::back_with( $back, array( 'forum_error' => 'Sign in (with a verified email) to post in the forum.' ) );
		}
		$body  = isset( $_POST['ob_forum_body'] ) ? (string) wp_unslash( (string) $_POST['ob_forum_body'] ) : '';
		$title = isset( $_POST['ob_forum_title'] ) ? (string) wp_unslash( (string) $_POST['ob_forum_title'] ) : '';
		try {
			if ( 'topic' === $kind ) {
				$topic_id = self::create_topic( get_current_user_id(), $title, $body );
				$topic    = self::topic( (string) $topic_id );
				self::back_with( $topic ? self::topic_url( $topic ) : self::forum_url(), array( 'forum_notice' => 'Thread started.' ) );
			}
			if ( 'reply' === $kind && $topic_id ) {
				self::add_reply( $topic_id, get_current_user_id(), $body );
				self::back_with( self::topic_url( self::topic( (string) $topic_id ) ?? (object) array( 'slug' => (string) $topic_id ) ), array( 'forum_notice' => 'Reply posted.' ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			self::back_with( $back, array( 'forum_error' => $e->getMessage() ) );
		} catch ( \Throwable $e ) {
			self::back_with( $back, array( 'forum_error' => 'Something went wrong saving your post. Please try again.' ) );
		}
		self::back_with( self::forum_url(), array( 'forum_error' => 'Unknown forum action.' ) );
	}

	public static function handle_moderate(): void {
		$topic_id = isset( $_POST['topic_id'] ) ? absint( $_POST['topic_id'] ) : 0;
		$action   = isset( $_POST['ob_moderate'] ) ? sanitize_key( wp_unslash( (string) $_POST['ob_moderate'] ) ) : '';
		$back     = $topic_id ? self::topic_url( self::topic( (string) $topic_id ) ?? (object) array( 'slug' => (string) $topic_id ) ) : self::forum_url();
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( self::CAP ) ) {
			self::back_with( self::forum_url(), array( 'forum_error' => 'You are not allowed to moderate the forum.' ) );
		}
		if ( ! isset( $_POST['ob_forum_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['ob_forum_nonce'] ) ), 'obitleague_forum' ) ) {
			self::back_with( $back, array( 'forum_error' => 'That form expired. Please try again.' ) );
		}
		try {
			self::moderate_topic( $topic_id, $action );
			$messages = array(
				'pin'    => 'Topic pinned.',
				'unpin'  => 'Topic unpinned.',
				'lock'   => 'Topic locked.',
				'unlock' => 'Topic unlocked.',
				'delete' => 'Topic deleted.',
			);
			self::back_with( 'delete' === $action ? self::forum_url() : $back, array( 'forum_notice' => $messages[ $action ] ?? 'Done.' ) );
		} catch ( \Throwable $e ) {
			self::back_with( $back, array( 'forum_error' => $e->getMessage() ) );
		}
	}
}

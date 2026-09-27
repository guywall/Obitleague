<?php
/** Dedicated public registration and email-verification flow. */
declare( strict_types = 1 );

namespace Obitleague\Modules;

use WP_Error;
use WP_User;

final class Auth {
	private const VERIFY_META = 'obitleague_verify_hash';
	private const EXPIRES_META = 'obitleague_verify_expires';
	private const VERIFIED_META = 'obitleague_email_verified';
	private const CAMPAIGN_META = 'obitleague_campaign_signup';
	private const TOKEN_TTL = 86400;

	private function __construct() {}

	public static function boot(): void {
		add_shortcode( 'obitleague_register', array( self::class, 'register_shortcode' ) );
		add_action( 'template_redirect', array( self::class, 'verify_request' ), 1 );
		add_filter( 'authenticate', array( self::class, 'block_unverified_login' ), 30, 3 );
	}

	public static function register_shortcode(): string {
		wp_enqueue_style( 'obitleague-campaign', OBITLEAGUE_DIR_URL . 'assets/campaign.css', array( 'obitleague' ), OBITLEAGUE_VERSION );
		if ( is_user_logged_in() ) {
			return Shortcodes::enqueue() . '<section class="ob-card ob-auth-card"><h2>You’re already in.</h2><p>Your 2027 team is waiting.</p><a class="ob-btn" href="' . esc_url( home_url( '/my-leagues/#build-team' ) ) . '">Build your team</a></section>';
		}
		$message = '';
		$success = false;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['obitleague_register_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( (string) $_POST['obitleague_register_nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'obitleague_register' ) ) {
				$message = 'That signup form expired. Please try again.';
			} elseif ( ! empty( $_POST['ob_company'] ) ) {
				$success = true;
			} else {
				$result = self::register_account(
					sanitize_email( wp_unslash( (string) ( $_POST['ob_email'] ?? '' ) ) ),
					(string) wp_unslash( (string) ( $_POST['ob_password'] ?? '' ) ),
					(string) ( $_SERVER['REMOTE_ADDR'] ?? '' )
				);
				$success = true === $result;
				$message = is_string( $result ) ? $result : '';
			}
		}
		$out = Shortcodes::enqueue() . '<section class="ob-card ob-auth-card"><span class="ob-hero__kicker">2027 season · join the game</span><h1>Create your Obitleague account</h1><p>We’ll email you a verification link. Verify your address to open your team builder.</p>';
		if ( isset( $_GET['verification'] ) && 'invalid' === sanitize_key( (string) $_GET['verification'] ) ) {
			$out .= '<p class="ob-auth-message ob-auth-message--error" role="alert">That verification link is invalid or expired. Request a fresh link below.</p>';
		}
		if ( $success ) {
			$out .= '<p class="ob-auth-message" role="status">If this address can be registered, a verification email will arrive shortly. Check spam; the link expires in 24 hours.</p>';
		} else {
			if ( '' !== $message ) {
				$out .= '<p class="ob-auth-message ob-auth-message--error" role="alert">' . esc_html( $message ) . '</p>';
			}
			$out .= '<form method="post" class="ob-auth-form">' . wp_nonce_field( 'obitleague_register', 'obitleague_register_nonce', true, false );
			$out .= '<label for="ob-email">Email address</label><input id="ob-email" type="email" name="ob_email" autocomplete="email" required maxlength="254" />';
			$out .= '<label for="ob-password">Password</label><input id="ob-password" type="password" name="ob_password" autocomplete="new-password" required minlength="12" /><small>Use at least 12 characters.</small>';
			$out .= '<label class="ob-auth-honeypot" aria-hidden="true" for="ob-company">Company</label><input class="ob-auth-honeypot" id="ob-company" type="text" name="ob_company" tabindex="-1" autocomplete="off" />';
			$out .= '<button class="ob-btn" type="submit">Create account &amp; choose a team</button></form>';
			$out .= '<p>Already registered? <a href="' . esc_url( wp_login_url( home_url( '/my-leagues/' ) ) ) . '">Sign in</a>.</p>';
		}
		return $out . '</section>';
	}

	private static function register_account( string $email, string $password, string $ip ) {
		if ( ! is_email( $email ) || strlen( $email ) > 254 ) {
			return 'Enter a valid email address.';
		}
		if ( strlen( $password ) < 12 || strlen( $password ) > 4096 ) {
			return 'Choose a password between 12 and 4096 characters.';
		}
		if ( ! self::allow_attempt( 'ip:' . $ip, 10, HOUR_IN_SECONDS ) || ! self::allow_attempt( 'email:' . strtolower( $email ), 4, HOUR_IN_SECONDS ) ) {
			return 'Too many attempts. Please wait a while and try again.';
		}
		$user = get_user_by( 'email', $email );
		if ( $user && ( '1' === (string) get_user_meta( $user->ID, self::VERIFIED_META, true ) || '1' !== (string) get_user_meta( $user->ID, self::CAMPAIGN_META, true ) ) ) {
			return true; // Generic response protects both existing and verified addresses.
		}
		if ( ! $user ) {
			$local = sanitize_user( (string) strtok( $email, '@' ), true );
			$login = substr( '' !== $local ? $local : 'player', 0, 40 ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
			$user_id = wp_insert_user( array(
				'user_login' => $login,
				'user_pass' => $password,
				'user_email' => $email,
				'display_name' => $login,
				'role' => 'subscriber',
			) );
			if ( is_wp_error( $user_id ) ) {
				return 'We couldn’t create the account. Please check your details and try again.';
			}
			update_user_meta( (int) $user_id, self::VERIFIED_META, '0' );
			update_user_meta( (int) $user_id, self::CAMPAIGN_META, '1' );
			$user = get_user_by( 'id', (int) $user_id );
		}
		if ( ! $user instanceof WP_User ) {
			return 'We couldn’t create the account. Please try again.';
		}
		return self::send_verification( $user ) ? true : 'We couldn’t send the verification email right now. Please try again shortly.';
	}

	private static function send_verification( WP_User $user ): bool {
		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta( $user->ID, self::VERIFY_META, hash( 'sha256', $token ) );
		update_user_meta( $user->ID, self::EXPIRES_META, (string) ( time() + self::TOKEN_TTL ) );
		$url = add_query_arg( array( 'uid' => $user->ID, 'token' => $token ), home_url( '/verify-email/' ) );
		$subject = 'Verify your Obitleague email';
		$body = "Welcome to Obitleague’s 2027 season.\n\nVerify your email and open your team builder:\n{$url}\n\nThis link expires in 24 hours. If you did not request an account, ignore this email.";
		return (bool) wp_mail( $user->user_email, $subject, $body );
	}

	private static function allow_attempt( string $key, int $limit, int $window ): bool {
		$name = 'ob_signup_' . md5( $key );
		$count = (int) get_transient( $name );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $name, $count + 1, $window );
		return true;
	}

	public static function verify_request(): void {
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( 'verify-email' !== $path || empty( $_GET['uid'] ) || empty( $_GET['token'] ) ) {
			return;
		}
		$user_id = absint( $_GET['uid'] );
		$token = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) );
		$stored = (string) get_user_meta( $user_id, self::VERIFY_META, true );
		$expires = (int) get_user_meta( $user_id, self::EXPIRES_META, true );
		$valid = get_user_by( 'id', $user_id ) && '' !== $stored && $expires >= time() && hash_equals( $stored, hash( 'sha256', $token ) );
		if ( $valid ) {
			update_user_meta( $user_id, self::VERIFIED_META, '1' );
			delete_user_meta( $user_id, self::VERIFY_META );
			delete_user_meta( $user_id, self::EXPIRES_META );
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true, is_ssl() );
			wp_safe_redirect( home_url( '/my-leagues/#build-team' ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'verification', 'invalid', home_url( '/register/' ) ) );
		exit;
	}

	public static function block_unverified_login( $user, string $username, string $password ) {
		if ( $user instanceof WP_User && '0' === (string) get_user_meta( $user->ID, self::VERIFIED_META, true ) && '1' === (string) get_user_meta( $user->ID, self::CAMPAIGN_META, true ) ) {
			return new WP_Error( 'obitleague_email_unverified', 'Please verify your email using the link we sent before signing in.' );
		}
		return $user;
	}

	public static function must_be_verified(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'obitleague_auth_required', 'Please sign in to continue.', array( 'status' => 401 ) );
		}
		if ( '0' === (string) get_user_meta( get_current_user_id(), self::VERIFIED_META, true ) && '1' === (string) get_user_meta( get_current_user_id(), self::CAMPAIGN_META, true ) ) {
			return new WP_Error( 'obitleague_email_unverified', 'Verify your email before continuing.', array( 'status' => 403 ) );
		}
		return true;
	}
}

<?php
/** Dedicated public account registration, sign-in and email-verification flow. */
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
		add_shortcode( 'obitleague_login', array( self::class, 'login_shortcode' ) );
		add_filter( 'login_url', array( self::class, 'frontend_login_url' ), 10, 3 );
		add_filter( 'logout_url', array( self::class, 'frontend_logout_url' ), 10, 2 );
		add_filter( 'show_admin_bar', array( self::class, 'show_admin_bar' ) );
		add_action( 'admin_bar_menu', array( self::class, 'add_frontend_admin_bar_link' ), 90 );
		add_action( 'init', array( self::class, 'hide_wordpress_generator' ), 20 );
		add_filter( 'login_headerurl', array( self::class, 'wordpress_login_brand_url' ) );
		add_filter( 'login_headertext', static fn (): string => 'Obitleague' );
		add_filter( 'the_generator', '__return_empty_string' );
		add_action( 'login_init', array( self::class, 'redirect_native_login' ), 1 );
		add_action( 'login_form', array( self::class, 'admin_test_fields' ) );
		add_action( 'login_form', array( self::class, 'preserve_admin_test_redirect' ) );
		add_filter( 'authenticate', array( self::class, 'restrict_admin_test_login' ), 40, 3 );
		add_action( 'admin_init', array( self::class, 'restrict_admin_area' ), 1 );
		add_action( 'template_redirect', array( self::class, 'handle_auth_request' ), 1 );
		add_action( 'template_redirect', array( self::class, 'verify_request' ), 2 );
		add_filter( 'authenticate', array( self::class, 'block_unverified_login' ), 30, 3 );
	}

	/** Route player sign-in links through the Obitleague account page. */
	public static function frontend_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
		$url = home_url( '/login/' );
		if ( '' !== $redirect ) {
			$url = add_query_arg( 'redirect_to', $redirect, $url );
		}
		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}
		return $url;
	}

	/** Provide a branded, nonce-protected sign-out flow. */
	public static function frontend_logout_url( string $logout_url, string $redirect ): string {
		if ( current_user_can( 'manage_options' ) ) {
			return $logout_url;
		}
		$url = add_query_arg( '_ob_logout_nonce', wp_create_nonce( 'obitleague_logout' ), home_url( '/login/' ) );
		$url = add_query_arg( 'ob_logout', '1', $url );
		return '' !== $redirect ? add_query_arg( 'redirect_to', $redirect, $url ) : $url;
	}

	public static function wordpress_login_brand_url( string $url ): string {
		return home_url( '/' );
	}

	/** Remove public head metadata that advertises the implementation platform. */
	public static function hide_wordpress_generator(): void {
		remove_action( 'wp_head', 'wp_generator' );
	}

	/** Only administrators receive the WordPress toolbar. */
	public static function show_admin_bar( bool $show ): bool {
		return $show && current_user_can( 'manage_options' );
	}

	/** Let administrators switch from the WordPress testing view to the game. */
	public static function add_frontend_admin_bar_link( $admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) || ! is_object( $admin_bar ) || ! method_exists( $admin_bar, 'add_node' ) ) {
			return;
		}
		$admin_bar->add_node(
			array(
				'id'    => 'obitleague-frontend',
				'title' => 'Obitleague site',
				'href'  => home_url( '/' ),
			)
		);
	}

	/** Native WordPress login remains an administrator-only testing surface. */
	public static function redirect_native_login(): void {
		if ( current_user_can( 'manage_options' ) && false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/wp-admin/' ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		if ( 'logout' === $action ) {
			if ( current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( is_user_logged_in() && check_admin_referer( 'log-out', '_wpnonce', false ) ) {
				wp_logout();
			}
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		if ( current_user_can( 'manage_options' ) && false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 'ob_admin_token=' ) ) {
			return;
		}
		$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( (string) $_REQUEST['redirect_to'] ), home_url( '/my-leagues/' ) ) : home_url( '/my-leagues/' );
		wp_safe_redirect( add_query_arg( 'redirect_to', $redirect, home_url( '/login/' ) ) );
		exit;
	}

	/** Only administrators may authenticate through the signed test-login link. */
	public static function restrict_admin_test_login( $user ) {
		if ( ! is_wp_error( $user ) && isset( $_REQUEST['ob_admin_time'] ) && ! self::valid_admin_test_request() ) {
			return new WP_Error( 'obitleague_admin_test_expired', 'This administrator testing link expired. Return to Obitleague and open it again.' );
		}
		if ( ! self::valid_admin_test_request() || is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}
		return user_can( $user, 'manage_options' ) ? $user : new WP_Error( 'obitleague_admin_test_only', 'This sign-in option is only available to administrators. Use the Obitleague sign-in page.' );
	}

	/** Add the temporary administrator-only token to native login form submissions. */
	public static function admin_test_fields(): void {
		if ( ! self::valid_admin_test_request() ) {
			return;
		}
		$time  = self::admin_test_time();
		$token = self::admin_test_token( $time );
		echo '<input type="hidden" name="ob_admin_time" value="' . esc_attr( (string) $time ) . '" />';
		echo '<input type="hidden" name="ob_admin_token" value="' . esc_attr( $token ) . '" />';
	}

	public static function preserve_admin_test_redirect(): void {
		if ( ! self::valid_admin_test_request() ) {
			return;
		}
		$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( (string) $_REQUEST['redirect_to'] ), admin_url() ) : admin_url();
		echo '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '" />';
	}

	public static function admin_test_url(): string {
		$time = time();
		return add_query_arg(
			array( 'ob_admin_time' => $time, 'ob_admin_token' => self::admin_test_token( $time ) ),
			site_url( '/wp-login.php' )
		);
	}

	private static function admin_test_time(): int {
		return isset( $_REQUEST['ob_admin_time'] ) ? absint( $_REQUEST['ob_admin_time'] ) : 0;
	}

	private static function admin_test_token( int $time ): string {
		return hash_hmac( 'sha256', 'obitleague-admin-test:' . $time, wp_salt( 'auth' ) );
	}

	private static function valid_admin_test_request(): bool {
		$time  = self::admin_test_time();
		$token = isset( $_REQUEST['ob_admin_token'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['ob_admin_token'] ) ) : '';
		return $time > 0 && abs( time() - $time ) <= 600 && '' !== $token && hash_equals( self::admin_test_token( $time ), $token );
	}

	/** Registered players use the game, not the WordPress dashboard. */
	public static function restrict_admin_area(): void {
		global $pagenow;
		$allowed = array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' );
		if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) || in_array( $pagenow, $allowed, true ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/my-leagues/' ) );
		exit;
	}

	/** Handle sign-in, password recovery and sign-out before the theme emits output. */
	public static function handle_auth_request(): void {
		$path = self::request_path();
		if ( 'login' !== $path ) {
			return;
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$mode  = isset( $_POST['ob_auth_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['ob_auth_action'] ) ) : '';
		$nonce = isset( $_POST['ob_auth_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ob_auth_nonce'] ) ) : '';
		if ( ! in_array( $mode, array( 'login', 'request_reset', 'reset_password' ), true ) || ! wp_verify_nonce( $nonce, 'obitleague_' . $mode ) ) {
			wp_safe_redirect( add_query_arg( 'auth_error', 'expired', home_url( '/login/' ) ) );
			exit;
		}

		if ( 'login' === $mode ) {
			$identity = sanitize_text_field( wp_unslash( (string) ( $_POST['ob_email'] ?? '' ) ) );
			if ( is_email( $identity ) ) {
				$account = get_user_by( 'email', $identity );
				$identity = $account instanceof WP_User ? $account->user_login : $identity;
			}
			$user = wp_signon(
				array(
					'user_login'    => $identity,
					'user_password' => (string) wp_unslash( (string) ( $_POST['ob_password'] ?? '' ) ),
					'remember'      => ! empty( $_POST['ob_remember'] ),
				),
				is_ssl()
			);
			if ( is_wp_error( $user ) ) {
				$error = 'obitleague_email_unverified' === $user->get_error_code() ? 'unverified' : 'credentials';
					wp_safe_redirect( add_query_arg( 'auth_error', $error, home_url( '/login/' ) ) );
				exit;
			}
			$redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( (string) $_POST['redirect_to'] ) : home_url( '/my-leagues/' );
			wp_safe_redirect( wp_validate_redirect( $redirect, home_url( '/my-leagues/' ) ) );
			exit;
		}

		if ( 'request_reset' === $mode ) {
			$email = sanitize_email( wp_unslash( (string) ( $_POST['ob_email'] ?? '' ) ) );
			$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
			if ( $user instanceof WP_User ) {
				$key = get_password_reset_key( $user );
				if ( ! is_wp_error( $key ) ) {
					$url     = add_query_arg( array( 'action' => 'reset', 'key' => $key, 'login' => $user->user_login ), home_url( '/login/' ) );
					$subject = 'Reset your Obitleague password';
					$message = "A password reset was requested for your Obitleague account.\n\nChoose a new password using this secure link:\n{$url}\n\nIf you did not request this, you can ignore this email.";
					wp_mail( $user->user_email, $subject, $message );
				}
			}
			wp_safe_redirect( add_query_arg( 'auth_notice', 'reset-sent', home_url( '/login/' ) ) );
			exit;
		}

		$key      = isset( $_POST['ob_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ob_key'] ) ) : '';
		$login    = isset( $_POST['ob_login'] ) ? sanitize_user( wp_unslash( (string) $_POST['ob_login'] ) ) : '';
		$password = (string) wp_unslash( (string) ( $_POST['ob_password'] ?? '' ) );
		$user     = check_password_reset_key( $key, $login );
		if ( ! is_wp_error( $user ) && strlen( $password ) >= 12 && strlen( $password ) <= 4096 ) {
			reset_password( $user, $password );
			wp_safe_redirect( add_query_arg( 'auth_notice', 'password-reset', home_url( '/login/' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'auth_error', 'reset-invalid', home_url( '/login/' ) ) );
		exit;
	}

	public static function request_path(): string {
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		return $path;
	}

	/** Render the branded sign-in and password recovery screen. */
	public static function login_shortcode(): string {
		wp_enqueue_style( 'obitleague-campaign', OBITLEAGUE_DIR_URL . 'assets/campaign.css', array( 'ob-chrome' ), OBITLEAGUE_VERSION );
		if ( is_user_logged_in() ) {
			$out = Shortcodes::enqueue() . '<section class="ob-card ob-auth-card"><h1>You’re signed in.</h1><p>Your Obitleague account is ready.</p><a class="ob-btn" href="' . esc_url( home_url( '/my-leagues/' ) ) . '">Go to My leagues</a>';
				if ( current_user_can( 'manage_options' ) ) {
				$switch_url = wp_nonce_url( add_query_arg( 'ob_switch', 'wordpress', home_url( '/login/' ) ), 'obitleague_admin_switch' );
				$out .= ' <a class="ob-btn ob-btn--secondary" href="' . esc_url( $switch_url ) . '">Switch to WordPress admin</a>';
			}
			return $out . '</section>';
		}

		$out = Shortcodes::enqueue() . '<section class="ob-card ob-auth-card"><span class="ob-hero__kicker">Your game</span>';
		if ( 'reset' === sanitize_key( (string) ( $_GET['action'] ?? '' ) ) ) {
			$key   = sanitize_text_field( wp_unslash( (string) ( $_GET['key'] ?? '' ) ) );
			$login = sanitize_user( wp_unslash( (string) ( $_GET['login'] ?? '' ) ) );
			$user  = check_password_reset_key( $key, $login );
			$out  .= '<h1>Choose a new password</h1>';
			if ( is_wp_error( $user ) ) {
				return $out . '<p class="ob-auth-message ob-auth-message--error" role="alert">That password-reset link is invalid or expired. Request a new one below.</p>' . self::password_reset_request_form() . '</section>';
			}
			$out .= '<p>Choose a password with at least 12 characters.</p><form method="post" class="ob-auth-form">' . wp_nonce_field( 'obitleague_reset_password', 'ob_auth_nonce', true, false );
			$out .= '<input type="hidden" name="ob_auth_action" value="reset_password" /><input type="hidden" name="ob_key" value="' . esc_attr( $key ) . '" /><input type="hidden" name="ob_login" value="' . esc_attr( $login ) . '" />';
			$out .= '<label for="ob-password">New password</label><input id="ob-password" type="password" name="ob_password" autocomplete="new-password" required minlength="12" /><button class="ob-btn" type="submit">Save new password</button></form></section>';
			return $out;
		}

		$out .= '<h1>Sign in to Obitleague</h1>';
		$messages = array(
			'auth_error'  => array(
				'expired'      => array( 'error', 'That form expired. Please try again.' ),
				'credentials'   => array( 'error', 'Those sign-in details were not recognised. Check them and try again.' ),
				'unverified'    => array( 'error', 'Please verify your email using the link we sent before signing in.' ),
				'reset-invalid' => array( 'error', 'Choose a valid password of at least 12 characters using a current reset link.' ),
			),
			'auth_notice' => array(
				'reset-sent'    => array( 'success', 'If an account matches that email address, a password-reset link has been sent.' ),
				'password-reset' => array( 'success', 'Your password has been changed. You can now sign in.' ),
			),
		);
		foreach ( $messages as $query_key => $options ) {
			$value = sanitize_key( (string) ( $_GET[ $query_key ] ?? '' ) );
			if ( isset( $options[ $value ] ) ) {
				[ $type, $message ] = $options[ $value ];
				$class = 'error' === $type ? ' ob-auth-message--error' : '';
				$out .= '<p class="ob-auth-message' . $class . '" role="' . ( 'error' === $type ? 'alert' : 'status' ) . '">' . esc_html( $message ) . '</p>';
			}
		}

		$redirect = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( wp_unslash( (string) $_GET['redirect_to'] ), home_url( '/my-leagues/' ) ) : home_url( '/my-leagues/' );
		$out .= '<form method="post" class="ob-auth-form">' . wp_nonce_field( 'obitleague_login', 'ob_auth_nonce', true, false );
		$out .= '<input type="hidden" name="ob_auth_action" value="login" /><input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '" />';
		$out .= '<label for="ob-email">Email address or username</label><input id="ob-email" type="text" name="ob_email" autocomplete="username" required />';
		$out .= '<label for="ob-password">Password</label><input id="ob-password" type="password" name="ob_password" autocomplete="current-password" required />';
		$out .= '<label><input type="checkbox" name="ob_remember" value="1" /> Remember me</label><button class="ob-btn" type="submit">Sign in</button></form>';
		$out .= '<details class="ob-password-reset"><summary>Forgot your password?</summary>' . self::password_reset_request_form() . '</details>';
		$out .= '<p>New to the game? <a href="' . esc_url( home_url( '/register/' ) ) . '">Create an Obitleague account</a>.</p></section>';
		if ( current_user_can( 'manage_options' ) ) {
			$test_url = add_query_arg( 'redirect_to', admin_url(), self::admin_test_url() );
			$out .= '<p class="ob-admin-switch"><a href="' . esc_url( $test_url ) . '">Administrator testing: WordPress sign-in</a></p>';
		}
		return $out;
	}

	private static function password_reset_request_form(): string {
		return '<form method="post" class="ob-auth-form">' . wp_nonce_field( 'obitleague_request_reset', 'ob_auth_nonce', true, false ) . '<input type="hidden" name="ob_auth_action" value="request_reset" /><label for="ob-reset-email">Email address</label><input id="ob-reset-email" type="email" name="ob_email" autocomplete="email" required /><button class="ob-btn ob-btn--secondary" type="submit">Send reset link</button></form>';
	}

	public static function register_shortcode(): string {
		wp_enqueue_style( 'obitleague-campaign', OBITLEAGUE_DIR_URL . 'assets/campaign.css', array( 'ob-chrome' ), OBITLEAGUE_VERSION );
		if ( is_user_logged_in() ) {
			return Shortcodes::enqueue() . '<section class="ob-card ob-auth-card"><h2>You’re already in.</h2><p>Your ' . esc_html( (string) League_Service::current_season() ) . ' team is waiting.</p><a class="ob-btn" href="' . esc_url( home_url( '/my-leagues/#build-team' ) ) . '">Build your team</a></section>';
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
				$result  = self::register_account(
					sanitize_email( wp_unslash( (string) ( $_POST['ob_email'] ?? '' ) ) ),
					(string) wp_unslash( (string) ( $_POST['ob_password'] ?? '' ) ),
					(string) ( $_SERVER['REMOTE_ADDR'] ?? '' )
				);
				$success = true === $result;
				$message = is_string( $result ) ? $result : '';
			}
		}
		$out = Shortcodes::enqueue() . '<section class="ob-card ob-auth-card"><span class="ob-hero__kicker">' . esc_html( (string) League_Service::current_season() ) . ' season · join the game</span><h1>Create your Obitleague account</h1><p>We’ll email you a verification link. Verify your address to open your team builder.</p>';
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
			$out .= '<p>Already registered? <a href="' . esc_url( home_url( '/login/?redirect_to=' . rawurlencode( home_url( '/my-leagues/' ) ) ) ) . '">Sign in</a>.</p>';
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
			return true;
		}
		if ( ! $user ) {
			$local = sanitize_user( (string) strtok( $email, '@' ), true );
			$login = substr( '' !== $local ? $local : 'player', 0, 40 ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
			$user_id = wp_insert_user( array(
				'user_login'   => $login,
				'user_pass'    => $password,
				'user_email'   => $email,
				'display_name' => $login,
				'role'         => 'subscriber',
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
		$url     = add_query_arg( array( 'uid' => $user->ID, 'token' => $token ), home_url( '/verify-email/' ) );
		$subject = 'Verify your Obitleague email';
		$body    = "Welcome to Obitleague’s " . League_Service::current_season() . " season.\n\nVerify your email and open your team builder:\n{$url}\n\nThis link expires in 24 hours. If you did not request an account, ignore this email.";
		return (bool) wp_mail( $user->user_email, $subject, $body );
	}

	private static function allow_attempt( string $key, int $limit, int $window ): bool {
		$name  = 'ob_signup_' . md5( $key );
		$count = (int) get_transient( $name );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $name, $count + 1, $window );
		return true;
	}

	public static function verify_request(): void {
		if ( 'verify-email' !== self::request_path() || empty( $_GET['uid'] ) || empty( $_GET['token'] ) ) {
			return;
		}
		$user_id = absint( $_GET['uid'] );
		$token   = sanitize_text_field( wp_unslash( (string) $_GET['token'] ) );
		$stored  = (string) get_user_meta( $user_id, self::VERIFY_META, true );
		$expires = (int) get_user_meta( $user_id, self::EXPIRES_META, true );
		$valid   = get_user_by( 'id', $user_id ) && '' !== $stored && $expires >= time() && hash_equals( $stored, hash( 'sha256', $token ) );
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

<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

/** Owns accounts, credential rules, sessions, and the session cookie. */
final class Auth {
	public function __construct( private Database $db, private Csrf $csrf, private Clock $clock ) {
	}

	/** The registration input problem, or null when the details are acceptable. */
	public function registration_error( string $username, string $display_name, string $password ): ?string {
		if ( ! preg_match( '/^[a-z0-9_.-]{3,40}$/', $username )
			|| '' === $display_name || strlen( $display_name ) > 80
			|| strlen( $password ) < 12 || strlen( $password ) > 72 ) {
			return 'Choose a valid username, a display name up to 80 characters, and a password of 12–72 characters.';
		}
		return null;
	}

	/** Insert an account and return its id. */
	public function create_account( string $username, string $display_name, string $password ): int {
		$this->db->pdo()->prepare( 'INSERT INTO accounts (username, display_name, password_hash, created_at) VALUES (?, ?, ?, ?)' )
			->execute( array( $username, $display_name, password_hash( $password, PASSWORD_DEFAULT ), $this->clock->now() ) );
		return (int) $this->db->pdo()->lastInsertId();
	}

	/** Return the account row when the password matches, otherwise null. */
	public function verify_credentials( string $username, string $password ): ?array {
		$stmt = $this->db->pdo()->prepare( 'SELECT * FROM accounts WHERE username = ? COLLATE NOCASE' );
		$stmt->execute( array( trim( $username ) ) );
		$account = $stmt->fetch();
		if ( ! $account || ! password_verify( $password, (string) $account['password_hash'] ) ) {
			return null;
		}
		return $account;
	}

	/**
	 * Verify credentials and open a session.
	 *
	 * @return array{csrf:string, cookie:string}|null Null when the password is wrong.
	 */
	public function login( string $username, string $password ): ?array {
		$account = $this->verify_credentials( $username, $password );
		if ( ! $account ) {
			return null;
		}
		$session = $this->start_session( (int) $account['id'] );
		return array(
			'csrf'   => $session['csrf'],
			'cookie' => $this->session_cookie( $session['token'] ),
		);
	}

	/** Create a session and return the bearer token plus its CSRF token. */
	private function start_session( int $account_id ): array {
		$token = bin2hex( random_bytes( 32 ) );
		$csrf = bin2hex( random_bytes( 24 ) );
		$expires_at = $this->clock->instant()->add( new \DateInterval( 'P30D' ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$this->db->pdo()->prepare( 'INSERT INTO web_sessions (token_hash, account_id, csrf_token, expires_at) VALUES (?, ?, ?, ?)' )
			->execute( array( hash( 'sha256', $token ), $account_id, $csrf, $expires_at ) );
		return array( 'token' => $token, 'csrf' => $csrf );
	}

	/**
	 * Resolve the signing-in account and require it to be an operator.
	 *
	 * Operator identity is configured, not self-served: the comma-separated
	 * usernames in OBITLEAGUE_OPERATORS are the only accounts allowed to review
	 * deaths. An unset list means no operators, so the route is closed by default.
	 */
	public function require_operator( array $headers, bool $check_csrf = false ): array {
		$account = $this->authenticate( $headers, $check_csrf );
		if ( ! $this->is_operator( $account ) ) {
			throw new Http_Error( 403, 'Only league operators may review deaths.' );
		}
		return $account;
	}

	/** Whether an account may review deaths; the single owner of the operator rule. */
	public function is_operator( array $account ): bool {
		$operators = array_filter( array_map( 'trim', explode( ',', (string) getenv( 'OBITLEAGUE_OPERATORS' ) ) ), static fn ( string $name ): bool => '' !== $name );
		return in_array( (string) ( $account['username'] ?? '' ), $operators, true );
	}

	/** Resolve the signing-in account, optionally enforcing the CSRF token. */
	public function authenticate( array $headers, bool $check_csrf = false ): array {
		$token = $this->session_token( $headers );
		if ( '' === $token ) {
			throw new Http_Error( 401, 'Sign in to continue.' );
		}
		$stmt = $this->db->pdo()->prepare( 'SELECT a.id, a.username, a.display_name, s.csrf_token FROM web_sessions s JOIN accounts a ON a.id = s.account_id WHERE s.token_hash = ? AND s.expires_at > ?' );
		$stmt->execute( array( hash( 'sha256', $token ), $this->clock->now() ) );
		$account = $stmt->fetch();
		if ( ! $account ) {
			throw new Http_Error( 401, 'Your sign-in has expired.' );
		}
		if ( $check_csrf ) {
			$this->csrf->verify( $account, $headers );
		}
		return $account;
	}

	/** Delete the session carried by the request. */
	public function destroy_session( array $headers ): void {
		$this->db->pdo()->prepare( 'DELETE FROM web_sessions WHERE token_hash = ?' )
			->execute( array( hash( 'sha256', $this->session_token( $headers ) ) ) );
	}

	/** The Set-Cookie value for a session, marked Secure on HTTPS requests. */
	private function session_cookie( string $token ): string {
		$secure = ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ? '; Secure' : '';
		return 'obl_session=' . $token . '; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax' . $secure;
	}

	private function session_token( array $headers ): string {
		if ( isset( $headers['Cookie'] ) && preg_match( '/(?:^|;\s*)obl_session=([a-f0-9]{64})(?:;|$)/', $headers['Cookie'], $match ) ) {
			return $match[1];
		}
		return '';
	}
}

<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

/** Verifies the per-session request token on mutating requests. */
final class Csrf {
	public function verify( array $account, array $headers ): void {
		if ( ! hash_equals( (string) $account['csrf_token'], (string) ( $headers['X-CSRF-Token'] ?? '' ) ) ) {
			throw new Http_Error( 403, 'The request token is invalid.' );
		}
	}
}

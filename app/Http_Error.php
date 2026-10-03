<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use RuntimeException;

/** A request failure carrying the status code the interface should render. */
final class Http_Error extends RuntimeException {
	public function __construct( public readonly int $status, string $message ) {
		parent::__construct( $message );
	}
}

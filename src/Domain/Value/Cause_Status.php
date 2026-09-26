<?php
/**
 * Cause status.
 *
 * Date and cause are separate editorial decisions. A death can score with an
 * undisclosed cause; a published cause can be corrected later without
 * affecting points.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Cause_Status {

	public const NOT_DISCLOSED = 'not_disclosed';
	public const PENDING       = 'pending_official';
	public const CONFIRMED     = 'confirmed';
	public const CONTESTED     = 'contested';

	private function __construct() {}

	/** Public label for a cause status. */
	public static function label( string $status ): string {
		return match ( $status ) {
			self::NOT_DISCLOSED => 'Cause of death has not been publicly disclosed.',
			self::PENDING       => 'Cause of death is awaiting official confirmation.',
			self::CONFIRMED     => 'Confirmed cause of death.',
			self::CONTESTED     => 'Cause of death is under review.',
			default             => throw new \InvalidArgumentException( 'Unknown cause status: ' . $status ),
		};
	}

	/** Statuses that may appear on an approved event. */
	public static function publishable(): array {
		return array( self::CONFIRMED, self::NOT_DISCLOSED );
	}
}

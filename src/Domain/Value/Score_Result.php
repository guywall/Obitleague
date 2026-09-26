<?php
/**
 * Scoring result: either an award or an explicit hold.
 *
 * A confirmed death with an unclear date is published with an honest label
 * and held from scoring until an exact date is established. The hold is a
 * first-class result, never silence and never a guess.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Score_Result {

	public const SCORED = 'scored';
	public const HELD   = 'held';

	private function __construct(
		public readonly string $status,
		public readonly ?Award $award,
		public readonly ?string $reason
	) {}

	public static function scored( Award $award ): self {
		return new self( self::SCORED, $award, null );
	}

	public static function held( string $reason ): self {
		return new self( self::HELD, null, $reason );
	}

	public function is_scored(): bool {
		return self::SCORED === $this->status;
	}
}

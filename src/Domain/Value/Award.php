<?php
/**
 * One awarded score line for a pick.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

final class Award {

	/**
	 * @param string $event_id      Approved event the award derives from.
	 * @param int    $completed_age Completed years used in the formula.
	 * @param int    $points        max(1, 100 − age).
	 */
	public function __construct(
		public readonly string $event_id,
		public readonly int $completed_age,
		public readonly int $points
	) {}
}

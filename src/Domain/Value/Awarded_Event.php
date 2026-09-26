<?php
/**
 * Awarded event.
 *
 * An editor-approved death. This is the ONLY input the scoring engine
 * accepts: pipeline candidates and unreviewed reports never reach this type,
 * so the engine cannot score what has not been approved.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain\Value;

use Obitleague\Domain\Value\Cause_Status;

final class Awarded_Event {

	/**
	 * @param string $event_id     Stable event identifier.
	 * @param string $person_id    Internal person UUID.
	 * @param string $display_name Person name, for reasons and notifications.
	 * @param Partial_Date $death  Verified death date (may carry reduced precision).
	 * @param string $cause_status One of Cause_Status::CONFIRMED or Cause_Status::NOT_DISCLOSED.
	 * @param string|null $cause   Approved cause wording when disclosed.
	 * @param \DateTimeImmutable $approved_at When the editor approved the event.
	 */
	public function __construct(
		public readonly string $event_id,
		public readonly string $person_id,
		public readonly string $display_name,
		public readonly Partial_Date $death,
		public readonly string $cause_status,
		public readonly ?string $cause,
		public readonly \DateTimeImmutable $approved_at
	) {
		if ( ! in_array( $this->cause_status, array( Cause_Status::CONFIRMED, Cause_Status::NOT_DISCLOSED ), true ) ) {
			throw new \InvalidArgumentException( 'An awarded event requires a published cause status.' );
		}
		if ( Cause_Status::CONFIRMED === $this->cause_status && ( null === $this->cause || '' === trim( $this->cause ) ) ) {
			throw new \InvalidArgumentException( 'A confirmed cause requires approved wording.' );
		}
	}
}

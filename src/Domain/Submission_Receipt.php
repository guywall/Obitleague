<?php
/**
 * Submission receipt.
 *
 * Immutable proof of what a player's entry committed to: which revision,
 * which picks, under which ruleset, at which instant. Shown to the player
 * after submission and sufficient to reconstruct the competing entry later.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Ruleset;

final class Submission_Receipt {

	/**
	 * @param string $receipt_id      Unique receipt identifier.
	 * @param int    $entry_id        Entry the submission belongs to.
	 * @param int    $revision_id     The revision that now competes.
	 * @param int    $league_id       League of the entry.
	 * @param int    $season          Season of the entry.
	 * @param string[] $picks         The ten distinct person IDs submitted.
	 * @param string $ruleset_version Ruleset the submission was validated under.
	 * @param \DateTimeImmutable $committed_at Server instant of the commit.
	 * @param \DateTimeImmutable $deadline     Entry deadline that was applied.
	 */
	public function __construct(
		public readonly string $receipt_id,
		public readonly int $entry_id,
		public readonly int $revision_id,
		public readonly int $league_id,
		public readonly int $season,
		public readonly array $picks,
		public readonly string $ruleset_version,
		public readonly \DateTimeImmutable $committed_at,
		public readonly \DateTimeImmutable $deadline
	) {
		if ( '' === $this->receipt_id ) {
			throw new \InvalidArgumentException( 'A receipt requires an identifier.' );
		}
		if ( count( $this->picks ) !== Ruleset::TEAM_SIZE ) {
			throw new \InvalidArgumentException( 'A receipt requires exactly ' . Ruleset::TEAM_SIZE . ' picks.' );
		}
		if ( Ruleset::VERSION !== $this->ruleset_version ) {
			throw new \InvalidArgumentException( 'Receipt ruleset must match the current version.' );
		}
	}

	/** True when this receipt's commit beat the deadline it names. */
	public function is_competing(): bool {
		return $this->committed_at < $this->deadline;
	}

	/** Human-readable summary shown to the player. */
	public function summary(): string {
		return sprintf(
			'Submission %s received %s — %d picks, ruleset v%s, deadline %s. This revision competes.',
			$this->receipt_id,
			$this->committed_at->setTimezone( new \DateTimeZone( 'Europe/London' ) )->format( 'j F Y H:i:s T' ),
			count( $this->picks ),
			$this->ruleset_version,
			$this->deadline->format( 'j F Y H:i T' )
		);
	}
}

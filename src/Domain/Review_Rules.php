<?php
/**
 * Review rules.
 *
 * Pure state machine for editorial review cases. An RSS headline opens a
 * candidate; only an editor's field-by-field decision produces a published
 * death event; retractions are reversible and auditable. No pipeline stage
 * may publish a death — the state machine makes that structurally true.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Review_Rules {

	/** Case states. */
	public const PENDING   = 'pending';
	public const APPROVED  = 'approved';
	public const REJECTED  = 'rejected';
	public const RETRACTED = 'retracted';

	/** Field decisions required on every approval. */
	public const DECISION_FIELDS = array( 'identity', 'death', 'date', 'cause' );

	private function __construct() {}

	/** Legal transitions. */
	public static function can_transition( string $from, string $to ): bool {
		return in_array(
			"{$from}>{$to}",
			array(
				self::PENDING . '>' . self::APPROVED,
				self::PENDING . '>' . self::REJECTED,
				self::APPROVED . '>' . self::RETRACTED,
				self::RETRACTED . '>' . self::APPROVED, // Re-approval after a retraction is possible.
				self::REJECTED . '>' . self::PENDING,  // New evidence reopens a rejected case.
				self::RETRACTED . '>' . self::REJECTED,
			),
			true
		);
	}

	/**
	 * Validate an approval. Two editorially independent origin groups (or one
	 * authentic official statement) are required; date needs day precision;
	 * a claimed cause needs approved wording. Identity is implied by a
	 * resolved person UUID.
	 *
	 * @param array{origin_groups:string[], death_date?:array{y?:int,m?:int,d?:int}, cause_text?:string, cause_disclosed?:bool} $decision
	 * @return string[] Problems found; empty when valid.
	 */
	public static function approval_problems( array $decision ): array {
		$problems = array();

		$groups = array_values( array_unique( array_filter( $decision['origin_groups'] ?? array() ) ) );
		if ( count( $groups ) < 2 ) {
			// One origin group is acceptable only for an official statement.
			if ( empty( $decision['official_statement'] ) ) {
				$problems[] = 'Two independent origin groups are required, or one authentic official statement.';
			}
		}

		$d = $decision['death_date'] ?? array();
		if ( empty( $d['y'] ) || empty( $d['m'] ) || empty( $d['d'] ) ) {
			$problems[] = 'An exact death date is required before points can be awarded (month or year precision stays unapproved for scoring).';
		}

		if ( ! empty( $decision['cause_disclosed'] ) && '' === trim( (string) ( $decision['cause_text'] ?? '' ) ) ) {
			$problems[] = 'A disclosed cause requires approved cause wording from the source.';
		}

		return $problems;
	}

	/** True when the editor-supplied expected revision still governs. */
	public static function revision_current( int $expected, int $current ): bool {
		return $expected === $current;
	}

	/**
	 * Effect of a decision on the person's selection status.
	 *
	 * @return string 'block_selection' | 'keep_blocked' | 'none'
	 */
	public static function selection_effect( string $state ): string {
		return match ( $state ) {
			self::PENDING  => 'block_selection',  // Unresolved report blocks new selection.
			self::APPROVED => 'keep_blocked',     // Dead people are not selectable.
			default        => 'none',
		};
	}
}

<?php
/**
 * Entry rules.
 *
 * Pure rules for pick lists and entry revisions. A submitted revision is
 * immutable; saves before lock create draft revisions; the entry competes
 * with its latest submitted revision only.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Ruleset;

final class Entry_Rules {

	/** Entry states. */
	public const DRAFT     = 'draft';
	public const SUBMITTED = 'submitted';

	/** Revision kinds. */
	public const KIND_DRAFT     = 'draft';
	public const KIND_SUBMITTED = 'submitted';

	private function __construct() {}

	/**
	 * Validate a pick list and return the normalised list of ten distinct
	 * person IDs. Throws Invalid_Team_Exception with every problem found.
	 *
	 * @param string[] $person_ids
	 * @return string[] Exactly ten distinct non-empty IDs, in given order.
	 */
	public static function validate_picks( array $person_ids ): array {
		$problems = array();

		foreach ( $person_ids as $id ) {
			if ( ! is_string( $id ) || '' === trim( $id ) ) {
				$problems[] = 'Every pick must be a non-empty person identifier.';
				break;
			}
		}

		$unique = array_values( array_unique( $person_ids ) );
		if ( count( $unique ) !== count( $person_ids ) ) {
			$problems[] = 'Picks must be distinct people — remove the duplicates.';
		}

		if ( count( $person_ids ) !== Ruleset::TEAM_SIZE ) {
			$problems[] = sprintf( 'A team is exactly %d picks; %d given.', Ruleset::TEAM_SIZE, count( $person_ids ) );
		}

		if ( $problems ) {
			throw new Invalid_Team_Exception( $problems );
		}

		return $unique;
	}

	/** True when either a draft or competing team can be changed before lock. */
	public static function can_edit( string $state, bool $deadline_open ): bool {
		return $deadline_open && in_array( $state, array( self::DRAFT, self::SUBMITTED ), true );
	}

	/** True when the entry can be submitted: draft state, open, valid picks. */
	public static function can_submit( string $state, bool $deadline_open ): bool {
		return $deadline_open && self::DRAFT === $state;
	}

	/**
	 * Effect of a save attempt. A submitted team can be amended until lock;
	 * each amendment creates a new immutable competing revision.
	 *
	 * @return string 'create_draft' | 'create_amendment' | 'refuse'
	 */
	public static function save_effect( string $state, bool $deadline_open ): string {
		if ( ! self::can_edit( $state, $deadline_open ) ) {
			return 'refuse';
		}
		return self::SUBMITTED === $state ? 'create_amendment' : 'create_draft';
	}

	/**
	 * Effect of a submit attempt on a draft: becomes the competing revision.
	 *
	 * @return string 'submit_draft' | 'refuse'
	 */
	public static function submit_effect( string $state, bool $deadline_open ): string {
		return self::can_submit( $state, $deadline_open ) ? 'submit_draft' : 'refuse';
	}

	/**
	 * Effect of discovering a submitted pick was already dead before the
	 * season began. Before lock the player may replace it; after lock the
	 * pick stays, scores zero and is explained — no retrospective changes.
	 *
	 * @return string 'allow_replacement' | 'keep_zero_explained'
	 */
	public static function already_dead_effect( bool $deadline_open ): string {
		return $deadline_open ? 'allow_replacement' : 'keep_zero_explained';
	}

	/**
	 * Score for one pick given submitted picks and awards.
	 *
	 * @param string[]        $submitted Normalised submitted pick IDs.
	 * @param array<string,int> $awards  Map of person ID to points awarded.
	 * @return array{points:int, scoring_picks:int}
	 */
	public static function score_submission( array $submitted, array $awards ): array {
		$points  = 0;
		$scoring = 0;
		foreach ( $submitted as $pick ) {
			if ( isset( $awards[ $pick ] ) ) {
				$points += $awards[ $pick ];
				++$scoring;
			}
		}
		return array(
			'points'        => $points,
			'scoring_picks' => $scoring,
		);
	}
}

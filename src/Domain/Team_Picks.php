<?php
/**
 * Team validation.
 *
 * A submitted team is exactly ten distinct people. Validation happens on the
 * server at submission; the client cannot widen or relax it.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

use Obitleague\Domain\Value\Ruleset;

final class Team_Picks {

	private function __construct() {}

	/**
	 * Validate a pick list. Returns the normalised list of ten unique
	 * person IDs; throws Invalid_Team_Exception otherwise.
	 *
	 * @param string[] $person_ids
	 * @return string[]
	 */
	public static function validate( array $person_ids ): array {
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
			$problems[] = sprintf(
				'A team is exactly %d picks; %d given.',
				Ruleset::TEAM_SIZE,
				count( $person_ids )
			);
		}

		if ( $problems ) {
			throw new Invalid_Team_Exception( $problems );
		}

		return $unique;
	}
}

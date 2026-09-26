<?php
/**
 * Team validation exception carrying every problem found.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Invalid_Team_Exception extends \InvalidArgumentException {

	/** @param string[] $problems Human-readable validation problems. */
	public function __construct( public readonly array $problems ) {
		parent::__construct( 'Team is invalid: ' . implode( '; ', $problems ) );
	}
}

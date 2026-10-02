<?php
/**
 * The wire's obituary likelihood on one honest 0–95 scale.
 *
 * The weighted classifier already scores on that scale. Rows written by the
 * first-generation cue counter hold small positive integers, which are mapped
 * ×10 so the discard threshold means the same thing across both eras. Pure
 * arithmetic — no WordPress.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Score {

	private function __construct() {}

	/** Classifier score as an honest 0–95 obituary likelihood. */
	public static function likelihood_pct( int $score ): int {
		if ( $score <= 0 ) {
			return 0;
		}
		if ( $score >= Feed_Classifier::THRESHOLD_REVIEW ) {
			return (int) min( 95, $score );
		}
		return (int) min( 95, $score * 10 );
	}
}

<?php
/**
 * Managed death-wire phrase scenarios.
 *
 * Wire_Phrases itself is a DB-backed class; the suite runs without
 * WordPress, so these tests cover the pure contract: the override plumbing
 * in Feed_Classifier (which the merged tables feed) and the built-in
 * exposure accessors Wire_Phrases reads.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Feed_Classifier;

final class Scenario_Wire_Phrases {

	public function test_builtin_tables_are_exposed_for_the_manager( Runner $t ): void {
		$strong = Feed_Classifier::builtin_strong_phrases();
		$t->check( isset( $strong['dies aged'] ), __METHOD__, 'strong table exposes dies aged with weights' );
		$t->check( is_array( $strong['dies aged'] ) && 2 === count( $strong['dies aged'] ), __METHOD__, 'strong phrases carry title/body weights' );

		$soft = Feed_Classifier::builtin_soft_negatives();
		$t->check( isset( $soft['anniversary'] ), __METHOD__, 'soft table exposes anniversary' );

		$exclude = Feed_Classifier::builtin_hard_exclusions();
		$t->check( in_array( 'death hoax', $exclude, true ), __METHOD__, 'exclusion table exposes death hoax' );

		$confirm = Feed_Classifier::builtin_confirmation_phrases();
		$t->check( in_array( 'family confirmed', $confirm, true ) || in_array( 'family confirms', $confirm, true ), __METHOD__, 'confirmation table exposes a family confirmation cue' );
	}

	public function test_null_tables_preserve_builtin_behaviour( Runner $t ): void {
		$default = Feed_Classifier::classify( 'Actor dies aged 80' );
		$explicit = Feed_Classifier::classify(
			'Actor dies aged 80',
			'',
			array(),
			array(
				'strong'  => Feed_Classifier::builtin_strong_phrases(),
				'soft'    => Feed_Classifier::builtin_soft_negatives(),
				'exclude' => Feed_Classifier::builtin_hard_exclusions(),
				'confirm' => Feed_Classifier::builtin_confirmation_phrases(),
			)
		);
		$t->check( $default['classification'] === $explicit['classification'], __METHOD__, 'identical tables give the identical verdict' );
		$t->check( $default['score'] === $explicit['score'], __METHOD__, 'identical tables give the identical score' );
	}

	public function test_added_strong_phrase_lifts_a_story_over_the_line( Runner $t ): void {
		$tables = array(
			'strong'  => Feed_Classifier::builtin_strong_phrases() + array( 'slipped away peacefully' => array( 65, 40 ) ),
			'soft'    => Feed_Classifier::builtin_soft_negatives(),
			'exclude' => Feed_Classifier::builtin_hard_exclusions(),
			'confirm' => Feed_Classifier::builtin_confirmation_phrases(),
		);
		$before = Feed_Classifier::classify( 'Broadcaster slipped away peacefully on Sunday' );
		$after  = Feed_Classifier::classify( 'Broadcaster slipped away peacefully on Sunday', '', array(), $tables );
		$t->check( $after['score'] > $before['score'], __METHOD__, 'managed strong phrase adds score' );
		$t->check( in_array( 'slipped away peacefully', $after['matched'], true ), __METHOD__, 'managed phrase appears in matched cues' );
		$t->check( Feed_Classifier::is_death_signal( $after['classification'] ), __METHOD__, 'managed phrase lifts the story to a death signal' );
	}

	public function test_excluded_phrase_forces_not_death( Runner $t ): void {
		// Remove 'dies aged' from the strong table and add it as a hard
		// exclusion — a story that only leans on it must fall out entirely.
		$strong = Feed_Classifier::builtin_strong_phrases();
		unset( $strong['dies aged'] );
		$tables = array(
			'strong'  => $strong,
			'soft'    => Feed_Classifier::builtin_soft_negatives(),
			'exclude' => array_merge( Feed_Classifier::builtin_hard_exclusions(), array( 'dies aged' ) ),
			'confirm' => Feed_Classifier::builtin_confirmation_phrases(),
		);
		$result = Feed_Classifier::classify( 'Veteran actor dies aged 84', '', array(), $tables );
		$t->check( Feed_Classifier::NOT_DEATH === $result['classification'], __METHOD__, 'excluded phrase forces not_death' );
		$t->check( in_array( 'dies aged', $result['excluded'], true ), __METHOD__, 'the excluded phrase is recorded' );
	}

	public function test_disabled_dampener_stops_dampening( Runner $t ): void {
		$soft = Feed_Classifier::builtin_soft_negatives();
		unset( $soft['anniversary'] );
		$tables = array(
			'strong'  => Feed_Classifier::builtin_strong_phrases(),
			'soft'    => $soft,
			'exclude' => Feed_Classifier::builtin_hard_exclusions(),
			'confirm' => Feed_Classifier::builtin_confirmation_phrases(),
		);
		$title  = 'Remembering the anniversary of his death: a look back';
		$with   = Feed_Classifier::classify( $title );
		$without = Feed_Classifier::classify( $title, '', array(), $tables );
		$t->check( $without['score'] >= $with['score'], __METHOD__, 'disabling a dampener never lowers the score' );
		$t->check( ! in_array( 'anniversary', $without['negative'], true ), __METHOD__, 'disabled dampener leaves the negative cues' );
	}
}

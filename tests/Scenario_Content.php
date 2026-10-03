<?php
/**
 * Person body inclusion scenarios.
 *
 * The person page body is a formatter over approved fields. Which facts it
 * lists — and from which storage form — must not depend on WordPress, because
 * the same record can carry its occupations as a stored list or as taxonomy
 * terms depending on when it was enriched. These cases exercise the pure
 * inclusion rule behind the occupations paragraph.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Modules\Person_Content;

require_once __DIR__ . '/../src/Domain/Value/Role_Label.php';
require_once __DIR__ . '/../src/Modules/Person_Content.php';

final class Scenario_Content {

	public function test_stored_occupations_win_over_taxonomy_terms( Runner $t ): void {
		// The stored list is authoritative; the terms are only its mirror.
		$t->check(
			array( 'actor', 'singer' ) === Person_Content::bio_occupation_labels( array( 'actor', 'singer' ), array( 'actor', 'singer', 'politician' ) ),
			__METHOD__,
			'the recorded list is used when both sources exist'
		);
	}

	public function test_terms_are_used_only_when_no_stored_list_exists( Runner $t ): void {
		$t->check(
			array( 'actor', 'singer' ) === Person_Content::bio_occupation_labels( array(), array( 'actor', 'singer' ) ),
			__METHOD__,
			'a record filed only through the term backfill still lists its occupations'
		);
	}

	public function test_labels_are_cleaned_and_de_duplicated( Runner $t ): void {
		$t->check(
			array( 'actor' ) === Person_Content::bio_occupation_labels( array( 'actor', 'actor', '  ' ), array() ),
			__METHOD__,
			'blank and repeated labels collapse to one'
		);
		$t->check(
			array( 'South Korean actor' ) === Person_Content::bio_occupation_labels( array( 'South Korean actor , blood cancer' ), array() ),
			__METHOD__,
			'a leaked cause-of-death clause is cleaned out of a label'
		);
	}

	public function test_an_unusable_stored_list_falls_back_to_terms( Runner $t ): void {
		// A stored value that cleans away to nothing must not silence the
		// paragraph when the taxonomy still holds a real occupation.
		$t->check(
			array( 'actor' ) === Person_Content::bio_occupation_labels( array( 'cancer' ), array( 'actor' ) ),
			__METHOD__,
			'a cause-only stored value falls back to the term'
		);
	}

	public function test_no_occupations_yields_an_empty_list( Runner $t ): void {
		$t->check(
			array() === Person_Content::bio_occupation_labels( array(), array() ),
			__METHOD__,
			'a record with no occupations lists none'
		);
	}
}

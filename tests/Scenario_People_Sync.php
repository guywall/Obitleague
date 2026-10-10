<?php
/**
 * Wikidata claim-reading scenarios for People_Sync.
 *
 * The cause of death is published on the person page as a stated fact, so the
 * reader that chooses it has to be strict: only a live, item-valued P509 claim
 * counts, a preferred rank wins, and anything unusable is refused rather than
 * guessed at. Running without WordPress keeps that rule verifiable anywhere.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Modules\People_Sync;

final class Scenario_People_Sync {

	/** Build a minimal wbgetentities claim carrying an item value. */
	private static function item_claim( string $qid, string $rank = 'normal' ): array {
		return array(
			'rank'     => $rank,
			'mainsnak' => array( 'datavalue' => array( 'value' => array( 'id' => $qid ) ) ),
		);
	}

	public function test_cause_qid_refuses_anything_unusable( Runner $t ): void {
		$t->check( '' === People_Sync::cause_qid( null ), __METHOD__, 'no entity yields no cause' );
		$t->check( '' === People_Sync::cause_qid( array() ), __METHOD__, 'an empty entity yields no cause' );
		$t->check( '' === People_Sync::cause_qid( array( 'claims' => array() ) ), __METHOD__, 'an entity without P509 yields no cause' );
		$t->check( '' === People_Sync::cause_qid( array( 'claims' => array( 'P106' => array( self::item_claim( 'Q1' ) ) ) ) ), __METHOD__, 'an unrelated property yields no cause' );
		$t->check(
			'' === People_Sync::cause_qid( array( 'claims' => array( 'P509' => array( 'not-a-claim' ) ) ) ),
			__METHOD__,
			'a malformed claim is skipped'
		);
		$t->check(
			'' === People_Sync::cause_qid( array( 'claims' => array( 'P509' => array( array( 'mainsnak' => array( 'datavalue' => array( 'value' => 'lung cancer' ) ) ) ) ) ) ),
			__METHOD__,
			'a non-item value yields no cause'
		);
	}

	public function test_cause_qid_ignores_deprecated_claims( Runner $t ): void {
		$entity = array( 'claims' => array( 'P509' => array( self::item_claim( 'Q2920409', 'deprecated' ) ) ) );
		$t->check( '' === People_Sync::cause_qid( $entity ), __METHOD__, 'a deprecated claim is refused' );

		$entity = array(
			'claims' => array(
				'P509' => array(
					self::item_claim( 'Q2920409', 'deprecated' ),
					self::item_claim( 'Q1654' ),
				),
			),
		);
		$t->check( 'Q1654' === People_Sync::cause_qid( $entity ), __METHOD__, 'a live claim wins over a deprecated one' );
	}

	public function test_cause_qid_prefers_the_preferred_rank( Runner $t ): void {
		$entity = array(
			'claims' => array(
				'P509' => array(
					self::item_claim( 'Q1654' ),
					self::item_claim( 'Q2920409', 'preferred' ),
				),
			),
		);
		$t->check( 'Q2920409' === People_Sync::cause_qid( $entity ), __METHOD__, 'the preferred rank is chosen' );

		$entity = array( 'claims' => array( 'P509' => array( self::item_claim( 'Q1654' ), self::item_claim( 'Q178561' ) ) ) );
		$t->check( 'Q1654' === People_Sync::cause_qid( $entity ), __METHOD__, 'with no preferred rank the first claim wins' );
	}

	public function test_missing_field_labels_names_every_gap( Runner $t ): void {
		$t->check(
			array() === People_Sync::missing_field_labels( array( 'image' => true, 'occupations' => true, 'role' => true, 'cause' => true ) ),
			__METHOD__,
			'a complete record has no gaps'
		);
		$t->check(
			array( 'portrait' ) === People_Sync::missing_field_labels( array( 'image' => false, 'occupations' => true, 'role' => true, 'cause' => true ) ),
			__METHOD__,
			'a missing portrait is named'
		);
		$t->check(
			array( 'portrait', 'occupations', 'role', 'cause of death' ) === People_Sync::missing_field_labels( array() ),
			__METHOD__,
			'every absent field is named in a stable order'
		);
		$t->check(
			array( 'occupations', 'cause of death' ) === People_Sync::missing_field_labels( array( 'image' => true, 'role' => true ) ),
			__METHOD__,
			'absent keys count as missing'
		);
		$t->check(
			array( 'portrait' ) === People_Sync::missing_field_labels( array( 'image' => '', 'occupations' => '1', 'role' => '1', 'cause' => '1' ) ),
			__METHOD__,
			'an empty string counts as missing'
		);
	}

	public function test_settle_cutoff_measures_the_refresh_window( Runner $t ): void {
		$t->check(
			'2026-10-01 00:00:00' === People_Sync::settle_cutoff( '2026-10-02 00:00:00', 86400 ),
			__METHOD__,
			'the cutoff subtracts the whole window'
		);
		$t->check(
			'2026-09-02 12:00:00' === People_Sync::settle_cutoff( '2026-10-02 12:00:00' ),
			__METHOD__,
			'the default window is 30 days'
		);
		$t->check(
			'2026-10-02 00:00:00' === People_Sync::settle_cutoff( '2026-10-02 00:00:00', 0 ),
			__METHOD__,
			'a zero window leaves now unchanged'
		);
		$t->check(
			'2026-10-02 00:00:00' === People_Sync::settle_cutoff( '2026-10-02 00:00:00', -500 ),
			__METHOD__,
			'a negative window cannot push the cutoff into the future'
		);
		$t->check(
			'' !== People_Sync::settle_cutoff( 'not a date' ),
			__METHOD__,
			'unusable input still yields a usable cutoff'
		);
	}

	public function test_sweep_limit_survives_the_hook_empty_string( Runner $t ): void {
		// WordPress calls a one-accepted-arg hook callback with a single '',
		// which a typed $limit used to reject with a TypeError and that took
		// the whole daily profile refresh down. The cap must absorb it.
		$t->check(
			People_Sync::SWEEP_LIMIT === People_Sync::sweep_limit( '' ),
			__METHOD__,
			"WordPress's empty-string hook arg falls back to the default cap"
		);
		$t->check( 250 === People_Sync::sweep_limit( 250 ), __METHOD__, 'a positive int is honoured' );
		$t->check( 250 === People_Sync::sweep_limit( '250' ), __METHOD__, 'a numeric string is honoured' );
		$t->check( People_Sync::SWEEP_LIMIT === People_Sync::sweep_limit( 0 ), __METHOD__, 'zero falls back' );
		$t->check( People_Sync::SWEEP_LIMIT === People_Sync::sweep_limit( -5 ), __METHOD__, 'a negative cap falls back' );
		$t->check( People_Sync::SWEEP_LIMIT === People_Sync::sweep_limit( null ), __METHOD__, 'null falls back' );
		$t->check( People_Sync::SWEEP_LIMIT === People_Sync::sweep_limit( 'nope' ), __METHOD__, 'a non-numeric string falls back' );
	}

	public function test_gap_counts_split_and_clamp( Runner $t ): void {
		$t->check(
			array( 'all' => 10, 'living' => 6, 'deceased' => 4 ) === People_Sync::gap_counts( 10, 4 ),
			__METHOD__,
			'the deceased part is subtracted to give the living part'
		);
		$t->check(
			array( 'all' => 5, 'living' => 5, 'deceased' => 0 ) === People_Sync::gap_counts( 5, 0 ),
			__METHOD__,
			'no deceased records leaves everything living'
		);
		$t->check(
			array( 'all' => 3, 'living' => 0, 'deceased' => 3 ) === People_Sync::gap_counts( 3, 3 ),
			__METHOD__,
			'all deceased leaves nothing living'
		);
		$t->check(
			array( 'all' => 2, 'living' => 0, 'deceased' => 2 ) === People_Sync::gap_counts( 2, 9 ),
			__METHOD__,
			'a deceased count above the total cannot make living negative'
		);
	}

	public function test_normalize_scope_refuses_to_widen_the_pass( Runner $t ): void {
		// A garbled form post, CLI flag or hook arg must never turn a targeted
		// pass into a whole-catalogue re-ask, so anything unrecognised means the
		// deceased-only default rather than the broadest scope.
		$t->check( 'deceased' === People_Sync::normalize_scope( 'deceased' ), __METHOD__, 'deceased is accepted' );
		$t->check( 'living' === People_Sync::normalize_scope( 'living' ), __METHOD__, 'living is accepted' );
		$t->check( 'published' === People_Sync::normalize_scope( 'published' ), __METHOD__, 'published is accepted' );
		$t->check( 'living' === People_Sync::normalize_scope( '  LIVING  ' ), __METHOD__, 'case and surrounding space are forgiven' );
		$t->check( 'deceased' === People_Sync::normalize_scope( '' ), __METHOD__, 'an empty scope falls back to deceased' );
		$t->check( 'deceased' === People_Sync::normalize_scope( 'everyone' ), __METHOD__, 'an unknown scope falls back to deceased' );
		$t->check( 'deceased' === People_Sync::normalize_scope( '../publish' ), __METHOD__, 'a traversal attempt falls back to deceased' );
	}

	public function test_scope_expression_makes_living_the_negation_of_deceased( Runner $t ): void {
		$meta      = 'wp_postmeta';
		$deceased  = People_Sync::scope_expression( 'deceased', $meta );
		$living    = People_Sync::scope_expression( 'living', $meta );
		$published = People_Sync::scope_expression( 'published', $meta );

		$t->check( str_contains( $deceased, 'obit_death_date' ), __METHOD__, 'deceased reads the death-date meta' );
		$t->check( ! str_contains( $deceased, 'NOT' ), __METHOD__, 'deceased is a plain existence test' );
		$t->check( "NOT ( {$deceased} )" === $living, __METHOD__, 'living is the exact negation of deceased' );
		$t->check( str_contains( $living, $meta ), __METHOD__, 'the expression uses the postmeta table it was handed' );
		$t->check( '1 = 1' === $published, __METHOD__, 'published filters nothing' );
		$t->check( $deceased === People_Sync::scope_expression( 'nonsense', $meta ), __METHOD__, 'an unknown scope means deceased' );
	}
}

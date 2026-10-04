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
}

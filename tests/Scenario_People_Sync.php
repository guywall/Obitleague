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
}

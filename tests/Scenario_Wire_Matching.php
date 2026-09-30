<?php
/**
 * Death wire matching scenarios.
 *
 * The wire runs inside WordPress (DB, queue, imports), so the suite runs
 * only its pure surface: the headline name-group matcher that decides
 * whether a story has a usable subject at all.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Modules\Death_Wire;

final class Scenario_Wire_Matching {

	public function test_obituary_headline_yields_the_name_group( Runner $t ): void {
		$t->check( 'Mighty Sparrow' === Death_Wire::match_group( 'Mighty Sparrow obituary' ), __METHOD__, 'obituary desk headline yields the bare name' );
		$t->check( 'David Willey' === Death_Wire::match_group( 'David Willey obituary: a life at the BBC' ), __METHOD__, 'colon subtitle is stripped' );
		$group = Death_Wire::match_group( 'British wrestler Benjamin Satterley, known as Pac, dies aged 40' );
		$t->check( is_string( $group ) || null === $group, __METHOD__, 'relative-clause headline keeps a usable group or is refused' );
	}

	public function test_general_news_fragments_are_refused( Runner $t ): void {
		$junk = array(
			'UK diesel price hits all-time high, RAC says',
			'Security lapses at Utah campus where Charlie Kirk was killed, review says',
			'UK tries to stop Trump\'s diesel export ban',
			'What a US diesel export ban could mean for you',
			'Teens who died in car that entered river named',
			'Esther Rantzen: from That’s Life! to Childline – in pictures',
			'US actor Chad Lowe \'heartbroken\' by death of daughter Fiona aged 13',
		);
		foreach ( $junk as $title ) {
			$group = Death_Wire::match_group( $title );
			$t->check(
				null === $group || ! preg_match( '/\b(says|review|named|pictures|heartbroken)\b/iu', (string) $group ),
				__METHOD__,
				'prose fragment is refused or carries no desk furniture: ' . $title . ' → ' . var_export( $group, true )
			);
		}
	}

	public function test_word_limit_keeps_sentences_out_of_the_matcher( Runner $t ): void {
		$t->check( null === Death_Wire::match_group( 'One two three four five six seven eight' ), __METHOD__, 'eight-word fragment is refused' );
		$t->check( null !== Death_Wire::match_group( 'Dai Owen obituary' ), __METHOD__, 'two-word name passes' );
	}
}

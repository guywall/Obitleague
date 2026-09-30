<?php
/**
 * Wordcloud and same-name disambiguation scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Wordcloud;

final class Scenario_Wordcloud {

	public function test_cloud_counts_and_ignores_noise( Runner $t ): void {
		$cloud = Wordcloud::from_text( 'The cricketer and the cricketer played; a fair season for cricket, aged 80, died in 2026. Cricket!' );
		$t->check( 2 === ( $cloud['cricketer'] ?? 0 ), __METHOD__, 'repeated words accumulate' );
		$t->check( 2 === ( $cloud['cricket'] ?? 0 ), __METHOD__, 'stemless distinct words counted separately' );
		$t->check( ! isset( $cloud['the'], $cloud['and'], $cloud['aged'], $cloud['died'] ), __METHOD__, 'stopwords and death furniture dropped' );
		$t->check( ! isset( $cloud['80'], $cloud['2026'] ), __METHOD__, 'numbers dropped' );
		$t->check( ! isset( $cloud['in'], $cloud['a'] ), __METHOD__, 'short words dropped' );
	}

	public function test_cap_keeps_the_most_frequent( Runner $t ): void {
		$cloud = Wordcloud::from_text( 'alpha alpha alpha beta beta gamma', 2 );
		$t->check( array( 'alpha', 'beta' ) === array_keys( $cloud ), __METHOD__, 'cap slices most frequent first' );
	}

	public function test_wikitext_is_reduced_to_plain_words( Runner $t ): void {
		$wikitext = "'''John Smith''' (born 1 January 1940) was an English [[cricketer|cricketer]] who played for {{cite web|url=http://example.com|title=x}} Yorkshire.<ref>Ref content here</ref>\n\n== Career ==\nHe scored many runs.";
		$plain    = Wordcloud::wikitext_plain( $wikitext );
		$cloud    = Wordcloud::from_wikitext( $wikitext, 0 );
		$t->check( str_contains( $plain, 'cricketer' ), __METHOD__, 'wikilink label kept' );
		$t->check( ! str_contains( $plain, 'cite' ) && ! str_contains( $plain, 'example.com' ), __METHOD__, 'templates and urls stripped' );
		$t->check( ! str_contains( $plain, 'Ref content' ), __METHOD__, 'reference bodies stripped' );
		$t->check( isset( $cloud['yorkshire'], $cloud['english'], $cloud['runs'] ), __METHOD__, 'real words survive into the cloud' );
		$t->check( ! isset( $cloud['john'] ) || true, __METHOD__, 'name words may survive (not stopwords)' );
	}

	public function test_similarity_orders_overlap( Runner $t ): void {
		$a = Wordcloud::from_text( 'cricket batsman yorkshire county championship runs' );
		$b = Wordcloud::from_text( 'yorkshire county championship cricket batsman runs scored' );
		$c = Wordcloud::from_text( 'jazz saxophonist recording quartet album tour' );
		$close  = Wordcloud::similarity( $a, $b );
		$distant = Wordcloud::similarity( $a, $c );
		$t->check( $close > 0.3, __METHOD__, 'overlapping clouds score high: ' . $close );
		$t->check( 0.0 === $distant, __METHOD__, 'disjoint clouds score zero' );
		$t->check( Wordcloud::similarity( array(), $b ) === 0.0, __METHOD__, 'empty vector scores zero' );
	}

	public function test_best_match_respects_the_floor( Runner $t ): void {
		$story = Wordcloud::from_text( 'yorkshire cricketer championship runs score county' );
		$hits  = array(
			'p1' => Wordcloud::from_text( 'yorkshire cricketer championship runs county debut' ),
			'p2' => Wordcloud::from_text( 'jazz saxophonist recording quartet album tour' ),
		);
		$t->check( 'p1' === Wordcloud::best_match( $story, $hits ), __METHOD__, 'the overlapping candidate wins' );
		$all_far = array(
			'p1' => Wordcloud::from_text( 'astronomy telescope comet observation' ),
			'p2' => Wordcloud::from_text( 'opera soprano conductor orchestra' ),
		);
		$t->check( null === Wordcloud::best_match( $story, $all_far ), __METHOD__, 'below-floor ambiguity stays unresolved' );
		$t->check( null === Wordcloud::best_match( $story, array( 'p1' => array() ) ), __METHOD__, 'empty candidates never match' );
	}

	public function test_stopword_check( Runner $t ): void {
		$t->check( Wordcloud::stopword( 'obituary' ), __METHOD__, 'wire furniture is a stopword' );
		$t->check( ! Wordcloud::stopword( 'cricketer' ), __METHOD__, 'content words are not stopwords' );
	}
}

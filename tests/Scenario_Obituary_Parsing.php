<?php
/**
 * Obituary article parsing and source-domain scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Obituary_Article;
use Obitleague\Domain\Sources;

final class Scenario_Obituary_Parsing {

	public function test_signature_line_yields_name_and_exact_date( Runner $t ): void {
		$parsed = Obituary_Article::parse( 'Rory Stewart, the writer and former politician, dies after a short illness. Full obituary here. Rory Stewart, writer and politician, died on 12 September 2026. He was 52.' );
		$t->check( 'Rory Stewart' === $parsed['name'], __METHOD__, 'name captured from the signature' );
		$t->check( '2026-09-12' === $parsed['death_date'], __METHOD__, 'exact date captured from the signature' );
	}

	public function test_american_date_order_parses( Runner $t ): void {
		$parsed = Obituary_Article::parse( 'Jane Smith, actress, died September 12, 2026, at her home in Maine.' );
		$t->check( '2026-09-12' === $parsed['death_date'], __METHOD__, 'September 12, 2026 → 2026-09-12' );
	}

	public function test_no_signature_yields_nothing( Runner $t ): void {
		$parsed = Obituary_Article::parse( 'A fond look back at a long career in television.' );
		$t->check( '' === $parsed['name'] && '' === $parsed['death_date'], __METHOD__, 'no signature, no facts' );
	}

	public function test_dayless_or_implausible_dates_are_refused( Runner $t ): void {
		$parsed = Obituary_Article::parse( 'The actor died in 1899 after a long career.' );
		$t->check( '' === $parsed['death_date'], __METHOD__, 'year-only and 19th-century dates refused' );
	}

	public function test_registrable_domains( Runner $t ): void {
		$t->check( 'theguardian.com' === Sources::registrable_domain( 'https://www.theguardian.com/tone/obituaries/rss' ), __METHOD__, 'www stripped' );
		$t->check( 'bbc.co.uk' === Sources::registrable_domain( 'https://www.bbc.co.uk/news/x' ), __METHOD__, 'multi-part suffix kept whole' );
		$t->check( '' === Sources::registrable_domain( 'not a url' ), __METHOD__, 'no host → empty' );
	}

	public function test_duplicate_domains_count_once( Runner $t ): void {
		$domains = array(
			Sources::registrable_domain( 'https://www.theguardian.com/a' ),
			Sources::registrable_domain( 'https://theguardian.com/b' ),
			Sources::registrable_domain( 'https://www.bbc.co.uk/c' ),
		);
		$t->check( 2 === count( array_unique( $domains ) ), __METHOD__, 'same outlet twice = one domain' );
	}
}

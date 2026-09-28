<?php
/**
 * Living-person Discovery screening scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Discovery_Rules;

final class Scenario_Discovery {

	public function test_requires_a_non_deprecated_human_claim( Runner $t ): void {
		$human = array( 'claims' => array( 'P31' => array( array( 'mainsnak' => array( 'datavalue' => array( 'value' => array( 'id' => 'Q5' ) ) ) ) ) ) );
		$dead_rank = $human;
		$dead_rank['claims']['P31'][0]['rank'] = 'deprecated';
		$other = array( 'claims' => array( 'P31' => array( array( 'mainsnak' => array( 'datavalue' => array( 'value' => array( 'id' => 'Q43229' ) ) ) ) ) ) );
		$t->check( Discovery_Rules::is_human( $human ), __METHOD__, 'Q5 instance claim identifies a human' );
		$t->check( ! Discovery_Rules::is_human( $dead_rank ), __METHOD__, 'deprecated human claim is ignored' );
		$t->check( ! Discovery_Rules::is_human( $other ), __METHOD__, 'non-human entity is excluded' );
	}

	public function test_any_active_death_claim_excludes_candidate( Runner $t ): void {
		$entity = array( 'claims' => array( 'P570' => array( array( 'mainsnak' => array() ) ) ) );
		$deprecated = array( 'claims' => array( 'P570' => array( array( 'rank' => 'deprecated', 'mainsnak' => array() ) ) ) );
		$t->check( Discovery_Rules::has_death_claim( $entity ), __METHOD__, 'normal death claim excludes candidate' );
		$t->check( ! Discovery_Rules::has_death_claim( $deprecated ), __METHOD__, 'deprecated death claim does not exclude candidate' );
	}

	public function test_birth_requires_unambiguous_day_precision( Runner $t ): void {
		$exact = array(
			'claims' => array(
				'P569' => array(
					array( 'rank' => 'normal', 'mainsnak' => array( 'datavalue' => array( 'value' => array( 'time' => '+1940-02-29T00:00:00Z', 'precision' => 11 ) ) ) ),
			),
			),
		);
		$t->check( '1940-02-29' === Discovery_Rules::exact_birth_date( $exact ), __METHOD__, 'exact valid Wikidata date is retained' );
		$exact['claims']['P569'][0]['mainsnak']['datavalue']['value']['precision'] = 9;
		$t->check( null === Discovery_Rules::exact_birth_date( $exact ), __METHOD__, 'year-only precision is rejected' );
		$exact['claims']['P569'][0]['mainsnak']['datavalue']['value'] = array( 'time' => '+1940-02-30T00:00:00Z', 'precision' => 11 );
		$t->check( null === Discovery_Rules::exact_birth_date( $exact ), __METHOD__, 'impossible calendar date is rejected' );
	}

	public function test_conflicting_birth_dates_fail_closed( Runner $t ): void {
		$claim = static fn ( string $date, string $rank = 'normal' ): array => array( 'rank' => $rank, 'mainsnak' => array( 'datavalue' => array( 'value' => array( 'time' => $date . 'T00:00:00Z', 'precision' => 11 ) ) ) );
		$entity = array( 'claims' => array( 'P569' => array( $claim( '1940-02-29' ), $claim( '1941-02-28' ) ) ) );
		$t->check( null === Discovery_Rules::exact_birth_date( $entity ), __METHOD__, 'conflicting active birth statements are rejected' );
		$entity['claims']['P569'] = array( $claim( '1940-02-29', 'preferred' ), $claim( '1941-02-28', 'normal' ) );
		$t->check( null === Discovery_Rules::exact_birth_date( $entity ), __METHOD__, 'preferred birth claim does not conceal a conflicting non-deprecated claim' );
	}

	public function test_wikipedia_categories_are_only_screening_signals( Runner $t ): void {
		$t->check( Discovery_Rules::has_living_category( array( 'Category:Living people' ) ), __METHOD__, 'positive living category recognized' );
		$t->check( ! Discovery_Rules::has_living_category( array( 'Category:Living people by occupation' ) ), __METHOD__, 'similar but non-positive category does not count' );
		$t->check( array( '2024 deaths' ) === Discovery_Rules::death_categories( array( 'Category:2024 deaths', 'Category:20th-century people' ), 1960 ), __METHOD__, 'death-year category after birth excludes candidate' );
		$t->check( array() === Discovery_Rules::death_categories( array( 'Category:1940 deaths' ), 1940 ), __METHOD__, 'same-year category is not treated as a reliable match' );
	}

	public function test_exact_age_and_entry_season_threshold( Runner $t ): void {
		$t->check( Discovery_Rules::is_old_enough( '2009-01-01', 2027 ), __METHOD__, 'person reaches 18 by season entry deadline' );
		$t->check( ! Discovery_Rules::is_old_enough( '2009-01-02', 2027 ), __METHOD__, 'person one day too young at season start is excluded' );
		$t->check( ! Discovery_Rules::is_old_enough( '2009-01', 2027 ), __METHOD__, 'partial date is rejected' );
	}

	public function test_living_evidence_must_be_recent_valid_and_https( Runner $t ): void {
		$t->check( Discovery_Rules::is_recent_evidence_date( '2026-09-28', '2026-09-28' ), __METHOD__, 'today is recent evidence' );
		$t->check( Discovery_Rules::is_recent_evidence_date( '2025-09-29', '2026-09-28' ), __METHOD__, 'within the rolling 12-month window' );
		$t->check( ! Discovery_Rules::is_recent_evidence_date( '2025-09-27', '2026-09-28' ), __METHOD__, 'older than 12 months is rejected' );
		$t->check( ! Discovery_Rules::is_recent_evidence_date( '2026-09-29', '2026-09-28' ), __METHOD__, 'future publication date is rejected' );
		$t->check( ! Discovery_Rules::is_recent_evidence_date( '2026-02-30', '2026-09-28' ), __METHOD__, 'invalid date is rejected' );
		$t->check( Discovery_Rules::is_https_source_url( 'https://news.example.org/story' ), __METHOD__, 'absolute HTTPS source accepted' );
		$t->check( ! Discovery_Rules::is_https_source_url( 'http://news.example.org/story' ), __METHOD__, 'unencrypted source rejected' );
		$t->check( ! Discovery_Rules::is_https_source_url( 'https://editor:secret@news.example.org/story' ), __METHOD__, 'credential-bearing source rejected' );
		$t->check( Discovery_Rules::is_approved_living_evidence( 'https://news.example.org/story', '2026-09-28', true, '2026-09-28' ), __METHOD__, 'confirmed alive with recent secure evidence accepted' );
		$t->check( ! Discovery_Rules::is_approved_living_evidence( 'https://news.example.org/story', '2026-09-28', false, '2026-09-28' ), __METHOD__, 'source without editor confirmation cannot approve' );
	}
}

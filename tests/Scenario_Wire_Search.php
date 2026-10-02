<?php
/**
 * Wikipedia title-search response scenarios.
 *
 * The wire only knows a name has "no article" when the search says so
 * cleanly. A transport error, a throttle or a malformed body must read as
 * "we could not ask" and must never be cached as a negative answer, or a
 * well-known person is parked as no_anchor until the cache expires.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Wire_Search;

final class Scenario_Wire_Search {

	public function test_transport_failure_is_transient_not_empty( Runner $t ): void {
		$verdict = Wire_Search::interpret( true, 0, null );
		$t->check( Wire_Search::TRANSIENT === $verdict['outcome'], __METHOD__, 'a transport error is a failure to ask' );
		$t->check( '' === $verdict['title'], __METHOD__, 'a transient failure yields no title' );
		$t->check( ! Wire_Search::is_definitive( $verdict['outcome'] ), __METHOD__, 'a transient failure is never cacheable' );
	}

	public function test_throttling_is_transient_not_empty( Runner $t ): void {
		foreach ( array( 429, 503 ) as $code ) {
			$verdict = Wire_Search::interpret( false, $code, null );
			$t->check( Wire_Search::THROTTLED === $verdict['outcome'], __METHOD__, "HTTP {$code} is a throttle" );
			$t->check( ! Wire_Search::is_definitive( $verdict['outcome'] ), __METHOD__, "HTTP {$code} is never cacheable" );
		}
	}

	public function test_other_http_errors_are_transient( Runner $t ): void {
		foreach ( array( 400, 403, 500, 502, 504 ) as $code ) {
			$verdict = Wire_Search::interpret( false, $code, null );
			$t->check( Wire_Search::TRANSIENT === $verdict['outcome'], __METHOD__, "HTTP {$code} is a failure to ask" );
			$t->check( ! Wire_Search::is_definitive( $verdict['outcome'] ), __METHOD__, "HTTP {$code} is never cacheable" );
		}
	}

	public function test_malformed_200_body_is_transient( Runner $t ): void {
		$bodies = array(
			null,
			'not json',
			array(),
			array( 'query' => array() ),
			array( 'query' => array( 'search' => 'oops' ) ),
		);
		foreach ( $bodies as $body ) {
			$verdict = Wire_Search::interpret( false, 200, $body );
			$t->check( Wire_Search::TRANSIENT === $verdict['outcome'], __METHOD__, 'a 200 with no readable answer is transient: ' . var_export( $body, true ) );
			$t->check( ! Wire_Search::is_definitive( $verdict['outcome'] ), __METHOD__, 'a malformed body is never cacheable' );
		}
	}

	public function test_genuine_empty_result_is_definitive( Runner $t ): void {
		$verdict = Wire_Search::interpret( false, 200, array( 'batchcomplete' => true, 'query' => array( 'search' => array() ) ) );
		$t->check( Wire_Search::NONE === $verdict['outcome'], __METHOD__, 'an empty search result is definitive' );
		$t->check( '' === $verdict['title'], __METHOD__, 'an empty result yields no title' );
		$t->check( Wire_Search::is_definitive( $verdict['outcome'] ), __METHOD__, 'a genuine empty result is cacheable' );
	}

	public function test_hit_is_definitive( Runner $t ): void {
		$verdict = Wire_Search::interpret( false, 200, array( 'query' => array( 'search' => array( array( 'title' => 'Bob Pettit' ) ) ) ) );
		$t->check( Wire_Search::FOUND === $verdict['outcome'], __METHOD__, 'a search hit is found' );
		$t->check( 'Bob Pettit' === $verdict['title'], __METHOD__, 'the hit title is returned' );
		$t->check( Wire_Search::is_definitive( $verdict['outcome'] ), __METHOD__, 'a hit is cacheable' );
	}
}

<?php
/**
 * Public scope scenarios.
 *
 * The exclusion rule is what keeps synthetic leagues and accounts off the
 * public surfaces, so it is asserted here in its WordPress-free parts: the
 * SQL fragment every read path builds from, and the word-token matchers the
 * flagging tool suggests with. A rule that silently stopped matching would
 * put the QA rows back on the front page without failing anything else.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Modules\Public_Scope;

final class Scenario_Public_Scope {

	public function test_flag_names_are_stable( Runner $t ): void {
		$t->check( 'is_hidden' === Public_Scope::LEAGUE_COLUMN, __METHOD__, 'league flag column: ' . Public_Scope::LEAGUE_COLUMN );
		$t->check( 'obitleague_test_account' === Public_Scope::META_TEST_ACCOUNT, __METHOD__, 'account flag meta: ' . Public_Scope::META_TEST_ACCOUNT );
	}

	public function test_exclusion_clause_is_a_not_exists_on_the_account_column( Runner $t ): void {
		$sql = Public_Scope::exclusion_clause( 'r.user_id', 'wp_usermeta', 'obitleague_test_account' );
		$t->check( str_contains( $sql, 'NOT EXISTS' ), __METHOD__, 'uses NOT EXISTS so rows without the meta are kept: ' . $sql );
		$t->check( str_contains( $sql, 'ob_scope_um.user_id = r.user_id' ), __METHOD__, 'correlates on the caller\'s column' );
		$t->check( str_contains( $sql, 'ob_scope_um.meta_key = \'obitleague_test_account\'' ), __METHOD__, 'matches the flag meta key' );
		$t->check( str_contains( $sql, 'ob_scope_um.meta_value = \'1\'' ), __METHOD__, 'matches the flag value' );
		$t->check( str_starts_with( trim( $sql ), 'AND' ), __METHOD__, 'concatenates onto an existing WHERE clause' );
	}

	public function test_hidden_league_clause_targets_the_alias( Runner $t ): void {
		$t->check( ' AND l.is_hidden = 0' === Public_Scope::hidden_league_exclusion(), __METHOD__, 'default alias' );
		$t->check( ' AND lg.is_hidden = 0' === Public_Scope::hidden_league_exclusion( 'lg' ), __METHOD__, 'explicit alias' );
	}

	public function test_league_name_suggestions_match_whole_words_only( Runner $t ): void {
		$t->check( Public_Scope::looks_like_test_league( "Guy's Test League 2027" ), __METHOD__, 'the live test league is suggested' );
		$t->check( Public_Scope::looks_like_test_league( 'Testings' ), __METHOD__, 'the live test league is suggested' );
		$t->check( Public_Scope::looks_like_test_league( 'Office Pool QA' ), __METHOD__, 'qa tag suggested' );
		// The canonical main league must never be suggested: hiding it would
		// take the whole game off the site.
		$t->check( ! Public_Scope::looks_like_test_league( 'Overall League 2027' ), __METHOD__, 'main league never matched' );
		$t->check( ! Public_Scope::looks_like_test_league( 'Contested Cup' ), __METHOD__, 'substring of "test" is not a match' );
		$t->check( ! Public_Scope::looks_like_test_league( 'Latest News' ), __METHOD__, 'substring of "test" is not a match' );
	}

	public function test_account_suggestions_read_login_display_and_email( Runner $t ): void {
		$t->check( Public_Scope::looks_like_test_account( 'qa.human.pass.1-ydzs6lha', 'QA Human Pass One', 'qa.human.pass.1@example.com' ), __METHOD__, 'login and display suggested' );
		$t->check( Public_Scope::looks_like_test_account( 'rowan', 'Demo Account One QA', '' ), __METHOD__, 'display suggested' );
		$t->check( Public_Scope::looks_like_test_account( 'someone', 'Someone', 'demo.user@example.com' ), __METHOD__, 'email local part suggested' );
		$t->check( ! Public_Scope::looks_like_test_account( 'jk_rowling', 'J. K. Rowling', 'jk@example.com' ), __METHOD__, 'a real account is not suggested' );
		// Documented limitation: an opaque synthetic login has no matching
		// word, so it is only ever flagged by the explicit list. The tool
		// reports that rather than guessing.
		$t->check( ! Public_Scope::looks_like_test_account( 'barrywhite5-qkuhd8vf', 'barrywhite5-qkuhd8vf', '' ), __METHOD__, 'opaque login is not guessed' );
	}
}

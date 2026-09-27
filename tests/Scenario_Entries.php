<?php
/**
 * Entry and deadline scenarios.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Entry_Rules;
use Obitleague\Domain\Invalid_Team_Exception;
use Obitleague\Domain\League_Rules;
use Obitleague\Domain\Submission_Receipt;
use Obitleague\Domain\Value\Ruleset;

require_once __DIR__ . '/../src/Domain/League_Rules.php';
require_once __DIR__ . '/../src/Domain/Deadline_Policy.php';
require_once __DIR__ . '/../src/Domain/Entry_Rules.php';
require_once __DIR__ . '/../src/Domain/Submission_Receipt.php';

final class Scenario_Entries {

	private const TEN = array( 'p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p10' );

	public function test_drafts_and_submitted_teams_can_change_until_lock( Runner $t ): void {
		$t->check( 'create_draft' === Entry_Rules::save_effect( Entry_Rules::DRAFT, true ), __METHOD__, 'open deadline: save creates a draft' );
		$t->check( 'create_amendment' === Entry_Rules::save_effect( Entry_Rules::SUBMITTED, true ), __METHOD__, 'submitted team can be amended before next season starts' );
		$t->check( 'refuse' === Entry_Rules::save_effect( Entry_Rules::DRAFT, false ), __METHOD__, 'late draft save is refused' );
		$t->check( 'refuse' === Entry_Rules::save_effect( Entry_Rules::SUBMITTED, false ), __METHOD__, 'late submitted amendment is refused' );
	}

	public function test_submit_only_from_draft_while_open( Runner $t ): void {
		$t->check( 'submit_draft' === Entry_Rules::submit_effect( Entry_Rules::DRAFT, true ), __METHOD__, 'draft submits while open' );
		$t->check( 'refuse' === Entry_Rules::submit_effect( Entry_Rules::SUBMITTED, true ), __METHOD__, 'double submit refused' );
		$t->check( 'refuse' === Entry_Rules::submit_effect( Entry_Rules::DRAFT, false ), __METHOD__, 'late submit refused' );
	}

	public function test_validate_picks_normalises_and_enforces_ten( Runner $t ): void {
		$out = Entry_Rules::validate_picks( self::TEN );
		$t->check( 10 === count( $out ) && 'p1' === $out[0], __METHOD__, 'ten distinct picks pass through' );

		try {
			Entry_Rules::validate_picks( array( 'a', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i' ) );
			$t->fail( __METHOD__, 'duplicates must throw' );
		} catch ( Invalid_Team_Exception $e ) {
			$t->check( str_contains( $e->getMessage(), 'distinct' ), __METHOD__, 'duplicates rejected with reason' );
		}

		try {
			Entry_Rules::validate_picks( array( 'a', 'b', 'c' ) );
			$t->fail( __METHOD__, 'three picks must throw' );
		} catch ( Invalid_Team_Exception $e ) {
			$t->check( str_contains( $e->getMessage(), 'exactly 10' ), __METHOD__, 'wrong count rejected with reason' );
		}
	}

	public function test_already_dead_pick_replacement_vs_kept_zero( Runner $t ): void {
		$t->check( 'allow_replacement' === Entry_Rules::already_dead_effect( true ), __METHOD__, 'before lock: player may replace' );
		$t->check( 'keep_zero_explained' === Entry_Rules::already_dead_effect( false ), __METHOD__, 'after lock: kept, zero, explained' );
	}

	public function test_score_submission_counts_only_awarded_picks( Runner $t ): void {
		$score = Entry_Rules::score_submission( self::TEN, array( 'p2' => 22, 'p7' => 5 ) );
		$t->check( 27 === $score['points'] && 2 === $score['scoring_picks'], __METHOD__, '22 + 5 across ten picks' );
		$t->check( 0 === Entry_Rules::score_submission( self::TEN, array() )['points'], __METHOD__, 'no awards: zero points' );
	}

	public function test_commit_time_rule_on_deadline_boundary( Runner $t ): void {
		$before = new \DateTimeImmutable( '2026-12-31T23:59:59+00:00' );
		$at     = new \DateTimeImmutable( '2027-01-01T00:00:00+00:00' );

		$t->check( Deadline_Policy::commit_on_time( 2027, $before ), __METHOD__, 'commit before deadline is on time' );
		$t->check( ! Deadline_Policy::commit_on_time( 2027, $at ), __METHOD__, 'commit at deadline is late' );
		$t->check( ! Deadline_Policy::is_entry_open( 2027, $at ), __METHOD__, 'entry closed at the instant' );
	}

	public function test_receipt_requires_ten_picks_and_current_ruleset( Runner $t ): void {
		$deadline = Deadline_Policy::entry_deadline( 2027 );
		$receipt  = new Submission_Receipt( 'r-1', 5, 9, 2, 2027, self::TEN, Ruleset::VERSION, $before = new \DateTimeImmutable( '2026-12-31T10:00:00+00:00' ), $deadline );
		$t->check( $receipt->is_competing(), __METHOD__, 'receipt before deadline competes' );
		$t->check( str_contains( $receipt->summary(), 'ruleset v1' ), __METHOD__, 'summary names the ruleset' );

		try {
			new Submission_Receipt( 'r-2', 5, 9, 2, 2027, array( 'p1' ), Ruleset::VERSION, $before, $deadline );
			$t->fail( __METHOD__, 'one-pick receipt must throw' );
		} catch ( \InvalidArgumentException ) {
			$t->check( true, __METHOD__, 'receipt enforces ten picks' );
		}
	}

	public function test_invite_tokens_hashed_and_lifetime_bounded( Runner $t ): void {
		$hash = League_Rules::hash_invite_token( 's3cret' );
		$t->check( 64 === strlen( $hash ) && 's3cret' !== $hash, __METHOD__, 'token stored as sha256, never plaintext' );
		$t->check( League_Rules::invite_token_matches( 's3cret', $hash ), __METHOD__, 'matching token verifies' );
		$t->check( ! League_Rules::invite_token_matches( 'wrong', $hash ), __METHOD__, 'wrong token fails' );

		$issued = new \DateTimeImmutable( '2026-12-01T00:00:00+00:00' );
		$expiry = $issued->modify( '+' . League_Rules::INVITE_TTL_DAYS . ' days' );
		$t->check( League_Rules::invite_token_live( $expiry, $issued ), __METHOD__, 'token at issue time is live' );
		$t->check( League_Rules::invite_token_live( $expiry, $issued->modify( '+13 days' ) ), __METHOD__, 'token within TTL is live' );
		$t->check( ! League_Rules::invite_token_live( $expiry, $issued->modify( '+15 days' ) ), __METHOD__, 'token past TTL is dead' );

		$t->check( 'member' === League_Rules::status_after_join( true ), __METHOD__, 'open-deadline join is a member' );
		$t->check( 'spectator' === League_Rules::status_after_join( false ), __METHOD__, 'post-lock join is a spectator' );
		$t->check( ! League_Rules::can_submit_after_join( true, true ), __METHOD__, 'spectator cannot submit even pre-deadline' );
		$t->check( League_Rules::can_submit_after_join( false, true ), __METHOD__, 'member who joined pre-deadline can submit' );
	}
}

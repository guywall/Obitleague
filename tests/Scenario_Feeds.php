<?php
/**
 * Feed classification scenarios for the weighted death-detection classifier.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Tests;

use Obitleague\Domain\Feed_Classifier;

final class Scenario_Feeds {

	public function test_dies_aged_headline_is_a_strong_announcement( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Veteran actor Alan Howard dies aged 84' );
		$t->check( Feed_Classifier::DEATH_ANNOUNCEMENT === $result['classification'], __METHOD__, 'dies aged headline → death_announcement' );
		$t->check( $result['score'] >= Feed_Classifier::THRESHOLD_STRONG, __METHOD__, 'dies aged headline scores 70+' );
	}

	public function test_has_died_aged_pattern_is_a_strong_announcement( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Sir Michael Jayston has died aged 89' );
		$t->check( Feed_Classifier::DEATH_ANNOUNCEMENT === $result['classification'], __METHOD__, 'has died aged → death_announcement' );
	}

	public function test_relative_clause_death_pattern_is_a_strong_announcement( Runner $t ): void {
		$result = Feed_Classifier::classify( 'June Spencer, who has died aged 105, was the last of the original Archers cast', 'The BBC announced her death has been confirmed by her family.' );
		$t->check( Feed_Classifier::DEATH_ANNOUNCEMENT === $result['classification'], __METHOD__, 'who has died aged clause → death_announcement' );
	}

	public function test_passes_away_and_killed_phrases_signal_death( Runner $t ): void {
		foreach ( array(
			'Long-serving presenter passes away at 71'          => 'passes away',
			'Former minister was killed in a car crash, family confirms' => 'was killed',
			'Novelist is dead at 93, publisher announces'        => 'is dead',
		) as $title => $label ) {
			$result = Feed_Classifier::classify( $title );
			$t->check( Feed_Classifier::is_death_signal( $result['classification'] ), __METHOD__, "{$label} headline is a death signal" );
		}
	}

	public function test_dedicated_obituary_feed_boosts_into_death_signal( Runner $t ): void {
		$result = Feed_Classifier::classify(
			'The career of a beloved character actor, in pictures',
			'A fond look back at a long career.',
			array( 'source_name' => 'BBC News — Obituaries' )
		);
		$t->check( Feed_Classifier::is_death_signal( $result['classification'] ), __METHOD__, 'obituary-desk source lifts a retrospective over the line' );
		$t->check( Feed_Classifier::OBITUARY === $result['classification'], __METHOD__, 'obituary-desk stories carry the obituary genre' );
	}

	public function test_death_hoax_is_not_death( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Actor denies death hoax: I am still alive', 'The star dismissed false reports of his death.' );
		$t->check( Feed_Classifier::NOT_DEATH === $result['classification'], __METHOD__, 'hoax and denial → not_death' );
		$t->check( array() !== $result['excluded'], __METHOD__, 'the exclusion phrase is recorded' );
	}

	public function test_falsely_reported_dead_is_not_death( Runner $t ): void {
		foreach ( array(
			'Singer mistakenly reported dead by radio station',
			'Rumours of his death are greatly exaggerated, says actor',
			'Footballer dies — death rumours spread online' ,
		) as $title ) {
			$result = Feed_Classifier::classify( $title );
			$t->check( Feed_Classifier::NOT_DEATH === $result['classification'], __METHOD__, "{$title} → not_death" );
		}
	}

	public function test_fictional_deaths_are_excluded( Runner $t ): void {
		foreach ( array(
			'Soap opera character killed off after 20 years',
			'Fans react to shocking death scene in season finale',
			'The on-screen death that still haunts viewers',
		) as $title ) {
			$result = Feed_Classifier::classify( $title );
			$t->check( Feed_Classifier::NOT_DEATH === $result['classification'], __METHOD__, "{$title} → not_death" );
		}
	}

	public function test_anniversary_pieces_are_not_new_deaths( Runner $t ): void {
		$result = Feed_Classifier::classify( 'On this day: the death of a screen legend, ten years on', 'An anniversary retrospective of a famous death.' );
		$t->check( Feed_Classifier::NOT_DEATH === $result['classification'], __METHOD__, 'anniversary context → not_death' );
	}

	public function test_funeral_and_inquest_followups_stay_out_of_the_announcement_band( Runner $t ): void {
		$funeral  = Feed_Classifier::classify( 'Family and friends attend funeral of much-loved broadcaster', 'Mourners gathered as the funeral took place.' );
		$inquest  = Feed_Classifier::classify( 'Inquest opens into the death of television presenter', 'The inquest heard opening statements.' );
		$t->check( Feed_Classifier::DEATH_ANNOUNCEMENT !== $funeral['classification'], __METHOD__, 'funeral follow-up is not an announcement' );
		$t->check( Feed_Classifier::DEATH_ANNOUNCEMENT !== $inquest['classification'], __METHOD__, 'inquest follow-up is not an announcement' );
	}

	public function test_tribute_piece_with_death_phrase_still_announces( Runner $t ): void {
		// The genre word must not bury a title that announces a death itself.
		$result = Feed_Classifier::classify( 'Tributes paid as much-loved commentator dies aged 67' );
		$t->check( Feed_Classifier::DEATH_ANNOUNCEMENT === $result['classification'], __METHOD__, 'tributes + dies aged remains an announcement' );
	}

	public function test_quiet_body_mention_scores_below_the_line( Runner $t ): void {
		// A passing reference in body text with no title wording is not a
		// candidate; the wire must not spend editor attention on it.
		$result = Feed_Classifier::classify( 'Transfer window: ten deals that could still happen', '...while the club also mourns their former groundsman who died last week.' );
		$t->check( Feed_Classifier::NOT_DEATH === $result['classification'], __METHOD__, 'body-only mention below 50 → not_death' );
	}

	public function test_zero_signal_story_is_not_death( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Premier league preview: title race heats up' );
		$t->check( Feed_Classifier::NOT_DEATH === $result['classification'], __METHOD__, 'no death wording → not_death, score 0' );
		$t->check( 0 === $result['score'], __METHOD__, 'score is zero' );
	}

	public function test_confirmation_attribution_adds_confidence( Runner $t ): void {
		$with    = Feed_Classifier::classify( 'Broadcaster dies aged 80, family announces' );
		$without = Feed_Classifier::classify( 'Broadcaster dies aged 80' );
		$t->check( $with['score'] > $without['score'], __METHOD__, 'family confirmation raises the score' );
	}

	public function test_scores_never_exceed_the_cap( Runner $t ): void {
		$result = Feed_Classifier::classify( 'Actor has died aged 90, passes away peacefully, family confirms death announced, obituary', 'He has died, passed away, was killed. Family confirms. Obituary follows.' );
		$t->check( $result['score'] <= Feed_Classifier::SCORE_CAP, __METHOD__, 'score is capped' );
	}

	public function test_legacy_classifications_still_count_as_signals( Runner $t ): void {
		$t->check( Feed_Classifier::is_death_signal( Feed_Classifier::CANDIDATE ), __METHOD__, 'legacy candidate rows remain wire signals' );
		$t->check( ! Feed_Classifier::is_death_signal( Feed_Classifier::NOT_CANDIDATE ), __METHOD__, 'legacy not_candidate is not a signal' );
		$t->check( ! Feed_Classifier::is_death_signal( Feed_Classifier::NOT_DEATH ), __METHOD__, 'not_death is not a signal' );
	}
}

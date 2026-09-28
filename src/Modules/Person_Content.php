<?php
/**
 * Person page body content.
 *
 * A person record is only as useful as the page behind it. The catalogue stores
 * structured, editor-approved facts; without a body those facts render as a
 * single row in a definition list and the page reads as empty to a reader and
 * to a crawler alike.
 *
 * This module composes that body — and *only* that body — from fields an editor
 * has already approved. It is a formatter, not an author:
 *
 *   - every sentence is assembled from stored values; nothing is inferred,
 *     summarised or invented about a person's life;
 *   - partial dates are never widened (the same rule the JSON-LD follows);
 *   - a date can only become a date if it was approved as one;
 *   - the wording is neutral, and pronoun-free: this is a death pool, and the
 *     page has to stay dignified for families reading it.
 *
 * The body is therefore safe to regenerate at any time: it is a pure function of
 * the record. `regenerate()` writes only when the composed output actually
 * changes, so `post_modified` is not churned on every editorial pass.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Age;
use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Role_Label;
use Obitleague\Domain\Value\Ruleset;

final class Person_Content {

	private function __construct() {}

	/* ---------- public API ---------- */

	/**
	 * The occupation phrase used in prose: the recorded role, cleaned of any
	 * cause-of-death leakage, falling back to the primary occupation term.
	 */
	public static function descriptor( int $post_id ): string {
		$role = Role_Label::clean( (string) get_post_meta( $post_id, 'obit_role', true ) );
		if ( '' !== $role ) {
			return $role;
		}
		$primary = Role_Label::clean( People_Sync::primary_occupation( $post_id ) );
		if ( '' !== $primary ) {
			return $primary;
		}
		foreach ( People_Sync::occupation_labels( $post_id ) as $label ) {
			$label = Role_Label::clean( $label );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Recompose one record's body. Returns true when the post was written.
	 */
	public static function regenerate( int $post_id ): bool {
		$post = get_post( (int) $post_id );
		if ( ! $post || Catalogue::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$body = self::compose( (int) $post_id );
		if ( $body === $post->post_content ) {
			return false;
		}

		// wp_update_post, not a direct query: this must fire save_post and
		// invalidate Elementor's render cache for the page.
		$result = wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => $body,
			),
			true
		);
		return ! is_wp_error( $result );
	}

	/**
	 * Backfill bodies for published people. Bounded and re-runnable: records
	 * whose composed output already matches are left untouched.
	 *
	 * @return array{checked:int,written:int,skipped:int}
	 */
	public static function regenerate_all( int $limit = 1000 ): array {
		// Published only: a draft or rejected candidate has no business being
		// described as editor-approved.
		$post_ids = get_posts(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$stats = array( 'checked' => 0, 'written' => 0, 'skipped' => 0 );
		foreach ( (array) $post_ids as $pid ) {
			++$stats['checked'];
			if ( self::regenerate( (int) $pid ) ) {
				++$stats['written'];
			} else {
				++$stats['skipped'];
			}
		}
		return $stats;
	}

	/* ---------- composition ---------- */

	/**
	 * Compose the body of a person record as a small block of HTML paragraphs.
	 * Pure: the same record always yields the same output.
	 */
	public static function compose( int $post_id ): string {
		$name    = trim( (string) get_the_title( $post_id ) );
		$role    = self::descriptor( $post_id );
		$birth   = self::date_field( $post_id, 'obit_birth_date' );
		$death   = self::date_field( $post_id, 'obit_death_date' );
		$is_dead = null !== $death;
		$age     = self::age_at_death( $birth, $death );

		$paragraphs = array();

		$lead = '<strong>' . esc_html( $name ) . '</strong>';
		$span = self::life_span( $birth, $death );
		if ( '' !== $span ) {
			$lead .= ' (' . esc_html( $span ) . ')';
		}
		$lead .= $is_dead ? ' was ' : ' is ';
		$lead .= '' !== $role
			? esc_html( Role_Label::with_article( $role ) ) . '.'
			: 'in the catalogue with no occupation recorded.';

		if ( $is_dead ) {
			$death_sentence = 'A confirmed death is recorded as ' . esc_html( $death->label() );
			$death_sentence .= null !== $age
				? ', at the age of ' . esc_html( (string) $age ) . '.'
				: '.';
			$death_sentence .= ' Confirmed deaths stay in the catalogue and score, but can no longer be picked.';
			$lead         .= ' ' . $death_sentence;
		} else {
			$lead .= ' This record is pickable while the person is living.';
		}
		$paragraphs[] = $lead;

		$occupations = self::occupations_paragraph( $post_id );
		if ( '' !== $occupations ) {
			$paragraphs[] = $occupations;
		}

		if ( $is_dead ) {
			$cause = self::cause_paragraph( $post_id );
			if ( '' !== $cause ) {
				$paragraphs[] = $cause;
			}
			$scoring = self::settled_scoring_paragraph( $death, $age );
			if ( '' !== $scoring ) {
				$paragraphs[] = $scoring;
			}
		} else {
			$potential = self::potential_paragraph( $birth );
			if ( '' !== $potential ) {
				$paragraphs[] = $potential;
			}
		}

		$sourcing = self::sourcing_paragraph( $post_id );
		if ( '' !== $sourcing ) {
			$paragraphs[] = $sourcing;
		}

		if ( array() === $paragraphs ) {
			return '';
		}
		return '<p>' . implode( '</p><p>', $paragraphs ) . '</p>';
	}

	/* ---------- paragraph builders ---------- */

	/** Occupations, linked to the occupation archive for internal discovery. */
	private static function occupations_paragraph( int $post_id ): string {
		$terms = get_the_terms( $post_id, Catalogue::TAX_OCCUPATION );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}

		$links = array();
		$count = 0;
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$name = Role_Label::clean( $term->name );
			if ( '' === $name ) {
				continue;
			}
			++$count;
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				$links[] = esc_html( $name );
				continue;
			}
			$links[] = '<a href="' . esc_url( (string) $link ) . '">' . esc_html( $name ) . '</a>';
		}
		if ( 0 === $count ) {
			return '';
		}

		$shared = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && (int) $term->count > 1 ) {
				$shared[] = (int) $term->count;
			}
		}

		$out = 'Occupations on this record: ' . implode( ', ', $links ) . '.';
		if ( array() !== $shared ) {
			$out .= ' Browse the linked archives to compare this record with the other people filed under the same occupations.';
		}
		return $out;
	}

	/**
	 * Cause of death, in the plugin's own terms: an undisclosed cause is a
	 * normal, fully-scored state, not a gap to apologise for.
	 */
	private static function cause_paragraph( int $post_id ): string {
		$status = (string) get_post_meta( $post_id, 'obit_cause_status', true );
		if ( '' === $status ) {
			$status = Cause_Status::NOT_DISCLOSED;
		}
		$text = trim( (string) get_post_meta( $post_id, 'obit_cause_text', true ) );

		$out = '';
		if ( '' !== $text ) {
			$out .= 'The cause of death is recorded as ' . esc_html( $text ) . '.';
		} else {
			$out .= match ( $status ) {
				Cause_Status::CONFIRMED => 'The cause of death has been confirmed.',
				Cause_Status::CONTESTED => 'The cause of death is under review.',
				Cause_Status::PENDING   => 'The cause of death is awaiting official confirmation.',
				default                => 'The cause of death has not been publicly disclosed.',
			};
		}
		$out .= ' This does not affect scoring: an editor-approved death date is what counts, not the cause.';
		return $out;
	}

	/** What this confirmed death is worth, and in which season it scores. */
	private static function settled_scoring_paragraph( Partial_Date $death, ?int $age ): string {
		$out = 'Under the current ruleset a confirmed death scores '
			. '<code>max(1, 100 − age at death)</code> points';

		if ( null === $age ) {
			$out .= '. An exact birth date is not on file, so the figure is not stated here.';
		} else {
			$points = Ruleset::points_for_age( $age );
			$out   .= ', which for this record is <strong>' . esc_html( (string) $points ) . ' points</strong>';
			$out   .= ' (' . esc_html( (string) $age ) . ' completed years).';
		}

		$seasons = Ruleset::eligible_seasons_for_death( $death );
		if ( array() !== $seasons ) {
			$out .= ' The death falls in the ' . esc_html( implode( ' and ', $seasons ) )
				. ' season' . ( count( $seasons ) > 1 ? 's' : '' ) . ', and every team that picked this record in that season scores it.';
		}
		return $out;
	}

	/**
	 * Living records state the rule but never a frozen figure. Age and the
	 * potential points derived from it move every day; a stored number would
	 * be wrong the morning after it was written. The live figure is rendered
	 * by the scorecard on the page itself.
	 */
	private static function potential_paragraph( ?Partial_Date $birth ): string {
		if ( null === $birth || ! $birth->is_exact() ) {
			return 'A points figure cannot be stated for this record because an exact birth date is not on file.';
		}
		return 'Points for a living pick are <code>max(1, 100 − age at death)</code>, so a death today would score on today\'s age. The live figure is shown in the panel on this page and moves with the calendar.';
	}

	/** Attribution, and an honest statement of what this page is. */
	private static function sourcing_paragraph( int $post_id ): string {
		$qid    = trim( (string) get_post_meta( $post_id, 'obit_qid', true ) );
		$enwiki = trim( (string) get_post_meta( $post_id, 'obit_enwiki', true ) );

		$sources = array();
		if ( '' !== $qid ) {
			$sources[] = '<a href="' . esc_url( 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ) ) . '">Wikidata</a>';
		}
		if ( '' !== $enwiki ) {
			$sources[] = '<a href="' . esc_url( 'https://en.wikipedia.org/wiki/' . rawurlencode( $enwiki ) ) . '">Wikipedia</a>';
		}

		$out = 'Every fact on this page is editor-approved, and this profile reports approved facts only'
			. ( array() !== $sources ? ', drawn from ' . implode( ' and ', $sources ) : '' )
			. '. Dates keep the precision they were sourced at; a year is never widened to a day.';

		if ( '' !== (string) get_post_meta( $post_id, People_Sync::META_IMAGE_URL, true ) ) {
			$out .= ' The portrait comes from Wikimedia Commons.';
		}
		return $out;
	}

	/* ---------- field helpers ---------- */

	private static function date_field( int $post_id, string $meta_key ): ?Partial_Date {
		$raw = trim( (string) get_post_meta( $post_id, $meta_key, true ) );
		if ( '' === $raw ) {
			return null;
		}
		try {
			return Catalogue::partial_date_from_stored( $raw );
		} catch ( \InvalidArgumentException ) {
			// A malformed stored date is treated as absent: the page then says
			// less, which is the correct failure direction for a fact page.
			return null;
		}
	}

	/** "1937–2026", or "born 1937" while living. */
	private static function life_span( ?Partial_Date $birth, ?Partial_Date $death ): string {
		$by = self::year_of( $birth );
		$dy = self::year_of( $death );
		if ( $by && $dy ) {
			return $by . '–' . $dy;
		}
		if ( $by ) {
			return 'born ' . $by;
		}
		return $dy ? 'died ' . $dy : '';
	}

	private static function year_of( ?Partial_Date $date ): int {
		if ( null === $date ) {
			return 0;
		}
		$interpretations = $date->interpretations();
		return $interpretations ? (int) $interpretations[0]->format( 'Y' ) : 0;
	}

	/** Age at death, or null unless both dates are exact enough to state it. */
	private static function age_at_death( ?Partial_Date $birth, ?Partial_Date $death ): ?int {
		if ( null === $birth || null === $death || ! $birth->is_exact() || ! $death->is_exact() ) {
			return null;
		}
		try {
			return Age::completed_at( $birth, $death->interpretations()[0] );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}
}

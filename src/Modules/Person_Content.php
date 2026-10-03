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
 * has already approved. It is a formatter, not an author, and it describes the
 * person and nothing else:
 *
 *   - every sentence is assembled from stored values; nothing is inferred,
 *     summarised or invented about a person's life;
 *   - partial dates are never widened (the same rule the JSON-LD follows);
 *   - a date can only become a date if it was approved as one;
 *   - the wording is neutral, and pronoun-free: this is a death pool, and the
 *     page has to stay dignified for families reading it;
 *   - it never talks about the website, the game, scoring or the catalogue.
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

final class Person_Content {

	private function __construct() {}

	/* ---------- public API ---------- */

	/**
	 * The occupation labels a body should list, in preference order: the
	 * stored occupation list is authoritative (the descriptor and the rest of
	 * the page read it), and the taxonomy terms are only its browsable mirror.
	 * Pure, so the precedence is verifiable without WordPress.
	 *
	 * @param string[] $stored Labels recorded on the record, in claim order.
	 * @param string[] $terms  Names of the assigned taxonomy terms.
	 * @return string[] Cleaned, de-duplicated labels in order.
	 */
	public static function bio_occupation_labels( array $stored, array $terms ): array {
		$labels = self::clean_labels( $stored );
		if ( array() !== $labels ) {
			return $labels;
		}
		// No stored list (for example a record filed only through the term
		// backfill): fall back to the taxonomy so the paragraph is not lost.
		return self::clean_labels( $terms );
	}

	/**
	 * Cleaned, de-duplicated, non-empty labels in encounter order.
	 *
	 * @param string[] $labels
	 * @return string[]
	 */
	private static function clean_labels( array $labels ): array {
		$out  = array();
		$seen = array();
		foreach ( $labels as $label ) {
			$label = Role_Label::clean( (string) $label );
			if ( '' === $label || isset( $seen[ $label ] ) ) {
				continue;
			}
			$seen[ $label ] = true;
			$out[]         = $label;
		}
		return $out;
	}

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
		if ( '' !== $role ) {
			$lead .= $is_dead ? ' was ' : ' is ';
			$lead .= esc_html( Role_Label::with_article( $role ) ) . '.';
		} else {
			$lead .= '.';
		}

		if ( $is_dead ) {
			$death_sentence = 'A confirmed death is recorded as ' . esc_html( $death->label() );
			$death_sentence .= null !== $age
				? ', at the age of ' . esc_html( (string) $age ) . '.'
				: '.';
			$lead .= ' ' . $death_sentence;
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
		}

		if ( array() === $paragraphs ) {
			return '';
		}
		return '<p>' . implode( '</p><p>', $paragraphs ) . '</p>';
	}

	/* ---------- paragraph builders ---------- */

	/**
	 * Occupations, linked to the occupation archive for internal discovery.
	 *
	 * Labels come from self::bio_occupation_labels(): the stored list first,
	 * the taxonomy mirror second. Reading the terms alone dropped the
	 * paragraph for records enriched before the term backfill ran — the
	 * occupations were recorded and shown elsewhere on the page, yet the body
	 * stayed silent about them.
	 */
	private static function occupations_paragraph( int $post_id ): string {
		$terms = get_the_terms( $post_id, Catalogue::TAX_OCCUPATION );
		$term_names = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$term_names[] = (string) $term->name;
				}
			}
		}

		$labels = self::bio_occupation_labels( People_Sync::occupation_labels( $post_id ), $term_names );
		if ( array() === $labels ) {
			return '';
		}

		$links = array();
		foreach ( $labels as $name ) {
			$term = get_term_by( 'name', $name, Catalogue::TAX_OCCUPATION );
			$url  = $term instanceof \WP_Term ? get_term_link( $term ) : '';
			if ( is_string( $url ) && '' !== $url ) {
				$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
				continue;
			}
			$links[] = esc_html( $name );
		}

		return 'Occupations: ' . implode( ', ', $links ) . '.';
	}

	/**
	 * Cause of death, in the plugin's own terms: an undisclosed cause is a
	 * normal state, not a gap to apologise for.
	 */
	private static function cause_paragraph( int $post_id ): string {
		$status = (string) get_post_meta( $post_id, 'obit_cause_status', true );
		if ( '' === $status ) {
			$status = Cause_Status::NOT_DISCLOSED;
		}
		$text = trim( (string) get_post_meta( $post_id, 'obit_cause_text', true ) );

		if ( '' !== $text ) {
			return 'The cause of death is recorded as ' . esc_html( $text ) . '.';
		}
		return match ( $status ) {
			Cause_Status::CONFIRMED => 'The cause of death has been confirmed.',
			Cause_Status::CONTESTED => 'The cause of death is under review.',
			Cause_Status::PENDING   => 'The cause of death is awaiting official confirmation.',
			default                 => 'The cause of death has not been publicly disclosed.',
		};
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

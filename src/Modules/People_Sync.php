<?php
/**
 * People portrait sync.
 *
 * Pulls portrait candidates (Wikidata P18 → Commons special-file redirect)
 * and applies them as postmeta on person posts. Media licensing per the plan
 * (§6/§7): every displayed portrait carries credit via special:filepath link
 * back to the Commons file page, licence checked editorially at approval.
 * Portraits are optional at launch — cards fall back to monograms.
 *
 * Run: wp eval-file tests/sync-portraits.php
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Value\Role_Label;

final class People_Sync {

	/** Postmeta keys written. */
	public const META_IMAGE_URL = 'obit_image_url';
	public const META_IMAGE_CREDIT = 'obit_image_credit';
	public const META_OCCUPATIONS = 'obit_occupations';
	public const META_OCCUPATION_PRIMARY = 'obit_occupation_primary';
	public const META_OCCUPATION_QIDS = 'obit_occupation_qids';

	/** Public although the class is static-only: WP-CLI instantiates array callables when invoking commands. */
	public function __construct() {}

	/** Keep the derived sort/birth-year meta fresh on every person save. */
	public static function boot(): void {
		add_action( 'save_post_' . Catalogue::POST_TYPE, array( self::class, 'compute_sort_meta' ), 20, 1 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'obitleague backfill-sort-meta',
				static function (): void {
					$count = self::backfill_sort_meta();
					\WP_CLI::success( "Backfilled browse/sort meta for {$count} people." );
				}
			);
		}
	}

	/**
	 * Sync portraits and occupations for all published people (bounded).
	 * Selects posts missing either field, so re-runs fill gaps in both.
	 * Returns stats array.
	 */
	public static function sync_all( int $limit = 400 ): array {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} img ON img.post_id = p.ID AND img.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} occ ON occ.post_id = p.ID AND occ.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND ( img.meta_value IS NULL OR img.meta_value = ''
				      OR occ.meta_value IS NULL OR occ.meta_value = '' )
				 ORDER BY p.ID ASC LIMIT %d",
				self::META_IMAGE_URL,
				self::META_OCCUPATIONS,
				Catalogue::POST_TYPE,
				$limit
			)
		);
		if ( ! $post_ids ) {
			$stats = array( 'checked' => 0, 'updated' => 0 );
			update_option( 'obitleague_people_sync_last_run', array_merge( $stats, array( 'completed_at' => current_time( 'mysql', true ) ) ), false );
			return $stats;
		}

		$stats = array( 'checked' => 0, 'updated' => 0 );
		foreach ( array_chunk( array_map( 'intval', $post_ids ), 50 ) as $chunk ) {
			$stats['checked'] += count( $chunk );
			$updated          = self::sync_batch( $chunk );
			$stats['updated'] += $updated;
			self::polite_wait();
		}
		update_option( 'obitleague_people_sync_last_run', array_merge( $stats, array( 'completed_at' => current_time( 'mysql', true ) ) ), false );
		return $stats;
	}

	/** Sync one batch (up to 50 ids) via one wbgetentities call. */
	public static function sync_batch( array $post_ids ): int {
		$qids = array();
		foreach ( $post_ids as $pid ) {
			$qid = (string) get_post_meta( (int) $pid, 'obit_qid', true );
			if ( '' !== $qid ) {
				$qids[ (int) $pid ] = $qid;
			}
		}
		if ( ! $qids ) {
			return 0;
		}

		$entities = self::fetch_entities( array_values( $qids ) );
		if ( ! $entities ) {
			return 0;
		}

		// Resolve every occupation QID in this batch up front (batched label lookups).
		$occ_qids = array();
		foreach ( $entities as $entity ) {
			foreach ( self::occupation_qids( $entity ) as $oqid ) {
				$occ_qids[ $oqid ] = true;
			}
		}
		self::label_prefetch( array_keys( $occ_qids ) );

		$updated = 0;
		foreach ( $qids as $pid => $qid ) {
			$entity = $entities[ $qid ] ?? null;

			// Occupations (P106): comma-separated English labels, preferred rank marks the primary.
			$occ = self::occupations_for( $entity );
			if ( array() !== $occ ) {
				update_post_meta( (int) $pid, self::META_OCCUPATIONS, implode( ', ', $occ['labels'] ) );
				update_post_meta( (int) $pid, self::META_OCCUPATION_PRIMARY, $occ['primary'] );
				update_post_meta( (int) $pid, self::META_OCCUPATION_QIDS, wp_json_encode( $occ['qids'] ) );
				// Mirror the labels into the obit_occupation taxonomy so groups of
				// people can be browsed, queried and output together.
				self::sync_occupation_terms( (int) $pid, $occ['labels'] );
				++$updated;
			}

			$url = self::image_url_for( $entity );
			if ( '' === $url ) {
				continue;
			}
			update_post_meta( (int) $pid, self::META_IMAGE_URL, $url );
			update_post_meta( (int) $pid, self::META_IMAGE_CREDIT, 'Wikimedia Commons via Wikidata (P18)' );
			++$updated;
		}
		return $updated;
	}

	/** Occupation QIDs claimed by one entity (P106, deprecated ranks skipped). */
	private static function occupation_qids( ?array $entity ): array {
		if ( ! $entity ) {
			return array();
		}
		$claims = is_array( $entity['claims'] ?? null ) ? $entity['claims'] : array();
		$out    = array();
		foreach ( (array) ( $claims['P106'] ?? array() ) as $claim ) {
			if ( ! is_array( $claim ) || 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			$qid   = is_array( $value ) ? (string) ( $value['id'] ?? '' ) : '';
			if ( '' !== $qid ) {
				$out[ $qid ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Occupations from one entity: preferred-rank claim becomes the primary
	 * designator (falling back to the first listed occupation when Wikidata
	 * marks none preferred). Returns labels, primary label and QID map.
	 *
	 * @return array<string,mixed>
	 */
	public static function occupations_for( ?array $entity ): array {
		if ( ! $entity ) {
			return array();
		}
		$claims = is_array( $entity['claims'] ?? null ) ? $entity['claims'] : array();
		if ( ! isset( $claims['P106'] ) || ! is_array( $claims['P106'] ) ) {
			return array();
		}

		$labels  = array();
		$qids    = array();
		$primary = '';
		$first   = '';
		foreach ( $claims['P106'] as $claim ) {
			if ( ! is_array( $claim ) || 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			$qid   = is_array( $value ) ? (string) ( $value['id'] ?? '' ) : '';
			if ( '' === $qid || isset( $qids[ $qid ] ) ) {
				continue;
			}
			$label = self::occupation_label( $qid );
			if ( '' === $label ) {
				continue;
			}
			$qids[ $qid ] = $label;
			$labels[]     = $label;
			if ( '' === $first ) {
				$first = $label;
			}
			if ( '' === $primary && 'preferred' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				$primary = $label;
			}
		}
		if ( array() === $labels ) {
			return array();
		}
		return array(
			'labels'  => $labels,
			'primary' => '' !== $primary ? $primary : $first,
			'qids'    => $qids,
		);
	}

	/** Label lookup cache (occupation QID → English label). */
	private static array $occ_labels = array();

	/** Prefetch labels for many occupation QIDs (50 ids per wbgetentities call). */
	private static function label_prefetch( array $qids ): void {
		$todo = array();
		foreach ( $qids as $qid ) {
			$qid = (string) $qid;
			if ( '' !== $qid && ! isset( self::$occ_labels[ $qid ] ) ) {
				$todo[] = $qid;
			}
		}
		foreach ( array_chunk( $todo, 50 ) as $i => $chunk ) {
			if ( $i > 0 ) {
				self::polite_wait();
			}
			$url = 'https://www.wikidata.org/w/api.php?' . http_build_query(
				array(
					'action'        => 'wbgetentities',
					'ids'           => implode( '|', $chunk ),
					'props'         => 'labels',
					'languages'     => 'en|mul',
					'format'        => 'json',
					'formatversion' => '2',
				)
			);
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 20,
					'headers' => array( 'User-Agent' => 'Obitleague/0.7 (local development; contact: local)' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			foreach ( $chunk as $qid ) {
				$label = '';
				foreach ( array( 'en', 'mul' ) as $lang ) {
					$candidate = $body['entities'][ $qid ]['labels'][ $lang ]['value'] ?? '';
					if ( is_string( $candidate ) && '' !== $candidate ) {
						$label = $candidate;
						break;
					}
				}
				self::$occ_labels[ $qid ] = $label;
			}
		}
	}

	/** English label for an occupation QID; unresolved QIDs are skipped. */
	private static function occupation_label( string $qid ): string {
		if ( isset( self::$occ_labels[ $qid ] ) ) {
			return self::$occ_labels[ $qid ];
		}
		self::$occ_labels[ $qid ] = '';
		$url = 'https://www.wikidata.org/w/api.php?' . http_build_query(
			array(
				'action'        => 'wbgetentities',
				'ids'           => $qid,
				'props'         => 'labels',
				'languages'     => 'en|mul',
				'format'        => 'json',
				'formatversion' => '2',
			)
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'User-Agent' => 'Obitleague/0.7 (local development; contact: local)' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$label = '';
		foreach ( array( 'en', 'mul' ) as $lang ) {
			$candidate = $body['entities'][ $qid ]['labels'][ $lang ]['value'] ?? '';
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$label = $candidate;
				break;
			}
		}
		self::$occ_labels[ $qid ] = $label;
		return $label;
	}

	/** Occupation labels for a person post, in Wikidata claim order. */
	public static function occupation_labels( int $post_id ): array {
		$raw = (string) get_post_meta( $post_id, self::META_OCCUPATIONS, true );
		if ( '' === $raw ) {
			return array();
		}
		$out = array();
		foreach ( explode( ',', $raw ) as $label ) {
			$label = trim( $label );
			if ( '' !== $label ) {
				$out[] = $label;
			}
		}
		return $out;
	}

	/** Primary occupation label for a person post ('' when none recorded). */
	public static function primary_occupation( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_OCCUPATION_PRIMARY, true );
	}

	/**
	 * Escaped links to the occupation archive for each assigned term.
	 * Empty array when the person has no occupation terms.
	 *
	 * @return string[] Pre-escaped <a> elements.
	 */
	public static function occupation_term_links( int $post_id ): array {
		$terms = get_the_terms( $post_id, Catalogue::TAX_OCCUPATION );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$out[] = '<a class="ob-occ-tag" href="' . esc_url( (string) $link ) . '">' . esc_html( $term->name ) . '</a> ';
		}
		return $out;
	}

	/**
	 * Mirror occupation labels into the obit_occupation taxonomy, replacing
	 * any previously assigned terms so the taxonomy stays authoritative.
	 */
	public static function sync_occupation_terms( int $post_id, array $labels ): void {
		$term_ids = array();
		foreach ( $labels as $label ) {
			// Terms are publicly browsable and indexable, so a label carrying a
			// cause of death must never become one.
			$label = Role_Label::clean( (string) $label );
			if ( '' === $label || mb_strlen( $label ) > 190 ) {
				continue;
			}
			$term = get_term_by( 'name', $label, Catalogue::TAX_OCCUPATION );
			if ( ! $term instanceof \WP_Term ) {
				$result = wp_insert_term( $label, Catalogue::TAX_OCCUPATION );
				if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
					$existing = get_term_by( 'name', $label, Catalogue::TAX_OCCUPATION );
					$term_id  = $existing ? (int) $existing->term_id : 0;
				} else {
					$term_id = is_wp_error( $result ) ? 0 : (int) $result['term_id'];
				}
			} else {
				$term_id = (int) $term->term_id;
			}
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}
		wp_set_object_terms( $post_id, $term_ids, Catalogue::TAX_OCCUPATION, false );
	}

	/**
	 * Backfill the occupation taxonomy from the stored Wikidata occupation
	 * postmeta for every published person. Additive and idempotent; run once
	 * per schema upgrade.
	 *
	 * @return int Number of people updated.
	 */
	public static function backfill_occupation_terms(): int {
		global $wpdb;
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value <> ''
				 WHERE p.post_type = %s AND p.post_status IN ( 'publish', 'draft' )",
				self::META_OCCUPATIONS,
				Catalogue::POST_TYPE
			)
		);
		$count = 0;
		foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
			$labels = self::occupation_labels( $post_id );
			if ( array() !== $labels ) {
				self::sync_occupation_terms( $post_id, $labels );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Compute the browse/sort meta for one person: a lowercase surrogate of
	 * the display title (leading article stripped) and the numeric birth
	 * year. Purely derived from stored data — no network.
	 */
	public static function compute_sort_meta( int $post_id ): void {
		$title   = (string) get_the_title( $post_id );
		$sort    = mb_strtolower( $title );
		$sort    = preg_replace( '/^(the|a|an)\s+/u', '', $sort ) ?? $sort;
		update_post_meta( $post_id, 'obit_sort_name', $sort );

		$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		if ( preg_match( '/^(\d{4})/', $birth_raw, $m ) ) {
			update_post_meta( $post_id, 'obit_birth_year_num', (int) $m[1] );
		} else {
			delete_post_meta( $post_id, 'obit_birth_year_num' );
		}
	}

	/**
	 * Backfill the browse/sort meta for every person post. Idempotent and
	 * local-only; run after imports or via the maintenance command.
	 */
	public static function backfill_sort_meta(): int {
		global $wpdb;
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ( 'publish', 'draft' )",
				Catalogue::POST_TYPE
			)
		);
		$count = 0;
		foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
			self::compute_sort_meta( $post_id );
			++$count;
		}
		return $count;
	}

	/** Extract a usable image URL from one entity payload. */
	private static function image_url_for( ?array $entity ): string {
		if ( ! $entity ) {
			return '';
		}
		$claims = $entity['claims'] ?? array();
		$best   = null;
		foreach ( (array) ( $claims['P18'] ?? [] ) as $claim ) {
			$rank = (string) ( $claim['rank'] ?? 'normal' );
			if ( 'deprecated' === $rank ) {
				continue;
			}
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			if ( is_string( $value ) && '' !== $value && 'preferred' === $rank ) {
				return self::commons_url( $value );
			}
			if ( is_string( $value ) && '' !== $value && null === $best ) {
				$best = $value;
			}
		}
		return null !== $best ? self::commons_url( $best ) : '';
	}

	/** Commons special:filepath redirect with a display width bound. */
	public static function commons_url( string $file_title ): string {
		$file_title = str_replace( ' ', '_', rawurlencode( $file_title ) );
		return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . $file_title . '?width=320';
	}

	/** wbgetentities for image claims, 50 ids per request. */
	public static function fetch_entities( array $qids ): array {
		$out = array();
		for ( $i = 0, $n = count( $qids ); $i < $n; $i += 50 ) {
			$batch = array_slice( $qids, $i, 50 );
			$url   = 'https://www.wikidata.org/w/api.php?' . http_build_query(
				array(
					'action'          => 'wbgetentities',
					'ids'             => implode( '|', $batch ),
					'props'           => 'claims',
					'format'          => 'json',
					'formatversion'   => '2',
					'language'        => 'en',
				)
			);
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 20,
					'headers' => array( 'User-Agent' => 'Obitleague/0.3 (local development; contact: local)' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			foreach ( (array) ( $body['entities'] ?? [] ) as $qid => $entity ) {
				$out[ (string) $qid ] = is_array( $entity ) ? $entity : array();
			}
		}
		return $out;
	}

	/** Stay under Wikidata rate guidance between batches. */
	private static function polite_wait(): void {
		usleep( 1200000 ); // 1.2 s
	}
}

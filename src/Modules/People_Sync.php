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

final class People_Sync {

	/** Postmeta keys written. */
	public const META_IMAGE_URL = 'obit_image_url';
	public const META_IMAGE_CREDIT = 'obit_image_credit';

	private function __construct() {}

	/**
	 * Sync portraits for all published people (bounded). Returns stats array.
	 */
	public static function sync_all( int $limit = 400 ): array {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND ( m.meta_value IS NULL OR m.meta_value = '' )
				 ORDER BY p.ID ASC LIMIT %d",
				self::META_IMAGE_URL,
				Catalogue::POST_TYPE,
				$limit
			)
		);
		if ( ! $post_ids ) {
			return array( 'checked' => 0, 'updated' => 0 );
		}

		$stats = array( 'checked' => 0, 'updated' => 0 );
		foreach ( array_chunk( array_map( 'intval', $post_ids ), 50 ) as $chunk ) {
			$stats['checked'] += count( $chunk );
			$updated          = self::sync_batch( $chunk );
			$stats['updated'] += $updated;
			self::polite_wait();
		}
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

		$updated = 0;
		foreach ( $qids as $pid => $qid ) {
			$url = self::image_url_for( $entities[ $qid ] ?? null );
			if ( '' === $url ) {
				continue;
			}
			update_post_meta( (int) $pid, self::META_IMAGE_URL, $url );
			update_post_meta( (int) $pid, self::META_IMAGE_CREDIT, 'Wikimedia Commons via Wikidata (P18)' );
			++$updated;
		}
		return $updated;
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
	private static function fetch_entities( array $qids ): array {
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

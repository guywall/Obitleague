<?php
/**
 * People portrait + occupation sync.
 *
 * Every outbound Wikidata call goes through the global Wiki_Request_Queue:
 * people missing a portrait or occupations are enqueued as enrich_person
 * requests, and a queue handler applies the fetched entity data to the
 * post. Nothing here fires HTTP directly, so shared Wikimedia rate-limit
 * pauses and per-source pacing are honoured automatically, and gaps are
 * retried until they fill rather than silently dropped.
 *
 * Media licensing per the plan (§6/§7): every displayed portrait carries
 * credit via special:filepath link back to the Commons file page, licence
 * checked editorially at approval. Portraits are optional — cards fall
 * back to monograms.
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
		add_action( 'obitleague_profile_refresh_tick', array( self::class, 'enqueue_missing' ) );
		Wiki_Request_Queue::register_handler(
			'enrich_person',
			static function ( array $payload ) {
				return self::handle_enrich( (int) ( $payload['post_id'] ?? 0 ) );
			}
		);
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command(
				'obitleague backfill-sort-meta',
				static function (): void {
					$count = self::backfill_sort_meta();
					\WP_CLI::success( "Backfilled browse/sort meta for {$count} people." );
				}
			);
			\WP_CLI::add_command(
				'obitleague sync-people',
				static function ( array $args, array $assoc_args ): void {
					$limit = (int) ( $assoc_args['limit'] ?? 400 );
					$stats = self::enqueue_missing( $limit );
					\WP_CLI::success( "Enqueued {$stats['enqueued']} enrichment request(s); {$stats['missing']} people missing data." );
				}
			);
		}
	}

	/**
	 * Enqueue enrichment requests for every published person still missing a
	 * portrait or occupations. Idempotent per person: while a request for
	 * one is pending — or a previous one already succeeded — the dedupe key
	 * keeps it to a single row. Returns stats.
	 *
	 * @return array{checked:int, missing:int, enqueued:int}
	 */
	public static function enqueue_missing( int $limit = 400 ): array {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} img ON img.post_id = p.ID AND img.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} occ ON occ.post_id = p.ID AND occ.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} rol ON rol.post_id = p.ID AND rol.meta_key = 'obit_role'
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND ( img.meta_value IS NULL OR img.meta_value = ''
				      OR occ.meta_value IS NULL OR occ.meta_value = ''
				      OR rol.meta_value IS NULL OR rol.meta_value = '' )
				 ORDER BY p.ID ASC LIMIT %d",
				self::META_IMAGE_URL,
				self::META_OCCUPATIONS,
				Catalogue::POST_TYPE,
				$limit
			)
		);
		$stats = array( 'checked' => 0, 'missing' => 0, 'enqueued' => 0 );
		foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
			++$stats['checked'];
			$qid = (string) get_post_meta( $post_id, 'obit_qid', true );
			if ( '' === $qid ) {
				continue; // No Wikidata id: nothing to fetch.
			}
			++$stats['missing'];
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}obitleague_wiki_queue
					 WHERE request_kind = 'enrich_person' AND dedupe_key = %s AND status IN ( 'pending', 'processing' ) LIMIT 1",
					self::enrich_dedupe_key( $post_id )
				)
			);
			if ( $existing > 0 ) {
				continue;
			}
			Wiki_Request_Queue::enqueue(
				'enrich_person',
				array( 'post_id' => $post_id ),
				self::enrich_dedupe_key( $post_id ),
				'wikidata'
			);
			++$stats['enqueued'];
		}
		update_option( 'obitleague_people_sync_last_run', array_merge( $stats, array( 'completed_at' => current_time( 'mysql', true ) ) ), false );
		return $stats;
	}

	/** Dedupe key for one person's enrichment request. */
	private static function enrich_dedupe_key( int $post_id ): string {
		return 'person-' . $post_id;
	}

	/**
	 * Queue handler: fetch one entity from Wikidata and apply it to the
	 * person post. Returns a WP_Error when the queue should retry (with the
	 * shared rate-limit pause honoured) rather than give up.
	 *
	 * @return array{ok:bool, updated:bool}|\WP_Error
	 */
	public static function handle_enrich( int $post_id ) {
		if ( $post_id < 1 || Catalogue::POST_TYPE !== (string) get_post_type( $post_id ) ) {
			return array( 'ok' => true, 'updated' => false ); // Post gone: nothing to do.
		}
		$qid = (string) get_post_meta( $post_id, 'obit_qid', true );
		if ( '' === $qid ) {
			return array( 'ok' => true, 'updated' => false );
		}

		$entities = self::fetch_entities( array( $qid ) );
		if ( array() === $entities || ! isset( $entities[ $qid ] ) ) {
			return new \WP_Error( 'obitleague_enrich_http', 'Wikidata returned no usable entity payload; will retry.' );
		}
		$updated = self::apply_entity( $post_id, $entities[ $qid ] );
		return array( 'ok' => true, 'updated' => $updated > 0 );
	}

	/**
	 * Apply one Wikidata entity to a person post: occupations (P106), the
	 * enwiki sitelink, a day-precision death-date refinement for provisional
	 * records, and the portrait (P18). Returns the number of meta groups
	 * written.
	 */
	public static function apply_entity( int $post_id, array $entity ): int {
		$updated = 0;

		// Occupations (P106): comma-separated English labels, preferred rank marks the primary.
		$occ_qids = self::occupation_qids( $entity );
		self::label_prefetch( $occ_qids );
		$occ = self::occupations_for( $entity );
		if ( array() !== $occ ) {
			update_post_meta( $post_id, self::META_OCCUPATIONS, implode( ', ', $occ['labels'] ) );
			update_post_meta( $post_id, self::META_OCCUPATION_PRIMARY, $occ['primary'] );
			update_post_meta( $post_id, self::META_OCCUPATION_QIDS, wp_json_encode( $occ['qids'] ) );
			// Mirror the labels into the obit_occupation taxonomy so groups of
			// people can be browsed, queried and output together.
			self::sync_occupation_terms( $post_id, $occ['labels'] );

			// The public role falls back to the primary Wikidata occupation.
			$primary = (string) ( $occ['primary'] ?? '' );
			if ( '' !== $primary && '' === (string) get_post_meta( $post_id, 'obit_role', true ) ) {
				update_post_meta( $post_id, 'obit_role', $primary );
			}
			// The body text is composed from these fields, so it must be
			// recomposed now that they exist — enrichment routinely lands
			// after the record was first published with an empty descriptor.
			Person_Content::regenerate( $post_id );
			++$updated;
		}

		// The English Wikipedia article title (sitelink) fills the
		// citation/link field the wire leaves empty.
		$enwiki = self::enwiki_from_entity( $entity );
		if ( '' !== $enwiki && '' === (string) get_post_meta( $post_id, 'obit_enwiki', true ) ) {
			update_post_meta( $post_id, 'obit_enwiki', $enwiki );
		}

		// A day-precision date of death (P570) refines the stored date of
		// a provisional (wire-published, unconfirmed) record only. Living
		// records gain deaths through the wire and review, never here, and
		// confirmed records keep the date their approval decided.
		$stored_death = (string) get_post_meta( $post_id, 'obit_death_date', true );
		if ( '' !== $stored_death && Death_Wire::is_provisional( $post_id ) ) {
			$wd_death = self::death_date_from_entity( $entity );
			if ( '' !== $wd_death && $wd_death !== $stored_death ) {
				update_post_meta( $post_id, 'obit_death_date', $wd_death );
				update_post_meta( $post_id, 'obit_death_precision', 'exact' );
				Person_Content::regenerate( $post_id );
			}
		}

		$url = self::image_url_for( $entity );
		if ( '' !== $url ) {
			update_post_meta( $post_id, self::META_IMAGE_URL, $url );
			update_post_meta( $post_id, self::META_IMAGE_CREDIT, 'Wikimedia Commons via Wikidata (P18)' );
			++$updated;
		}
		return $updated;
	}

	/**
	 * Sync portraits and occupations for all published people (bounded).
	 * Kept as a thin wrapper for existing wp-cli/eval-file callers: it now
	 * enqueues through the queue rather than fetching directly.
	 * Returns stats array.
	 *
	 * @return array{checked:int, missing:int, enqueued:int}
	 */
	public static function sync_all( int $limit = 400 ): array {
		return self::enqueue_missing( $limit );
	}	/**
	 * Sync one batch (up to 50 ids). Legacy direct-fetch path, retained for
	 * wp-cli/eval-file callers that want a synchronous run; the scheduled
	 * path goes through the queue instead.
	 *
	 * @return int Number of meta groups written.
	 */
	public static function sync_batch( array $post_ids ): int {
		$qids = array();
		foreach ( $post_ids as $pid ) {
			$pid  = (int) $pid;
			$qid = (string) get_post_meta( $pid, 'obit_qid', true );
			if ( '' !== $qid ) {
				$qids[ $pid ] = $qid;
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
			$entity = $entities[ $qid ] ?? null;
			if ( $entity ) {
				$updated += self::apply_entity( $pid, $entity );
			}
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
				'props'           => 'claims|sitelinks',
				'sitefilter'      => 'enwiki',
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
				// A rate-limit answer parks the wikidata source so every
				// queued request — this one included — waits until the
				// Retry-After moment instead of hammering on.
				if ( ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
					Wiki_Request_Queue::defer_source(
						'wikidata',
						(string) wp_remote_retrieve_header( $response, 'retry-after' )
					);
				}
				continue;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			foreach ( (array) ( $body['entities'] ?? [] ) as $qid => $entity ) {
				$out[ (string) $qid ] = is_array( $entity ) ? $entity : array();
			}
		}
		return $out;
	}

	/** English Wikipedia sitelink title for one entity (empty when none). */
	private static function enwiki_from_entity( ?array $entity ): string {
		$title = (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' );
		return '' !== $title ? str_replace( ' ', '_', $title ) : '';
	}

	/**
	 * Day-precision death date (P570) as Y-m-d, preferring preferred ranks;
	 * empty when the claim is absent, deprecated, or coarser than a day.
	 */
	private static function death_date_from_entity( ?array $entity ): string {
		$claims = (array) ( $entity['claims']['P570'] ?? array() );
		$candidates = array();
		foreach ( $claims as $claim ) {
			if ( 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			$candidates[] = $claim;
		}
		$preferred = array_values( array_filter( $candidates, static fn ( $c ): bool => 'preferred' === (string) ( $c['rank'] ?? '' ) ) );
		if ( $preferred ) {
			$candidates = $preferred;
		}
		foreach ( $candidates as $claim ) {
			$value = (array) ( $claim['mainsnak']['datavalue']['value'] ?? array() );
			if ( 11 !== (int) ( $value['precision'] ?? 0 ) ) {
				continue;
			}
			$time = (string) ( $value['time'] ?? '' );
			if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})T/', $time, $m ) ) {
				return $m[1] . '-' . $m[2] . '-' . $m[3];
			}
		}
		return '';
	}

	/** Stay under Wikidata rate guidance between batches. */
	private static function polite_wait(): void {
		usleep( 1200000 ); // 1.2 s
	}
}

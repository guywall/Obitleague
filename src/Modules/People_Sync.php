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

use Obitleague\Domain\Value\Cause_Status;
use Obitleague\Domain\Value\Role_Label;

final class People_Sync {

	/** Postmeta keys written. */
	public const META_IMAGE_URL = 'obit_image_url';
	public const META_IMAGE_CREDIT = 'obit_image_credit';
	public const META_OCCUPATIONS = 'obit_occupations';
	public const META_OCCUPATION_PRIMARY = 'obit_occupation_primary';
	public const META_OCCUPATION_QIDS = 'obit_occupation_qids';

	/**
	 * Where a record's cause of death came from. '' means it has not been
	 * resolved yet — an absent P509 and an unreadable one both leave a
	 * marker, so the sweep does not retry the same gap forever.
	 */
	public const META_CAUSE_SOURCE = 'obit_cause_source';
	public const CAUSE_SOURCE_WIKIDATA = 'wikidata-P509';
	public const CAUSE_SOURCE_EDITOR = 'editor';

	/**
	 * When a record was last read from Wikidata, UTC 'Y-m-d H:i:s'. Stamped
	 * on every successful fetch, whether or not it filled anything: a field
	 * the source simply does not hold is then a checked gap, not an open one,
	 * so the sweep stops re-fetching it every single day.
	 */
	public const META_ENRICHED_AT = 'obit_enriched_at';

	/** How long a settled record waits before the sweep re-checks it. */
	public const SETTLE_REFRESH_SECONDS = 2592000; // 30 days.

	/** Records the daily sweep enqueues per run. */
	public const SWEEP_LIMIT = 400;

	/** Public although the class is static-only: WP-CLI instantiates array callables when invoking commands. */
	public function __construct() {}

	/** Keep the derived sort/birth-year meta fresh on every person save. */
	public static function boot(): void {
		add_action( 'save_post_' . Catalogue::POST_TYPE, array( self::class, 'compute_sort_meta' ), 20, 1 );
		// Zero accepted args on purpose. With the default of one, WordPress
		// calls the callback with a single '' ($args[0] ?? ''), which a typed
		// $limit rejects with a TypeError and takes the whole daily refresh
		// down before it can enqueue or stamp anything.
		add_action( 'obitleague_profile_refresh_tick', array( self::class, 'enqueue_missing' ), 10, 0 );
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
					$note  = "Enqueued {$stats['enqueued']} enrichment request(s); {$stats['missing']} people missing data.";
					if ( $stats['failed'] > 0 ) {
						\WP_CLI::warning( $note . " {$stats['failed']} request(s) could not be stored in the Wikimedia request queue." );
						return;
					}
					\WP_CLI::success( $note );
				}
			);
			\WP_CLI::add_command(
				'obitleague backfill-people',
				array( self::class, 'cli_backfill_people' )
			);
		}
	}

	/**
	 * Enqueue enrichment requests for every published person still missing a
	 * portrait, occupations, a role, or a resolved cause of death. Idempotent
	 * per person: while a request for one is pending — or a previous one
	 * already succeeded — the dedupe key keeps it to a single row. Returns
	 * stats, including `failed` for requests the queue refused to store, so a
	 * broken insert is visible rather than counted as success.
	 *
	 * @return array{checked:int, missing:int, enqueued:int, failed:int}
	 */
	public static function enqueue_missing( $limit = self::SWEEP_LIMIT ): array {
		global $wpdb;

		$limit               = self::sweep_limit( $limit );
		list( $from, $args ) = self::missing_enrichment_query();
		$args[]              = self::settle_cutoff( current_time( 'mysql', true ) );
		$args[]              = max( 1, $limit );
		$post_ids            = $wpdb->get_col(
			$wpdb->prepare(
				// Never-checked records come first, newest first, so a page a
				// visitor has just landed on is enriched before the standing
				// backlog. A settled record is only retried once its marker is
				// older than the refresh window, and then ranks below the rest.
				"SELECT p.ID {$from}
				   AND ( enr.meta_value IS NULL OR enr.meta_value = '' OR enr.meta_value < %s )
				 ORDER BY ( enr.meta_value IS NULL OR enr.meta_value = '' ) DESC, p.ID DESC
				 LIMIT %d",
				...$args
			)
		);
		$stats = array( 'checked' => 0, 'missing' => 0, 'enqueued' => 0, 'failed' => 0 );
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
			// enqueue() returns 0 when the row could not be stored: the gap is
			// then still open and must be reported, never counted as a success.
			$row_id = Wiki_Request_Queue::enqueue(
				'enrich_person',
				array( 'post_id' => $post_id ),
				self::enrich_dedupe_key( $post_id ),
				'wikidata'
			);
			if ( $row_id > 0 ) {
				++$stats['enqueued'];
			} else {
				++$stats['failed'];
			}
		}
		update_option( 'obitleague_people_sync_last_run', array_merge( $stats, array( 'completed_at' => current_time( 'mysql', true ) ) ), false );
		return $stats;
	}

	/**
	 * The published people still missing a portrait, occupations, a role or a
	 * resolved cause of death, as a reusable SQL fragment. Shared by the sweep
	 * and the admin backlog view so the two can never disagree about who needs
	 * work.
	 *
	 * @return array{0:string,1:array<int,string>} FROM clause with placeholders,
	 *         then the values those placeholders expect, in order.
	 */
	private static function missing_enrichment_query(): array {
		global $wpdb;
		return array(
			"FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} img ON img.post_id = p.ID AND img.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} occ ON occ.post_id = p.ID AND occ.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} rol ON rol.post_id = p.ID AND rol.meta_key = 'obit_role'
			 LEFT JOIN {$wpdb->postmeta} cause ON cause.post_id = p.ID AND cause.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} enr ON enr.post_id = p.ID AND enr.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND ( img.meta_value IS NULL OR img.meta_value = ''
			      OR occ.meta_value IS NULL OR occ.meta_value = ''
			      OR rol.meta_value IS NULL OR rol.meta_value = ''
			      OR cause.meta_value IS NULL OR cause.meta_value = '' )",
			array(
				self::META_IMAGE_URL,
				self::META_OCCUPATIONS,
				self::META_CAUSE_SOURCE,
				self::META_ENRICHED_AT,
				Catalogue::POST_TYPE,
			),
		);
	}

	/**
	 * The sweep's per-run record cap, normalising whatever a caller hands in.
	 * A hook callback registered with the default one accepted argument is
	 * called with a single '', so the cap must accept that shape rather than
	 * type-hint int and fatal the scheduled run. Pure, so the fallback is
	 * verifiable without WordPress.
	 *
	 * @param mixed $limit A requested cap; anything unusable falls back.
	 */
	public static function sweep_limit( $limit ): int {
		$n = is_numeric( $limit ) ? (int) $limit : 0;
		return $n > 0 ? $n : self::SWEEP_LIMIT;
	}

	/**
	 * The UTC 'Y-m-d H:i:s' a settle marker must predate for the record to be
	 * swept again: now minus the refresh window. Pure, so the pacing rule is
	 * verifiable without WordPress.
	 */
	public static function settle_cutoff( string $now, int $cooldown_seconds = self::SETTLE_REFRESH_SECONDS ): string {
		$moment = strtotime( trim( $now ) . ' UTC' );
		if ( false === $moment || $moment <= 0 ) {
			$moment = time();
		}
		return gmdate( 'Y-m-d H:i:s', $moment - max( 0, $cooldown_seconds ) );
	}

	/**
	 * Human labels for whatever a record is still missing, given which fields
	 * it already has. Pure: the sweep and the admin view key off the same four
	 * gaps, so neither can drift from the other.
	 *
	 * @param array{image?:bool,occupations?:bool,role?:bool,cause?:bool} $present
	 * @return string[]
	 */
	public static function missing_field_labels( array $present ): array {
		$fields  = array(
			'image'       => 'portrait',
			'occupations' => 'occupations',
			'role'        => 'role',
			'cause'       => 'cause of death',
		);
		$missing = array();
		foreach ( $fields as $key => $label ) {
			if ( empty( $present[ $key ] ) ) {
				$missing[] = $label;
			}
		}
		return $missing;
	}

	/**
	 * Split a total count into the part of it that is deceased. Pure, so the
	 * subtraction that derives the living figure is testable and can never
	 * report a negative.
	 *
	 * @return array{all:int,living:int,deceased:int}
	 */
	public static function gap_counts( int $all, int $deceased ): array {
		$deceased = max( 0, min( $all, $deceased ) );
		return array( 'all' => $all, 'living' => $all - $deceased, 'deceased' => $deceased );
	}

	/**
	 * How many published people still lack occupations, and how many still
	 * lack a portrait, split living/deceased. Drives the Data-sources gap
	 * summary so the outstanding work is a number rather than an inference
	 * from a coverage ratio.
	 *
	 * @return array{occupations:array{all:int,living:int,deceased:int},portraits:array{all:int,living:int,deceased:int}}
	 */
	public static function coverage_gaps(): array {
		global $wpdb;
		$deceased = "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = 'obit_death_date' AND d.meta_value <> '' )";
		$row      = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   SUM( CASE WHEN occ.meta_value IS NULL OR occ.meta_value = '' THEN 1 ELSE 0 END ) AS occ_missing,
				   SUM( CASE WHEN ( occ.meta_value IS NULL OR occ.meta_value = '' ) AND {$deceased} THEN 1 ELSE 0 END ) AS occ_missing_deceased,
				   SUM( CASE WHEN img.meta_value IS NULL OR img.meta_value = '' THEN 1 ELSE 0 END ) AS img_missing,
				   SUM( CASE WHEN ( img.meta_value IS NULL OR img.meta_value = '' ) AND {$deceased} THEN 1 ELSE 0 END ) AS img_missing_deceased
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} occ ON occ.post_id = p.ID AND occ.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} img ON img.post_id = p.ID AND img.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish'",
				self::META_OCCUPATIONS,
				self::META_IMAGE_URL,
				Catalogue::POST_TYPE
			)
		);
		return array(
			'occupations' => self::gap_counts( (int) ( $row->occ_missing ?? 0 ), (int) ( $row->occ_missing_deceased ?? 0 ) ),
			'portraits'   => self::gap_counts( (int) ( $row->img_missing ?? 0 ), (int) ( $row->img_missing_deceased ?? 0 ) ),
		);
	}

	/**
	 * How many published people still need enrichment, and how many of them
	 * are sitting in the queue right now. Drives the Data-sources backlog line
	 * so an empty queue never reads as "nothing to do".
	 *
	 * @return array{missing:int,queued:int}
	 */
	public static function enrichment_backlog(): array {
		global $wpdb;
		list( $from, $args ) = self::missing_enrichment_query();
		$missing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", ...$args ) );
		$queued  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) {$from}
				   AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}obitleague_wiki_queue q
				                WHERE q.request_kind = 'enrich_person'
				                  AND q.dedupe_key = CONCAT( 'person-', p.ID )
				                  AND q.status IN ( 'pending', 'processing' ) )",
				...$args
			)
		);
		return array( 'missing' => $missing, 'queued' => $queued );
	}

	/**
	 * A page of the enrichment backlog, newest first — the same order the sweep
	 * works in — with the gaps each record still has and whether it is already
	 * queued.
	 *
	 * @return array<int,object>
	 */
	public static function enrichment_backlog_sample( int $limit = 50 ): array {
		global $wpdb;
		list( $from, $args ) = self::missing_enrichment_query();
		$args[] = max( 1, $limit );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS post_id, p.post_title, p.post_date,
				        img.meta_value AS image, occ.meta_value AS occupations,
				        rol.meta_value AS role, cause.meta_value AS cause,
				        enr.meta_value AS enriched_at,
				        EXISTS ( SELECT 1 FROM {$wpdb->prefix}obitleague_wiki_queue q
				                 WHERE q.request_kind = 'enrich_person'
				                   AND q.dedupe_key = CONCAT( 'person-', p.ID )
				                   AND q.status IN ( 'pending', 'processing' ) ) AS queued
				   {$from}
				 ORDER BY ( enr.meta_value IS NULL OR enr.meta_value = '' ) DESC, p.ID DESC
				 LIMIT %d",
				...$args
			)
		);
	}

	/** Dedupe key for one person's enrichment request. */
	private static function enrich_dedupe_key( int $post_id ): string {
		return 'person-' . $post_id;
	}

	/**
	 * Enqueue one person's enrichment (portrait, occupations, dates)
	 * through the global Wikimedia queue. Idempotent: pending and recently
	 * completed requests for the same person collapse into one row.
	 */
	public static function enqueue_person( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}
		Wiki_Request_Queue::enqueue(
			'enrich_person',
			array( 'post_id' => $post_id ),
			self::enrich_dedupe_key( $post_id ),
			'wikidata'
		);
	}

	/** Public dedupe key so other modules enqueue with the same key. */
	public static function enrich_dedupe_key_public( int $post_id ): string {
		return self::enrich_dedupe_key( $post_id );
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
		// A successful fetch settles the record whatever it returned: a field
		// Wikidata does not hold is a checked gap, not an open one, so the
		// daily sweep stops asking for it again and again.
		update_post_meta( $post_id, self::META_ENRICHED_AT, current_time( 'mysql', true ) );
		return array( 'ok' => true, 'updated' => $updated > 0 );
	}

	/**
	 * Apply one Wikidata entity to a person post: occupations (P106), the
	 * enwiki sitelink, a birth date (P569) when missing, a day-precision
	 * death-date refinement for provisional records, the cause of death
	 * (P509), and the portrait (P18). Returns the number of meta groups
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

		// Cause of death (P509). The cause is resolved once per record: an
		// editorial decision (Review_Service marks it 'editor') or an earlier
		// import always wins, and a record Wikidata has no cause for is
		// marked checked so the sweep stops retrying it.
		if ( '' === (string) get_post_meta( $post_id, self::META_CAUSE_SOURCE, true )
			&& '' === trim( (string) get_post_meta( $post_id, 'obit_cause_text', true ) ) ) {
			$cause_qid = self::cause_qid( $entity );
			if ( '' !== $cause_qid ) {
				$cause_label = self::label_for( $cause_qid );
				if ( '' !== $cause_label ) {
					update_post_meta( $post_id, 'obit_cause_status', Cause_Status::CONFIRMED );
					update_post_meta( $post_id, 'obit_cause_text', $cause_label );
					update_post_meta( $post_id, self::META_CAUSE_SOURCE, self::CAUSE_SOURCE_WIKIDATA );
					Person_Content::regenerate( $post_id );
					++$updated;
				}
				// A cause whose label will not resolve is left unmarked, so a
				// later pass can still read it.
			} else {
				update_post_meta( $post_id, self::META_CAUSE_SOURCE, 'none' );
			}
		}

		// A birth date (P569) fills the record's gap; an existing stored
		// date always wins over anything the sync could add.
		if ( '' === (string) get_post_meta( $post_id, 'obit_birth_date', true ) ) {
			$wd_birth = self::birth_date_from_entity( $entity );
			if ( '' !== $wd_birth ) {
				update_post_meta( $post_id, 'obit_birth_date', $wd_birth );
				update_post_meta( $post_id, 'obit_birth_precision', 'exact' );
				++$updated;
			}
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
				// A successful fetch settles the record whatever it returned,
				// exactly as the queued handler does, so the daily sweep does not
				// immediately re-enqueue a record that has been checked.
				update_post_meta( $pid, self::META_ENRICHED_AT, current_time( 'mysql', true ) );
			}
		}
		return $updated;
	}

	/**
	 * Deceased (or every published) people still missing an occupation or a
	 * portrait and not already checked inside the refresh window — the records
	 * a Wikidata backfill can meaningfully improve. Records checked recently
	 * are skipped so a re-run converges instead of re-asking for an occupation
	 * Wikidata permanently lacks; `$refresh` ignores the marker and re-checks
	 * everything missing.
	 *
	 * @return int[] Post ids, never-checked first.
	 */
	public static function backfill_targets( bool $deceased, int $limit, bool $refresh = false ): array {
		global $wpdb;
		$deceased_sql = $deceased
			? "AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = 'obit_death_date' AND d.meta_value <> '')"
			: '';
		$checked_sql = '';
		$args        = array(
			self::META_OCCUPATIONS,
			self::META_IMAGE_URL,
			self::META_ENRICHED_AT,
			Catalogue::POST_TYPE,
		);
		if ( ! $refresh ) {
			$checked_sql = 'AND ( enr.meta_value IS NULL OR enr.meta_value = %s OR enr.meta_value < %s )';
			$args[]      = '';
			$args[]      = self::settle_cutoff( current_time( 'mysql', true ) );
		}
		$args[] = max( 1, $limit );
		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} occ ON occ.post_id = p.ID AND occ.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} img ON img.post_id = p.ID AND img.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} enr ON enr.post_id = p.ID AND enr.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND ( occ.meta_value IS NULL OR occ.meta_value = '' OR img.meta_value IS NULL OR img.meta_value = '' )
			   {$checked_sql}
			   {$deceased_sql}
			 ORDER BY ( enr.meta_value IS NULL OR enr.meta_value = '' ) DESC, p.ID ASC
			 LIMIT %d",
			...$args
		);
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Fetch Wikidata data for the given people and apply it, 50 ids per
	 * request. Shared by the WP-CLI catch-up and the admin one-click backfill
	 * so both process the same target set the same way. Each successful fetch
	 * stamps the record checked; the batch gap keeps us polite to the source.
	 *
	 * @param int[]         $targets       Person ids to process.
	 * @param callable|null $progress      Called with ( done, total ) after each batch.
	 * @param bool          $wait_on_pause Sleep out a Wikidata rate-limit pause rather
	 *                                     than hammering it — right for the CLI, too
	 *                                     slow for a web request.
	 * @param callable|null $log           Called with human messages (pause notices).
	 *
	 * @return array{targets:int,no_qid:int,occupations:int,portraits:int}
	 */
	public static function backfill_people( array $targets, ?callable $progress = null, bool $wait_on_pause = true, ?callable $log = null ): array {
		$total  = count( $targets );
		$no_qid = 0;
		$done   = 0;
		foreach ( array_chunk( $targets, 50 ) as $chunk ) {
			$batch = array();
			foreach ( $chunk as $pid ) {
				if ( '' === (string) get_post_meta( (int) $pid, 'obit_qid', true ) ) {
					++$no_qid;
					continue;
				}
				$batch[] = (int) $pid;
			}
			if ( array() !== $batch ) {
				if ( $wait_on_pause ) {
					// Honour a Wikidata rate-limit pause rather than hammering a
					// parked source: wait it out, bounded, then continue.
					$pause = Wiki_Request_Queue::source_pause_until( 'wikidata' );
					if ( $pause > time() ) {
						$wait = min( 300, $pause - time() + 1 );
						if ( null !== $log ) {
							$log( "wikidata parked; waiting {$wait}s" );
						}
						sleep( $wait );
					}
				}
				self::sync_batch( $batch );
				usleep( 300000 ); // A short gap between batches keeps us polite.
			}
			$done += count( $chunk );
			if ( null !== $progress ) {
				$progress( $done, $total );
			}
		}

		$with_occ = 0;
		$with_img = 0;
		foreach ( $targets as $pid ) {
			if ( array() !== self::occupation_labels( (int) $pid ) ) {
				++$with_occ;
			}
			if ( '' !== (string) get_post_meta( (int) $pid, self::META_IMAGE_URL, true ) ) {
				++$with_img;
			}
		}
		return array( 'targets' => $total, 'no_qid' => $no_qid, 'occupations' => $with_occ, 'portraits' => $with_img );
	}

	/**
	 * Backfill occupations and portraits for published people straight from
	 * Wikidata, 50 ids per request. This is the one-off catch-up for records a
	 * stalled queue skipped; the routine path stays the daily enqueue_missing()
	 * sweep through the shared request queue. Idempotent and re-runnable — a
	 * record is only targeted while it still lacks an occupation or a portrait.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Include living people too. Default: deceased records only.
	 *
	 * [--limit=<n>]
	 * : Most people to process. Default 3000.
	 *
	 * [--refresh]
	 * : Re-check records already enriched inside the refresh window. Without
	 *   it a re-run only touches records never checked (or checked over 30 days
	 *   ago), so a second pass converges rather than re-asking for a permanent
	 *   gap.
	 *
	 * ## EXAMPLES
	 *
	 *     wp obitleague backfill-people
	 *     wp obitleague backfill-people --all --limit=500
	 *     wp obitleague backfill-people --refresh
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags.
	 */
	public static function cli_backfill_people( array $args, array $assoc_args ): void {
		$deceased = ! isset( $assoc_args['all'] );
		$limit    = max( 1, (int) ( $assoc_args['limit'] ?? 3000 ) );
		$refresh  = isset( $assoc_args['refresh'] );
		$scope    = $deceased ? 'deceased' : 'published';
		$targets  = self::backfill_targets( $deceased, $limit, $refresh );
		$total    = count( $targets );

		if ( 0 === $total ) {
			\WP_CLI::success( 'Nothing to backfill: every target has occupations and a portrait, or was checked recently (use --refresh to re-check).' );
			return;
		}
		\WP_CLI::log( "Backfilling {$total} {$scope} people from Wikidata, 50 per request" . ( $refresh ? ' (refresh).' : '.' ) );

		$stats = self::backfill_people(
			$targets,
			static function ( int $done, int $total ): void {
				\WP_CLI::log( "processed {$done}/{$total}" );
			},
			true,
			static function ( string $message ): void {
				\WP_CLI::log( $message );
			}
		);

		$note = "Backfilled {$stats['targets']} {$scope} people: {$stats['occupations']} now have occupations, {$stats['portraits']} a portrait";
		$note .= $stats['no_qid'] > 0 ? "; {$stats['no_qid']} had no Wikidata id and were skipped." : '.';
		\WP_CLI::success( $note );
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
	 * The Wikidata item named as the cause of death (P509) by one entity,
	 * '' when the person has no usable claim. Preferred ranks win; a claim
	 * Wikidata has deprecated is ignored.
	 */
	public static function cause_qid( ?array $entity ): string {
		if ( ! $entity ) {
			return '';
		}
		$claims = is_array( $entity['claims'] ?? null ) ? $entity['claims'] : array();
		$qids   = array();
		foreach ( (array) ( $claims['P509'] ?? array() ) as $claim ) {
			if ( ! is_array( $claim ) || 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			$value = $claim['mainsnak']['datavalue']['value'] ?? null;
			$qid   = is_array( $value ) ? (string) ( $value['id'] ?? '' ) : '';
			if ( '' !== $qid ) {
				$qids[ $qid ] = 'preferred' === (string) ( $claim['rank'] ?? 'normal' ) ? 'preferred' : 'normal';
			}
		}
		foreach ( $qids as $qid => $rank ) {
			if ( 'preferred' === $rank ) {
				return (string) $qid;
			}
		}
		$first = array_key_first( $qids );
		return null === $first ? '' : (string) $first;
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
			$label = self::label_for( $qid );
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

	/**
	 * Label lookup cache (Wikidata QID → English label), shared by every
	 * item-valued claim this module reads: occupations and cause of death.
	 */
	private static array $labels = array();

	/** Prefetch labels for many QIDs (50 ids per wbgetentities call). */
	private static function label_prefetch( array $qids ): void {
		$todo = array();
		foreach ( $qids as $qid ) {
			$qid = (string) $qid;
			if ( '' !== $qid && ! isset( self::$labels[ $qid ] ) ) {
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
				self::$labels[ $qid ] = $label;
			}
		}
	}

	/** English label for one Wikidata item; unresolved QIDs return ''. */
	private static function label_for( string $qid ): string {
		if ( isset( self::$labels[ $qid ] ) ) {
			return self::$labels[ $qid ];
		}
		self::$labels[ $qid ] = '';
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
		self::$labels[ $qid ] = $label;
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
	 * The occupation labels a public page should show for one person: the
	 * stored Wikidata labels when present, otherwise the browsable taxonomy
	 * terms. Profile pages and cards read this so a record enriched through
	 * either path still shows its occupations instead of an empty line — the
	 * stored list and its term mirror can land in different passes.
	 *
	 * @return string[] Cleaned labels in order.
	 */
	public static function display_occupation_labels( int $post_id ): array {
		$labels = self::occupation_labels( $post_id );
		if ( array() !== $labels ) {
			return $labels;
		}
		$terms = get_the_terms( $post_id, Catalogue::TAX_OCCUPATION );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$label = trim( (string) $term->name );
			if ( '' !== $label ) {
				$out[] = $label;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Every occupation of a person as a pre-escaped tag, linked to its archive
	 * term when one exists and plain otherwise. Reads the same source as the
	 * profile list, so the list and its tags can never disagree.
	 *
	 * @return string[] Pre-escaped <a>/<span> elements.
	 */
	public static function display_occupation_links( int $post_id ): array {
		$out = array();
		foreach ( self::display_occupation_labels( $post_id ) as $label ) {
			$term = get_term_by( 'name', $label, Catalogue::TAX_OCCUPATION );
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$out[] = '<a class="ob-occ-tag" href="' . esc_url( (string) $link ) . '">' . esc_html( $label ) . '</a>';
					continue;
				}
			}
			$out[] = '<span class="ob-occ-tag ob-occ-tag--plain">' . esc_html( $label ) . '</span>';
		}
		return $out;
	}

	/**
	 * The one occupation a catalogue card or search result should show: the
	 * stored preferred-rank occupation, falling back to the first label from
	 * whichever source holds one. The full list belongs on the profile page,
	 * not on every tile the person appears in.
	 */
	public static function primary_occupation_label( int $post_id ): string {
		$primary = self::primary_occupation( $post_id );
		if ( '' !== $primary ) {
			return $primary;
		}
		$labels = self::display_occupation_labels( $post_id );
		return (string) ( $labels[0] ?? '' );
	}

	/**
	 * The primary occupation as one pre-escaped archive link, or '' when the
	 * person has none. Cards show a single occupation; the profile page uses
	 * `occupation_term_links()` to list every term.
	 */
	public static function primary_occupation_link( int $post_id ): string {
		$label = self::primary_occupation_label( $post_id );
		if ( '' === $label ) {
			return '';
		}
		$term = get_term_by( 'name', $label, Catalogue::TAX_OCCUPATION );
		if ( $term instanceof \WP_Term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				return '<a class="ob-occ-tag" href="' . esc_url( (string) $link ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		return '<span class="ob-occ-tag ob-occ-tag--plain">' . esc_html( $label ) . '</span>';
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
	 * Day-precision birth date (P569) as Y-m-d, preferring preferred ranks;
	 * empty when the claim is absent, deprecated, or coarser than a day.
	 */
	private static function birth_date_from_entity( ?array $entity ): string {
		$claims = (array) ( ( $entity['claims']['P569'] ?? array() ) );
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

<?php
/**
 * Bounded Wikidata candidate discovery for the private editorial queue.
 *
 * Candidates are never published automatically. Wikimedia data is only a
 * filter; editor approval requires fresh upstream checks and a recent source.
 * One cursor page and no more than five new drafts are processed per day.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Discovery_Rules;
use Obitleague\Domain\Value\Ruleset;

final class Discovery_Service {

	private const SPARQL_ENDPOINT    = 'https://query.wikidata.org/sparql';
	private const WIKIDATA_API       = 'https://www.wikidata.org/w/api.php';
	private const WIKIPEDIA_API      = 'https://en.wikipedia.org/w/api.php';
	private const CURSOR_OPTION      = 'obitleague_discovery_cursor';
	private const LAST_RUN_OPTION    = 'obitleague_discovery_last_run';
	private const PAUSE_OPTION       = 'obitleague_discovery_pause_until';
	private const LOCK_OPTION        = 'obitleague_discovery_lock';
	private const HTTP_LOCK_OPTION   = 'obitleague_discovery_http_lock';
	private const LAST_HTTP_OPTION   = 'obitleague_discovery_last_http';
	private const REVIEW_LOCK_PREFIX = 'obitleague_discovery_review_';
	private const DAILY_OPTION       = 'obitleague_discovery_daily';
	private const BATCH_SIZE         = 25;
	private const QUEUE_LIMIT        = 5;
	private const FIRST_BIRTH_YEAR   = 1946;
	private const EDITOR_CAPABILITY  = 'obitleague_review';
	private const REVIEW_LOCK_TTL    = 900;

	private static ?int $editorial_publication_post_id = null;

	private function __construct() {}

	public static function boot(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'guard_candidate_publication' ), 10, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'obitleague discovery', array( self::class, 'cli_run_batch' ) );
		}
	}

	/** Force Discovery candidates to draft unless this exact ID is approved. */
	public static function guard_candidate_publication( array $data, array $postarr ): array {
		$post_id = absint( $postarr['ID'] ?? 0 );		if ( $post_id
			&& Catalogue::POST_TYPE === (string) ( $data['post_type'] ?? '' )
			&& 'publish' === (string) ( $data['post_status'] ?? '' )
			&& 'publish' !== get_post_status( $post_id )
			&& 'wikidata-wdqs' === (string) get_post_meta( $post_id, 'obit_discovery_source', true )
			&& self::$editorial_publication_post_id !== $post_id
		) {
			$data['post_status'] = 'draft';
		}
		return $data;
	}

	/** Scope the only Discovery publication path to an authenticated reviewer. */
	public static function begin_editorial_publication( int $post_id ): void {
		if ( ! is_user_logged_in() || ! current_user_can( self::EDITOR_CAPABILITY ) || $post_id < 1 ) {
			throw new \RuntimeException( 'Only an authorised editorial reviewer may publish a Discovery candidate.' );
		}
		self::$editorial_publication_post_id = $post_id;
	}

	public static function end_editorial_publication(): void {
		self::$editorial_publication_post_id = null;
	}

	/** Validate saved evidence and reviewer identity immediately before publishing. */
	public static function assert_approval_evidence( int $post_id, ?array $evidence ): void {
		if ( self::$editorial_publication_post_id !== $post_id || ! is_user_logged_in() || ! current_user_can( self::EDITOR_CAPABILITY ) || ! is_array( $evidence ) ) {
			throw new \InvalidArgumentException( 'Discovery candidates require approval through the editorial review queue.' );
		}
		$url                  = trim( (string) ( $evidence['url'] ?? '' ) );
		$date                 = trim( (string) ( $evidence['date'] ?? '' ) );
		$reviewer             = (int) ( $evidence['reviewer_id'] ?? 0 );
		$checked_at           = trim( (string) ( $evidence['checked_at'] ?? '' ) );
		$checked_at_timestamp = strtotime( $checked_at . ' UTC' );
		if ( ! Discovery_Rules::is_approved_living_evidence( $url, $date, true )
			|| false === $checked_at_timestamp			|| $checked_at_timestamp < time() - 60
			|| $checked_at_timestamp > time() + 30
			|| $reviewer < 1
			|| $reviewer !== get_current_user_id()
			|| '1' !== (string) get_post_meta( $post_id, 'obit_alive_evidence_checked', true )
			|| esc_url_raw( $url ) !== (string) get_post_meta( $post_id, 'obit_alive_evidence_url', true )
			|| $date !== (string) get_post_meta( $post_id, 'obit_alive_evidence_date', true )
			|| $reviewer !== (int) get_post_meta( $post_id, 'obit_alive_evidence_checked_by', true )
			|| $checked_at !== (string) get_post_meta( $post_id, 'obit_alive_evidence_checked_at', true )
		) {
			throw new \InvalidArgumentException( 'A valid recent HTTPS source and the authenticated editor’s living-status confirmation are required.' );
		}
	}

	/** Run one small, manually invoked batch. No WP-Cron hook is installed. */
	public static function run_batch( int $limit = self::QUEUE_LIMIT ): array|\WP_Error {
		$limit = min( self::QUEUE_LIMIT, max( 1, $limit ) );
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			$lock_time = (int) get_option( self::LOCK_OPTION, 0 );
			if ( $lock_time > time() - 600 ) {
				return new \WP_Error( 'obitleague_discovery_busy', 'A Discovery batch is already running.' );
			}
			delete_option( self::LOCK_OPTION );
			if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
				return new \WP_Error( 'obitleague_discovery_busy', 'A Discovery batch is already running.' );
			}
		}
		try {
			return self::run_batch_locked( $limit );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/** @return array<string,int|string>|\WP_Error */
	private static function run_batch_locked( int $limit ): array|\WP_Error {
		$today = gmdate( 'Y-m-d' );
		$daily = get_option( self::DAILY_OPTION, array() );
		if ( is_array( $daily ) && $today === (string) ( $daily['date'] ?? '' ) && ! empty( $daily['ran'] ) ) {
			return new \WP_Error( 'obitleague_discovery_daily_limit', 'Discovery has already run its one small batch for today (UTC). Try again tomorrow.' );
		}
		$pause = (int) get_option( self::PAUSE_OPTION, 0 );
		if ( $pause > time() ) {
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikimedia asked us to pause until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause ) ), array( 'status' => 429, 'retry_after' => $pause ) );
		}
		self::mark_daily_run( $today );

		$cursor = self::cursor();
		$window = sprintf( '%04d-%02d', $cursor['year'], $cursor['month'] );
		$rows   = self::fetch_sparql_page( $cursor );
		if ( is_wp_error( $rows ) ) {
			self::record_last_run( array( 'status' => 'error', 'window' => $window, 'message' => $rows->get_error_message() ) );
			return $rows;
		}

		$unique = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_array( $row['person'] ?? null ) || ! is_array( $row['title'] ?? null )
				|| ! is_string( $row['person']['value'] ?? null ) || ! is_string( $row['title']['value'] ?? null )
			) {
				self::record_last_run( array( 'status' => 'error', 'window' => $window, 'message' => 'Wikidata returned a malformed row; the cursor did not advance.' ) );
				return new \WP_Error( 'obitleague_discovery_sparql_row', 'Wikidata returned a malformed row; no candidates were created.' );
			}
			$qid = self::qid_from_uri( $row['person']['value'] );
			if ( '' === $qid ) {
				self::record_last_run( array( 'status' => 'error', 'window' => $window, 'message' => 'Wikidata returned an invalid person URI; the cursor did not advance.' ) );
				return new \WP_Error( 'obitleague_discovery_sparql_qid', 'Wikidata returned an invalid person URI; no candidates were created.' );
			}
			if ( ! isset( $unique[ $qid ] ) ) {
				$unique[ $qid ] = $row;
			}
		}
		if ( array() === $unique ) {
			if ( array() !== $rows ) {
				self::record_last_run( array( 'status' => 'error', 'window' => $window, 'message' => 'Wikidata returned no valid identifiers; the cursor did not advance.' ) );
				return new \WP_Error( 'obitleague_discovery_sparql_qid', 'Wikidata returned no valid identifiers; no candidates were created.' );
			}
			self::advance_cursor( $cursor );
			$result = array( 'status' => 'ok', 'window' => $window, 'checked' => 0, 'queued' => 0, 'skipped' => 0, 'note' => 'No rows in this window; advanced to the next birth month.' );
			self::record_last_run( $result );
			return $result;
		}

		$unseen = array();
		foreach ( $unique as $qid => $row ) {
			if ( ! Import_Service::post_id_by_qid( $qid ) ) {
				$unseen[ $qid ] = $row;
			}
		}
		$entities = array();
		if ( $unseen ) {
			$entities = self::fetch_entities( array_keys( $unseen ) );
			if ( is_wp_error( $entities ) ) {
				self::record_last_run( array( 'status' => 'error', 'window' => $window, 'message' => $entities->get_error_message() ) );
				return $entities;
			}
		}

		$eligible = array();
		foreach ( $unseen as $qid => $row ) {
			$entity = $entities[ $qid ] ?? null;
			if ( ! is_array( $entity ) || array_key_exists( 'missing', $entity ) ) {
				continue;
			}
			$birth = Discovery_Rules::exact_birth_date( $entity );
			if ( ! Discovery_Rules::is_human( $entity ) || Discovery_Rules::has_death_claim( $entity )
				|| null === $birth || ! str_starts_with( $birth, $window )
				|| ! Discovery_Rules::is_old_enough( $birth, League_Service::current_season() )
			) {
				continue;
			}
			$title     = trim( (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' ) );
			$row_title = trim( (string) $row['title']['value'] );
			if ( '' !== $title && self::normalize_title( $title ) === self::normalize_title( $row_title ) ) {
				$eligible[ $qid ] = array( 'entity' => $entity, 'title' => $title, 'birth' => $birth );
			}
		}

		$categories = array();
		if ( $eligible ) {
			$categories = self::fetch_categories( array_column( $eligible, 'title' ) );
			if ( is_wp_error( $categories ) ) {
				self::record_last_run( array( 'status' => 'error', 'window' => $window, 'message' => $categories->get_error_message() ) );
				return $categories;
			}
		}

		$queued = 0;
		$skipped = 0;
		$last_processed = (string) $cursor['after_qid'];
		$stopped_at_limit = false;
		foreach ( $unique as $qid => $row ) {
			$last_processed = $qid;
			if ( Import_Service::post_id_by_qid( $qid ) ) {
				++$skipped;
				continue;
			}
			$candidate = $eligible[ $qid ] ?? null;
			if ( ! is_array( $candidate ) ) {
				++$skipped;
				continue;
			}
			$page_categories = $categories[ self::normalize_title( $candidate['title'] ) ] ?? null;
			if ( ! is_array( $page_categories )
				|| Discovery_Rules::death_categories( $page_categories, (int) substr( $candidate['birth'], 0, 4 ) )
				|| ! Discovery_Rules::has_living_category( $page_categories )
			) {
				++$skipped;
				continue;
			}

			$entity = $candidate['entity'];
			$name   = trim( (string) ( $entity['labels']['en']['value'] ?? $candidate['title'] ) );
			try {
				$post_id = Import_Service::import_person( array( 'qid' => $qid, 'name' => $name, 'birth_date' => $candidate['birth'], 'enwiki' => str_replace( ' ', '_', $candidate['title'] ), 'discovery_candidate' => true ) );
			} catch ( \Throwable ) {
				++$skipped;
				continue;
			}
			update_post_meta( $post_id, 'obit_discovery_status', 'pending' );
			update_post_meta( $post_id, 'obit_discovery_source', 'wikidata-wdqs' );
			update_post_meta( $post_id, 'obit_discovery_qid', $qid );
			update_post_meta( $post_id, 'obit_discovery_original_title', (string) get_the_title( $post_id ) );
			update_post_meta( $post_id, 'obit_discovery_original_enwiki', str_replace( ' ', '_', $candidate['title'] ) );
			update_post_meta( $post_id, 'obit_discovery_created_at', current_time( 'mysql', true ) );
			update_post_meta( $post_id, 'obit_discovery_checked_at', current_time( 'mysql', true ) );
			update_post_meta( $post_id, 'obit_discovery_living_category', 'Living people' );
			update_post_meta( $post_id, 'obit_discovery_description', trim( (string) ( $entity['descriptions']['en']['value'] ?? '' ) ) );
			++$queued;
			self::audit( $post_id, 'candidate_queued', array( 'qid' => $qid, 'birth' => $candidate['birth'], 'window' => $window, 'living_category' => true ) );
			if ( $queued >= $limit ) {
				$stopped_at_limit = true;
				break;
			}
		}

		$cursor['after_qid'] = $last_processed;
		if ( $stopped_at_limit ) {
			update_option( self::CURSOR_OPTION, $cursor, false );
		} elseif ( count( $rows ) < self::BATCH_SIZE ) {
			self::advance_cursor( $cursor );
		} else {
			$cursor['after_qid'] = (string) array_key_last( $unique );
			update_option( self::CURSOR_OPTION, $cursor, false );
		}
		$result = array( 'status' => 'ok', 'window' => $window, 'checked' => count( $unique ), 'queued' => $queued, 'skipped' => $skipped, 'note' => 'Candidates remain private drafts; editor must verify a recent living-status source before approval.' );
		self::record_last_run( $result );
		return $result;
	}

	/** Approve only after fresh structured screening and an editor's source check. */
	public static function approve_candidate( int $post_id, string $source_url, string $evidence_date, bool $editor_confirmed_alive, int $reviewer_id ): int {
		if ( ! is_user_logged_in() || ! current_user_can( self::EDITOR_CAPABILITY ) || $reviewer_id !== get_current_user_id() ) {
			throw new \RuntimeException( 'Only the authenticated editorial reviewer may approve this candidate.' );
		}
		$post = get_post( $post_id );
		if ( ! $post || Catalogue::POST_TYPE !== $post->post_type || 'draft' !== $post->post_status
			|| 'pending' !== (string) get_post_meta( $post_id, 'obit_discovery_status', true )
		) {
			throw new \InvalidArgumentException( 'This Discovery candidate is no longer pending.' );
		}
		if ( ! Discovery_Rules::is_approved_living_evidence( $source_url, $evidence_date, $editor_confirmed_alive ) ) {
			throw new \InvalidArgumentException( 'Confirm the person is living and provide an HTTPS source published within the last 12 months.' );
		}
		self::acquire_review_lock( $post_id );
		try {
			return self::approve_candidate_locked( $post_id, $source_url, $evidence_date, $reviewer_id );
		} finally {
			delete_option( self::REVIEW_LOCK_PREFIX . $post_id );
		}
	}

	private static function approve_candidate_locked( int $post_id, string $source_url, string $evidence_date, int $reviewer_id ): int {
		if ( 'draft' !== get_post_status( $post_id ) || 'pending' !== (string) get_post_meta( $post_id, 'obit_discovery_status', true ) ) {
			throw new \InvalidArgumentException( 'This Discovery candidate is no longer pending.' );
		}
		foreach ( array( 'obit_alive_evidence_url', 'obit_alive_evidence_date', 'obit_alive_evidence_checked', 'obit_alive_evidence_checked_by', 'obit_alive_evidence_checked_at' ) as $evidence_key ) {
			delete_post_meta( $post_id, $evidence_key );
		}
		$checked = self::verify_current_candidate( $post_id );
		if ( is_wp_error( $checked ) ) {
			throw new \RuntimeException( $checked->get_error_message() );
		}
		update_post_meta( $post_id, 'obit_alive_evidence_url', esc_url_raw( $source_url ) );
		update_post_meta( $post_id, 'obit_alive_evidence_date', $evidence_date );
		update_post_meta( $post_id, 'obit_alive_evidence_checked', '1' );
		update_post_meta( $post_id, 'obit_alive_evidence_checked_by', $reviewer_id );
		$checked_at = current_time( 'mysql', true );
		update_post_meta( $post_id, 'obit_alive_evidence_checked_at', $checked_at );

		self::begin_editorial_publication( $post_id );
		try {
			Import_Service::approve_person( $post_id, 'Approved by an editor after a recent living-status source was checked.' );
		} catch ( \Throwable $error ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			update_post_meta( $post_id, 'obit_eligibility', 'candidate' );
			update_post_meta( $post_id, 'obit_eligibility_note', 'Discovery candidate — awaiting editorial eligibility review.' );
			foreach ( array( 'obit_alive_evidence_url', 'obit_alive_evidence_date', 'obit_alive_evidence_checked', 'obit_alive_evidence_checked_by', 'obit_alive_evidence_checked_at' ) as $evidence_key ) {
				delete_post_meta( $post_id, $evidence_key );
			}
			throw $error;
		} finally {
			self::end_editorial_publication();
		}
		update_post_meta( $post_id, 'obit_discovery_status', 'approved' );
		self::audit( $post_id, 'candidate_approved', array( 'qid' => (string) get_post_meta( $post_id, 'obit_qid', true ), 'evidence_url' => esc_url_raw( $source_url ), 'evidence_date' => $evidence_date, 'reviewer_id' => $reviewer_id, 'rechecked_at' => current_time( 'mysql', true ) ) );
		return $post_id;
	}

	/** Reject without deleting the private draft or audit trail. */
	public static function reject_candidate( int $post_id, string $reason, int $reviewer_id ): void {
		if ( ! is_user_logged_in() || ! current_user_can( self::EDITOR_CAPABILITY ) || $reviewer_id !== get_current_user_id() ) {
			throw new \RuntimeException( 'Only the authenticated editorial reviewer may reject this candidate.' );
		}
		$post = get_post( $post_id );
		if ( ! $post || Catalogue::POST_TYPE !== $post->post_type || 'draft' !== $post->post_status || 'pending' !== (string) get_post_meta( $post_id, 'obit_discovery_status', true ) ) {
			throw new \InvalidArgumentException( 'This Discovery candidate is no longer pending.' );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason || strlen( $reason ) > 1000 ) {
			throw new \InvalidArgumentException( 'A rejection note of 1–1000 characters is required.' );
		}
		self::acquire_review_lock( $post_id );
		try {
			if ( 'pending' !== (string) get_post_meta( $post_id, 'obit_discovery_status', true ) || 'draft' !== get_post_status( $post_id ) ) {
				throw new \InvalidArgumentException( 'This Discovery candidate is no longer pending.' );
			}
			update_post_meta( $post_id, 'obit_discovery_status', 'rejected' );
			update_post_meta( $post_id, 'obit_discovery_rejected_by', $reviewer_id );
			update_post_meta( $post_id, 'obit_discovery_rejected_at', current_time( 'mysql', true ) );
			update_post_meta( $post_id, 'obit_discovery_rejection_note', $reason );
			update_post_meta( $post_id, 'obit_eligibility', 'ineligible' );
			update_post_meta( $post_id, 'obit_eligibility_note', $reason );
			self::audit( $post_id, 'candidate_rejected', array( 'qid' => (string) get_post_meta( $post_id, 'obit_qid', true ), 'reason' => $reason, 'reviewer_id' => $reviewer_id ) );
		} finally {
			delete_option( self::REVIEW_LOCK_PREFIX . $post_id );
		}
	}

	/** Saved progress for the admin screen. */
	public static function status(): array {
		$cursor = self::cursor();
		$daily  = get_option( self::DAILY_OPTION, array() );
		return array(
			'cursor'        => sprintf( '%04d-%02d', $cursor['year'], $cursor['month'] ),
			'last_run'      => get_option( self::LAST_RUN_OPTION, array() ),
			'pause_until'   => (int) get_option( self::PAUSE_OPTION, 0 ),
			'daily_limited' => is_array( $daily ) && gmdate( 'Y-m-d' ) === (string) ( $daily['date'] ?? '' ) && ! empty( $daily['ran'] ),
		);
	}

	/** Explicit WP-CLI command: wp obitleague discovery [--limit=1..5]. */
	public static function cli_run_batch( array $args, array $assoc_args ): void {
		$limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 1;
		$result = self::run_batch( $limit );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Window %s: checked %d, queued %d, skipped %d. %s', $result['window'], $result['checked'], $result['queued'], $result['skipped'], $result['note'] ) );
	}

	/** Freshly verify Wikidata facts and the positive Wikipedia living category. */
	private static function verify_current_candidate( int $post_id ): bool|\WP_Error {
		$qid = strtoupper( trim( (string) get_post_meta( $post_id, 'obit_qid', true ) ) );
		if ( ! preg_match( '/^Q[1-9][0-9]*$/', $qid ) ) {
			return new \WP_Error( 'obitleague_discovery_qid', 'Candidate has no valid Wikidata identifier.' );
		}
		$entities = self::fetch_entities( array( $qid ), false );
		if ( is_wp_error( $entities ) ) {
			return $entities;
		}
		$entity = $entities[ $qid ] ?? array();
		$birth  = Discovery_Rules::exact_birth_date( $entity );
		$stored = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		if ( $qid !== (string) get_post_meta( $post_id, 'obit_discovery_qid', true )
			|| (string) get_the_title( $post_id ) !== (string) get_post_meta( $post_id, 'obit_discovery_original_title', true )
			|| self::normalize_title( (string) get_post_meta( $post_id, 'obit_enwiki', true ) ) !== self::normalize_title( (string) get_post_meta( $post_id, 'obit_discovery_original_enwiki', true ) )
		) {
			return new \WP_Error( 'obitleague_discovery_identity_changed', 'The candidate identity fields changed after Discovery. Restore the original QID, name, and Wikipedia title before retrying approval.' );
		}
		if ( ! Discovery_Rules::is_human( $entity ) || Discovery_Rules::has_death_claim( $entity ) ) {
			return new \WP_Error( 'obitleague_discovery_not_living', 'Wikidata no longer supports this person as an undeceased human.' );
		}
		if ( null === $birth || $birth !== $stored || ! Discovery_Rules::is_old_enough( $birth, League_Service::current_season() ) ) {
			return new \WP_Error( 'obitleague_discovery_birth_changed', 'Current birth facts no longer satisfy this season’s eligibility requirements.' );
		}
		$title = trim( (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' ) );
		$stored_enwiki = str_replace( '_', ' ', (string) get_post_meta( $post_id, 'obit_enwiki', true ) );
		if ( '' !== $title && self::normalize_title( $title ) !== self::normalize_title( $stored_enwiki ) ) {
			return new \WP_Error( 'obitleague_discovery_wikipedia_changed', 'The English Wikipedia identity has changed since the candidate was queued.' );
		}
		if ( '' === $title ) {
			return new \WP_Error( 'obitleague_discovery_wikipedia', 'No English Wikipedia article is available for the liveness cross-check.' );
		}
		$categories = self::fetch_categories( array( $title ), false );
		if ( is_wp_error( $categories ) ) {
			return $categories;
		}
		$page_categories = $categories[ self::normalize_title( $title ) ] ?? array();
		if ( Discovery_Rules::death_categories( $page_categories, (int) substr( $birth, 0, 4 ) ) ) {
			return new \WP_Error( 'obitleague_discovery_death_category', 'Wikipedia currently places this article in a death-year category.' );
		}
		if ( ! Discovery_Rules::has_living_category( $page_categories ) ) {
			return new \WP_Error( 'obitleague_discovery_living_category', 'The Wikipedia living-person category is no longer present; review manually and retry only if that signal returns.' );
		}
		return true;
	}

	/** Fetch one ordered page from a bounded month window. */
	private static function fetch_sparql_page( array $cursor ): array|\WP_Error {
		$from  = sprintf( '%04d-%02d-01T00:00:00Z', $cursor['year'], $cursor['month'] );
		$next  = ( new \DateTimeImmutable( $from, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 month' )->format( 'Y-m-d' ) . 'T00:00:00Z';
		$after = '';
		if ( '' !== $cursor['after_qid'] ) {
			$after = 'FILTER(STR(?person) > "http://www.wikidata.org/entity/' . $cursor['after_qid'] . '")';
		}
		$query = 'PREFIX wd: <http://www.wikidata.org/entity/> '
			. 'PREFIX wdt: <http://www.wikidata.org/prop/direct/> '
			. 'PREFIX p: <http://www.wikidata.org/prop/> '
			. 'PREFIX ps: <http://www.wikidata.org/prop/statement/> '
			. 'PREFIX xsd: <http://www.w3.org/2001/XMLSchema#> '
			. 'PREFIX schema: <http://schema.org/> '
			. 'PREFIX wikibase: <http://wikiba.se/ontology#> '
			. 'SELECT DISTINCT ?person ?title WHERE {'
			. ' ?person wdt:P31 wd:Q5 ; p:P569 ?birthStatement .'
			. ' ?birthStatement ps:P569 ?birth .'
			. ' FILTER(?birth >= "' . $from . '"^^xsd:dateTime && ?birth < "' . $next . '"^^xsd:dateTime)'
			. ' FILTER NOT EXISTS { ?person wdt:P570 ?death . }'
			. ' ?person wikibase:sitelinks ?sitelinks . FILTER(?sitelinks >= 20)'
			. ' ?article schema:about ?person ; schema:name ?title ; schema:isPartOf <https://en.wikipedia.org/> .'
			. ' ' . $after
			. ' } ORDER BY STR(?person) LIMIT ' . self::BATCH_SIZE;
		$url  = self::SPARQL_ENDPOINT . '?' . http_build_query( array( 'format' => 'json', 'query' => $query ) );
		$data = self::request_json( $url, 'application/sparql-results+json' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$results  = $data['results'] ?? null;
		$bindings = is_array( $results ) ? ( $results['bindings'] ?? null ) : null;
		if ( ! is_array( $bindings ) ) {
			return new \WP_Error( 'obitleague_discovery_sparql_shape', 'Wikidata returned an unexpected query result; no candidates were created.' );
		}
		return $bindings;
	}

	/** Fetch entity claims and English labels for at most one page. */
	private static function fetch_entities( array $qids, bool $use_cache = true ): array|\WP_Error {
		$qids = array_values( array_unique( array_slice( array_filter( $qids ), 0, self::BATCH_SIZE ) ) );
		if ( ! $qids ) {
			return array();
		}
		$url = self::WIKIDATA_API . '?' . http_build_query(
			array(
				'action'        => 'wbgetentities',
				'ids'           => implode( '|', $qids ),
				'props'         => 'claims|labels|descriptions|sitelinks',
				'languages'     => 'en',
				'sites'         => 'enwiki',
				'format'        => 'json',
				'formatversion' => '2',
				'maxlag'        => 5,
			)
		);
		$data = self::request_json( $url, 'application/json', $use_cache );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$entity_results = $data['entities'] ?? null;
		if ( ! is_array( $entity_results ) || array() === $entity_results ) {
			return new \WP_Error( 'obitleague_discovery_entities_shape', 'Wikidata returned no usable entities; no candidates were created.' );
		}
		$entities = array();
		foreach ( $entity_results as $qid => $entity ) {
			if ( is_array( $entity ) ) {
				$entities[ strtoupper( (string) $qid ) ] = $entity;
			}
		}
		return $entities;
	}

	/** Fetch category memberships for up to 25 Wikipedia titles. */
	private static function fetch_categories( array $titles, bool $use_cache = true ): array|\WP_Error {
		$titles = array_values( array_unique( array_filter( array_map( 'trim', $titles ) ) ) );
		if ( ! $titles ) {
			return array();
		}
		$url = self::WIKIPEDIA_API . '?' . http_build_query(
			array(
				'action'        => 'query',
				'prop'          => 'categories',
				'cllimit'       => 'max',
				'titles'        => implode( '|', $titles ),
				'redirects'     => 1,
				'format'        => 'json',
				'formatversion' => 2,
				'maxlag'        => 5,
			)
		);
		$data = self::request_json( $url, 'application/json', $use_cache );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $data['continue'] ) ) {
			return new \WP_Error( 'obitleague_discovery_categories_incomplete', 'Wikipedia returned an incomplete category list; this cohort was not used to create candidates.' );
		}
		$query = $data['query'] ?? null;
		$pages = is_array( $query ) ? ( $query['pages'] ?? null ) : null;
		if ( ! is_array( $pages ) ) {
			return new \WP_Error( 'obitleague_discovery_categories_shape', 'Wikipedia returned an unexpected category result; no candidates were created.' );
		}
		if ( count( $pages ) > count( $titles ) ) {
			return new \WP_Error( 'obitleague_discovery_categories_incomplete', 'Wikipedia did not return a category list for every title; this cohort was not used to create candidates.' );
		}
		$out = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || ! is_array( $page['categories'] ?? array() ) ) {
				return new \WP_Error( 'obitleague_discovery_categories_page', 'Wikipedia returned a malformed page category list; no candidates were created.' );
			}
			$page_title = trim( (string) ( $page['title'] ?? '' ) );
			$input_titles = array_map( static fn ( string $title ): string => self::normalize_title( $title ), $titles );
			if ( '' === $page_title || ! in_array( self::normalize_title( $page_title ), $input_titles, true ) ) {
				return new \WP_Error( 'obitleague_discovery_categories_identity', 'Wikipedia returned an unexpected page title; no candidates were created.' );
			}
			$cats       = array();
			foreach ( $page['categories'] ?? array() as $category ) {
				if ( ! is_array( $category ) || ! is_string( $category['title'] ?? null ) ) {
					return new \WP_Error( 'obitleague_discovery_categories_page', 'Wikipedia returned a malformed category; no candidates were created.' );
				}
				$cats[] = $category['title'];
			}
			$out[ self::normalize_title( $page_title ) ] = $cats;
		}
		foreach ( array_merge( (array) ( $query['normalized'] ?? array() ), (array) ( $query['redirects'] ?? array() ) ) as $mapping ) {
			$from = self::normalize_title( (string) ( $mapping['from'] ?? '' ) );
			$to   = self::normalize_title( (string) ( $mapping['to'] ?? '' ) );
			if ( isset( $out[ $to ] ) ) {
				$out[ $from ] = $out[ $to ];
			}
		}
		return $out;
	}

	/** One sequential request with a descriptive User-Agent and cache. */
	private static function request_json( string $url, string $accept, bool $use_cache = true ): array|\WP_Error {
		$cache_key = 'ob_discovery_' . hash( 'sha256', $url );
		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$pause_until = (int) get_option( self::PAUSE_OPTION, 0 );
		if ( $pause_until > time() ) {
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikimedia asked us to pause until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ), array( 'status' => 429, 'retry_after' => $pause_until ) );
		}
		if ( ! self::acquire_http_lock() ) {
			return new \WP_Error( 'obitleague_discovery_http_busy', 'Another Wikimedia request is in progress; please retry later.' );
		}
		try {
			$pause_until = (int) get_option( self::PAUSE_OPTION, 0 );
			if ( $pause_until > time() ) {
				return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikimedia asked us to pause until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ), array( 'status' => 429, 'retry_after' => $pause_until ) );
			}
			$last_request = (float) get_option( self::LAST_HTTP_OPTION, 0 );
			$elapsed      = microtime( true ) - $last_request;
			if ( $last_request > 0 && $elapsed < 1.0 ) {
				usleep( (int) ( ( 1.0 - $elapsed ) * 1_000_000 ) );
			}
			$response_started = microtime( true );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 2,
					'user-agent'  => self::user_agent(),
					'headers'     => array( 'Accept' => $accept ),
				)
			);
			update_option( self::LAST_HTTP_OPTION, $response_started, false );
		} finally {
			delete_option( self::HTTP_LOCK_OPTION );
		}
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'obitleague_discovery_network', 'Wikimedia could not be reached: ' . $response->get_error_message() );
		}
		$code      = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$api_error = is_array( $body ) && is_array( $body['error'] ?? null ) ? $body['error'] : array();
		if ( in_array( $code, array( 429, 503 ), true ) || in_array( (string) ( $api_error['code'] ?? '' ), array( 'maxlag', 'ratelimited' ), true ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after;
			$pause_until = self::set_rate_limit_pause( $retry_after );
			return new \WP_Error( 'obitleague_discovery_rate_limited', sprintf( 'Wikimedia asked us to slow down until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ), array( 'status' => $code ?: 503, 'retry_after' => $pause_until ) );
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'obitleague_discovery_http', sprintf( 'Wikimedia returned HTTP %d; no candidates were created.', $code ) );
		}
		if ( $api_error ) {
			return new \WP_Error( 'obitleague_discovery_api', 'Wikimedia API error: ' . sanitize_text_field( (string) ( $api_error['info'] ?? $api_error['code'] ?? 'unknown error' ) ) );
		}
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'obitleague_discovery_json', 'Wikimedia returned unreadable JSON; no candidates were created.' );
		}
		if ( $use_cache ) {
			set_transient( $cache_key, $body, 6 * HOUR_IN_SECONDS );
		}
		return $body;
	}

	/** Serialize Wikimedia calls across requests and enforce a one-second gap. */
	private static function acquire_http_lock(): bool {
		$deadline = microtime( true ) + 30.0;
		do {
			if ( add_option( self::HTTP_LOCK_OPTION, time(), '', false ) ) {
				return true;
			}
			$locked_at = (int) get_option( self::HTTP_LOCK_OPTION, 0 );
			if ( $locked_at < time() - 120 ) {
				delete_option( self::HTTP_LOCK_OPTION );
				continue;
			}
			usleep( 250_000 );
		} while ( microtime( true ) < $deadline );
		return false;
	}

	private static function acquire_review_lock( int $post_id ): void {
		$option = self::REVIEW_LOCK_PREFIX . $post_id;
		if ( add_option( $option, time(), '', false ) ) {
			return;
		}
		$locked_at = (int) get_option( $option, 0 );
		if ( $locked_at < time() - self::REVIEW_LOCK_TTL ) {
			delete_option( $option );
			if ( add_option( $option, time(), '', false ) ) {
				return;
			}
		}
		throw new \RuntimeException( 'Another reviewer is already processing this candidate.' );
	}

	private static function set_rate_limit_pause( string $retry_after ): int {
		$retry_after = trim( $retry_after );
		$until = is_numeric( $retry_after )
			? time() + max( 5, (int) $retry_after )
			: ( false !== strtotime( $retry_after ) ? strtotime( $retry_after ) : time() + 60 );
		$until = max( time() + 5, (int) $until );
		update_option( self::PAUSE_OPTION, $until, false );
		return $until;
	}

	private static function user_agent(): string {
		$contact  = sanitize_email( (string) get_option( 'admin_email', '' ) );
		$identity = home_url( '/about/' );
		$version  = defined( 'OBITLEAGUE_VERSION' ) ? OBITLEAGUE_VERSION : '0.1';
		return 'Obitleague-Discovery/' . $version . ' (' . $identity . '; ' . ( '' !== $contact ? $contact : home_url( '/' ) ) . ') WordPress/' . get_bloginfo( 'version' );
	}

	private static function cursor(): array {
		$cursor   = get_option( self::CURSOR_OPTION, array() );
		$cursor   = is_array( $cursor ) ? $cursor : array();
		$max_year = max( self::FIRST_BIRTH_YEAR, League_Service::current_season() - Ruleset::MIN_AGE );
		$year     = max( self::FIRST_BIRTH_YEAR, min( $max_year, (int) ( $cursor['year'] ?? self::FIRST_BIRTH_YEAR ) ) );
		$month    = max( 1, min( 12, (int) ( $cursor['month'] ?? 1 ) ) );
		$after    = strtoupper( trim( (string) ( $cursor['after_qid'] ?? '' ) ) );
		if ( ! preg_match( '/^Q[1-9][0-9]*$/', $after ) ) {
			$after = '';
		}
		return array( 'year' => $year, 'month' => $month, 'after_qid' => $after );
	}

	private static function advance_cursor( array $cursor ): void {
		$max_year = max( self::FIRST_BIRTH_YEAR, League_Service::current_season() - Ruleset::MIN_AGE );
		++$cursor['month'];
		$cursor['after_qid'] = '';
		if ( $cursor['month'] > 12 ) {
			$cursor['month'] = 1;
			++$cursor['year'];
			if ( $cursor['year'] > $max_year ) {
				$cursor['year'] = self::FIRST_BIRTH_YEAR;
			}
		}
		update_option( self::CURSOR_OPTION, $cursor, false );
	}

	private static function qid_from_uri( string $uri ): string {
		if ( ! preg_match( '~^https?://www\.wikidata\.org/entity/(Q[1-9][0-9]*)$~D', trim( $uri ), $match ) ) {
			return '';
		}
		return strtoupper( $match[1] );
	}

	private static function normalize_title( string $title ): string {
		$title = str_replace( '_', ' ', trim( $title ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );
	}

	private static function mark_daily_run( string $date ): void {
		update_option( self::DAILY_OPTION, array( 'date' => $date, 'ran' => true ), false );
	}

	private static function record_last_run( array $result ): void {
		$result['completed_at'] = current_time( 'mysql', true );
		update_option( self::LAST_RUN_OPTION, $result, false );
	}

	private static function audit( int $post_id, string $action, array $details ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'obitleague_admin_audit',
			array(
				'actor_user_id' => get_current_user_id(),
				'object_type'   => 'discovery_candidate',
				'object_id'     => $post_id,
				'action'        => $action,
				'details'       => wp_json_encode( $details ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}
}

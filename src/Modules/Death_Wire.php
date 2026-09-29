<?php
/**
 * The 2026 death wire.
 *
 * Two pipelines, one season scope (deaths dated in the in-play year only):
 *
 * 1. Wikipedia "Deaths in <year>" confirmation. The monthly sections of the
 *    canonical list are read through the Wikidata WDQS endpoint via the
 *    global Wikimedia request queue. People already on the site with a
 *    confirmed in-season death are matched and credited; living records that
 *    the list proves dead get a review case with the list as evidence; names
 *    not on the site yet are imported as drafts for the editors.
 *
 * 2. RSS source attachment. Candidate-classified feed items from the obit
 *    wires are matched to records by headline group (the name before a dash
 *    or colon) and to the Wikipedia article the record cites. A news story
 *    about a confirmed death attaches as public reporting on the obit page;
 *    a story about a living record whose Wikipedia article confirms the
 *    death opens a review case; a story that cannot be anchored to any
 *    record's article is flagged on the record for manual checking.
 *
 * Wikimedia politeness: every network call goes through the shared
 * rate-limit pause and the global request queue, never inline.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class Death_Wire {

	private const LIST_CURSOR_OPTION = 'obitleague_death_wire_list_cursor';
	private const WIRE_CURSOR_OPTION = 'obitleague_death_wire_item_cursor';
	private const LAST_RUN_OPTION    = 'obitleague_death_wire_last_run';
	private const DONE_MONTHS_OPTION = 'obitleague_death_wire_months_done';
	private const WIKI_LIST_TITLE    = 'Deaths in 2026';
	private const PAGE_SIZE          = 50;
	private const MAX_LIST_PAGES     = 12; // Safety bound: ~600 names/month.
	private const WIRE_BATCH         = 100;
	private const WIRE_LOOKBACK_DAYS = 400;
	private const USER_AGENT         = 'Obitleague-DeathWire/0.1 (WordPress; +obitleague.co.uk)';

	private function __construct() {}

	public static function boot(): void {
		Wiki_Request_Queue::register_handler( 'deaths2026_list_page', array( self::class, 'handle_list_page' ) );
		Wiki_Request_Queue::register_handler( 'death_wire_batch', array( self::class, 'handle_wire_batch' ) );
		Wiki_Request_Queue::register_handler( 'death_wire_check', array( self::class, 'handle_wire_check' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Closure form: wp-cli's reflection would otherwise try to
			// instantiate this class (private constructor) for the command.
			\WP_CLI::add_command(
				'obitleague deaths2026',
				static function ( array $args, array $assoc_args ): void {
					self::cli_run( $args, $assoc_args );
				}
			);
		}
	}

	/** Enqueue the Wikipedia list pass and the RSS wire pass. */
	public static function run(): array {
		$months = self::month_windows();
		$done   = (array) get_option( self::DONE_MONTHS_OPTION, array() );
		$queued = 0;
		foreach ( $months as $month ) {
			if ( in_array( $month, $done, true ) ) {
				continue;
			}
			Wiki_Request_Queue::enqueue( 'deaths2026_list_page', array( 'month' => $month ), 'deaths2026-' . $month );
			++$queued;
		}
		Wiki_Request_Queue::enqueue( 'death_wire_batch', array(), 'death-wire-batch' );
		return array( 'months_queued' => $queued, 'months_done' => count( $done ) );
	}

	/** WP-CLI: wp obitleague deaths2026 [--wire-only] [--list-only]. */
	public static function cli_run( array $args, array $assoc_args ): void {
		$wire_only = isset( $assoc_args['wire-only'] );
		$list_only = isset( $assoc_args['list-only'] );

		if ( ! $wire_only ) {
			$result = self::run();
			\WP_CLI::log( sprintf( 'Queued %d Wikipedia month pages (%d already done) and the RSS wire pass.', $result['months_queued'], $result['months_done'] ) );
		}
		if ( $list_only ) {
			\WP_CLI::success( 'List pages queued; they run through the global Wikimedia queue.' );
			return;
		}
		// Drain the queue in bounded passes so a CLI run makes real progress.
		$processed = 0;
		for ( $i = 0; $i < 20; ++$i ) {
			$done_now = Wiki_Request_Queue::process( 2 );
			$processed += $done_now;
			if ( 0 === $done_now ) {
				break;
			}
		}
		$stats = self::last_run();
		\WP_CLI::log( 'Queue requests completed this run: ' . $processed );
		\WP_CLI::success( 'Deaths in ' . Pick_Stats::season_in_play() . ': ' . wp_json_encode( $stats ) );
	}

	public static function last_run(): array {
		return (array) get_option( self::LAST_RUN_OPTION, array() );
	}

	/**
	 * Public source list for one obit page: news reports attached to the
	 * approved death. Empty for pending or unapproved records.
	 *
	 * @return array<int, array{name:string,url:string,date:string}>
	 */
	public static function public_sources( int $post_id ): array {
		global $wpdb;
		if ( $post_id < 1 ) {
			return array();
		}
		$uuid = (string) get_post_meta( $post_id, 'obit_uuid', true );
		if ( '' === $uuid ) {
			return array();
		}
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.source_name, s.source_url, s.published_at
				 FROM {$wpdb->prefix}obitleague_death_sources s
				 JOIN {$wpdb->prefix}obitleague_review_cases c ON c.id = s.case_id
				 WHERE c.person_uuid = %s AND c.state = 'approved'
				 ORDER BY s.id ASC LIMIT 12",
				$uuid
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'name' => (string) $row->source_name,
				'url'  => (string) $row->source_url,
				'date' => '' !== (string) $row->published_at ? substr( (string) $row->published_at, 0, 10 ) : '',
			);
		}
		return $out;
	}

	/** Count of attached public reports for one record (list-page badge). */
	public static function source_count( int $post_id ): int {
		global $wpdb;
		if ( $post_id < 1 ) {
			return 0;
		}
		$uuid = (string) get_post_meta( $post_id, 'obit_uuid', true );
		if ( '' === $uuid ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}obitleague_death_sources s
				 JOIN {$wpdb->prefix}obitleague_review_cases c ON c.id = s.case_id
				 WHERE c.person_uuid = %s AND c.state = 'approved'",
				$uuid
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Pipeline 1: Wikipedia "Deaths in 2026" sections.
	 * ------------------------------------------------------------------- */

	/** Month windows for the in-play year: past months plus the current and next. */
	private static function month_windows(): array {
		$year   = Pick_Stats::season_in_play();
		$months = array();
		for ( $m = 1; $m <= 12; ++$m ) {
			$stamp = mktime( 0, 0, 0, $m, 1, $year );
			if ( $stamp < time() - 90 * DAY_IN_SECONDS || $stamp > time() + 62 * DAY_IN_SECONDS ) {
				continue; // Keep the window tight: the season plus a small margin.
			}
			$months[] = gmdate( 'Y-m', $stamp );
		}
		return $months;
	}

	/** Queue handler: one month section of the Wikipedia list. */
	public static function handle_list_page( array $payload ): array {
		$month = (string) ( $payload['month'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return array( 'ok' => true, 'note' => 'No month given.' );
		}
		$offset = max( 0, (int) ( $payload['offset'] ?? 0 ) );

		$sparql = 'SELECT ?item ?itemLabel ?sl ?dob ?dod WHERE { '
			. '?item wdt:P31 wd:Q5 . '
			. '?item rdfs:label ?itemLabel FILTER(LANG(?itemLabel) = "en") . '
			. 'OPTIONAL { ?item wdt:P569 ?dob } . '
			. '?item wdt:P570 ?dod . '
			. 'FILTER(?dod >= "' . $month . '-01T00:00:00Z"^^<http://www.w3.org/2001/XMLSchema#dateTime> '
			. '&& ?dod < "' . self::next_month( $month ) . '-01T00:00:00Z"^^<http://www.w3.org/2001/XMLSchema#dateTime>) '
			. 'OPTIONAL { ?sl schema:about ?item . ?sl schema:isPartOf <https://en.wikipedia.org/> } '
			. '} ORDER BY ?item LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset;

		$rows = self::sparql( $sparql );
		if ( is_wp_error( $rows ) ) {
			return $rows; // Parked or failed; the queue retries.
		}

		$stats  = self::fresh_stats( 'list' );
		$people = array();
		$seen   = array();
		foreach ( $rows as $row ) {
			$qid = strtoupper( substr( (string) ( $row['item']['value'] ?? '' ), strlen( 'http://www.wikidata.org/entity/' ) ) );
			if ( ! preg_match( '/^Q\d+$/', $qid ) || isset( $seen[ $qid ] ) ) {
				continue;
			}
			$seen[ $qid ] = true;
			$people[]     = array(
				'qid'  => $qid,
				'name' => (string) ( $row['itemLabel']['value'] ?? '' ),
				'enwiki' => isset( $row['sl']['value'] ) ? self::enwiki_title_from_uri( (string) $row['sl']['value'] ) : '',
				'dob'  => (string) ( $row['dob']['value'] ?? '' ),
				'dod'  => (string) ( $row['dod']['value'] ?? '' ),
			);
		}

		foreach ( $people as $person ) {
			self::reconcile( $person, $stats );
		}

		$got_full_page = count( $rows ) >= self::PAGE_SIZE && count( $people ) >= self::PAGE_SIZE && $offset < self::PAGE_SIZE * ( self::MAX_LIST_PAGES - 1 );
		if ( $got_full_page ) {
			Wiki_Request_Queue::enqueue( 'deaths2026_list_page', array( 'month' => $month, 'offset' => $offset + self::PAGE_SIZE ), 'deaths2026-' . $month . '-p' . ( $offset / self::PAGE_SIZE + 1 ) );
		} else {
			// Section finished: remember the month so re-runs skip it.
			$done   = (array) get_option( self::DONE_MONTHS_OPTION, array() );
			$done[] = $month;
			update_option( self::DONE_MONTHS_OPTION, array_values( array_unique( $done ) ), false );
		}

		self::save_stats( $stats );
		return array( 'ok' => true, 'people' => count( $people ) );
	}

	/** Match one Wikipedia list entry against the site's records. */
	private static function reconcile( array $person, array &$stats ): void {
		if ( '' === $person['name'] ) {
			return;
		}
		$death_norm = self::normalize_day( $person['dod'] );
		$birth_norm = self::normalize_day( $person['dob'] );
		$post_id    = Import_Service::post_id_by_qid( $person['qid'] );

		if ( ! $post_id ) {
			// Not on the site yet: import a draft candidate for the editors.
			// Records with no stored birth date cannot be created at all.
			if ( '' === $birth_norm ) {
				++$stats['skipped_no_birth'];
				return;
			}
			try {
				$new_id = Import_Service::import_person(
					array(
						'qid'        => $person['qid'],
						'name'       => $person['name'],
						'birth_date' => $birth_norm,
						'death_date' => $death_norm,
						'enwiki'     => $person['enwiki'],
					)
				);
			} catch ( \Throwable $e ) {
				++$stats['skipped_no_birth'];
				return;
			}
			update_post_meta( $new_id, 'obit_death_wiki_name', self::WIKI_LIST_TITLE );
			self::open_case_for( $new_id, 'Wikipedia lists a ' . $person['dod'] . ' death; editor confirmation required.' );
			self::attach_source( $new_id, 'Wikipedia — ' . self::WIKI_LIST_TITLE, 'https://en.wikipedia.org/wiki/' . rawurlencode( self::WIKI_LIST_TITLE ), '' );
			++$stats['new_candidates'];
			return;
		}

		// Repair the citation when the record predates the enwiki backfill.
		if ( '' !== $person['enwiki'] && '' === (string) get_post_meta( $post_id, 'obit_enwiki', true ) ) {
			update_post_meta( $post_id, 'obit_enwiki', $person['enwiki'] );
			++$stats['enwiki_repaired'];
		}

		$stored = (string) get_post_meta( $post_id, 'obit_death_date', true );
		if ( '' !== $stored && str_starts_with( $stored, '2026' ) ) {
			// Already confirmed on the site: credit the list as a source.
			self::attach_source( $post_id, 'Wikipedia — ' . self::WIKI_LIST_TITLE, 'https://en.wikipedia.org/wiki/' . rawurlencode( self::WIKI_LIST_TITLE ), '' );
			++$stats['confirmed_matched'];
			return;
		}

		// On the site as living, but Wikipedia records a 2026 death: open a
		// review case rather than publishing an unapproved death.
		if ( self::open_case_for( $post_id, 'Wikipedia records a ' . $person['dod'] . ' death; the record here is still living. Editor confirmation required.' ) ) {
			self::attach_source( $post_id, 'Wikipedia — ' . self::WIKI_LIST_TITLE, 'https://en.wikipedia.org/wiki/' . rawurlencode( self::WIKI_LIST_TITLE ), '' );
			++$stats['review_opened'];
			return;
		}
		++$stats['already_in_review'];
	}

	/* ---------------------------------------------------------------------
	 * Pipeline 2: RSS wire matching.
	 * ------------------------------------------------------------------- */

	/** Queue handler: process a bounded slice of candidate feed items. */
	public static function handle_wire_batch( array $payload ): array {
		$paused = self::paused_error();
		if ( $paused ) {
			return $paused;
		}
		global $wpdb;
		$cursor = (int) get_option( self::WIRE_CURSOR_OPTION, 0 );
		$season = Pick_Stats::season_in_play();
		$items  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.title, i.url, i.published_at, s.name AS source_name
				 FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id
				 WHERE i.classification = %s AND i.wire_state = '' AND i.id > %d
				   AND i.published_at >= %s AND i.published_at < %s
				 ORDER BY i.id ASC LIMIT %d",
				\Obitleague\Domain\Feed_Classifier::CANDIDATE,
				$cursor,
				$season . '-01-01 00:00:00',
				( $season + 1 ) . '-01-01 00:00:00',
				self::WIRE_BATCH
			)
		);
		if ( ! $items ) {
			// Sweep complete: rewind for the next round of feed polls.
			update_option( self::WIRE_CURSOR_OPTION, 0, false );
			return array( 'ok' => true, 'note' => 'Wire sweep complete.' );
		}

		$stats = self::fresh_stats( 'wire' );
		foreach ( $items as $item ) {
			update_option( self::WIRE_CURSOR_OPTION, (int) $item->id, false );
			$source_name = (string) ( $item->source_name ?: 'News feed' );
			$source_url  = (string) $item->url;
			$title       = (string) $item->title;
			$outcome     = self::attach( $title, $source_url, $source_name, (int) $item->id, $stats );
			$wpdb->update(
				$wpdb->prefix . 'obitleague_feed_items',
				array( 'wire_state' => $outcome ),
				array( 'id' => (int) $item->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
		self::save_stats( $stats );
		return array( 'ok' => true, 'items' => count( $items ) );
	}

	/**
	 * Match one wire story to a record and act on it. Returns the outcome
	 * label stored on the feed item so a story is only ever processed once.
	 *
	 * @param array<string,int> $stats Running tallies.
	 */
	private static function attach( string $title, string $source_url, string $source_name, int $item_id, array &$stats ): string {
		if ( '' === trim( $title ) ) {
			return 'no_title';
		}
		if ( '' === $source_url ) {
			++$stats['skipped_no_url'];
			return 'no_url';
		}
		$group = self::match_group( $title );
		if ( null === $group || '' === $group ) {
			++$stats['unmatched_title'];
			return 'no_name'; // No usable name: these stay visible in the discovery queue.
		}

		$found = self::person_for_group( $group );
		if ( $found ) {
			if ( self::has_death( $found['post_id'] ) ) {
				// Known 2026 death: the story is public reporting — attach it.
				if ( self::attach_source( $found['post_id'], $source_name, $source_url, (string) gmdate( 'Y-m-d H:i:s' ) ) ) {
					++$stats['sources_attached'];
					return 'attached';
				}
				++$stats['source_duplicates'];
				return 'duplicate';
			}
			// Living record: queue the Wikipedia confirmation pass.
			$enwiki = '' !== $found['enwiki'] ? $found['enwiki'] : self::discover( $found['post_id'] );
			if ( '' === $enwiki ) {
				self::flag( $found['post_id'], 'wire', 'Story matched by name, but the record has no Wikipedia article to confirm it against.' );
				++$stats['flagged_no_anchor'];
				return 'flagged_no_anchor';
			}
			Wiki_Request_Queue::enqueue(
				'death_wire_check',
				array(
					'post_id'     => $found['post_id'],
					'enwiki'      => $enwiki,
					'source_url'  => $source_url,
					'source_name' => $source_name,
				),
				'death-wire-' . $found['post_id']
			);
			++$stats['checks_queued'];
			return 'check_queued';
		}

		// No record yet: anchor to a Wikipedia article, then confirm before
		// creating anything.
		$enwiki_guess = self::wiki_search_title( $group );
		if ( '' === $enwiki_guess ) {
			++$stats['wire_no_anchor'];
			return 'no_anchor';
		}
		Wiki_Request_Queue::enqueue(
			'death_wire_check',
			array(
				'post_id'     => 0,
				'name'        => $group,
				'enwiki'      => $enwiki_guess,
				'source_url'  => $source_url,
				'source_name' => $source_name,
			),
			'death-wire-new-' . md5( $enwiki_guess )
		);
		++$stats['checks_queued'];
		return 'check_queued';
	}

	/** Queue handler: confirm one story against the subject's Wikipedia article. */
	public static function handle_wire_check( array $payload ): array {
		$paused = self::paused_error();
		if ( $paused ) {
			return $paused;
		}
		$post_id     = (int) ( $payload['post_id'] ?? 0 );
		$enwiki      = (string) ( $payload['enwiki'] ?? '' );
		$source_url  = (string) ( $payload['source_url'] ?? '' );
		$source_name = (string) ( $payload['source_name'] ?? '' );
		$stats       = self::fresh_stats( 'check' );

		if ( '' === $enwiki ) {
			return array( 'ok' => true, 'note' => 'No article to check.' );
		}
		$wikitext = self::wiki_article_wikitext( $enwiki );
		if ( is_wp_error( $wikitext ) ) {
			return $wikitext; // Parked on a rate limit; retried later.
		}
		$year = (int) gmdate( 'Y' );

		if ( ! self::wiki_death_year( $wikitext, $year ) ) {
			if ( $post_id > 0 ) {
				self::flag( $post_id, 'unconfirmable', 'A news story matched this record, but its Wikipedia article does not record a ' . $year . ' death. Needs a human check.' );
				++$stats['flagged_unconfirmable'];
			} else {
				++$stats['wire_new_not_dead'];
			}
			self::save_stats( $stats );
			return array( 'ok' => true, 'note' => 'No ' . $year . ' death in the article.' );
		}

		if ( $post_id > 0 ) {
			// Confirmed against Wikipedia: open the editorial case with the
			// story attached; the editors enter the exact date.
			if ( self::open_case_for( $post_id, 'Wire story ' . $source_url . ' matches this record and the Wikipedia article confirms a ' . $year . ' death. Editor confirmation required.' ) ) {
				self::attach_source( $post_id, $source_name, $source_url, (string) current_time( 'mysql', true ) );
				++$stats['review_opened'];
			} else {
				// Case already open or approved: make sure the story is kept.
				if ( self::attach_source( $post_id, $source_name, $source_url, (string) current_time( 'mysql', true ) ) ) {
					++$stats['sources_attached'];
				} else {
					++$stats['source_duplicates'];
				}
			}
			self::save_stats( $stats );
			return array( 'ok' => true, 'note' => 'Death confirmed against Wikipedia.' );
		}

		// New name: import the draft, then hand the editors the case.
		$qid      = self::discover_by_enwiki( $enwiki );
		$birth    = '' !== $qid ? self::birth_year_from_wikitext( $wikitext ) : '';
		$deathstr = self::death_date_from_wikitext( $wikitext, $year );
		if ( '' === $qid || '' === $birth ) {
			++$stats['wire_new_unconfirmed'];
			self::save_stats( $stats );
			return array( 'ok' => true, 'note' => 'Confirmed story but no importable identity.' );
		}
		try {
			$new_id = Import_Service::import_person(
				array(
					'qid'        => $qid,
					'name'       => (string) ( $payload['name'] ?? str_replace( '_', ' ', $enwiki ) ),
					'birth_date' => $birth,
					'death_date' => $deathstr,
					'enwiki'     => $enwiki,
				)
			);
		} catch ( \Throwable $e ) {
			++$stats['wire_new_unconfirmed'];
			self::save_stats( $stats );
			return array( 'ok' => true, 'note' => 'Import failed: ' . $e->getMessage() );
		}
		update_post_meta( $new_id, 'obit_death_wiki_name', $enwiki );
		self::open_case_for( $new_id, 'Wire story ' . $source_url . ' and the Wikipedia article confirm a ' . $year . ' death. Editor confirmation required.' );
		self::attach_source( $new_id, $source_name, $source_url, (string) current_time( 'mysql', true ) );
		++$stats['new_candidates'];
		self::save_stats( $stats );
		return array( 'ok' => true, 'note' => 'Imported ' . $enwiki . ' with an open review case.' );
	}

	/* ---------------------------------------------------------------------
	 * Matching and confirmation helpers.
	 * ------------------------------------------------------------------- */

	/**
	 * The subject group of a headline: the name before the dash/colon, with
	 * tail pieces (publications, sections, ellipses) stripped.
	 */
	public static function match_group( string $title ): ?string {
		$t = trim( preg_replace( '/\s+/u', ' ', $title ) ?? $title );
		$t = preg_replace( '/\s*[|·]\s*.*/u', '', $t ) ?? $t;
		if ( preg_match( '/^(.{3,191}?)\s+[—–-]\s+\S/u', $t, $m ) ) {
			$t = trim( $m[1] );
		} elseif ( preg_match( '/^(.{3,191}?):\s+\S/u', $t, $m ) ) {
			$t = trim( $m[1] );
		}
		$t = preg_replace( '/\s*\.{3,}$/u', '', $t ) ?? $t;
		$t = trim( $t );
		if ( '' === $t || mb_strlen( $t ) > 191 ) {
			return null;
		}
		return $t;
	}


	/**
	 * Find the site record a headline group refers to.
	 *
	 * @return array{post_id:int,enwiki:string}|null
	 */
	private static function person_for_group( string $group ): ?array {
		global $wpdb;
		// The Wikipedia name this record actually died under.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_death_wiki_name' AND meta_value = %s LIMIT 1",
				$group
			)
		);
		if ( $post_id ) {
			return array( 'post_id' => $post_id, 'enwiki' => '' );
		}
		// Exact citation title.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_enwiki' AND meta_value = %s LIMIT 1",
				$group
			)
		);
		if ( $post_id ) {
			return array( 'post_id' => $post_id, 'enwiki' => $group );
		}
		// Search Wikipedia for the article, then match its title.
		$search = self::wiki_search_title( $group );
		if ( '' !== $search ) {
			$post_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_enwiki' AND meta_value = %s LIMIT 1",
					$search
				)
			);
			if ( $post_id ) {
				return array( 'post_id' => $post_id, 'enwiki' => $search );
			}
		}
		return null;
	}

	private static function has_death( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, 'obit_death_date', true );
	}

	/** Open a review case unless one is already open or approved. */
	private static function open_case_for( int $post_id, string $note ): bool {
		$uuid = (string) get_post_meta( $post_id, 'obit_uuid', true );
		if ( '' === $uuid ) {
			return false;
		}
		if ( Review_Service::has_open_case( $uuid ) ) {
			return false;
		}
		try {
			Review_Service::open_case( $uuid, $note );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Attach public reporting to a death.
	 *
	 * Approved deaths take the source straight onto the public obit page;
	 * anything else stages it on the review case so it is there at approval.
	 */
	private static function attach_source( int $post_id, string $source_name, string $source_url, string $published_at ): bool {
		global $wpdb;
		$uuid = (string) get_post_meta( $post_id, 'obit_uuid', true );
		if ( '' === $uuid || '' === $source_url ) {
			return false;
		}
		$case = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}obitleague_review_cases
				 WHERE person_uuid = %s AND state IN ('approved', 'pending') ORDER BY (state = 'approved') DESC, id DESC LIMIT 1",
				$uuid
			)
		);
		if ( ! $case ) {
			return false;
		}
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}obitleague_death_sources WHERE case_id = %d AND source_url = %s LIMIT 1",
				$case,
				$source_url
			)
		);
		if ( $exists ) {
			return false;
		}
		$wpdb->insert(
			$wpdb->prefix . 'obitleague_death_sources',
			array(
				'case_id'      => $case,
				'source_name'  => mb_substr( sanitize_text_field( $source_name ), 0, 191 ),
				'source_url'   => esc_url_raw( $source_url ),
				'published_at' => $published_at,
				'attached_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return 1 === (int) $wpdb->rows_affected;
	}

	/** Stamp a human-check flag on a record (shown in the queues). */
	private static function flag( int $post_id, string $kind, string $note ): void {
		update_post_meta( $post_id, 'obit_death_flag', $kind );
		update_post_meta( $post_id, 'obit_death_flag_note', mb_substr( sanitize_text_field( $note ), 0, 500 ) );
		update_post_meta( $post_id, 'obit_death_flag_at', current_time( 'mysql', true ) );
	}

	/* ---------------------------------------------------------------------
	 * Wikimedia access (shared pause, global queue).
	 * ------------------------------------------------------------------- */

	private static function paused_error(): ?\WP_Error {
		$pause = Discovery_Service::rate_limit_pause_until();
		if ( $pause > time() ) {
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikimedia pause until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause ) ) );
		}
		return null;
	}

	/** One WDQS page (politeness handled by the caller's queue context). */
	private static function sparql( string $query ): array|\WP_Error {
		$response = wp_remote_get(
			'https://query.wikidata.org/sparql?format=json&query=' . rawurlencode( $query ),
			array(
				'timeout'    => 60,
				'user-agent' => self::USER_AGENT,
				'headers'    => array( 'Accept' => 'application/sparql-results+json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'obitleague_discovery_network', 'Wikidata could not be reached: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 429, 503 ), true ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after;
			$pause_until = Discovery_Service::note_rate_limit( $retry_after );
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikidata asked us to slow down until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ) );
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'obitleague_discovery_http', 'Wikidata returned HTTP ' . $code . '.' );
		}
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$cards = is_array( $body ) ? (array) ( $body['results']['bindings'] ?? array() ) : array();
		return array_map( static fn ( $b ): array => is_array( $b ) ? $b : array(), $cards );
	}

	private static function enwiki_title_from_uri( string $uri ): string {
		// https://en.wikipedia.org/wiki/Title → Title (URL-decoded).
		$marker = '/wiki/';
		$pos    = strpos( $uri, $marker );
		if ( false === $pos ) {
			return '';
		}
		return rawurldecode( substr( $uri, $pos + strlen( $marker ) ) );
	}

	/** Wikipedia search: the best article title for a name, or ''. */
	private static function wiki_search_title( string $term ): string {
		$response = wp_remote_get(
			'https://en.wikipedia.org/w/api.php?action=query&list=search&format=json&formatversion=2&srlimit=3&srsearch=' . rawurlencode( $term ),
			array( 'timeout' => 15, 'user-agent' => self::USER_AGENT )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return (string) ( $body['query']['search'][0]['title'] ?? '' );
	}

	/** Raw wikitext of one enwiki article ('' when missing). */
	private static function wiki_article_wikitext( string $title ): string|\WP_Error {
		$response = wp_remote_get(
			'https://en.wikipedia.org/w/api.php?action=query&prop=revisions&rvprop=content&rvslots=main&format=json&formatversion=2&titles=' . rawurlencode( $title ),
			array( 'timeout' => 20, 'user-agent' => self::USER_AGENT )
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'obitleague_discovery_network', 'Wikipedia could not be reached: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 429, 503 ), true ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after;
			$pause_until = Discovery_Service::note_rate_limit( $retry_after );
			return new \WP_Error( 'obitleague_discovery_paused', sprintf( 'Wikipedia asked us to slow down until %s UTC.', gmdate( 'Y-m-d H:i:s', $pause_until ) ) );
		}
		if ( 200 !== $code ) {
			return '';
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return (string) ( $body['query']['pages'][0]['revisions'][0]['slots']['main']['content'] ?? '' );
	}

	/**
	 * Does the wikitext record a death in the given year? Reads the
	 * death_date infobox field first, then plain-text death wording.
	 */
	public static function wiki_death_year( string $wikitext, int $year ): bool {
		if ( '' === $wikitext ) {
			return false;
		}
		if ( preg_match( '/\|\s*death_date\s*=\s*([^\n|]*)/i', $wikitext, $m ) ) {
			if ( preg_match( '/(^|[|\s])' . $year . '(?![0-9])/', $m[1] ) ) {
				return true;
			}
		}
		if ( preg_match( '/(?:died|death|passed away)[^.\n]{0,120}\b' . $year . '\b/i', $wikitext ) ) {
			return true;
		}
		return false;
	}

	/** First birth year found in the article text ('' when absent). */
	private static function birth_year_from_wikitext( string $wikitext ): string {
		if ( preg_match( '/\|\s*birth_date\s*=\s*([^\n|]*)/i', $wikitext, $m ) && preg_match( '/\b(1[89]\d{2}|20[0-2]\d)\b/', $m[1], $y ) ) {
			return $y[1];
		}
		if ( preg_match( '/\(born[^)]*?\b(1[89]\d{2}|20[0-2]\d)\b/i', $wikitext, $m2 ) ) {
			return $m2[1];
		}
		return '';
	}

	/** Best exact death date for the season year from the article ('' when absent). */
	private static function death_date_from_wikitext( string $wikitext, int $year ): string {
		if ( preg_match( '/\|\s*death_date\s*=\s*\{\{[^|}]*\|(' . $year . ')\|(\d{1,2})\|(\d{1,2})/i', $wikitext, $m ) ) {
			return sprintf( '%04d-%02d-%02d', $year, min( 12, max( 1, (int) $m[2] ) ), min( 31, max( 1, (int) $m[3] ) ) );
		}
		$months = 'January|February|March|April|May|June|July|August|September|October|November|December';
		if ( preg_match( '/\b(\d{1,2})\s+(' . $months . ')\s+' . $year . '\b/i', $wikitext, $m2 ) ) {
			$month = (int) ( array_search( ucfirst( strtolower( $m2[2] ) ), explode( '|', $months ), true ) + 1 );
			return sprintf( '%04d-%02d-%02d', $year, $month, min( 31, max( 1, (int) $m2[1] ) ) );
		}
		return (string) $year;
	}

	/** The Wikidata QID behind an enwiki title ('' when none). */
	private static function discover_by_enwiki( string $enwiki ): string {
		$response = wp_remote_get(
			'https://en.wikipedia.org/w/api.php?action=query&prop=pageprops&format=json&formatversion=2&titles=' . rawurlencode( $enwiki ),
			array( 'timeout' => 15, 'user-agent' => self::USER_AGENT )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$props = (array) ( $body['query']['pages'][0]['pageprops'] ?? array() );
		$qid   = (string) ( $props['wikibase_item'] ?? '' );
		return preg_match( '/^Q\d+$/', $qid ) ? $qid : '';
	}

	/** The stored identity anchor for a record (citation or QID lookup). */
	private static function discover( int $post_id ): string {
		$enwiki = trim( (string) get_post_meta( $post_id, 'obit_enwiki', true ) );
		if ( '' !== $enwiki ) {
			return $enwiki;
		}
		$qid = trim( (string) get_post_meta( $post_id, 'obit_qid', true ) );
		if ( '' === $qid ) {
			return '';
		}
		$entity = People_Sync::fetch_entities( array( $qid ) );
		$data   = (array) ( $entity[ $qid ] ?? array() );
		foreach ( (array) ( $data['sitelinks'] ?? array() ) as $site => $link ) {
			if ( 'enwiki' === $site || 'enwikiwiki' === $site ) {
				return (string) ( is_array( $link ) ? ( $link['title'] ?? '' ) : '' );
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Small utilities.
	 * ------------------------------------------------------------------- */

	/** '2026-09-14T00:00:00Z' → '2026-09-14'; precision placeholders drop. */
	private static function normalize_day( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-00-00T/', $iso, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-00T/', $iso, $m ) ) {
			return $m[1] . '-' . $m[2];
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})T/', $iso, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private static function next_month( string $month ): string {
		[ $y, $m ] = array_map( 'intval', explode( '-', $month ) );
		return $m === 12 ? ( ( $y + 1 ) . '-01' ) : sprintf( '%04d-%02d', $y, $m + 1 );
	}

	private static function fresh_stats( string $run ): array {
		$stats            = self::last_run();
		$stats['run']     = $run;
		$stats['run_at']  = current_time( 'mysql', true );
		$stats['confirmed_matched'] = 0;
		$stats['review_opened']     = 0;
		$stats['already_in_review'] = 0;
		$stats['new_candidates']    = 0;
		$stats['sources_attached']  = 0;
		$stats['source_duplicates'] = 0;
		$stats['checks_queued']     = 0;
		$stats['flagged_unconfirmable'] = 0;
		$stats['flagged_no_anchor'] = 0;
		$stats['skipped_no_url']    = 0;
		$stats['skipped_no_birth']  = 0;
		$stats['unmatched_title']   = 0;
		$stats['wire_no_anchor']    = 0;
		$stats['wire_new_not_dead'] = 0;
		$stats['wire_new_unconfirmed'] = 0;
		$stats['enwiki_repaired']   = 0;
		return $stats;
	}

	private static function save_stats( array $stats ): void {
		update_option( self::LAST_RUN_OPTION, $stats, false );
	}
}

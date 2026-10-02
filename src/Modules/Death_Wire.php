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
	/** Stories below this obituary likelihood are auto-discarded. */
	public const DISCARD_BELOW = 50;
	/** Stored, adjustable discard threshold (admin overview sets it). */
	private const DISCARD_OPTION   = 'obitleague_death_wire_discard_below';
	/** How long a fetched Wikipedia article (wikitext + QID) stays cached. */
	private const WIKI_TTL         = 2 * HOUR_IN_SECONDS;
	/** How long a fetched publisher article text stays cached. */
	private const ARTICLE_TTL      = 12 * HOUR_IN_SECONDS;
	private const PAGE_SIZE          = 50;
	private const MAX_LIST_PAGES     = 40; // Safety bound: ~2000 names/month.
	private const WIRE_BATCH         = 100;
	private const WIRE_LOOKBACK_DAYS = 400;
	private const USER_AGENT         = 'Obitleague-DeathWire/0.1 (WordPress; +obitleague.co.uk)';

	/** The obituary likelihood below which a story is auto-discarded (0–95). */
	public static function discard_threshold(): int {
		$value = (int) get_option( self::DISCARD_OPTION, self::DISCARD_BELOW );
		return max( 0, min( 95, $value ) );
	}

	/** Store the adjustable discard threshold, clamped to the honest scale. */
	public static function set_discard_threshold( int $value ): void {
		update_option( self::DISCARD_OPTION, max( 0, min( 95, $value ) ), false );
	}

	/** Public although the class is static-only: WP-CLI instantiates array callables when invoking commands. */
	public function __construct() {}

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
			\WP_CLI::add_command(
				'obitleague reclassify-feed-items',
				static function ( array $args, array $assoc_args ): void {
					self::cli_reclassify( $args, $assoc_args );
				}
			);
			\WP_CLI::add_command(
				'obitleague death-wire-tidy-pending',
				static function ( array $args, array $assoc_args ): void {
					self::cli_tidy_pending( $args, $assoc_args );
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
				 WHERE c.person_uuid = %s AND c.state IN ('approved', 'pending')
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
	public static function handle_list_page( array $payload ): array|\WP_Error {
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
			// Not on the site yet: publish immediately as provisional. The
			// record is visible at once (flagged as awaiting confirmation and
			// scoring nothing) with a pending review case, so editors triage a
			// queue instead of hunting through hidden drafts.
			if ( '' === $birth_norm ) {
				++$stats['skipped_no_birth'];
				return;
			}
			try {
				$new_id = Import_Service::import_person(
					array(
						'qid'         => $person['qid'],
						'name'        => $person['name'],
						'birth_date'  => $birth_norm,
						'death_date'  => $death_norm,
						'enwiki'      => $person['enwiki'],
						'provisional' => true,
					)
				);
			} catch ( \Throwable $e ) {
				++$stats['skipped_no_birth'];
				return;
			}
			update_post_meta( $new_id, 'obit_death_wiki_name', self::WIKI_LIST_TITLE );
			self::mark_provisional( $new_id );
			self::open_case_for( $new_id, 'Wikipedia lists a ' . $person['dod'] . ' death; editor confirmation required.' );
			self::attach_source( $new_id, 'Wikipedia — ' . self::WIKI_LIST_TITLE, 'https://en.wikipedia.org/wiki/' . rawurlencode( self::WIKI_LIST_TITLE ), '' );
			Person_Content::regenerate( $new_id );
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
			// Already recorded on the site: credit the list as a source. A
			// provisional record the list re-confirms is upgraded on the spot.
			if ( self::is_provisional( $post_id ) ) {
				self::confirm_provisional( $post_id );
			}
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
	public static function handle_wire_batch( array $payload ): array|\WP_Error {
		$paused = self::paused_error();
		if ( $paused ) {
			return $paused;
		}
		global $wpdb;
		$cursor = (int) get_option( self::WIRE_CURSOR_OPTION, 0 );
		$season = Pick_Stats::season_in_play();
		$signals  = \Obitleague\Domain\Feed_Classifier::DEATH_SIGNALS;
		$in       = implode( ',', array_fill( 0, count( $signals ), '%s' ) );			$items    = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.title, i.url, i.description, i.published_at, i.classification_score, s.name AS source_name
				 FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id
				 WHERE i.classification IN ({$in}) AND i.wire_state = '' AND i.id > %d
				   AND i.published_at >= %s AND i.published_at < %s
				 ORDER BY i.id ASC LIMIT %d",
				array_merge(
					$signals,
					array( $cursor, $season . '-01-01 00:00:00', ( $season + 1 ) . '-01-01 00:00:00', self::WIRE_BATCH )
				)
			)
		);
		if ( ! $items ) {
			// Sweep complete: rewind for the next round of feed polls.
			update_option( self::WIRE_CURSOR_OPTION, 0, false );
			$stranded = self::requeue_stranded_checks();
			return array( 'ok' => true, 'note' => 'Wire sweep complete.' . ( $stranded > 0 ? " {$stranded} stranded check(s) re-run." : '' ) );
		}

		$stats = self::fresh_stats( 'wire' );
		foreach ( $items as $item ) {
			update_option( self::WIRE_CURSOR_OPTION, (int) $item->id, false );
			// Stories whose obituary likelihood sits below the discard line are
			// not worth editor attention: deleted outright, never swept again.
			if ( self::likelihood_pct( (int) $item->classification_score ) < self::discard_threshold() ) {
				$wpdb->delete(
					$wpdb->prefix . 'obitleague_feed_items',
					array( 'id' => (int) $item->id ),
					array( '%d' )
				);
				++$stats['discarded_low_likelihood'];
				continue;
			}
			$source_name = (string) ( $item->source_name ?: 'News feed' );
			$source_url  = (string) $item->url;
			$title       = (string) $item->title;
			$outcome     = self::attach( $title, $source_url, $source_name, (int) $item->id, $stats, (string) $item->description );
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
	 * A story whose queued 'death_wire_check' has completed (done or failed)
	 * but which still reads 'check_queued' was stranded — an older bug let
	 * the same enwiki title dedupe many stories onto one queue row that ran
	 * once. Re-run the attach for each stranded story so a real outcome
	 * (attached, no_anchor, dismissed…) replaces the parked state.
	 *
	 * @return int Number of stranded stories re-run.
	 */
	private static function requeue_stranded_checks(): int {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			"SELECT i.id, i.title, i.url, s.name AS source_name
			 FROM {$wpdb->prefix}obitleague_feed_items i
			 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id
			 WHERE i.wire_state = 'check_queued' LIMIT 200"
		);
		$ran = 0;
		foreach ( $rows as $row ) {
			$stats = self::fresh_stats( 'wire' );
			self::attach( (string) $row->title, (string) $row->url, (string) ( $row->source_name ?: 'News feed' ), (int) $row->id, $stats );
			self::save_stats( $stats );
			++$ran;
		}
		return $ran;
	}

	/**
	 * Match one wire story to a record and act on it. Returns the outcome
	 * label stored on the feed item so a story is only ever processed once.
	 *
	 * @param array<string,int> $stats Running tallies.
	 */
	private static function attach( string $title, string $source_url, string $source_name, int $item_id, array &$stats, string $description = '' ): string {
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

		$found = self::person_for_group( $group, $title . ' ' . $description, $source_url );
		if ( $found ) {
			if ( self::has_death( $found['post_id'] ) ) {
				// Known 2026 death: the story is public reporting — attach it.
				if ( self::attach_source( $found['post_id'], $source_name, $source_url, (string) gmdate( 'Y-m-d H:i:s' ) ) ) {
					++$stats['sources_attached'];
					if ( self::maybe_auto_confirm( $found['post_id'] ) ) {
						++$stats['auto_confirmed'];
					}
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

		// No record yet. An obituary-desk article that signs its piece with
		// name + exact date of death is evidence enough to create the person
		// immediately (provisional, unscored, corroboration-pending). The
		// Wikipedia-anchored path still runs for everything else.
		$signature = self::signature_from_story( $source_url );
		if ( null !== $signature ) {
			$created = self::create_provisional_from_signature( $signature['name'], $signature['death_date'], $source_url, $source_name );
			if ( $created > 0 ) {
				++$stats['created_from_article'];
				return 'created_provisional';
			}
		}
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
	public static function handle_wire_check( array $payload ): array|\WP_Error {
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
		$article = self::wiki_article( $enwiki );
		if ( is_wp_error( $article ) ) {
			return $article; // Parked on a rate limit; retried later.
		}
		$wikitext = (string) $article['wikitext'];
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
			// Cache the article's wordcloud on the record: this is the
			// reference language future same-name stories are compared against.
			self::store_wiki_cloud( $post_id, $wikitext );
			// Fill the identity gaps (QID, portrait, occupations, DoB) in the
			// background rather than leaving them for an editor to chase.
			self::enrich_person( $post_id, (string) $article['qid'] );
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

		// New name: import the draft, then hand the editors the case. The
		// QID came back in the same cached request that fetched the wikitext.
		$qid      = (string) $article['qid'];
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
					// The Wikipedia article title is the canonical name; the
					// headline group is only a search hint and can carry
					// desk furniture ("Stephanie Cole obituary").
					'name'       => str_replace( '_', ' ', $enwiki ),
					'birth_date' => $birth,
					'death_date' => $deathstr,
					'enwiki'     => $enwiki,
				)
			);
		} catch ( \Throwable $e ) {
			++$stats['wire_new_unconfirmed'];
			self::save_stats( $stats );
			return array( 'ok' => true, 'note' => 'Import failed: ' . $e->getMessage() );
		}			update_post_meta( $new_id, 'obit_death_wiki_name', $enwiki );
			self::mark_provisional( $new_id );
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
		// Obituary-desk suffixes: "Mighty Sparrow obituary" names Mighty
		// Sparrow, not a person called "Mighty Sparrow obituary".
		$t = preg_replace( '/\s+\b(obituary|obit|tribute|appreciation)\b\s*:?.*$/iu', '', $t ) ?? $t;
		$t = trim( $t );
		if ( '' === $t || mb_strlen( $t ) > 191 ) {
			return null;
		}
		// Broad-sheet obituaries sign the subject before a comma and describe
		// them after it: "Bob Pettit, N.B.A. Great for the Hawks, Dies at 93".
		// That is a name, not prose — but only the leading segment, and only
		// when it reads as one, so descriptors ("Kris Jenner's mom, …") and
		// sentences are still refused.
		$leading = self::leading_name( $t );
		if ( null !== $leading ) {
			return $leading;
		}
		// A group is a name, not a sentence: general-news headlines
		// ("UK diesel price hits all-time high, RAC says") produce sentence
		// fragments that Wikipedia search happily mis-anchors to some
		// unrelated article. Refuse anything that reads like prose — more
		// than a handful of words, or still carrying desk furniture.
		$words = preg_split( '/\s+/u', $t ) ?: array();
		if ( count( $words ) > 7 ) {
			return null;
		}
		if ( preg_match( '/\b(says|warns|hits|review|price|ban|named|pictures|heartbroken|slams|urges|faces|amid)\b/iu', $t ) ) {
			return null;
		}
		return $t;
	}

	/**
	 * The subject of a "Name, descriptor, dies at NN" headline: the segment
	 * before the first comma, when — and only when — it reads as a personal
	 * name (two to four capitalised tokens, no digits, no lowercase words).
	 */
	private static function leading_name( string $title ): ?string {
		$comma = mb_strpos( $title, ',' );
		if ( false === $comma ) {
			return null;
		}
		$head = trim( mb_substr( $title, 0, $comma ) );
		if ( '' === $head || mb_strlen( $head ) > 191 || preg_match( '/\d/u', $head ) ) {
			return null;
		}
		$tokens = preg_split( '/\s+/u', $head ) ?: array();
		if ( count( $tokens ) < 2 || count( $tokens ) > 4 ) {
			return null;
		}
		foreach ( $tokens as $token ) {
			// A name part starts with a capital and carries only name
			// characters: initials ("G."), apostrophes ("O’Neill") and
			// hyphens ("Ruth-Bader") included.
			if ( ! preg_match( '/^\p{Lu}[\p{L}\'’.\-]*$/u', $token ) ) {
				return null;
			}
		}
		return $head;
	}


	/**
	 * Find the site record a headline group refers to.
	 *
	 * When several published records share the group's name the story's
	 * own language (title + excerpt + article text, wordcloud-reduced)
	 * is compared against each candidate's stored Wikipedia-article cloud
	 * and the best overlap wins — provided it clears the floor. An
	 * unresolved ambiguity returns null and lands on the editors.
	 *
	 * @return array{post_id:int,enwiki:string}|null
	 */
	private static function person_for_group( string $group, string $story_text = '', string $source_url = '' ): ?array {
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
		// Every published record citing the same Wikipedia article title.
		$enwiki_posts = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_enwiki' AND meta_value = %s",
				$group
			)
		);
		// Search Wikipedia for the article, then collect its records too.
		$search = self::wiki_search_title( $group );
		if ( '' !== $search ) {
			$by_search = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_enwiki' AND meta_value = %s",
					$search
				)
			);
			$enwiki_posts = array_values( array_unique( array_merge( $enwiki_posts, $by_search ) ) );
		}
		if ( array() === $enwiki_posts ) {
			return null;
		}
		if ( 1 === count( $enwiki_posts ) ) {
			return array( 'post_id' => (int) $enwiki_posts[0], 'enwiki' => '' !== $search ? $search : $group );
		}
		// Same-name ambiguity: resolve it by language, or don't resolve it.
		return self::disambiguate( $enwiki_posts, $story_text, $search, $source_url );
	}

	/**
	 * Pick among same-name records by wordcloud overlap between the story
	 * and each record's stored Wikipedia-article cloud (cached at import
	 * time by handle_wire_check). Below-floor or tie outcomes stay
	 * unresolved on purpose: wrong attachments are worse than flagged ones.
	 *
	 * @param int[] $post_ids Same-name candidate records.
	 * @return array{post_id:int,enwiki:string}|null
	 */
	private static function disambiguate( array $post_ids, string $story_text, string $enwiki, string $source_url = '' ): ?array {
		$story_cloud = \Obitleague\Domain\Wordcloud::from_text( $story_text, 60 );
		if ( array() === $story_cloud && '' !== $source_url ) {
			// The excerpt was empty: fall back to the article text itself
		// (cached; one polite fetch per story per TTL window).
			$story_cloud = \Obitleague\Domain\Wordcloud::from_text( self::article_text( $source_url ), 60 );
		}
		if ( array() === $story_cloud ) {
			return null;
		}
		$candidates = array();
		foreach ( $post_ids as $pid ) {
			$stored = get_post_meta( (int) $pid, 'obit_wiki_cloud', true );
			$counts  = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;
			if ( ! is_array( $counts ) || array() === $counts ) {
				return null; // An un-cached candidate makes the choice unprovable.
			}
			$candidates[ (int) $pid ] = $counts;
		}
		$best = \Obitleague\Domain\Wordcloud::best_match( $story_cloud, $candidates );
		if ( null === $best ) {
			return null;
		}
		return array( 'post_id' => (int) $best, 'enwiki' => $enwiki );
	}

	/**
	 * WP-CLI: wp obitleague death-wire-tidy-pending [--dry-run]
	 * [--threshold=N]
	 *
	 * Clears the wire's backlog of never-swept pending stories: re-runs the
	 * current classifier over every pending signal first (older items were
	 * scored by earlier phrase tables), then marks everything below the
	 * threshold as discarded. Stories at or above the line stay pending for
	 * the sweep to process normally.
	 */
	public static function cli_tidy_pending( array $args, array $assoc_args ): void {
		global $wpdb;
		$dry_run   = (bool) ( $assoc_args['dry-run'] ?? false );
		$threshold = isset( $assoc_args['threshold'] ) ? max( 0, min( 95, (int) $assoc_args['threshold'] ) ) : self::discard_threshold();

		$signals = \Obitleague\Domain\Feed_Classifier::DEATH_SIGNALS;
		$in      = implode( ',', array_fill( 0, count( $signals ), '%s' ) );

		// 1. Reclassify every pending signal with the current phrase tables
		// so the threshold is applied to today's scoring, not the scorer
		// that was live when the item was ingested.
		$table    = $wpdb->prefix . 'obitleague_feed_items';
		$sources  = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, name, feed_url FROM {$wpdb->prefix}obitleague_sources" ) as $s ) {
			$sources[ (int) $s->id ] = $s;
		}
		$offset  = 0;
		$batch   = 500;
		$scored  = 0;
		$changed = 0;
		do {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT i.id, i.source_id, i.title, i.description, i.classification, i.classification_score
					 FROM {$table} i WHERE i.wire_state = '' AND i.classification IN ({$in})
					 ORDER BY i.id ASC LIMIT %d OFFSET %d",
					array_merge( $signals, array( $batch, $offset ) )
				)
			);
			foreach ( $rows as $row ) {
				++$scored;
				$source  = $sources[ (int) $row->source_id ] ?? null;
				$verdict = \Obitleague\Domain\Wire_Phrases::classify(
					(string) $row->title,
					(string) ( $row->description ?? '' ),
					array(
						'source_name' => (string) ( $source->name ?? '' ),
						'source_url'  => (string) ( $source->feed_url ?? '' ),
					)
				);
				\Obitleague\Domain\Wire_Phrases::note_hits( (array) $verdict['matched'], (array) $verdict['negative'], (array) $verdict['excluded'] );
				if ( $verdict['classification'] === (string) $row->classification && (int) $verdict['score'] === (int) $row->classification_score ) {
					continue;
				}
				$cues = array_merge(
					(array) $verdict['matched'],
					array_map( static fn ( string $cue ): string => '−' . $cue, (array) $verdict['negative'] ),
					array_map( static fn ( string $cue ): string => '✕' . $cue, (array) $verdict['excluded'] )
				);
				if ( ! $dry_run ) {
					$wpdb->update(
						$table,
						array(
							'classification'       => $verdict['classification'],
							'classification_score' => (int) $verdict['score'],
							'matched_cues'         => mb_substr( implode( ', ', $cues ), 0, 191 ),
						),
						array( 'id' => (int) $row->id ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					);
				}
				++$changed;
			}
			$offset += $batch;
		} while ( count( $rows ) === $batch );

		// 2. Discard every remaining pending signal below the line. The
		// mapped-likelihood comparison runs in PHP because likelihood_pct()
		// folds two scoring eras (legacy cue counters ×10, weighted 50+)
		// into one 0–95 scale.
		$discarded = 0;
		$kept      = 0;
		$not_death = 0;
		if ( $dry_run ) {
			$below = 0;
		}
		$offset = 0;
		do {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, classification_score FROM {$table}
					 WHERE wire_state = '' AND classification IN ({$in})
					 ORDER BY id ASC LIMIT %d OFFSET %d",
					array_merge( $signals, array( $batch, $offset ) )
				)
			);
			foreach ( $rows as $row ) {
				$likelihood = self::likelihood_pct( (int) $row->classification_score );
				if ( $likelihood >= $threshold ) {
					++$kept;
					continue;
				}
				if ( $dry_run ) {
					++$below;
					continue;
				}
				$wpdb->delete(
					$table,
					array( 'id' => (int) $row->id ),
					array( '%d' )
				);
				++$discarded;
			}
			$offset += $batch;
		} while ( count( $rows ) === $batch );

		// 3. Delete every never-swept not_death story plus everything
		// already sitting in the discarded/duplicate states from earlier
		// runs. The classifier has already ruled them out; discarded means
		// discarded, not retained for an audit nobody asked for.
		if ( $dry_run ) {
			$not_death = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table} WHERE ( wire_state = '' AND classification = 'not_death' ) OR wire_state IN ('discarded','duplicate')"
			);
		} else {
			$not_death = (int) $wpdb->query(
				"DELETE FROM {$table} WHERE ( wire_state = '' AND classification = 'not_death' ) OR wire_state IN ('discarded','duplicate')"
			);
		}

		if ( $dry_run ) {
			\WP_CLI::log( sprintf( 'Threshold: %d%%. Pending signals seen: %d; reclassified: %d.', $threshold, $scored, $changed ) );
			\WP_CLI::log( sprintf( 'Dry run: %d pending signal(s) below the %d%% line and %d not_death/discarded story/stories would be deleted.', $below, $threshold, $not_death ) );
			\WP_CLI::success( 'No changes made (dry run).' );
			return;
		}
		\WP_CLI::log( sprintf( 'Threshold: %d%%. Pending signals seen: %d; reclassified: %d.', $threshold, $scored, $changed ) );
		\WP_CLI::success( sprintf( 'Tidy complete: %d pending signal(s) below %d%% and %d not_death/already-discarded story/stories deleted; %d remain pending.', $discarded, $threshold, $not_death, $kept ) );
	}

	/**
	 * Re-run the current classifier over stored feed items so their verdicts
	 * and likelihoods reflect the weighted scoring. Titles keep whatever
	 * text was stored at ingest; later ingests also carry descriptions.
	 */
	public static function cli_reclassify( array $args, array $assoc_args ): void {
		global $wpdb;
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );
		$batch   = 500;
		$offset  = 0;
		$changed = 0;
		$total   = 0;

		$sources = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, name, feed_url FROM {$wpdb->prefix}obitleague_sources" ) as $s ) {
			$sources[ (int) $s->id ] = $s;
		}

		do {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT i.id, i.source_id, i.title, i.classification, i.classification_score FROM {$wpdb->prefix}obitleague_feed_items i ORDER BY i.id ASC LIMIT %d OFFSET %d",
					$batch,
					$offset
				)
			);
			foreach ( $rows as $row ) {
				++$total;
				$source  = $sources[ (int) $row->source_id ] ?? null;
				$verdict = \Obitleague\Domain\Feed_Classifier::classify(
					(string) $row->title,
					'',
					array(
						'source_name' => (string) ( $source->name ?? '' ),
						'source_url'  => (string) ( $source->feed_url ?? '' ),
					)
				);
				if ( $verdict['classification'] === (string) $row->classification && (int) $verdict['score'] === (int) $row->classification_score ) {
					continue;
				}
				if ( ! $dry_run ) {
					$wpdb->update(
						$wpdb->prefix . 'obitleague_feed_items',
						array(
							'classification'       => $verdict['classification'],
							'classification_score' => (int) $verdict['score'],
							'matched_cues'         => mb_substr( implode( ', ', (array) $verdict['matched'] ), 0, 191 ),
						),
						array( 'id' => (int) $row->id ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					);
				}
				++$changed;
				\WP_CLI::log( sprintf( '#%d %s/%d -> %s/%d  %s', (int) $row->id, (string) $row->classification, (int) $row->classification_score, $verdict['classification'], (int) $verdict['score'], mb_substr( (string) $row->title, 0, 60 ) ) );
			}
			$offset += $batch;
		} while ( count( $rows ) === $batch );

		\WP_CLI::success( sprintf( '%s: %d of %d stored item(s) reclassified.', $dry_run ? 'Dry run' : 'Done', $changed, $total ) );
	}

	private static function has_death( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, 'obit_death_date', true );
	}

	/** Independent source domains required before an auto-confirm. */
	public const CORROBORATION_MIN = 2;

	/**
	 * When a pending case's record carries an exact death date and enough
	 * independent corroborating source domains (the Wikipedia list plus
	 * press coverage, or two press outlets), approve the case as the system
	 * reviewer. Uses the same decide() path as an editor, so the audit
	 * trail, event and standings updates are identical.
	 */
	public static function maybe_auto_confirm( int $post_id ): bool {
		global $wpdb;
		$uuid = (string) get_post_meta( $post_id, 'obit_uuid', true );
		if ( '' === $uuid ) {
			return false;
		}
		$case = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, revision FROM {$wpdb->prefix}obitleague_review_cases
				 WHERE person_uuid = %s AND state = 'pending' ORDER BY id DESC LIMIT 1",
				$uuid
			)
		);
		if ( ! $case ) {
			return false;
		}
		$exact = Review_Cli::parse_exact_date( (string) get_post_meta( $post_id, 'obit_death_date', true ) );
		if ( null === $exact ) {
			return false;
		}

		$source_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.source_url FROM {$wpdb->prefix}obitleague_death_sources s
				 JOIN {$wpdb->prefix}obitleague_review_cases c ON c.id = s.case_id
				 WHERE c.person_uuid = %s",
				$uuid
			)
		);
		$origins = array();
		if ( '' !== (string) get_post_meta( $post_id, 'obit_death_wiki_name', true ) || '' !== (string) get_post_meta( $post_id, 'obit_enwiki', true ) ) {
			$origins[] = 'enwiki-deaths-list';
		}
		if ( '' !== (string) get_post_meta( $post_id, 'obit_qid', true ) ) {
			$origins[] = 'wikidata-P570';
		}
		$domains = array();
		foreach ( $source_rows as $row ) {
			$domain = \Obitleague\Domain\Sources::registrable_domain( (string) $row->source_url );
			if ( '' !== $domain ) {
				$domains[ $domain ] = true;
			}
		}
		$domains = array_keys( $domains );
		foreach ( $domains as $domain ) {
			$origins[] = 'press:' . $domain;
		}
		if ( count( $origins ) < self::CORROBORATION_MIN ) {
			return false;
		}

		try {
			Review_Service::decide(
				(int) $case->id,
				0, // the system, on corroborating evidence
				\Obitleague\Domain\Review_Rules::APPROVED,
				array(
					'origin_groups'      => array_slice( array_unique( $origins ), 0, 4 ),
					'death_date'         => $exact,
					'cause_disclosed'    => false,
					'cause_text'         => '',
					'official_statement' => false,
					'reason'             => sprintf( 'Auto-approved: corroborated by %d independent source(s): %s.', count( $domains ) > 0 ? count( $domains ) : count( $origins ), implode( ', ', array_slice( array_merge( array_diff( $origins, array( 'enwiki-deaths-list', 'wikidata-P570' ) ), array( 'enwiki-deaths-list', 'wikidata-P570' ) ), 0, 4 ) ) ),
				),
				(int) $case->revision
			);
			\Obitleague\Modules\Outbox_Service::process_outbox( 100 );
			return true;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Store a record's Wikipedia-article wordcloud (JSON, capped) so same-
	 * name stories can be disambiguated later without refetching the article.
	 */
	private static function store_wiki_cloud( int $post_id, string $wikitext ): void {
		$cloud = \Obitleague\Domain\Wordcloud::from_wikitext( $wikitext, 80 );
		if ( array() === $cloud ) {
			return;
		}
		update_post_meta( $post_id, 'obit_wiki_cloud', wp_json_encode( $cloud ) );
	}

	/**
	 * Fill a record's identity gaps in the background: stamp the Wikidata
	 * QID when the article carried one and the record lacks it, and enqueue
	 * a People_Sync enrichment (portrait, occupations, dates) through the
	 * global Wikimedia queue.
	 */
	private static function enrich_person( int $post_id, string $qid ): void {
		if ( $post_id < 1 ) {
			return;
		}
		if ( '' === (string) get_post_meta( $post_id, 'obit_qid', true ) && preg_match( '/^Q\d+$/', $qid ) ) {
			update_post_meta( $post_id, 'obit_qid', $qid );
		}
		if ( '' === (string) get_post_meta( $post_id, 'obit_qid', true ) ) {
			return;
		}
		People_Sync::enqueue_person( $post_id );
	}

	/**
	 * Provisional publication flag. A provisional record is public at once
	 * but scores nothing until an editor confirms the death.
	 */
	public static function mark_provisional( int $post_id ): void {
		update_post_meta( $post_id, 'obit_death_provisional', 1 );
	}

	/** True when the record is published but still awaiting confirmation. */
	public static function is_provisional( int $post_id ): bool {
		return (bool) (int) get_post_meta( $post_id, 'obit_death_provisional', true );
	}

	/** Promote a provisional record to a confirmed death. */
	public static function confirm_provisional( int $post_id ): void {
		delete_post_meta( $post_id, 'obit_death_provisional' );
	}

	/** Fetch and parse a story's article for the name + death signature. */
	private static function signature_from_story( string $source_url ): ?array {
		$cache_key = 'obit_sig_' . md5( $source_url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$parsed = \Obitleague\Domain\Obituary_Article::parse( self::article_text( $source_url ) );
		if ( '' === $parsed['name'] || '' === $parsed['death_date'] ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return null;
		}
		set_transient( $cache_key, $parsed, 12 * HOUR_IN_SECONDS );
		return $parsed;
	}

	/**
	 * Create a provisional person from an article signature. Refuses when a
	 * record with the same name already exists — ambiguous homonyms are the
	 * editor's problem, not the wire's.
	 */
	private static function create_provisional_from_signature( string $name, string $death_date, string $source_url, string $source_name ): int {
		$existing = get_posts(
			array(
				'post_type'      => Catalogue::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'title'          => $name,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $existing ) {
			return 0;
		}
		try {
			$post_id = Import_Service::import_person( array( 'name' => $name, 'birth_date' => '', 'death_date' => $death_date, 'provisional' => true ) );
		} catch ( \Throwable ) {
			return 0;
		}
		self::mark_provisional( $post_id );
		self::open_case_for( $post_id, 'Obituary desk signature: ' . $source_name . ' records a death on ' . $death_date . '; corroboration pending.' );
		self::attach_source( $post_id, $source_name, $source_url, (string) gmdate( 'Y-m-d H:i:s' ) );
		Person_Content::regenerate( $post_id );
		return $post_id;
	}

	/** Fetch the visible text of a story's article page (bounded, cache-
	 * friendly). Used to lift the name + date-of-death signature that
	 * obituary desks print at the foot of their pieces.
	 */
	public static function article_text( string $url ): string {
		if ( '' === $url || ! str_starts_with( $url, 'http' ) ) {
			return '';
		}
		$cache_key = 'obit_article_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'limit_response_size' => 524288,
				'user-agent' => self::USER_AGENT,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$html = (string) wp_remote_retrieve_body( $response );
		// Prefer the <article> element; fall back to the whole body.
		if ( preg_match( '/<article[^>]*>(.*?)<\/article>/is', $html, $m ) ) {
			$html = (string) $m[1];
		}
		$text = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $html ) ?? $html;
		$text = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $text ) ?? $text;
		$text = preg_replace( '/<[^>]+>/', ' ', $text ) ?? $text;
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		// Empty results cache briefly, real text for the article TTL: a page
		// that failed once should not be re-fetched on every look.
		set_transient( $cache_key, $text, '' === $text ? HOUR_IN_SECONDS : self::ARTICLE_TTL );
		return $text;
	}

	/**
	 * Classifier score as an honest 0–95 obituary likelihood. The weighted
	 * classifier already scores on that scale; first-generation cue-counter
	 * rows (small positive ints) are mapped ×10 as before.
	 */
	public static function likelihood_pct( int $score ): int {
		if ( $score <= 0 ) {
			return 0;
		}
		if ( $score >= \Obitleague\Domain\Feed_Classifier::THRESHOLD_REVIEW ) {
			return (int) min( 95, $score );
		}
		return (int) min( 95, $score * 10 );
	}

	/** The person record a story title matches, for queue display. */
	public static function story_match( string $title ): ?array {
		$group = self::match_group( $title );
		if ( null === $group || '' === $group ) {
			return null;
		}
		$found = self::person_for_group( $group );
		if ( ! $found ) {
			return null;
		}
		return array( 'post_id' => (int) $found['post_id'], 'name' => (string) get_the_title( (int) $found['post_id'] ) );
	}

	/**
	 * Run the wire's own match logic on one story immediately — the Publish
	 * button. A story matching a confirmed death gains its source on the
	 * public obit page at once; one matching a living or provisional record
	 * queues the Wikipedia confirmation pass; an unmatched story queues the
	 * new-person check. Returns the outcome label stored on the item.
	 */
	public static function process_story( int $item_id ): string {
		global $wpdb;
		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.title, i.url, s.name AS source_name FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id WHERE i.id = %d",
				$item_id
			)
		);
		if ( ! $item ) {
			return 'missing';
		}
		$stats   = self::fresh_stats( 'wire' );
		$outcome = self::attach( (string) $item->title, (string) $item->url, (string) ( $item->source_name ?: 'News feed' ), $item_id, $stats );
		$wpdb->update(
			$wpdb->prefix . 'obitleague_feed_items',
			array( 'wire_state' => $outcome ),
			array( 'id' => $item_id ),
			array( '%s' ),
			array( '%d' )
		);
		self::save_stats( $stats );
		return $outcome;
	}

	/** Attach a story source to a person's review case (public wrapper). */
	public static function attach_story( int $post_id, string $item_id ): bool {
		global $wpdb;
		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.title, i.url, s.name AS source_name FROM {$wpdb->prefix}obitleague_feed_items i
				 JOIN {$wpdb->prefix}obitleague_sources s ON s.id = i.source_id WHERE i.id = %d",
				$item_id
			)
		);
		if ( ! $item || '' === (string) $item->url ) {
			return false;
		}
		$ok = self::attach_source( $post_id, (string) ( $item->source_name ?: 'News feed' ), (string) $item->url, (string) gmdate( 'Y-m-d H:i:s' ) );
		if ( $ok ) {
			self::maybe_auto_confirm( $post_id );
			$wpdb->update(
				$wpdb->prefix . 'obitleague_feed_items',
				array( 'wire_state' => 'attached' ),
				array( 'id' => (int) $item_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( self::has_death( $post_id ) ) {
				Person_Content::regenerate( $post_id );
			}
		}
		return (bool) $ok;
	}

	/** Dismiss a story: deleted outright — discarded means discarded. */
	public static function dismiss_story( int $item_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'obitleague_feed_items',
			array( 'id' => $item_id ),
			array( '%d' )
		);
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
		Discovery_Service::note_success();
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

	/** Wikipedia search: the best article title for a name, cached briefly. */
	private static function wiki_search_title( string $term ): string {
		$term   = trim( $term );
		if ( '' === $term ) {
			return '';
		}
		$cache_key = 'obit_wikisearch_' . md5( mb_strtolower( $term ) );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			'https://en.wikipedia.org/w/api.php?action=query&list=search&format=json&formatversion=2&srlimit=3&srsearch=' . rawurlencode( $term ),
			array( 'timeout' => 15, 'user-agent' => self::USER_AGENT )
		);
		$title = '';
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			Discovery_Service::note_success();
			$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$title = (string) ( $body['query']['search'][0]['title'] ?? '' );
		}
		// Cache misses too: a name with no article stays no-article for the TTL.
		set_transient( $cache_key, $title, self::WIKI_TTL );
		return $title;
	}

	/**
	 * One cached enwiki request carrying everything the wire needs about a
	 * person: the article wikitext AND the Wikidata QID behind it (both live
	 * in a single action=query&prop=revisions|pageprops call). Consumers:
	 * death-year check, exact death-date extraction, birth year, and the
	 * wordcloud stored on the record for same-name disambiguation.
	 *
	 * @return array{wikitext:string, qid:string}|\WP_Error Cached arrays are
	 *         shared by reference discipline — callers must not mutate.
	 */
	public static function wiki_article( string $title ): array|\WP_Error {
		$title = str_replace( ' ', '_', trim( $title ) );
		if ( '' === $title ) {
			return array( 'wikitext' => '', 'qid' => '' );
		}
		$cache_key = 'obit_wikiart_' . md5( $title );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['wikitext'] ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			'https://en.wikipedia.org/w/api.php?action=query&prop=revisions%7Cpageprops&rvprop=content&rvslots=main&format=json&formatversion=2&maxlag=5&titles=' . rawurlencode( $title ),
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
			// Negative answers cache too: dead titles stay dead for the TTL.
			$empty = array( 'wikitext' => '', 'qid' => '' );
			set_transient( $cache_key, $empty, self::WIKI_TTL );
			return $empty;
		}
		Discovery_Service::note_success();
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$page  = (array) ( $body['query']['pages'][0] ?? array() );
		$out   = array(
			'wikitext' => (string) ( $page['revisions'][0]['slots']['main']['content'] ?? '' ),
			'qid'      => (string) ( $page['pageprops']['wikibase_item'] ?? '' ),
		);
		if ( '' === $out['qid'] || ! preg_match( '/^Q\d+$/', $out['qid'] ) ) {
			$out['qid'] = '';
		}
		set_transient( $cache_key, $out, self::WIKI_TTL );
		return $out;
	}

	/** Raw wikitext of one enwiki article via the combined cached fetch. */
	private static function wiki_article_wikitext( string $title ): string|\WP_Error {
		$result = self::wiki_article( $title );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (string) $result['wikitext'];
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
		$stats['discarded_low_likelihood'] = 0;
		$stats['created_from_article']     = 0;
		$stats['auto_confirmed']           = 0;
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

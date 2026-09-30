<?php
/**
 * WP-CLI commands for editorial review.
 *
 * `wp obitleague review-approve-all` bulk-approves every pending review case
 * whose person record already carries the evidence the approval rules demand:
 * an exact death date and two independent origin groups (the Wikipedia deaths
 * list plus the Wikidata P570 record, both of which the wire requires before
 * a case can exist). Cases that cannot satisfy the rules honestly are listed
 * and left pending for a human editor — the command never fabricates
 * evidence to push a case through.
 *
 * Every approval goes through Review_Service::decide(), so the audit trail,
 * death events, outbox notifications and standings updates are identical to
 * a decision made by hand in the admin screen.
 *
 * Usage:
 *   wp obitleague review-approve-all [--dry-run] [--limit=<n>] [--editor=<id>] [--reason=<text>]
 *
 * @package Obitleague
 */

namespace Obitleague\Modules;

use Obitleague\Domain\Review_Rules;

final class Review_Cli {

	private function __construct() {}

	public static function boot(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Closure form: wp-cli's reflection would otherwise try to
			// instantiate this class (private constructor) for the command.
			\WP_CLI::add_command(
				'obitleague review-approve-all',
				static function ( array $args, array $assoc_args ): void {
					self::cli_approve_all( $args, $assoc_args );
				}
			);
		}
	}

	/** Handle `wp obitleague review-approve-all`. */
	public static function cli_approve_all( array $args, array $assoc_args ): void {
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 0;
		$editor  = isset( $assoc_args['editor'] ) ? (int) $assoc_args['editor'] : 0;
		$reason  = isset( $assoc_args['reason'] ) ? (string) $assoc_args['reason'] : '';
		$reason  = '' !== trim( $reason ) ? trim( $reason ) . ' ' : '';
		$reason .= 'Bulk CLI approval: exact death date from the person record; enwiki deaths list + Wikidata P570.';

		$approved  = 0;
		$skipped   = 0;
		$failed    = 0;
		$attempted = array();

		// Cases leave the pending set as they are approved, so every batch is
		// drawn from the top and already-attempted ids are filtered in memory.
		// The loop ends when a batch contains nothing unattempted.
		do {
			$cases = self::pending_batch( 100, 0 );
			$fresh = array();
			foreach ( $cases as $case ) {
				if ( ! in_array( (int) $case->id, $attempted, true ) ) {
					$fresh[] = $case;
				}
			}
			if ( ! $fresh ) {
				break;
			}
			foreach ( $fresh as $case ) {
				$attempted[] = (int) $case->id;
				if ( $limit > 0 && $approved >= $limit ) {
					break 2;
				}

				$decision = self::build_decision( (int) $case->id );
				if ( null === $decision ) {
					++$skipped;
					\WP_CLI::log( sprintf( 'skip  #%d %s — record lacks an exact death date or two origin groups; left pending', (int) $case->id, (string) $case->person_name ) );
					continue;
				}
				$decision['reason'] = $reason;

				if ( $dry_run ) {
					++$approved;
					\WP_CLI::log( sprintf( 'would approve  #%d %s (death %s)', (int) $case->id, (string) $case->person_name, (string) $case->death_date ) );
					continue;
				}

				try {
					Review_Service::decide( (int) $case->id, $editor, Review_Rules::APPROVED, $decision, (int) $case->revision );
					++$approved;
					\WP_CLI::log( sprintf( 'approved  #%d %s', (int) $case->id, (string) $case->person_name ) );
				} catch ( \Throwable $e ) {
					++$failed;
					\WP_CLI::warning( sprintf( 'failed   #%d %s: %s', (int) $case->id, (string) $case->person_name, $e->getMessage() ) );
				}
			}
		} while ( count( $cases ) === 100 );

		if ( $dry_run ) {
			\WP_CLI::success( sprintf( 'Dry run: %d case(s) would be approved, %d skipped. Re-run without --dry-run to apply.', $approved, $skipped ) );
			return;
		}

		// Same immediate settle the admin screen performs after a decision.
		if ( $approved > 0 ) {
			Outbox_Service::process_outbox( 500 );
		}
		\WP_CLI::success( sprintf( 'Approved %d, skipped %d, failed %d.', $approved, $skipped, $failed ) );
	}

	/**
	 * The honest bulk decision for a case, or null when the record cannot
	 * satisfy the approval rules without inventing evidence.
	 *
	 * @return array{origin_groups:string[],death_date:array{y:int,m:int,d:int},cause_disclosed:bool,cause_text:string,official_statement:bool,reason:string}|null
	 */
	public static function build_decision( int $case_id ): ?array {
		global $wpdb;

		$case = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, person_uuid FROM ' . $wpdb->prefix . 'obitleague_review_cases WHERE id = %d AND state = %s',
				$case_id,
				Review_Rules::PENDING
			)
		);
		if ( ! $case ) {
			return null;
		}

		$post_id = Review_Service::person_post_id( (string) $case->person_uuid );
		if ( ! $post_id ) {
			return null;
		}

		$origins = array();
		if ( (string) get_post_meta( $post_id, 'obit_enwiki', true ) !== '' ) {
			$origins[] = 'enwiki-deaths-list';
		}
		if ( (string) get_post_meta( $post_id, 'obit_qid', true ) !== '' ) {
			$origins[] = 'wikidata-P570';
		}

		$death = self::parse_exact_date( (string) get_post_meta( $post_id, 'obit_death_date', true ) );
		if ( count( $origins ) < 2 || null === $death ) {
			return null;
		}

		$cause_status = (string) get_post_meta( $post_id, 'obit_cause_status', true );
		$cause_text   = (string) get_post_meta( $post_id, 'obit_cause_text', true );

		return array(
			'origin_groups'      => $origins,
			'death_date'         => $death,
			'cause_disclosed'    => 'confirmed' === $cause_status && '' !== trim( $cause_text ),
			'cause_text'         => 'confirmed' === $cause_status ? $cause_text : '',
			'official_statement' => false,
			'reason'             => '',
		);
	}

	/** Accept only exact Y-m-d dates (day > 0); month/year precision stays unapproved for scoring. */
	public static function parse_exact_date( string $raw ): ?array {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $raw ), $m ) ) {
			return null;
		}
		$y = (int) $m[1];
		$mth = (int) $m[2];
		$d = (int) $m[3];
		if ( $mth < 1 || $mth > 12 || $d < 1 || $d > 31 ) {
			return null;
		}
		return array( 'y' => $y, 'm' => $mth, 'd' => $d );
	}

	/** A batch of pending cases with names, ordered oldest first. */
	private static function pending_batch( int $count, int $offset ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, person_uuid, death_date, revision FROM ' . $wpdb->prefix . 'obitleague_review_cases
				 WHERE state = %s ORDER BY created_at ASC LIMIT %d OFFSET %d',
				Review_Rules::PENDING,
				$count,
				$offset
			)
		);
		foreach ( $rows as $row ) {
			$row->person_name = Review_Service::person_name( (string) $row->person_uuid );
		}
		return (array) $rows;
	}
}

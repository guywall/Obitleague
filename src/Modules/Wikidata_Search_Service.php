<?php
/**
 * On-demand Wikidata lookup and player-requested catalogue additions.
 *
 * Search results are limited to humans with an exact birth date, no recorded
 * death date, and the minimum ruleset age at the selected season's deadline.
 * A player explicitly choosing a result creates a public, selectable profile
 * from CC0 structured facts; no biography text is copied.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

use Obitleague\Domain\Age;
use Obitleague\Domain\Deadline_Policy;
use Obitleague\Domain\Value\Partial_Date;
use Obitleague\Domain\Value\Ruleset;
use Obitleague\Support\Time;

final class Wikidata_Search_Service {

	private const API = 'https://www.wikidata.org/w/api.php';
	private const USER_AGENT = 'Obitleague/0.7 (Wikidata person lookup; contact: site administrator)';

	private function __construct() {}

	/** Search, then verify that candidates are selectable people for a season. */
	public static function search( string $term, int $season ): array|\WP_Error {
		$term = trim( sanitize_text_field( $term ) );
		if ( mb_strlen( $term ) < 2 || mb_strlen( $term ) > 100 ) {
			return new \WP_Error( 'obitleague_search_term', 'Enter between 2 and 100 characters to search Wikidata.', array( 'status' => 400 ) );
		}
		$cache_key = 'ob_wd_search_' . md5( $season . '|' . mb_strtolower( $term ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$search = self::request(
			self::API . '?' . http_build_query(
				array(
					'action' => 'wbsearchentities',
					'search' => $term,
					'language' => 'en',
					'uselang' => 'en',
					'type' => 'item',
					'limit' => 12,
					'format' => 'json',
				)
			)
		);
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		$qids = array_values(
			array_filter(
				array_map(
					static fn ( $item ): string => (string) ( $item['id'] ?? '' ),
					(array) ( $search['search'] ?? array() )
				),
				static fn ( string $qid ): bool => (bool) preg_match( '/^Q[1-9][0-9]*$/', $qid )
			)
		);
		if ( ! $qids ) {
			set_transient( $cache_key, array(), 10 * MINUTE_IN_SECONDS );
			return array();
		}
		foreach ( $qids as $qid ) {
			if ( Import_Service::post_id_by_qid( $qid ) ) {
				unset( $qids[ array_search( $qid, $qids, true ) ] );
			}
		}
		$qids = array_values( $qids );
		if ( ! $qids ) {
			set_transient( $cache_key, array(), 10 * MINUTE_IN_SECONDS );
			return array();
		}

		$entities = self::entities( $qids );
		if ( is_wp_error( $entities ) ) {
			return $entities;
		}
		$occupation_ids = array();
		foreach ( $entities as $entity ) {
			foreach ( self::claim_item_ids( $entity, 'P106' ) as $qid ) {
				$occupation_ids[ $qid ] = true;
			}
		}
		$occupation_labels = self::labels( array_keys( $occupation_ids ) );

		$people = array();
		$deadline = Deadline_Policy::season_start( $season );
		foreach ( $qids as $qid ) {
			$entity = $entities[ $qid ] ?? null;
			if ( ! self::is_human( $entity ) || self::has_live_claim( $entity, 'P570' ) ) {
				continue;
			}
			$birth = self::birth_date( $entity );
			if ( null === $birth ) {
				continue;
			}
			try {
				$season_age = Age::completed_at( $birth, $deadline );
				$current_age = Age::completed_at( $birth, Time::now() );
			} catch ( \InvalidArgumentException ) {
				continue;
			}
			if ( null === $season_age || $season_age < Ruleset::MIN_AGE || null === $current_age ) {
				continue;
			}
			$occupations = array();
			foreach ( self::claim_item_ids( $entity, 'P106' ) as $occupation_id ) {
				if ( ! empty( $occupation_labels[ $occupation_id ] ) ) {
					$occupations[] = $occupation_labels[ $occupation_id ];
				}
			}
			$people[] = array(
				'qid' => $qid,
				'name' => self::entity_label( $entity, $qid ),
				'description' => self::entity_description( $entity ),
				'birth' => $birth->label(),
				'age' => $current_age,
				'occupations' => array_values( array_unique( $occupations ) ),
				'enwiki' => (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' ),
				'url' => 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ),
			);
			if ( count( $people ) >= 8 ) {
				break;
			}
		}
		set_transient( $cache_key, $people, 10 * MINUTE_IN_SECONDS );
		return $people;
	}

	/** Import one explicitly chosen result after repeating all eligibility checks. */
	public static function import_selected( string $qid, int $season ): int|\WP_Error {
		$qid = strtoupper( trim( sanitize_text_field( $qid ) ) );
		if ( ! preg_match( '/^Q[1-9][0-9]*$/', $qid ) ) {
			return new \WP_Error( 'obitleague_invalid_qid', 'That Wikidata item is not valid.', array( 'status' => 400 ) );
		}
		$entities = self::entities( array( $qid ) );
		if ( is_wp_error( $entities ) ) {
			return $entities;
		}
		$entity = $entities[ $qid ] ?? null;
		if ( ! self::is_human( $entity ) || self::has_live_claim( $entity, 'P570' ) ) {
			return new \WP_Error( 'obitleague_not_living_human', 'Only living human Wikidata items can be added as picks.', array( 'status' => 422 ) );
		}
		$birth = self::birth_date( $entity );
		if ( null === $birth ) {
			return new \WP_Error( 'obitleague_birth_date_unverified', 'This person does not have an exact Wikidata birth date, so cannot be selected.', array( 'status' => 422 ) );
		}
		try {
			$age_at_lock = Age::completed_at( $birth, Deadline_Policy::season_start( $season ) );
		} catch ( \InvalidArgumentException ) {
			$age_at_lock = null;
		}
		if ( null === $age_at_lock || $age_at_lock < Ruleset::MIN_AGE ) {
			return new \WP_Error( 'obitleague_underage_pick', 'This person will not meet the minimum age at the season start.', array( 'status' => 422 ) );
		}

		$existing_id = Import_Service::post_id_by_qid( $qid );
		if ( $existing_id ) {
			if ( 'publish' === get_post_status( $existing_id ) && Catalogue::is_selectable( $existing_id, $season ) ) {
				return $existing_id;
			}
			return new \WP_Error( 'obitleague_existing_candidate', 'This person is already in the catalogue and needs an editor to make them selectable.', array( 'status' => 409 ) );
		}

		$occupation_ids = self::claim_item_ids( $entity, 'P106' );
		$occupation_labels = self::labels( $occupation_ids );
		$name = self::entity_label( $entity, $qid );
		$occupation_map = array();
		foreach ( $occupation_ids as $occupation_id ) {
			$label = (string) ( $occupation_labels[ $occupation_id ] ?? '' );
			if ( '' !== $label ) {
				$occupation_map[ $occupation_id ] = $label;
			}
		}
		$occupations = array_values( $occupation_map );
		$post_id = wp_insert_post(
			array(
				'post_type' => Catalogue::POST_TYPE,
				'post_status' => 'publish',
				'post_title' => $name,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'obitleague_import_failed', 'The selected person could not be added to the catalogue.', array( 'status' => 500 ) );
		}

		$uuid = wp_generate_uuid4();
		$enwiki = (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' );
		update_post_meta( $post_id, 'obit_uuid', $uuid );
		update_post_meta( $post_id, 'obit_qid', $qid );
		update_post_meta( $post_id, 'obit_birth_date', sprintf( '%04d-%02d-%02d', $birth->year, $birth->month, $birth->day ) );
		update_post_meta( $post_id, 'obit_eligibility', 'approved' );
		update_post_meta( $post_id, 'obit_eligibility_note', 'Player-selected Wikidata item; human status, exact birth date and season age verified automatically.' );
		update_post_meta( $post_id, 'obit_role', implode( ', ', $occupations ) );
		if ( '' !== $enwiki ) {
			update_post_meta( $post_id, 'obit_enwiki', $enwiki );
		}
		if ( $occupations ) {
			update_post_meta( $post_id, People_Sync::META_OCCUPATIONS, implode( ', ', $occupations ) );
			update_post_meta( $post_id, People_Sync::META_OCCUPATION_PRIMARY, $occupations[0] );
			update_post_meta( $post_id, People_Sync::META_OCCUPATION_QIDS, wp_json_encode( $occupation_map ) );
		}
		if ( ! Catalogue::is_selectable( (int) $post_id, $season ) ) {
			wp_delete_post( (int) $post_id, true );
			return new \WP_Error( 'obitleague_pick_not_selectable', 'Wikidata facts did not meet the catalogue pick rules.', array( 'status' => 422 ) );
		}
		return (int) $post_id;
	}

	/**
	 * Wikidata search tuned for obituary pairing: returns humans with their
	 * birth and death date labels, including deceased subjects (unlike search(),
	 * which filters out P570 bearers). No season or age filtering is applied:
	 * the editor has chosen to pair, so every human candidate is fair game.
	 *
	 * @return array<int,array{qid:string,name:string,description:string,birth:string,death:string,enwiki:string,url:string}>|\WP_Error
	 */
	public static function obituary_search( string $term ): array|\WP_Error {
		$term = trim( sanitize_text_field( $term ) );
		if ( mb_strlen( $term ) < 2 || mb_strlen( $term ) > 100 ) {
			return new \WP_Error( 'obitleague_search_term', 'Enter between 2 and 100 characters to search Wikidata.', array( 'status' => 400 ) );
		}
		$cache_key = 'obit_wd_obitsearch_' . md5( mb_strtolower( $term ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$search = self::request(
			self::API . '?' . http_build_query(
				array(
					'action'     => 'wbsearchentities',
					'search'     => $term,
					'language'   => 'en',
					'uselang'    => 'en',
					'type'       => 'item',
					'limit'      => 12,
					'format'     => 'json',
				)
			)
		);
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		$qids = array_values(
			array_filter(
				array_map(
					static fn ( $item ): string => (string) ( $item['id'] ?? '' ),
					(array) ( $search['search'] ?? array() )
				),
				static fn ( string $qid ): bool => (bool) preg_match( '/^Q[1-9][0-9]*$/', $qid )
			)
		);
		if ( ! $qids ) {
			set_transient( $cache_key, array(), 10 * MINUTE_IN_SECONDS );
			return array();
		}
		// Keep already-imported people out of the pairing list.
		foreach ( $qids as $qid ) {
			if ( Import_Service::post_id_by_qid( $qid ) ) {
				unset( $qids[ array_search( $qid, $qids, true ) ] );
			}
		}
		$qids = array_values( $qids );
		if ( ! $qids ) {
			set_transient( $cache_key, array(), 10 * MINUTE_IN_SECONDS );
			return array();
		}

		$entities = self::entities( $qids );
		if ( is_wp_error( $entities ) ) {
			return $entities;
		}

		$people = array();
		foreach ( $qids as $qid ) {
			$entity = $entities[ $qid ] ?? null;
			if ( ! self::is_human( $entity ) ) {
				continue;
			}
			$people[] = array(
				'qid'          => $qid,
				'name'         => self::entity_label( $entity, $qid ),
				'description'  => self::entity_description( $entity ),
				'birth'        => self::date_label( $entity, 'P569' ),
				'death'        => self::date_label( $entity, 'P570' ),
				'enwiki'       => (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' ),
				'url'          => 'https://www.wikidata.org/wiki/' . rawurlencode( $qid ),
			);
			if ( count( $people ) >= 8 ) {
				break;
			}
		}
		set_transient( $cache_key, $people, 10 * MINUTE_IN_SECONDS );
		return $people;
	}

	/**
	 * Fetch a single Wikidata entity for import after an editor picks a
	 * search result. Returns identification fields plus best-effort birth
	 * and death dates (ISO strings); the caller falls back to Wikipedia
	 * wikitext extraction where the entity claim is absent.
	 *
	 * @return array{qid:string,name:string,enwiki:string,description:string,birth:string,death:string}|\WP_Error
	 */
	public static function entity_for_pairing( string $qid ): array|\WP_Error {
		$qid = strtoupper( trim( sanitize_text_field( $qid ) ) );
		if ( ! preg_match( '/^Q[1-9][0-9]*$/', $qid ) ) {
			return new \WP_Error( 'obitleague_invalid_qid', 'That Wikidata item is not valid.', array( 'status' => 400 ) );
		}
		$entities = self::entities( array( $qid ) );
		if ( is_wp_error( $entities ) ) {
			return $entities;
		}
		$entity = $entities[ $qid ] ?? null;
		if ( ! self::is_human( $entity ) ) {
			return new \WP_Error( 'obitleague_not_human', 'That Wikidata item is not a human.', array( 'status' => 422 ) );
		}
		return array(
			'qid'          => $qid,
			'name'         => self::entity_label( $entity, $qid ),
			'description'  => self::entity_description( $entity ),
			'enwiki'       => (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' ),
			'birth'        => self::date_to_iso( self::claim_date( $entity, 'P569' ) ),
			'death'        => self::date_to_iso( self::claim_date( $entity, 'P570' ) ),
		);
	}

	/** Fetch and index one bounded batch of Wikidata entities. */
	private static function entities( array $qids ): array|\WP_Error {
		$url = self::API . '?' . http_build_query(
			array(
				'action' => 'wbgetentities',
				'ids' => implode( '|', array_slice( $qids, 0, 50 ) ),
				'props' => 'claims|labels|descriptions|sitelinks',
				'languages' => 'en',
				'sites' => 'enwiki',
				'format' => 'json',
				'formatversion' => '2',
			)
		);
		$response = self::request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$out = array();
		foreach ( (array) ( $response['entities'] ?? array() ) as $id => $entity ) {
			$out[ (string) $id ] = is_array( $entity ) ? $entity : array();
		}
		return $out;
	}

	/** Batch English occupation labels through the same API. */
	private static function labels( array $qids ): array {
		if ( ! $qids ) {
			return array();
		}
		$url = self::API . '?' . http_build_query(
			array(
				'action' => 'wbgetentities',
				'ids' => implode( '|', array_slice( array_values( array_unique( $qids ) ), 0, 50 ) ),
				'props' => 'labels',
				'languages' => 'en',
				'format' => 'json',
				'formatversion' => '2',
			)
		);
		$response = self::request( $url );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( $response['entities'] ?? array() ) as $id => $entity ) {
			$out[ (string) $id ] = (string) ( $entity['labels']['en']['value'] ?? '' );
		}
		return $out;
	}

	private static function request( string $url ): array|\WP_Error {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'User-Agent' => self::USER_AGENT ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'obitleague_wikidata_unavailable', 'Wikidata could not be reached just now. Please retry shortly.', array( 'status' => 502 ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'obitleague_wikidata_unavailable', 'Wikidata returned an error. Please retry shortly.', array( 'status' => 502 ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new \WP_Error( 'obitleague_wikidata_invalid', 'Wikidata returned unreadable data.', array( 'status' => 502 ) );
	}

	private static function is_human( ?array $entity ): bool {
		return null !== $entity && in_array( 'Q5', self::claim_item_ids( $entity, 'P31' ), true );
	}

	private static function has_live_claim( ?array $entity, string $property ): bool {
		foreach ( (array) ( $entity['claims'][ $property ] ?? array() ) as $claim ) {
			if ( is_array( $claim ) && 'deprecated' !== (string) ( $claim['rank'] ?? 'normal' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Item-valued claims, de-duplicated and excluding deprecated claims. */
	private static function claim_item_ids( ?array $entity, string $property ): array {
		$out = array();
		foreach ( (array) ( $entity['claims'][ $property ] ?? array() ) as $claim ) {
			if ( ! is_array( $claim ) || 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			$id = (string) ( $claim['mainsnak']['datavalue']['value']['id'] ?? '' );
			if ( preg_match( '/^Q[1-9][0-9]*$/', $id ) ) {
				$out[ $id ] = true;
			}
		}
		return array_keys( $out );
	}

	private static function birth_date( ?array $entity ): ?Partial_Date {
		foreach ( (array) ( $entity['claims']['P569'] ?? array() ) as $claim ) {
			if ( ! is_array( $claim ) || 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			$value = $claim['mainsnak']['datavalue']['value'] ?? array();
			$time = is_array( $value ) ? (string) ( $value['time'] ?? '' ) : '';
			if ( 11 !== (int) ( $value['precision'] ?? 0 ) || ! preg_match( '/^\+(\d{4})-(\d{2})-(\d{2})T/', $time, $matches ) ) {
				continue;
			}
			try {
				$date = new Partial_Date( (int) $matches[1], (int) $matches[2], (int) $matches[3] );
				return $date->interpretations() ? $date : null;
			} catch ( \InvalidArgumentException ) {
				return null;
			}
		}
		return null;
	}

	private static function entity_label( ?array $entity, string $fallback ): string {
		$label = trim( (string) ( $entity['labels']['en']['value'] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}
		// Some Wikidata items carry no English label at all (the label graph
		// is incomplete); the English Wikipedia sitelink title is the next-
		// best human-readable name. Never fall straight through to the QID
		// when a real name is reachable.
		$enwiki = trim( (string) ( $entity['sitelinks']['enwiki']['title'] ?? '' ) );
		if ( '' !== $enwiki ) {
			return str_replace( '_', ' ', $enwiki );
		}
		return $fallback;
	}

	private static function entity_description( ?array $entity ): string {
		return trim( (string) ( $entity['descriptions']['en']['value'] ?? '' ) );
	}

	/**
	 * Best-effort human-readable date label for a claim property (P569 birth,
	 * P570 death). Handles year, month and day precision; returns '' when
	 * the claim is absent.
	 */
	private static function date_label( ?array $entity, string $property ): string {
		$date = self::claim_date( $entity, $property );
		return null !== $date ? $date->label() : '';
	}

	/**
	 * Extract a date from a Wikidata date property (P569, P570), accepting
	 * year, month or day precision. Returns null when the claim is absent
	 * or malformed.
	 */
	private static function claim_date( ?array $entity, string $property ): ?Partial_Date {
		foreach ( (array) ( $entity['claims'][ $property ] ?? array() ) as $claim ) {
			if ( ! is_array( $claim ) || 'deprecated' === (string) ( $claim['rank'] ?? 'normal' ) ) {
				continue;
			}
			$value = $claim['mainsnak']['datavalue']['value'] ?? array();
			if ( ! is_array( $value ) ) {
				continue;
			}
			$time     = (string) ( $value['time'] ?? '' );
			$precision = (int) ( $value['precision'] ?? 0 );
			if ( $precision < 9 || ! preg_match( '/^([+-]\d{4})-(\d{2})-(\d{2})T/', $time, $matches ) ) {
				continue;
			}
			$year  = (int) $matches[1];
			$month = $precision >= 10 ? (int) $matches[2] : null;
			$day   = ( $precision >= 11 && null !== $month ) ? min( 31, max( 1, (int) $matches[3] ) ) : null;
			try {
				$date = new Partial_Date( $year, $month, $day );
				if ( null === $month || ! empty( $date->interpretations() ) ) {
					return $date;
				}
				continue;
			} catch ( \InvalidArgumentException ) {
				continue;
			}
		}
		return null;
	}

	/** Render a Partial_Date as 'Y', 'Y-m' or 'Y-m-d' for Import_Service. */
	private static function date_to_iso( ?Partial_Date $date ): string {
		if ( null === $date || null === $date->year ) {
			return '';
		}
		if ( null === $date->month ) {
			return sprintf( '%04d', $date->year );
		}
		if ( null === $date->day ) {
			return sprintf( '%04d-%02d', $date->year, $date->month );
		}
		return sprintf( '%04d-%02d-%02d', $date->year, $date->month, $date->day );
	}
}

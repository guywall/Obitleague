<?php
/**
 * League page view service.
 *
 * Read-only projections for public league pages: league header, member
 * roster, post-lock pick summaries with scoring context, and the current
 * viewer's membership state. Privacy follows the ruleset: pre-lock teams
 * are hidden from everyone but the owner; picks become visible to all
 * after lock (the demo season is in play, so submitted teams show).
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Modules;

final class League_View_Service {

	/** Request-local cache for catalogue UUID lookups. */
	private static array $person_cards = array();
	private static array $post_ids_by_uuid = array();

	private function __construct() {}

	/** League header row or null. */
	public static function league( int $league_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name, season, owner_user_id, state, created_at FROM ' . $wpdb->prefix . 'obitleague_leagues WHERE id = %d',
				$league_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$owner = get_userdata( (int) $row->owner_user_id );
		return array(
			'id'         => (int) $row->id,
			'name'       => (string) $row->name,
			'season'     => (int) $row->season,
			'state'      => (string) $row->state,
			'owner'      => $owner ? (string) $owner->display_name : '',
			'members'    => (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d', $league_id )
			),
		);
	}

	/** Is the current viewer a member (or site admin)? */
	public static function viewer_is_member( int $league_id ): bool {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'obitleague_league_members WHERE league_id = %d AND user_id = %d',
				$league_id,
				$uid
			)
		);
	}

	/**
	 * Scored roster: standings rows joined with each player's submitted
	 * picks (post-lock visible), scoring awards flagged.
	 *
	 * @return array[] Each: user_id, player, rank, points, scoring_picks, picks[].
	 */
	public static function scoreboard( int $league_id, int $season, int $page = 1, int $per_page = 50 ): array {
		$page = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$rows = Standings_Service::current( $league_id, $season, $per_page, ( $page - 1 ) * $per_page );
		if ( null === $rows ) {
			return array();
		}
		/*
		 * Picks become public once the season's entry window closes. Under
		 * rolling entry that is the end of the season year; v1 seasons kept
		 * their 1 January instant, so existing behaviour is unchanged.
		 */
		$locked = ! \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/London' ) ) );
		$entry_ids = array_map( static fn ( $row ): int => (int) ( $row['entry_id'] ?? 0 ), $rows );
		$cards_by_entry = $locked ? self::pick_cards_for_entries( $entry_ids, $season ) : array();
		$out = array();
		foreach ( $rows as $row ) {
			$entry_id = (int) ( $row['entry_id'] ?? 0 );
			$picks = $entry_id ? ( $cards_by_entry[ $entry_id ] ?? array() ) : array();
			$out[] = array(
				'user_id'       => (int) $row['user_id'],
				'player'        => (string) $row['player'],
				'team_name'     => (string) ( $row['team_name'] ?? '' ),
				'owner'         => (string) ( $row['owner'] ?? '' ),
				'rank'          => (int) $row['rank'],
				'points'        => (int) $row['points'],
				'scoring_picks' => (int) $row['scoring_picks'],
				'picks'         => $picks,
			);
		}
		return $out;
	}

	/** The player's submitted entry id in this league+season, or 0. */
	public static function submitted_entry_id( int $user_id, int $league_id, int $season ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT e.id FROM ' . $wpdb->prefix . 'obitleague_entries e
				 JOIN ' . $wpdb->prefix . "obitleague_entry_revisions r ON r.entry_id = e.id AND r.kind = 'submitted'
				 WHERE e.user_id = %d AND e.league_id = %d AND e.season = %d AND e.state = 'submitted'
				 ORDER BY r.id DESC LIMIT 1",
				$user_id,
				$league_id,
				$season
			)
		);
	}

	/**
	 * Pick cards for one submitted entry: person name/link/portrait plus
	 * award state for the season.
	 *
	 * @return array[]
	 */
	public static function pick_cards( int $entry_id, int $season ): array {
		$revision = Entry_Service::submitted_revision( $entry_id );
		if ( ! $revision ) {
			return array();
		}
		return self::pick_cards_for_revision( (int) $revision->id, $entry_id, $season );
	}

	/** Batch-load pick cards and award totals for a bounded scoreboard page. */
	public static function pick_cards_for_entries( array $entry_ids, int $season ): array {
		$entry_ids = array_values( array_unique( array_filter( array_map( 'intval', $entry_ids ) ) ) );
		if ( ! $entry_ids ) {
			return array();
		}
		global $wpdb;
		$ids = implode( ',', $entry_ids );
		$revision_rows = $wpdb->get_results(
			"SELECT r.id AS revision_id, r.entry_id, p.slot, p.person_uuid
			 FROM {$wpdb->prefix}obitleague_entry_revisions r
			 JOIN (SELECT entry_id, MAX(id) AS revision_id FROM {$wpdb->prefix}obitleague_entry_revisions WHERE kind = 'submitted' AND entry_id IN ({$ids}) GROUP BY entry_id) latest ON latest.revision_id = r.id
			 JOIN {$wpdb->prefix}obitleague_entry_picks p ON p.revision_id = r.id
			 ORDER BY r.entry_id ASC, p.slot ASC"
		);
		$uuids = array_values( array_unique( array_map( static fn ( $row ): string => (string) $row->person_uuid, (array) $revision_rows ) ) );
		if ( ! $revision_rows ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
		$awards = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, pick_slug, COALESCE(SUM(award_delta), 0) AS points FROM {$wpdb->prefix}obitleague_awards WHERE season = %d AND entry_id IN ({$ids}) AND pick_slug IN ({$placeholders}) GROUP BY entry_id, pick_slug",
				$season,
				...$uuids
			)
		);
		$award_map = array();
		foreach ( (array) $awards as $award ) {
			$award_map[ (int) $award->entry_id ][ (string) $award->pick_slug ] = (int) $award->points;
		}
		$cards = array();
		foreach ( $revision_rows as $row ) {
			$entry_id = (int) $row->entry_id;
			$uuid = (string) $row->person_uuid;
			$card = self::person_card_by_uuid( $uuid );
			$card['awarded'] = (int) ( $award_map[ $entry_id ][ $uuid ] ?? 0 );
			$card['slot'] = (int) $row->slot;
			$cards[ $entry_id ][] = $card;
		}
		return $cards;
	}

	private static function pick_cards_for_revision( int $revision_id, int $entry_id, int $season ): array {
		global $wpdb;
		$picks = Entry_Service::revision_picks( $revision_id );
		if ( ! $picks ) {
			return array();
		}
		$uuids = array_map( static fn ( $pick ): string => (string) $pick->person_uuid, $picks );
		self::prefetch_person_cards( $uuids );
		$placeholders = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
		$awards = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pick_slug, COALESCE(SUM(award_delta), 0) AS points FROM {$wpdb->prefix}obitleague_awards WHERE entry_id = %d AND season = %d AND pick_slug IN ({$placeholders}) GROUP BY pick_slug",
				$entry_id, $season, ...$uuids
			)
		);
		$award_map = array();
		foreach ( (array) $awards as $award ) {
			$award_map[ (string) $award->pick_slug ] = (int) $award->points;
		}
		$cards = array();
		foreach ( $picks as $pick ) {
			$uuid = (string) $pick->person_uuid;
			$card = self::person_card_by_uuid( $uuid );
			$card['awarded'] = (int) ( $award_map[ $uuid ] ?? 0 );
			$card['slot'] = (int) $pick->slot;
			$cards[] = $card;
		}
		return $cards;
	}

	/** Prime UUID-to-post and post-meta caches for one bounded set of picks. */
	private static function prefetch_person_cards( array $uuids ): void {
		$uuids = array_map( 'strval', $uuids );
		$missing = array_values(
			array_unique(
				array_filter(
					$uuids,
					static fn ( string $uuid ): bool => '' !== $uuid && ! array_key_exists( $uuid, self::$person_cards ) && ! array_key_exists( $uuid, self::$post_ids_by_uuid )
				)
			)
		);
		if ( ! $missing ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value AS uuid FROM {$wpdb->postmeta} WHERE meta_key = 'obit_uuid' AND meta_value IN ({$placeholders})",
				...$missing
			)
		);
		$post_ids = array();
		foreach ( (array) $rows as $row ) {
			self::$post_ids_by_uuid[ (string) $row->uuid ] = (int) $row->post_id;
			$post_ids[] = (int) $row->post_id;
		}
		foreach ( $missing as $uuid ) {
			if ( ! array_key_exists( $uuid, self::$post_ids_by_uuid ) ) {
				self::$post_ids_by_uuid[ $uuid ] = 0;
			}
		}
		if ( ! $post_ids ) {
			return;
		}
		$posts = get_posts(
			array(
				'post_type' => Catalogue::POST_TYPE,
				'post_status' => 'publish',
				'post__in' => array_values( array_unique( $post_ids ) ),
				'posts_per_page' => count( array_unique( $post_ids ) ),
				'orderby' => 'post__in',
			)
		);
		foreach ( $posts as $post ) {
			$uuid = (string) get_post_meta( (int) $post->ID, 'obit_uuid', true );
			if ( '' !== $uuid ) {
				self::$person_cards[ $uuid ] = self::person_card_for_post( $uuid, (int) $post->ID );
			}
		}
	}

	/** Person summary by internal uuid (name, link, portrait, dates). */
	public static function person_card_by_uuid( string $uuid ): array {
		$empty = array(
			'uuid'     => $uuid,
			'name'     => '',
			'url'      => '',
			'image'    => '',
			'role'     => '',
			'birth'    => '',
			'death'    => '',
			'is_dead'  => false,
		);
		if ( '' === $uuid ) {
			return $empty;
		}
		if ( isset( self::$person_cards[ $uuid ] ) ) {
			return self::$person_cards[ $uuid ];
		}
		global $wpdb;
		if ( ! array_key_exists( $uuid, self::$post_ids_by_uuid ) ) {
			self::$post_ids_by_uuid[ $uuid ] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_uuid' AND meta_value = %s LIMIT 1", $uuid )
			);
		}
		$post_id = (int) self::$post_ids_by_uuid[ $uuid ];
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return $empty;
		}
		return self::$person_cards[ $uuid ] = self::person_card_for_post( $uuid, $post_id );
	}

	/** Construct a cached catalogue summary from an already-loaded post. */
	private static function person_card_for_post( string $uuid, int $post_id ): array {
		$birth_raw = (string) get_post_meta( $post_id, 'obit_birth_date', true );
		$death_raw = (string) get_post_meta( $post_id, 'obit_death_date', true );
		$birth     = '' !== $birth_raw ? Import_Service::parse_partial( $birth_raw ) : null;
		$death     = '' !== $death_raw ? Import_Service::parse_partial( $death_raw ) : null;
		return array(
			'uuid'    => $uuid,
			'name'    => (string) get_the_title( $post_id ),
			'url'     => (string) get_permalink( $post_id ),
			'image'   => (string) get_post_meta( $post_id, People_Sync::META_IMAGE_URL, true ),
			'role'    => (string) get_post_meta( $post_id, 'obit_role', true ),
			'birth'   => $birth ? $birth->label() : '',
			'death'   => $death ? $death->label() : '',
			'is_dead' => '' !== $death_raw,
		);
	}

	/** Deadline instant label for a season (00:00 London 1 Jan). */
	public static function deadline_label( int $season ): string {
		return $season . '-01-01 00:00 Europe/London';
	}
}

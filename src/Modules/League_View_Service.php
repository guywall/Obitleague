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
	public static function scoreboard( int $league_id, int $season ): array {
		$rows = Standings_Service::current( $league_id, $season );
		if ( null === $rows ) {
			return array();
		}			$locked = ! \Obitleague\Domain\Deadline_Policy::is_entry_open( $season, new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/London' ) ) );
		$out    = array();
		foreach ( $rows as $row ) {
			$entry_id = self::submitted_entry_id( (int) $row['user_id'], $league_id, $season );
			$picks    = array();
			if ( $entry_id && $locked ) {
				$picks = self::pick_cards( $entry_id, $season );
			}
			$out[] = array(
				'user_id'       => (int) $row['user_id'],
				'player'        => (string) $row['player'],
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
		$picks = Entry_Service::revision_picks( (int) $revision->id );
		if ( ! $picks ) {
			return array();
		}

		global $wpdb;
		$cards = array();
		foreach ( $picks as $pick ) {
			$uuid  = (string) $pick->person_uuid;
			$card  = self::person_card_by_uuid( $uuid );
			$award = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(award_delta), 0) FROM ' . $wpdb->prefix . 'obitleague_awards
					 WHERE entry_id = %d AND pick_slug = %s AND season = %d',
					$entry_id,
					$uuid,
					$season
				)
			);
			$card['awarded'] = $award;
			$card['slot']    = (int) $pick->slot;
			$cards[]         = $card;
		}
		return $cards;
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
		global $wpdb;
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'obit_uuid' AND meta_value = %s LIMIT 1",
				$uuid
			)
		);
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return $empty;
		}
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

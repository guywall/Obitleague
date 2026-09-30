<?php
/**
 * Time helpers.
 *
 * Every deadline and boundary is computed in Europe/London regardless of
 * server or user timezone, as required by the ruleset. The instants
 * themselves are single-sourced in Deadline_Policy so the rolling-entry
 * ruleset cannot drift between call sites.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Support;

use Obitleague\Domain\Deadline_Policy;

final class Time {

	public const SITE_TIMEZONE = 'Europe/London';

	private function __construct() {}

	public static function london(): \DateTimeZone {
		return new \DateTimeZone( self::SITE_TIMEZONE );
	}

	/** Current instant, timezone-independent. */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/** The instant entries must commit strictly before (see Deadline_Policy). */
	public static function entry_deadline( int $season ): \DateTimeImmutable {
		return Deadline_Policy::entry_deadline( $season );
	}

	/** 00:00 Europe/London on 1 January of the season year. */
	public static function season_start( int $season ): \DateTimeImmutable {
		return Deadline_Policy::season_start( $season );
	}

	/** 23:59:59 Europe/London on 31 January following the season. */
	public static function settlement_instant( int $season ): \DateTimeImmutable {
		return Deadline_Policy::settlement_instant( $season );
	}

	/** True while picks may still be submitted or replaced for the season. */
	public static function is_entry_open( int $season, ?\DateTimeImmutable $now = null ): bool {
		return Deadline_Policy::is_entry_open( $season, $now ?? self::now() );
	}

	/**
	 * True when a death approved at $at may still receive a NEW award in
	 * $season: the season must be a game season and its settlement window
	 * must still be open (inclusive of the settlement instant).
	 */
	public static function accepts_new_awards( int $season, ?\DateTimeImmutable $at = null, int $first_season = 2027 ): bool {
		$at = $at ?? self::now();
		return $season >= $first_season && $at <= self::settlement_instant( $season );
	}
}

<?php
/**
 * Season boundaries.
 *
 * All boundary instants are computed in Europe/London regardless of the
 * server zone. A write must COMMIT strictly before the entry deadline; jobs
 * re-check these boundaries themselves, so a delayed job can never extend
 * entry eligibility or award a death into a settled season.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Season_Boundary {

	private const LONDON = 'Europe/London';

	/** First season the game operates; earlier deaths are archive-only. */
	public readonly int $first_season;

	public function __construct( int $first_season = 2027 ) {
		if ( $first_season < 1970 || $first_season > 9999 ) {
			throw new \InvalidArgumentException( 'first_season out of range.' );
		}
		$this->first_season = $first_season;
	}

	/** The instant entries must commit strictly before (see Deadline_Policy). */
	public function entry_deadline( int $season ): \DateTimeImmutable {
		return Deadline_Policy::entry_deadline( $season );
	}

	/** 00:00 Europe/London on 1 January of the season year. */
	public function season_start( int $season ): \DateTimeImmutable {
		return Deadline_Policy::season_start( $season );
	}

	/** 23:59:59 Europe/London on 31 January following the season. */
	public function settlement_instant( int $season ): \DateTimeImmutable {
		return new \DateTimeImmutable(
			sprintf( 'last day of January %04d 23:59:59', $season + 1 ),
			new \DateTimeZone( self::LONDON )
		);
	}

	/** True while picks may still be submitted or replaced for the season. */
	public function is_entry_open( int $season, \DateTimeImmutable $now ): bool {
		return $now < $this->entry_deadline( $season );
	}

	/**
	 * True when a death approved at $at may still receive a NEW award in
	 * $season: the season must be a game season and its settlement window
	 * must still be open (inclusive of the settlement instant).
	 */
	public function accepts_new_awards( int $season, \DateTimeImmutable $at ): bool {
		return $season >= $this->first_season
			&& $at <= $this->settlement_instant( $season );
	}
}

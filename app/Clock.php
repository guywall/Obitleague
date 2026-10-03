<?php
declare( strict_types = 1 );

namespace Obitleague\Standalone;

use DateTimeImmutable;
use DateTimeZone;

/** The application's single source of "now". */
class Clock {
	/**
	 * @param DateTimeImmutable|null $pinned A fixed instant used by tests and the
	 *   ops override; null reads the wall clock. The season and every timestamp
	 *   derive from it, so a pinned clock makes the deadline boundary observable.
	 */
	public function __construct( private ?DateTimeImmutable $pinned = null ) {
	}

	/** The current instant, pinned or read from the wall clock. */
	public function instant(): DateTimeImmutable {
		return $this->pinned ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/** UTC timestamp in the format stored in text columns. */
	public function now(): string {
		return $this->instant()->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** The season is the calendar year in Europe/London. */
	public function season_now(): int {
		return (int) $this->instant()->setTimezone( new DateTimeZone( 'Europe/London' ) )->format( 'Y' );
	}
}

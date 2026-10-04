<?php
/**
 * Retry policy for the shared Wikimedia request queue.
 *
 * The queue drains oldest-first, so a row that is retried without moving its
 * next attempt forward sits at the head and is picked straight back up: a
 * single bad request can burn a whole pass and climb the attempt counter into
 * the thousands, starving the work behind it. This policy answers the two
 * questions the queue asks on every failure — how long to wait before trying
 * again, and when to stop trying altogether — as pure numbers, so the caps
 * that keep the queue moving stay verifiable without WordPress.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wiki_Queue_Policy {

	/** Ordinary failures (bad body, 404, no data): give up after this many. */
	public const MAX_ATTEMPTS = 5;

	/**
	 * A job that only ever returns a rate-limit/pause error retries on a
	 * longer leash — a genuine throttle clears — but not forever. Past this
	 * many tries it is failed so the queue is never permanently blocked; its
	 * scheduler can enqueue it again fresh.
	 */
	public const MAX_DEFER_ATTEMPTS = 25;

	private function __construct() {}

	/**
	 * Whether a retry should give up. `$attempts` is the attempt number just
	 * made (1 for the first try).
	 */
	public static function should_fail( int $attempts, bool $rate_limited ): bool {
		$cap = $rate_limited ? self::MAX_DEFER_ATTEMPTS : self::MAX_ATTEMPTS;
		return $attempts >= $cap;
	}

	/**
	 * Seconds to wait before the next try: exponential across consecutive
	 * failures, clamped by the shared pause policy.
	 */
	public static function retry_backoff_seconds( int $attempts ): int {
		return Wire_Pause::seconds( '', max( 1, $attempts ) );
	}
}

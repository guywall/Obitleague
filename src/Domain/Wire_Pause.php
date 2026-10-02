<?php
/**
 * Bounded backoff policy for the shared Wikimedia rate-limit pause.
 *
 * A throttled request carries Retry-After, which can be a few seconds or —
 * after upstream congestion or a bad patch — a moment days away. The queue
 * honours this value globally, so an unbounded pause freezes every
 * Wikimedia-backed pipeline at once: discovery, the news wire's title
 * checks, provisional creation and enrichment. This policy keeps the wait
 * polite but self-healing: it honours a genuine hint up to a ceiling, backs
 * off exponentially across consecutive failures so a busy endpoint is not
 * hammered, and never exceeds that ceiling.
 *
 * @package Obitleague
 */

declare( strict_types = 1 );

namespace Obitleague\Domain;

final class Wire_Pause {

	/** Longest pause the automation may ever take. */
	public const MAX_SECONDS = 3600;

	/** First consecutive-failure wait; doubles per further failure. */
	public const BASE_SECONDS = 30;

	/** Shortest wait, so a burst cannot spin the queue. */
	public const MIN_SECONDS = 5;

	private function __construct() {}

	/**
	 * Seconds to wait for a declared Retry-After hint and a run of
	 * consecutive rate-limit failures.
	 *
	 * A genuine hint is honoured on the first failure; once failures repeat
	 * the wait grows exponentially regardless of a stale hint, always inside
	 * [MIN_SECONDS, MAX_SECONDS].
	 *
	 * @param string $retry_after Raw Retry-After header value (may be empty).
	 * @param int    $streak      Consecutive rate-limit responses, 1 or more.
	 */
	public static function seconds( string $retry_after, int $streak ): int {
		$declared = self::retry_after_seconds( $retry_after );
		$streak   = max( 1, $streak );
		// Cap the shift before it overflows, then clamp by the ceiling below.
		$backoff  = self::BASE_SECONDS * ( 2 ** min( $streak - 1, 8 ) );
		$wait     = $declared > 0 ? $declared : $backoff;
		if ( $streak > 1 ) {
			$wait = max( $wait, $backoff );
		}
		return max( self::MIN_SECONDS, min( self::MAX_SECONDS, $wait ) );
	}

	/**
	 * The moment a queued row for one source next becomes eligible: the
	 * later of the source's rate-limit pause, the point the last queued row
	 * for that source has been spaced past, and now.
	 *
	 * Stored timestamps are UTC 'Y-m-d H:i:s'. They are parsed as datetimes,
	 * never cast: casting a datetime string to a number yields only its year,
	 * which silently produced a timestamp like "2027" — a value MySQL then
	 * coerced to zero, so every row sharing it read as due at once and the
	 * per-source spacing the queue promises was lost.
	 *
	 * @param string $last_attempt Latest queued next_attempt_at, '' when none.
	 * @param int    $gap_seconds  Minimum spacing between two same-source calls.
	 * @param string $now          Current UTC time, Y-m-d H:i:s.
	 * @param int    $pause_until  Unix timestamp a rate-limit pause lifts at.
	 * @return string UTC 'Y-m-d H:i:s'.
	 */
	public static function next_attempt_at( string $last_attempt, int $gap_seconds, string $now, int $pause_until ): string {
		$out = self::parse_utc( $now ) ?? time();
		if ( $pause_until > $out ) {
			$out = $pause_until;
		}
		$last = self::parse_utc( $last_attempt );
		if ( null !== $last ) {
			$spaced = $last + max( 0, $gap_seconds );
			if ( $spaced > $out ) {
				$out = $spaced;
			}
		}
		return gmdate( 'Y-m-d H:i:s', $out );
	}

	/**
	 * Parse a stored UTC 'Y-m-d H:i:s' into a Unix timestamp, null when it is
	 * empty or unusable (including the zero dates a bad write can leave).
	 */
	private static function parse_utc( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$moment = strtotime( $value . ' UTC' );
		return ( false === $moment || $moment <= 0 ) ? null : (int) $moment;
	}

	/** Parse a Retry-After value into non-negative seconds, 0 when unusable. */
	public static function retry_after_seconds( string $retry_after ): int {
		$retry_after = trim( $retry_after );
		if ( '' === $retry_after ) {
			return 0;
		}
		if ( is_numeric( $retry_after ) ) {
			return max( 0, (int) $retry_after );
		}
		$moment = strtotime( $retry_after );
		if ( false === $moment ) {
			return 0;
		}
		return max( 0, $moment - time() );
	}
}

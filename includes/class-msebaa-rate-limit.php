<?php
/**
 * Soft rate limiting for password-reset requests.
 *
 * @package Msebaa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed per-visitor rate limiter for password-reset attempts.
 *
 * The counter uses a fixed window: the first recorded attempt opens the window
 * and later attempts inside it reuse the remaining TTL rather than extending it.
 */
class Msebaa_Rate_Limit {

	/**
	 * Transient key prefix.
	 */
	public const KEY_PREFIX = 'msebaa_pwreset_';

	/**
	 * Window length in seconds.
	 */
	public const WINDOW = 3600;

	/**
	 * Attempts allowed per visitor per window.
	 */
	public const LIMIT = 5;

	/**
	 * Build an opaque per-visitor identifier from IP and user agent.
	 *
	 * Uses wp_hash() so the raw address never reaches storage.
	 */
	public static function visitor_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filter the visitor IP used for password-reset rate limiting.
		 *
		 * Sites behind a trusted reverse proxy can substitute the forwarded
		 * address here. Only override when the header cannot be spoofed.
		 *
		 * @param string $ip Remote address from the current request.
		 */
		$ip = (string) apply_filters( 'msebaa_rate_limit_visitor_ip', $ip );

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		return wp_hash( $ip . '|' . $user_agent );
	}

	/**
	 * Transient key for a visitor.
	 *
	 * @param string $visitor_key Opaque visitor identifier; defaults to the current visitor.
	 */
	public static function key( string $visitor_key = '' ): string {
		if ( '' === $visitor_key ) {
			$visitor_key = self::visitor_key();
		}

		return self::KEY_PREFIX . $visitor_key;
	}

	/**
	 * Attempts recorded in the current window.
	 *
	 * @param string $visitor_key Opaque visitor identifier; defaults to the current visitor.
	 */
	public static function attempts( string $visitor_key = '' ): int {
		$window = self::read_window( $visitor_key );

		return null === $window ? 0 : $window['count'];
	}

	/**
	 * True when the visitor has used up the window allowance.
	 *
	 * @param string $visitor_key Opaque visitor identifier; defaults to the current visitor.
	 */
	public static function is_limited( string $visitor_key = '' ): bool {
		return self::attempts( $visitor_key ) >= self::LIMIT;
	}

	/**
	 * Record one attempt against the visitor allowance.
	 *
	 * Call only when the visitor was under the limit and a MemberSuite reset
	 * call was attempted, including when that call failed in transport.
	 *
	 * @param string $visitor_key Opaque visitor identifier; defaults to the current visitor.
	 * @return int Attempts recorded in the window after this call.
	 */
	public static function record_attempt( string $visitor_key = '' ): int {
		if ( '' === $visitor_key ) {
			$visitor_key = self::visitor_key();
		}

		$now    = time();
		$window = self::read_window( $visitor_key );

		if ( null === $window ) {
			$window = array(
				'count'   => 0,
				'expires' => $now + self::WINDOW,
			);
		}

		++$window['count'];

		$ttl = max( 1, $window['expires'] - $now );

		set_transient( self::key( $visitor_key ), $window, $ttl );

		return $window['count'];
	}

	/**
	 * Clear the counter for a visitor.
	 *
	 * @param string $visitor_key Opaque visitor identifier; defaults to the current visitor.
	 */
	public static function clear( string $visitor_key = '' ): void {
		delete_transient( self::key( $visitor_key ) );
	}

	/**
	 * Read the stored window, or null when no live window exists.
	 *
	 * @param string $visitor_key Opaque visitor identifier; defaults to the current visitor.
	 * @return array{count: int, expires: int}|null
	 */
	private static function read_window( string $visitor_key = '' ) {
		$stored = get_transient( self::key( $visitor_key ) );

		if ( ! is_array( $stored ) || ! isset( $stored['count'], $stored['expires'] ) ) {
			return null;
		}

		$expires = (int) $stored['expires'];

		if ( $expires <= time() ) {
			return null;
		}

		return array(
			'count'   => absint( $stored['count'] ),
			'expires' => $expires,
		);
	}
}

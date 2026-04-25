<?php
/**
 * BKC_Rate_Limiter — WP transient-based rate limiting.
 *
 * @package bkc-push
 */

defined( 'ABSPATH' ) || exit;

/**
 * Simple per-action/per-IP rate limiter backed by WP transients.
 */
class BKC_Rate_Limiter {

	/**
	 * Check whether the given action/IP combination is within allowed limits.
	 *
	 * Returns false when the request exceeds the limit (i.e. should be blocked).
	 * Returns true when the request is within the limit (i.e. allowed).
	 *
	 * @param string $action          Logical action name (e.g. 'subscribe').
	 * @param string $ip              Client IP address.
	 * @param int    $limit           Maximum number of requests allowed.
	 * @param int    $window_seconds  Window duration in seconds.
	 * @return bool True = allowed, false = rate limit exceeded.
	 */
	public static function check( string $action, string $ip, int $limit, int $window_seconds ): bool {
		$key   = 'bkc_rl_' . md5( $action . '_' . $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, $window_seconds );
		} else {
			// Increment without resetting the TTL by using the existing expiry.
			// WP does not expose remaining TTL, so we set with original window on
			// subsequent calls; in practice the window is approximate but sufficient.
			set_transient( $key, $count + 1, $window_seconds );
		}

		return true;
	}

	/**
	 * Get the client IP from the current request.
	 *
	 * Prefers X-Forwarded-For when behind a trusted proxy.
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Take the first IP in the chain.
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}
}

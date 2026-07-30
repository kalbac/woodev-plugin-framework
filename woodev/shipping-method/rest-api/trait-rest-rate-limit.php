<?php
/**
 * Woodev REST Rate-Limit + Param-Hygiene Trait
 *
 * Shared by the framework's public, guest-reachable `woodev/v1` REST controllers
 * ({@see Field_Source_Controller}, {@see Pickup_Controller}) so the per-IP rate limit,
 * client-IP resolution and free-text param capping exist in exactly one place, with
 * exactly one copy of the security caveats that go with them. Both consumers are
 * public, unauthenticated endpoints, but they serve different workloads (a cascade
 * dropdown fired once on user selection vs. a map firing continuously while the
 * customer pans/zooms) — this trait owns only the mechanism; each consumer supplies
 * its own key prefix and budget per call, so one workload never consumes another's.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! trait_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Rest_Rate_Limit_Trait' ) ) :

	/**
	 * Rate-limit + param-hygiene helpers for a public REST controller.
	 *
	 * @since 2.0.2
	 */
	trait Rest_Rate_Limit_Trait {

		/**
		 * Best-effort per-IP rate limit (bar-raiser), fixed-window.
		 *
		 * This is a WEAK defense — it is trivially defeated by proxies and does not
		 * account for shared or rotating IPv6 — but it raises the cost of trivial abuse
		 * of a public endpoint. Allows `$max` requests per client IP within `$window`
		 * seconds, tracked in a transient keyed by `$key_prefix` + the hashed client IP.
		 *
		 * FIXED window, not sliding: the stored state is `{ count, reset }`. A request
		 * within the current window only increments `count`; the transient's own TTL is
		 * refreshed on every write, but that TTL is a garbage-collection hint ONLY — it
		 * must never be what closes the window. The `reset` timestamp inside the stored
		 * value is what actually does that, via the `time() >= reset` check below. An
		 * earlier version tied window closure to the transient's TTL itself, so every
		 * accepted request re-armed the full TTL and the window never lapsed under
		 * sustained traffic — a customer panning a map at roughly one request per
		 * second exhausted the budget and stayed locked out until they stopped moving
		 * for a full window, which is the wrong failure mode for a continuous-interaction
		 * surface. Storing `reset` explicitly and comparing against it (rather than
		 * inferring window closure from the transient having expired) fixes that: the
		 * window closes on schedule regardless of how many accepted requests refreshed
		 * the transient's TTL in the meantime.
		 *
		 * Overridable so tests can bypass it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key_prefix transient key prefix; give each distinct workload
		 *                           (route / bucket) its OWN prefix so one does not
		 *                           consume another's budget.
		 * @param int    $max        requests allowed per IP within `$window`.
		 * @param int    $window     window length, in seconds. Written as a literal
		 *                           default rather than WordPress's own
		 *                           `MINUTE_IN_SECONDS`, so a class using this trait can
		 *                           load and be unit-tested before WordPress's core time
		 *                           constants exist — "WP-free", not "WC-free":
		 *                           `MINUTE_IN_SECONDS` is a WordPress constant, not a
		 *                           WooCommerce one.
		 *
		 * @return bool true when the caller has exceeded the current window's budget.
		 */
		protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {

			$ip = $this->get_client_ip();

			if ( '' === $ip ) {
				return false;
			}

			$key       = $key_prefix . md5( $ip );
			$now       = time();
			$state     = get_transient( $key );
			$is_stored = is_array( $state ) && isset( $state['count'], $state['reset'] );

			if ( ! $is_stored || $now >= (int) $state['reset'] ) {
				$state = [
					'count' => 0,
					'reset' => $now + $window,
				];
			}

			if ( $state['count'] >= $max ) {
				return true;
			}

			++$state['count'];

			// TTL is a garbage-collection hint only — see the docblock above; the
			// `reset` timestamp inside $state is what actually gates the window.
			set_transient( $key, $state, $window );

			return false;
		}

		/**
		 * Resolves the client IP for the rate-limit key.
		 *
		 * Uses WooCommerce's geolocation helper when present (which already applies the
		 * trusted-proxy logic), falling back to the raw remote address.
		 *
		 * @since 2.0.2
		 *
		 * @return string sanitized client IP, or '' when unknown.
		 */
		protected function get_client_ip(): string {

			if ( class_exists( '\\WC_Geolocation' ) ) {
				return (string) \WC_Geolocation::get_ip_address();
			}

			return isset( $_SERVER['REMOTE_ADDR'] )
				? (string) wc_clean( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '';
		}

		/**
		 * Caps a string to `$max_length` characters (multibyte-aware).
		 *
		 * @since 2.0.2
		 *
		 * @param string $value      value to cap.
		 * @param int    $max_length maximum length, in characters.
		 *
		 * @return string
		 */
		protected function cap_length( string $value, int $max_length ): string {
			return function_exists( 'mb_substr' )
				? mb_substr( $value, 0, $max_length )
				: substr( $value, 0, $max_length );
		}
	}

endif;

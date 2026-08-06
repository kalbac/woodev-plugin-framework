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
		 * The bucket identity used when the web server reported no usable address at all.
		 *
		 * A LITERAL bucket, deliberately, not a bypass: every unattributable request in the
		 * install shares this one budget. See {@see self::is_rate_limited()} for the bug
		 * this replaced.
		 *
		 * A method rather than the `const` this obviously wants to be: constants in a TRAIT
		 * are PHP 8.2+, and this framework targets PHP 7.4. Same for the two below.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function rate_limit_unknown_identity(): string {
			return 'unknown';
		}

		/**
		 * How much larger the coarse (connection-address) budget is than the fine
		 * (client-address) one — see {@see self::is_rate_limited()}'s TWO BUCKETS section.
		 *
		 * Overridable, so a consumer whose workload needs a different ratio can say so; 10 is
		 * chosen so that a store behind a reverse proxy — where every customer shares one
		 * connection address — can run roughly ten concurrent customers at full budget before
		 * the coarse bound engages, while an attacker forging a fresh client address per
		 * request is bounded at ten times the budget rather than at nothing.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		protected function rate_limit_edge_multiplier(): int {
			return 10;
		}

		/**
		 * Object-cache group the counters live in on an install with a persistent cache.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function rate_limit_cache_group(): string {
			return 'woodev_rate_limit';
		}

		/**
		 * Best-effort per-address rate limit (bar-raiser), fixed-window.
		 *
		 * This is a WEAK defense — a distributed caller with many real source addresses
		 * defeats it, and it does not account for shared or rotating IPv6 — but it raises the
		 * cost of trivial abuse of a public endpoint, and on the `.../select` route it is the
		 * only thing standing between a scripted client holding a valid nonce and the
		 * merchant's carrier quota.
		 *
		 * FIXED window, keyed by window id. The stored value is a bare COUNTER and the window
		 * id — `floor( now / window )` — is part of the KEY, so a window closes on wall-clock
		 * schedule no matter how many accepted requests refreshed the storage TTL in the
		 * meantime, and the TTL is a pure garbage-collection hint. An earlier version tied
		 * window closure to the TTL itself, so every accepted request re-armed the full TTL
		 * and the window never lapsed under sustained traffic — a customer panning a map at
		 * roughly one request per second exhausted the budget and stayed locked out until
		 * they stopped moving for a full window, which is the wrong failure mode for a
		 * continuous-interaction surface. (The intermediate fix stored an explicit `reset`
		 * timestamp beside the count; putting the window id in the key achieves the same
		 * thing and additionally lets the value be a bare integer, which is what makes the
		 * atomic increment below possible at all.) The standard fixed-window caveat applies:
		 * a caller can spend one budget just before a boundary and another just after.
		 *
		 * INCREMENT FIRST, COMPARE SECOND. The count is never read into PHP, compared, and
		 * written back — that read-compare-write is what let sixteen concurrent requests all
		 * read `count = 0`, all pass, and all reach the carrier. The counter is bumped by one
		 * indivisible operation ({@see self::increment_rate_limit_counter()}) and the value it
		 * returns is what the budget is compared against, so concurrent requests get distinct
		 * values and exactly `$max` of them can be under budget. A racing read-back can only
		 * over-count, which fails CLOSED.
		 *
		 * TWO BUCKETS, and why the obvious single bucket is wrong either way it is written:
		 *
		 * - Bucketing on the CLIENT address alone (what WooCommerce's geolocation helper
		 *   yields) is forgeable. That helper prioritises an unvalidated `X-Real-IP` and
		 *   otherwise takes the first entry of `X-Forwarded-For`; on any install whose edge
		 *   does not overwrite those headers, the caller picks its own bucket, and rotating
		 *   the header per request means no bucket ever fills.
		 * - Bucketing on the CONNECTION address alone is unforgeable but collapses every
		 *   customer of a store behind Cloudflare or an nginx front end into ONE budget, so a
		 *   handful of simultaneous shoppers would throttle each other out of a working map.
		 *
		 * So both are enforced: the client address gets the stated budget (fairness), and the
		 * connection address — which the caller cannot choose — gets
		 * {@see self::rate_limit_edge_multiplier()} times it (the bound). The coarse bucket is
		 * only spent when the two identities actually differ, i.e. when a forwarding header
		 * was involved at all, so a plain direct request still costs exactly one bucket.
		 *
		 * An install that knows its own trusted-proxy boundary should say so through
		 * `woodev_rest_rate_limit_client_ip` (see {@see self::get_client_ip()}); the fine
		 * bucket then carries a verified address rather than a best-effort one.
		 *
		 * Overridable so tests can bypass it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key_prefix transient key prefix; give each distinct workload
		 *                           (route / bucket) its OWN prefix so one does not
		 *                           consume another's budget.
		 * @param int    $max        requests allowed per client address within `$window`.
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

			$window = max( 1, $window );
			$max    = max( 0, $max );

			$edge   = $this->get_edge_ip();
			$client = $this->get_client_ip();

			if ( $client !== $edge
				&& $this->bucket_exhausted(
					$key_prefix . 'edge_',
					$edge,
					$max * $this->rate_limit_edge_multiplier(),
					$window
				) ) {
				return true;
			}

			return $this->bucket_exhausted( $key_prefix, $client, $max, $window );
		}

		/**
		 * Spends one request from a single bucket and reports whether it was already full.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key_prefix storage key prefix for this bucket.
		 * @param string $identity   the address (or {@see self::rate_limit_unknown_identity()})
		 *                           the bucket is keyed on.
		 * @param int    $max        requests allowed within `$window`.
		 * @param int    $window     window length, in seconds.
		 *
		 * @return bool
		 */
		private function bucket_exhausted( string $key_prefix, string $identity, int $max, int $window ): bool {

			$key = $key_prefix . md5( $identity ) . '_' . (int) floor( $this->rate_limit_now() / $window );

			// TTL is twice the window purely as a garbage-collection margin — the key itself
			// carries the window id, so an over-long TTL cannot hold a window open.
			return $this->increment_rate_limit_counter( $key, $window * 2 ) > $max;
		}

		/**
		 * Atomically increments one counter and returns its NEW value.
		 *
		 * Two storage paths, and the branch is a correctness requirement rather than an
		 * optimization: on an install with a persistent object cache WordPress keeps
		 * transients in that cache and never writes them to the options table, so a counter
		 * maintained in the table would simply never be seen. Each path uses the atomic
		 * primitive its own backend offers:
		 *
		 * - persistent object cache — `wp_cache_add()` seeds the bucket only if it is absent
		 *   (so it cannot clobber a live count) and `wp_cache_incr()` is a single atomic
		 *   operation in every real backend (Redis `INCRBY`, Memcached `incr`);
		 * - otherwise — one `INSERT … ON DUPLICATE KEY UPDATE option_value = option_value + 1`
		 *   statement, which MySQL executes indivisibly under the options table's unique key
		 *   on `option_name`. The value is read back afterwards rather than returned by the
		 *   write; that read can only observe an EQUAL or HIGHER count (another request's
		 *   later increment), never a lower one, so the comparison it feeds fails closed.
		 *
		 * `protected` so a consumer or a test can substitute a counter without reimplementing
		 * the window/bucket policy above.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key storage key, already carrying its window id.
		 * @param int    $ttl garbage-collection lifetime, in seconds.
		 *
		 * @return int the counter's value after this request's increment.
		 */
		protected function increment_rate_limit_counter( string $key, int $ttl ): int {

			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {

				wp_cache_add( $key, 0, $this->rate_limit_cache_group(), $ttl );

				$count = wp_cache_incr( $key, 1, $this->rate_limit_cache_group() );

				if ( false !== $count ) {
					return (int) $count;
				}
			}

			return $this->increment_stored_counter( $key, $ttl );
		}

		/**
		 * The options-table half of {@see self::increment_rate_limit_counter()}.
		 *
		 * Writes the transient's two rows itself instead of going through
		 * `get_transient()`/`set_transient()`: those are a read-modify-write pair by
		 * construction and cannot be made atomic from PHP. The rows written are exactly the
		 * shape WordPress's own transient API expects (`_transient_{key}` plus
		 * `_transient_timeout_{key}`, `autoload` off), so `delete_expired_transients()` still
		 * collects them on schedule and nothing is left orphaned in the table.
		 *
		 * Falls back to the non-atomic transient pair only when there is no `$wpdb` at all —
		 * a harness without WordPress's database layer, never a real request.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key storage key, already carrying its window id.
		 * @param int    $ttl garbage-collection lifetime, in seconds.
		 *
		 * @return int
		 */
		private function increment_stored_counter( string $key, int $ttl ): int {

			global $wpdb;

			/**
			 * Duck-typed rather than `instanceof \wpdb`, deliberately: the installed-site type
			 * is always `\wpdb`, but a drop-in (`db.php`) may substitute its own, and this
			 * trait's unit tests drive the SQL path through a lightweight double instead of
			 * standing up a database. The `@var` tells the analyser what the real one is.
			 *
			 * @var \wpdb $wpdb
			 */
			if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {

				$count = (int) get_transient( $key ) + 1;

				set_transient( $key, $count, $ttl );

				return $count;
			}

			$option = '_transient_' . $key;

			// The expiry row first: a counter row that outlived its sibling would never be
			// collected. INSERT IGNORE, so a concurrent request creating it first is fine.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
					'_transient_timeout_' . $key,
					(string) ( $this->rate_limit_now() + $ttl )
				)
			);

			// THE atomic step — see this method's docblock. One statement: create at 1, or
			// increment in place. Never a read, a comparison and a write back.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
					$option
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$option
				)
			);

			// A read that failed outright (a dropped connection, a replica that has not caught
			// up) is treated as this request's own first hit rather than as an exhausted
			// budget: a database hiccup must not lock a customer out of the checkout.
			return is_numeric( $count ) ? (int) $count : 1;
		}

		/**
		 * The current time, as one overridable seam so a test can drive window rollover
		 * without sleeping.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		protected function rate_limit_now(): int {
			return time();
		}

		/**
		 * The address the web server itself observed for this connection.
		 *
		 * `REMOTE_ADDR` is the ONE address in the request the caller cannot choose: it is
		 * whatever the TCP peer actually is. Behind a reverse proxy that peer is the proxy,
		 * so this is a coarse identity — but it is an honest one, which is why
		 * {@see self::is_rate_limited()} uses it as the bound that cannot be rotated away.
		 *
		 * Falls back to {@see self::rate_limit_unknown_identity()} — a real, shared bucket —
		 * when there is no usable address, never to a value that disables the limit.
		 *
		 * @since 2.0.2
		 *
		 * @return string a validated IP address, or {@see self::rate_limit_unknown_identity()}.
		 */
		protected function get_edge_ip(): string {

			$remote = isset( $_SERVER['REMOTE_ADDR'] )
				? (string) wc_clean( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '';

			return self::valid_ip( $remote ) ?? $this->rate_limit_unknown_identity();
		}

		/**
		 * Resolves the address the fine-grained bucket is keyed on.
		 *
		 * Starts from WooCommerce's geolocation helper, which is forwarding-header aware, but
		 * treats its answer as a HINT rather than as an identity: the helper prioritises an
		 * unvalidated `X-Real-IP` and otherwise takes the first `X-Forwarded-For` entry, both
		 * of which a caller can set freely on an install whose edge does not overwrite them,
		 * and it yields `''` for a header that is not an address at all. A hint that does not
		 * validate as an IP is discarded in favour of {@see self::get_edge_ip()} — it must
		 * never become an empty identity, and an empty identity must never become a bypass.
		 *
		 * The forgeability that remains is bounded, not trusted: see
		 * {@see self::is_rate_limited()}'s TWO BUCKETS section.
		 *
		 * @since 2.0.2
		 *
		 * @return string a validated IP address, or {@see self::get_edge_ip()}'s answer.
		 */
		protected function get_client_ip(): string {

			$edge = $this->get_edge_ip();

			$forwarded = class_exists( '\\WC_Geolocation' )
				? self::valid_ip( (string) \WC_Geolocation::get_ip_address() )
				: null;

			/**
			 * Filters the address a public Woodev REST route buckets its rate limit under.
			 *
			 * THE TRUSTED-PROXY BOUNDARY HOOK. The framework cannot know which forwarding
			 * header an install's own edge rewrites, and guessing is what makes a header-derived
			 * address forgeable in the first place. An install that DOES know — "our nginx sets
			 * `X-Real-IP` and strips whatever the client sent", "we are behind Cloudflare, use
			 * `CF-Connecting-IP`" — returns the verified client address here, and the fine
			 * bucket becomes an identity rather than a hint.
			 *
			 * A returned value that is not a valid IP address is discarded, so a filter cannot
			 * weaken the limit by returning `''` or junk.
			 *
			 * @since 2.0.2
			 *
			 * @param string      $address   the best-effort client address: the forwarding-header
			 *                               hint when it validated, else `$edge`.
			 * @param string      $edge      the address the web server observed for this
			 *                               connection — never caller-controlled.
			 * @param string|null $forwarded the validated forwarding-header hint, or null when
			 *                               there was none or it did not validate.
			 */
			$client = apply_filters( 'woodev_rest_rate_limit_client_ip', $forwarded ?? $edge, $edge, $forwarded );

			return is_string( $client ) ? ( self::valid_ip( $client ) ?? $edge ) : $edge;
		}

		/**
		 * Returns `$ip` when it is a real IPv4/IPv6 address, else null.
		 *
		 * `filter_var()`, not WordPress's `rest_is_ip_address()`: this trait's whole point is
		 * to be loadable and unit-testable without WordPress present (see
		 * {@see self::is_rate_limited()}'s `$window` note), and the PHP built-in answers the
		 * same question.
		 *
		 * @since 2.0.2
		 *
		 * @param string $ip candidate address.
		 *
		 * @return string|null
		 */
		private static function valid_ip( string $ip ): ?string {

			$ip = trim( $ip );

			return '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
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

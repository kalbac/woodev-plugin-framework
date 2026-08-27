<?php
/**
 * Woodev Location Resolution Cache
 *
 * Lazy, session-cached adapter resolution behind the Location Provider layer (Task 5;
 * spec D9, §4.3): every shipping plugin participating in the layer translates the
 * customer's neutral {@see Location_Record} into its own carrier identity through a
 * mandatory {@see Location_Adapter}. Running that translation is the plugin's job;
 * running it LAZILY (only when a rate/points calculation actually needs it, never at
 * selection time) and caching BOTH outcomes per `(locality_key, plugin_id)` for the
 * rest of the session is this class's entire job.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Resolution_Cache' ) ) :

	/**
	 * Runs a plugin's {@see Location_Adapter} lazily and caches the result in
	 * `WC()->session`.
	 *
	 * Stored shape, under ONE framework-owned session key
	 * ({@see self::STORAGE_KEY}):
	 *
	 * ```
	 * [
	 *     <locality_key> => [
	 *         <plugin_id> => [ 'v' => <mixed|null>, 'ok' => bool, 't' => <int unix timestamp> ],
	 *         ...
	 *     ],
	 *     ...
	 * ]
	 * ```
	 *
	 * `ok` is `true` when the adapter resolved to a real (possibly falsy —
	 * `0`, `''`, `false`, `[]` are all legitimate carrier identities) value, and
	 * `false` when it resolved to `null` ("this carrier does not serve this
	 * locality" — spec §4.3, a legitimate answer, not an error). BOTH are cache
	 * HITS: {@see self::resolve_for()} returns `v` without calling the adapter
	 * again either way. The bucket's mere PRESENCE — not `ok`, not the
	 * truthiness of `v` — is what distinguishes "resolved" from "never asked";
	 * {@see self::has()} exposes exactly that presence check, because a naive
	 * `empty( $entry['v'] )`/`?? `-style read would silently mistake a cached
	 * `0`/`''`/`false`/`[]` identity for a cache miss and re-call the adapter on
	 * every read — the classic bug this shape exists to avoid.
	 *
	 * A THROW from the adapter is a third, DIFFERENT outcome from both of the
	 * above: it is logged and re-thrown to the caller, and — critically —
	 * NOTHING is written to the cache, so the very next
	 * {@see self::resolve_for()} call for the same `(locality_key, plugin_id)`
	 * calls the adapter again (see {@see Location_Adapter::resolve()} for why
	 * a throw, not `null`, is the correct signal for a transient failure).
	 *
	 * @since 2.0.2
	 */
	class Location_Resolution_Cache {

		/**
		 * The single framework-owned `WC()->session` key this class owns. A NEW
		 * key (installed-site data contracts untouched — CLAUDE.md "never
		 * break" list).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const STORAGE_KEY = 'woodev_location_resolution_cache';

		/**
		 * Filter tag: lets a site override how long (in seconds) a cached entry
		 * stays valid before {@see self::resolve_for()} treats it as absent and
		 * re-runs the adapter.
		 *
		 * Defaults to `0`, meaning NO expiry — honestly, not merely unread: a
		 * `0`-or-lower value (the default, and anything a misbehaving callback
		 * returns that is not a positive number) disables the TTL check
		 * entirely, {@see self::read_entry()} never even looks at the stored
		 * timestamp. This default is deliberate, not an oversight: staleness in
		 * this cache is already handled BY CONSTRUCTION — a provider switch or a
		 * locality change produces a different `locality_key` and therefore a
		 * different cache slot (spec D5/D9), and a real customer re-selection
		 * fires the explicit `update_checkout` (spec D8) that drives a fresh
		 * rate calculation anyway. A store that still wants entries to expire
		 * after N seconds (e.g. a carrier whose serviceable-area list itself
		 * changes over time, independent of any customer action) sets this
		 * filter to a positive value and gets REAL, honored expiry — a stamped
		 * `t` on every write, compared against `time()` on every read.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_TTL = 'woodev_location_resolution_cache_ttl';

		/**
		 * Resolves `$plugin`'s carrier identity for `$record`'s locality —
		 * cached.
		 *
		 * Order of operations: (1) a usable, non-expired cache entry short
		 * circuits everything below and is returned as-is, without ever
		 * touching {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_location_adapter()}
		 * (true laziness: a configured-but-never-needed plugin's adapter is
		 * never even fetched on a hit); (2) otherwise the adapter obligation is
		 * checked — a plugin declaring {@see \Woodev\Framework\Shipping\Shipping_Plugin::needs_location_provider()}
		 * `true` but returning `null` from `get_location_adapter()` is a plugin
		 * bug, reported via `_doing_it_wrong()`, and resolves to `null`
		 * (uncached, so a later fix is picked up on the very next call); (3)
		 * otherwise the adapter runs — a thrown `\Throwable` is logged and
		 * RE-THROWN uncached (transient, retryable, see
		 * {@see Location_Adapter::resolve()}), a normal return (including
		 * `null`) is cached and returned.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Shipping\Shipping_Plugin $plugin The plugin whose
		 *                                                           adapter should resolve
		 *                                                           `$record`.
		 * @param Location_Record                            $record The customer's
		 *                                                            current location
		 *                                                            record.
		 *
		 * @return mixed|null The plugin's carrier identity for this locality
		 *                     (opaque to the framework), or `null` when the
		 *                     carrier does not serve it.
		 *
		 * @throws \Throwable Re-thrown, after logging, when the adapter itself
		 *                     threw — see the class docblock.
		 */
		public function resolve_for( \Woodev\Framework\Shipping\Shipping_Plugin $plugin, Location_Record $record ) {
			$locality_key = $record->key();
			$plugin_id    = (string) $plugin->get_id();
			$cacheable    = self::is_usable_key( $locality_key ) && self::is_usable_key( $plugin_id );

			if ( $cacheable ) {
				$entry = $this->read_entry( $locality_key, $plugin_id );

				if ( null !== $entry ) {
					return $entry['v'];
				}
			}

			$adapter = $plugin->get_location_adapter();

			if ( null === $adapter ) {
				if ( $plugin->needs_location_provider() ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							'Shipping plugin "%s" declares needs_location_provider() but get_location_adapter() returns null; a participating plugin MUST supply a Location_Adapter (spec §4.3).',
							$plugin_id
						),
						'2.0.2'
					);
				}

				return null;
			}

			try {
				$value = $adapter->resolve( $record );
			} catch ( \Throwable $throwable ) {
				error_log(
					sprintf(
						'[woodev] location adapter "%s" (plugin "%s") resolve() failed for locality "%s": %s',
						get_class( $adapter ),
						$plugin_id,
						$locality_key,
						\Woodev_API_Base::redact_secret_log_text( $throwable->getMessage() )
					)
				); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- loud-but-contained boundary; a transient adapter failure must never be cached, and the caller decides how to handle the exception (spec: throw = retryable).

				throw $throwable;
			}

			if ( $cacheable ) {
				$this->write_entry( $locality_key, $plugin_id, $value );
			}

			return $value;
		}

		/**
		 * Whether a cache entry exists for `(record's locality key, plugin id)`
		 * — a resolved identity OR a cached `null` "does not serve" answer —
		 * WITHOUT running the adapter.
		 *
		 * This is the seam that lets a caller (or a test) tell a cached failure
		 * apart from "never asked": both {@see self::resolve_for()} calls
		 * return `null` for a locality this carrier does not serve, but only
		 * the SECOND call (after the first cached it) has `has()` return `true`
		 * beforehand.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Shipping\Shipping_Plugin $plugin Plugin to check.
		 * @param Location_Record                            $record Location record
		 *                                                            whose locality
		 *                                                            key to check.
		 *
		 * @return bool
		 */
		public function has( \Woodev\Framework\Shipping\Shipping_Plugin $plugin, Location_Record $record ): bool {
			$locality_key = $record->key();
			$plugin_id    = (string) $plugin->get_id();

			if ( ! self::is_usable_key( $locality_key ) || ! self::is_usable_key( $plugin_id ) ) {
				return false;
			}

			return null !== $this->read_entry( $locality_key, $plugin_id );
		}

		/**
		 * Reads the live `WC()->session`, or `null` when WooCommerce is
		 * unavailable or no session has been started yet.
		 *
		 * `protected` as a test seam — same shape and reasoning as
		 * {@see Customer_Location_Store::session()} (Task 4): a probe overrides
		 * this single line rather than `WC()` needing to be a real function in
		 * the unit-test process.
		 *
		 * @since 2.0.2
		 *
		 * @return \WC_Session|null
		 */
		protected function session() {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return null;
			}

			return WC()->session;
		}

		/**
		 * Reads one `(locality_key, plugin_id)` entry, honoring the TTL filter.
		 *
		 * `null` when: no session is available (degradation — see the class
		 * docblock and {@see Location_Resolution_Cache} generally: an
		 * uncacheable environment still RESOLVES, it just never reaches this
		 * method with anything to find), the stored cache blob is not an array,
		 * no bucket exists for this pair, the bucket is malformed (missing `v`
		 * or `ok` — defends the same way {@see Customer_Location_Store::parse_stored()}
		 * defends against a corrupt/legacy blob), or the entry has expired per
		 * {@see self::FILTER_TTL}.
		 *
		 * @since 2.0.2
		 *
		 * @param string $locality_key The record's locality key.
		 * @param string $plugin_id    The plugin's id.
		 *
		 * @return array{v: mixed, ok: bool, t: int}|null
		 */
		private function read_entry( string $locality_key, string $plugin_id ): ?array {
			$session = $this->session();

			if ( null === $session ) {
				return null;
			}

			$cache = $session->get( self::STORAGE_KEY );

			if ( ! is_array( $cache ) ) {
				return null;
			}

			$bucket = $cache[ $locality_key ][ $plugin_id ] ?? null;

			if ( ! is_array( $bucket ) || ! array_key_exists( 'v', $bucket ) || ! array_key_exists( 'ok', $bucket ) ) {
				return null;
			}

			/**
			 * Filters the resolution cache entry TTL, in seconds.
			 *
			 * @since 2.0.2
			 *
			 * @param int $ttl `0` (default) disables expiry entirely; a positive
			 *                 value expires an entry `$ttl` seconds after it was
			 *                 written. See {@see Location_Resolution_Cache::FILTER_TTL}'s
			 *                 own docblock for the full rationale.
			 */
			$ttl = (int) apply_filters( self::FILTER_TTL, 0 );

			if ( $ttl > 0 ) {
				$stamped_at = isset( $bucket['t'] ) && is_numeric( $bucket['t'] ) ? (int) $bucket['t'] : 0;

				if ( ( time() - $stamped_at ) >= $ttl ) {
					return null; // Expired: treated exactly like "never asked" — the caller re-resolves and re-writes.
				}
			}

			return $bucket;
		}

		/**
		 * Writes one `(locality_key, plugin_id)` entry. A missing/uninitialized
		 * session is a silent no-op — see the class docblock's degradation
		 * note; the resolved value has already been returned to the caller by
		 * {@see self::resolve_for()} regardless of whether this succeeds.
		 *
		 * @since 2.0.2
		 *
		 * @param string $locality_key The record's locality key.
		 * @param string $plugin_id    The plugin's id.
		 * @param mixed  $value        The adapter's resolved value (possibly `null`).
		 *
		 * @return void
		 */
		private function write_entry( string $locality_key, string $plugin_id, $value ): void {
			$session = $this->session();

			if ( null === $session ) {
				return;
			}

			$cache = $session->get( self::STORAGE_KEY );

			if ( ! is_array( $cache ) ) {
				$cache = [];
			}

			$cache[ $locality_key ][ $plugin_id ] = [
				'v'  => $value,
				'ok' => null !== $value,
				't'  => time(),
			];

			$session->set( self::STORAGE_KEY, $cache );
		}

		/**
		 * Whether a string can serve as a dimension of the composite cache key.
		 *
		 * A record's own `locality_key` ({@see Location_Record::key()}) is
		 * ALREADY guaranteed non-empty by {@see Location_Record::from_array()}'s
		 * own construction-time validation — this class can never observe an
		 * empty one through that dimension. `plugin_id` (a plain
		 * `Shipping_Plugin::get_id()` string) carries no such construction-time
		 * guarantee, so this guard is this class's own half of the empty-key
		 * discipline (gotcha `an-empty-domain-key-is-not-a-key`): refusing BOTH
		 * the write and the read for an unnameable dimension, exactly as that
		 * gotcha prescribes, rather than letting every plugin with a blank id
		 * collapse into one shared `''` bucket that a later, differently-blank
		 * plugin could misread as its own answer.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key Candidate key dimension.
		 *
		 * @return bool
		 */
		private static function is_usable_key( string $key ): bool {
			return '' !== $key;
		}
	}

endif;

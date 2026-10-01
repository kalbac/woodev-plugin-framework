<?php
/**
 * Woodev Shipping Rate Cache
 *
 * Transient-backed cache of SUCCESSFUL shipping rate calculations (shipping module
 * decisions §6 / SP-6, issue #958).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Rate_Cache' ) ) :

	/**
	 * Caches the {@see Shipping_Rate} a method produced for a package, so the carrier API is not
	 * called again on every `update_order_review` (each checkout field change).
	 *
	 * OPT-IN, OFF BY DEFAULT. A cache that is wrong by default is worse than no cache: the framework
	 * cannot see what a carrier's price depends on (credentials, origin, a request parameter), so a
	 * method is cached only when it declares {@see Shipping_Method::FEATURE_RATE_CACHE} — which is a
	 * promise that {@see Shipping_Method::get_rate_cache_context()} names every input of its rate. A
	 * method that did not declare it never touches a transient. {@see self::FILTER_ENABLED} stays as a
	 * further veto.
	 *
	 * WHY TRANSIENTS. The decision is a cache that works for every merchant. `wp_cache_*` — what the
	 * v1 CDEK plugin used — lives for ONE request without a persistent object cache, which is the
	 * common case and exactly the case that hurts: every AJAX refresh is a new request. A transient
	 * is a persistent object-cache entry where one exists and a `wp_options` row where it does not.
	 *
	 * WHAT IS CACHED. Only a real {@see Shipping_Rate}, only from a calculation that did not throw.
	 * The caller never hands this class a `null` or an exception, and {@see self::put()} refuses a
	 * rate it cannot store as plain data. «Cache-as-fallback» is rejected by the decision (a stale
	 * tariff would be booked at a price the carrier no longer honours): an error is never cached,
	 * and a cached rate is never served in place of a failed call — the entry just expires.
	 *
	 * THE PACKAGE IS NEVER STORED. A rate that carries the calculation's package gets the CURRENT
	 * package on a hit (the key discards cart order and package fields it does not price by, so the
	 * stored copy could be stale). A rate carrying a package that is NOT the calculation's own is
	 * simply not cached — there is nothing safe to rebuild it from.
	 *
	 * THE KEY is a hash of {@see Shipping_Method::get_rate_cache_context()} (see its docblock for
	 * the default inputs) plus whatever {@see self::FILTER_KEY_PARTS} adds. The data must be plain,
	 * finite and encodable: an object, a resource, INF/NAN, invalid UTF-8 or a failed encode makes
	 * the key `null`, which DISABLES caching for that call — it never collapses to a shared hash.
	 * A changed instance setting lands on a new key: that is the invalidation, no hook needed.
	 *
	 * @since 2.0.2
	 */
	class Shipping_Rate_Cache {

		/**
		 * Default lifetime of a cached rate, in seconds.
		 *
		 * Ten minutes: long enough to cover a whole checkout session (the customer editing fields
		 * and re-submitting), and to let neighbours with the same cart and destination share one
		 * API call; short enough that a tariff change or a carrier-side outage is felt quickly —
		 * the decision rejects serving stale rates, so the window is deliberately small.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		public const DEFAULT_TTL = 600;

		/**
		 * Filter: whether the cache is used. `apply_filters( tag, bool $enabled, Shipping_Method $method, array $package )`.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_ENABLED = 'woodev_shipping_rate_cache_enabled';

		/**
		 * Filter: lifetime in seconds. `apply_filters( tag, int $ttl, Shipping_Method $method, array $package )`.
		 * Zero or negative disables the cache for that call; a non-numeric return is ignored.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_TTL = 'woodev_shipping_rate_cache_ttl';

		/**
		 * Filter: extra key parts the framework cannot see. `apply_filters( tag, array $parts, Shipping_Method $method, array $package )`.
		 * A return that is not an array is ignored.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_KEY_PARTS = 'woodev_shipping_rate_cache_key_parts';

		/**
		 * Transient name prefix. A 32-char hash follows, well inside the 172-char transient limit.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const TRANSIENT_PREFIX = 'woodev_rate_';

		/**
		 * Stored-shape version. Bump it when {@see self::snapshot()} changes, so an older entry is a miss.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		private const SHAPE_VERSION = 2;

		/**
		 * Deepest array nesting {@see self::is_plain()} accepts. A real key context or rate snapshot is
		 * about five levels deep (package → contents → line → product data → attributes; rate → meta →
		 * value), so 16 leaves wide headroom while still ending a self-referencing array quickly.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		private const MAX_PLAIN_DEPTH = 16;

		/**
		 * Returns the cached rate for this method and package, or `null` on a miss (or a disabled cache).
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Method $method  Method calculating the rate.
		 * @param array           $package WooCommerce shipping package.
		 * @return Shipping_Rate|null
		 */
		public function get( Shipping_Method $method, array $package ): ?Shipping_Rate {

			$key = $this->usable_key( $method, $package );

			if ( null === $key ) {
				return null;
			}

			$stored = get_transient( $key );

			if ( ! is_array( $stored ) || ( $stored['v'] ?? null ) !== self::SHAPE_VERSION || ! isset( $stored['rate'] ) || ! is_array( $stored['rate'] ) ) {
				return null;
			}

			return $this->restore( $stored['rate'], $package );
		}

		/**
		 * Stores a successfully calculated rate.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Method $method  Method that calculated the rate.
		 * @param array           $package WooCommerce shipping package.
		 * @param Shipping_Rate   $rate    The rate to keep.
		 * @return bool Whether the rate was stored.
		 */
		public function put( Shipping_Method $method, array $package, Shipping_Rate $rate ): bool {

			$key = $this->usable_key( $method, $package );

			if ( null === $key ) {
				return false;
			}

			$rate_package = $rate->get_package();

			// A rate carrying some OTHER package cannot be rebuilt on a hit: the stored copy could be stale.
			if ( is_array( $rate_package ) && $rate_package !== $package ) {
				return false;
			}

			$snapshot = $this->snapshot( $rate, $package );

			// A rate carrying an object (a WC_Product inside its package, say) is not plain data: it would
			// be serialised into the database and unserialised on every hit.
			if ( ! self::is_plain( $snapshot ) ) {
				return false;
			}

			return (bool) set_transient(
				$key,
				[
					'v'    => self::SHAPE_VERSION,
					'rate' => $snapshot,
				],
				$this->get_ttl( $method, $package )
			);
		}

		/**
		 * Builds the cache key, or `null` when the call must not be cached: the method cannot be
		 * identified, its context cannot be built, or the context is not plain, finite, encodable data.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Method $method  Method calculating the rate.
		 * @param array           $package WooCommerce shipping package.
		 * @return string|null
		 */
		public function build_key( Shipping_Method $method, array $package ): ?string {

			if ( '' === (string) $method->get_id() ) {
				return null;
			}

			try {
				$parts = $method->get_rate_cache_context( $package );
			} catch ( \Throwable $exception ) {
				// A context that cannot be built is an unknown input: do not cache.
				return null;
			}

			/**
			 * Shipping Rate Cache Key Parts Filter.
			 *
			 * Adds what changes this method's answer but its context does not name. Prefer overriding
			 * {@see Shipping_Method::get_rate_cache_context()} in the carrier; this filter is for code
			 * that does not own the method class. Parts must be plain data (scalars, `null`, arrays) and
			 * finite.
			 *
			 * @since 2.0.2
			 *
			 * @param array           $parts   Key parts.
			 * @param Shipping_Method $method  Method instance.
			 * @param array           $package Package data.
			 */
			$filtered = apply_filters( self::FILTER_KEY_PARTS, $parts, $method, $package );

			if ( is_array( $filtered ) ) {
				$parts = $filtered;
			}

			$hash = self::hash( $parts );

			return null === $hash ? null : self::TRANSIENT_PREFIX . $hash;
		}

		/**
		 * The part of {@see Shipping_Method::get_rate_cache_context()} that comes from the package:
		 * lines (cart-order independent), contents cost, destination down to the street, the store
		 * currency and the dimension/weight units the packer converts with.
		 *
		 * @since 2.0.2
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return array<string, mixed>
		 */
		public static function package_context( array $package ): array {

			$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];

			return [
				'contents'    => self::normalize_contents( $package ),
				'cost'        => isset( $package['contents_cost'] ) && is_scalar( $package['contents_cost'] ) ? (string) $package['contents_cost'] : '',
				'destination' => [
					'country'   => self::normalize_text( $destination['country'] ?? '' ),
					'state'     => self::normalize_text( $destination['state'] ?? '' ),
					'city'      => self::normalize_text( $destination['city'] ?? '' ),
					'postcode'  => str_replace( ' ', '', self::normalize_text( $destination['postcode'] ?? '' ) ),
					'address'   => self::normalize_text( $destination['address'] ?? '' ),
					'address_1' => self::normalize_text( $destination['address_1'] ?? '' ),
					'address_2' => self::normalize_text( $destination['address_2'] ?? '' ),
				],
				'currency'    => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
				'units'       => [
					'dimension' => (string) get_option( 'woocommerce_dimension_unit', '' ),
					'weight'    => (string) get_option( 'woocommerce_weight_unit', '' ),
				],
			];
		}

		/**
		 * Whether the cache is on for this call.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Method $method  Method calculating the rate.
		 * @param array           $package WooCommerce shipping package.
		 * @return bool
		 */
		public function is_enabled( Shipping_Method $method, array $package ): bool {

			/**
			 * Shipping Rate Cache Enabled Filter.
			 *
			 * Return `false` to calculate every rate afresh. Only consulted for a method that declared
			 * {@see Shipping_Method::FEATURE_RATE_CACHE}: the filter can veto the cache, never force it on.
			 *
			 * @since 2.0.2
			 *
			 * @param bool            $enabled Whether to use the cache. Default true.
			 * @param Shipping_Method $method  Method instance.
			 * @param array           $package Package data.
			 */
			if ( ! $method->supports_rate_cache() ) {
				return false;
			}

			return true === apply_filters( self::FILTER_ENABLED, true, $method, $package ) && $this->get_ttl( $method, $package ) > 0;
		}

		/**
		 * Lifetime of a cached rate, in seconds.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Method $method  Method calculating the rate.
		 * @param array           $package WooCommerce shipping package.
		 * @return int
		 */
		public function get_ttl( Shipping_Method $method, array $package ): int {

			/**
			 * Shipping Rate Cache TTL Filter.
			 *
			 * @since 2.0.2
			 *
			 * @param int             $ttl     Seconds. Zero or less switches the cache off.
			 * @param Shipping_Method $method  Method instance.
			 * @param array           $package Package data.
			 */
			$ttl = apply_filters( self::FILTER_TTL, self::DEFAULT_TTL, $method, $package );

			return is_numeric( $ttl ) ? (int) $ttl : self::DEFAULT_TTL;
		}

		/**
		 * The key when the cache is on and the method is identifiable, otherwise `null`.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Method $method  Method calculating the rate.
		 * @param array           $package WooCommerce shipping package.
		 * @return string|null
		 */
		private function usable_key( Shipping_Method $method, array $package ): ?string {
			return $this->is_enabled( $method, $package ) ? $this->build_key( $method, $package ) : null;
		}

		/**
		 * Reduces the package contents to what changes a quote, in a cart-order-independent form.
		 *
		 * @since 2.0.2
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return array<int, array<string, string>>
		 */
		private static function normalize_contents( array $package ): array {

			$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : [];
			$lines    = [];

			foreach ( $contents as $item ) {

				if ( ! is_array( $item ) ) {
					continue;
				}

				$line = [
					'product'   => (string) ( $item['product_id'] ?? '' ),
					'variation' => (string) ( $item['variation_id'] ?? '' ),
					'qty'       => (string) ( $item['quantity'] ?? '' ),
				];

				$product = $item['data'] ?? null;

				if ( $product instanceof \WC_Product ) {
					$line['length'] = (string) $product->get_length();
					$line['width']  = (string) $product->get_width();
					$line['height'] = (string) $product->get_height();
					$line['weight'] = (string) $product->get_weight();
					$line['class']  = (string) $product->get_shipping_class_id();

					// The packer treats a virtual product as weightless and skips it.
					$line['virtual'] = $product->is_virtual() ? '1' : '0';
				}

				$lines[] = $line;
			}

			usort(
				$lines,
				static function ( array $a, array $b ): int {
					return strcmp( (string) wp_json_encode( $a ), (string) wp_json_encode( $b ) );
				}
			);

			return $lines;
		}

		/**
		 * Turns a rate into plain data.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Rate $rate    Rate to snapshot.
		 * @param array         $package The package the rate was calculated for.
		 * @return array
		 */
		private function snapshot( Shipping_Rate $rate, array $package ): array {
			$rate_package = $rate->get_package();

			return [
				'method_id' => $rate->get_method_id(),
				'id'        => $rate->get_id(),
				'label'     => $rate->get_label(),
				'cost'      => $rate->get_cost(),
				// The calculation's own package is stored as a marker and rebuilt from the CURRENT one.
				'package'   => is_array( $rate_package ) && $rate_package === $package ? true : $rate_package,
				'meta_data' => $rate->get_meta_data(),
				'args'      => $rate->get_args(),
			];
		}

		/**
		 * Rebuilds a rate from a snapshot; a malformed one is a miss.
		 *
		 * @since 2.0.2
		 *
		 * @param array $data    Snapshot from {@see self::snapshot()}.
		 * @param array $package The package of the CURRENT calculation.
		 * @return Shipping_Rate|null
		 */
		private function restore( array $data, array $package ): ?Shipping_Rate {

			if ( ! isset( $data['method_id'], $data['id'], $data['label'] ) || ! array_key_exists( 'cost', $data ) ) {
				return null;
			}

			try {
				return new Shipping_Rate(
					(string) $data['method_id'],
					(string) $data['id'],
					(string) $data['label'],
					$data['cost'],
					true === ( $data['package'] ?? null ) ? $package : ( $data['package'] ?? null ),
					is_array( $data['meta_data'] ?? null ) ? $data['meta_data'] : [],
					is_array( $data['args'] ?? null ) ? $data['args'] : []
				);
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}
		}

		/**
		 * Whether a value is scalars, null and arrays all the way down — finite numbers and valid
		 * UTF-8 strings only, so it always encodes to JSON and distinct values never share a hash.
		 *
		 * Nesting deeper than {@see self::MAX_PLAIN_DEPTH} is not plain: that also stops a
		 * self-referencing array (`$a['self'] = &$a`), which would otherwise recurse until the process dies.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Value to check.
		 * @param int   $depth Array levels already entered; internal.
		 * @return bool
		 */
		private static function is_plain( $value, int $depth = 0 ): bool {

			if ( is_array( $value ) ) {
				if ( $depth >= self::MAX_PLAIN_DEPTH ) {
					return false;
				}

				foreach ( $value as $item ) {
					if ( ! self::is_plain( $item, $depth + 1 ) ) {
						return false;
					}
				}

				return true;
			}

			if ( is_float( $value ) ) {
				return is_finite( $value );
			}

			if ( is_string( $value ) ) {
				return 1 === preg_match( '//u', $value );
			}

			return null === $value || is_scalar( $value );
		}

		/**
		 * Lower-cased, trimmed string form of a destination field.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Raw field.
		 * @return string
		 */
		private static function normalize_text( $value ): string {
			return is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		}

		/**
		 * Stable hash of an arbitrary plain structure (key order does not matter), or `null` when the
		 * structure is not plain data or does not encode — never a hash of an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Structure to hash.
		 * @return string|null
		 */
		private static function hash( $value ): ?string {

			if ( ! self::is_plain( $value ) ) {
				return null;
			}

			$encoded = wp_json_encode( self::canonicalize( $value ) );

			return is_string( $encoded ) && '' !== $encoded ? md5( $encoded ) : null;
		}

		/**
		 * Recursively key-sorts nested arrays for {@see self::hash()}.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Value.
		 * @return mixed
		 */
		private static function canonicalize( $value ) {

			if ( is_array( $value ) ) {
				ksort( $value );

				return array_map( [ self::class, 'canonicalize' ], $value );
			}

			return $value;
		}
	}

endif;

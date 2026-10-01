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
	 * WHAT THE KEY IS MADE OF — everything that changes the answer:
	 *
	 *  - the method (`get_id()`) and its INSTANCE (zone placement carries its own settings);
	 *  - a hash of the method's instance settings. A merchant editing the tariff, markup, origin or
	 *    packing algorithm therefore lands on a new key — that is the invalidation, no hook needed;
	 *  - the package contents: product id, variation id, quantity, dimensions, weight and shipping
	 *    class of every line (sorted, so cart order is irrelevant), plus `contents_cost` — insurance
	 *    and declared value follow it;
	 *  - the destination: country, state, city, postcode;
	 *  - the store currency.
	 *
	 * What the framework CANNOT see is added through {@see self::FILTER_KEY_PARTS}: the pickup point
	 * a customer chose (the framework has no accessor for it — the carrier plugin owns that
	 * selection), the payment method when COD changes the price, the street address when a carrier
	 * prices by it, global plugin settings such as credentials or origin.
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
		private const SHAPE_VERSION = 1;

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

			return $this->restore( $stored['rate'] );
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

			$snapshot = $this->snapshot( $rate );

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
		 * Builds the cache key, or `null` when the method cannot be identified.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Method $method  Method calculating the rate.
		 * @param array           $package WooCommerce shipping package.
		 * @return string|null
		 */
		public function build_key( Shipping_Method $method, array $package ): ?string {

			$method_id = (string) $method->get_id();

			if ( '' === $method_id ) {
				return null;
			}

			$settings = property_exists( $method, 'instance_settings' ) && is_array( $method->instance_settings )
				? $method->instance_settings
				: [];

			$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];

			$parts = [
				'method'      => $method_id,
				'instance'    => property_exists( $method, 'instance_id' ) ? (int) $method->instance_id : 0,
				'settings'    => self::hash( $settings ),
				'contents'    => $this->normalize_contents( $package ),
				'cost'        => isset( $package['contents_cost'] ) && is_scalar( $package['contents_cost'] ) ? (string) $package['contents_cost'] : '',
				'destination' => [
					'country'  => self::normalize_text( $destination['country'] ?? '' ),
					'state'    => self::normalize_text( $destination['state'] ?? '' ),
					'city'     => self::normalize_text( $destination['city'] ?? '' ),
					'postcode' => str_replace( ' ', '', self::normalize_text( $destination['postcode'] ?? '' ) ),
				],
				'currency'    => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			];

			/**
			 * Shipping Rate Cache Key Parts Filter.
			 *
			 * Adds what changes this method's answer but the framework cannot see: the pickup point the
			 * customer chose, the payment method when COD alters the price, the street address, global
			 * plugin settings. Parts must be plain data (scalars and arrays).
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

			return self::TRANSIENT_PREFIX . self::hash( $parts );
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
			 * Return `false` to calculate every rate afresh.
			 *
			 * @since 2.0.2
			 *
			 * @param bool            $enabled Whether to use the cache. Default true.
			 * @param Shipping_Method $method  Method instance.
			 * @param array           $package Package data.
			 */
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
		private function normalize_contents( array $package ): array {

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
				}

				$lines[] = $line;
			}

			usort(
				$lines,
				static function ( array $a, array $b ): int {
					return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) );
				}
			);

			return $lines;
		}

		/**
		 * Turns a rate into plain data.
		 *
		 * @since 2.0.2
		 *
		 * @param Shipping_Rate $rate Rate to snapshot.
		 * @return array
		 */
		private function snapshot( Shipping_Rate $rate ): array {
			return [
				'method_id' => $rate->get_method_id(),
				'id'        => $rate->get_id(),
				'label'     => $rate->get_label(),
				'cost'      => $rate->get_cost(),
				'package'   => $rate->get_package(),
				'meta_data' => $rate->get_meta_data(),
				'args'      => $rate->get_args(),
			];
		}

		/**
		 * Rebuilds a rate from a snapshot; a malformed one is a miss.
		 *
		 * @since 2.0.2
		 *
		 * @param array $data Snapshot from {@see self::snapshot()}.
		 * @return Shipping_Rate|null
		 */
		private function restore( array $data ): ?Shipping_Rate {

			if ( ! isset( $data['method_id'], $data['id'], $data['label'] ) || ! array_key_exists( 'cost', $data ) ) {
				return null;
			}

			try {
				return new Shipping_Rate(
					(string) $data['method_id'],
					(string) $data['id'],
					(string) $data['label'],
					$data['cost'],
					$data['package'] ?? null,
					is_array( $data['meta_data'] ?? null ) ? $data['meta_data'] : [],
					is_array( $data['args'] ?? null ) ? $data['args'] : []
				);
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}
		}

		/**
		 * Whether a value is scalars, null and arrays all the way down.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Value to check.
		 * @return bool
		 */
		private static function is_plain( $value ): bool {

			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( ! self::is_plain( $item ) ) {
						return false;
					}
				}

				return true;
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
		 * Stable hash of an arbitrary plain structure (key order does not matter).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Structure to hash.
		 * @return string
		 */
		private static function hash( $value ): string {

			if ( is_array( $value ) ) {
				ksort( $value );
				$value = array_map( [ self::class, 'canonicalize' ], $value );
			}

			return md5( (string) wp_json_encode( $value ) );
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

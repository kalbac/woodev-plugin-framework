<?php
/**
 * Woodev Shipping Order Handler
 *
 * Central HPOS-safe accessor for a shipping plugin's order meta. It reads and
 * writes ONLY the carrier's own installed-site order-meta keys, supplied by the
 * plugin as an explicit logical-field → real-meta-key map. The framework hardcodes
 * no prefix, no suffix and no key: every carrier's order-meta keys are DISTINCT
 * installed-site data contracts (edostavka uses `cdek_order_id`, `tracking_code`,
 * `status`; yandex uses decomposed `_yandex_delivery_*` keys) and must be preserved
 * byte-for-byte on installed sites. The plugin owns those strings; this handler only
 * routes them through {@see \Woodev_Order_Compatibility} so the same code works on
 * both HPOS and legacy post-meta storage.
 *
 * The checkout/session half of the chosen point lives in
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection} (session-only); this
 * class is the order-meta half and shares no key with it.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.3.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Shipping_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Shipping_Order_Handler' ) ) :

	/**
	 * HPOS-safe read/write of a plugin's order meta, keyed by a plugin-supplied map.
	 *
	 * A carrier constructs the handler with its own logical-field → real-meta-key map,
	 * e.g. `[ 'carrier_order_id' => 'cdek_order_id', 'tracking_number' => 'tracking_code' ]`.
	 * Reads/writes resolve the logical field to the plugin's real key and go through
	 * {@see \Woodev_Order_Compatibility}; an unmapped field throws rather than silently
	 * orphaning live order data under a neutral, never-installed key.
	 *
	 * @since 1.5.0
	 */
	class Shipping_Order_Handler {

		/** @var array<string, string> plugin-supplied map: logical field name => real installed-site order-meta key */
		private array $key_map;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, string> $key_map logical field name => real order-meta key, supplied by the plugin (the framework hardcodes none)
		 */
		public function __construct( array $key_map ) {
			$this->key_map = $key_map;
		}

		/**
		 * Reads a logical field from the order's meta.
		 *
		 * Resolves the logical field to the plugin's real meta key and reads it via
		 * {@see \Woodev_Order_Compatibility::get_order_meta()} (HPOS-safe).
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order   order to read from
		 * @param string    $logical logical field name present in the key map
		 * @return mixed the stored meta value
		 * @throws Shipping_Exception when the logical field is not in the plugin's key map
		 */
		public function get( \WC_Order $order, string $logical ) {
			return \Woodev_Order_Compatibility::get_order_meta( $order, $this->resolve( $logical ) );
		}

		/**
		 * Writes a logical field to the order's meta.
		 *
		 * Resolves the logical field to the plugin's real meta key and writes it via
		 * {@see \Woodev_Order_Compatibility::update_order_meta()} (HPOS-safe).
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order   order to write to
		 * @param string    $logical logical field name present in the key map
		 * @param mixed     $value   value to store under the resolved key
		 * @return void
		 * @throws Shipping_Exception when the logical field is not in the plugin's key map
		 */
		public function set( \WC_Order $order, string $logical, $value ): void {
			\Woodev_Order_Compatibility::update_order_meta( $order, $this->resolve( $logical ), $value );
		}

		/**
		 * Resolves a logical field name to the plugin's real order-meta key.
		 *
		 * @since 1.5.0
		 *
		 * @param string $logical logical field name
		 * @return string the real installed-site order-meta key supplied by the plugin
		 * @throws Shipping_Exception when the logical field is not present in the key map
		 */
		private function resolve( string $logical ): string {
			if ( ! isset( $this->key_map[ $logical ] ) ) {
				throw new Shipping_Exception(
					sprintf( 'Unmapped order-meta field "%s": the plugin must supply its real meta key.', $logical )
				);
			}

			return $this->key_map[ $logical ];
		}
	}

endif;

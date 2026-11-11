<?php
/**
 * Minimal `WC_Cache_Helper` so that code which drops WooCommerce's product caches loads without WooCommerce.
 *
 * Only declared when no other exists. `get_transient_version()` matches the double in `ShippingRateCacheTest`, which is declared
 * under the same guard: whichever file loads first wins, so the two must agree on it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

if ( ! class_exists( 'WC_Cache_Helper', false ) ) {
	// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing -- A double of the WooCommerce class.
	class WC_Cache_Helper {
		public static function get_transient_version( string $group ): string {
			return 'shipping-generation-1';
		}

		public static function invalidate_cache_group( $group ) {
			return true;
		}
	}
}

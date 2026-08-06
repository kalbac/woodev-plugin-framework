<?php
/**
 * Minimal `\WC_Geolocation` stand-in for the rate-limit trait's unit tests.
 *
 * Lives in its own file, in the GLOBAL namespace, because that is where the real class the
 * trait looks up by name lives — `RestRateLimitTraitTest.php` itself is namespaced, so it
 * cannot declare one. Guarded by the caller (`class_exists`), so a run that has the real
 * WooCommerce class loaded keeps it.
 *
 * `$address` is the whole point of the double: WooCommerce's own `get_ip_address()` reads
 * `X-Real-IP` / `X-Forwarded-For` verbatim and returns `''` when the header is not an IP,
 * which is exactly the input the trait must no longer be fooled by.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Test double for WooCommerce's geolocation helper.
 */
class WC_Geolocation {

	/**
	 * Forced return value, or null to echo back `REMOTE_ADDR` the way WooCommerce does when
	 * no forwarding header is present.
	 *
	 * @var string|null
	 */
	public static $address = null;

	/**
	 * @return string
	 */
	public static function get_ip_address() {
		if ( null !== self::$address ) {
			return self::$address;
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}
}

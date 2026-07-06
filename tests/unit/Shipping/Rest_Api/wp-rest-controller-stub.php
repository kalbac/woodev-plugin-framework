<?php
/**
 * Minimal WP_REST_Controller stub for WC-free unit tests.
 *
 * Field_Source_Controller extends WP_REST_Controller (matching the sibling
 * shipping controllers), but the unit suite runs without WordPress. This stub
 * provides just enough of the class for the controller to be instantiated and
 * its WC-free core dispatch exercised. It is loaded only when the real class
 * is absent.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

// phpcs:disable

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	class WP_REST_Controller {}
}

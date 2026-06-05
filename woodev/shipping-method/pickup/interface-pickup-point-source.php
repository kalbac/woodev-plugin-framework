<?php
/**
 * Woodev Pickup Point Source Interface
 *
 * The **sourcing** axis of the pickup design (decision §6a: sourcing ≠ rendering).
 * A `Pickup_Point_Source` is the normalizing seam that sits *above* the raw
 * carrier API (`api/interface-shipping-api.php::get_pickup_points()`): it turns a
 * search query into a list of framework {@see Pickup_Point} value objects, mapping
 * the carrier's own payload into the shared core schema while preserving provider
 * specifics in the `raw` escape hatch.
 *
 * Pure contract — no WooCommerce assumptions. See
 * docs-internal/platform-v2-s1-shipping-spec.md §4.1.ii.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Point_Source' ) ) :

	/**
	 * Normalizing pickup point source.
	 *
	 * Implementations wrap a single carrier: they call the carrier API, then map
	 * each returned record into a {@see Pickup_Point}. Callers receive a uniform
	 * `Pickup_Point[]` regardless of carrier, and can narrow it further with
	 * {@see Pickup_Point_Filter}.
	 *
	 * @since 1.5.0
	 */
	interface Pickup_Point_Source {

		/**
		 * Searches the carrier for pickup points matching the given query.
		 *
		 * Implementations map the carrier's raw API payload into framework value
		 * objects; carrier-specific fields are preserved on each point's `raw`
		 * escape hatch rather than discarded.
		 *
		 * @since 1.5.0
		 *
		 * @param array $params {
		 *     Pickup point search parameters. All keys are optional; a carrier
		 *     uses whichever it supports.
		 *
		 *     @type string $city        City name to search in.
		 *     @type string $postal_code Postal code to search near.
		 *     @type float  $lat         Latitude for coordinate-based search.
		 *     @type float  $lng         Longitude for coordinate-based search.
		 *     @type int    $limit       Maximum number of results to return.
		 * }
		 *
		 * @return Pickup_Point[] normalized pickup points (possibly empty)
		 */
		public function search( array $params ): array;
	}

endif;

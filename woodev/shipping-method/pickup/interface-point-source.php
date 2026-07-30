<?php
/**
 * Woodev Pickup Point Source
 *
 * The plugin seam. A shipping plugin implements this to expose its carrier's pickup
 * points; the framework never learns anything about the carrier's API from it.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Point_Source' ) ) :

	/**
	 * Supplies normalized pickup points for one carrier.
	 *
	 * @since 2.0.2
	 */
	interface Point_Source {

		/** Load every point for a locality at once (Yandex, CDEK). */
		public const STRATEGY_BULK = 'bulk';

		/** Load points for the visible bounding box, details on demand (OZON, Pochta). */
		public const STRATEGY_VIEWPORT = 'viewport';

		/**
		 * Returns the loading strategy this source supports.
		 *
		 * Determines whether the map provider queries once by locality or repeatedly by
		 * bounding box as the customer pans. It also determines what the framework
		 * guarantees about the query passed to {@see self::fetch_points()} — see there.
		 *
		 * @since 2.0.2
		 *
		 * @return string One of the STRATEGY_* constants.
		 */
		public function get_strategy(): string;

		/**
		 * Fetches points matching the query.
		 *
		 * The framework guarantees the query shape matches the declared strategy: a source
		 * declaring `STRATEGY_BULK` is always handed a query with a non-null
		 * {@see Point_Query::get_locality()}; a source declaring `STRATEGY_VIEWPORT` is
		 * always handed a query with non-null {@see Point_Query::get_bounds()}. A source
		 * need not defend against the other addressing mode arriving instead.
		 *
		 * Implementations must return already-normalized points. A malformed entry (one
		 * that fails carrier-side mapping) should be skipped rather than aborting the whole
		 * fetch — one bad point must not empty the map. A failed fetch is a different case
		 * and must not be reported as zero points: throw instead, see `@throws` below.
		 *
		 * @since 2.0.2
		 *
		 * @param Point_Query $query What to fetch.
		 *
		 * @return Pickup_Point[]
		 *
		 * @throws \Woodev_API_Exception On a carrier transport, authentication, or API error —
		 *                                the REST layer turns this into an error response with
		 *                                a retry, distinct from a genuinely empty result.
		 */
		public function fetch_points( Point_Query $query ): array;

		/**
		 * Fetches one point's full detail.
		 *
		 * Under the viewport strategy the list response is usually sparse and this call adds
		 * opening hours, payment methods and weight limits. Under the bulk strategy it may
		 * simply return the point already known.
		 *
		 * @since 2.0.2
		 *
		 * @param string $point_id Carrier point id.
		 *
		 * @return Pickup_Point|null Null when the point is unknown.
		 *
		 * @throws \Woodev_API_Exception On a carrier transport, authentication, or API error —
		 *                                the REST layer turns this into an error response with
		 *                                a retry, distinct from a genuinely unknown point.
		 */
		public function fetch_details( string $point_id ): ?Pickup_Point;
	}

endif;

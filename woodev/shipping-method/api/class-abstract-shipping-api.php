<?php
/**
 * Woodev Abstract Shipping API
 *
 * Base class that wires the carrier-neutral {@see \Woodev\Framework\Shipping\Shipping_API}
 * contract onto the framework's {@see \Woodev_API_Base} HTTP plumbing. It inherits the
 * request/response transport, the TLS handling, and — crucially — the request logging
 * broadcast (`woodev_{plugin_id}_api_request_performed`) from the base, and adds the two
 * pieces a carrier shouldn't have to repeat:
 *
 * 1. Typed `get_request()` / `get_response()` accessors. {@see \Woodev_API_Base} exposes
 *    these untyped; the {@see \Woodev\Framework\Shipping\Shipping_API} interface declares
 *    them with `\Woodev_API_Request` / `\Woodev_API_Response` return types, so this class
 *    re-declares them with the interface-compatible signatures over the same backing state.
 * 2. A default pickup-point mapping that turns a carrier pickup-points response into
 *    {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point} value objects. The interface's
 *    `get_pickup_points()` returns the raw `\Woodev_API_Response` (a fixed contract that is
 *    NOT changed here); {@see self::to_pickup_points()} / {@see self::get_pickup_point_models()}
 *    are the carrier-neutral default that yields `Pickup_Point[]`. A carrier supplies only the
 *    thin response→rows extraction via {@see self::parse_pickup_points_data()}.
 *
 * Carriers extend this with thin subclasses providing their endpoint mapping: the per-operation
 * request building (`calculate_rates()`, `get_pickup_points()`, `create_order()`, `get_order()`,
 * `cancel_order()`, `get_tracking()`) plus the base seams `get_new_request()` / `get_plugin()`.
 * No installed-site contract string (shipping-method id, option key, meta key, hook name) is
 * introduced here — this is carrier-neutral framework scaffolding.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.5.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Abstract_Shipping_API' ) ) :

	/**
	 * Carrier-neutral shipping API base.
	 *
	 * Implements {@see Shipping_API} on top of {@see \Woodev_API_Base}. The carrier-specific
	 * operations stay abstract (inherited from the interface) so each carrier maps only its own
	 * endpoints; this base contributes the typed request/response wiring and the default
	 * pickup-point→{@see Pickup_Point} mapping.
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Shipping_API extends \Woodev_API_Base implements Shipping_API {

		/**
		 * Returns the most recent request object.
		 *
		 * Re-declares {@see \Woodev_API_Base::get_request()} with the typed return required by
		 * {@see Shipping_API}; the backing state is the same protected request property.
		 *
		 * @since 1.5.0
		 *
		 * @return \Woodev_API_Request the most recent request object
		 */
		public function get_request(): \Woodev_API_Request {

			/** @var \Woodev_API_Request $request */
			$request = $this->request;

			return $request;
		}

		/**
		 * Returns the most recent response object, or null when there is none.
		 *
		 * Re-declares {@see \Woodev_API_Base::get_response()} with the typed return required
		 * by {@see Shipping_API}. The backing {@see \Woodev_API_Base} response property is
		 * NULLABLE -- it is null before the first request and is reset to null before each
		 * request (and on a transport / pre-parse failure). The return type therefore MUST be
		 * nullable: a non-nullable declaration TypeErrors when the base calls this during its
		 * failure broadcast, masking the real API exception and suppressing the
		 * woodev_{plugin_id}_api_request_performed log hook.
		 *
		 * @since 1.5.0
		 *
		 * @return \Woodev_API_Response|null the most recent response, or null when none
		 */
		public function get_response(): ?\Woodev_API_Response {

			/** @var \Woodev_API_Response|null $response */
			$response = $this->response;

			return $response;
		}

		/**
		 * Retrieves pickup points for the given parameters as value objects.
		 *
		 * Carrier-neutral default: performs the carrier {@see Shipping_API::get_pickup_points()}
		 * request and maps the response into {@see Pickup_Point} objects via
		 * {@see self::to_pickup_points()}. The interface's `get_pickup_points()` still returns the
		 * raw `\Woodev_API_Response`; this convenience method is the typed `Pickup_Point[]` seam.
		 *
		 * @since 1.5.0
		 *
		 * @param array $params pickup point search parameters (see {@see Shipping_API::get_pickup_points()})
		 *
		 * @return Pickup_Point[] the carrier's pickup points as value objects
		 * @throws \Woodev_API_Exception on network timeouts, API errors, or invalid parameters
		 */
		public function get_pickup_point_models( array $params ): array {

			return $this->to_pickup_points( $this->get_pickup_points( $params ) );
		}

		/**
		 * Maps a carrier pickup-points response into {@see Pickup_Point} value objects.
		 *
		 * The default loops the carrier-extracted rows from {@see self::parse_pickup_points_data()}
		 * and builds one {@see Pickup_Point} per row with {@see Pickup_Point::from_array()}. The
		 * core-schema casting lives in the value object, so a carrier only has to surface its rows
		 * keyed by the core schema (plus an optional `raw` escape hatch).
		 *
		 * @since 1.5.0
		 *
		 * @param \Woodev_API_Response $response the carrier pickup-points response
		 *
		 * @return Pickup_Point[] the mapped pickup points
		 */
		public function to_pickup_points( \Woodev_API_Response $response ): array {

			$points = [];

			foreach ( $this->parse_pickup_points_data( $response ) as $data ) {
				$points[] = Pickup_Point::from_array( (array) $data );
			}

			return $points;
		}

		/**
		 * Extracts the carrier pickup-point rows from a pickup-points response.
		 *
		 * Each returned element is fed to {@see Pickup_Point::from_array()}, so it should be an
		 * array keyed by the core pickup-point schema (with carrier-specific fields preserved
		 * under the `raw` key). This is the only carrier-specific piece of the pickup-point
		 * mapping — everything else is the carrier-neutral default above.
		 *
		 * @since 1.5.0
		 *
		 * @param \Woodev_API_Response $response the carrier pickup-points response
		 *
		 * @return array<int, array<string, mixed>> list of pickup-point rows keyed by core schema
		 */
		abstract protected function parse_pickup_points_data( \Woodev_API_Response $response ): array;
	}

endif;

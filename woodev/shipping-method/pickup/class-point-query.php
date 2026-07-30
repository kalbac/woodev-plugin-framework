<?php
/**
 * Woodev Pickup Point Query
 *
 * Describes one request for pickup points: either a locality (bulk strategy) or a
 * bounding box (viewport strategy), optionally narrowed by a search term.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Point_Query' ) ) :

	/**
	 * Immutable request for pickup points.
	 *
	 * A request must name at least one addressing mode — a `locality` (bulk strategy:
	 * the carrier returns every point for a locality in one call) or a `bbox` (viewport
	 * strategy: the carrier is queried per visible bounding box as the customer pans).
	 * Both may be present together; a request naming neither is unusable and is refused.
	 *
	 * @since 2.0.2
	 */
	class Point_Query {

		/**
		 * Largest bounding box side we will serve, in degrees, checked independently on
		 * each axis.
		 *
		 * A viewport carrier is queried per visible area; without a cap a client could ask
		 * for the entire planet and force the plugin to hammer the carrier API. An area-only
		 * cap is not enough: it still admits an arbitrarily elongated strip (e.g. a
		 * fraction of a degree of latitude by the full 360° of longitude), which is exactly
		 * the API-amplification this cap exists to prevent. Bounding both sides forces the
		 * box to be reasonably square — at most 10°x10°, far larger than any realistic
		 * checkout viewport. This is a deliberate blunt instrument, not a geodesic
		 * calculation: degrees of longitude vary with latitude, and precision here would
		 * buy nothing.
		 *
		 * @var float
		 */
		private const MAX_BBOX_SPAN = 10.0;

		/**
		 * Locality name, or null when the request addresses by bounding box only.
		 *
		 * @var string|null
		 */
		private ?string $locality;

		/**
		 * Bounding box as [ min_lat, min_lng, max_lat, max_lng ], or null when the request
		 * addresses by locality only.
		 *
		 * @var array{0: float, 1: float, 2: float, 3: float}|null
		 */
		private ?array $bounds;

		/**
		 * Free-text search term narrowing either addressing mode. Empty string when absent.
		 *
		 * @var string
		 */
		private string $search;

		/**
		 * Constructor. Use {@see from_request()} — it validates.
		 *
		 * @since 2.0.2
		 *
		 * @param string|null                                        $locality Locality name, or null.
		 * @param array{0: float, 1: float, 2: float, 3: float}|null $bounds Bounding box, or null.
		 * @param string                                             $search   Free-text search term.
		 */
		private function __construct( ?string $locality, ?array $bounds, string $search ) {
			$this->locality = $locality;
			$this->bounds   = $bounds;
			$this->search   = $search;
		}

		/**
		 * Builds a query from browser-supplied request parameters.
		 *
		 * `locality` normalizes an empty string to "not supplied" rather than rejecting —
		 * an empty `locality` alone is refused only because it then leaves nothing to
		 * address by, not because an empty string is itself invalid; pairing it with a
		 * usable `bbox` produces a valid viewport-only query. `bbox` gets the identical
		 * carve-out for a different reason: the REST route (a later task) declares `bbox`
		 * as a request argument, and if that declaration ever carries a default of `''`,
		 * every locality-only bulk request would otherwise arrive with an empty `bbox` and
		 * be rejected outright. Treating empty as absent is the safer failure direction —
		 * an actually-malformed `bbox` (wrong arity, non-numeric, out of range, inverted,
		 * oversized) still rejects the whole request rather than silently falling back to
		 * `locality`; see {@see parse_bbox()}.
		 *
		 * Returns null when the request names neither `locality` nor `bbox` (there is
		 * nothing to address by), when `locality`/`bbox`/`q` is present but not a string,
		 * or when a non-empty `bbox` fails validation. Values are rejected rather than
		 * coerced: a non-scalar `q` must not silently become the literal string `"Array"`.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $params Raw request parameters (e.g. from a REST request).
		 *
		 * @return self|null
		 */
		public static function from_request( array $params ): ?self {
			if ( isset( $params['q'] ) && ! is_string( $params['q'] ) ) {
				return null;
			}

			$search = $params['q'] ?? '';

			if ( isset( $params['locality'] ) && ! is_string( $params['locality'] ) ) {
				return null;
			}

			$locality = isset( $params['locality'] ) && '' !== $params['locality'] ? $params['locality'] : null;
			$bounds   = null;

			if ( isset( $params['bbox'] ) && '' !== $params['bbox'] ) {
				if ( ! is_string( $params['bbox'] ) ) {
					return null;
				}

				$bounds = self::parse_bbox( $params['bbox'] );

				if ( null === $bounds ) {
					return null;
				}
			}

			if ( null === $locality && null === $bounds ) {
				return null;
			}

			return new self( $locality, $bounds, $search );
		}

		/**
		 * Parses and validates a `min_lat,min_lng,max_lat,max_lng` bounding box string.
		 *
		 * Rejects rather than coerces: a wrong-arity, non-numeric, out-of-range, inverted,
		 * or oversized box returns null instead of producing a box that would silently
		 * serve the wrong area or the whole planet.
		 *
		 * Antimeridian-crossing boxes (e.g. a Chukotka viewport spanning 170° to -170°)
		 * are out of scope and are refused by the inversion check below, because
		 * `min_lng` reads greater than `max_lng`. This is deliberate, not an oversight: the
		 * customer-visible symptom is an empty map with no explanation, and most map
		 * libraries emit an un-wrapped longitude past 180° when panning across the seam
		 * anyway, which the range check would reject regardless.
		 *
		 * @since 2.0.2
		 *
		 * @param string $raw Raw `bbox` request parameter.
		 *
		 * @return array{0: float, 1: float, 2: float, 3: float}|null
		 */
		private static function parse_bbox( string $raw ): ?array {
			$parts = array_map( 'trim', explode( ',', $raw ) );

			if ( 4 !== count( $parts ) ) {
				return null;
			}

			foreach ( $parts as $part ) {
				if ( ! is_numeric( $part ) ) {
					return null;
				}
			}

			[ $min_lat, $min_lng, $max_lat, $max_lng ] = array_map( 'floatval', $parts );

			// The upper bound on min_lat/min_lng (a min above the +90/+180 ceiling) is not
			// checked here — it is implied by the inversion check just below, since a
			// min above the ceiling is necessarily >= any in-range max. Do not "simplify"
			// this away without keeping that guarantee.
			if ( $min_lat < -90.0 || $max_lat > 90.0 || $min_lng < -180.0 || $max_lng > 180.0 ) {
				return null;
			}

			if ( $min_lat >= $max_lat || $min_lng >= $max_lng ) {
				return null;
			}

			// A span cap is strictly stronger than an area cap: bounding both sides to
			// MAX_BBOX_SPAN implies area <= MAX_BBOX_SPAN^2, so no separate area check
			// is needed. An area-only cap would still admit an arbitrarily elongated
			// strip (e.g. a fraction of a degree of latitude by the full 360° of
			// longitude) — exactly the whole-planet request this cap exists to refuse.
			if ( $max_lat - $min_lat > self::MAX_BBOX_SPAN || $max_lng - $min_lng > self::MAX_BBOX_SPAN ) {
				return null;
			}

			return [ $min_lat, $min_lng, $max_lat, $max_lng ];
		}

		/**
		 * Gets the locality, or null when the request addresses by bounding box only.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_locality(): ?string {
			return $this->locality;
		}

		/**
		 * Gets the bounding box as [ min_lat, min_lng, max_lat, max_lng ], or null when the
		 * request addresses by locality only.
		 *
		 * @since 2.0.2
		 *
		 * @return array{0: float, 1: float, 2: float, 3: float}|null
		 */
		public function get_bounds(): ?array {
			return $this->bounds;
		}

		/**
		 * Gets the free-text search term, or an empty string when absent.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_search(): string {
			return $this->search;
		}
	}

endif;

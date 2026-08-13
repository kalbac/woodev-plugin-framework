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

use Woodev\Framework\Shipping\Location\Location_Record;

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
	 * LOCALITY ADDRESSING BY RECORD (Task 15; issue #159; spec §4.5.4): `locality`
	 * itself stays an opaque string, exactly as before this task — the framework never
	 * interpreted it beyond "present or not" ({@see self::from_request()}, this class'
	 * own {@see MAX_BBOX_SPAN} sibling for `bbox`). What changed is what a client now
	 * puts there: the Location Provider layer's namespaced locality KEY
	 * ({@see \Woodev\Framework\Shipping\Location\Locality_Key}, `provider_id:native_id`)
	 * rather than a raw, DOM-read place name. {@see self::get_record()} and
	 * {@see self::get_resolved_identity()} carry the richer data a real
	 * {@see Point_Source} needs to act on that key — the customer's full, neutral
	 * {@see \Woodev\Framework\Shipping\Location\Location_Record} and this plugin's own
	 * adapter-resolved carrier identity (via
	 * {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_for()}) — set
	 * via {@see self::with_location()}, NEVER by {@see self::from_request()} itself:
	 * both come from server-side state a raw HTTP request cannot be trusted to carry,
	 * so only the REST dispatcher that already holds a
	 * {@see \Woodev\Framework\Shipping\Location\Location_Service} may attach them.
	 * Both are `null` on a query the bbox-only (viewport) path builds — that path is
	 * untouched by this task and keeps working exactly as it did.
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
		 * Point-type codes narrowing either addressing mode. An empty array means "all
		 * types" — the filter UI forbids deselecting its last checkbox (T16), so an
		 * explicit "no types" is not a state that can arise and must not be representable
		 * here either.
		 *
		 * Codes are opaque strings owned by the plugin/carrier (e.g. `pvz`, `postamat`) and
		 * are compared byte-for-byte — see {@see self::get_types()} for why case is not
		 * folded.
		 *
		 * @var string[]
		 */
		private array $types;

		/**
		 * The customer's current, neutral location record (Task 15), or `null` when
		 * none was attached via {@see self::with_location()} — every query
		 * {@see self::from_request()} builds starts `null` here; never populated from
		 * raw request params, see the class docblock.
		 *
		 * @since 2.0.2
		 * @var Location_Record|null
		 */
		private ?Location_Record $record;

		/**
		 * This plugin's own carrier identity resolved for {@see self::$record} (Task
		 * 15) — the {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_for()}
		 * result, opaque to this class exactly like it is to the framework everywhere
		 * else. `null` both when no record is attached and when the record IS attached
		 * but the plugin's own adapter answered "does not serve this locality" — a
		 * {@see Point_Source} distinguishes the two the same way it always
		 * distinguishes "no locality known" from "carrier refuses this one": by
		 * checking {@see self::get_record()} first.
		 *
		 * @since 2.0.2
		 * @var mixed
		 */
		private $resolved_identity;

		/**
		 * Constructor. Use {@see from_request()} — it validates.
		 *
		 * @since 2.0.2
		 *
		 * @param string|null                                        $locality Locality name, or null.
		 * @param array{0: float, 1: float, 2: float, 3: float}|null $bounds Bounding box, or null.
		 * @param string                                             $search   Free-text search term.
		 * @param string[]                                           $types    Point-type codes, or an empty
		 *                                                                     array meaning "all types".
		 */
		private function __construct( ?string $locality, ?array $bounds, string $search, array $types ) {
			$this->locality          = $locality;
			$this->bounds            = $bounds;
			$this->search            = $search;
			$this->types             = $types;
			$this->record            = null;
			$this->resolved_identity = null;
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
		 * nothing to address by), when `locality`/`bbox`/`q`/`types` is present but not a
		 * string, or when a non-empty `bbox` fails validation. Values are rejected rather
		 * than coerced: a non-scalar `q` must not silently become the literal string
		 * `"Array"`. `types` narrows a query, the same as `q` — it is never itself an
		 * addressing mode, so a request naming only `types` (with neither `locality` nor
		 * `bbox`) is still refused.
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

			if ( isset( $params['types'] ) && ! is_string( $params['types'] ) ) {
				return null;
			}

			$types = isset( $params['types'] ) ? self::parse_types( $params['types'] ) : [];

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

			return new self( $locality, $bounds, $search, $types );
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
		 * Parses a comma-separated `types` request parameter into a deduplicated list of
		 * type codes, preserving first-seen order.
		 *
		 * Each comma-separated segment is trimmed; blank segments (a leading, trailing, or
		 * doubled comma, or a whitespace-only segment) are dropped. `array_unique()`
		 * preserves the KEYS of the first occurrence of each value, which leaves gaps where
		 * a later duplicate was dropped — `array_values()` closes those gaps. Skipping it
		 * would leave a sparse array that `wp_json_encode()` serializes as a JSON object,
		 * not an array, breaking the map's client-side rendering (the same trap documented
		 * on {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller::get_points_data()}).
		 *
		 * No case-folding: see {@see self::get_types()} for why.
		 *
		 * @since 2.0.2
		 *
		 * @param string $raw Raw `types` request parameter.
		 *
		 * @return string[]
		 */
		private static function parse_types( string $raw ): array {
			$parts = array_map( 'trim', explode( ',', $raw ) );
			$parts = array_filter(
				$parts,
				static function ( string $part ): bool {
					return '' !== $part;
				}
			);

			return array_values( array_unique( $parts ) );
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

		/**
		 * Gets the point-type codes narrowing this query, or an empty array meaning "all
		 * types".
		 *
		 * Comparison is case-sensitive: `PVZ` and `pvz` are different codes. Type codes are
		 * opaque strings owned by the plugin/carrier — the framework has no vocabulary of
		 * its own to normalize against, and folding case would risk silently merging two
		 * codes the carrier deliberately kept distinct. A {@see Point_Source} must compare
		 * against these codes exactly as received, and the filter UI must send back exactly
		 * the code string the source originally returned.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_types(): array {
			return $this->types;
		}

		/**
		 * Returns a COPY of this query carrying `$record`/`$resolved_identity` (Task 15) —
		 * this class stays immutable, so the original instance is never mutated.
		 *
		 * Callable any number of times; only ever called by a REST dispatcher that already
		 * holds a {@see \Woodev\Framework\Shipping\Location\Location_Service} (see the class
		 * docblock for why {@see self::from_request()} never sets these itself).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record $record            The customer's current location record.
		 * @param mixed           $resolved_identity  This plugin's own carrier identity for
		 *                                             `$record` (opaque), or `null` when the
		 *                                             carrier does not serve it.
		 *
		 * @return self
		 */
		public function with_location( Location_Record $record, $resolved_identity ): self {
			$clone                    = clone $this;
			$clone->record            = $record;
			$clone->resolved_identity = $resolved_identity;

			return $clone;
		}

		/**
		 * Gets the customer's current location record (Task 15), or `null` when none was
		 * attached via {@see self::with_location()}.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Record|null
		 */
		public function get_record(): ?Location_Record {
			return $this->record;
		}

		/**
		 * Gets this plugin's own carrier identity resolved for {@see self::get_record()}
		 * (Task 15) — opaque to this class; see {@see self::$resolved_identity} for the two
		 * distinct `null` cases a {@see Point_Source} must tell apart via
		 * {@see self::get_record()} first.
		 *
		 * @since 2.0.2
		 *
		 * @return mixed
		 */
		public function get_resolved_identity() {
			return $this->resolved_identity;
		}
	}

endif;

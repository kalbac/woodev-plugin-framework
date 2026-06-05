<?php
/**
 * Woodev Pickup Point Filter
 *
 * The narrowing companion to {@see Pickup_Point_Source} on the **sourcing** axis
 * (decision §6a: sourcing ≠ rendering). A source returns every pickup point a
 * carrier knows about for a query; this pure helper trims that list down to the
 * points an actual shipment can use — the same three constraints the yandex
 * reference applies: point `type`, accepted `payment_method` (COD support), and
 * the parcel's `max_weight` / `max_dimensions`.
 *
 * Pure PHP — no WooCommerce, no I/O. Every method is static and returns a fresh,
 * re-indexed {@see Pickup_Point}[]; the input array is never mutated. See
 * docs-internal/platform-v2-s1-shipping-spec.md §4.1.ii.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Point_Filter' ) ) :

	/**
	 * Stateless pickup point filter.
	 *
	 * Each filter is independent and composable; {@see Pickup_Point_Filter::apply()}
	 * chains them from a single criteria array. A filter with an empty/zero
	 * constraint is a no-op (returns the list unchanged but re-indexed), so callers
	 * may pass partial criteria without special-casing absent keys.
	 *
	 * @since 1.5.0
	 */
	class Pickup_Point_Filter {

		/**
		 * Applies every supported constraint present in a criteria array.
		 *
		 * Constraints are applied in sequence; absent or empty keys are skipped.
		 *
		 * @since 1.5.0
		 *
		 * @param Pickup_Point[] $points   pickup points to narrow
		 * @param array          $criteria {
		 *     Filter criteria. All keys are optional.
		 *
		 *     @type string|string[] $type           allowed pickup point type(s)
		 *     @type string          $payment_method payment method the point must accept (e.g. COD)
		 *     @type float           $max_weight     parcel weight that must fit the point's capacity
		 *     @type string|string[] $max_dimensions parcel dimensions ('LxWxH' or [L, W, H]) that must fit
		 * }
		 *
		 * @return Pickup_Point[] the narrowed, re-indexed list
		 */
		public static function apply( array $points, array $criteria ): array {

			if ( isset( $criteria['type'] ) ) {
				$types  = is_array( $criteria['type'] ) ? $criteria['type'] : [ $criteria['type'] ];
				$points = self::by_type( $points, $types );
			}

			if ( isset( $criteria['payment_method'] ) && '' !== (string) $criteria['payment_method'] ) {
				$points = self::by_payment_method( $points, (string) $criteria['payment_method'] );
			}

			if ( isset( $criteria['max_weight'] ) && (float) $criteria['max_weight'] > 0.0 ) {
				$points = self::by_max_weight( $points, (float) $criteria['max_weight'] );
			}

			if ( isset( $criteria['max_dimensions'] ) ) {

				$dimensions = is_array( $criteria['max_dimensions'] )
					? implode( 'x', array_map( static fn( $value ): string => (string) $value, $criteria['max_dimensions'] ) )
					: (string) $criteria['max_dimensions'];

				if ( '' !== $dimensions ) {
					$points = self::by_max_dimensions( $points, $dimensions );
				}
			}

			return $points;
		}

		/**
		 * Keeps only points whose type is in the allowed set.
		 *
		 * @since 1.5.0
		 *
		 * @param Pickup_Point[] $points pickup points to narrow
		 * @param array          $types  allowed type strings; an empty set is a no-op
		 *
		 * @return Pickup_Point[] the narrowed, re-indexed list
		 */
		public static function by_type( array $points, array $types ): array {

			if ( [] === $types ) {
				return array_values( $points );
			}

			$allowed = array_map( static fn( $type ): string => (string) $type, $types );

			return array_values(
				array_filter(
					$points,
					static fn( Pickup_Point $point ): bool => in_array( $point->get_type(), $allowed, true )
				)
			);
		}

		/**
		 * Keeps only points that accept the given payment method.
		 *
		 * This is the COD seam: a point lists its accepted methods on
		 * {@see Pickup_Point::get_payment_methods()}, and a cash-on-delivery order
		 * passes its COD token here to drop points that cannot collect payment.
		 *
		 * @since 1.5.0
		 *
		 * @param Pickup_Point[] $points         pickup points to narrow
		 * @param string         $payment_method method the point must accept; an empty string is a no-op
		 *
		 * @return Pickup_Point[] the narrowed, re-indexed list
		 */
		public static function by_payment_method( array $points, string $payment_method ): array {

			if ( '' === $payment_method ) {
				return array_values( $points );
			}

			return array_values(
				array_filter(
					$points,
					static fn( Pickup_Point $point ): bool => in_array( $payment_method, $point->get_payment_methods(), true )
				)
			);
		}

		/**
		 * Keeps only points whose weight capacity accommodates the parcel.
		 *
		 * A point with no declared capacity ({@see Pickup_Point::get_max_weight()}
		 * `<= 0`) is treated as unlimited and always kept.
		 *
		 * @since 1.5.0
		 *
		 * @param Pickup_Point[] $points pickup points to narrow
		 * @param float          $weight parcel weight that must fit; a non-positive value is a no-op
		 *
		 * @return Pickup_Point[] the narrowed, re-indexed list
		 */
		public static function by_max_weight( array $points, float $weight ): array {

			if ( $weight <= 0.0 ) {
				return array_values( $points );
			}

			return array_values(
				array_filter(
					$points,
					static fn( Pickup_Point $point ): bool => $point->get_max_weight() <= 0.0 || $point->get_max_weight() >= $weight
				)
			);
		}

		/**
		 * Keeps only points whose dimension capacity accommodates the parcel.
		 *
		 * Both the parcel and each point's {@see Pickup_Point::get_max_dimensions()}
		 * are parsed into axis sizes and compared largest-against-largest, so axis
		 * ordering ('LxWxH' vs 'WxHxL') does not matter. A point that declares no
		 * dimension limit is always kept.
		 *
		 * @since 1.5.0
		 *
		 * @param Pickup_Point[] $points     pickup points to narrow
		 * @param string         $dimensions parcel dimensions, e.g. '30x20x10'; an unparseable value is a no-op
		 *
		 * @return Pickup_Point[] the narrowed, re-indexed list
		 */
		public static function by_max_dimensions( array $points, string $dimensions ): array {

			$required = self::parse_dimensions( $dimensions );

			if ( [] === $required ) {
				return array_values( $points );
			}

			return array_values(
				array_filter(
					$points,
					static fn( Pickup_Point $point ): bool => self::dimensions_fit( $required, $point->get_max_dimensions() )
				)
			);
		}

		/**
		 * Parses a dimensions string into descending axis sizes.
		 *
		 * Splits on any run of non-numeric characters (so 'LxWxH', 'L*W*H' and
		 * 'L x W x H' all parse) and sorts the axes largest-first for order-agnostic
		 * comparison.
		 *
		 * @since 1.5.0
		 *
		 * @param string $dimensions raw dimensions string
		 *
		 * @return float[] axis sizes sorted descending, or an empty array if none parse
		 */
		private static function parse_dimensions( string $dimensions ): array {

			$parts = preg_split( '/[^0-9.]+/', $dimensions, -1, PREG_SPLIT_NO_EMPTY );

			if ( false === $parts || [] === $parts ) {
				return [];
			}

			$values = array_map( static fn( string $part ): float => (float) $part, $parts );

			rsort( $values );

			return $values;
		}

		/**
		 * Decides whether a parcel fits within a point's dimension capacity.
		 *
		 * @since 1.5.0
		 *
		 * @param float[] $required parcel axis sizes, sorted descending
		 * @param string  $capacity the point's raw max-dimensions string
		 *
		 * @return bool true when the parcel fits (or the point declares no limit)
		 */
		private static function dimensions_fit( array $required, string $capacity ): bool {

			$limits = self::parse_dimensions( $capacity );

			if ( [] === $limits ) {
				return true;
			}

			if ( count( $limits ) < count( $required ) ) {
				return false;
			}

			foreach ( $required as $index => $value ) {
				if ( $value > $limits[ $index ] ) {
					return false;
				}
			}

			return true;
		}
	}

endif;

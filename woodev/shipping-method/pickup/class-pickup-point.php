<?php
/**
 * Woodev Pickup Point
 *
 * The normalized carrier pickup point. Plugins translate their carrier's payload into
 * this shape; neither the framework nor a map provider ever sees a raw carrier response.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Point' ) ) :

	/**
	 * Immutable value object describing one pickup point.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Point {

		/** @var array<string, mixed> normalized data */
		private array $data;

		/**
		 * Constructor. Use {@see from_array()} — it validates.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data Pre-validated normalized data.
		 */
		private function __construct( array $data ) {
			$this->data = $data;
		}

		/**
		 * Builds a point from a plugin-supplied payload.
		 *
		 * Returns null when a required field is missing, empty, or the wrong shape (a
		 * non-scalar `id`/`name`/`lat`/`lng`/`address`, a non-numeric `lat`/`lng`, or a
		 * `type` that is not an array with `code` and `label`), or when a coordinate is
		 * out of range — a malformed point must never reach the map, and a carrier
		 * returning junk for one point must not break the whole list. Values are
		 * rejected rather than coerced: a non-numeric `lat` must not silently become
		 * `0.0` and render in the wrong place.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $payload Raw normalized payload from the plugin.
		 *
		 * @return self|null
		 */
		public static function from_array( array $payload ): ?self {
			foreach ( [ 'id', 'name', 'lat', 'lng', 'address', 'type' ] as $required ) {
				if ( ! isset( $payload[ $required ] ) || '' === $payload[ $required ] ) {
					return null;
				}

				if ( 'type' !== $required && ! is_scalar( $payload[ $required ] ) ) {
					return null;
				}
			}

			if ( ! is_array( $payload['type'] ) || ! isset( $payload['type']['code'], $payload['type']['label'] ) ) {
				return null;
			}

			if ( ! is_numeric( $payload['lat'] ) || ! is_numeric( $payload['lng'] ) ) {
				return null;
			}

			$lat = (float) $payload['lat'];
			$lng = (float) $payload['lng'];

			if ( $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
				return null;
			}

			$payment_methods = isset( $payload['payment_methods'] )
				? array_map( 'strval', (array) $payload['payment_methods'] )
				: [];

			$photos = isset( $payload['photos'] )
				? array_map( 'strval', (array) $payload['photos'] )
				: [];

			// Unlike payment_methods/photos above (which strval-cast every element), services
			// are filtered rather than coerced: a non-string element (an un-flattened object, an
			// array a carrier adapter forgot to map) is dropped instead of becoming the literal
			// string "Array" — esc_html() in to_browser_array() would fatal on an actual array.
			// A whitespace-only entry ('   ') is treated as absent and dropped via trim(); the
			// string '0' is a legitimate service label and is deliberately kept — it is truthy
			// via trim() !== '' but would be silently eaten by a naive `if ( $service )` filter.
			// array_values() re-indexes so wp_json_encode() emits a JSON array, not an object
			// (see gotcha: php-stdlib-traps-that-survive-tests).
			$services = isset( $payload['services'] )
				? array_values(
					array_filter(
						(array) $payload['services'],
						static function ( $service ): bool {
							return is_string( $service ) && '' !== trim( $service );
						}
					)
				)
				: [];

			return new self(
				[
					'id'              => (string) $payload['id'],
					'name'            => (string) $payload['name'],
					'lat'             => $lat,
					'lng'             => $lng,
					'address'         => (string) $payload['address'],
					'type'            => [
						'code'  => (string) $payload['type']['code'],
						'label' => (string) $payload['type']['label'],
					],
					'short_address'   => isset( $payload['short_address'] ) ? (string) $payload['short_address'] : '',
					'locality'        => isset( $payload['locality'] ) ? (string) $payload['locality'] : '',
					'postal_code'     => isset( $payload['postal_code'] ) ? (string) $payload['postal_code'] : '',
					'phone'           => isset( $payload['phone'] ) ? (string) $payload['phone'] : '',
					'instruction'     => isset( $payload['instruction'] ) ? (string) $payload['instruction'] : '',
					'work_time'       => isset( $payload['work_time'] ) ? (string) $payload['work_time'] : '',
					'payment_methods' => $payment_methods,
					'photos'          => $photos,
					'services'        => $services,
					'accepts_cod'     => isset( $payload['accepts_cod'] ) ? (bool) $payload['accepts_cod'] : null,
					'max_weight'      => isset( $payload['max_weight'] ) ? (int) $payload['max_weight'] : null,
				]
			);
		}

		/**
		 * Gets the carrier point id.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->data['id'];
		}

		/**
		 * Gets the latitude.
		 *
		 * @since 2.0.2
		 *
		 * @return float
		 */
		public function get_lat(): float {
			return $this->data['lat'];
		}

		/**
		 * Gets the longitude.
		 *
		 * @since 2.0.2
		 *
		 * @return float
		 */
		public function get_lng(): float {
			return $this->data['lng'];
		}

		/**
		 * Gets the full address.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_address(): string {
			return $this->data['address'];
		}

		/**
		 * Gets the locality, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_locality(): string {
			return $this->data['locality'];
		}

		/**
		 * Gets the postal code, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_postal_code(): string {
			return $this->data['postal_code'];
		}

		/**
		 * Whether the point accepts cash on delivery. Null means the carrier did not say.
		 *
		 * @since 2.0.2
		 *
		 * @return bool|null
		 */
		public function get_accepts_cod(): ?bool {
			return $this->data['accepts_cod'];
		}

		/**
		 * Maximum accepted weight in GRAMS, or null when the carrier did not say.
		 *
		 * @since 2.0.2
		 *
		 * @return int|null
		 */
		public function get_max_weight(): ?int {
			return $this->data['max_weight'];
		}

		/**
		 * Returns the canonical, unescaped normalized representation.
		 *
		 * This is what gets persisted: {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler}
		 * writes it into installed-site order meta. The un-mangled value must reach that
		 * destination — an address containing `"` or `&` must not be permanently
		 * HTML-entity-encoded in the database. Escaping happens only at the browser
		 * boundary; see {@see self::to_browser_array()}.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return $this->data;
		}

		/**
		 * Returns the browser-safe representation.
		 *
		 * Every display string is escaped here, once, server-side, immediately before the
		 * point reaches the browser — the same rule the checkout field-source controller
		 * applies to option labels. `id` is deliberately NOT escaped: it is an identity
		 * token, not display text, and round-trips back from the browser as `point_id` to
		 * a carrier lookup and into order meta — an escaped `&` would corrupt the lookup.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function to_browser_array(): array {
			$out = $this->to_array();

			$escaped_keys = [
				'name',
				'address',
				'short_address',
				'locality',
				'postal_code',
				'phone',
				'instruction',
				'work_time',
			];

			foreach ( $escaped_keys as $key ) {
				$out[ $key ] = esc_html( $out[ $key ] );
			}

			$out['type']['code']    = esc_html( $out['type']['code'] );
			$out['type']['label']   = esc_html( $out['type']['label'] );
			$out['payment_methods'] = array_map( 'esc_html', $out['payment_methods'] );
			$out['services']        = array_map( 'esc_html', $out['services'] );

			// esc_url_raw, not esc_url: this is a JSON payload, not HTML. esc_url_raw still
			// strips dangerous schemes like `javascript:`, but esc_url additionally
			// HTML-entity-encodes '&' to '&#038;', which is correct for an HTML attribute
			// and wrong for a JSON string a client will use as a URL.
			$out['photos'] = array_map( 'esc_url_raw', $out['photos'] );

			return $out;
		}
	}

endif;

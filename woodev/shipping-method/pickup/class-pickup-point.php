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
				? self::sanitize_string_list( (array) $payload['payment_methods'] )
				: [];

			$photos = isset( $payload['photos'] )
				? self::sanitize_string_list( (array) $payload['photos'] )
				: [];

			$services = isset( $payload['services'] )
				? self::sanitize_string_list( (array) $payload['services'] )
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
					// Card tab label override (issue #199) — the framework numbers co-located
					// tabs, the domain names them; an absent value falls back to `type.label`
					// (`pickup-panels.js`'s `buildTabs()`), same `isset() ? … : ''` cascade every
					// other optional display string on this list already uses.
					'point_short_name' => isset( $payload['point_short_name'] ) ? (string) $payload['point_short_name'] : '',
					'payment_methods'  => $payment_methods,
					'photos'           => $photos,
					'services'         => $services,
					'accepts_cod'      => isset( $payload['accepts_cod'] ) ? (bool) $payload['accepts_cod'] : null,
					'max_weight'       => isset( $payload['max_weight'] ) ? (int) $payload['max_weight'] : null,
					'icons'            => self::sanitize_icons( $payload['icons'] ?? null ),
				]
			);
		}

		/**
		 * Sanitizes the point's own icon override — cascade tier 1 (issue #193): the
		 * point's own icon beats the domain's type-keyed icons, which beat the framework's
		 * default pin. Resolution itself happens client-side, in `map-provider-yandex.js`'s
		 * `_buildProperties()`; this method only decides what SURVIVES onto `to_array()`.
		 *
		 * An ABSENT `icons` key and an explicit `null` both mean "this point carries no icon
		 * of its own" — fall through to the next cascade tier. So does a `default` that is
		 * missing, non-string, or empty. This is DELIBERATELY not the trap `close` fell into
		 * before PR #192 (`Pickup_Handler`/`pickup-mount.js`'s `resolveFlag()`), which treats
		 * an explicit `false` as a decision distinct from "the domain said nothing" and must
		 * beat a truthy default: there the value is a BOOLEAN, and `false` is itself a
		 * meaningful, distinct state worth preserving. An icon is a URL — a blank string can
		 * never be rendered as an image, so it has no equivalent meaningful-but-empty state
		 * to protect. Treating "empty" the same as "absent" here loses no information the
		 * cascade could otherwise have honoured; it IS the correct, and only sensible,
		 * reading.
		 *
		 * `active` mirrors `default` when the domain supplies only one image — the same D-5
		 * rule {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::normalized_point_icons()}
		 * already applies to the type-level tier, so a point supplying its own icon never
		 * reaches the browser with a broken/empty `active` URL.
		 *
		 * Values are kept RAW here, matching `to_array()`'s unescaped, canonical contract
		 * (the same split `photos` already establishes) — escaping via `esc_url_raw()`, and
		 * the post-escape "did this collapse to an unusable empty string" recheck, happen
		 * once, in {@see self::to_browser_array()}.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $icons Raw `icons` payload value.
		 *
		 * @return array{default: string, active: string}|null
		 */
		private static function sanitize_icons( $icons ): ?array {
			if ( ! is_array( $icons ) || empty( $icons['default'] ) || ! is_string( $icons['default'] ) ) {
				return null;
			}

			$default = $icons['default'];
			$active  = ( ! empty( $icons['active'] ) && is_string( $icons['active'] ) ) ? $icons['active'] : $default;

			return [
				'default' => $default,
				'active'  => $active,
			];
		}

		/**
		 * Filters a raw carrier list down to non-empty strings, re-indexed from zero.
		 *
		 * Shared by `payment_methods`, `photos`, and `services`. Elements are FILTERED, not
		 * coerced: a non-string element (an un-flattened carrier object, a nested array a
		 * carrier adapter forgot to map) is dropped instead of becoming the literal string
		 * "Array" — `strval()`/`(string)` on an array in PHP 8 does not fatal, it emits a
		 * warning and returns "Array", which would otherwise reach the customer as a payment
		 * method or photo literally named "Array" (see issue #154). A whitespace-only entry
		 * ('   ') is treated as absent and dropped via `trim()`; the string '0' is a
		 * legitimate label and is deliberately kept — it is truthy via `trim() !== ''` but
		 * would be silently eaten by a naive `if ( $value )` filter. `array_values()`
		 * re-indexes so `wp_json_encode()` emits a JSON array, not an object, the moment any
		 * record is dropped (see gotcha: php-stdlib-traps-that-survive-tests).
		 *
		 * DELIBERATELY STRICT, not just non-empty-after-cast: an `int`, `float`, or `bool`
		 * element is DROPPED, not `strval()`-coerced into a string that happens to look
		 * plausible. This narrows the old `payment_methods`/`photos` behaviour (which used
		 * `array_map( 'strval', ... )` and so kept a coerced `5` as `'5'`) rather than merely
		 * closing the "Array" hole while leaving scalar coercion in place — checked against
		 * both reference carriers in `plugins-reference/` (issue #154 follow-up, 2026-08-07):
		 * CDEK (`woocommerce-edostavka`) never emits a `payment_methods` list at all — its API
		 * reports `have_cashless`/`have_cash`/`allowed_cod` booleans, which an adapter targeting
		 * this contract would map to STRING codes, not carry through as numbers; Yandex Delivery
		 * (`woocommerce-yandex-delivery`) emits string codes directly (`'cash_on_receipt'`,
		 * `'card_on_receipt'`). Every in-repo pickup fixture agrees (`'card'`, `'cod'`; `photos`
		 * always `[]` — no real carrier this framework targets populates it yet). A numeric
		 * element here is therefore not a plausible carrier shape to accommodate; it is far more
		 * likely a broken adapter leaking an internal id/enum, and silently stringifying it would
		 * put a meaningless "5" in front of the customer exactly as confusingly as "Array" did —
		 * dropping it is the correct failure mode, matching `services`, which never coerced.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int|string, mixed> $list Raw list from a carrier payload.
		 *
		 * @return string[] Non-empty string values, re-indexed from zero.
		 */
		private static function sanitize_string_list( array $list ): array {
			return array_values(
				array_filter(
					$list,
					static function ( $value ): bool {
						return is_string( $value ) && '' !== trim( $value );
					}
				)
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
				'point_short_name',
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

			// Same esc_url_raw rule as `photos`, plus the post-escape recheck
			// `Pickup_Handler::normalized_point_icons()` already applies to the type-level
			// tier: a `default` that survives escaping as an empty string (a `javascript:`
			// URL, which WordPress's bad-protocol stripping collapses to `''`) drops the
			// whole override rather than reaching the browser as an icon pointing at `""`.
			if ( null !== $out['icons'] ) {
				$default = esc_url_raw( $out['icons']['default'] );

				if ( '' === $default ) {
					$out['icons'] = null;
				} else {
					$active = esc_url_raw( $out['icons']['active'] );

					$out['icons'] = [
						'default' => $default,
						'active'  => '' !== $active ? $active : $default,
					];
				}
			}

			return $out;
		}
	}

endif;

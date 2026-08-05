<?php
/**
 * Woodev Pickup Handler
 *
 * The framework's thin coordination point for the checkout pickup-point picker (SP-5
 * "pickup points + map" plan, Task 8). It hands the browser a JS-safe config (see
 * {@see self::get_js_config()}), enqueues the picker's frontend assets, registers the
 * `woodev/v1` pickup-points REST routes, and runs the server-side authority behind the
 * client's UX-only verdict: a re-check of the chosen point's constraints on
 * `woocommerce_checkout_process`, plus — when the owning plugin wires it, see
 * {@see self::__construct()} — persistence of the full normalized point alongside the id
 * §8 already saves.
 *
 * Two deliberate deviations from the SP-5 plan text, both load-bearing:
 *
 * 1. **The constructor takes a {@see Point_Source}, not a bare strategy string.** The
 *    plan's signature was `( $plugin_id, $field_id, $strategy, $provider )`. A separately
 *    passed strategy can silently disagree with what the source actually declares via
 *    {@see Point_Source::get_strategy()} — and
 *    {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller} now ENFORCES the
 *    source's own declaration, refusing any query that does not match it. Advertising a
 *    different strategy to the browser than the one the REST layer enforces would make
 *    the client send queries the controller silently empties — a blank map with no error.
 *    `strategy` in {@see self::get_js_config()} is therefore always
 *    `$source->get_strategy()`, never a constructor argument.
 * 2. **`replaceAddress` carries `billingOnly`, never a resolved `target`.** The plan had
 *    this emit `[ 'enabled' => bool, 'target' => Address_Target::resolve( … ) ]` — a
 *    target baked in at PHP render time. `ship_to_different_address` is a LIVE checkbox
 *    the customer can tick after the page renders; a target resolved before that goes
 *    stale, and the mount would write the pickup address into `billing_*` while the real
 *    delivery fieldset (`shipping_*`) stays untouched — silently overwriting a genuinely
 *    separate billing address, exactly what
 *    {@see \Woodev\Framework\Shipping\Pickup\Address_Target}'s own docblock exists to
 *    prevent. Only the STABLE half — `wc_ship_to_billing_address_only()`, a store setting
 *    that cannot change mid-page — is emitted; the browser re-applies
 *    {@see \Woodev\Framework\Shipping\Pickup\Address_Target::resolve()}'s rule against the
 *    live checkbox at write time.
 *
 * `replaceAddress.enabled` follows the `$replace_address` constructor argument (SP-5 Task 17,
 * default `true`) — the merchant-configurable on/off toggle. `mapConfig` comes straight from the
 * active {@see \Woodev\Framework\Shipping\Map\Map_Provider}'s own
 * {@see \Woodev\Framework\Shipping\Map\Map_Provider::get_js_config()} (SP-5 Task 9) — this
 * handler owns none of that shape itself, only passes through a request-scoped `$context`.
 * {@see self::get_settings_fields()} (SP-5 Task 16) applies the same pass-through discipline to
 * the active provider's own {@see \Woodev\Framework\Shipping\Map\Map_Provider::get_settings_fields()}
 * — the handler never learns what a map key IS, only that the active provider may want one.
 *
 * The client verdict rendered in the modal is UX only. `woocommerce_checkout_process`
 * hooks {@see self::handle_checkout_process()}, the real authority: it re-fetches the
 * chosen point via {@see Point_Source::fetch_details()} (the order only ever carries the
 * point id — the §8 field value) and re-runs {@see Constraint_Checker} against the live
 * cart weight and chosen payment method. A `null` result is an AUTHORITATIVE negative —
 * the carrier itself does not know this point — and blocks. Any THROWN exception — a
 * carrier {@see \Woodev_API_Exception}, but just as importantly a `\TypeError`, a
 * transport-library exception, or the plugin's own exception type, since
 * {@see Point_Source::fetch_details()} is a plugin seam wrapping a live carrier SDK — is a
 * failure mode entirely distinct from the identity gate §8 already guarantees: an uncaught
 * throw here is a FATAL on the checkout POST, not a validation notice, so every one of
 * them is caught (`\Throwable`, not a single exception class) and, by default, ALLOWS the
 * order through rather than converting a transient carrier hiccup or a plugin bug into a
 * lost order over a refinement check (COD + weight). This is filterable via
 * `woodev_shipping_pickup_recheck_outage_allows_checkout` for a merchant who would rather
 * block and resolve questionable points by hand; see {@see self::log_carrier_failure()}
 * for how the log line still tells a genuine carrier outage apart from an unexpected error.
 *
 * This class deliberately does NOT re-implement "a pickup point is required" —
 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler} already enforces that twice
 * over (a conditional-required field spec, plus an independent `requires_pickup` backstop
 * keyed by shipping-method id). A third gate here would either double the customer-visible
 * error or silently diverge from it, so a blank posted field value is simply not this
 * handler's concern.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

use Woodev\Framework\Shipping\Map\Map_Provider;
use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
use Woodev\Framework\Shipping\Rest_Api\Pickup_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Handler' ) ) :

	/**
	 * Orchestrates the pickup-point picker: JS config, asset enqueueing, the server-side
	 * constraint re-check, and full-point persistence.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Handler {

		/**
		 * Plugin identifier used to build the REST route and the JS config global name.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $plugin_id;

		/**
		 * The §8 checkout field id holding the chosen point code — the SAME id §8's
		 * Checkout_Handler already uses as the order-meta key for the point id.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $field_id;

		/**
		 * The plugin's carrier pickup-point source.
		 *
		 * @since 2.0.2
		 * @var Point_Source
		 */
		private Point_Source $source;

		/**
		 * The active map provider.
		 *
		 * Owns everything drawn inside the modal's map container; see
		 * {@see Map_Provider} for the seam. `get_js_config()` reads this provider's
		 * own {@see Map_Provider::get_id()} and
		 * {@see Map_Provider::get_js_config()} — never a bare id string —
		 * and {@see self::enqueue_assets()} enqueues under
		 * {@see Map_Provider::get_script_handle()} verbatim, so the `provider`
		 * config value and the enqueued HANDLE can never silently disagree with the
		 * provider. The enqueued script's FILE PATH is still built from
		 * {@see Map_Provider::get_id()} separately (`map-provider-{id}.js`) — that
		 * half is convention, not an enforced invariant; see
		 * {@see Map_Provider::get_script_handle()}'s own docblock.
		 *
		 * @since 2.0.2
		 * @var Map_Provider
		 */
		private Map_Provider $map_provider;

		/**
		 * The plugin's order-meta accessor, or null when the plugin has not wired
		 * full-point persistence.
		 *
		 * @since 2.0.2
		 * @var Shipping_Order_Handler|null
		 */
		private ?Shipping_Order_Handler $order_handler;

		/**
		 * The logical field name {@see Shipping_Order_Handler::store_pickup_point()} stores
		 * the full point under, or null when the plugin has not wired full-point
		 * persistence.
		 *
		 * @since 2.0.2
		 * @var string|null
		 */
		private ?string $point_field_logical;

		/**
		 * Whether the picker replaces the delivery address fields with the selected
		 * point's address (SP-5 Task 17). Only the STABLE half of the rule — this flag,
		 * plus `wc_ship_to_billing_address_only()` — travels to the browser via
		 * {@see self::get_js_config()}; see the class docblock's second deviation for why
		 * a resolved `billing`/`shipping` target never does.
		 *
		 * @since 2.0.2
		 * @var bool
		 */
		private bool $replace_address;

		/**
		 * Per-request memoization of {@see Point_Source::fetch_details()}, keyed by point
		 * id — see {@see self::fetch_point()}.
		 *
		 * @since 2.0.2
		 * @var array<string, Pickup_Point|null>
		 */
		private array $fetched_points = [];

		/**
		 * Per-request memoization of a thrown fetch failure, keyed by point id — a repeat
		 * lookup for the SAME id in the same request re-throws the same failure rather than
		 * hitting the carrier again.
		 *
		 * @since 2.0.2
		 * @var array<string, \Throwable>
		 */
		private array $fetch_failures = [];

		/**
		 * The plugin's hardcoded default viewport (Moscow for CDEK/Yandex.Delivery, etc.),
		 * used when the geocoder cascade cannot yet centre the map on the buyer's own city
		 * (spec D-7). REQUIRED — a shared, load-bearing value must never be something an
		 * author can forget, the same reasoning behind
		 * {@see \Woodev\Framework\Shipping\Map\Yandex_Map_Provider}'s required fallback
		 * key. Validated once, on construction, by {@see self::validate_default_location()}.
		 *
		 * @since 2.0.2
		 * @var array{center: array{0: float|int, 1: float|int}, zoom: int}
		 */
		private array $default_location;

		/**
		 * Plugin-supplied icon URLs, keyed by point type code, each holding up to a
		 * `default` and an `active` URL (spec D-5). Stored RAW — normalisation (dropping an
		 * unusable type, filling `active` from `default`, escaping every URL) happens once,
		 * at config-build time, in {@see self::normalized_point_icons()}, never here.
		 *
		 * @since 2.0.2
		 * @var array<string, array{default?: string, active?: string}>
		 */
		private array $point_icons;

		/**
		 * The minimum zoom level {@see self::validate_default_location()} accepts,
		 * matching the map provider's own configured `minZoom` (spec D-7). A default
		 * viewport the map itself refuses to render at is not a usable obligation.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		private const MIN_ZOOM = 8;

		/**
		 * The maximum zoom level {@see self::validate_default_location()} accepts,
		 * matching the map provider's own configured `maxZoom` (spec D-7).
		 *
		 * @since 2.0.2
		 * @var int
		 */
		private const MAX_ZOOM = 18;

		/**
		 * Framework fallback accent colour, used only when NEITHER the merchant setting
		 * NOR the plugin default survives sanitisation (spec D-15). The framework itself
		 * knows no carrier's brand — this exists purely so `resolve_accent_color()` always
		 * has a valid hex colour to fall back to.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const DEFAULT_ACCENT_COLOR = '#06aedd';

		/**
		 * Framework default number of nearest groups an address search fits the camera to
		 * (Task 19, D-6) — see {@see self::resolve_search_nearest_count()}. Deliberately NOT
		 * a constructor argument: what varies between installs is network DENSITY, and that
		 * varies between cities of one carrier far more than between carriers, so a single
		 * per-plugin number could never track it — fitting to the N nearest points adapts
		 * automatically, because it works in geometry rather than in kilometres.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		private const DEFAULT_SEARCH_NEAREST_COUNT = 3;

		/**
		 * The plugin's default accent colour (spec D-15) — drives the map's CTA, the
		 * active list item, the drawer toggle, the cluster icon, and the checkout trigger
		 * button. Overridden by {@see self::$setting_accent_color} when the merchant has
		 * set one.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $accent_color;

		/**
		 * The merchant's own accent-colour setting, or `''` when unset — resolution order
		 * is merchant setting → plugin default → framework default (spec D-15), the same
		 * shape as the API-key fallback.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $setting_accent_color;

		/**
		 * Whether the map offers an address search (Task 18, spec V-6), plugin-settable,
		 * default `true`. Deliberately a `Pickup_Handler` property, never a
		 * {@see Map_Provider} method: whether a carrier wants an address search is the
		 * plugin's decision, not the map library's, and `Map_Provider` currently declares
		 * only `get_id`/`get_label`/`get_script_handle`/`get_settings_fields`/
		 * `get_js_config`/`owns_chrome` — adding a seventh method would oblige both
		 * providers and every fixture to answer a question neither of them owns. Read by
		 * {@see self::get_js_config()}, which applies the
		 * `woodev_pickup_map_search_enabled` filter on top before emitting it.
		 *
		 * @since 2.0.2
		 * @var bool
		 */
		private bool $search_enabled;

		/**
		 * Constructor.
		 *
		 * `$order_handler` and `$point_field_logical` are optional and go together: when
		 * either is omitted, full-point persistence is skipped entirely rather than the
		 * framework coining a key of its own — see
		 * {@see self::handle_checkout_order_processed()}.
		 *
		 * @since 2.0.2
		 *
		 * @param string                      $plugin_id           plugin identifier (REST route +
		 *                                                          JS config global name).
		 * @param string                      $field_id            the §8 checkout field id holding
		 *                                                          the chosen point code.
		 * @param Point_Source                $source              the plugin's carrier
		 *                                                          pickup-point source; its
		 *                                                          {@see Point_Source::get_strategy()}
		 *                                                          is the single source of truth
		 *                                                          for the strategy — see the
		 *                                                          class docblock's first
		 *                                                          deviation.
		 * @param Map_Provider                $map_provider        the active map provider.
		 * @param array                       $default_location    the plugin's hardcoded
		 *                                                          default viewport — REQUIRED,
		 *                                                          see {@see self::$default_location}.
		 *                                                          Shape: `[ 'center' => [ float|int
		 *                                                          $lat, float|int $lng ], 'zoom' =>
		 *                                                          int ]`. Validated on
		 *                                                          construction; see
		 *                                                          {@see self::validate_default_location()}.
		 * @param Shipping_Order_Handler|null $order_handler   the plugin's order-meta
		 *                                                      accessor, holding its own
		 *                                                      logical→real key map. Omit to
		 *                                                      skip full-point persistence.
		 * @param string|null                 $point_field_logical the logical field name to store
		 *                                                          the full point under via
		 *                                                          {@see Shipping_Order_Handler::store_pickup_point()}.
		 *                                                          Omit to skip full-point
		 *                                                          persistence.
		 * @param bool                        $replace_address     whether the picker replaces the
		 *                                                          delivery address fields with the
		 *                                                          selected point's address (SP-5
		 *                                                          Task 17). Appended last, after the
		 *                                                          `$order_handler` /
		 *                                                          `$point_field_logical` pair, so
		 *                                                          the overwhelming majority of
		 *                                                          callers — who want the default
		 *                                                          `true` — never have to pass
		 *                                                          anything for it, including every
		 *                                                          caller that DOES wire full-point
		 *                                                          persistence.
		 * @param array                       $point_icons         plugin-supplied icon URLs per
		 *                                                          point type (spec D-5); see
		 *                                                          {@see self::$point_icons}. Optional
		 *                                                          — a plugin that supplies none gets
		 *                                                          the framework's generic pin.
		 * @param string                      $accent_color        the plugin's default accent
		 *                                                          colour (spec D-15); see
		 *                                                          {@see self::$accent_color}.
		 *                                                          Optional — a plugin that supplies
		 *                                                          none inherits the framework's own
		 *                                                          default.
		 * @param string                      $setting_accent_color the merchant's own accent-colour
		 *                                                          setting (spec D-15); see
		 *                                                          {@see self::$setting_accent_color}.
		 *                                                          Optional, empty when unset.
		 * @param bool                        $search_enabled       whether the map offers an address
		 *                                                          search (Task 18, spec V-6); see
		 *                                                          {@see self::$search_enabled}.
		 *                                                          Optional, default `true`.
		 *
		 * @throws \InvalidArgumentException when `$default_location` does not have a valid
		 *                                    `center` (two floats/ints, lat within ±90, lng
		 *                                    within ±180) and a valid `zoom` (an int between
		 *                                    {@see self::MIN_ZOOM} and {@see self::MAX_ZOOM}).
		 */
		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			?Shipping_Order_Handler $order_handler = null,
			?string $point_field_logical = null,
			bool $replace_address = true,
			array $point_icons = [],
			string $accent_color = self::DEFAULT_ACCENT_COLOR,
			string $setting_accent_color = '',
			bool $search_enabled = true
		) {
			self::validate_default_location( $default_location );

			$this->plugin_id            = $plugin_id;
			$this->field_id             = $field_id;
			$this->source               = $source;
			$this->map_provider         = $map_provider;
			$this->default_location     = $default_location;
			$this->order_handler        = $order_handler;
			$this->point_field_logical  = $point_field_logical;
			$this->replace_address      = $replace_address;
			$this->point_icons          = $point_icons;
			$this->accent_color         = $accent_color;
			$this->setting_accent_color = $setting_accent_color;
			$this->search_enabled       = $search_enabled;
		}

		/**
		 * Resolves the map's accent colour: the merchant's setting, else the plugin's
		 * default, else the framework's (spec D-15). Filterable, and sanitised AFTER the
		 * filter — a filter is an untrusted input on a path that ends in CSS; sanitising
		 * only the merchant setting and the plugin default would let a filter returning
		 * garbage reach `setProperty()` unvalidated.
		 *
		 * Lowercasing happens HERE, exactly once, on the final resolved value — regardless
		 * of which branch produced it — and nowhere else. `sanitize_hex_color()` (verified
		 * against WordPress core) does NOT itself lower-case; it returns a matching value
		 * byte-for-byte and `null` otherwise. Without this explicit step, a plugin default
		 * of `#FCE000` would reach the browser uppercase.
		 *
		 * @since 2.0.2
		 *
		 * @return string a valid, lower-cased hex colour, never an empty string.
		 */
		private function resolve_accent_color(): string {
			$candidate = '' !== $this->setting_accent_color ? $this->setting_accent_color : $this->accent_color;

			/**
			 * Filters the pickup map's accent colour.
			 *
			 * @since 2.0.2
			 *
			 * @param string $colour resolved colour, before sanitisation.
			 */
			$filtered = (string) apply_filters( 'woodev_pickup_accent_color', $candidate );

			$sanitized = sanitize_hex_color( $filtered )
				?: ( sanitize_hex_color( $this->accent_color ) ?: self::DEFAULT_ACCENT_COLOR );

			return strtolower( $sanitized );
		}

		/**
		 * Resolves the number of nearest pickup points an address search fits the camera to
		 * (Task 19, D-6) — filterable via `woodev_pickup_search_nearest_count`, sanitised
		 * AFTER the filter, same discipline as {@see self::resolve_accent_color()}: a filter
		 * is untrusted input on a path that ends in a camera fit, so a filtered value that is
		 * not a positive integer falls back to {@see self::DEFAULT_SEARCH_NEAREST_COUNT}
		 * rather than reaching the browser as zero, negative, or non-numeric — any of which
		 * would make an address search fit the camera to nothing.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		private function resolve_search_nearest_count(): int {
			/**
			 * Filters the number of nearest pickup points an address search fits the camera to.
			 *
			 * @since 2.0.2
			 *
			 * @param int $count default {@see Pickup_Handler::DEFAULT_SEARCH_NEAREST_COUNT}.
			 */
			$filtered = apply_filters( 'woodev_pickup_search_nearest_count', self::DEFAULT_SEARCH_NEAREST_COUNT );

			return is_int( $filtered ) && $filtered > 0 ? $filtered : self::DEFAULT_SEARCH_NEAREST_COUNT;
		}

		/**
		 * Validates a plugin's default-viewport argument, throwing when it is not
		 * something the map can actually render — an obligation that silently accepts
		 * nonsense is not an obligation (spec D-7).
		 *
		 * `center` accepts an int OR a float for each coordinate — a whole-degree city
		 * centre (e.g. `56`) is just as valid a latitude as `55.75` — but the VALUE must be
		 * a real number, not a numeric string. `zoom` must be an `int` (not a numeric
		 * string, not a float) within {@see self::MIN_ZOOM}..{@see self::MAX_ZOOM}
		 * inclusive — the same range the map provider itself is configured with, so a
		 * default the map would refuse to honour can never be constructed.
		 *
		 * @since 2.0.2
		 *
		 * @param array $default_location the raw constructor argument to validate.
		 *
		 * @throws \InvalidArgumentException on any of the failures documented above.
		 *
		 * @return void
		 */
		private static function validate_default_location( array $default_location ): void {
			if ( ! array_key_exists( 'center', $default_location )
				|| ! array_key_exists( 'zoom', $default_location ) ) {
				throw new \InvalidArgumentException(
					'$default_location must have both a "center" and a "zoom" key.'
				);
			}

			$center = $default_location['center'];

			if ( ! is_array( $center ) || 2 !== count( $center )
				|| ! array_key_exists( 0, $center ) || ! array_key_exists( 1, $center ) ) {
				throw new \InvalidArgumentException(
					'$default_location["center"] must be a two-element [ lat, lng ] array.'
				);
			}

			[ $lat, $lng ] = array_values( $center );

			if ( ! is_int( $lat ) && ! is_float( $lat ) ) {
				throw new \InvalidArgumentException(
					'$default_location["center"][0] (latitude) must be an int or a float.'
				);
			}

			if ( ! is_int( $lng ) && ! is_float( $lng ) ) {
				throw new \InvalidArgumentException(
					'$default_location["center"][1] (longitude) must be an int or a float.'
				);
			}

			// NAN fails EVERY comparison (including against itself), so `$lat < -90.0` and
			// `$lat > 90.0` are both false for it — the range check below would silently let
			// a NaN latitude through. INF/-INF pass `is_float()` but fail the range check
			// anyway; `is_finite()` catches both in one place before either value can reach
			// `wp_json_encode()`, which cannot represent NAN/INF at all.
			if ( ! is_finite( (float) $lat ) ) {
				throw new \InvalidArgumentException(
					'$default_location["center"][0] (latitude) must be a finite number.'
				);
			}

			if ( ! is_finite( (float) $lng ) ) {
				throw new \InvalidArgumentException(
					'$default_location["center"][1] (longitude) must be a finite number.'
				);
			}

			if ( $lat < -90.0 || $lat > 90.0 ) {
				throw new \InvalidArgumentException(
					'$default_location["center"][0] (latitude) must be between -90 and 90.'
				);
			}

			if ( $lng < -180.0 || $lng > 180.0 ) {
				throw new \InvalidArgumentException(
					'$default_location["center"][1] (longitude) must be between -180 and 180.'
				);
			}

			$zoom = $default_location['zoom'];

			if ( ! is_int( $zoom ) ) {
				throw new \InvalidArgumentException( '$default_location["zoom"] must be an int.' );
			}

			if ( $zoom < self::MIN_ZOOM || $zoom > self::MAX_ZOOM ) {
				throw new \InvalidArgumentException(
					sprintf(
						'$default_location["zoom"] must be between %d and %d.',
						self::MIN_ZOOM,
						self::MAX_ZOOM
					)
				);
			}
		}

		/**
		 * Normalises {@see self::$point_icons} into the shape the browser receives, once,
		 * at config-build time (spec D-5):
		 *
		 * - a type whose `default` is missing entirely is dropped — the framework never
		 *   invents a placeholder icon;
		 * - every URL is run through `esc_url_raw()` — this is a JSON payload, not HTML,
		 *   so `esc_url_raw()`, never `esc_url()` (which would turn a querystring `&` into
		 *   `&#038;`);
		 * - a `default` that survives escaping as an empty string (e.g. a `javascript:`
		 *   URL, which WordPress's own bad-protocol stripping collapses to `''`) drops the
		 *   whole type too — an icon pointing at `""` is not a usable icon;
		 * - `active` falls back to the (already-escaped) `default` when the plugin supplied
		 *   only one image per type (CDEK's shape: active state expressed by CSS size
		 *   alone, see the class docblock's D-5 reference).
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array{default: string, active: string}>
		 */
		private function normalized_point_icons(): array {
			$normalized = [];

			foreach ( $this->point_icons as $type => $urls ) {
				if ( ! is_array( $urls ) || empty( $urls['default'] ) || ! is_string( $urls['default'] ) ) {
					continue;
				}

				$default = esc_url_raw( $urls['default'] );

				if ( '' === $default ) {
					continue;
				}

				$active = ( ! empty( $urls['active'] ) && is_string( $urls['active'] ) )
					? esc_url_raw( $urls['active'] )
					: $default;

				$normalized[ $type ] = [
					'default' => $default,
					'active'  => '' !== $active ? $active : $default,
				];
			}

			return $normalized;
		}

		/**
		 * Builds the JS-safe config the browser mounts the picker from.
		 *
		 * Never emits a carrier credential, a callable, or the {@see Point_Source} instance
		 * itself — mirrors
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config}'s discipline. See the
		 * class docblock for why `strategy` always comes from the source and
		 * `replaceAddress` never carries a resolved target. `restRoot` is the points
		 * COLLECTION url (see {@see self::rest_root()}); the client concatenates `/{id}`
		 * for the single-point detail route.
		 *
		 * @since 2.0.2
		 *
		 * @return array{
		 *     fieldId: string,
		 *     strategy: string,
		 *     provider: string,
		 *     restRoot: string,
		 *     nonce: string,
		 *     i18n: array<string, string>,
		 *     defaultLocation: array{center: array{0: float|int, 1: float|int}, zoom: int},
		 *     pointIcons: array<string, array{default: string, active: string}>,
		 *     mapConfig: array<string, mixed>,
		 *     replaceAddress: array{enabled: bool, billingOnly: bool},
		 *     accentColor: string,
		 *     searchNearestCount: int,
		 *     modal: array{width: int, bodyHeight: string},
		 *     search: bool
		 * }
		 */
		public function get_js_config(): array {
			$strings = [
				'modalTitle'     => __( 'Выберите пункт выдачи', 'woodev-plugin-framework' ),
				'close'          => __( 'Закрыть', 'woodev-plugin-framework' ),
				'select'         => __( 'Выбрать этот пункт', 'woodev-plugin-framework' ),
				'loading'        => __( 'Загрузка пунктов выдачи…', 'woodev-plugin-framework' ),
				'error'          => __(
					'Не удалось загрузить пункты выдачи. Попробуйте ещё раз.',
					'woodev-plugin-framework'
				),
				'noResults'      => __( 'Поиск не дал результатов.', 'woodev-plugin-framework' ),
				'blocked'        => __(
					'Этот пункт выдачи недоступен для вашего заказа.',
					'woodev-plugin-framework'
				),
				// Consumed by the mount script (Task 12), not by the modal shell or the map
				// provider — see Pickup_Mount's own docblock for why it reads these keys.
				'trigger'        => __( 'Выбрать пункт выдачи', 'woodev-plugin-framework' ),
				'retry'          => __( 'Повторить', 'woodev-plugin-framework' ),
				'upstreamError'  => __(
					'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.',
					'woodev-plugin-framework'
				),
				'rateLimited'    => __(
					'Слишком много запросов. Подождите немного и попробуйте снова.',
					'woodev-plugin-framework'
				),
				'notFound'       => __(
					'Этот пункт выдачи больше не найден. Пожалуйста, выберите другой.',
					'woodev-plugin-framework'
				),
				// Consumed by the map provider scripts (Tasks 13/14), not by this handler or
				// the modal shell — a missing key here renders BLANK in the provider's UI
				// rather than throwing, so every one of these nine must stay exact: the
				// provider reads them by name, never falls back to a hardcoded default.
				'search'         => __( 'Поиск по адресу', 'woodev-plugin-framework' ),
				'drawerTitle'    => __( 'Пункты выдачи в этой области', 'woodev-plugin-framework' ),
				'howToGet'       => __( 'Как добраться', 'woodev-plugin-framework' ),
				'paymentMethods' => __( 'Способы оплаты', 'woodev-plugin-framework' ),
				'workTime'       => __( 'Часы работы', 'woodev-plugin-framework' ),
				'phone'          => __( 'Телефон', 'woodev-plugin-framework' ),
				'maxWeight'      => __( 'Максимальный вес', 'woodev-plugin-framework' ),
				'allTypes'       => __( 'Все типы пунктов', 'woodev-plugin-framework' ),
				'detailsError'   => __(
					'Не удалось загрузить подробности о пункте выдачи. Вы всё ещё можете его выбрать.',
					'woodev-plugin-framework'
				),
				// Consumed by the panels (Tasks 12-15), not by this handler or the modal
				// shell — same "renders blank, never a hardcoded fallback" contract as the
				// nine keys above.
				// Task 15 (spec V-12): the point card's "Адрес" section title. Distinct
				// from `yourAddress` below, which labels the SEARCH field, not a card section.
				'address'          => __( 'Адрес', 'woodev-plugin-framework' ),
				'services'         => __( 'Услуги', 'woodev-plugin-framework' ),
				'yourAddress'      => __( 'Ваш адрес', 'woodev-plugin-framework' ),
				/* translators: %s: the searched address. */
				'nearestTo'        => __( 'Ближайшие к «%s»', 'woodev-plugin-framework' ),
				'resetSearch'      => __( 'Сбросить', 'woodev-plugin-framework' ),
				'nothingNearby'    => __(
					'Рядом с этим адресом пунктов выдачи нет.',
					'woodev-plugin-framework'
				),
				'showNearest'      => __( 'Показать ближайший', 'woodev-plugin-framework' ),
				'continueCheckout' => __( 'Продолжить оформление заказа', 'woodev-plugin-framework' ),
				'zoomIn'           => __(
					'Приблизьте карту, чтобы увидеть пункты выдачи',
					'woodev-plugin-framework'
				),
				'sectionPoints'    => __( 'Пункты выдачи', 'woodev-plugin-framework' ),
				'sectionAddresses' => __( 'Адреса', 'woodev-plugin-framework' ),
				'filterTypes'      => __( 'Тип пунктов', 'woodev-plugin-framework' ),
				'emptyInView'      => __( 'В этой области пунктов выдачи нет', 'woodev-plugin-framework' ),
				// Task 17 (spec V-5): a genuinely empty LOCALITY, distinct from `emptyInView`
				// (a viewport-strategy "none in THIS VIEW" statement) and from `noResults`
				// (a search found nothing) — an empty result is domain language (Russian Post
				// has no pickup points, it has post offices), which is exactly why this whole
				// map passes through the filter below rather than the framework guessing at
				// carrier-specific wording itself.
				'emptyLocality'    => __(
					'В выбранном населённом пункте нет пунктов выдачи',
					'woodev-plugin-framework'
				),
				// The checkout trigger's second state (Task 20) — a customer who has
				// already chosen a point sees this instead of `trigger`.
				'triggerChange'    => __( 'Выбрать другой пункт выдачи', 'woodev-plugin-framework' ),
				// Task 14 (spec V-13): the zoom control's two `aria-label`s. Distinct from
				// `zoomIn` above, which labels the unrelated "zoom in to see points" bbox
				// message — reusing it here would have mislabelled the button with a full
				// sentence about a different situation.
				'zoomInLabel'      => __( 'Приблизить карту', 'woodev-plugin-framework' ),
				'zoomOutLabel'     => __( 'Отдалить карту', 'woodev-plugin-framework' ),
			];

			/**
			 * Filters every customer-facing string the pickup map renders.
			 *
			 * An empty result is domain language, not framework language — Russian Post has no
			 * pickup points, it has post offices, so `emptyLocality`'s framework default above
			 * is wrong text for it. Rather than growing a second, parallel `messages` array
			 * beside this one, the assembled string map IS the override surface: a plugin
			 * overrides any key it disagrees with (spec V-5). One string system, not two.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string, string> $strings   The framework's default strings.
			 * @param string                $plugin_id The plugin the map belongs to.
			 */
			$strings = apply_filters( 'woodev_pickup_map_i18n', $strings, $this->plugin_id );

			/**
			 * Filters whether the pickup map offers an address search (Task 18, spec V-6).
			 *
			 * @since 2.0.2
			 *
			 * @param bool   $search_enabled the plugin's own constructor-supplied default.
			 * @param string $plugin_id      The plugin the map belongs to.
			 */
			$search_enabled = (bool) apply_filters(
				'woodev_pickup_map_search_enabled',
				$this->search_enabled,
				$this->plugin_id
			);

			return [
				'fieldId'  => $this->field_id,
				'strategy' => $this->source->get_strategy(),
				'provider' => $this->map_provider->get_id(),
				'restRoot' => $this->rest_root(),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => $strings,

				'defaultLocation' => $this->default_location,
				'pointIcons'      => $this->normalized_point_icons(),

				'mapConfig'      => $this->map_provider->get_js_config( [ 'plugin_id' => $this->plugin_id ] ),

				'replaceAddress' => [
					'enabled'     => $this->replace_address,
					'billingOnly' => (bool) wc_ship_to_billing_address_only(),
				],

				// Top level, NOT inside `mapConfig`: the checkout trigger button lives
				// outside the modal entirely and needs this too (spec D-15).
				'accentColor' => $this->resolve_accent_color(),

				// Consumed by the map provider's own address-search fit (Task 19, D-6) — see
				// self::resolve_search_nearest_count()'s own docblock for why this is a
				// framework-filterable constant rather than a plugin constructor argument.
				'searchNearestCount' => $this->resolve_search_nearest_count(),

				// The dialog sizes itself before any content exists (spec V-1); these two
				// values used to live only in CSS, on the MAP element, which is why the
				// modal opened as a header-tall strip until the map mounted.
				//
				// Raised 920 -> 1024 after the operator's live review: the sidebar takes a
				// fixed 320px out of this width whenever it is open, so at 920 the map was
				// left with under 600px and, in his words, "от карты почти ничего не
				// остаётся". The sidebar's width is fixed while the dialog's is not, so the
				// only way to give the map back usable area is to widen the dialog. 1024 is
				// his own suggested figure and still fits a 1280-wide viewport with margin
				// to spare; narrower viewports are unaffected, since the dialog goes
				// full-screen below its own breakpoint (see woodev-modal.css).
				'modal' => [
					'width'      => 1024,
					'bodyHeight' => 'min(80vh, 800px)',
				],

				// Top level, NOT inside `mapConfig`: see self::$search_enabled's own
				// docblock for why this is a handler property, not a Map_Provider method.
				'search' => $search_enabled,
			];
		}

		/**
		 * Gets the settings fields the active map provider needs, if any, merged with the
		 * framework's own `pickup_accent_color` field (spec D-15).
		 *
		 * Stopped being a pure pass-through to {@see Map_Provider::get_settings_fields()}
		 * as of Task 8B — the accent colour is a framework-owned field, not something any
		 * provider knows about (a provider merely READS the resolved colour via
		 * {@see self::resolve_accent_color()} for e.g. `clusterIconColor`; see spec D-15).
		 * The FRAMEWORK's field is added LAST, after the provider's own fields — a provider
		 * field named `pickup_accent_color` would otherwise silently win, and a provider
		 * accidentally shadowing the framework's own settings key is a much stranger bug to
		 * chase than "the framework's key always wins"; see
		 * {@see PickupHandlerTest::test_a_provider_field_cannot_shadow_the_frameworks_accent_field()}.
		 *
		 * This is NOT automatic. Nothing on the framework side calls this method — the
		 * plugin that owns the shipping integration MUST call it itself and merge the
		 * result into its own settings registration for the merchant-facing
		 * `map_api_key` and `pickup_accent_color` fields to exist at all. Spec §10.8
		 * amends §4.7's "auto-registers" wording, which described the field as something a
		 * plugin "automatically gains"; the framework cannot register a field into a
		 * plugin's own settings provider without owning it, the same boundary §10.6 already
		 * drew for the fallback key. Skip the call and every install of the plugin stays
		 * pinned to the plugin's shared fallback key and default accent colour — exactly
		 * the quota risk §4.7 flagged as a watch item.
		 *
		 * The provider's own descriptors are passed through UNMODIFIED — in the Woodev
		 * settings-API `register_setting()` args shape (`name`, `type`, `default`,
		 * `required`, `sensitive`, `description`), not the WooCommerce `form_fields` shape
		 * — so the caller merges it into the shipping integration's own settings as-is.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array<string, mixed>> settings field definitions keyed by field id.
		 */
		public function get_settings_fields(): array {
			return array_merge(
				$this->map_provider->get_settings_fields(),
				[
					'pickup_accent_color' => [
						'name'        => __( 'Акцентный цвет карты', 'woodev-plugin-framework' ),
						'type'        => \Woodev_Setting::TYPE_STRING,
						'controlType' => \Woodev_Control::TYPE_COLOR,
						'description' => __(
							'Цвет кнопок, активных пунктов и кластеров на карте пунктов выдачи.',
							'woodev-plugin-framework'
						),
						'default'     => $this->accent_color,
						'required'    => false,
					],
				]
			);
		}

		/**
		 * Re-checks a resolved point's constraints against the cart and payment method.
		 *
		 * Pure constraint check — delegates to {@see Constraint_Checker}, adding a
		 * WooCommerce error notice (which blocks placement) on failure. Does not itself
		 * fetch anything; see {@see self::validate_posted_point()} for the id-based entry
		 * point {@see self::handle_checkout_process()} actually calls.
		 *
		 * @since 2.0.2
		 *
		 * @param Pickup_Point $point          the resolved point to re-check.
		 * @param string       $payment_method the chosen WooCommerce payment method id.
		 * @param int          $cart_weight    current cart weight in GRAMS.
		 *
		 * @return bool true when the point is still selectable; false when checkout is blocked.
		 */
		public function validate_selected_point( Pickup_Point $point, string $payment_method, int $cart_weight ): bool {
			$verdict = ( new Constraint_Checker() )->check( $point, $payment_method, $cart_weight );

			if ( ! $verdict['allowed'] ) {
				$reason = null !== $verdict['reason'] ? $verdict['reason'] : self::default_blocked_message();
				$this->add_error( $reason );

				return false;
			}

			return true;
		}

		/**
		 * Resolves the posted point id via the {@see Point_Source} and re-checks it — the
		 * authoritative server-side backstop behind the client's UX-only verdict.
		 *
		 * Three outcomes:
		 * - The point is found → delegates to {@see self::validate_selected_point()}.
		 * - `null` → an AUTHORITATIVE negative (the carrier itself does not know this
		 *   point); blocks with a message asking the customer to choose again.
		 * - Any thrown `\Throwable` (a carrier `\Woodev_API_Exception`, but also a
		 *   `\TypeError`, a transport-library exception, or the plugin's own exception
		 *   type — see the class docblock) → NOT an identity failure; ALLOWS the order
		 *   through by default (filterable — see {@see self::evaluate_recheck_outage()})
		 *   and logs.
		 *
		 * @since 2.0.2
		 *
		 * @param string $point_id       the posted point id (the §8 field value).
		 * @param string $payment_method the chosen WooCommerce payment method id.
		 * @param int    $cart_weight    current cart weight in GRAMS.
		 *
		 * @return bool true when checkout may proceed; false when it is blocked.
		 */
		public function validate_posted_point( string $point_id, string $payment_method, int $cart_weight ): bool {
			try {
				$point = $this->fetch_point( $point_id );
			} catch ( \Throwable $e ) {
				return $this->evaluate_recheck_outage( $e, $point_id );
			}

			if ( null === $point ) {
				$this->add_error( self::point_unavailable_message() );

				return false;
			}

			return $this->validate_selected_point( $point, $payment_method, $cart_weight );
		}

		/**
		 * Fetches a point via the {@see Point_Source}, memoized per instance by point id.
		 *
		 * `woocommerce_checkout_process` and `woocommerce_checkout_order_processed` both
		 * fire inside the SAME `WC_Checkout::process_checkout()` call against the SAME
		 * `$_POST`, and both need the same point — without memoization that is two carrier
		 * round-trips on the most latency-sensitive request in the store, the second one
		 * AFTER the order already exists (delaying the thank-you redirect for an order
		 * already placed). Safe because a `Pickup_Handler` instance is built once per
		 * request; a repeat lookup for the SAME id re-throws the SAME failure rather than
		 * calling the carrier again.
		 *
		 * @since 2.0.2
		 *
		 * @param string $point_id the point id to fetch.
		 *
		 * @return Pickup_Point|null
		 *
		 * @throws \Throwable whatever {@see Point_Source::fetch_details()} threw on the
		 *                     first lookup for this id.
		 */
		private function fetch_point( string $point_id ): ?Pickup_Point {
			if ( array_key_exists( $point_id, $this->fetch_failures ) ) {
				throw $this->fetch_failures[ $point_id ];
			}

			if ( array_key_exists( $point_id, $this->fetched_points ) ) {
				return $this->fetched_points[ $point_id ];
			}

			try {
				$point = $this->source->fetch_details( $point_id );
			} catch ( \Throwable $e ) {
				$this->fetch_failures[ $point_id ] = $e;

				throw $e;
			}

			$this->fetched_points[ $point_id ] = $point;

			return $point;
		}

		/**
		 * Evaluates whether an outage encountered while re-checking the posted point
		 * allows checkout to proceed.
		 *
		 * Not a hook callback despite the `\Throwable` parameter — named without the
		 * `handle_` prefix this project reserves for `add_action`/`add_filter` targets.
		 * Logs the real exception (never shown to the customer, see
		 * {@see self::log_carrier_failure()}) and applies the
		 * `woodev_shipping_pickup_recheck_outage_allows_checkout` filter, which defaults to
		 * ALLOW — see the class docblock for why an outage (or any other thrown failure)
		 * must not block checkout by default.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable $e        the caught exception — a carrier outage or an
		 *                             unexpected error (see {@see self::log_carrier_failure()}).
		 * @param string     $point_id the point id that failed to resolve.
		 *
		 * @return bool true when checkout may proceed despite the failure; false when blocked.
		 */
		private function evaluate_recheck_outage( \Throwable $e, string $point_id ): bool {
			$this->log_carrier_failure( $e, 'checkout re-check' );

			/**
			 * Filters whether a carrier outage (or other unexpected failure) during the
			 * checkout re-check allows checkout to proceed.
			 *
			 * Defaults to `true`: the re-check is a refinement (COD + weight), not the
			 * identity gate §8 already guarantees, and blocking on a transient failure
			 * converts it into a lost order. A merchant who would rather block and resolve
			 * questionable points by hand can return `false`.
			 *
			 * @since 2.0.2
			 *
			 * @param bool       $allow     Whether checkout may proceed. Default true.
			 * @param \Throwable $exception The caught exception.
			 * @param string     $plugin_id The owning plugin id.
			 * @param string     $point_id  The point id that failed to resolve.
			 */
			$allow = (bool) apply_filters(
				'woodev_shipping_pickup_recheck_outage_allows_checkout',
				true,
				$e,
				$this->plugin_id,
				$point_id
			);

			if ( ! $allow ) {
				$this->add_error( self::outage_blocked_message() );
			}

			return $allow;
		}

		/**
		 * Wires the handler into WooCommerce checkout.
		 *
		 * Enqueues the picker's frontend assets on `wp_enqueue_scripts` (checkout page
		 * only), registers the `woodev/v1` pickup-points REST routes on `rest_api_init`
		 * (see {@see self::register_rest()} — closing the gap where a plugin wires this
		 * handler but forgets {@see Pickup_Controller}, and the map fetches 404 with no
		 * diagnostic), hooks the server-side re-check onto `woocommerce_checkout_process`,
		 * and hooks full-point persistence onto `woocommerce_checkout_order_processed` —
		 * which fires AFTER the order is saved, matching
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::register()}'s own
		 * reasoning for why persisting any earlier silently drops the meta on classic
		 * storage.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register(): void {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'rest_api_init', [ $this, 'register_rest' ] );
			add_action( 'woocommerce_checkout_process', [ $this, 'handle_checkout_process' ] );
			add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout_order_processed' ], 10, 3 );
		}

		/**
		 * Registers the `woodev/v1` pickup-points REST routes for this handler.
		 *
		 * Constructs {@see Pickup_Controller} with the SAME {@see Point_Source} this
		 * handler re-checks against, and with this handler's own request-context readers —
		 * {@see self::current_cart_weight_grams()} and {@see self::rest_payment_method()}
		 * — as its cart-weight and payment-method callables, closing the asymmetry where
		 * §8's `Checkout_Handler::register()` wires its own REST controller but nothing
		 * wired this one.
		 *
		 * The points/detail GET requests these routes actually serve fire on map panning,
		 * before the checkout form is ever posted, so `$_POST['payment_method']` is empty.
		 * That is NOT the same as the payment method being unknown — the "unknown is
		 * permissive" rule {@see Constraint_Checker::check()} documents was written for a
		 * carrier's genuinely sparse list response, where the framework has no way to learn
		 * the missing value at all. Here the value is knowable: WooCommerce writes the
		 * customer's live choice to `WC()->session` on every `update_order_review` ajax call
		 * the checkout form fires the instant a payment method is picked, and
		 * {@see self::rest_payment_method()} reads it — see that method's own docblock for
		 * why treating this as permissive was the bug an SP-5 rig e2e caught, not a
		 * legitimate use of the sparse-response rule. That fallback is REST-only by design:
		 * {@see self::handle_checkout_process()} deliberately uses the separate, POST-only
		 * {@see self::checkout_payment_method()} instead — see that method's docblock for
		 * why the checkout path must never inherit this one's session fallback.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_rest(): void {
			( new Pickup_Controller(
				$this->plugin_id,
				$this->source,
				[ $this, 'current_cart_weight_grams' ],
				[ $this, 'rest_payment_method' ]
			) )->register_routes();
		}

		/**
		 * Enqueues the picker's frontend assets on the checkout page.
		 *
		 * Registers handles for JS/CSS files owned by SP-5 tasks — the dataSource
		 * (`pickup-datasource.js`, Task 11), the geometry/colour helpers
		 * (`pickup-geo.js`, Task 9, no dependencies of its own — pure functions), the
		 * framework-owned panels (`pickup-panels.js`, Tasks 12-16, depends on `pickup-geo.js`
		 * for its distance/colour arithmetic), the mount script (`pickup-mount.js`, Task 12),
		 * the active provider's script (`map-provider-{$provider}.js`, Tasks 13/14 — depends on
		 * `pickup-geo.js` too: `map-provider-yandex.js` calls its `safeColor()`/`nearest()`/
		 * `boundsFor()`/`matchPoints()`) and the pickup panels' own stylesheet (`pickup.css`,
		 * Task 15). Any of these is still skipped entirely via
		 * {@see self::enqueue_style_if_built()}/{@see self::enqueue_script_if_built()}'s
		 * {@see self::asset_exists()} check rather than enqueueing a `href`/`src` that would
		 * 404 — a missing tag is invisible, but a 404ing one next to fully wired assets is a
		 * live checkout regression. (The modal shell — `woodev-modal.js` AND its chrome
		 * stylesheet `woodev-modal.css` — is a framework-level asset pair since the pickup-map
		 * presentation rework's Tasks 1/3 moved both out of this module entirely; each is
		 * registered ONCE, framework-side, by {@see \Woodev_Plugin::frontend_enqueue_scripts()}
		 * under the shared `woodev-modal` handle (scripts and styles are separate WP registries,
		 * so the shared name is not a collision). This method only ever lists `woodev-modal` as
		 * a script/style dependency, same as any other subsystem that needs a dialog would,
		 * never re-registers it.) The mount config is therefore localized only when the mount
		 * script itself was actually enqueued (it now always is, since every one of its
		 * declared dependencies — including `pickup-geo.js`, `pickup-panels.js` and the provider
		 * handle — is a registered handle as of this fix; see
		 * {@see PickupHandlerTest::test_enqueue_assets_enqueues_only_the_assets_already_built()}).
		 *
		 * LOAD ORDER (Task 20): a UMD-ish file that reads `window.WoodevPickupGeo`/
		 * `window.WoodevPickupPanels` at CALL time (not require time — see those files' own
		 * docblocks) still needs the script actually present in the DOM before the mount runs
		 * — WP's own dependency resolution is what guarantees that, which is why `pickup-mount`
		 * declares `woodev-pickup-geo`/`woodev-pickup-panels`/the provider handle as real
		 * dependencies here rather than relying on enqueue ORDER (WP does not guarantee
		 * source order matches enqueue-call order; only the `deps` array does).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
				return;
			}

			$provider_handle = $this->map_provider->get_script_handle();

			$this->enqueue_script_if_built( 'woodev-pickup-datasource', 'js/frontend/pickup-datasource.js', [] );
			$this->enqueue_script_if_built( 'woodev-pickup-geo', 'js/frontend/pickup-geo.js', [] );
			$this->enqueue_script_if_built(
				'woodev-pickup-panels',
				'js/frontend/pickup-panels.js',
				[ 'woodev-pickup-geo' ]
			);
			$this->enqueue_script_if_built(
				$provider_handle,
				'js/frontend/map-provider-' . $this->map_provider->get_id() . '.js',
				[ 'woodev-pickup-geo' ]
			);

			// `jquery`: the mount script binds `updated_checkout` through jQuery when it is
			// present (see pickup-mount.js's own docblock) — declared explicitly here rather
			// than free-riding on `checkout-field-classic.js` happening to also require it.
			$mount_enqueued = $this->enqueue_script_if_built(
				'woodev-pickup-mount',
				'js/frontend/pickup-mount.js',
				[
					'jquery',
					'woodev-modal',
					'woodev-pickup-datasource',
					'woodev-pickup-geo',
					'woodev-pickup-panels',
					$provider_handle,
				]
			);

			// `woodev-modal`: the framework-registered chrome stylesheet (D-13) — declared as a
			// dependency here, exactly like the mount script does above, never re-registered.
			$this->enqueue_style_if_built( 'woodev-pickup-styles', 'css/frontend/pickup.css', [ 'woodev-modal' ] );

			if ( $mount_enqueued ) {
				wp_localize_script(
					'woodev-pickup-mount',
					'woodev_pickup_config_' . $this->config_object_suffix(),
					$this->get_js_config()
				);
			}
		}

		/**
		 * Enqueues one script handle, but only when its file actually exists on disk.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $handle   the script handle to register.
		 * @param string   $relative path relative to the assets directory.
		 * @param string[] $deps     script dependencies.
		 *
		 * @return bool true when the script was enqueued; false when its file is missing.
		 */
		private function enqueue_script_if_built( string $handle, string $relative, array $deps ): bool {
			$path = self::asset_path( $relative );

			if ( ! static::asset_exists( $path ) ) {
				return false;
			}

			wp_enqueue_script( $handle, self::asset_url( $relative ), $deps, self::asset_version( $path ), true );

			return true;
		}

		/**
		 * Enqueues one stylesheet handle, but only when its file actually exists on disk.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $handle   the style handle to register.
		 * @param string   $relative path relative to the assets directory.
		 * @param string[] $deps     style dependencies — e.g. the framework-registered
		 *                           `woodev-modal` chrome stylesheet (D-13). Never re-registers
		 *                           a dependency handle, only declares it; see class docblock.
		 *
		 * @return bool true when the style was enqueued; false when its file is missing.
		 */
		private function enqueue_style_if_built( string $handle, string $relative, array $deps = [] ): bool {
			$path = self::asset_path( $relative );

			if ( ! static::asset_exists( $path ) ) {
				return false;
			}

			wp_enqueue_style( $handle, self::asset_url( $relative ), $deps, self::asset_version( $path ) );

			return true;
		}

		/**
		 * Reads the posted value of the managed pickup field.
		 *
		 * Guards with `is_scalar()` before casting: an array-valued `$_POST[$field_id]`
		 * (e.g. a malformed or multi-value form field) would otherwise emit an "Array to
		 * string conversion" notice, silently become the literal string `"Array"`, and
		 * trigger a pointless carrier lookup for point id `"Array"`.
		 *
		 * @since 2.0.2
		 *
		 * @return string sanitized field value, or empty string when absent or non-scalar.
		 */
		protected function posted_field_value(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the nonce before hooks fire.
			$value = $_POST[ $this->field_id ] ?? '';

			return is_scalar( $value ) ? wc_clean( (string) wp_unslash( $value ) ) : '';
		}

		public function rest_payment_method(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the nonce before hooks fire.
			$posted = $_POST['payment_method'] ?? '';
			$posted = is_scalar( $posted ) ? wc_clean( (string) wp_unslash( $posted ) ) : '';

			if ( '' !== $posted ) {
				return $posted;
			}

			$chosen = $this->wc_session_chosen_payment_method();

			return is_scalar( $chosen ) ? wc_clean( (string) $chosen ) : '';
		}


		protected function checkout_payment_method(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the nonce before hooks fire.
			$posted = $_POST['payment_method'] ?? '';

			return is_scalar( $posted ) ? wc_clean( (string) wp_unslash( $posted ) ) : '';
		}

		/**
		 * Reads WooCommerce's own record of the customer's live payment-method choice —
		 * `WC()->session->get( 'chosen_payment_method' )` — or `null` when WooCommerce is
		 * unavailable or no session has been started yet (WC loaded but no session store
		 * started, the same "not wrong, just not there yet" case
		 * {@see self::wc_cart()} guards for the cart).
		 *
		 * `protected`, not inlined into {@see self::rest_payment_method()}: a test
		 * subclass overrides this single line to exercise the fallback's precedence and
		 * value handling WITHOUT `WC()` needing to be a real function in the unit-test
		 * process — see {@see self::asset_exists()}'s own docblock for why this project's
		 * test doubles override a single forwarding seam rather than faking WordPress
		 * globals. Every real call site is this method's own default body; only
		 * `PickupHandlerTest`'s probes override it. NOT used by
		 * {@see self::checkout_payment_method()} — that reader is deliberately POST-only.
		 *
		 * @since 2.0.2
		 *
		 * @return mixed the raw session value, or null when unavailable.
		 */
		protected function wc_session_chosen_payment_method() {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return null;
			}

			return WC()->session->get( 'chosen_payment_method' );
		}

		/**
		 * Returns the current WooCommerce cart weight in GRAMS.
		 *
		 * `public`, not `protected`: {@see self::register_rest()} hands this method,
		 * `[ $this, 'current_cart_weight_grams' ]`, to {@see Pickup_Controller} as its
		 * injected cart-weight callable — a PHP callable array invoked from OUTSIDE this
		 * class's scope requires public visibility, or the call fatals with "Call to
		 * protected method ... from scope Pickup_Controller".
		 *
		 * On `woocommerce_checkout_process` ({@see self::handle_checkout_process()}) the
		 * cart is already loaded — WooCommerce guarantees a live cart/session by the time
		 * that hook fires — so the load-fallback below never triggers there; a `0` from
		 * THAT caller still only means an actually-empty cart (or `WC()` being unavailable,
		 * e.g. in a unit test), exactly as before this fix. This method's behaviour on that
		 * call site is unchanged.
		 *
		 * The points/detail REST routes ({@see self::register_rest()}) are a different
		 * story: WooCommerce does NOT initialize `WC()->cart` for a plain custom REST route
		 * (only the core Store API does, via `wc_load_cart()`, for this exact reason) — a
		 * customer panning the map before `update_order_review` has ever run finds
		 * `WC()->cart` null even though a real cart/session exists. Returning `0`
		 * unconditionally in that case (the previous behaviour) silently disabled the §4.5
		 * weight-limit gate for every map request. `wc_load_cart()` (WC 3.6+, guarded by
		 * {@see self::wc_load_cart_available()}) is exactly what the Store API itself calls
		 * to bridge this gap, so this method now does the same — but ONLY when the cart is
		 * not already loaded, never a redundant reload on the checkout-process path.
		 * {@see Pickup_Controller::get_points_data()} and
		 * {@see Pickup_Controller::get_point_data()} each invoke this callable at most ONCE
		 * per REST request — never once per returned point — so the extra initialization
		 * cost lands once per debounced map pan (client-side, 300ms), not once per point on
		 * screen; the same amortization the Store API's own callers already accept for the
		 * identical `wc_load_cart()` call. A cart that still cannot be loaded (WooCommerce
		 * absent, or the load itself leaves `WC()->cart` null) legitimately stays `0` —
		 * permissive, matching {@see Pickup_Controller}'s own docblock for why `0` is a
		 * frequent, expected answer on this path.
		 *
		 * Delegates the actual unit conversion to
		 * {@see Constraint_Checker::to_grams()}, the single conversion authority both this
		 * class and {@see Pickup_Controller} must share.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		public function current_cart_weight_grams(): int {
			$cart = $this->wc_cart();

			if ( ! $cart && $this->wc_load_cart_available() ) {
				$this->load_wc_cart();
				$cart = $this->wc_cart();
			}

			if ( ! $cart ) {
				return 0;
			}

			return Constraint_Checker::to_grams( $cart->get_cart_contents_weight() );
		}

		/**
		 * Reads the live `WC()->cart`, or `null` when WooCommerce is unavailable or no
		 * cart has been initialized for this request yet.
		 *
		 * `protected`, not inlined into {@see self::current_cart_weight_grams()}: a test
		 * subclass overrides this single line to simulate every cart-availability
		 * combination WITHOUT `WC()` needing to be a real function in the unit-test
		 * process — mirrors {@see self::asset_exists()}'s own seam, added for the identical
		 * reason (there, faking a built asset without writing one to disk; here, faking a
		 * WooCommerce global without loading WooCommerce). Every real call site is this
		 * method's own default body; only `PickupHandlerTest`'s probes override it.
		 *
		 * @since 2.0.2
		 *
		 * @return object|null
		 */
		protected function wc_cart() {
			return function_exists( 'WC' ) ? WC()->cart : null;
		}

		/**
		 * Reports whether `wc_load_cart()` exists (WooCommerce 3.6+).
		 *
		 * `protected` for the same test-seam reason as {@see self::wc_cart()}.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected function wc_load_cart_available(): bool {
			return function_exists( 'wc_load_cart' );
		}

		/**
		 * Initializes the WooCommerce cart via `wc_load_cart()` — the same call WooCommerce's
		 * own Store API makes to bridge the identical gap (see
		 * {@see self::current_cart_weight_grams()}'s own docblock).
		 *
		 * `protected` for the same test-seam reason as {@see self::wc_cart()}: a probe
		 * overrides this to simulate a successful (or still-failed) load without the real
		 * `wc_load_cart()` function needing to exist in the unit-test process.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function load_wc_cart(): void {
			wc_load_cart();
		}

		/**
		 * Handles `woocommerce_checkout_process` — the server-side constraint re-check.
		 *
		 * A blank posted field value means nothing is our concern here — see the class
		 * docblock's "does NOT re-implement a pickup point is required" note.
		 *
		 * Reads the payment method via {@see self::checkout_payment_method()}, NOT
		 * {@see self::rest_payment_method()} — the latter's session fallback is a
		 * REST-only best-effort for a GET request that never posts anything; here, on the
		 * checkout POST itself, an absent value IS the answer and must not be overridden
		 * by a stale session entry. See {@see self::checkout_payment_method()}'s own
		 * docblock for why.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function handle_checkout_process(): void {
			$point_id = $this->posted_field_value();

			if ( '' === $point_id ) {
				return;
			}

			$this->validate_posted_point(
				$point_id,
				$this->checkout_payment_method(),
				$this->current_cart_weight_grams()
			);
		}

		/**
		 * Handles `woocommerce_checkout_order_processed` — persists the full normalized
		 * point alongside the id §8 already saved, when the owning plugin has wired
		 * full-point persistence.
		 *
		 * Skips ENTIRELY when {@see self::$order_handler} or
		 * {@see self::$point_field_logical} is absent (see {@see self::__construct()}) —
		 * the framework must not coin an installed-site order-meta key of its own; the
		 * plugin-supplied key map on {@see Shipping_Order_Handler} is the only sanctioned
		 * destination (see
		 * {@see Shipping_Order_Handler::store_pickup_point()}). The id itself is already
		 * persisted by §8 regardless, so a plugin that has not wired this loses nothing
		 * beyond the extra copy.
		 *
		 * Re-reads the field value from `$_POST` directly (rather than trusting
		 * `$posted_data`), mirroring
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_checkout_order_processed()}'s
		 * own convention. A blank value, an unknown point, or any thrown `\Throwable`
		 * while re-fetching are all degraded-but-safe: this method only ever adds to the
		 * id §8 already persisted and never blocks or (re-)throws after the order exists —
		 * see the class docblock for why an uncaught throw here is strictly worse than one
		 * on `woocommerce_checkout_process` (the order row is already committed, and later
		 * priority callbacks on this same hook — including other plugins' — never run).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param int                  $order_id    the created order id (unused; see above).
		 * @param array<string, mixed> $posted_data the posted checkout data (unused; see above).
		 * @param \WC_Order            $order       the created, saved order.
		 *
		 * @return void
		 */
		public function handle_checkout_order_processed( int $order_id, array $posted_data, \WC_Order $order ): void {
			if ( null === $this->order_handler || null === $this->point_field_logical ) {
				return;
			}

			$point_id = $this->posted_field_value();

			if ( '' === $point_id ) {
				return;
			}

			try {
				$point = $this->fetch_point( $point_id );
			} catch ( \Throwable $e ) {
				$this->log_carrier_failure( $e, 'order persistence re-fetch' );

				return;
			}

			if ( null === $point ) {
				return;
			}

			// store_pickup_point() writes to_array() — the canonical, UNESCAPED
			// representation — through the plugin's own logical→real key map. Order meta
			// is data at rest that a later stage sends back to the carrier on export;
			// to_browser_array() exists solely for the REST response and must never reach
			// the database.
			$this->order_handler->store_pickup_point( $order, $this->point_field_logical, $point );
		}

		/**
		 * Logs a swallowed exception's real message.
		 *
		 * `protected`, not `private`, so a test subclass can override and silence it rather
		 * than letting a fake, credential-shaped carrier message reach real test stderr —
		 * mirrors
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller::log_carrier_failure()}.
		 * Accepts `\Throwable`, not just `\Woodev_API_Exception` (see the class docblock):
		 * {@see Point_Source::fetch_details()} is a plugin seam wrapping a live carrier
		 * SDK, so a `\TypeError`, a transport-library exception, or the plugin's own
		 * exception type all reach here too. The log line still tells a genuine carrier
		 * outage apart from an unexpected error — a merchant reading the log needs to know
		 * whether to blame the carrier or file a plugin bug.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable $e       the caught exception.
		 * @param string     $context short description of the failing call.
		 *
		 * @return void
		 */
		protected function log_carrier_failure( \Throwable $e, string $context ): void {
			$kind = $e instanceof \Woodev_API_Exception ? 'carrier outage' : 'unexpected error';

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic
			// for a carrier failure; the browser/customer only ever sees a generic notice.
			error_log(
				sprintf(
					'[woodev] pickup %s failed for plugin "%s" (%s): %s',
					$context,
					$this->plugin_id,
					$kind,
					$e->getMessage()
				)
			);
		}

		/**
		 * Adds a WooCommerce error notice when one is available.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message the error message.
		 *
		 * @return void
		 */
		private function add_error( string $message ): void {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Default "blocked" message used only if a filtered {@see Constraint_Checker}
		 * verdict is blocked with a null reason (should not happen in practice, but
		 * `wc_add_notice()` must never receive an empty string).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function default_blocked_message(): string {
			return __(
				'Этот пункт выдачи недоступен для вашего заказа. Пожалуйста, выберите другой пункт выдачи.',
				'woodev-plugin-framework'
			);
		}

		/**
		 * Message shown when the carrier authoritatively does not know the posted point.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function point_unavailable_message(): string {
			return __(
				'Выбранный пункт выдачи больше недоступен. Пожалуйста, выберите пункт выдачи заново.',
				'woodev-plugin-framework'
			);
		}

		/**
		 * Message shown when a merchant's outage filter blocks checkout instead of
		 * allowing it through.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function outage_blocked_message(): string {
			return __(
				'Не удалось проверить пункт выдачи. Пожалуйста, повторите оформление заказа через несколько минут.',
				'woodev-plugin-framework'
			);
		}

		/**
		 * Builds the REST route the browser fetches pickup points from.
		 *
		 * Mirrors
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller::register_routes()}'s
		 * own plugin-segment derivation exactly, so the emitted URL always matches the
		 * ACTUALLY registered route.
		 *
		 * Despite the key name, this is the points COLLECTION url (`GET .../points`), not
		 * a root the client resolves further sub-paths against generically — the mount
		 * task concatenates `/{id}` onto it for the single-point detail route
		 * (`GET .../points/{id}`, see {@see Pickup_Controller::register_routes()}).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function rest_root(): string {
			return rtrim( rest_url( 'woodev/v1' ), '/' ) . '/shipping/pickup/' . $this->plugin_segment() . '/points';
		}

		/**
		 * Sanitizes the plugin id into a route-safe segment, falling back to `shipping`.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function plugin_segment(): string {
			$segment = preg_replace( '/[^\w-]/', '', $this->plugin_id );

			return '' !== (string) $segment ? (string) $segment : 'shipping';
		}

		/**
		 * Returns a JS-identifier-safe version of the plugin id, used as the suffix in the
		 * `woodev_pickup_config_{suffix}` JS config global name.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function config_object_suffix(): string {
			return preg_replace( '/[^a-z0-9_]/i', '_', $this->plugin_id );
		}

		/**
		 * Resolves the filesystem path to a shipping-framework asset.
		 *
		 * Mirrors
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler}'s own asset-path
		 * helper. This file lives in `pickup/`, a direct child of the shipping-method root;
		 * `assets/` is ALSO a direct child of that root — a sibling of `pickup/`, not of
		 * this file's own directory.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory.
		 *
		 * @return string absolute filesystem path to the asset.
		 */
		private static function asset_path( string $relative ): string {
			return dirname( __DIR__ ) . '/assets/' . ltrim( $relative, '/' );
		}

		/**
		 * Resolves a URL within the shipping-framework assets directory.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory.
		 *
		 * @return string absolute URL to the asset.
		 */
		private static function asset_url( string $relative ): string {
			$file = self::asset_path( $relative );

			return plugins_url( basename( $file ), $file );
		}

		/**
		 * Reports whether an asset file exists on disk.
		 *
		 * `protected static`, not `private`, purely so a test subclass can force it `true`
		 * to exercise {@see self::enqueue_script_if_built()}/{@see self::enqueue_style_if_built()}'s
		 * "built" branch without writing a real file into the assets directory. The real
		 * (default) implementation is a plain `file_exists()` — each SP-5 asset reports
		 * `true`/`false` independently as its own task lands (`pickup-datasource.js` as of
		 * Task 11; `pickup-mount.js`, the map-provider script, and the stylesheet as of
		 * Tasks 12–15). The modal shell (`woodev-modal.js`) is no longer one of these checks —
		 * it moved to a framework-side registration (see {@see self::enqueue_assets()}'s own
		 * docblock) that this class does not gate. This is exactly the "skip a not-yet-built
		 * asset" behaviour {@see self::enqueue_assets()} exists to get right for what remains.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path absolute filesystem path to the asset.
		 *
		 * @return bool
		 */
		protected static function asset_exists( string $path ): bool {
			return file_exists( $path );
		}

		/**
		 * Resolves an asset's cache-busting version string.
		 *
		 * Uses the file's own mtime (so a later task's file gets a fresh version the
		 * moment it lands), falling back to {@see \Woodev_Plugin::VERSION} only as a
		 * defensive fallback for a file removed between
		 * {@see self::asset_exists()}'s check and this call — every real call site checks
		 * `asset_exists()` first, so the fallback branch is not expected to be reached in
		 * practice.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path absolute filesystem path to the asset.
		 *
		 * @return string
		 */
		private static function asset_version( string $path ): string {
			return file_exists( $path ) ? (string) filemtime( $path ) : (string) \Woodev_Plugin::VERSION;
		}
	}

endif;

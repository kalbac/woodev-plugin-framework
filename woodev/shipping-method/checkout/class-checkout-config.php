<?php
/**
 * Checkout Config.
 *
 * Builds a JS-safe config array from a {@see Checkout_Fields} set.
 * The emitted array contains only serialisable data — no PHP callables,
 * no raw source/sanitize/validate callbacks. Takeover conditions are
 * evaluated eagerly against the injected country list so the browser
 * receives a plain `{ country_code: bool }` map instead of a predicate.
 *
 * Location-provider layer (Task 9, 2026-08-12 plan; spec D1/D4/D15/§4.4): when a
 * {@see \Woodev\Framework\Shipping\Location\Location_Service} collaborator is
 * injected AND {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()},
 * {@see self::build()} adds ONE extra `location` block to this SAME config
 * object — there is exactly one config object and one enqueue path (spec
 * intent), the location block just rides inside it. The block is entirely
 * absent (no `location` key at all, not merely `null`) when no service is
 * injected or the layer is inactive, so an existing consumer that never heard
 * of the location layer sees no shape change. See {@see self::build_location_block()}
 * for the block's own shape and the D4/D15 non-leak guarantees.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping\Checkout
 */

namespace Woodev\Framework\Shipping\Checkout;

use Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Config' ) ) :

	/**
	 * Builds a JS-safe config array from a {@see Checkout_Fields} set.
	 *
	 * Shape of the returned array:
	 * ```
	 * [
	 *   'fields'            => [ field_id => [ id, type, section, source_kind, location_level, depends_on, required, is_pickup_slot ] ],
	 *   'endpoint'          => '{rest_base}/shipping/checkout/{plugin_id}/field-source',
	 *   'nonce'             => string,
	 *   'takeover'          => [ field_id => [ country_code => bool ] ],
	 *   // Checkout field policy (Task 6, issue #362, spec §4.3): effective values of the
	 *   // three classic-only/JS-driven settings — this config only PUBLISHES them,
	 *   // Checkout_Field_Policy (not this class) applies region/postcode/preset via
	 *   // woocommerce_get_country_locale + woocommerce_checkout_fields.
	 *   'field_policy'      => [
	 *     'address'  => string, // 'show' | 'hide_for_pickup'
	 *     'postcode' => string, // 'show' | 'hide_for_pickup' | 'remove'
	 *     'country'  => string, // 'show' | 'hide'
	 *   ],
	 *   'pickup_method_ids' => string[], // WC_Shipping_Method::$id of every pickup-shipping method.
	 *   // Present only when a Location_Service was injected AND is_active() (Task 9):
	 *   'location' => [
	 *     'endpoints' => [ 'suggest' => string, 'select' => string, 'list' => string ], // 'list' added Task 13
	 *     'nonce'     => string, // same wp_rest nonce as the top-level 'nonce' above
	 *     'countries' => string[],
	 *     // Two independent axes since issue #380 (each carries the same three
	 *     // values — 'typeahead' | 'related-list' | 'ajax-select2', spec D7):
	 *     'mode'      => [ 'region' => string, 'settlement' => string ],
	 *     'levels'    => [ country_code => [ 'region' => bool, 'settlement' => bool, 'address' => bool ] ],
	 *     'owners'    => [ country_code => [ 'region' => string, 'settlement' => string, 'address' => string ] ], // issue #352: provider id or '' — see build_location_block()'s own docblock
	 *     'current'   => [ 'key' => string, 'level' => string ]|null,
	 *     'chain'     => [ level => [ 'key' => string, 'level' => string ] ], // issue #330: every level in the customer's saved chain; [] when there is no customer record at all
	 *     'implicit'  => bool,
	 *     'defaultCountry' => string, // issue #296: checkout field -> WC store setting -> RU
	 *   ],
	 * ]
	 * ```
	 *
	 * Task 13 (issue #294) note on `location.levels[country].region`: `true` means
	 * this layer wants AND is free to serve that country's region field with a
	 * typeahead — free because WooCommerce has no registered `woocommerce_states`
	 * entry for it. `false` covers two different reasons the client must NOT try to
	 * tell apart from this flag alone (it does not need to): either no configured
	 * provider supports the level there, OR WooCommerce already renders a native
	 * `<select>` for it (its own list, a plugin's §8 carrier takeover, or THIS
	 * layer's own `related-list` mode injection) — in every `false` case the client
	 * must leave the field exactly as WooCommerce rendered it and never attach a
	 * typeahead. See {@see self::build_location_block()}'s own docblock for the full
	 * arbitration rule and the `related-list` region seam's exact value shape.
	 *
	 * @since 2.0.2
	 */
	class Checkout_Config {

		/**
		 * Plugin identifier used to build the REST endpoint.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $plugin_id;

		/**
		 * REST API base URL (no trailing slash).
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $rest_base;

		/**
		 * WP nonce for the field-source REST endpoint.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $nonce;

		/**
		 * Country codes to evaluate takeover conditions against.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private array $countries;

		/**
		 * The Location Provider layer's service façade, or `null` when this
		 * config was built without location-layer awareness (e.g. an older
		 * caller, or a unit test that does not care about it). `null` and "the
		 * service reports {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()}
		 * `false`" collapse to the exact same outcome — no `location` key in
		 * the built array — so a caller never needs to branch on which one it
		 * is.
		 *
		 * @since 2.0.2
		 * @var \Woodev\Framework\Shipping\Location\Location_Service|null
		 */
		private ?\Woodev\Framework\Shipping\Location\Location_Service $location_service;

		/**
		 * Task 6's «Поля» store-level settings handler (issue #362, spec §4.3), or `null`
		 * when this config was built without checkout-field-policy awareness (an older
		 * caller, or a unit test that does not care about it). `null` clamps every
		 * `field_policy` value to `'show'`; `pickup_method_ids()` never depends on this
		 * collaborator at all — see {@see self::build()}.
		 *
		 * @since 2.0.2
		 * @var Checkout_Field_Settings|null
		 */
		private ?Checkout_Field_Settings $field_settings;

		/**
		 * Constructor.
		 *
		 * Country codes are injected for testability; the real caller should
		 * pass `array_keys( WC()->countries->get_countries() )` — this class
		 * never calls `WC()` itself.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$location_service` collaborator
		 *              (location-provider layer Task 9).
		 * @since 2.0.2 Added the optional `$field_settings` collaborator (checkout
		 *              field policy Task 6, issue #362).
		 *
		 * @param string                                                    $plugin_id        Plugin identifier (used in REST endpoint path).
		 * @param string                                                    $rest_base        REST API base URL without a trailing slash.
		 * @param string                                                    $nonce            WP nonce for the field-source endpoint.
		 * @param string[]                                                  $countries        Country codes to evaluate takeover predicates against.
		 * @param \Woodev\Framework\Shipping\Location\Location_Service|null $location_service Location Provider layer façade; `null` omits the
		 *                                                                                        `location` block entirely.
		 * @param Checkout_Field_Settings|null                              $field_settings   The store-level «Поля» settings handler — always
		 *                                                                                        {@see \Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::get_field_settings()}
		 *                                                                                        at the real call site, never a fresh instance
		 *                                                                                        (its availability rules must not be computed
		 *                                                                                        twice with different answers); `null` clamps
		 *                                                                                        `field_policy` to all-`'show'`.
		 */
		public function __construct(
			string $plugin_id,
			string $rest_base,
			string $nonce,
			array $countries,
			?\Woodev\Framework\Shipping\Location\Location_Service $location_service = null,
			?Checkout_Field_Settings $field_settings = null
		) {
			$this->plugin_id        = $plugin_id;
			$this->rest_base        = rtrim( $rest_base, '/' );
			$this->nonce            = $nonce;
			$this->countries        = $countries;
			$this->location_service = $location_service;
			$this->field_settings   = $field_settings;
		}

		/**
		 * Builds the JS-safe config array from the given field set.
		 *
		 * Iterates all normalized field descriptors and emits only the safe
		 * subset of keys. Callable seams (`source`, `takeover_condition`,
		 * `sanitize_callback`, `validate_callback`) are stripped. For each
		 * field whose `takeover_condition` is a callable the method evaluates
		 * it against every country in {@see $countries} and stores the boolean
		 * result map under `$config['takeover'][$field_id]`.
		 *
		 * `pickup_slot_placements` (issue #274 item 3) is resolved only for a field
		 * with `is_pickup_slot === true` — every other field gets `[]` unconditionally,
		 * without ever invoking the `woodev_pickup_slot_placements` filter, mirroring
		 * how the `takeover` map above only ever gets an entry for a field whose
		 * `takeover_condition` is actually callable.
		 *
		 * A pickup-slot field's own `pickup_slot_placements` is `string[]|null`, never
		 * collapsed to `[]` for both of its two distinct meanings (issue #308 item 2 —
		 * adversarial review of #274): `null` is {@see self::resolve_pickup_slot_placements()}
		 * reporting a MALFORMED filter return (the browser applies its own mixed-fleet
		 * safety net, exactly as it already does for a field whose config predates this
		 * key entirely); `[]` is that method reporting a well-formed, EXPLICITLY empty
		 * filter return — a plugin deliberately owning both triggers itself — and must
		 * reach the browser as a real empty array, not silently upgraded to `null`.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added `pickup_slot_placements` (issue #274 item 3).
		 * @since 2.0.2 `pickup_slot_placements` is now `string[]|null` for a pickup-slot
		 *              field, so a malformed filter return and a deliberate `[]` no longer
		 *              collapse to the same value (issue #308 item 2).
		 * @since 2.0.2 Added `field_policy` and `pickup_method_ids` (checkout field policy
		 *              Task 6, issue #362).
		 *
		 * @param Checkout_Fields $fields Normalized field definitions to emit.
		 *
		 * @return array{
		 *     fields: array<string, array{
		 *         id: string,
		 *         type: string,
		 *         section: string,
		 *         source_kind: string|null,
		 *         location_level: string|null,
		 *         depends_on: string|null,
		 *         required: bool|array<string, mixed>,
		 *         is_pickup_slot: bool,
		 *         pickup_slot_placements: string[]|null
		 *     }>,
		 *     endpoint: string,
		 *     nonce: string,
		 *     takeover: array<string, array<string, bool>>,
		 *     field_policy: array{address: string, postcode: string, country: string},
		 *     pickup_method_ids: string[],
		 *     location?: array{
		 *         endpoints: array{suggest: string, select: string},
		 *         nonce: string,
		 *         countries: string[],
		 *         mode: array{region: string, settlement: string},
		 *         levels: array<string, array{region: bool, settlement: bool, address: bool}>,
		 *         owners: array<string, array{region: string, settlement: string, address: string}>,
		 *         current: array{key: string, level: string}|null,
		 *         chain: array<string, array{key: string, level: string}>,
		 *         implicit: bool
		 *     }
		 * }
		 */
		public function build( Checkout_Fields $fields ): array {
			$out_fields = [];
			$takeover   = [];

			foreach ( $fields->get_fields() as $id => $def ) {
				$out_fields[ $id ] = [
					'id'                     => $def['id'],
					'type'                   => $def['type'],
					'section'                => $def['section'],
					'source_kind'            => $def['source_kind'],
					'location_level'         => $def['location_level'] ?? null,
					'depends_on'             => $def['depends_on'],
					'required'               => $def['required'],
					'is_pickup_slot'         => $def['is_pickup_slot'],
					'pickup_slot_placements' => $def['is_pickup_slot']
						? $this->resolve_pickup_slot_placements( $id )
						: [],
				];

				$condition = $def['takeover_condition'] ?? null;
				if ( is_callable( $condition ) ) {
					$map = [];
					foreach ( $this->countries as $code ) {
						$map[ $code ] = (bool) $condition( [ 'country' => $code ] );
					}
					$takeover[ $id ] = $map;
				}
			}

			$config = [
				'fields'            => $out_fields,
				'endpoint'          => $this->rest_base . '/shipping/checkout/' . $this->plugin_id . '/field-source',
				'nonce'             => $this->nonce,
				'takeover'          => $takeover,
				'field_policy'      => $this->build_field_policy(),
				'pickup_method_ids' => self::pickup_method_ids(),
			];

			if ( null !== $this->location_service && $this->location_service->is_active() ) {
				$config['location'] = $this->build_location_block( $this->location_service );
			}

			return $config;
		}

		/**
		 * Builds the `field_policy` block (Task 6, issue #362, spec §4.3): the effective
		 * values of the three settings that stay classic-only/JS-driven
		 * (`address_field`, `postcode_field`, `country_field` — Task 9 acts on them in
		 * `checkout-field-classic.js`). This method only PUBLISHES the values; it never
		 * acts on them in PHP (T2 — that stays this method's whole job, the actual
		 * hiding/removal instruments live in {@see Checkout_Field_Policy}).
		 *
		 * `null` {@see self::$field_settings} (no policy handler was injected) clamps
		 * every value to `'show'` — the same fallback {@see Checkout_Field_Settings::effective()}
		 * itself uses for a stored value that names an option not currently offered.
		 *
		 * @since 2.0.2
		 *
		 * @return array{address: string, postcode: string, country: string}
		 */
		private function build_field_policy(): array {
			if ( null === $this->field_settings ) {
				return [
					'address'  => 'show',
					'postcode' => 'show',
					'country'  => 'show',
				];
			}

			return [
				'address'  => $this->field_settings->effective( 'address_field' ),
				'postcode' => $this->field_settings->effective( 'postcode_field' ),
				'country'  => $this->field_settings->effective( 'country_field' ),
			];
		}

		/**
		 * The WooCommerce shipping method ids (issue #362, spec §4.3) whose
		 * {@see \Woodev\Framework\Shipping\Shipping_Method::is_pickup_shipping()} is
		 * `true` — published so `checkout-field-classic.js` can match the customer's
		 * CHOSEN method against them the exact same way
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::chosen_method_matches()}
		 * already does server-side: `$chosen === $id || 0 === strpos( $chosen, $id . ':' )`.
		 * That is why this returns the plain method id — `WC_Shipping_Method::$id`, no
		 * zone-instance suffix — never a per-zone rate id like `pickup:3`.
		 *
		 * Guarded for the unit suite (no `WC()` there): returns `[]` when WooCommerce's
		 * shipping subsystem is unavailable, exactly like {@see self::wc_states()}
		 * degrades for the same reason.
		 *
		 * `public static` (2.0.2, issue #362 pickup-required-relaxation fix): touches no
		 * `$this` — pure indirection over `WC()` — so
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy::pickup_method_chosen()}
		 * reuses this SAME list server-side rather than re-deriving it, keeping exactly one
		 * place that decides which WooCommerce method ids count as pickup.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public static function pickup_method_ids(): array {
			if ( ! function_exists( 'WC' ) || ! method_exists( WC(), 'shipping' ) || ! WC()->shipping() ) {
				return [];
			}

			$ids = [];

			foreach ( WC()->shipping()->get_shipping_methods() as $method ) {
				if ( $method instanceof \Woodev\Framework\Shipping\Shipping_Method && $method->is_pickup_shipping() ) {
					$ids[] = $method->get_id();
				}
			}

			return array_values( array_unique( $ids ) );
		}

		/**
		 * Resolves which DOM anchors a pickup-slot field's checkout trigger mounts
		 * into (issue #274 item 3; default narrowed to ONE place by issue #323).
		 *
		 * The framework's default is `'rate'` alone — the slot goes INSIDE the
		 * SELECTED rate's own `<li>`, under its label, mirroring
		 * `woocommerce_after_shipping_rate`. The other placement, `'review'` (after
		 * the shipping-methods list — the ORIGINAL placement, measured to already sit
		 * exactly where WooCommerce's own `woocommerce_review_order_after_shipping`
		 * action would render in the classic checkout template), stays reachable
		 * through the filter below but is no longer a default.
		 *
		 * #274 item 3 had defaulted to BOTH at once, reading the Yandex reference's
		 * two `add_action` calls as "the button is drawn in two places". Measuring that
		 * reference says otherwise: it has ONE position out of two, picked by a store
		 * SETTING (`widget_position`, default `under_methods`), and each hook returns
		 * immediately when the setting does not name it. Почта РФ has the same shape
		 * under `map_button_place`. Rendering both at once put two identical buttons a
		 * few pixels apart in front of the customer (issue #323).
		 *
		 * WHERE the trigger is drawn is a FRAMEWORK decision, not a carrier's: a store
		 * running both СДЭК and Почта must not get their buttons in different places.
		 * What the carrier plugin owns is the button's TEXT, through the existing
		 * `woodev_pickup_map_i18n` filter's `trigger` key.
		 *
		 * Precedence: the framework's `'rate'`-alone default → the store's own
		 * `pickup_button_placement` setting (Task 8, issue #362, design S7 — one of the
		 * three map behaviours a customer sees across every carrier at once, so it is a
		 * STORE decision, never a carrier's) → the `woodev_pickup_slot_placements`
		 * filter LAST. {@see Pickup_Map_Settings::current()}'s stored value is clamped
		 * to `'rate'`/`'review'` on READ (design §7 — never rewrite the stored option);
		 * an unrecognised value (a stale constant, a value from a future settings
		 * version this code does not know about yet) falls back to the framework's own
		 * `'rate'` default rather than reaching the filter as-is. The filter is kept,
		 * per this framework's own rule for extension hooks (a hook is never withheld
		 * for lack of a caller today), and it is also what keeps "both places at once"
		 * available to a store that genuinely wants it without spending a third option
		 * in a UI. Only `'review'` and `'rate'` ever reach the browser, in that order,
		 * each at most once — an array containing unrecognised values (a typo, a stale
		 * constant) is filtered down to whichever of the two it actually names, same as
		 * ever.
		 *
		 * A non-array return is a DIFFERENT failure than a well-formed, empty one
		 * (issue #308 item 2 — adversarial review of #274 item 3): it means the filter
		 * itself is malformed — nobody made a decision the framework can trust — and is
		 * reported as `null`, never `[]`. `[]` is reserved for a filter that returned a
		 * real (possibly empty, e.g. `[]` itself, or an array of only unrecognised
		 * values) array: THAT is a plugin deliberately telling the framework it wants
		 * neither placement, and must reach the browser as a genuine empty array, not
		 * silently folded into the same value a malformed return produces — the two
		 * mean opposite things at the browser: `null` falls back to the framework's own
		 * mixed-fleet default, `[]` suppresses both triggers outright — and is the one
		 * case that gets no fallback anchor either (`placeSlots()` in
		 * `checkout-field-classic.js`).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Returns `null`, not `[]`, for a non-array filter return, so the
		 *              browser can tell "malformed" from "deliberately empty" apart
		 *              (issue #308 item 2).
		 * @since 2.0.2 Defaults to `[ 'rate' ]` alone, never both placements at once
		 *              (issue #323).
		 * @since 2.0.2 The filter's default now runs through the store's own
		 *              `pickup_button_placement` setting before reaching the filter
		 *              (Task 8, issue #362, design S7).
		 *
		 * @param string $field_id the pickup-slot field id.
		 *
		 * @return string[]|null Zero, one, or both of `'review'`, `'rate'`; `null` when the
		 *                       filter itself returned something other than an array.
		 */
		private function resolve_pickup_slot_placements( string $field_id ): ?array {
			$stored_placement = (string) Pickup_Map_Settings::current()->get_value( 'pickup_button_placement' );
			$default          = in_array( $stored_placement, [ 'rate', 'review' ], true ) ? [ $stored_placement ] : [ 'rate' ];

			/**
			 * Filters which anchors a pickup-slot field's checkout trigger mounts into.
			 *
			 * @since 2.0.2
			 *
			 * @param string[] $placements the framework's default, after the store's own
			 *                             `pickup_button_placement` setting (Task 8) has
			 *                             already been applied — `'rate'` alone unless the
			 *                             store chose `'review'`.
			 * @param string   $field_id   the pickup-slot field id.
			 * @param string   $plugin_id  the owning plugin id.
			 */
			$placements = apply_filters(
				'woodev_pickup_slot_placements',
				$default,
				$field_id,
				$this->plugin_id
			);

			if ( ! is_array( $placements ) ) {
				return null;
			}

			return array_values( array_intersect( [ 'review', 'rate' ], $placements ) );
		}

		/**
		 * Builds the location-provider config block (Task 9; spec D1, D4, D15, §4.4).
		 *
		 * Only ever called once {@see self::build()} has already confirmed
		 * {@see \Woodev\Framework\Shipping\Location\Location_Service::is_active()}, so
		 * every read here is against an active, configured provider.
		 *
		 * - `endpoints` — the FLEET-WIDE `woodev/v1/location/(suggest|select)` routes
		 *   (Task 8's {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}),
		 *   never namespaced by `plugin_id` — there is exactly one active location
		 *   provider per store.
		 * - `nonce` — deliberately the SAME `wp_rest` cookie nonce as the top-level
		 *   `$config['nonce']` (both come from one `wp_create_nonce( 'wp_rest' )` call
		 *   at the caller), because that is the exact nonce
		 *   {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::check_select_permission()}
		 *   verifies — minting a second, differently-named nonce for the same action
		 *   would just be two names for one value.
		 * - `countries` — the intersection of the injected WC selling-country list
		 *   ({@see $countries}) and {@see \Woodev\Framework\Shipping\Location\Location_Service::get_supported_countries()}:
		 *   the UNION across the whole D15 chain (D15 gate fix, block PR-B) — a
		 *   country only the FALLBACK covers at some level must still surface here,
		 *   not just what the ACTIVE provider alone lists. A country the store does
		 *   not even sell to is not useful information for the client-side D2
		 *   arbitration this list feeds, hence the intersection with the WC list.
		 * - `mode` — the store's TWO field-presentation axes (issue #380 split
		 *   the single legacy setting: "each axis carries the same three
		 *   values"), `{ region, settlement }`, read from
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Service::get_field_mode_region()}
		 *   and {@see \Woodev\Framework\Shipping\Location\Location_Service::get_field_mode_settlement()}
		 *   — each one of `typeahead` / `related-list` / `ajax-select2`,
		 *   already clamped against the active provider's own capabilities (a
		 *   mode the provider cannot serve is never returned, regardless of
		 *   what the store option literally holds) — `region` is ALSO clamped
		 *   to `typeahead` once `region_field` is removed (issue #369
		 *   closure; see `get_field_mode_region()`'s own docblock).
		 * - `levels` — a MAP, `{ [country]: { region, settlement, address } }`, one
		 *   entry per country in `countries` above (D15 amendment follow-up:
		 *   per-country suggest levels — DaData genuinely serves `address` in
		 *   RU/BY/KZ/UZ but not in AM/AZ/KG/TJ/TM, so a single flat per-level
		 *   answer is no longer honest across every country the layer covers).
		 *   Shipped as a full map rather than the current country's answer alone
		 *   because this config is emitted ONCE per page render (`wp_localize_script`,
		 *   no round-trip on a plain country-field change) — the client's D2/D15
		 *   country-switch handling (Task 11) needs to re-evaluate per-level
		 *   support the instant the customer picks a different country, and a
		 *   single-country answer would go stale at that exact moment with no way
		 *   to refresh it. Each per-country entry answers whether the D15
		 *   provider-fallback chain
		 *   ({@see \Woodev\Framework\Shipping\Location\Location_Service::get_levels_for_country()})
		 *   resolves ANY provider for that level IN that country. This is the
		 *   ONLY thing `levels` answers — WHICH provider serves a level is
		 *   `owners`' job, below (spec D15's original single-answer intent, kept
		 *   for this key specifically) — because neither this method nor
		 *   `get_levels_for_country()` ever reads
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Provider::get_id()}.
		 * - `owners` — a MAP with the SAME shape as `levels`
		 *   (`{ [country]: { region, settlement, address } }`), but each leaf is
		 *   the id of the provider the D15 chain resolves for that (level,
		 *   country) rather than a bool — `''` (never `null`) when no provider
		 *   serves it there, from
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Service::get_level_owners_for_country()}.
		 *   Issue #352: this is spec D15's one deliberate, documented exception —
		 *   nothing NEW leaks by publishing it, because every persisted
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Record::to_array()}
		 *   already carries `provider_id`, and `key()` is literally
		 *   `provider_id:native_id`; what changes is that the CLIENT can now act
		 *   on ownership BEFORE posting a record, not only after. A store running
		 *   a mixed provider chain (e.g. the active provider serving
		 *   `region`/`settlement`, the bundled fallback serving `address`) can
		 *   never prove cross-provider kinship for
		 *   {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::rebuild_chain()}'s
		 *   `is_within()` check (issue #334, deliberately UNCHANGED — a Moscow
		 *   settlement must not survive a Saint-Petersburg address, and that rule
		 *   cannot distinguish "different provider" from "different place"); a
		 *   client that posted a foreign-provider address anyway would silently
		 *   amputate every shallower level of the customer's chain the instant the
		 *   server applied that rule. `owners` lets `location-cascade.js`'s
		 *   `mayEnterChain()` refuse to post a foreign-owned pick in the first
		 *   place, keeping the address as local field TEXT only (Variant A). `owners[c][l] === ''`
		 *   EXACTLY when `levels[c][l] === false`, for the same country and
		 *   level — including the `region` #294 arbitration below, which this
		 *   method applies to BOTH maps together so they can never disagree.
		 *
		 *   **`levels[country]['region']` — issue #294 arbitration (Task 13).**
		 *   The D15 chain's own opinion ("some provider could suggest a region")
		 *   is NOT the final answer for `region` the way it is for `settlement`/
		 *   `address`: WooCommerce owns the region concept as soon as ANY
		 *   `woocommerce_states` entry exists for that country. This method reads
		 *   `WC()->countries->get_states( $code )` (through {@see self::wc_states()},
		 *   a thin overridable seam — see that method's own docblock for why it
		 *   is not an inline `WC()` call) — deliberately HERE, AFTER every
		 *   `woocommerce_states` filter has already run (WooCommerce's own
		 *   native list, a plugin's §8 carrier takeover via
		 *   {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::inject_states()},
		 *   and this layer's OWN `related-list` mode injector,
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::inject_related_list_states()}
		 *   — one place, one source of truth, per the decided rule) — and reports
		 *   `region` as OURS (`true`) only when the chain wants it AND the country
		 *   has NO registered states at all. A non-empty state list means
		 *   WooCommerce renders a native `<select>` there regardless of what this
		 *   layer wants, so pretending a typeahead still belongs on that field is
		 *   exactly the drift the card that decided this found (never write an
		 *   empty array into `woocommerce_states` either — gotcha
		 *   `checkout-field-takeover-woocommerce-states` — this method only READS
		 *   the filter's result, it never writes to it).
		 *
		 *   A non-empty state list the D15 chain wanted `region` for, that this
		 *   layer did NOT inject itself
		 *   ({@see \Woodev\Framework\Shipping\Location\Location_Service::owns_region_states()}
		 *   false), is a genuine conflict — some OTHER source (native WC, or a
		 *   plugin's own §8 takeover) already owns that country's regions. This
		 *   is reported via `_doing_it_wrong()`, ONCE per config build (not once
		 *   per conflicting country), mirroring `inject_states()`'s own
		 *   conflict-observability mechanism. A `related-list`-mode injection is
		 *   never a conflict with itself: `owns_region_states()` distinguishes
		 *   "we put these states there" from "someone else did", so the warning
		 *   never fires for the layer's own intended rendering.
		 * - `current`/`implicit` — from
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Service::get_customer_record()}.
		 *   `current`'s shape (`{ key, level }`) is deliberately byte-for-byte the
		 *   same as what
		 *   {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::handle_select_request()}
		 *   returns under its own `current` key (Task 8), so the client can update
		 *   from either response without the two ever disagreeing. `implicit` is
		 *   `false` when there is no record at all — an implicit flag is only
		 *   meaningful attached to an actual record.
		 * - `chain` (location-chain design §8; issue #330;
		 *   `docs-internal/specs/2026-08-15-location-chain-design.md`) — every
		 *   level in the customer's saved chain, from
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Service::get_customer_chain()},
		 *   in the SAME per-entry `{ key, level }` shape as `current`, keyed by
		 *   level — byte-for-byte the same shape
		 *   {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::handle_select_request()}
		 *   returns under its own `chain` key, so `location-cascade.js`'s
		 *   `prefill()` can seed `entry.records[ level ]` for every level from
		 *   either response without the two ever disagreeing. A PHP `[]` (never
		 *   `null`, never omitted) when there is no customer record at all — an
		 *   empty array serializes to a JSON `[]`, which the client's own
		 *   `'object' !== typeof chain` guard already treats as "nothing to
		 *   seed" and skips, so the harmless PHP/JS array-vs-object mismatch at
		 *   the empty case costs nothing.
		 *
		 * **`related-list` region seam (Task 13; client-facing contract for the
		 * next agent — CORRECTED after the s71 rig measurement AND the PR #304
		 * review, see below):** when `mode.region === 'related-list'` (issue
		 * #380 — the REGION axis specifically, independent of `mode.settlement`),
		 * the region `<select>` WooCommerce renders is populated by
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::inject_related_list_states()}
		 * with `wc_strtoupper( label ) => label` pairs — the `<option>` VALUE is
		 * the record's own {@see \Woodev\Framework\Shipping\Location\Location_Record::label()}
		 * UPPERCASED (via WooCommerce's own `wc_strtoupper()`, Cyrillic-aware),
		 * the `<option>` TEXT is the human-readable label unchanged, and NEITHER
		 * is ever the record's `key()` (`provider_id:native_id`). The VALUE is
		 * what the customer's browser submits as `billing_state`/`shipping_state`
		 * and it persists PERMANENTLY into order data (a release-blocking
		 * installed-site data contract, per this project's backward-compatibility
		 * policy); a provider-namespaced key stored there renders as raw,
		 * meaningless text the instant this injector stops running — after a
		 * provider switch (the key's namespace changes), after the store mode is
		 * set back to `typeahead`, or after the plugin is deactivated. Measured
		 * on the rig (s71): WITHOUT the injector attached, a stored
		 * `dadata:0c089b04-…` key rendered verbatim inside
		 * `WC_Countries::get_formatted_address()`'s output instead of the region
		 * name. The VALUE is uppercased — not the bare label — because
		 * `WC_Checkout::validate_posted_data()` uppercases whatever the customer
		 * posted before matching it against the registered keys; a mixed-case
		 * key used bare would get shouted into an uppercase STORED value that no
		 * longer equals its own option's key, so the field would silently revert
		 * to the placeholder on the next render (PR #304 review finding 2,
		 * measured on the rig: posting `Московская область` stored `МОСКОВСКАЯ
		 * ОБЛАСТЬ`, which the mixed-case key could never match again).
		 *
		 * A client-side related-list handler does NOT reconstruct a
		 * `Location_Record` from the selected `<option>` alone — a label is not a
		 * full record. Instead it takes the option's selected TEXT (the human
		 * label — NOT its `value`, which is uppercased) and looks it up in the
		 * SAME country's response from the `list` endpoint above (`GET
		 * .../location/list?level=region&country={code}`, already fetched or
		 * fetchable via this block's `endpoints.list`): each entry there carries
		 * `{ key, label, level, record }` where `record` is the UNTOUCHED
		 * {@see \Woodev\Framework\Shipping\Location\Location_Record::to_array()}
		 * payload — match the selected label against that entry's `record.label`
		 * (the raw, exact label, not the top-level `label` field, which is
		 * `esc_html()`-escaped for direct display and must not be used for
		 * equality matching), then POST the matched entry's `record` verbatim to
		 * the SAME `/location/select` endpoint every other level already uses —
		 * no second lookup beyond that one `/location/list` call, matching the
		 * backwards-fill discipline Task 11 already established elsewhere in this
		 * layer. Two records that legitimately share one label within a country
		 * are pre-resolved server-side: `inject_related_list_states()` keeps only
		 * the first and reports the rest via `_doing_it_wrong()`, so the label
		 * the client sees is already unique — `record.label` uniquely identifies
		 * one entry in that same country's `/location/list` response.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 `levels` changed from a single flat per-level map to a
		 *              per-country map (D15 amendment follow-up).
		 * @since 2.0.2 `mode` reads the real store setting instead of a hardcoded
		 *              constant, `levels[country]['region']` is additionally
		 *              gated against WooCommerce's own registered states, and
		 *              `endpoints` gained `list` (Task 13; issue #294).
		 * @since 2.0.2 `related-list` region seam corrected: the injected WC
		 *              state VALUE is the record's `label()`, not its `key()` —
		 *              a `billing_state`/`shipping_state` value is permanent
		 *              order data (rig measurement, s71).
		 * @since 2.0.2 `related-list` region seam corrected again: the injected
		 *              WC state KEY is now `wc_strtoupper( trim( label ) )`
		 *              rather than the bare label, the ownership check
		 *              ({@see \Woodev\Framework\Shipping\Location\Location_Service::owns_region_states()})
		 *              now re-confirms against the FINAL registered states, and
		 *              `wc_states()`'s cast no longer turns a `false`
		 *              `get_states()` result into a non-empty array (PR #304
		 *              review findings 1-4).
		 * @since 2.0.2 `i18n` gained `notPersisted` — the client-side consumer for an honest
		 *              `persisted: false` `/select` response (Task 13; issue #295 finding 1).
		 *
		 * @since 2.0.2 Gained `defaultCountry` -- steps 2+3 of the "checkout field -> WC store
		 *              setting -> RU" fallback chain (issue #296), via
		 *              {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_default_country()}.
		 * @since 2.0.2 Gained `chain` — every level in the customer's saved chain
		 *              (location-chain design §8; issue #330), via
		 *              {@see \Woodev\Framework\Shipping\Location\Location_Service::get_customer_chain()}.
		 * @since 2.0.2 Gained `owners` — issue #352's mixed-provider-chain fix
		 *              (Variant A): per-level provider ownership, so the client
		 *              can refuse to post a foreign-provider record into the
		 *              server-side chain, via
		 *              {@see \Woodev\Framework\Shipping\Location\Location_Service::get_level_owners_for_country()}.
		 * @since 2.0.2 `mode` is now `{ region, settlement }` — TWO independent
		 *              axes, issue #380 — instead of one shared mode string.
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Service $service The active, already-confirmed facade.
		 *
		 * @return array{
		 *     endpoints: array{suggest: string, select: string, list: string},
		 *     nonce: string,
		 *     countries: string[],
		 *     mode: array{region: string, settlement: string},
		 *     levels: array<string, array{region: bool, settlement: bool, address: bool}>,
		 *     owners: array<string, array{region: string, settlement: string, address: string}>,
		 *     current: array{key: string, level: string}|null,
		 *     chain: array<string, array{key: string, level: string}>,
		 *     implicit: bool,
		 *     defaultCountry: string
		 * }
		 */
		private function build_location_block( \Woodev\Framework\Shipping\Location\Location_Service $service ): array {

			$supported_countries = $service->get_supported_countries();

			$countries = [];
			foreach ( $this->countries as $code ) {
				if ( in_array( $code, $supported_countries, true ) ) {
					$countries[] = $code;
				}
			}

			$levels                    = [];
			$owners                    = [];
			$region_conflict_countries = [];

			foreach ( $countries as $code ) {
				$country_levels = $service->get_levels_for_country( $code );
				$country_owners = $service->get_level_owners_for_country( $code );

				// #294 arbitration: the authority is the FINAL woocommerce_states
				// result, read AFTER every filter (native WC, §8 carrier takeover,
				// this layer's own related-list injector) has already run — see this
				// method's own docblock for the full rule, and {@see self::wc_states()}
				// for why that read goes through an overridable seam rather than a bare
				// WC() call inline here.
				$final_states   = $this->wc_states( $code );
				$states_present = [] !== $final_states;

				if ( $country_levels['region'] && $states_present && ! $service->owns_region_states( $code, $final_states ) ) {
					$region_conflict_countries[] = $code;
				}

				$country_levels['region'] = $country_levels['region'] && ! $states_present;

				// `owners` MUST honour the SAME final answer as `levels` (issue #352):
				// a provider that technically resolves for "region" is not the owner
				// of a field the #294 arbitration just stood this layer down from —
				// otherwise the client could see `levels[c].region === false` (native
				// WC select) alongside a non-empty `owners[c].region` (a typeahead
				// owner) for the same level, which is incoherent.
				if ( ! $country_levels['region'] ) {
					$country_owners['region'] = '';
				}

				$levels[ $code ] = $country_levels;
				$owners[ $code ] = $country_owners;
			}

			if ( [] !== $region_conflict_countries ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						'the location layer wanted to serve the "region" level via typeahead for %s, but WooCommerce already has native/injected states registered for those countries (see the woocommerce_states filter — issue #294); the layer stands down and reports "region" as not its own for them.',
						implode( ', ', $region_conflict_countries )
					),
					'2.0.2'
				);
			}

			$customer = $service->get_customer_record();
			$current  = null;
			$implicit = false;

			if ( null !== $customer ) {
				$current  = [
					'key'   => $customer['record']->key(),
					'level' => $customer['record']->level(),
				];
				$implicit = (bool) $customer['implicit'];
			}

			// Issue #330 (location-chain design §8): every level in the customer's
			// saved chain, same `{ key, level }` shape as `current` above, keyed by
			// level. `[]` (never `null`) when there is no customer record at all —
			// see this method's own docblock for why an empty array is the chosen,
			// harmless shape on the JS side.
			$chain          = [];
			$customer_chain = $service->get_customer_chain();

			if ( null !== $customer_chain ) {
				foreach ( $customer_chain['records'] as $chain_level => $chain_record ) {
					$chain[ $chain_level ] = [
						'key'   => $chain_record->key(),
						'level' => $chain_record->level(),
					];
				}
			}

			/**
			 * Filters the location typeahead's user-facing strings.
			 *
			 * Mirrors `woodev_pickup_map_i18n` (see
			 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::get_js_config()}) — the
			 * same reason applies here: these strings reach the customer, so a plugin whose
			 * carrier calls a locality something else must be able to say so without
			 * translating the framework.
			 *
			 * @since 2.1.0
			 *
			 * @param array<string, string> $strings The framework's default strings.
			 */
			$strings = apply_filters(
				'woodev_location_i18n',
				[
					// Shown INSIDE the open listbox when a completed search returned nothing.
					// A silent empty panel and a slow network are indistinguishable to the
					// customer, so this one case is worth a sentence (operator, s70).
					'noResults'        => __(
						'Поиск не дал результатов. Попробуйте изменить запрос.',
						'woodev-plugin-framework'
					),

					// The ADDRESS level gets its own wording (operator, s70). "Nothing found"
					// under a street field reads as "you cannot be delivered to" — and a
					// street genuinely absent from the provider's registry is the ordinary
					// case there, not an error. This says the field still works, which is
					// true: a location field is a plain text input with the typeahead layered
					// on top, so a hand-typed address was always accepted.
					'noResultsAddress' => __(
						'Адрес не найден — введите вручную.',
						'woodev-plugin-framework'
					),

					// #295 finding 1 (Task 13): `POST /location/select` answers
					// `{persisted: false}` when the write failed server-side (typically a guest
					// whose WooCommerce session/cart cookie has not initialized yet — gotcha
					// `guest-session-write-needs-the-cart-cookie`) — an honest signal the client
					// used to read for exactly one thing (not firing `update_checkout`) and
					// otherwise discard. `location-cascade.js`'s `showNotPersistedNotice()` is
					// the consumer this string exists for.
					'notPersisted'     => __(
						'Не удалось сохранить выбор — попробуйте ещё раз.',
						'woodev-plugin-framework'
					),

					// Issue #405: shown INSIDE the open listbox when a search could not be
					// COMPLETED at all (the provider's own request failed — wrong keys, a
					// network failure, a malformed upstream payload), as opposed to `noResults`/
					// `noResultsAddress` above, which mean "the search ran and genuinely found
					// nothing". `location-typeahead.js`'s own `errorText` docblock and
					// `Location_Provider::suggest()`'s "EMPTY VS. FAILED" docblock section are
					// the two ends of this same contract — a REST 502 from `/location/suggest`
					// is what actually triggers this string.
					'unavailable'      => __(
						'Источник подсказок недоступен. Попробуйте ещё раз позже или введите вручную.',
						'woodev-plugin-framework'
					),
				]
			);

			return [
				'endpoints'      => [
					'suggest' => $this->rest_base . '/location/suggest',
					'select'  => $this->rest_base . '/location/select',
					'list'    => $this->rest_base . '/location/list',
				],
				'nonce'          => $this->nonce,
				'countries'      => array_values( $countries ),
				// Issue #380: two independent axes — see build_location_block()'s own
				// docblock — instead of one shared mode string.
				'mode'           => [
					'region'     => $service->get_field_mode_region(),
					'settlement' => $service->get_field_mode_settlement(),
				],
				'levels'         => $levels,
				'owners'         => $owners,
				'current'        => $current,
				'chain'          => $chain,
				'implicit'       => $implicit,
				// Issue #296: steps 2+3 of the country fallback chain `checkout field ->
				// WooCommerce store setting -> RU`, already merged into ONE value by
				// {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_default_country()}
				// — the client's own `location-cascade.js::countryFor()` reads step 1 (the live
				// DOM field) itself and falls back to THIS value only when that field is absent
				// or empty (a single-country store that dropped the country field entirely used
				// to leave the whole location layer silently dead — no widget ever attached,
				// because the client had no country to arbitrate by).
				'defaultCountry' => $service->resolve_default_country(),
				'i18n'           => array_map( 'strval', (array) $strings ),
			];
		}

		/**
		 * Reads WooCommerce's FINAL registered states for one country — the
		 * issue #294 arbitration's own single source of truth (see
		 * {@see self::build_location_block()}'s own docblock for the full rule).
		 *
		 * A `protected`, overridable seam rather than an inline `WC()` call for
		 * a specific, measured reason: Brain Monkey (`Functions\when( 'WC' )`)
		 * instruments the global `WC()` function for the REST OF THE PHP
		 * PROCESS once any single test stubs it — every OTHER unit test in the
		 * suite that relies on `function_exists( 'WC' ) === false` (the
		 * established "no WooCommerce loaded in unit tests" idiom this
		 * codebase's other classes already use, e.g.
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::wc_country_codes()})
		 * would start seeing `WC()` DEFINED-BUT-UNMOCKED and fail with Brain
		 * Monkey's own `MissingFunctionExpectations` — measured directly: a
		 * first version of this method called `WC()` inline and a single
		 * stubbing test in `CheckoutConfigTest` broke 21 unrelated tests
		 * elsewhere in the full suite the moment `composer test:unit` ran all
		 * files in one process. A protected method a TEST SUBCLASS overrides
		 * (the same "Probe subclass" discipline `Checkout_Handler::current_country()`
		 * already establishes for this exact class of problem) sidesteps Brain
		 * Monkey's global function table entirely.
		 *
		 * Degrades to `[]` (never fatals) when `WC()`/`WC()->countries` is
		 * unavailable — the same "no states known, trust the D15 chain's own
		 * answer unchanged" degradation the pre-Task-13 code implicitly had. Also
		 * degrades to `[]` (via `array_filter()`) when `get_states()` itself
		 * answers `false` for an absent country key — see this method's own body
		 * comment (PR #304 review finding 1).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Wrapped the cast in `array_filter()` — a bare `(array) false`
		 *              is `[ 0 => false ]`, non-empty (PR #304 review finding 1).
		 *
		 * @param string $country ISO-3166 alpha-2 country code.
		 *
		 * @return array<string, string> WC state code => label, or `[]` when
		 *                                WooCommerce has none registered (or is
		 *                                unavailable).
		 */
		protected function wc_states( string $country ): array {
			if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->countries ) ) {
				return [];
			}

			// `WC_Countries::get_states()` returns `false` — not `[]` — when the
			// country key is absent (`includes/class-wc-countries.php`), and
			// `(array) false === [ 0 => false ]`, which is NON-empty: a bare cast
			// here made `$states_present` true for every country with no states at
			// all, which in the DEFAULT configuration (typeahead mode, no §8
			// takeover) meant the region typeahead never attached anywhere and
			// `_doing_it_wrong()` fired on every checkout render (PR #304 review
			// finding 1, measured on the rig: `get_states( 'RU' )` -> `false`,
			// `(array) false` -> `[ 0 => false ]`). `array_filter()` drops that
			// falsy entry, mirroring WooCommerce's own precedent for this exact
			// trap: `StoreApi\Utilities\ValidationUtils::format_state()` reads
			// `array_filter( (array) wc()->countries->get_states( $country ) )`.
			return array_filter( (array) WC()->countries->get_states( $country ) );
		}
	}

endif;

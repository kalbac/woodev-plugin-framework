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
	 *   'fields'   => [ field_id => [ id, type, section, source_kind, location_level, depends_on, required, is_pickup_slot ] ],
	 *   'endpoint' => '{rest_base}/shipping/checkout/{plugin_id}/field-source',
	 *   'nonce'    => string,
	 *   'takeover' => [ field_id => [ country_code => bool ] ],
	 *   // Present only when a Location_Service was injected AND is_active() (Task 9):
	 *   'location' => [
	 *     'endpoints' => [ 'suggest' => string, 'select' => string, 'list' => string ], // 'list' added Task 13
	 *     'nonce'     => string, // same wp_rest nonce as the top-level 'nonce' above
	 *     'countries' => string[],
	 *     'mode'      => string, // 'typeahead' | 'related-list' | 'ajax-select2' (Task 13; spec D7)
	 *     'levels'    => [ country_code => [ 'region' => bool, 'settlement' => bool, 'address' => bool ] ],
	 *     'current'   => [ 'key' => string, 'level' => string ]|null,
	 *     'implicit'  => bool,
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
		 * Constructor.
		 *
		 * Country codes are injected for testability; the real caller should
		 * pass `array_keys( WC()->countries->get_countries() )` — this class
		 * never calls `WC()` itself.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the optional `$location_service` collaborator
		 *              (location-provider layer Task 9).
		 *
		 * @param string                                                    $plugin_id        Plugin identifier (used in REST endpoint path).
		 * @param string                                                    $rest_base        REST API base URL without a trailing slash.
		 * @param string                                                    $nonce            WP nonce for the field-source endpoint.
		 * @param string[]                                                  $countries        Country codes to evaluate takeover predicates against.
		 * @param \Woodev\Framework\Shipping\Location\Location_Service|null $location_service Location Provider layer façade; `null` omits the
		 *                                                                                        `location` block entirely.
		 */
		public function __construct(
			string $plugin_id,
			string $rest_base,
			string $nonce,
			array $countries,
			?\Woodev\Framework\Shipping\Location\Location_Service $location_service = null
		) {
			$this->plugin_id        = $plugin_id;
			$this->rest_base        = rtrim( $rest_base, '/' );
			$this->nonce            = $nonce;
			$this->countries        = $countries;
			$this->location_service = $location_service;
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
		 * @since 2.0.2
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
		 *         is_pickup_slot: bool
		 *     }>,
		 *     endpoint: string,
		 *     nonce: string,
		 *     takeover: array<string, array<string, bool>>,
		 *     location?: array{
		 *         endpoints: array{suggest: string, select: string},
		 *         nonce: string,
		 *         countries: string[],
		 *         mode: string,
		 *         levels: array<string, array{region: bool, settlement: bool, address: bool}>,
		 *         current: array{key: string, level: string}|null,
		 *         implicit: bool
		 *     }
		 * }
		 */
		public function build( Checkout_Fields $fields ): array {
			$out_fields = [];
			$takeover   = [];

			foreach ( $fields->get_fields() as $id => $def ) {
				$out_fields[ $id ] = [
					'id'             => $def['id'],
					'type'           => $def['type'],
					'section'        => $def['section'],
					'source_kind'    => $def['source_kind'],
					'location_level' => $def['location_level'] ?? null,
					'depends_on'     => $def['depends_on'],
					'required'       => $def['required'],
					'is_pickup_slot' => $def['is_pickup_slot'],
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
				'fields'   => $out_fields,
				'endpoint' => $this->rest_base . '/shipping/checkout/' . $this->plugin_id . '/field-source',
				'nonce'    => $this->nonce,
				'takeover' => $takeover,
			];

			if ( null !== $this->location_service && $this->location_service->is_active() ) {
				$config['location'] = $this->build_location_block( $this->location_service );
			}

			return $config;
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
		 * - `mode` — the store's field-presentation setting (spec D7; Task 13),
		 *   read from {@see \Woodev\Framework\Shipping\Location\Location_Service::get_field_mode()}
		 *   — one of `typeahead` / `related-list` / `ajax-select2`, already
		 *   clamped against the active provider's own capabilities (a mode the
		 *   provider cannot serve is never returned, regardless of what the
		 *   store option literally holds).
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
		 *   resolves ANY provider for that level IN that country. The client
		 *   learns only this — NEVER which provider serves a level (spec D15) —
		 *   because neither this method nor `get_levels_for_country()` ever reads
		 *   {@see \Woodev\Framework\Shipping\Location\Location_Provider::get_id()}.
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
		 *
		 * **`related-list` region seam (Task 13; client-facing contract for the
		 * next agent — CORRECTED after the s71 rig measurement AND the PR #304
		 * review, see below):** when `mode === 'related-list'`, the region
		 * `<select>` WooCommerce renders is populated by
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
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Service $service The active, already-confirmed façade.
		 *
		 * @return array{
		 *     endpoints: array{suggest: string, select: string, list: string},
		 *     nonce: string,
		 *     countries: string[],
		 *     mode: string,
		 *     levels: array<string, array{region: bool, settlement: bool, address: bool}>,
		 *     current: array{key: string, level: string}|null,
		 *     implicit: bool
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
			$region_conflict_countries = [];

			foreach ( $countries as $code ) {
				$country_levels = $service->get_levels_for_country( $code );

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

				$levels[ $code ] = $country_levels;
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
				]
			);

			return [
				'endpoints' => [
					'suggest' => $this->rest_base . '/location/suggest',
					'select'  => $this->rest_base . '/location/select',
					'list'    => $this->rest_base . '/location/list',
				],
				'nonce'     => $this->nonce,
				'countries' => array_values( $countries ),
				'mode'      => $service->get_field_mode(),
				'levels'    => $levels,
				'current'   => $current,
				'implicit'  => $implicit,
				'i18n'      => array_map( 'strval', (array) $strings ),
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

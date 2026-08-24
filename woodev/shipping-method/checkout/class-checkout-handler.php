<?php
/**
 * Woodev Checkout Handler
 *
 * The checkout orchestration backbone (spec §4.2): injects the plugin's
 * {@see Checkout_Fields} into the WooCommerce checkout, reads + sanitizes +
 * validates posted data, and saves the surviving values onto the order in an
 * HPOS-safe way via {@see \Woodev_Order_Compatibility}. Each step fires a forward
 * framework hook so the host plugin can attach its own `handle_*` callbacks.
 *
 * Contract-neutral by construction: field ids are supplied by the host plugin
 * (via `Checkout_Fields`), and the per-field order-meta key IS that plugin-supplied
 * id — the framework hardcodes no installed-site contract string here. The hooks
 * introduced by this class are NEW forward contracts, not renames of any existing
 * installed-site hook.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.2.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Checkout_Handler' ) ) :

	/**
	 * Orchestrates custom checkout fields through the WooCommerce checkout.
	 *
	 * Holds a {@see Checkout_Fields} definition set and runs posted data through the
	 * uniform `sanitize → validate → save` pipeline. Sanitization and validation are
	 * delegated per field to the descriptor's callback seams; persistence is delegated
	 * to {@see \Woodev_Order_Compatibility} so it stays HPOS-safe. Every stage fires a
	 * forward hook, namespaced by a plugin-supplied token, so plugins can react without
	 * the framework owning any contract value.
	 *
	 * @since 1.5.0
	 */
	class Checkout_Handler {

		/** @var Checkout_Fields the field definitions this handler manages */
		private Checkout_Fields $fields;

		/** @var string plugin-supplied token that namespaces this handler's forward hooks */
		private string $hook_prefix;

		/**
		 * The Location Provider layer's service façade (location-provider layer
		 * Task 9). `null` until {@see self::location_service()} lazily builds a
		 * default instance — mirrors
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller}'s own
		 * "optional constructor collaborator, defaults to a fresh instance" test
		 * seam. A fresh default instance is equivalent to any other: the layer's
		 * actual state lives in `WC()->session`/user meta/store options, not in
		 * this object, so which instance answers `is_active()` never matters.
		 *
		 * @since 2.0.2
		 * @var \Woodev\Framework\Shipping\Location\Location_Service|null
		 */
		private ?\Woodev\Framework\Shipping\Location\Location_Service $location_service;

		/**
		 * Shipping method ids that unconditionally require a non-empty pickup field.
		 *
		 * Populated via {@see set_requires_pickup_methods()}. When non-empty,
		 * {@see validate()} runs an independent backstop guard after the per-field
		 * loop to ensure a pickup method can never be placed without a pickup point —
		 * regardless of the field's condition-spec.
		 *
		 * @var string[]
		 */
		private array $requires_pickup_methods = [];

		/**
		 * Registry of native WC field ids claimed by a plugin_id.
		 *
		 * Used by {@see guard_native_field_conflicts()} to detect multi-plugin
		 * conflicts at registration time. Keyed by field id, value is the
		 * plugin_id string of the first handler that registered that field.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string, string>
		 */
		private static array $native_field_registry = [];

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added the optional `$location_service` collaborator
		 *              (location-provider layer Task 9).
		 *
		 * @param Checkout_Fields                                           $fields           field definitions to inject and handle
		 * @param string                                                    $hook_prefix      plugin-supplied token (e.g. the plugin id) that
		 *     namespaces this handler's forward hooks so each plugin's hooks stay distinct;
		 *     defaults to none, yielding bare `woodev_shipping_*` hooks
		 * @param \Woodev\Framework\Shipping\Location\Location_Service|null $location_service Location Provider layer façade; `null`
		 *        (the default) lazily builds a fresh instance on first use — see
		 *        {@see self::location_service()}.
		 */
		public function __construct(
			Checkout_Fields $fields,
			string $hook_prefix = '',
			?\Woodev\Framework\Shipping\Location\Location_Service $location_service = null
		) {
			$this->fields           = $fields;
			$this->hook_prefix      = $hook_prefix;
			$this->location_service = $location_service;
		}

		/**
		 * Gets the Location Provider layer's service façade, building a default
		 * instance on first use (location-provider layer Task 9). See the
		 * {@see self::$location_service} property docblock for why a lazily-built
		 * default is equivalent to any plugin-supplied instance.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Service
		 */
		private function location_service(): \Woodev\Framework\Shipping\Location\Location_Service {
			return $this->location_service ??= new \Woodev\Framework\Shipping\Location\Location_Service();
		}

		/**
		 * Gets the field definitions this handler manages.
		 *
		 * @since 1.5.0
		 *
		 * @return Checkout_Fields
		 */
		public function get_fields(): Checkout_Fields {
			return $this->fields;
		}

		/**
		 * Registers the shipping method ids that unconditionally require a pickup point.
		 *
		 * When set, {@see validate()} runs an independent backstop guard (separate from the
		 * per-field condition-spec loop) that blocks checkout whenever one of these methods
		 * is active and the first `is_pickup_slot` field is blank. This catches the case
		 * where a malformed or missing condition-spec would otherwise silently let an order
		 * place without a mandatory pickup point.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $ids Shipping method ids, e.g. `[ 'carrier_pickup', 'carrier_pickup_express' ]`.
		 *
		 * @return void
		 */
		public function set_requires_pickup_methods( array $ids ): void {
			$this->requires_pickup_methods = array_values( $ids );
		}

		/**
		 * Wires the handler into the WooCommerce checkout.
		 *
		 * Hooks field injection onto `woocommerce_checkout_fields`, posted-data
		 * validation onto the `woocommerce_checkout_process` validation phase (so a
		 * failing field blocks checkout before any order is created), and the
		 * sanitize → validate → save pipeline onto `woocommerce_checkout_order_processed`
		 * — which fires AFTER the order is created and saved, so it has a real id and meta
		 * persistence works on BOTH classic and HPOS storage. (Persisting on
		 * `woocommerce_checkout_create_order` runs before the save: on classic storage the
		 * order id is still 0 and the meta is silently dropped.) Also enqueues frontend
		 * assets on `wp_enqueue_scripts` and registers the field-source REST route on
		 * `rest_api_init`. Not gated on `is_checkout()` so the REST route is available
		 * on API requests. Call once during plugin bootstrap.
		 *
		 * Location-provider layer (Task 12, spec D2): also hooks the WC Address
		 * Autocomplete suppression check onto `init` at priority 21 — strictly
		 * AFTER {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::collect()}
		 * (hooked at `init:20`), so the provider chain and its countries are
		 * already resolved when {@see self::maybe_suppress_wc_address_providers()}
		 * runs. `init` (not `wp_enqueue_scripts`) is deliberate: WooCommerce reads
		 * the `woocommerce_address_providers` filter from ITS OWN `wp_enqueue_scripts`
		 * callback, so racing registration order on the very same hook would be
		 * fragile; `init` always finishes before `wp_enqueue_scripts` fires.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added the `init:21` WC Address Autocomplete suppression hook
		 *              (location-provider layer Task 12).
		 *
		 * @return void
		 */
		public function register(): void {
			add_filter( 'woocommerce_checkout_fields', [ $this, 'handle_checkout_fields' ] );
			add_filter( 'woocommerce_states', [ $this, 'inject_states' ] );
			add_action( 'woocommerce_checkout_process', [ $this, 'handle_checkout_process' ] );
			add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout_order_processed' ], 10, 3 );
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'rest_api_init', [ $this, 'register_rest' ] );
			add_action( 'init', [ $this, 'maybe_suppress_wc_address_providers' ], 21 );
			add_filter( 'woocommerce_checkout_get_value', [ $this, 'handle_checkout_get_value' ], 10, 2 );

			$this->guard_native_field_conflicts();
		}

		/**
		 * Blanks WooCommerce's `*` "no state" sentinel for fields this layer manages.
		 *
		 * `woocommerce_default_country` is stored as `COUNTRY:STATE`, and a merchant who
		 * picked a country without naming a state gets `RU:*`. WooCommerce parses that into
		 * the customer's default state, so `WC_Checkout::get_value( 'shipping_state' )`
		 * returns the literal `*`.
		 *
		 * Natively this is invisible: WC renders a state field as a `<select>`, and `*` simply
		 * matches no option. A field this layer manages is a text `<input>`, so the sentinel
		 * becomes a visible value — the customer opens checkout and finds `*` sitting in
		 * «Регион», and it would be submitted and persisted as if they had typed it.
		 *
		 * Scoped deliberately narrowly: only fields this handler manages, and only the exact
		 * one-character sentinel. A legitimate value is never `*` — it is WooCommerce's own
		 * wildcard, not a place name.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param string|null $value the value WooCommerce resolved
		 * @param string      $input the field id being resolved
		 *
		 * @return string|null
		 */
		public function handle_checkout_get_value( $value, $input ) {

			// `woocommerce_checkout_get_value` is a SHORT-CIRCUIT filter, not a post-filter:
			// WC_Checkout::get_value() applies it with `null` BEFORE resolving anything, and
			// uses the callback's answer only when it is not null. So a callback that waits to
			// be handed the resolved value is never handed anything — it must resolve the value
			// itself, and return null to mean "carry on, WC".
			if ( null !== $value ) {
				return $value;
			}

			$fields = $this->effective_fields();

			if ( ! isset( $fields[ $input ] ) ) {
				return $value;
			}

			$customer = $this->wc_customer();
			$getter   = 'get_' . $input;

			if ( ! is_object( $customer ) || ! is_callable( [ $customer, $getter ] ) ) {
				return $value;
			}

			// Short-circuit ONLY for the sentinel; every other value is left to WC's own
			// resolution, so this filter cannot drift away from WC's behaviour over time.
			return '*' === $customer->{$getter}() ? '' : $value;
		}

		/**
		 * The WooCommerce customer object, or null when WooCommerce is unavailable.
		 *
		 * A seam, deliberately, rather than a bare `WC()` call: mocking the `WC` function with
		 * Brain Monkey DEFINES it globally and PHP cannot un-define it, so it leaks into every
		 * later test in the process and breaks the ones that assert WooCommerce is ABSENT
		 * (gotcha `brain-monkey-function-pollution`). Overriding a method costs a subclass and
		 * pollutes nothing — the same reason {@see self::wc_country_codes()} exists.
		 *
		 * @since 2.0.2
		 *
		 * @return object|null
		 */
		protected function wc_customer() {

			if ( ! function_exists( 'WC' ) ) {
				return null;
			}

			$wc = WC();

			return is_object( $wc ) && isset( $wc->customer ) ? $wc->customer : null;
		}


		/**
		 * WC Address Autocomplete arbitration — the server-side half (location-provider
		 * layer Task 12, spec D2).
		 *
		 * WooCommerce's own Address Autocomplete feature (option
		 * `woocommerce_address_autocomplete_enabled` + providers via the
		 * `woocommerce_address_providers` filter, since WC 9.9.0) arbitrates
		 * per-country client-side; see gotcha
		 * `wc-address-autocomplete-hosts-only-address1-and-flattens-identity` for the
		 * full measurement this rests on. When our own layer already covers EVERY
		 * country the store sells to, there is nothing left for WC's autocomplete to
		 * usefully do, and the two would otherwise fight over the same address
		 * fields — so this applies the documented full kill: filters
		 * `woocommerce_address_providers` down to an empty array at `PHP_INT_MAX`,
		 * which is WC's own suggested lever for turning the feature off entirely
		 * (their script enqueue check short-circuits on an empty provider list, so
		 * this covers classic AND block checkouts alike — both read the SAME
		 * `AddressProviderController`).
		 *
		 * A MIXED-country store — one selling to at least one country our provider
		 * chain does not cover — must NOT get the full kill: that would silently take
		 * away WC's autocomplete for the countries we do not serve. In that case (and
		 * whenever the layer is inactive, or the selling-country set cannot be
		 * determined at all) this method does nothing — the filter is left completely
		 * untouched, not merely made to return its input unchanged. The client-side
		 * half of this same arbitration (a per-country wrap of WC's OWN provider
		 * registry, for exactly this mixed-country case) lives in
		 * `location-cascade.js`.
		 *
		 * @internal Hooked to `init` (priority 21) by {@see self::register()}.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function maybe_suppress_wc_address_providers(): void {
			if ( ! $this->location_service()->is_active() ) {
				return;
			}

			$selling_countries = $this->wc_selling_country_codes();

			if ( [] === $selling_countries ) {
				return;
			}

			$supported_countries = $this->location_service()->get_supported_countries();

			foreach ( $selling_countries as $country ) {
				if ( ! in_array( $country, $supported_countries, true ) ) {
					return;
				}
			}

			add_filter( 'woocommerce_address_providers', '__return_empty_array', PHP_INT_MAX );
		}

		/**
		 * Injects a takeover STATE field's source options as WooCommerce native states.
		 *
		 * For a field whose id is a WC state field (`*_state`) that declares a `source` +
		 * `takeover_condition`, this registers the source's options as the country's states for
		 * every country where the takeover condition holds. WooCommerce then renders the field
		 * as a native `<select>` and persists the chosen value in the session — so the region
		 * survives `update_checkout` with NO client-side DOM surgery (the fragile approach this
		 * replaces). Non-state takeover fields (e.g. a city autocomplete) are still enhanced on
		 * the client, since "city" is not a WooCommerce concept.
		 *
		 * Two contract constraints follow from `woocommerce_states` being keyed by COUNTRY, not
		 * by field:
		 *
		 * 1. **Empty source → WooCommerce's own states are kept.** When the takeover condition
		 *    holds but the source yields no options (an unserved country, or a transient carrier
		 *    API failure), the country is left untouched rather than overwritten. Writing an empty
		 *    array would tell WooCommerce "this country has no states at all" and it would HIDE
		 *    the region field entirely — a far worse checkout than falling back to WC's list.
		 * 2. **One state source per country.** Two state descriptors (e.g. `billing_state` and
		 *    `shipping_state`) that take over the same country must resolve to the same options,
		 *    because only one option set can be registered for that country. Two NON-EMPTY sets
		 *    that disagree are a plugin bug: the first one wins and `_doing_it_wrong()` reports
		 *    the conflict rather than letting the last descriptor silently overwrite it. An EMPTY
		 *    result is deliberately NOT treated as a conflicting opinion — per rule 1 it means
		 *    "this source has nothing for this country", which is indistinguishable from a
		 *    transient carrier API failure, so warning on it would fire falsely at runtime on a
		 *    live checkout rather than flagging a real coding mistake.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, array<string, string>> $states WC states keyed by country code
		 *
		 * @return array<string, array<string, string>>
		 */
		public function inject_states( $states ): array {
			$states    = is_array( $states ) ? $states : [];
			$countries = $this->wc_country_codes();
			$injected  = [];

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$condition = $field['takeover_condition'] ?? null;
				$source    = $field['source'] ?? null;

				if ( '_state' !== substr( $id, -6 ) || null === $condition || ! is_callable( $source ) ) {
					continue;
				}

				foreach ( $countries as $code ) {
					if ( ! (bool) $condition( [ 'country' => (string) $code ] ) ) {
						continue;
					}

					$options = [];

					foreach ( (array) $source( [ 'country' => (string) $code ] ) as $item ) {
						if ( is_array( $item ) && isset( $item['value'], $item['label'] ) ) {
							$options[ (string) $item['value'] ] = (string) $item['label'];
						}
					}

					if ( [] === $options ) {
						continue;
					}

					$code = (string) $code;

					if ( isset( $injected[ $code ] ) && $injected[ $code ] !== $options ) {
						_doing_it_wrong(
							__METHOD__,
							sprintf(
								"checkout field '%s' registers a different region set for country '%s'; a country can only have one state source, so this registration is ignored",
								$id,
								$code
							),
							'2.0.2'
						);

						continue;
					}

					$injected[ $code ] = $options;
					$states[ $code ]   = $options;
				}
			}

			return $states;
		}

		/**
		 * Returns the plugin token that identifies this handler.
		 *
		 * Exposes the constructor-injected `$hook_prefix` as a stable public accessor.
		 * Used to namespace the JS config global and the REST route plugin-id segment.
		 * Falls back to `'shipping'` when the prefix was left empty (anonymous handler).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function plugin_id(): string {
			return '' !== $this->hook_prefix ? $this->hook_prefix : 'shipping';
		}

		/**
		 * Returns a JS-identifier-safe version of the plugin id.
		 *
		 * Used as the suffix in the `woodev_checkout_field_config_{suffix}` global name
		 * so the name is always a valid JS identifier regardless of what the plugin
		 * supplies as its id token.
		 *
		 * COLLAPSING IS THE BUG THIS GUARDS (issue #142). Replacing every character outside
		 * `[a-z0-9_]` with `_` is not injective: `carrier-a`, `carrier.a` and `carrier_a` all
		 * produce `carrier_a`, so two shipping plugins with ids that near on one checkout page
		 * share ONE config global and the second `wp_localize_script()` silently overwrites
		 * the first's field descriptors and REST endpoint. An id that already is a valid
		 * identifier is returned untouched (the common case keeps a readable name); only a
		 * REWRITTEN id pays for a short digest of the original, which is what keeps two
		 * different originals on two different suffixes.
		 *
		 * The suffix is never read by the browser as a literal — `checkout-field-classic.js`
		 * discovers configs by scanning `window` for the `woodev_checkout_field_config_`
		 * PREFIX — so its exact spelling is a framework-internal detail, not a contract.
		 * Mirrored, deliberately, in
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::config_object_suffix()},
		 * which carries the longer version of this note; change one and look at the other.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function config_object_suffix(): string {
			$plugin_id = $this->plugin_id();
			$sanitized = (string) preg_replace( '/[^a-z0-9_]/i', '_', $plugin_id );

			return $sanitized === $plugin_id
				? $sanitized
				: $sanitized . '_' . substr( md5( $plugin_id ), 0, 8 );
		}

		/**
		 * Enqueues the checkout-field store and classic adapter scripts.
		 *
		 * Only runs on the checkout page and only when there is at least one managed
		 * field. Localizes the full JS config (field descriptors, REST endpoint, nonce,
		 * takeover map, i18n strings) onto the classic adapter handle so it can
		 * bootstrap without any inline PHP.
		 *
		 * Location-provider layer (Task 9): the SAME config object also carries the
		 * `location` block (via the {@see Checkout_Config} constructor's
		 * `$location_service` collaborator) — one config object, one enqueue path.
		 * When that block is present, ALSO enqueues the Task 10/11 client scripts
		 * (`location-typeahead.js`, `location-cascade.js`) and the typeahead's own
		 * suggestion-listbox stylesheet (`location.css`) — but ONLY when their files
		 * actually exist on disk ({@see self::enqueue_script_if_built()} /
		 * {@see self::enqueue_style_if_built()}): those files ship in a later PR
		 * block (PR-C), so this handler is wired now with a guard that can never
		 * 404, and needs zero further code changes once the files land.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Enqueues the location-provider client scripts, guarded on
		 *              their files existing on disk (location-provider layer Task 9).
		 * @since 2.1.0 Also enqueues `location.css`, the typeahead listbox's own
		 *              theme-resistant styles, under the same guard.
		 * @since 2.1.0 Also enqueues `location-select-modes.js` (Task 13; spec D7), the
		 *              `related-list`/`ajax-select2` renderer registry — same guard, declared
		 *              as a dependency of `woodev-location-cascade` so it always registers
		 *              before the cascade's own boot pass reads it.
		 * @since 2.0.2 Builds the localized config from {@see self::effective_fields()}
		 *              rather than the raw {@see Checkout_Fields} instance, so a
		 *              Location-Provider field's Rule 7b fan-out reaches the browser
		 *              config under the same id(s)/section(s) it attaches to server-side
		 *              (issue #458).
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {

			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
				return;
			}

			if ( [] === $this->fields->get_fields() ) {
				return;
			}

			$store_path   = self::asset_path( 'js/frontend/checkout-field-store.js' );
			$classic_path = self::asset_path( 'js/frontend/checkout-field-classic.js' );

			wp_enqueue_script(
				'woodev-checkout-field-store',
				self::asset_url( 'js/frontend/checkout-field-store.js' ),
				[],
				file_exists( $store_path ) ? (string) filemtime( $store_path ) : (string) \Woodev_Plugin::VERSION,
				true
			);

			wp_enqueue_script(
				'woodev-checkout-field-classic',
				self::asset_url( 'js/frontend/checkout-field-classic.js' ),
				[ 'jquery', 'selectWoo', 'woodev-checkout-field-store' ],
				file_exists( $classic_path ) ? (string) filemtime( $classic_path ) : (string) \Woodev_Plugin::VERSION,
				true
			);

			$config          = ( new Checkout_Config(
				$this->plugin_id(),
				rtrim( rest_url( 'woodev/v1' ), '/' ),
				wp_create_nonce( 'wp_rest' ),
				$this->wc_country_codes(),
				$this->location_service(),
				// Checkout field policy (Task 6, issue #362): store-level, reached through
				// the tab singleton rather than constructed here — its availability rules
				// must not be computed twice with a different answer than the «Поля»
				// section itself uses.
				\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::instance()->get_field_settings()
			) )->build( Checkout_Fields::from_array( array_values( $this->effective_fields() ) ) );
			// No `required` string here any more (#274): the client no longer renders an
			// inline «Заполните обязательное поле.» under the field or under the pickup
			// trigger — none of СДЭК/Яндекс/Почта does, and a blocked control needs no
			// caption. The disabled «Оформить заказ» button is the signal; WooCommerce
			// still says its own piece on submit.
			$config['i18n']  = [
				'placeholder' => $this->placeholder_label(),
			];

			if ( isset( $config['location'] ) ) {
				$typeahead_built = $this->enqueue_script_if_built( 'woodev-location-typeahead', 'js/frontend/location-typeahead.js', [] );

				// Task 13's `related-list`/`ajax-select2` renderers (spec D7) — depends on
				// `selectWoo`, the SAME WC-bundled select2 handle `woodev-checkout-field-classic`
				// already requires, because select2 can only enhance a real `<select>` (see the
				// file's own SELECT2 IS OPTIONAL AT RUNTIME section). Declared as a dependency of
				// `woodev-location-cascade` below (never the reverse) so its own registration onto
				// `window.WoodevLocationRenderers` has always already run by the time the cascade's
				// `boot()` calls `attachAll()` — the cascade never imports this file directly (spec
				// D7: "mode is presentation... the cascade must not know which renderer a field
				// uses"), it only reads the registry that file populates.
				$select_modes_built = $this->enqueue_script_if_built(
					'woodev-location-select-modes',
					'js/frontend/location-select-modes.js',
					[ 'jquery', 'selectWoo' ]
				);

				$this->enqueue_script_if_built(
					'woodev-location-cascade',
					'js/frontend/location-cascade.js',
					array_values(
						array_filter(
							[
								'jquery',
								'woodev-checkout-field-store',
								'woodev-checkout-field-classic',
								$typeahead_built ? 'woodev-location-typeahead' : null,
								$select_modes_built ? 'woodev-location-select-modes' : null,
							]
						)
					)
				);

				// The typeahead's own suggestion-listbox stylesheet — same guard, same PR-C
				// "wired now, lands with zero code changes once the file exists" discipline as
				// the two scripts above (see `enqueue_script_if_built()`'s own docblock).
				$this->enqueue_style_if_built( 'woodev-location-styles', 'css/frontend/location.css', [] );
			}

			wp_localize_script(
				'woodev-checkout-field-classic',
				'woodev_checkout_field_config_' . $this->config_object_suffix(),
				$config
			);
		}

		/**
		 * Enqueues one script handle, but only when its file actually exists on disk.
		 *
		 * The Location Provider layer's client scripts (Tasks 10-11) land in a later
		 * PR block; calling this NOW, before those files exist, is a deliberate no-op
		 * rather than a dead registration — mirrors
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_script_if_built()}'s
		 * own "never register a dependency on a handle nothing enqueued" discipline
		 * (gotcha `built-on-both-sides-with-no-caller-in-the-middle`): an unconditional
		 * `wp_enqueue_script()` pointed at a file that does not exist would 404 in the
		 * browser the moment this layer goes active; a guarded one simply enqueues
		 * nothing until the file is there.
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
		 * The CSS counterpart of {@see self::enqueue_script_if_built()} — same "never register a
		 * `href` that would 404" discipline, same {@see self::asset_exists()} seam, applied to
		 * `location.css` (the typeahead's own suggestion-listbox styles). Mirrors
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_style_if_built()}.
		 *
		 * @since 2.1.0
		 *
		 * @param string   $handle   the style handle to register.
		 * @param string   $relative path relative to the assets directory.
		 * @param string[] $deps     style dependencies.
		 *
		 * @return bool true when the style was enqueued; false when its file is missing.
		 */
		private function enqueue_style_if_built( string $handle, string $relative, array $deps ): bool {
			$path = self::asset_path( $relative );

			if ( ! static::asset_exists( $path ) ) {
				return false;
			}

			wp_enqueue_style( $handle, self::asset_url( $relative ), $deps, self::asset_version( $path ) );

			return true;
		}

		/**
		 * Whether an asset file exists on disk. A protected static seam so a test
		 * probe can simulate "already built" without touching the real filesystem —
		 * mirrors {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::asset_exists()}.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path absolute filesystem path.
		 *
		 * @return bool
		 */
		protected static function asset_exists( string $path ): bool {
			return file_exists( $path );
		}

		/**
		 * Resolves an asset's cache-busting version string: the file's own mtime, or
		 * {@see \Woodev_Plugin::VERSION} as a defensive fallback for a file removed
		 * between {@see self::asset_exists()}'s check and this call (every real call
		 * site checks existence first via {@see self::enqueue_script_if_built()}).
		 *
		 * @since 2.0.2
		 *
		 * @param string $path absolute filesystem path.
		 *
		 * @return string
		 */
		private static function asset_version( string $path ): string {
			return file_exists( $path ) ? (string) filemtime( $path ) : (string) \Woodev_Plugin::VERSION;
		}

		/**
		 * Registers the field-source REST route for this handler.
		 *
		 * Delegates to {@see Field_Source_Controller::register_routes()} so the route
		 * is available on all REST requests, not just checkout-page loads.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_rest(): void {
			( new \Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller( $this->fields, $this->plugin_id() ) )->register_routes();
		}

		/**
		 * Warns when two handlers try to enhance the same native WC field.
		 *
		 * Maintains a static registry of native-field-id → plugin_id claims.
		 * If a field id that belongs to the WooCommerce billing/shipping address
		 * namespace (see {@see is_native_wc_field()}) is already registered by a
		 * different handler, fires `_doing_it_wrong` so the developer sees the conflict
		 * immediately. Last registration wins — the warning is advisory only.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function guard_native_field_conflicts(): void {

			foreach ( array_keys( $this->effective_fields() ) as $id ) {
				if ( ! $this->is_native_wc_field( $id ) ) {
					continue;
				}

				if ( isset( self::$native_field_registry[ $id ] ) && self::$native_field_registry[ $id ] !== $this->plugin_id() ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							"checkout field '%s' is enhanced by more than one shipping plugin; last registration wins",
							$id
						),
						'2.0.2'
					);
				}

				self::$native_field_registry[ $id ] = $this->plugin_id();
			}
		}

		/**
		 * Resets the static native-field registry.
		 *
		 * Provided for unit-test teardown so that tests that register handlers with
		 * conflicting native-field ids do not bleed state into subsequent tests.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset_native_field_registry(): void {
			self::$native_field_registry = [];
		}

		/**
		 * Resolves the filesystem path to a shipping-framework asset.
		 *
		 * Mirrors {@see asset_url()} but returns a local path suitable for
		 * `filemtime()` and `file_exists()` checks.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory
		 *
		 * @return string absolute filesystem path to the asset
		 */
		private static function asset_path( string $relative ): string {
			return dirname( __DIR__ ) . '/assets/' . ltrim( $relative, '/' );
		}

		/**
		 * Resolves a URL within the shipping-framework assets directory.
		 *
		 * This file lives in `checkout/`, a direct child of the shipping-method root;
		 * `assets/` is ALSO a direct child of that root — a sibling of `checkout/`, not of
		 * this file's own directory. Resolving from this file keeps the handler
		 * self-contained — it needs no plugin instance to locate its assets.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory
		 *
		 * @return string absolute URL to the asset
		 */
		private static function asset_url( string $relative ): string {
			$file = self::asset_path( $relative );

			return plugins_url( basename( $file ), $file );
		}

		/**
		 * Injects the managed fields into the WooCommerce checkout fields.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param mixed $checkout_fields the WC checkout fields, keyed by section
		 *
		 * @return array<string, mixed>
		 */
		public function handle_checkout_fields( $checkout_fields ): array {
			return $this->inject( (array) $checkout_fields );
		}

		/**
		 * Validates the posted field values during the checkout validation phase.
		 *
		 * Runs while WooCommerce is still collecting validation errors, so a blank
		 * required field or a failing `validate_callback` halts checkout before an order
		 * exists.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Builds a `$state` map (chosen shipping method + billing country) and
		 *              passes it to `validate()` so conditional-required specs (A2) can be
		 *              resolved at validation time.
		 *
		 * @return void
		 */
		public function handle_checkout_process(): void {
			$state = [
				'chosen_shipping_method' => $this->chosen_shipping_method(),
				'country'                => $this->posted_country(),
			];
			$this->validate( $this->sanitize_posted_data( $this->get_posted_data() ), $state );
		}

		/**
		 * Sanitizes, validates and saves the posted values onto the created order.
		 *
		 * Fires on `woocommerce_checkout_order_processed`, AFTER the order has been saved,
		 * so it has a real id and meta persistence works on classic + HPOS storage. Only
		 * reached once validation has passed, so the re-validation inside
		 * {@see self::process()} adds no duplicate notices.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param int                  $order_id    the created order id (unused; the order object is used)
		 * @param array<string, mixed> $posted_data the posted checkout data (unused; raw post is read directly)
		 * @param \WC_Order            $order       the created, saved order
		 *
		 * @return void
		 */
		public function handle_checkout_order_processed( int $order_id, array $posted_data, \WC_Order $order ): void {
			$this->process( $this->get_posted_data(), $order );
		}

		/**
		 * Reads the raw posted checkout data.
		 *
		 * Returns the unslashed `$_POST`; per-field cleaning happens in
		 * {@see self::sanitize_posted_data()}. WooCommerce verifies the checkout nonce
		 * before its checkout hooks fire, so no separate nonce check is performed here.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, mixed>
		 */
		protected function get_posted_data(): array {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before its checkout hooks fire; values are cleaned in sanitize_posted_data().
			return (array) wp_unslash( $_POST );
		}

		/**
		 * Injects the managed fields into a WooCommerce checkout-fields array.
		 *
		 * Each managed field is placed under its own `section` descriptor key
		 * (default `'order'`). When a field already exists in WooCommerce's array it
		 * is **enhanced in place**: only the keys this framework owns (`type`, `label`,
		 * `required`, plus `options` when pre-filled) are overridden; all other WC
		 * args (`class`, `priority`, `validate`, `custom_attributes`, …) are
		 * preserved unchanged via `array_merge( $existing, $our_overrides )`.
		 *
		 * For an options-kind root field (has a callable `source`, `source_kind ===
		 * 'options'`, `depends_on === null`) the source is invoked with the current
		 * customer billing country as context to pre-fill the native `<select>`
		 * `options` map (`[ value => label ]`). Dependent and suggest-kind fields
		 * receive their options dynamically via the field-source REST endpoint and
		 * are left without a static `options` key.
		 *
		 * A Location-Provider field ({@see Field::source_location()}) is expanded by
		 * {@see self::effective_fields()} into the section(s) Rule 7b attaches it to
		 * (`docs-internal/AGENT-RULES.md`) BEFORE this loop ever runs, so this method
		 * itself stays unaware of that fan-out: it still just places one descriptor
		 * per iteration under `$field['section']`, only now iterating the expanded id
		 * set instead of the host plugin's raw declarations.
		 *
		 * The fully-merged result is passed through the forward `..._checkout_fields`
		 * filter so the host plugin can refine field args further.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Fields are grouped by their own `section`; existing WC args
		 *              are preserved (conservative merge); options-kind root fields
		 *              have their source() called to pre-fill the options map.
		 * @since 2.0.2 Iterates {@see self::effective_fields()} instead of
		 *              {@see Checkout_Fields::get_fields()} directly, so a
		 *              Location-Provider field's Rule 7b fan-out reaches WooCommerce's
		 *              checkout array under the right section(s)/id(s) (issue #458).
		 * @since 2.0.2 Re-declares a non-empty `data-input-classes` through `custom_attributes`
		 *              for a field WooCommerce would otherwise have rendered through its `state`
		 *              branch, the only branch that emits the attribute (issue #469).
		 *
		 * @param array<string, mixed> $checkout_fields WC checkout fields, keyed by section
		 * @param string               $section         unused override kept for BC; per-field
		 *                                              `section` key is the primary path
		 *
		 * @return array<string, mixed>
		 */
		public function inject( array $checkout_fields, string $section = 'order' ): array {
			$country = $this->current_country();

			foreach ( $this->effective_fields() as $id => $field ) {
				$field_section = '' !== ( $field['section'] ?? '' ) ? (string) $field['section'] : $section;

				if ( ! isset( $checkout_fields[ $field_section ] ) || ! is_array( $checkout_fields[ $field_section ] ) ) {
					$checkout_fields[ $field_section ] = [];
				}

				// Takeover fields are owned entirely by the CLIENT: the server renders a guessed
				// country (a guest has no country yet), but the customer may see a different one,
				// so a server-side swap would mismatch the displayed country. Leave WC's native
				// field untouched here; the classic adapter converts it for the ACTUAL country on
				// load and on every `country_to_state_changed`. (No-JS falls back to WC's native
				// field — acceptable progressive enhancement.)
				if ( null !== ( $field['takeover_condition'] ?? null ) ) {
					continue;
				}

				// Build only the keys we own. `required` is touched ONLY when the descriptor is
				// opinionated, so enhancing a native WC field never silently changes its required
				// flag (Codex review P1 + re-critic):
				// - a condition-spec (array) → WC static `false` (WC must not block a blank
				// conditional field regardless of the chosen method; our validate() + store
				// gate enforce conditional requiredness instead);
				// - an explicit bool `true` → WC `required`;
				// - a default/`false` required → leave WC's own required flag UNTOUCHED (e.g.
				// turning `billing_city` into a select must not un-require it).
				$our_overrides = [
					'type'  => (string) $field['type'],
					'label' => (string) $field['label'],
				];

				if ( is_array( $field['required'] ) ) {
					$our_overrides['required'] = false;
				} elseif ( true === $field['required'] ) {
					$our_overrides['required'] = true;
				}

				// Pre-fill options for root options-kind fields (source must be callable,
				// source_kind must be 'options', depends_on must be null).
				$is_options_root = null === $field['depends_on']
					&& 'options' === ( $field['source_kind'] ?? null )
					&& is_callable( $field['source'] ?? null );

				if ( $is_options_root ) {
					$raw_options = (array) ( $field['source'] )( [ 'country' => $country ] );
					$options_map = [];
					foreach ( $raw_options as $item ) {
						if ( is_array( $item ) && isset( $item['value'], $item['label'] ) ) {
							$options_map[ (string) $item['value'] ] = (string) $item['label'];
						}
					}
					$our_overrides['options'] = $options_map;
				}

				// A `select`-type field with EMPTY options is rendered as an empty string by
				// woocommerce_form_field() — the field silently vanishes from the checkout. Ensure
				// at least a placeholder empty option so the <select> always renders (suggest
				// fields have no preset options; a takeover-true country may have no regions). The
				// classic adapter / select2 populate real options client-side.
				if ( 'select' === $our_overrides['type'] ) {
					$options = isset( $our_overrides['options'] ) && is_array( $our_overrides['options'] )
						? $our_overrides['options']
						: [];

					if ( ! array_key_exists( '', $options ) ) {
						$options = [ '' => $this->placeholder_label() ] + $options;
					}

					$our_overrides['options'] = $options;
				}

				// Conservative merge: start from whatever WC already has for this field,
				// then overlay only our keys — preserving validate, class, priority, etc.
				$existing_wc_args = $checkout_fields[ $field_section ][ $id ] ?? [];
				$existing_wc_args = is_array( $existing_wc_args ) ? $existing_wc_args : [];

				// WooCommerce emits `data-input-classes` ONLY from the three sub-branches of
				// `case 'state':` in `woocommerce_form_field()`. Overriding `type` moves the field
				// out of that branch, so a theme's `input_class` silently stops reaching the
				// markup — and `country-select.js:103` reads that attribute off the statebox
				// before rebuilding it. Re-declare it through `custom_attributes`, which every
				// render branch interpolates (issue #469).
				//
				// An EMPTY class list is declared as a single SPACE, not as an empty string, and
				// that is WooCommerce's constraint rather than a preference: before rendering,
				// `woocommerce_form_field()` runs
				// `array_filter( (array) $args['custom_attributes'], 'strlen' )`
				// (`wc-template-functions.php:3367`, WooCommerce 11.0.1), which DROPS an
				// empty-string attribute outright. WooCommerce's own `state` branch can emit
				// `data-input-classes=""` only because it writes the attribute literally, outside
				// `custom_attributes`. A stock install always hits the empty case — WC core sets
				// no `input_class` on any address field — so declaring nothing there would leave
				// the defect exactly as it was.
				//
				// A space is equivalent to an empty string for every consumer of a class-list
				// attribute, and that was measured on the rig rather than reasoned about: with
				// `data-input-classes=" "` WooCommerce's rebuild produced `class="state_select"`,
				// byte-identical to the `""` control, and round-tripped the attribute onto the
				// element it built.
				if ( 'state' === ( $existing_wc_args['type'] ?? '' ) && 'state' !== $our_overrides['type'] ) {
					$input_classes = implode( ' ', (array) ( $existing_wc_args['input_class'] ?? [] ) );
					$wc_attributes = $existing_wc_args['custom_attributes'] ?? [];
					$wc_attributes = is_array( $wc_attributes ) ? $wc_attributes : [];

					$wc_attributes['data-input-classes'] = '' === $input_classes ? ' ' : $input_classes;

					$our_overrides['custom_attributes'] = $wc_attributes;
				}

				$checkout_fields[ $field_section ][ $id ] = array_merge(
					$existing_wc_args,
					$our_overrides
				);
			}

			/**
			 * Filters the checkout fields after the managed fields are injected.
			 *
			 * @since 1.5.0
			 *
			 * @param array<string, mixed> $checkout_fields the merged checkout fields
			 * @param string               $section         the primary section (legacy param, kept for BC)
			 */
			return (array) apply_filters( $this->hook( 'checkout_fields' ), $checkout_fields, $section );
		}

		/**
		 * Returns the current WooCommerce customer billing country.
		 *
		 * Returns an empty string when WC is not available (e.g. in unit tests).
		 * Override in subclasses or test doubles to supply a specific country code
		 * without bootstrapping WooCommerce.
		 *
		 * @since 2.0.2
		 *
		 * @return string ISO 3166-1 alpha-2 country code, or empty string.
		 */
		protected function current_country(): string {
			if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
				return '';
			}

			$country = (string) WC()->customer->get_billing_country();

			// A fresh guest has no customer country yet, but WooCommerce still DISPLAYS the
			// store base country in the checkout country field. Mirror that fallback so the
			// server enhances the same country the customer actually sees (otherwise takeover
			// never applies on the initial render).
			if ( '' === $country && function_exists( 'wc_get_base_country' ) ) {
				$country = (string) wc_get_base_country();
			}

			return $country;
		}

		/**
		 * Returns the placeholder label for an empty <select> option.
		 *
		 * Mirrors WooCommerce's own "Select an option…" empty option so an enhanced select
		 * never shows a blank first row. Shared by inject() (the server-rendered option) and
		 * the JS config (`i18n.placeholder`, used by the client cascade/select2).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function placeholder_label(): string {
			return __( 'Выберите…', 'woodev-plugin-framework' );
		}

		/**
		 * Returns the list of WooCommerce country codes.
		 *
		 * Extracted so tests can supply a country list without bootstrapping WooCommerce.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		protected function wc_country_codes(): array {
			if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
				return [];
			}

			return array_map( 'strval', array_keys( (array) WC()->countries->get_countries() ) );
		}


		/**
		 * Returns the WooCommerce store's SELLING country codes — i.e. what the
		 * merchant's "Selling location(s)" setting (`woocommerce_allowed_countries`
		 * and its `all_except`/`specific` companions) actually admits, via
		 * {@see \WC_Countries::get_allowed_countries()}. Deliberately NOT
		 * {@see self::wc_country_codes()} (every country WC knows about, ~250 of
		 * them, used for the `<select>` option list) — Task 12's arbitration needs
		 * to know what the store actually SELLS to, so a store that only sells to
		 * `RU` never gets penalized for the other 249 codes WC merely knows how to
		 * spell.
		 *
		 * Extracted (like {@see self::wc_country_codes()}) so tests can supply a
		 * selling-country list without bootstrapping WooCommerce; returns an empty
		 * array when WC is unavailable — a caller must treat that as "unknown",
		 * never as "sells nowhere" (see {@see self::maybe_suppress_wc_address_providers()}).
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		protected function wc_selling_country_codes(): array {
			if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
				return [];
			}

			return array_map( 'strval', array_keys( (array) WC()->countries->get_allowed_countries() ) );
		}

		/**
		 * Sanitizes posted checkout data for the managed fields.
		 *
		 * For each field, pulls its raw value from the posted data by id and runs it
		 * through the field's `sanitize_callback`, falling back to `wc_clean`. Fields
		 * absent from the post resolve to `''`.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Iterates {@see self::effective_fields()} so a Location-Provider
		 *              field's Rule 7b fan-out reads posted data under the ids
		 *              WooCommerce/the browser actually used (issue #458).
		 *
		 * @param array<string, mixed> $posted raw posted data (e.g. `$_POST`)
		 *
		 * @return array<string, mixed> clean values keyed by field id
		 */
		public function sanitize_posted_data( array $posted ): array {
			$clean = [];

			foreach ( $this->effective_fields() as $id => $field ) {
				$raw      = $posted[ $id ] ?? '';
				$callback = $field['sanitize_callback'] ?? null;

				$clean[ $id ] = is_callable( $callback ) ? $callback( $raw ) : wc_clean( $raw );
			}

			return $clean;
		}

		/**
		 * Validates sanitized field values, blocking checkout on any failure.
		 *
		 * A required field that is blank fails. A field whose `validate_callback` returns
		 * `false` or a {@see \WP_Error} fails. Every failure adds a WooCommerce error
		 * notice — which halts checkout — and the method returns `false` overall.
		 *
		 * The `required` descriptor is resolved via {@see Checkout_Condition::is_required()}
		 * which handles both plain booleans and conditional condition-spec arrays (A2 gating).
		 * Pass `$state` with the runtime context (chosen shipping method, billing country) so
		 * condition-spec `required` values can be evaluated correctly.
		 *
		 * After the per-field loop an independent pickup backstop runs when
		 * {@see set_requires_pickup_methods()} has been called: if the chosen method is one of
		 * the declared pickup methods AND a pickup-slot field's value is blank, checkout is
		 * blocked regardless of that field's condition-spec. EVERY declared slot is checked,
		 * not just the first one found ({@see self::pickup_slot_fields()}, issue #325) — see
		 * that method for what in this layer is still single-package on purpose, and where a
		 * package dimension would go.
		 *
		 * The backstop skips its OWN notice when the per-field loop already added a
		 * required-field error for that exact pickup field id (measured duplication, #299/#134:
		 * a `Pickup_Field` preset's condition-spec and `set_requires_pickup_methods()` are normally
		 * driven by the same method-id list, so both mechanisms catch the same blank field on the
		 * same submit). It still fires alone whenever the per-field loop did NOT catch the field —
		 * e.g. `set_requires_pickup_methods()`'s id list and the descriptor's own condition-spec
		 * `value` list are two independently host-supplied lists that nothing keeps in sync: a
		 * method id can be present in `requires_pickup_methods` while absent from the condition-spec,
		 * so the per-field loop's strict `in` comparison never trips that field's `required` gate even
		 * though this backstop's own {@see chosen_method_matches()} check matches — that divergence is
		 * the backstop's actual reason to exist, so this guard never suppresses it as a class, only the
		 * field-id-exact repeat.
		 *
		 * This guard only dedupes OUR OWN two notices (the per-field loop and the backstop). A field
		 * declared with a plain-bool `required` (no condition-spec, no pickup backstop involved) still
		 * gets both WC's own native "is a required field" notice — via {@see inject()}'s WC `required`
		 * flag — AND this method's own {@see required_message()} notice; that duplication class is not
		 * addressed here.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `$state` parameter for conditional-required (A2) evaluation.
		 * @since 2.0.2 Added independent pickup backstop guard.
		 * @since 2.0.2 Backstop notice suppressed when the per-field loop already reported the
		 *              same field id blank-and-required (#299, #134).
		 * @since 2.0.2 Backstop enforces EVERY declared pickup slot instead of only the first
		 *              one found (#325).
		 * @since 2.0.2 Iterates {@see self::effective_fields()} instead of
		 *              {@see Checkout_Fields::get_fields()} directly, so a Location-Provider
		 *              field's Rule 7b fan-out is validated under the ids it actually attaches
		 *              to (issue #458).
		 *
		 * @param array<string, mixed> $values clean values keyed by field id
		 * @param array<string, mixed> $state  flat checkout-state map, e.g.
		 *                                     `['chosen_shipping_method' => 'carrier_pickup', 'country' => 'RU']`
		 *
		 * @return bool true when every field is valid; false when any field blocks checkout
		 */
		public function validate( array $values, array $state = [] ): bool {
			$valid              = true;
			$blank_required_ids = [];

			foreach ( $this->effective_fields() as $id => $field ) {
				$value    = $values[ $id ] ?? '';
				$required = Checkout_Condition::is_required( $field['required'], $state );

				if ( $required && self::is_blank( $value ) ) {
					$this->add_error( self::required_message( $field ) );
					$blank_required_ids[ $id ] = true;
					$valid                     = false;
					continue;
				}

				$callback = $field['validate_callback'] ?? null;

				if ( self::is_blank( $value ) || ! is_callable( $callback ) ) {
					continue;
				}

				$result = $callback( $value, $field );

				if ( false === $result || $result instanceof \WP_Error ) {
					$message = $result instanceof \WP_Error ? (string) $result->get_error_message() : self::invalid_message( $field );
					$this->add_error( $message );
					$valid = false;
				}
			}

			// Independent pickup backstop: runs after the per-field loop regardless of spec.
			if ( [] !== $this->requires_pickup_methods ) {
				$chosen = (string) ( $state['chosen_shipping_method'] ?? '' );

				if ( self::chosen_method_matches( $chosen, $this->requires_pickup_methods ) ) {
					foreach ( $this->pickup_slot_fields() as $pickup_field ) {
						$pickup_value = $values[ $pickup_field['id'] ] ?? '';

						if ( ! self::is_blank( $pickup_value ) ) {
							continue;
						}

						$valid = false;

						// Already reported by the per-field loop above — do not repeat the same
						// failure as a second notice (measured duplication, see method docblock).
						if ( ! isset( $blank_required_ids[ $pickup_field['id'] ] ) ) {
							$supplied = self::supplied_required_message( $pickup_field );

							/*
							 * Its OWN wording, deliberately not the per-field message (#327):
							 * this branch fires precisely when the field's own condition-spec
							 * did NOT match the chosen method, so it is the one that can say
							 * why a point is needed at all. Both dropped «значение поля» —
							 * the control here is a button, and there is no field to fill in.
							 * A plugin-supplied message still wins, because it is a statement
							 * about the field rather than about this code path.
							 */
							$this->add_error(
								'' !== $supplied
									? $supplied
									: __(
										'Для этого способа доставки нужно выбрать пункт выдачи заказов.',
										'woodev-plugin-framework'
									)
							);
						}
					}
				}
			}

			return $valid;
		}

		/**
		 * Every field declared as a pickup slot ({@see Field::pickup_slot()}), in declaration
		 * order.
		 *
		 * ONE SLOT IS A CONFIGURATION OUTCOME HERE, NOT AN ASSUMPTION (issue #325). Every
		 * plugin in production today declares exactly one, so this list has exactly one entry
		 * — but nothing in this method, in {@see validate()}'s backstop above, or in
		 * `checkout-field-classic.js`'s own `placeSlots()` (which has always mounted a trigger
		 * per declared slot) depends on that. Until #325 the backstop took the FIRST slot and
		 * stopped: a second declared slot was mounted client-side and then silently never
		 * validated, which is the exact shape of a hidden "one" the issue asks to remove.
		 *
		 * WHAT IS STILL SINGLE, DELIBERATELY, AND WHERE IT WOULD BE EXTENDED. A multi-package
		 * cart is NOT built (operator decision, 16.08.2026 — YAGNI, no consumer), and two
		 * places genuinely still carry one package's worth of state. Both are named here so
		 * the next reader recognises an accepted limit rather than an oversight to "fix":
		 *
		 * - {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler} persists ONE point to the
		 *   order, through the single logical field id the plugin wired. A package dimension
		 *   would be an addition to that key map, not a change to this layer.
		 * - {@see \Woodev\Framework\Shipping\Pickup\Pickup_Selection}'s session map is keyed
		 *   `[locality][type]`, which is already parameterised and already holds many entries
		 *   at once; a package would be a third key there, and the eviction cap it already
		 *   applies would carry over unchanged.
		 *
		 * The filter below is the boundary seam the issue asks for — a domain can add, remove
		 * or reorder the slots this backstop enforces without touching the field declarations
		 * themselves. Left in place with no consumer on purpose: an extension point is not
		 * gated on someone already using it.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, array<string, mixed>> Normalized descriptors, list-indexed.
		 */
		private function pickup_slot_fields(): array {
			$slots = [];

			foreach ( $this->fields->get_fields() as $field ) {
				if ( ! empty( $field['is_pickup_slot'] ) ) {
					$slots[] = $field;
				}
			}

			/**
			 * Filters the pickup-slot fields the checkout backstop enforces.
			 *
			 * @since 2.0.2
			 *
			 * @param array<int, array<string, mixed>> $slots  Normalized field descriptors, in
			 *                                                 declaration order.
			 * @param array<string, array<string, mixed>> $fields Every normalized field descriptor,
			 *                                                    keyed by id.
			 */
			$filtered = apply_filters( 'woodev_checkout_pickup_slot_fields', $slots, $this->fields->get_fields() );

			// A filter that returns something unusable leaves the framework's own answer
			// standing — the backstop exists to BLOCK a checkout, and a malformed filter
			// return must never be the reason it silently stops doing that. Entries are
			// checked for the ONE key this backstop actually dereferences, so a half-built
			// descriptor is dropped rather than warned about mid-validation.
			if ( ! is_array( $filtered ) ) {
				return $slots;
			}

			return array_values(
				array_filter(
					$filtered,
					static function ( $slot ): bool {
						return is_array( $slot ) && isset( $slot['id'] ) && '' !== (string) $slot['id'];
					}
				)
			);
		}

		/**
		 * Determines whether a chosen shipping method value matches any of the given method ids.
		 *
		 * WooCommerce passes the method value as either `method_id` (bare, when only one instance
		 * is configured) or `method_id:instance_id` (when multiple instances exist). This helper
		 * matches both shapes so plugins only need to register the base method id.
		 *
		 * `public` (2.0.2, issue #362 pickup-required-relaxation fix):
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy::pickup_method_chosen()}
		 * reuses this SAME matcher server-side, against
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::pickup_method_ids()}, so a
		 * `hide_for_pickup` field is relaxed under the exact same "chosen method names a pickup
		 * id" rule this class already uses for pickup-slot requiredness — single-sourced rather
		 * than re-implemented.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $chosen The `chosen_shipping_method` value from checkout state.
		 * @param string[] $ids    Method ids to match against (base ids without instance suffix).
		 *
		 * @return bool True when `$chosen` is or starts with one of the given ids.
		 */
		public static function chosen_method_matches( string $chosen, array $ids ): bool {
			foreach ( $ids as $id ) {
				if ( $chosen === $id || 0 === strpos( $chosen, $id . ':' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Saves the managed field values onto the order (HPOS-safe).
		 *
		 * Persists each value under the field id as the order-meta key via
		 * {@see self::persist_field()} → {@see \Woodev_Order_Compatibility::update_order_meta()}
		 * (the only persistence path, so HPOS and classic post-meta stores are both covered).
		 * Fires a per-field and a final forward hook so plugins can react to saved data.
		 *
		 * Fields whose id is a native WooCommerce address key (starts with `billing_` or
		 * `shipping_`) are skipped — WooCommerce already persists those as core order
		 * properties; writing them again as plugin meta double-stores the value and causes
		 * drift after edits/refunds. See {@see self::is_native_wc_field()}.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Native WC address fields (`billing_*` / `shipping_*`) are skipped.
		 * @since 2.0.2 Iterates {@see self::effective_fields()} instead of
		 *              {@see Checkout_Fields::get_fields()} directly. A Location-Provider
		 *              field's Rule 7b fan-out only ever produces `billing_*`/`shipping_*`
		 *              ids, so {@see self::is_native_wc_field()} still skips every variant
		 *              here — this loop never persists plugin meta for one (issue #458).
		 *
		 * @param \WC_Order|int        $order  order object or id to save onto
		 * @param array<string, mixed> $values clean values keyed by field id
		 *
		 * @return void
		 */
		public function save( $order, array $values ): void {
			foreach ( $this->effective_fields() as $id => $field ) {
				if ( ! array_key_exists( $id, $values ) ) {
					continue;
				}

				// Skip native WC address fields — WooCommerce already persists these as core
				// order properties; adding our own meta would double-store and cause drift.
				if ( $this->is_native_wc_field( $id ) ) {
					continue;
				}

				$value = $values[ $id ];

				$this->persist_field( $order, $id, $value );

				/**
				 * Fires after a single checkout field value is saved to the order.
				 *
				 * @since 1.5.0
				 *
				 * @param \WC_Order|int $order the order saved onto
				 * @param string        $id    the field id (also the order-meta key)
				 * @param mixed         $value the saved value
				 */
				do_action( $this->hook( 'checkout_field_saved' ), $order, $id, $value );
			}

			/**
			 * Fires after all managed checkout fields are saved to the order.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order|int        $order  the order saved onto
			 * @param array<string, mixed> $values the saved values keyed by field id
			 */
			do_action( $this->hook( 'checkout_data_saved' ), $order, $values );
		}

		/**
		 * Runs posted data through the full sanitize → validate → save pipeline.
		 *
		 * Returns `false` without saving when validation blocks checkout, so the caller
		 * can abort the order. On success the values are persisted and a final forward
		 * hook fires.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Builds a `$state` map from the `$posted` data and threads it through
		 *              `validate()` for consistent conditional-required evaluation at save time.
		 *
		 * @param array<string, mixed> $posted raw posted data (e.g. `$_POST`)
		 * @param \WC_Order|int        $order  order object or id to save onto
		 *
		 * @return bool true when the data was valid and saved; false when checkout is blocked
		 */
		public function process( array $posted, $order ): bool {
			$values = $this->sanitize_posted_data( $posted );
			$state  = [
				'chosen_shipping_method' => self::normalize_method_id( wc_clean( (string) wp_unslash( $posted['shipping_method'][0] ?? '' ) ) ),
				'country'                => wc_clean( (string) wp_unslash( $posted['billing_country'] ?? '' ) ),
			];

			if ( ! $this->validate( $values, $state ) ) {
				return false;
			}

			$this->save( $order, $values );

			/**
			 * Fires after posted checkout data is sanitized, validated and saved.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order|int        $order  the order saved onto
			 * @param array<string, mixed> $values the saved values keyed by field id
			 */
			do_action( $this->hook( 'checkout_processed' ), $order, $values );

			return true;
		}

		/**
		 * Builds a namespaced forward-hook name.
		 *
		 * @since 1.5.0
		 *
		 * @param string $name bare hook suffix
		 *
		 * @return string the full hook name, e.g. `woodev_shipping_{prefix}_{name}`
		 */
		private function hook( string $name ): string {
			$prefix = '' !== $this->hook_prefix ? $this->hook_prefix . '_' : '';

			return 'woodev_shipping_' . $prefix . $name;
		}

		/**
		 * Adds a WooCommerce error notice when one is available.
		 *
		 * @since 1.5.0
		 *
		 * @param string $message the error message
		 *
		 * @return void
		 */
		private function add_error( string $message ): void {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Determines whether a value counts as blank for validation.
		 *
		 * Strings are blank when they trim to empty; everything else is blank only when
		 * `null` or an empty array. A literal `'0'` is therefore NOT blank.
		 *
		 * @since 1.5.0
		 *
		 * @param mixed $value the value to test
		 *
		 * @return bool
		 */
		private static function is_blank( $value ): bool {
			if ( is_string( $value ) ) {
				return '' === trim( $value );
			}

			return null === $value || [] === $value;
		}

		/**
		 * Resolves the label to use in a generated checkout error message for a descriptor.
		 *
		 * Falls back from the dedicated `error_label` — a messages-only label independent
		 * of the visual `label`, see {@see Field::set_error_label()} — to the visual
		 * `label`, and only as a last resort to the raw field `id`. A field whose visible
		 * control is not its own native input (e.g. a hidden pickup-point field driven by
		 * a "Choose pickup point" button) legitimately has an empty visual `label`; falling
		 * straight to `id` there is what showed the buyer a raw field key (#299, #134).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function message_label( array $field ): string {
			$error_label = (string) ( $field['error_label'] ?? '' );

			if ( '' !== $error_label ) {
				return $error_label;
			}

			$label = (string) ( $field['label'] ?? '' );

			return '' !== $label ? $label : (string) $field['id'];
		}

		/**
		 * Builds the "you must supply this" error message for a descriptor.
		 *
		 * Three cases, in order:
		 *
		 * 1. The plugin supplied the whole sentence ({@see Field::set_required_message()})
		 *    — used verbatim. The framework's own nouns are carrier-neutral by default
		 *    and a carrier's may not be (Почта РФ has отделения, not пункты выдачи).
		 * 2. The field's visible control is a BUTTON, not its own input
		 *    (`is_pickup_slot`) — «Вы не выбрали пункт выдачи заказов.». Such a field
		 *    has no value to specify and, since #274/#323, not even a visible caption:
		 *    a message telling the buyer to fill in a field sends them looking for an
		 *    input that is not on the page (#327, found on the rig).
		 * 3. Anything else — the generic «Укажите значение поля «%s».», which is
		 *    accurate for a field the customer really does type into.
		 *
		 * #299 had tried to serve cases 2 and 3 with ONE template, widening the verb
		 * from "Заполните" to "Укажите". That was not enough: the rest of the sentence
		 * («значение поля») still describes an input, which is what #327 reported.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Label resolution delegated to {@see message_label()} (adds `error_label`).
		 * @since 2.0.2 Wording changed from "Заполните" to "Укажите" so the shared template
		 *              also fits button-driven fields, not just typed inputs (#299).
		 * @since 2.0.2 Button-driven fields get their own message instead of the shared
		 *              template, and a plugin can replace it wholesale (#327).
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function required_message( array $field ): string {
			$supplied = self::supplied_required_message( $field );

			if ( '' !== $supplied ) {
				return $supplied;
			}

			if ( ! empty( $field['is_pickup_slot'] ) ) {
				return __( 'Вы не выбрали пункт выдачи заказов.', 'woodev-plugin-framework' );
			}

			/* translators: %s: checkout field label */
			return sprintf( __( 'Укажите значение поля «%s».', 'woodev-plugin-framework' ), self::message_label( $field ) );
		}

		/**
		 * The plugin-supplied whole-message override for a descriptor, or `''`.
		 *
		 * Read by both paths that can report a missing pickup point — the per-field
		 * required loop (through {@see required_message()}) and the independent
		 * backstop in {@see validate()} — because the override is a statement about the
		 * FIELD, not about whichever code path happened to catch it (#327).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function supplied_required_message( array $field ): string {
			return (string) ( $field['required_message'] ?? '' );
		}

		/**
		 * Builds the "invalid value" error message for a descriptor: the plugin's own whole
		 * sentence ({@see Field::set_invalid_message()}) when it supplied one, the
		 * framework's template otherwise.
		 *
		 * The override exists because this message has the SAME defect its sibling had
		 * (#327, fixed there; #328 for this one): for a field whose visible control is a
		 * BUTTON, «Поле «Пункт выдачи» заполнено некорректно.» sends the customer looking
		 * for an input that is not on the page. The framework deliberately ships NO
		 * button-specific default of its own here, and that asymmetry with
		 * {@see self::required_message()} is the decision, not an omission: "you did not
		 * choose a point" is a fact the framework knows, while for an already-chosen point
		 * "filled in incorrectly" most likely means "that point is unavailable" — a
		 * different statement only the domain can vouch for. So the framework owns when the
		 * message appears and the plugin owns the words (#328's own recommendation).
		 *
		 * Only reachable through a plugin's {@see Field::set_validate_callback()} returning
		 * a bare `false`: a callback returning a `WP_Error` supplies its own message, which
		 * {@see self::validate()} uses instead of ever calling this.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Label resolution delegated to {@see message_label()} (adds `error_label`).
		 * @since 2.0.2 A plugin can replace the whole sentence (#328).
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function invalid_message( array $field ): string {
			$supplied = (string) ( $field['invalid_message'] ?? '' );

			if ( '' !== $supplied ) {
				return $supplied;
			}

			/* translators: %s: checkout field label */
			return sprintf( __( 'Поле «%s» заполнено некорректно.', 'woodev-plugin-framework' ), self::message_label( $field ) );
		}

		/**
		 * Returns the chosen shipping method for the first package from the posted data.
		 *
		 * WooCommerce posts `shipping_method` as a zero-indexed array keyed by package index;
		 * we take index 0 as the primary method. WooCommerce verifies the checkout nonce
		 * before its checkout hooks fire, so no separate nonce check is performed here.
		 *
		 * @since 2.0.2
		 *
		 * @return string bare method id (the `:instance_id` suffix is stripped), or empty string
		 */
		private function chosen_shipping_method(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before its checkout hooks fire; values are cleaned in sanitize_posted_data().
			$method = wc_clean( (string) wp_unslash( $_POST['shipping_method'][0] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

			return self::normalize_method_id( (string) $method );
		}

		/**
		 * Strips the `:instance_id` suffix from a WooCommerce shipping-method value.
		 *
		 * WooCommerce posts the chosen method as `method_id:instance_id` whenever the zone has
		 * an instance id (the usual case). Condition-specs and the requires-pickup list are
		 * declared against the BARE method id, and the JS store's `setChosenMethod()` strips the
		 * suffix client-side — so every server entry point must normalise identically or the same
		 * spec evaluates differently depending on which path validated it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $method Raw posted shipping-method value.
		 *
		 * @return string Bare method id, or empty string.
		 */
		private static function normalize_method_id( string $method ): string {
			return explode( ':', $method )[0];
		}

		/**
		 * Returns the billing country from the posted data.
		 *
		 * WooCommerce verifies the checkout nonce before its checkout hooks fire, so no
		 * separate nonce check is performed here.
		 *
		 * @since 2.0.2
		 *
		 * @return string sanitized ISO 2-letter country code, or empty string
		 */
		private function posted_country(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before its checkout hooks fire; values are cleaned in sanitize_posted_data().
			return wc_clean( (string) wp_unslash( $_POST['billing_country'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

		/**
		 * Determines whether a field id belongs to the native WooCommerce address namespace.
		 *
		 * WooCommerce persists `billing_*` and `shipping_*` fields as core order properties
		 * via its own checkout pipeline. Writing them again as plugin order-meta would
		 * double-store the value and cause silent drift after order edits or refunds. This
		 * heuristic covers the two address namespaces that WC always owns; plugin-defined
		 * ids (e.g. `carrier_pickup_point`, `pvz_id`) never start with these prefixes.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id field id to test
		 *
		 * @return bool true when WooCommerce already persists this field natively
		 */
		protected function is_native_wc_field( string $id ): bool {
			return 0 === strpos( $id, 'billing_' ) || 0 === strpos( $id, 'shipping_' );
		}


		/**
		 * The checkout section(s) a Location-Provider field's cascade attaches to (Rule
		 * 7b, `docs-internal/AGENT-RULES.md`): a store forcing shipping to the billing
		 * address (`woocommerce_ship_to_destination === 'billing_only'`) attaches ONLY to
		 * `billing` — WooCommerce drops the shipping fieldset from the checkout form
		 * entirely in that mode ({@see \WC_Checkout::maybe_skip_fieldset()}), so a field
		 * left on `shipping` alone would never render. Every other store configuration
		 * attaches to BOTH `billing` and `shipping` — not "whichever one determines
		 * delivery" — because WooCommerce still renders both fieldsets and lets the
		 * customer type two genuinely different addresses into them.
		 *
		 * Deliberately reads `wc_ship_to_billing_address_only()` rather than
		 * reimplementing its `'billing_only' === get_option( 'woocommerce_ship_to_destination' )`
		 * check — the same reasoning
		 * {@see \Woodev\Framework\Shipping\Pickup\Address_Target::resolve()} documents for
		 * its own, narrower question (WHERE to write one chosen address, versus this
		 * method's WHICH section(s) a field belongs to at all — do not derive this method
		 * from that one; see that class's own docblock for why the two never fully agree).
		 *
		 * @since 2.0.2
		 *
		 * @return string[] `[ 'billing' ]` or `[ 'billing', 'shipping' ]`.
		 */
		protected static function location_target_sections(): array {
			return wc_ship_to_billing_address_only() ? [ 'billing' ] : [ 'billing', 'shipping' ];
		}

		/**
		 * Derives a Location-Provider field's WooCommerce-convention suffix by stripping a
		 * leading `billing_`/`shipping_` prefix, or returns the id unchanged when neither
		 * prefix is present.
		 *
		 * Used to reconstruct the sibling id for the OTHER address section: `shipping_city`
		 * and a bare `city` both yield the same `city` suffix, so
		 * {@see self::location_field_variants()} builds the same `billing_city` /
		 * `shipping_city` pair regardless of which convention the host plugin declared the
		 * field id with.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id field id as declared via {@see Field::source_location()}.
		 *
		 * @return string
		 */
		protected static function strip_address_prefix( string $id ): string {
			foreach ( [ 'billing_', 'shipping_' ] as $prefix ) {
				if ( 0 === strpos( $id, $prefix ) ) {
					return substr( $id, strlen( $prefix ) );
				}
			}

			return $id;
		}

		/**
		 * Expands one Location-Provider field descriptor into the WC-keyed variant(s) it
		 * actually attaches to ({@see self::location_target_sections()}), each carrying its
		 * own derived `id` and `section`. Every other descriptor key (`type`, `label`,
		 * `required`, callables, …) is carried through unchanged onto every variant.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $id    the field's declared id.
		 * @param array<string, mixed> $field its normalized descriptor.
		 *
		 * @return array<string, array<string, mixed>> one or two descriptors keyed by their
		 *         derived id.
		 */
		protected static function location_field_variants( string $id, array $field ): array {
			$suffix   = self::strip_address_prefix( $id );
			$variants = [];

			foreach ( self::location_target_sections() as $section ) {
				$variant_id              = $section . '_' . $suffix;
				$variant                 = $field;
				$variant['id']           = $variant_id;
				$variant['section']      = $section;
				$variants[ $variant_id ] = $variant;
			}

			return $variants;
		}

		/**
		 * The managed field descriptors keyed by the id WooCommerce actually keys checkout
		 * data with.
		 *
		 * A Location-Provider field ({@see Field::source_location()}) is fanned across the
		 * section(s) Rule 7b attaches it to ({@see self::location_target_sections()});
		 * every other field passes through unchanged, keyed by its own declared id.
		 * {@see self::inject()}, {@see self::sanitize_posted_data()}, {@see self::validate()},
		 * {@see self::save()}, {@see self::handle_checkout_get_value()} and
		 * {@see self::guard_native_field_conflicts()} all key off this rather than
		 * {@see Checkout_Fields::get_fields()} directly, so WooCommerce's checkout array,
		 * posted-data handling and native-field bookkeeping agree on the SAME ids the
		 * browser actually submits.
		 *
		 * ID PRECEDENCE (issue #458 round 4). Two descriptors can claim the SAME id, and the
		 * ordinary case is not an author error at all: the §8 demo declares `billing_state`
		 * directly while a `source_location( 'region' )` field declared as `shipping_state`
		 * is fanned by Rule 7b into a `billing_state` variant too. The FRAMEWORK creates that
		 * collision — the plugin never asked for the second claim — so it is resolved by a
		 * documented rule and reported nowhere. It was briefly a `_doing_it_wrong()` (round 3);
		 * that was wrong twice over: the notice fired on a legitimate configuration, and
		 * `_doing_it_wrong()` states "the developer called this incorrectly", which nobody did.
		 * It also broke five integration tests, which is how the mistake surfaced.
		 *
		 * The rule, in order:
		 *
		 * 1. **A directly declared descriptor beats a Rule 7b fan-out variant**, whatever the
		 *    registration order. A direct declaration names that exact id on purpose; a
		 *    fan-out variant is derived by the framework, and deriving a claim must never
		 *    silently displace one an author actually wrote. This is also the behaviour that
		 *    shipped before round 3 (a direct descriptor won via unconditional assignment) —
		 *    it is now order-independent rather than incidental.
		 * 2. **Between two claims of the same kind, the FIRST registration wins**, matching
		 *    the order `Checkout_Fields::get_fields()` preserves and the tie-break precedent in
		 *    {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::collect_all_provider_fields()}.
		 *    Two fan-out variants can only collide when two `source_location()` fields carry
		 *    the same address-prefix-stripped suffix (e.g. `billing_city` and `shipping_city`
		 *    both declared with `source_location()`), which Rule 7b makes redundant by
		 *    definition.
		 *
		 * The losing claim keeps the WINNER's position in the returned array, so the checkout
		 * field ORDER never depends on which descriptor won.
		 *
		 * KNOWN CONSEQUENCE, deliberately not worked around here: when a direct declaration
		 * takes the id the CURRENTLY ACTIVE address column would have used, the location
		 * cascade's only surviving variant is the other column's, so `location-cascade.js`
		 * puts the one live cascade there (Rule 7c). That is a configuration the plugin
		 * created by claiming one id twice, and the cure is to stop double-claiming it — not
		 * for the framework to overrule an explicit declaration.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array<string, mixed>>
		 */
		protected function effective_fields(): array {
			$effective   = [];
			$declared_by = [];

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$is_location = 'location' === ( $field['source_kind'] ?? null );
				$variants    = $is_location
					? self::location_field_variants( $id, $field )
					: [ $id => $field ];

				foreach ( $variants as $variant_id => $variant ) {
					// Rule 1: a fan-out variant never displaces an existing claim, and a direct
					// declaration never displaces another DIRECT one (rule 2, first wins).
					if ( isset( $effective[ $variant_id ] ) && ( $is_location || 'direct' === $declared_by[ $variant_id ] ) ) {
						continue;
					}

					// Assigning over an existing key keeps that key's original position, so a
					// direct declaration arriving after the fan-out variant it displaces
					// inherits its slot rather than moving to the end.
					$effective[ $variant_id ]   = $variant;
					$declared_by[ $variant_id ] = $is_location ? 'fan-out' : 'direct';
				}
			}

			return $effective;
		}

		/**
		 * Persists a single field value onto the order via HPOS-safe meta storage.
		 *
		 * Extracted as a protected seam so subclasses (and unit-test spies) can intercept
		 * persistence without depending on {@see \Woodev_Order_Compatibility} in test contexts.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order|int $order order object or id to persist onto
		 * @param string        $id    field id used as the order-meta key
		 * @param mixed         $value the value to persist
		 *
		 * @return void
		 */
		protected function persist_field( $order, string $id, $value ): void {
			\Woodev_Order_Compatibility::update_order_meta( $order, $id, $value );
		}
	}

endif;

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

			if ( '*' !== $value ) {
				return $value;
			}

			$fields = $this->fields->get_fields();

			return isset( $fields[ $input ] ) ? '' : $value;
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
		 * (`location-typeahead.js`, `location-cascade.js`) — but ONLY when their
		 * files actually exist on disk ({@see self::enqueue_script_if_built()}):
		 * those files ship in a later PR block (PR-C), so this handler is wired now
		 * with a guard that can never 404, and needs zero further code changes once
		 * the files land.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Enqueues the location-provider client scripts, guarded on
		 *              their files existing on disk (location-provider layer Task 9).
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
				$this->location_service()
			) )->build( $this->fields );
			$config['i18n']  = [
				'required'    => __( 'Заполните обязательное поле.', 'woodev-plugin-framework' ),
				'placeholder' => $this->placeholder_label(),
			];

			if ( isset( $config['location'] ) ) {
				$typeahead_built = $this->enqueue_script_if_built( 'woodev-location-typeahead', 'js/frontend/location-typeahead.js', [] );

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
							]
						)
					)
				);
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

			foreach ( array_keys( $this->fields->get_fields() ) as $id ) {
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
		 * The fully-merged result is passed through the forward `..._checkout_fields`
		 * filter so the host plugin can refine field args further.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Fields are grouped by their own `section`; existing WC args
		 *              are preserved (conservative merge); options-kind root fields
		 *              have their source() called to pre-fill the options map.
		 *
		 * @param array<string, mixed> $checkout_fields WC checkout fields, keyed by section
		 * @param string               $section         unused override kept for BC; per-field
		 *                                              `section` key is the primary path
		 *
		 * @return array<string, mixed>
		 */
		public function inject( array $checkout_fields, string $section = 'order' ): array {
			$country = $this->current_country();

			foreach ( $this->fields->get_fields() as $id => $field ) {
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
				$existing_wc_args                         = $checkout_fields[ $field_section ][ $id ] ?? [];
				$checkout_fields[ $field_section ][ $id ] = array_merge(
					is_array( $existing_wc_args ) ? $existing_wc_args : [],
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
		 *
		 * @param array<string, mixed> $posted raw posted data (e.g. `$_POST`)
		 *
		 * @return array<string, mixed> clean values keyed by field id
		 */
		public function sanitize_posted_data( array $posted ): array {
			$clean = [];

			foreach ( $this->fields->get_fields() as $id => $field ) {
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
		 * the declared pickup methods AND the `is_pickup_slot` field value is blank, checkout is
		 * blocked regardless of that field's condition-spec.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `$state` parameter for conditional-required (A2) evaluation.
		 * @since 2.0.2 Added independent pickup backstop guard.
		 *
		 * @param array<string, mixed> $values clean values keyed by field id
		 * @param array<string, mixed> $state  flat checkout-state map, e.g.
		 *                                     `['chosen_shipping_method' => 'carrier_pickup', 'country' => 'RU']`
		 *
		 * @return bool true when every field is valid; false when any field blocks checkout
		 */
		public function validate( array $values, array $state = [] ): bool {
			$valid = true;

			foreach ( $this->fields->get_fields() as $id => $field ) {
				$value    = $values[ $id ] ?? '';
				$required = Checkout_Condition::is_required( $field['required'], $state );

				if ( $required && self::is_blank( $value ) ) {
					$this->add_error( self::required_message( $field ) );
					$valid = false;
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
					$pickup_field = null;

					foreach ( $this->fields->get_fields() as $field ) {
						if ( ! empty( $field['is_pickup_slot'] ) ) {
							$pickup_field = $field;
							break;
						}
					}

					if ( null !== $pickup_field ) {
						$pickup_value = $values[ $pickup_field['id'] ] ?? '';

						if ( self::is_blank( $pickup_value ) ) {
							$this->add_error(
								sprintf(
									/* translators: %s: pickup field label */
									__( 'Для доставки в пункт выдачи выберите значение поля «%s».', 'woodev-plugin-framework' ),
									'' !== (string) $pickup_field['label'] ? (string) $pickup_field['label'] : (string) $pickup_field['id']
								)
							);
							$valid = false;
						}
					}
				}
			}

			return $valid;
		}

		/**
		 * Determines whether a chosen shipping method value matches any of the given method ids.
		 *
		 * WooCommerce passes the method value as either `method_id` (bare, when only one instance
		 * is configured) or `method_id:instance_id` (when multiple instances exist). This helper
		 * matches both shapes so plugins only need to register the base method id.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $chosen The `chosen_shipping_method` value from checkout state.
		 * @param string[] $ids    Method ids to match against (base ids without instance suffix).
		 *
		 * @return bool True when `$chosen` is or starts with one of the given ids.
		 */
		private static function chosen_method_matches( string $chosen, array $ids ): bool {
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
		 *
		 * @param \WC_Order|int        $order  order object or id to save onto
		 * @param array<string, mixed> $values clean values keyed by field id
		 *
		 * @return void
		 */
		public function save( $order, array $values ): void {
			foreach ( $this->fields->get_fields() as $id => $field ) {
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
		 * Builds the default "required field" error message for a descriptor.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function required_message( array $field ): string {
			$label = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['id'];

			/* translators: %s: checkout field label */
			return sprintf( __( 'Заполните поле «%s».', 'woodev-plugin-framework' ), $label );
		}

		/**
		 * Builds the default "invalid value" error message for a descriptor.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $field normalized field descriptor
		 *
		 * @return string
		 */
		private static function invalid_message( array $field ): string {
			$label = '' !== (string) $field['label'] ? (string) $field['label'] : (string) $field['id'];

			/* translators: %s: checkout field label */
			return sprintf( __( 'Поле «%s» заполнено некорректно.', 'woodev-plugin-framework' ), $label );
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

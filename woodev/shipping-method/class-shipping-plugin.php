<?php
/**
 * Woodev Shipping Plugin
 *
 * Base plugin class for WooCommerce shipping plugins.
 * Provides infrastructure for shipping methods, pickup points, checkout integration,
 * order export, tracking, webhooks, and admin functionality.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Plugin' ) ) :

	abstract class Shipping_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

		/** @var array optional associative array of shipping method id */
		private array $methods = [];

		/** @var array supported feature flags */
		private array $supports = [];

		/** @var array accepted currency codes */
		private array $currencies = [];

		/** @var array accepted country codes */
		private array $countries = [];

		/** @var string|null integration class name */
		private ?string $integration_class = null;

		/** @var Map\Map_Provider_Registry|null lazily-built map-provider registry */
		private ?Map\Map_Provider_Registry $map_provider_registry = null;

		/** @var Location\Location_Service|null lazily-built location service façade */
		private ?Location\Location_Service $location_service = null;

		/** @var Settings\Export_Settings|null lazily-built «Выгрузка» settings of this carrier (#1007) */
		private ?Settings\Export_Settings $export_settings = null;

		/**
		 * Initializes the shipping plugin.
		 *
		 * @since 1.5.0
		 *
		 * @param string $id plugin id
		 * @param string $version plugin version
		 * @param array  $args {
		 *      Plugin configuration arguments.
		 *
		 *     @type string[] $supports          Plugin-scoped feature flags consumed by the host plugin (no framework-side consumer yet)
		 *     @type string[] $currencies         Accepted currency codes
		 *     @type string[] $countries          Accepted country codes
		 *     @type string   $integration_class  WC_Integration class name for settings
		 *     @type string   $map_provider       Map provider id, e.g. 'yandex'
		 * }
		 */
		public function __construct( string $id, string $version, array $args = [] ) {

			parent::__construct( $id, $version, $args );

			$args = wp_parse_args(
				$args,
				[
					'supports'   => [],
					'currencies' => [],
					'countries'  => [],
				]
			);

			$this->supports   = (array) $args['supports'];
			$this->currencies = (array) $args['currencies'];
			$this->countries  = (array) $args['countries'];

			$this->includes();
			$this->add_hooks();
		}

		/**
		 * Builds the shipping REST API handler.
		 *
		 * Mirrors {@see \Woodev_Payment_Gateway_Plugin::init_rest_api_handler()}: the
		 * shipping module ships its own REST bootstrap whose namespace is the plugin's
		 * id-dasherized slug and whose controllers are host-supplied (none by default),
		 * so the framework mints no installed-site REST namespace literal.
		 *
		 * @since 1.5.0
		 *
		 * @see \Woodev_Plugin::init_rest_api_handler()
		 */
		protected function init_rest_api_handler() {

			require_once $this->get_shipping_framework_path() . '/rest-api/class-shipping-rest-api.php';

			$this->rest_api_handler = new Rest_Api\Shipping_REST_API( $this );
		}

		/**
		 * Gets the shipping method class names for this plugin.
		 *
		 * @since 1.5.0
		 *
		 * @return class-string<Shipping_Method>[]
		 */
		abstract protected function get_shipping_method_classes(): array;

		/**
		 * Gets the carrier API instance.
		 * This is a stub method which must be overridden
		 *
		 * @return null|Api\Shipping_API
		 * @since 1.5.0
		 */
		abstract public function get_api(): ?Api\Shipping_API;

		/**
		 * Includes required framework files.
		 *
		 * @since 1.5.0
		 */
		private function includes(): void {

			$path = $this->get_shipping_framework_path();

			// exceptions
			require_once $path . '/exceptions/class-shipping-exception.php';

			// helper
			require_once $path . '/class-shipping-helper.php';

			// API interfaces + base
			require_once $path . '/api/interface-shipping-api.php';
			require_once $path . '/api/class-abstract-shipping-api.php';

			// base shipping method and specializations
			require_once $path . '/class-shipping-rate.php';
			require_once $path . '/class-shipping-method.php';
			require_once $path . '/class-shipping-method-courier.php';
			require_once $path . '/class-shipping-method-pickup.php';
			require_once $path . '/class-shipping-method-postal.php';

			// settings
			require_once $path . '/settings/class-shipping-integration.php';

			// settings tools (card #598/#600: the settings-tab "tools" tab framework)
			require_once $path . '/settings/class-tool-result.php';
			require_once $path . '/settings/class-shipping-tool.php';
			require_once $path . '/settings/class-shipping-tools-registry.php';

			// «Доставка» tab registrar (Task 4; issue #362): the tab + «Поля»/«Карта»
			// stub handlers it composes with the location layer's handler. Required
			// unconditionally, same reasoning as the location-provider block below —
			// the registrar stays inert until declare_shipping_plugin() is called.
			require_once $path . '/checkout/class-checkout-field-environment.php';
			require_once $path . '/checkout/class-checkout-field-settings.php';
			// Checkout field policy (Task 6; issue #362): applies the «Поля» settings to
			// the real checkout via woocommerce_get_country_locale + woocommerce_checkout_fields.
			// Required unconditionally, same reasoning as the tab registrar above — it stays
			// inert until Shipping_Settings_Tab::register() boots it.
			require_once $path . '/checkout/class-checkout-field-policy.php';
			require_once $path . '/pickup/class-pickup-map-settings.php';
			require_once $path . '/settings/class-shipping-settings-tab.php';
			require_once $path . '/settings/class-export-settings.php';

			// checkout field definitions + presets
			require_once $path . '/checkout/class-field.php';
			require_once $path . '/checkout/class-checkout-condition.php';
			require_once $path . '/checkout/class-checkout-config.php';
			require_once $path . '/checkout/class-phone-mask-patterns.php';
			require_once $path . '/checkout/presets/class-dependent-select.php';
			require_once $path . '/checkout/presets/class-pickup-field.php';

			// pickup-point map provider interface + registry (no default provider ships)
			require_once $path . '/map/interface-map-provider.php';
			require_once $path . '/map/class-map-provider-registry.php';
			require_once $path . '/map/class-embedded-map-provider.php';
			require_once $path . '/map/class-yandex-map-provider.php';

			// location provider layer (Tasks 1-5): neutral record/key/scope, provider
			// contract, the registry with its activation gate + store setting, the dual
			// customer-location store, and the mandatory per-plugin adapter contract +
			// its lazy session-cached resolution. The registry itself stays completely
			// inert until a plugin declares need (see add_hooks() below) — loading
			// these files unconditionally costs nothing, the same way
			// map/interface-map-provider.php above is always loaded even though no
			// default map provider ships.
			require_once $path . '/location/class-locality-key.php';
			require_once $path . '/location/class-location-record.php';
			require_once $path . '/location/class-location-scope.php';
			require_once $path . '/location/class-location-provider-exception.php';
			require_once $path . '/location/interface-location-provider.php';
			require_once $path . '/location/abstract-location-provider.php';
			require_once $path . '/location/class-location-settings.php';
			require_once $path . '/location/class-location-provider-registry.php';
			require_once $path . '/location/class-customer-location-store.php';
			require_once $path . '/location/interface-location-adapter.php';
			require_once $path . '/location/class-location-resolution-cache.php';
			require_once $path . '/location/class-location-service.php';

			// popular settlements (default-locality suggestion + staleness verification)
			require_once $path . '/location/class-popular-settlement-entry.php';
			require_once $path . '/location/class-popular-settlement-store.php';
			require_once $path . '/location/class-popular-settlements-tools.php';
			require_once $path . '/location/class-popular-settlement-verification.php';
			require_once $path . '/location/class-popular-settlement-verifier.php';

			// Task 7: the bundled DaData provider — the registry's own
			// bundled_provider_classes() class_exists()-guards its FQCN, so this
			// require can sit anywhere before init/collect() (as long as it is
			// loaded at all); kept adjacent to the rest of the location block.
			require_once $path . '/location/providers/class-dadata-api-request.php';
			require_once $path . '/location/providers/class-dadata-api-response.php';
			require_once $path . '/location/providers/class-dadata-api-client.php';
			require_once $path . '/location/providers/class-dadata-provider.php';

			// pickup models
			require_once $path . '/pickup/class-pickup-point.php';

			// pickup selection engine (SP-5): scope + query + constraint checking, the
			// handler that composes them, and the address-target value object the
			// checkout side of pickup selection reads.
			require_once $path . '/pickup/interface-selection-scope.php';
			require_once $path . '/pickup/class-provider-selection-scope.php';
			require_once $path . '/pickup/interface-point-source.php';
			require_once $path . '/pickup/interface-location-aware-point-source.php';
			require_once $path . '/pickup/abstract-bulk-point-source.php';
			require_once $path . '/pickup/class-point-query.php';
			require_once $path . '/pickup/class-constraint-checker.php';
			require_once $path . '/pickup/class-selection-result.php';
			require_once $path . '/pickup/class-pickup-selection.php';
			require_once $path . '/pickup/class-address-target.php';
			require_once $path . '/pickup/class-pickup-handler.php';

			// checkout fields + handler backbone
			require_once $path . '/checkout/class-checkout-fields.php';
			require_once $path . '/checkout/class-checkout-handler.php';

			// order meta handler + abstract shipment/tracking/webhook handlers
			require_once $path . '/order/class-shipping-order-handler.php';
			require_once $path . '/order/class-action-result.php';
			require_once $path . '/order/class-order-lock.php';
			require_once $path . '/order/class-export-retry.php';
			require_once $path . '/order/class-carrier-cancel.php';
			require_once $path . '/order/class-export-queue.php';
			require_once $path . '/order/class-order-automation.php';
			require_once $path . '/order/abstract-shipment-handler.php';
			require_once $path . '/order/abstract-tracking-handler.php';
			require_once $path . '/order/abstract-webhook-handler.php';

			// canonical delivery-status enum (SP-10 increment 2, spec D4)
			require_once $path . '/order/class-delivery-status.php';

			// delivery-status sync freshness — the last-updated/next-update seam (SP-10
			// spec D9, #828)
			require_once $path . '/order/class-delivery-sync-status.php';

			// admin bootstrap + order admin handler
			require_once $path . '/admin/class-shipping-admin.php';
			require_once $path . '/admin/class-shipping-admin-order.php';

			// shipping orders registry (SP-10 increment 1): carrier descriptor, the
			// registry aggregator, and the HPOS-safe scope query. Required unconditionally,
			// same reasoning as the location-provider block above — the registry stays
			// inert until a carrier plugin calls register_provider().
			require_once $path . '/admin/orders/class-orders-provider.php';
			require_once $path . '/admin/orders/class-orders-registry.php';
			require_once $path . '/admin/orders/class-export-queue-notice.php';
			// The carrier marker contract (#967): loaded with the registry it reads providers from.
			require_once $path . '/order/class-order-marker.php';
			require_once $path . '/admin/orders/class-orders-query.php';
			require_once $path . '/admin/orders/class-orders-id-resolver.php';
			require_once $path . '/admin/orders/class-order-edit-lock.php';
			require_once $path . '/admin/orders/class-order-actions.php';
			require_once $path . '/admin/orders/class-order-row-builder.php';

			// admin order wizard (#710): the create / update / load service and its payload check (#968)
			require_once $path . '/admin/orders/class-carrier-field-set.php';
			require_once $path . '/admin/orders/class-order-payload-validator.php';
			require_once $path . '/admin/orders/class-order-editor.php';
			// admin order wizard (#710): rates for a hand-built package, and its route (#965)
			require_once $path . '/admin/orders/class-admin-rate-calculator.php';

			// REST API (§8 checkout classes' server-side counterparts)
			require_once $path . '/rest-api/class-shipping-rest-api.php';
			require_once $path . '/rest-api/class-field-source-controller.php';
			require_once $path . '/rest-api/class-location-controller.php';
			require_once $path . '/rest-api/class-pickup-controller.php';
			require_once $path . '/rest-api/class-orders-controller.php';
			require_once $path . '/rest-api/class-order-editor-controller.php';
			require_once $path . '/rest-api/class-rates-controller.php';
		}

		/**
		 * Adds action and filter hooks.
		 *
		 * @since 1.5.0
		 */
		private function add_hooks(): void {

			// register shipping methods with WooCommerce
			add_filter( 'woocommerce_shipping_methods', [ $this, 'register_shipping_methods' ] );

			// render the method description (and whatever a plugin adds via the filter)
			// under the rate on the order form. This is the CLASSIC form's only seam for
			// it — WooCommerce's own template prints the label and nothing else. The
			// block form reads the same text off the WC_Shipping_Rate instead, which
			// Shipping_Method::apply_rate_attributes() fills in.
			add_action( 'woocommerce_after_shipping_rate', [ $this, 'render_rate_additional_info' ], 10, 2 );

			// register WC_Integration if configured
			if ( $this->get_integration_handler() instanceof Settings\Shipping_Integration ) {
				add_filter( 'woocommerce_integrations', [ $this, 'register_integration' ] );
			}

			// add shipping method information to the system status report
			add_action( 'woocommerce_system_status_report', [ $this, 'add_system_status_information' ] );

			// «Доставка» tab (Task 4; issue #362): every shipping plugin needs it, so
			// declare it unconditionally — same synchronous-during-add_hooks() reasoning
			// as the Location Provider declaration immediately below (it must run before
			// Shipping_Settings_Tab's own `init` priority 25 registration hook fires).
			Settings\Shipping_Settings_Tab::instance()->declare_shipping_plugin();

			// Location Provider layer (Task 3): declare need with the shared registry
			// singleton so its activation gate opens and its store setting appears.
			// Declared HERE, synchronously during add_hooks() — which the constructor
			// calls directly, not via another hook — rather than through the lazy
			// accessor pattern used for get_map_provider_registry()/get_checkout_handler()
			// below: this is a one-way DECLARATION into a registry shared across every
			// plugin in the fleet, not a per-plugin instance this class itself owns and
			// builds on demand. It must run before the registry's own `init`-time
			// collection hook fires (Location_Provider_Registry::collect(), hooked at
			// priority 20) — true for every plugin, since plugin construction happens at
			// `plugins_loaded`, always before `init`.
			if ( $this->needs_location_provider() ) {
				Location\Location_Provider_Registry::instance()->declare_needed();
			}

			// wire the host-supplied subsystems; each accessor returns null in the base,
			// so a plugin that does not supply a subsystem leaves it inert (null-guarded).
			// (Explicit null checks, not the nullsafe `?->` operator: this codebase
			// supports PHP 7.4, where `?->` is a parse error.)

			// checkout field injection + posted-data processing/save
			$checkout_handler = $this->get_checkout_handler();
			if ( null !== $checkout_handler ) {
				$checkout_handler->register();
			}

			// inbound carrier webhook REST route
			$webhook_handler = $this->get_webhook_handler();
			if ( null !== $webhook_handler ) {
				$webhook_handler->register();
			}

			// #954: the delayed export retry is carried out by WP-Cron / the Action Scheduler runner,
			// with no admin request in sight. The hook is wired here, in EVERY request of a shipping
			// plugin, so an action due while the carrier registers itself only under is_admin() — or not
			// at all, the plugin being deactivated — still reaches the registry, which then says on the
			// order that the carrier was not found instead of letting the action complete in silence.
			// add_action() ignores a second identical callback, so the registry's own wiring is harmless.
			add_action( Order\Export_Retry::HOOK, [ Admin\Orders\Orders_Registry::instance(), 'run_export_retry' ] );

			// #1007: the same reasoning for the background auto-export and the cancellation at the
			// carrier. An order changes status on the storefront (a payment gateway's callback, the
			// thank-you page) and in cron as much as in the admin, and the cancellation runs from the
			// queue — so both hooks are wired in every request, not only where the carrier registered
			// itself for the admin.
			add_action( 'woocommerce_order_status_changed', [ Admin\Orders\Orders_Registry::instance(), 'handle_order_status_changed' ], 20, 4 );
			add_action( Order\Carrier_Cancel::HOOK, [ Admin\Orders\Orders_Registry::instance(), 'run_cancel_at_carrier' ] );

			// admin suite. Shipping_Admin self-wires its admin_init/admin_menu
			// registration in its constructor, so obtaining the host instance is what
			// makes its handlers + pages live; calling register_handlers()/register_pages()
			// here would double-register and fire before admin_menu.
			if ( is_admin() ) {
				$this->get_shipping_admin();
			}

			// NOTE: the REST API handler is already initialized by the base lifecycle
			// (Woodev_Plugin::__construct() -> init_rest_api_handler()), so it is not
			// re-wired here.
		}

		/**
		 * Registers shipping methods with WooCommerce.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 #842: the class-list resolution (filter, non-array fallback,
		 *              validation) moved into {@see self::get_valid_shipping_method_classes()},
		 *              shared with {@see self::get_declared_shipping_method_ids()}; this
		 *              method's own behaviour — same filter, same fallback, same
		 *              validation, same actions, same order — is unchanged.
		 *
		 * @param array $methods existing methods
		 * @return array
		 */
		final public function register_shipping_methods( array $methods ): array {

			foreach ( $this->get_valid_shipping_method_classes() as $class ) {

				$method_id = $class::get_method_id();

				/**
				 * Fires before a shipping method is registered.
				 *
				 * @since 1.5.0
				 *
				 * @param string $method_id method ID
				 * @param string $class method class name
				 * @param Shipping_Plugin $plugin plugin instance
				 */
				do_action( 'woodev_shipping_plugin_before_register_method', $method_id, $class, $this );

				$methods[ $method_id ] = $class;

				$this->add_shipping_method( $method_id, $class );

				/**
				 * Fires after a shipping method is registered.
				 *
				 * @since 1.5.0
				 *
				 * @param string $method_id method ID
				 * @param string $class method class name
				 * @param Shipping_Plugin $plugin plugin instance
				 */
				do_action( 'woodev_shipping_plugin_after_register_method', $method_id, $class, $this );
			}

			/**
			 * Filters the final registered methods array.
			 *
			 * A return that is not an array is discarded and the methods
			 * registered so far are returned instead — this method's `array`
			 * return type makes any other return a fatal `TypeError` on every
			 * cart/checkout shipping calculation.
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A non-array return is discarded; the pre-filter methods
			 *              are returned instead of trusting the return's type.
			 *
			 * @param array $methods registered methods
			 * @param Shipping_Plugin $plugin plugin instance
			 */
			$filtered_methods = apply_filters( 'woodev_shipping_plugin_registered_methods', $methods, $this );

			return is_array( $filtered_methods ) ? $filtered_methods : $methods;
		}

		/**
		 * Resolves this plugin's own valid shipping method classes: the
		 * `woodev_shipping_plugin_method_classes`-filtered class list (falling back
		 * to the unfiltered {@see self::get_shipping_method_classes()} for a
		 * non-array return), restricted to {@see self::is_valid_shipping_method_class()}.
		 *
		 * Shared by {@see self::register_shipping_methods()} and
		 * {@see self::get_declared_shipping_method_ids()} so the two can never drift.
		 *
		 * @since 2.0.2 #842
		 *
		 * @return class-string<Shipping_Method>[]
		 */
		private function get_valid_shipping_method_classes(): array {

			/**
			 * Filters the shipping method classes before registration.
			 *
			 * A return that is not an array is discarded and the plugin's own
			 * class list is used instead. A non-array value here does not fatal —
			 * `foreach` on it is a silent no-op — but it makes every shipping
			 * method vanish from checkout with nothing in the logs, which is worse.
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A non-array return is discarded; the plugin's own class
			 *              list is used instead of trusting the return's type.
			 *
			 * @param array $method_classes shipping method class names
			 * @param Shipping_Plugin $plugin plugin instance
			 */
			$filtered_classes = apply_filters( 'woodev_shipping_plugin_method_classes', $this->get_shipping_method_classes(), $this );

			$classes = is_array( $filtered_classes ) ? $filtered_classes : $this->get_shipping_method_classes();

			return array_values( array_filter( $classes, [ $this, 'is_valid_shipping_method_class' ] ) );
		}

		/**
		 * Returns the shipping method ids this plugin DECLARES, derived statically
		 * via `Shipping_Method::get_method_id()` — no shipping method is constructed.
		 *
		 * This is the side-effect-free counterpart to {@see self::get_shipping_method_ids()},
		 * which only reflects `add_shipping_method()` calls made from
		 * {@see self::register_shipping_methods()}, and which WooCommerce runs on its
		 * own schedule (see that method's docblock) — reaching it early means
		 * constructing every shipping method now. This method never does that;
		 * it is the authoritative source for
		 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::check_method_ids_contract()}
		 * (card #842).
		 *
		 * Applies the `woodev_shipping_plugin_method_classes` filter a SECOND time
		 * (once here, once whenever `register_shipping_methods()` itself runs) —
		 * acceptable because the filter is a pure list transform with no side
		 * effects of its own.
		 *
		 * @since 2.0.2 #842
		 *
		 * @return string[] shipping method id strings
		 */
		public function get_declared_shipping_method_ids(): array {

			$ids = [];

			foreach ( $this->get_valid_shipping_method_classes() as $class ) {
				$ids[] = $class::get_method_id();
			}

			return $ids;
		}

		/**
		 * Renders this plugin's extra information under a shipping rate on the order form.
		 *
		 * This is the CLASSIC order form's only seam for it. WooCommerce's own
		 * `cart/cart-shipping.php` prints the rate label and nothing else, then fires
		 * `woocommerce_after_shipping_rate` — so a method description, a delivery
		 * estimate or a pickup-point button all have to be echoed from here. The block
		 * order form takes a different route entirely: it reads `description` and
		 * `delivery_time` off the `WC_Shipping_Rate` through the Store API, which
		 * {@see Shipping_Method::apply_rate_attributes()} fills in.
		 *
		 * The base renders the merchant's Description here, because that field is a
		 * REQUIRED part of every woodev shipping plugin — settings screen AND order
		 * form, on both form types. Until 2.0.2 the framework only DECLARED the field
		 * and each plugin echoed it itself: `woocommerce-edostavka`,
		 * `woocommerce-yandex-delivery` and `woodev-russian-post` each carry a
		 * near-identical `woocommerce_after_shipping_rate` handler for it. That
		 * duplication is the mechanism this method absorbs.
		 *
		 * Anything carrier-specific stays with the plugin, through the filter below:
		 * a delivery estimate read off the rate meta, a commission line, a
		 * pickup-point button. The filter receives an array keyed by block name, so a
		 * plugin can add, replace or drop a block rather than append blindly.
		 *
		 * @since 2.0.2
		 *
		 * @internal Hooked on `woocommerce_after_shipping_rate`; not for direct calls.
		 *
		 * @param \WC_Shipping_Rate $rate  the rate being rendered
		 * @param int               $index the package index
		 */
		public function render_rate_additional_info( $rate, $index = 0 ): void {

			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				return;
			}

			// Only our own methods. Checked against the registry this plugin filled at
			// `woocommerce_shipping_methods` time — reading `$this->methods` directly
			// rather than through get_shipping_method_ids(), whose assert() would fire
			// for a plugin that registered none.
			if ( ! array_key_exists( $rate->get_method_id(), $this->methods ) ) {
				return;
			}

			$method = \WC_Shipping_Zones::get_shipping_method( $rate->get_instance_id() );

			if ( ! $method instanceof Shipping_Method ) {
				return;
			}

			$blocks      = [];
			$description = trim( (string) $method->get_option( 'description', '' ) );

			if ( '' !== $description ) {
				$blocks['description'] = sprintf(
					'<p class="woodev-shipping-method-description %s-method-description">%s</p>',
					esc_attr( $this->get_id_dasherized() ),
					wp_kses_post( $description )
				);
			}

			/**
			 * Filters the blocks rendered under a shipping rate on the order form.
			 *
			 * This is where a plugin adds what only it knows: a delivery estimate from
			 * the rate meta, a commission line, a pickup-point button. Keys name the
			 * block so a plugin can replace or remove one instead of only appending.
			 *
			 * ⚠ Values are echoed as HTML. Escape your own block — the framework
			 * escapes only the blocks it builds itself, because a plugin legitimately
			 * needs markup `wp_kses_post()` would strip (a `<button>`, a hidden input).
			 *
			 * A return that is not an array is discarded and the pre-filter blocks are
			 * rendered instead.
			 *
			 * @since 2.0.2
			 *
			 * @param array             $blocks block name => HTML
			 * @param \WC_Shipping_Rate $rate   the rate being rendered
			 * @param Shipping_Method   $method the method that produced it
			 * @param int               $index  the package index
			 * @param Shipping_Plugin   $plugin plugin instance
			 */
			$filtered = apply_filters( 'woodev_shipping_rate_additional_info', $blocks, $rate, $method, $index, $this );

			if ( is_array( $filtered ) ) {
				$blocks = $filtered;
			}

			$blocks = array_filter(
				$blocks,
				static function ( $block ) {
					return is_string( $block ) && '' !== trim( $block );
				}
			);

			if ( empty( $blocks ) ) {
				return;
			}

			printf(
				'<div class="woodev-shipping-method-additional-info %s-method-additional-info">%s</div>',
				esc_attr( $this->get_id_dasherized() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each block is escaped by whoever built it; see the filter docblock.
				implode( '', $blocks )
			);
		}

		/**
		 * Validates a shipping method class.
		 *
		 * @since 1.5.0
		 *
		 * @param string $class class name
		 * @return bool
		 */
		protected function is_valid_shipping_method_class( string $class ): bool {
			return is_subclass_of( $class, Shipping_Method::class );
		}

		/**
		 * Registers the integration class with WooCommerce.
		 *
		 * @since 1.5.0
		 *
		 * @param array $integrations existing integrations
		 * @return array
		 */
		public function register_integration( array $integrations ): array {
			return array_merge( $integrations, [ get_class( $this->get_integration_handler() ) ] );
		}

		/**
		 * Gets the integration handler instance.
		 *
		 * @since 1.5.0
		 *
		 * @return Settings\Shipping_Integration|null
		 */
		public function get_integration_handler(): ?Settings\Shipping_Integration {
			return null;
		}

		/**
		 * Gets a setting value from the integration handler.
		 *
		 * @since 1.5.0
		 *
		 * @param string $key setting key
		 * @param mixed  $default default value
		 * @return mixed
		 */
		public function get_integration_option( string $key, $default = null ) {

			$handler = $this->get_integration_handler();

			if ( $handler ) {
				return $handler->get_option( $key, $default );
			}

			// fallback to option directly
			$settings = get_option( 'woocommerce_' . $this->get_id_underscored() . '_settings', [] );

			return $settings[ $key ] ?? $default;
		}

		/**
		 * The «Выгрузка» settings of this carrier (#1007): auto-export on / off and the statuses it fires
		 * on. Stored per plugin, edited on the plugin's own tab of the framework settings page
		 * (`woodev-settings`), and read by {@see Order\Order_Automation}.
		 *
		 * The first call carries the v1 values over from the WooCommerce integration option, once
		 * ({@see Settings\Export_Settings::migrate_from_integration()}).
		 *
		 * @since 2.0.2
		 *
		 * @return Settings\Export_Settings
		 */
		public function get_export_settings(): Settings\Export_Settings {

			if ( null === $this->export_settings ) {
				// the v1 option's name comes from the integration handler itself, when there is one
				$this->export_settings = new Settings\Export_Settings(
					$this->get_id_underscored(),
					function (): ?string {
						$handler = $this->get_integration_handler();

						return $handler ? $handler->get_option_key() : null;
					}
				);
			}

			return $this->export_settings;
		}

		/**
		 * This carrier's ONE tab on the framework settings page (`woodev-settings`), id = the plugin id.
		 *
		 * The tab is a {@see \Woodev\Framework\Settings\Composite_Settings_Handler} — the way the
		 * «Доставка» tab composes several handlers — over the carrier's own contribution
		 * ({@see self::get_tab_settings_providers()}) plus the framework's «Выгрузка» section (#1007),
		 * which is added only when the carrier exports orders
		 * ({@see Admin\Orders\Orders_Registry::plugin_exports_orders()}). A rates-only carrier with no
		 * contribution of its own gets no tab at all.
		 *
		 * A carrier does NOT override this method: a second provider under the plugin id would be a
		 * duplicate tab, and `Settings_Page_Registry::build_tabs()` keeps only the first. It overrides
		 * {@see self::get_tab_settings_providers()} instead.
		 *
		 * This runs on the read path of every wp-admin page (the settings page collects its tabs on
		 * `admin_menu`), so a carrier's mistake must not fatal it: a contribution whose setting ids collide
		 * with «Выгрузка» or with an earlier contribution is reported with `_doing_it_wrong()` and left out
		 * of the tab, and the framework's «Выгрузка» stays.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 One composite tab per carrier, with an extension point for the carrier's own
		 *              sections (#1014); «Выгрузка» only for a carrier that exports.
		 *
		 * @return \Woodev\Framework\Settings\Settings_Provider[]
		 */
		public function get_settings_providers(): array {

			$providers = parent::get_settings_providers();
			$export    = Admin\Orders\Orders_Registry::instance()->plugin_exports_orders( $this ) ? $this->get_export_settings() : null;
			$handlers    = [];
			$sections    = [];
			$args        = [];
			$connections = [];

			foreach ( $this->get_tab_settings_providers() as $index => $contribution ) {

				if ( ! $contribution instanceof \Woodev\Framework\Settings\Settings_Provider ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							'Carrier "%s": get_tab_settings_providers() must return Settings_Provider instances; entry %s was ignored.',
							esc_html( $this->get_id() ),
							esc_html( (string) $index )
						),
						'2.0.2'
					);
					continue;
				}

				// a descriptor built with a null / foreign handler would fatal the composite (an \Error, not caught below)
				if ( ! $contribution->get_handler() instanceof \Woodev_Abstract_Settings ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							'Carrier "%1$s": the handler of get_tab_settings_providers() entry %2$s is not a Woodev_Abstract_Settings; the entry was ignored.',
							esc_html( $this->get_id() ),
							esc_html( (string) $index )
						),
						'2.0.2'
					);
					continue;
				}

				// «Выгрузка» is validated first, so it is the carrier's contribution that gives way to a clash.
				// A throwaway composite per contribution is O(n^2) in handlers — fine at one to three contributions.
				try {
					new \Woodev\Framework\Settings\Composite_Settings_Handler(
						$this->get_id(),
						array_merge( $handlers, null === $export ? [] : [ $export ], [ $contribution->get_handler() ] )
					);
				} catch ( \InvalidArgumentException $e ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							'Carrier "%1$s": the settings of get_tab_settings_providers() entry %2$s were left out of the tab. %3$s',
							esc_html( $this->get_id() ),
							esc_html( (string) $index ),
							esc_html( $e->getMessage() )
						),
						'2.0.2'
					);
					continue;
				}

				$handlers[] = $contribution->get_handler();
				$sections   = array_merge( $sections, $contribution->get_sections() );

				// a connection block is tested by the handler that CONTRIBUTED it — not derived from its setting ids,
				// which a handshake block (`create_connection()` with `[]`) does not have (#1028)
				foreach ( $contribution->get_sections() as $section ) {
					if ( $section->is_connection() ) {
						$connections[ $section->get_id() ] = $contribution->get_handler();
					}
				}

				// the tab-level attributes: the first contribution that declares one supplies it
				$declared = [
					'capability'        => $contribution->get_declared_capability(),
					'legacy_option_key' => $contribution->get_legacy_option_key(),
					'legacy_page'       => $contribution->get_legacy_page(),
				];

				foreach ( $declared as $key => $value ) {
					if ( null !== $value && ! isset( $args[ $key ] ) ) {
						$args[ $key ] = $value;
					}
				}
			}

			if ( null !== $export ) {

				$handlers[] = $export;
				$sections[] = \Woodev\Framework\Settings\Settings_Section::create(
					Settings\Export_Settings::SECTION_ID,
					__( 'Выгрузка', 'woodev-plugin-framework' ),
					$export->get_owned_setting_ids(),
					$export->get_section_description()
				);
			}

			if ( [] === $handlers ) {
				return $providers;
			}

			$providers[] = \Woodev\Framework\Settings\Settings_Provider::create_with_sections(
				$this->get_id(),
				$this->get_plugin_name(),
				new \Woodev\Framework\Settings\Composite_Settings_Handler( $this->get_id(), $handlers, $connections ),
				$args,
				...$sections
			);

			return $providers;
		}

		/**
		 * The carrier's OWN part of its settings tab: the extension point of
		 * {@see self::get_settings_providers()} (#1014).
		 *
		 * Return `Settings_Provider` descriptors built the usual way
		 * (`Settings_Provider::create_with_sections( $id, $label, $handler, $args, ...$sections )`).
		 * The framework does not register them as tabs: it takes each one's HANDLER and SECTIONS and
		 * merges them into the carrier's single tab, in the order returned and ahead of the framework's
		 * «Выгрузка» section. The descriptor's id and label are not used (the tab is named after the
		 * plugin); the first declared `capability`, `legacy_option_key` and `legacy_page` become the
		 * tab's. A descriptor's `supports` flags are DROPPED: the tab carries none. Handlers keep their own
		 * option namespaces — no key moves — but two handlers of one tab must not share a setting id, and
		 * the ids of «Выгрузка» are taken too: a clashing contribution is reported with `_doing_it_wrong()`
		 * and left out of the tab.
		 *
		 * A connection section (`Settings_Section::create_connection()`) works here, a handshake one (no
		 * setting ids) included: its «Проверить подключение» button and status badge are served by the
		 * handler of the descriptor that contributed the section, provided that handler implements `Woodev_Settings_Connection_Test` /
		 * `Woodev_Settings_Connection_Status`; one that does not shows no button.
		 *
		 * Default: none.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev\Framework\Settings\Settings_Provider[]
		 */
		protected function get_tab_settings_providers(): array {
			return [];
		}

		/**
		 * Gets all active shipping method instances from WooCommerce shipping zones.
		 *
		 * This is the recommended way to get actual method instances that are
		 * configured and active in shipping zones.
		 *
		 * @since 1.4.0
		 *
		 * @return Shipping_Method[]
		 */
		public function get_active_method_instances(): array {

			$instances = [];

			if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
				return $instances;
			}

			$shipping_zones = \WC_Shipping_Zones::get_zones();

			// Add methods from all zones
			foreach ( $shipping_zones as $zone ) {
				foreach ( $zone['shipping_methods'] as $shipping_method ) {
					if ( $shipping_method instanceof Shipping_Method && $this->is_valid_shipping_method_class( get_class( $shipping_method ) ) ) {
						$instances[] = $shipping_method;
					}
				}
			}

			// Add methods from "Rest of the World" zone (zone_id = 0)
			$worldwide_zone = new \WC_Shipping_Zone( 0 );
			foreach ( $worldwide_zone->get_shipping_methods( true ) as $shipping_method ) {
				if ( $shipping_method instanceof Shipping_Method && $this->is_valid_shipping_method_class( get_class( $shipping_method ) ) ) {
					$instances[] = $shipping_method;
				}
			}

			return $instances;
		}

		/**
		 * Add shipping method information to the system status report.
		 *
		 * @since 1.5.0
		 */
		public function add_system_status_information() {

			foreach ( $this->get_shipping_methods() as $method ) {

				if ( ! $method->is_enabled() ) {
					continue;
				}

				include $this->get_shipping_framework_path() . '/admin/views/html-admin-shipping-method-status.php';
			}
		}

		/**
		 * Convenience method to add delayed admin notices, which may depend upon
		 * some setting being saved prior to determining whether to render.
		 *
		 * @since 1.5.0
		 *
		 * @see Woodev_Plugin::add_delayed_admin_notices()
		 */
		public function add_delayed_admin_notices() {

			parent::add_delayed_admin_notices();

			// notices for currency issues
			$this->add_currency_admin_notices();

			// notices for countries issues
			$this->add_countries_admin_notices();

			// add notices about enabled debug logging
			$this->add_debug_setting_notices();

			// add notices about gateways not being configured
			$this->add_not_configured_notices();

			// add a notice when the active Location Provider is not configured
			// (#375/#377)
			$this->add_location_provider_not_configured_notice();

			// add a notice when the fixed default locality was picked under a
			// provider that is no longer the active one (#410)
			$this->add_default_locality_stale_notice();
		}

		/**
		 * Adds any currency admin notices.
		 *
		 * Checks if a particular currency is required and not being used and adds a
		 * dismissible admin notice if so.
		 *
		 * @since 1.5.0
		 *
		 * @see Woodev_Payment_Gateway_Plugin::render_admin_notices()
		 */
		protected function add_currency_admin_notices() {

			// report any currency issues
			if ( $this->get_accepted_currencies() ) {

				$suffix              = '';
				$name                = $this->get_plugin_name();
				$accepted_currencies = $this->get_accepted_currencies();

				$message = sprintf(
					_n( '%1$s accepts payment in %2$s only. %3$sConfigure%4$s WooCommerce to accept %2$s to enable this shipping method for checkout.', '%1$s accepts payment in one of %2$s only. %3$sConfigure%4$s WooCommerce to accept one of %2$s to enable this shipping method for checkout.', count( $accepted_currencies ), 'woodev-plugin-framework' ),
					$name,
					'<strong>' . implode( ', ', $accepted_currencies ) . '</strong>',
					'<a href="' . $this->get_general_configuration_url() . '">',
					'</a>'
				);

				$this->get_admin_notice_handler()->add_admin_notice(
					$message,
					'accepted-currency' . $suffix,
					[
						'notice_class' => 'error',
					]
				);

			}
		}

		protected function add_countries_admin_notices() {

			$accepted_countries = $this->get_accepted_countries();

			if ( ! $accepted_countries ) {
				return;
			}

			$store_country = wc_get_base_location()['country'] ?? '';

			if ( $store_country && ! in_array( $store_country, $accepted_countries, true ) ) {

				$message = sprintf(
					/* translators: %1$s - plugin name, %2$s - list of accepted countries, %3$s - opening <a> tag, %4$s - closing </a> tag */
					_n(
						'%1$s поддерживает доставку только в %2$s. %3$sНастройте%4$s WooCommerce для использования поддерживаемой страны.',
						'%1$s поддерживает доставку в одну из следующих стран: %2$s. %3$sНастройте%4$s WooCommerce для использования одной из поддерживаемых стран.',
						count( $accepted_countries ),
						'woodev-plugin-framework'
					),
					$this->get_plugin_name(),
					'<strong>' . implode( ', ', $accepted_countries ) . '</strong>',
					'<a href="' . esc_url( $this->get_general_configuration_url() ) . '">',
					'</a>'
				);

				$this->get_admin_notice_handler()->add_admin_notice(
					$message,
					'accepted-countries',
					[
						'notice_class' => 'error',
					]
				);
			}
		}


		/**
		 * Adds notices about enabled debug logging.
		 *
		 * @since 1.5.0
		 */
		protected function add_debug_setting_notices() {

			if ( ! $this->is_debug_enabled() ) {
				return;
			}

			$message = sprintf(
				/* translators: %1$s - plugin name, %2$s - opening <a> tag, %3$s - closing </a> tag */
				__( 'Внимание! %1$s работает в режиме отладки и записывает данные в лог. Если у вас нет проблем с доставкой, рекомендуем %2$sотключить режим отладки%3$s.', 'woodev-plugin-framework' ),
				$this->get_plugin_name(),
				'<a href="' . esc_url( $this->get_settings_url() ) . '">',
				' &raquo;</a>'
			);

			$this->get_admin_notice_handler()->add_admin_notice(
				$message,
				'debug-in-production',
				[
					'notice_class' => 'notice-warning',
				]
			);
		}


		/**
		 * Adds notices about plugin not being configured.
		 *
		 * @since 1.5.0
		 */
		protected function add_not_configured_notices() {

			if ( ! $this->get_shipping_methods() ) {
				return;
			}

			foreach ( $this->get_shipping_methods() as $method ) {

				if ( ! $method->is_enabled() ) {
					continue;
				}

				if ( method_exists( $method, 'is_configured' ) && ! $method->is_configured() ) {

					$message = sprintf(
						/* translators: %1$s - shipping method title, %2$s - opening <a> tag, %3$s - closing </a> tag */
						__( '%1$s не настроен. Пожалуйста, %2$sзавершите настройку%3$s для начала работы.', 'woodev-plugin-framework' ),
						$method->get_method_title(),
						'<a href="' . esc_url( $this->get_settings_url() ) . '">',
						' &raquo;</a>'
					);

					$this->get_admin_notice_handler()->add_admin_notice(
						$message,
						$method->id . '-not-configured',
						[
							'notice_class' => 'notice-warning',
						]
					);
				}
			}
		}

		/**
		 * Computes the "location provider not configured" notice — message text
		 * and a stable notice id — or `null` when nothing should be shown right
		 * now (#375/#377).
		 *
		 * Factored out as a PURE decision (touches no `Woodev_Admin_Notice_Handler`,
		 * no hook) from {@see self::add_location_provider_not_configured_notice()}
		 * so it is directly unit-testable via
		 * `( new ReflectionClass( $fixture ) )->newInstanceWithoutConstructor()`
		 * — the same split {@see \Woodev_Test_Credential_Seeder::should_seed()}'s
		 * own docblock documents for its own decision, and the same
		 * `newInstanceWithoutConstructor()` technique
		 * `ShippingPluginNeedsLocationProviderTest` already uses for this class.
		 * PUBLIC (not `protected`) specifically so that test can call it
		 * directly rather than through `ReflectionMethod::invoke()` — the
		 * accessibility half of that would need `ReflectionMethod::setAccessible()`,
		 * deprecated (and a no-op) since PHP 8.1, which this repo's own PHPUnit
		 * config (`failOnRisky="true"`) turns into a hard failure the instant it
		 * prints its deprecation notice.
		 *
		 * Fires only when THIS plugin opted into the Location Provider layer
		 * ({@see self::needs_location_provider()}) AND an active provider is
		 * resolved AND that provider's own {@see \Woodev\Framework\Shipping\Location\Location_Provider::is_configured()}
		 * answers `false` — precedent {@see self::add_not_configured_notices()}
		 * (`"%1$s не настроен..."`). A provider with ZERO declared fields that
		 * honestly reports `is_configured() === true` (the plan's `test-list`
		 * case) never reaches this far — see
		 * {@see \Woodev\Framework\Shipping\Location\Abstract_Location_Provider::is_configured()}'s
		 * own docblock for why zero declared fields defaults to `true`.
		 *
		 * Deliberately does NOT check {@see self::get_active_method_instances()}
		 * or `is_enabled()` the way {@see self::add_not_configured_notices()}
		 * does for a shipping METHOD — the Location Provider layer is a single,
		 * fleet-wide, STORE-level concern (one active provider per store, per
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry}'s
		 * own class docblock), not a per-method one, so there is no per-instance
		 * enabled/disabled state to gate on here.
		 *
		 * @since 2.0.2
		 *
		 * @return array{message: string, notice_id: string}|null
		 */
		public function location_provider_not_configured_notice(): ?array {

			if ( ! $this->needs_location_provider() ) {
				return null;
			}

			$provider = Location\Location_Provider_Registry::instance()->get_active_provider();

			if ( null === $provider || $provider->is_configured() ) {
				return null;
			}

			$message = sprintf(
				/* translators: %1$s - location provider name, %2$s - opening <a> tag, %3$s - closing </a> tag */
				__( 'Провайдер локаций «%1$s» не настроен. Пожалуйста, %2$sукажите ключи%3$s — иначе подсказки по адресам и населённым пунктам работать не будут.', 'woodev-plugin-framework' ),
				$provider->get_name(),
				'<a href="' . esc_url( $this->get_settings_url() ) . '">',
				' &raquo;</a>'
			);

			return [
				'message'   => $message,
				// Keyed by PROVIDER id, not by plugin/method id: the Location
				// Provider layer is shared by the whole fleet (one active
				// provider per store). The registry claims this id once per
				// request before a plugin-specific handler registers it.
				'notice_id' => 'location-provider-' . $provider->get_id() . '-not-configured',
			];
		}

		/**
		 * Adds an admin notice when the active Location Provider is not
		 * configured (#375/#377) — the Location Provider layer's counterpart to
		 * {@see self::add_not_configured_notices()}'s per-shipping-method notice.
		 *
		 * Dismissible (matching {@see self::add_not_configured_notices()}'s own
		 * default): an operator who has SEEN the warning and is deliberately
		 * postponing configuration should not be renagged on every admin page
		 * load — {@see \Woodev_Admin_Notice_Handler::should_display_notice()}
		 * still forces it back on THIS plugin's own settings page regardless
		 * (`always_show_on_settings`, the handler's own default), which is
		 * exactly where the merchant would go to actually fix it. Shown on
		 * every wp-admin screen the handler itself already renders on
		 * (`admin_notices`, `manage_woocommerce`-gated) — no narrower scope:
		 * an unconfigured location provider affects checkout everywhere, not
		 * one settings screen, so hiding it outside that one screen would
		 * under-warn.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function add_location_provider_not_configured_notice(): void {

			$notice = $this->location_provider_not_configured_notice();

			if ( null === $notice ) {
				return;
			}

			if ( ! Location\Location_Provider_Registry::instance()->claim_notice_id( $notice['notice_id'] ) ) {
				return;
			}

			$this->get_admin_notice_handler()->add_admin_notice(
				$notice['message'],
				$notice['notice_id'],
				[
					'notice_class' => 'notice-warning',
				]
			);
		}

		/**
		 * Adds an admin notice when the store's FIXED default-locality record was
		 * picked under a provider that is no longer the active one (#410) — the
		 * Location Provider layer's admin-notice counterpart to
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::apply_default_locality_status_note()}'s
		 * settings-page description note, which a merchant who switched
		 * providers OUTSIDE the settings form (`wp option update`, plugin
		 * deactivation, a direct-SQL migration) never sees.
		 *
		 * Scoped to Woodev admin pages only ({@see \Woodev_Admin_Pages::is_woodev_page()})
		 * — the operator's deliberate middle-loudness choice (#410): loud enough
		 * that a merchant working the Woodev settings surfaces meets it, but not
		 * global across every wp-admin screen the way
		 * {@see self::add_location_provider_not_configured_notice()} is, since an
		 * unconfigured provider blocks checkout everywhere while a stale fixed
		 * pick merely may not suit the currently-active provider.
		 *
		 * NON-dismissible, unlike {@see self::add_location_provider_not_configured_notice()}:
		 * the underlying condition is computed LIVE
		 * ({@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::default_locality_stale_notice()}),
		 * so the notice disappears by itself the instant the merchant re-picks
		 * the record or the active provider changes back — a dismiss flag in
		 * user meta would only let a merchant permanently hide a condition that
		 * is still true.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function add_default_locality_stale_notice(): void {

			if ( ! $this->needs_location_provider() ) {
				return;
			}

			if ( ! \Woodev_Admin_Pages::is_woodev_page() ) {
				return;
			}

			$notice = Location\Location_Provider_Registry::instance()->default_locality_stale_notice();

			if ( null === $notice ) {
				return;
			}

			if ( ! Location\Location_Provider_Registry::instance()->claim_notice_id( $notice['notice_id'] ) ) {
				return;
			}

			$this->get_admin_notice_handler()->add_admin_notice(
				$notice['message'],
				$notice['notice_id'],
				[
					'dismissible'  => false,
					'notice_class' => 'notice-warning',
				]
			);
		}

		/**
		 * Checks whether the plugin declares support for a plugin-scoped feature.
		 *
		 * Host-facing extension surface: a host plugin passes plugin-wide capability
		 * flags via the `supports` constructor arg and queries them here. This is the
		 * plugin-level counterpart to the per-method capability surface on
		 * {@see Shipping_Method::supports()} (cf. the per-gateway vs per-plugin scope
		 * split on Woodev_Payment_Gateway_Plugin). The framework ships no plugin-scoped
		 * FEATURE_* constants of its own; the vocabulary is defined by the host plugin.
		 *
		 * @since 1.5.0
		 *
		 * @param string $feature feature flag declared via the `supports` constructor arg
		 * @return bool
		 */
		public function supports( string $feature ): bool {
			return in_array( $feature, $this->supports, true );
		}

		/**
		 * Gets the plugin settings URL.
		 *
		 * @since 1.5.0
		 *
		 * @param string|null $plugin_id unused
		 * @return string
		 */
		public function get_settings_url( $plugin_id = null ): string {
			return add_query_arg(
				[
					'page'    => 'wc-settings',
					'tab'     => 'integration',
					'section' => $this->get_id(),
				],
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Checks if the current page is the plugin settings page.
		 *
		 * @since 1.5.0
		 *
		 * @return bool
		 */
		public function is_plugin_settings(): bool {
			return isset( $_GET['page'] ) && 'wc-settings' === $_GET['page']
				&& isset( $_GET['tab'] ) && 'integration' === $_GET['tab']
				&& isset( $_GET['section'] ) && $this->get_id() === $_GET['section'];
		}

		/**
		 * Adds the given shipping method id and shipping method class name as an available shipping method
		 * supported by this plugin
		 *
		 * @since 1.5.0
		 *
		 * @param string $shipping_method_id the shipping method identifier
		 * @param string $class_name the corresponding shipping method class name
		 */
		public function add_shipping_method( string $shipping_method_id, string $class_name ) {

			$this->methods[ $shipping_method_id ] = [
				'class_name'      => $class_name,
				'shipping_method' => null,
			];
		}


		/**
		 * Gets all supported shipping method class names
		 *
		 * @since 1.5.0
		 *
		 * @return array of string shipping method class names
		 */
		public function get_shipping_method_class_names(): array {

			$this->assert( ! empty( $this->methods ) );

			$shipping_method_class_names = [];

			foreach ( $this->methods as $method ) {
				$shipping_method_class_names[] = $method['class_name'];
			}

			return $shipping_method_class_names;
		}


		/**
		 * Gets the shipping method class name for the given shipping method id
		 *
		 * @since 1.5.0
		 *
		 * @param string $shipping_method_id the shipping method identifier
		 * @return string shipping method class name
		 */
		public function get_shipping_method_class_name( string $shipping_method_id ): string {

			$this->assert( isset( $this->methods[ $shipping_method_id ]['class_name'] ) );

			return $this->methods[ $shipping_method_id ]['class_name'];
		}


		/**
		 * Gets all supported gateway objects
		 *
		 * @since 1.5.0
		 *
		 * @return Shipping_Method[]
		 */
		public function get_shipping_methods(): array {

			$this->assert( ! empty( $this->methods ) );

			$shipping_methods = [];

			foreach ( $this->get_shipping_method_ids() as $shipping_method_id ) {
				$shipping_methods[] = $this->get_shipping_method( $shipping_method_id );
			}

			return $shipping_methods;
		}


		/**
		 * Adds the given $shipping_method to the internal shipping methods store
		 *
		 * A shipping method constructed before its id went through
		 * {@see self::add_shipping_method()} (a test, or any caller that builds a
		 * `Shipping_Method` outside the normal `register_shipping_methods()` flow) used to
		 * leave a HALF-ENTRY behind — only `shipping_method` set, no `class_name` — because
		 * this method blindly wrote into `$this->methods[ $shipping_method_id ]` regardless
		 * of whether that id already existed. {@see self::get_shipping_method_class_names()}
		 * then read the missing key unguarded (warning + `null` in the result).
		 *
		 * Fixed at this end (the writer), not the reader: for an id with no entry yet, the
		 * class name is read straight off the instance via `get_class()`, so the record this
		 * creates is complete from the start. For an id `add_shipping_method()` already
		 * registered, this keeps its existing `class_name` and only (re)sets the cached
		 * instance, same as before.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 #818: fills `class_name` from `get_class( $shipping_method )` when the
		 *              id has no entry yet, instead of leaving one with no `class_name`.
		 *
		 * @param string          $shipping_method_id  the shipping method identifier
		 * @param  Shipping_Method $shipping_method the shipping method object instance
		 * @return void
		 */
		public function set_shipping_method( string $shipping_method_id, Shipping_Method $shipping_method ) {

			if ( ! isset( $this->methods[ $shipping_method_id ]['class_name'] ) ) {
				$this->methods[ $shipping_method_id ]['class_name'] = get_class( $shipping_method );
			}

			$this->methods[ $shipping_method_id ]['shipping_method'] = $shipping_method;
		}


		/**
		 * Returns the identified shipping method object
		 *
		 * @param string|null $shipping_method_id  optional shipping_method identifier, defaults to first shipping method
		 *
		 * @return Shipping_Method the shipping method object
		 * @since 1.5.0
		 */
		public function get_shipping_method( ?string $shipping_method_id = null ): Shipping_Method {

			// default to first shipping method
			if ( is_null( $shipping_method_id ) ) {
				reset( $this->methods );
				$shipping_method_id = key( $this->methods );
			}

			if ( empty( $this->methods[ $shipping_method_id ]['shipping_method'] ) ) {

				// instantiate and cache
				$shipping_method_class_name = $this->get_shipping_method_class_name( $shipping_method_id );
				$this->set_shipping_method( $shipping_method_id, new $shipping_method_class_name() );
			}

			return $this->methods[ $shipping_method_id ]['shipping_method'];
		}


		/**
		 * Returns true if the plugin supports this shipping method
		 *
		 * @param string $shipping_method_id  the shipping method identifier
		 *
		 * @return boolean true if the plugin has this shipping method available, false otherwise
		 * @since 1.5.0
		 */
		public function has_shipping_method( string $shipping_method_id ): bool {
			return isset( $this->methods[ $shipping_method_id ] );
		}


		/**
		 * Returns all available shipping method ids for the plugin
		 *
		 * @since 1.5.0
		 *
		 * @return array of shipping method id strings
		 */
		public function get_shipping_method_ids(): array {

			$this->assert( ! empty( $this->methods ) );

			return array_keys( $this->methods );
		}


		// ---- Subsystem accessors ----

		/**
		 * Declares whether this plugin needs the framework's Location Provider layer
		 * (Task 3; spec §4.1).
		 *
		 * Default `false` — the layer stays completely inert for a plugin that never
		 * overrides this. A plugin that consumes checkout locality/address data
		 * (region/settlement/address suggestions) overrides this to `true`, which
		 * opens {@see Location\Location_Provider_Registry}'s activation gate for the
		 * WHOLE fleet (see {@see self::add_hooks()}, where this is consulted) — the
		 * same "one plugin's declaration turns on a shared service" shape as
		 * {@see \Woodev_Plugin::init_settings_page()} registering with
		 * `Settings_Page_Registry`. A plugin that returns `true` here MUST also
		 * override {@see self::get_location_adapter()} (Task 5) once that seam
		 * exists — the adapter is a mandatory obligation, not optional, per spec
		 * §4.3.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function needs_location_provider(): bool {
			return false;
		}

		/**
		 * Gets this plugin's adapter for the Location Provider layer (Task 5;
		 * spec §4.3): the per-plugin translator from a neutral
		 * {@see Location\Location_Record} into this plugin's own carrier
		 * identity (`city_code`, `geo_id`, a ФИАС-derived id, a postal index —
		 * whatever the carrier's own API needs).
		 *
		 * Default `null` — but that default is only a valid answer for a plugin
		 * that also returns `false` from {@see self::needs_location_provider()}.
		 * A plugin that opts INTO the layer (`needs_location_provider() ===
		 * true`) MUST override this to return a real adapter: it is a MANDATORY
		 * obligation, not an optional extension point, exactly like every
		 * participating plugin — including the one that brought the active
		 * provider — must supply one (spec §4.3: "Minimum for a not-yet-written
		 * plugin: one adapter + the declaration. No fields, no cascade, no UI
		 * work."). A plugin that opts in but leaves this at the default is a
		 * plugin bug; {@see Location\Location_Resolution_Cache::resolve_for()}
		 * reports it via `_doing_it_wrong()` the first time resolution is
		 * actually attempted, rather than this getter throwing itself — the
		 * getter has no way to know at call time whether it is being asked
		 * for informational purposes or as part of an actual resolution.
		 *
		 * @since 2.0.2
		 *
		 * @return Location\Location_Adapter|null
		 */
		public function get_location_adapter(): ?Location\Location_Adapter {
			return null;
		}

		/**
		 * Gets the Location Provider layer's service façade, building it on
		 * first use (Task 6; spec §4.1).
		 *
		 * {@see Location\Location_Service} is the single entry point every
		 * other framework layer (REST, checkout config, pickup) uses to talk
		 * to the layer — it composes {@see Location\Location_Provider_Registry::instance()}
		 * (the shared fleet-wide singleton Task 3 owns), a fresh
		 * {@see Location\Customer_Location_Store} and a fresh
		 * {@see Location\Location_Resolution_Cache}, and reimplements none of
		 * them. Unlike {@see self::get_location_adapter()} above (a per-plugin
		 * OBLIGATION a host plugin overrides), this is a framework-owned
		 * subsystem every plugin shares one instance of PER PLUGIN OBJECT —
		 * same lazily-built-and-cached shape as
		 * {@see self::get_map_provider_registry()}, not the
		 * declare-into-a-shared-registry shape {@see self::add_hooks()} uses
		 * for {@see Location\Location_Provider_Registry::declare_needed()}.
		 *
		 * @since 2.0.2
		 *
		 * @return Location\Location_Service
		 */
		public function get_location_service(): Location\Location_Service {

			if ( ! $this->location_service instanceof Location\Location_Service ) {
				$this->location_service = new Location\Location_Service();
			}

			return $this->location_service;
		}

		/**
		 * Gets the map-provider registry, building it on first use.
		 *
		 * The framework registers no default provider — neither
		 * {@see Map\Yandex_Map_Provider} nor {@see Map\Embedded_Map_Provider}. An earlier
		 * revision of this method registered `Yandex_Map_Provider` by default on the theory
		 * that its constructor was fully defaulted; that is no longer true. The fallback API
		 * key is now a REQUIRED constructor argument (a plugin obligation, not a framework
		 * one — see that class's docblock), so the framework literally cannot construct one
		 * without plugin-supplied data. Every host plugin registers whichever provider(s) it
		 * uses; see {@see Map\Map_Provider_Registry::register()} — a re-registered id
		 * overrides the previous one.
		 *
		 * @since 1.5.0
		 *
		 * @return Map\Map_Provider_Registry
		 */
		public function get_map_provider_registry(): Map\Map_Provider_Registry {

			if ( ! $this->map_provider_registry instanceof Map\Map_Provider_Registry ) {
				$this->map_provider_registry = new Map\Map_Provider_Registry();
			}

			return $this->map_provider_registry;
		}

		/**
		 * Gets the checkout handler.
		 *
		 * The framework ships only the checkout backbone ({@see Checkout\Checkout_Handler});
		 * a host plugin overrides this to return its concrete §8 checkout field handler.
		 * Defaults to none.
		 *
		 * @since 1.5.0
		 *
		 * @return Checkout\Checkout_Handler|null
		 */
		public function get_checkout_handler(): ?Checkout\Checkout_Handler {
			return null;
		}

		/**
		 * Gets the pickup handler.
		 *
		 * The framework builds none itself: a host plugin that constructs a
		 * {@see Pickup\Pickup_Handler} overrides this to return it, which lets the admin order
		 * wizard (#710) persist a chosen pickup point through the SAME handler the checkout uses.
		 * Defaults to none — the wizard then stores the point id only, as a checkout without
		 * full-point persistence does.
		 *
		 * @since 2.0.2
		 *
		 * @return Pickup\Pickup_Handler|null
		 */
		public function get_pickup_handler(): ?Pickup\Pickup_Handler {
			return null;
		}

		/**
		 * Gets the admin bootstrap.
		 *
		 * A host plugin overrides this to return its {@see Admin\Shipping_Admin}, built
		 * from its own order/warehouse handlers and admin pages. Defaults to none.
		 *
		 * @since 1.5.0
		 *
		 * @return Admin\Shipping_Admin|null
		 */
		public function get_shipping_admin(): ?Admin\Shipping_Admin {
			return null;
		}

		/**
		 * Gets the inbound webhook handler.
		 *
		 * {@see Order\Abstract_Webhook_Handler} is abstract and bound to host-supplied
		 * REST namespace/route + signature verification, so a host plugin overrides this
		 * to return its concrete handler. Defaults to none (a carrier without an inbound
		 * webhook — e.g. outbound-only yandex — simply leaves it unset).
		 *
		 * @since 1.5.0
		 *
		 * @return Order\Abstract_Webhook_Handler|null
		 */
		public function get_webhook_handler(): ?Order\Abstract_Webhook_Handler {
			return null;
		}


		/**
		 * Gets the plugin version to be used by any internal scripts.
		 *
		 * This normally corresponds to the plugin version, but can be overridden when debug mode is used.
		 * In that case `time()` will be used to force cache bursting.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_assets_version(): string {
			return $this->is_debug_enabled() ? time() : parent::get_assets_version();
		}

		/**
		 * Determines if debug mode is enabled.
		 *
		 * @return bool True if debug mode is enabled, false otherwise.
		 */
		public function is_debug_enabled(): bool {
			return $this->get_integration_option( 'debug_mode' ) ? wc_string_to_bool( $this->get_integration_option( 'debug_mode' ) ) : ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		}

		// ---- Paths ----

		/**
		 * Gets the shipping framework path without trailing slash.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_shipping_framework_path(): string {
			return untrailingslashit( plugin_dir_path( __FILE__ ) );
		}

		/**
		 * Gets the shipping framework assets URL without trailing slash.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_shipping_framework_assets_url(): string {
			return untrailingslashit( plugins_url( '/assets', __FILE__ ) );
		}

		/**
		 * Gets the accepted currencies.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_accepted_currencies(): array {
			/**
			 * Shipping Plugin Accepted Currencies Filter.
			 *
			 * Allow actors to filter accepted currencies.
			 *
			 * A return that is not an array is discarded and the plugin's own
			 * configured currencies are returned instead — this method's `array`
			 * return type makes any other return a fatal `TypeError`.
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A non-array return is discarded; the pre-filter
			 *              currencies are returned instead of trusting the
			 *              return's type.
			 *
			 * @param array $currencies Accepted currency codes
			 * @param Shipping_Plugin $plugin Plugin instance
			 */
			$filtered_currencies = apply_filters( sprintf( 'woodev_shipping_plugin_%s_accepted_currencies', $this->get_id_underscored() ), $this->currencies, $this );

			return is_array( $filtered_currencies ) ? $filtered_currencies : $this->currencies;
		}

		/**
		 * Gets the accepted countries.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_accepted_countries(): array {
			/**
			 * Shipping Plugin Accepted Countries Filter.
			 *
			 * Allow actors to filter accepted countries.
			 *
			 * A return that is not an array is discarded and the plugin's own
			 * configured countries are returned instead — this method's `array`
			 * return type makes any other return a fatal `TypeError`, and this
			 * getter sits on the checkout availability path
			 * ({@see \Woodev\Framework\Shipping\Shipping_Method::is_available_for_package()}).
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A non-array return is discarded; the pre-filter
			 *              countries are returned instead of trusting the return's
			 *              type.
			 *
			 * @param array $countries Accepted country codes
			 * @param Shipping_Plugin $plugin Plugin instance
			 */
			$filtered_countries = apply_filters( sprintf( 'woodev_shipping_plugin_%s_accepted_countries', $this->get_id_underscored() ), $this->countries, $this );

			return is_array( $filtered_countries ) ? $filtered_countries : $this->countries;
		}
	}

endif;

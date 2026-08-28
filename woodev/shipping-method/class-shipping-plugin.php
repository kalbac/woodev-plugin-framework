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
		 * @return null|Shipping_API
		 * @since 1.5.0
		 */
		abstract public function get_api(): ?Shipping_API;

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

			// API interfaces
			require_once $path . '/api/interface-shipping-api.php';

			// base shipping method and specializations
			require_once $path . '/class-shipping-rate.php';
			require_once $path . '/class-shipping-method.php';
			require_once $path . '/class-shipping-method-courier.php';
			require_once $path . '/class-shipping-method-pickup.php';
			require_once $path . '/class-shipping-method-postal.php';

			// settings
			require_once $path . '/settings/class-shipping-integration.php';

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

			// pickup-point map provider interface + registry (no default provider ships)
			require_once $path . '/map/interface-map-provider.php';
			require_once $path . '/map/class-map-provider-registry.php';

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
			require_once $path . '/location/interface-location-provider.php';
			require_once $path . '/location/abstract-location-provider.php';
			require_once $path . '/location/class-location-settings.php';
			require_once $path . '/location/class-location-provider-registry.php';
			require_once $path . '/location/class-customer-location-store.php';
			require_once $path . '/location/interface-location-adapter.php';
			require_once $path . '/location/class-location-resolution-cache.php';
			require_once $path . '/location/class-location-service.php';

			// Task 7: the bundled DaData provider — the registry's own
			// bundled_provider_classes() class_exists()-guards its FQCN, so this
			// require can sit anywhere before init/collect() (as long as it is
			// loaded at all); kept adjacent to the rest of the location block.
			require_once $path . '/location/providers/class-dadata-api-request.php';
			require_once $path . '/location/providers/class-dadata-api-response.php';
			require_once $path . '/location/providers/class-dadata-api-client.php';
			require_once $path . '/location/providers/class-dadata-provider.php';

			// pickup models and warehouse persistence
			require_once $path . '/pickup/class-pickup-point.php';
			require_once $path . '/pickup/class-warehouse.php';
			require_once $path . '/pickup/interface-warehouse-store.php';
			require_once $path . '/pickup/class-abstract-warehouse-store.php';

			// checkout fields + handler backbone
			require_once $path . '/checkout/class-checkout-fields.php';
			require_once $path . '/checkout/class-checkout-handler.php';

			// order meta handler + abstract shipment/tracking/webhook handlers
			require_once $path . '/order/class-shipping-order-handler.php';
			require_once $path . '/order/abstract-shipment-handler.php';
			require_once $path . '/order/abstract-tracking-handler.php';
			require_once $path . '/order/abstract-webhook-handler.php';

			// admin bootstrap + order/warehouse admin handlers
			require_once $path . '/admin/class-shipping-admin.php';
			require_once $path . '/admin/class-shipping-admin-order.php';
			require_once $path . '/admin/class-warehouse-admin.php';

			// warehouses REST controller base
			require_once $path . '/rest-api/abstract-warehouses-controller.php';
		}

		/**
		 * Adds action and filter hooks.
		 *
		 * @since 1.5.0
		 */
		private function add_hooks(): void {

			// register shipping methods with WooCommerce
			add_filter( 'woocommerce_shipping_methods', [ $this, 'register_shipping_methods' ] );

			// register WC_Integration if configured
			if ( $this->get_integration_handler() instanceof Shipping_Integration ) {
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
		 *
		 * @param array $methods existing methods
		 * @return array
		 */
		final public function register_shipping_methods( array $methods ): array {

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

			foreach ( $classes as $class ) {

				if ( ! $this->is_valid_shipping_method_class( $class ) ) {
					continue;
				}

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
		 * @return Shipping_Integration|null
		 */
		public function get_integration_handler(): ?Shipping_Integration {
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

			if ( ! Location\Location_Provider_Registry::instance()->claim_not_configured_notice( $notice['notice_id'] ) ) {
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
		 * @param string          $shipping_method_id  the shipping method identifier
		 * @param  Shipping_Method $shipping_method the shipping method object instance
		 *
		 * @since 1.5.0
		 */
		public function set_shipping_method( string $shipping_method_id, Shipping_Method $shipping_method ) {
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

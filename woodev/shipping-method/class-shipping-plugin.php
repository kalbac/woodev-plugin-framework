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

	abstract class Shipping_Plugin extends \Woodev_Plugin {

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
		 *     @type string[] $supports          Feature flags (see FEATURE_* constants)
		 *     @type string[] $currencies         Accepted currency codes
		 *     @type string[] $countries          Accepted country codes
		 *     @type string   $integration_class  WC_Integration class name for settings
		 *     @type string   $map_provider       Map provider: 'yandex' or 'leaflet'
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
			 * @since 1.5.0
			 *
			 * @param array $method_classes shipping method class names
			 * @param Shipping_Plugin $plugin plugin instance
			 */
			$classes = apply_filters( 'woodev_shipping_plugin_method_classes', $this->get_shipping_method_classes(), $this );

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
			 * @since 1.5.0
			 *
			 * @param array $methods registered methods
			 * @param Shipping_Plugin $plugin plugin instance
			 */
			return apply_filters( 'woodev_shipping_plugin_registered_methods', $methods, $this );
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
			return is_subclass_of( $class, 'Woodev_Shipping_Method' );
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
		function get_integration_handler(): ?Shipping_Integration {
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

				// TODO: add logic

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

			// report any countries issues
			if ( $this->get_accepted_countries() ) {
				// TODO: показываем уведомление о том что магазин не поддерживает ни одну из доступных стран доставки для матодов доставки
			}
		}


		/**
		 * Adds notices about enabled debug logging.
		 *
		 * @since 1.5.0
		 */
		protected function add_debug_setting_notices() {

			// TODO: добавить логику проверки, включен ли режим логирования. Если да, сообщить пользователю что нужно отключить.
		}


		/**
		 * Adds notices about plugin not being configured.
		 *
		 * @since 1.5.0
		 */
		protected function add_not_configured_notices() {

			// TODO: добавить логику, если основные параметры плагина не скофигурированы то показываем уведомление
		}

		/**
		 * Checks if a feature is supported.
		 *
		 * @since 1.5.0
		 *
		 * @param string $feature feature constant
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
		public function get_shipping_method( string $shipping_method_id = null ): Shipping_Method {

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
			 * @param array $currencies Accepted currency codes
			 * @param Shipping_Plugin $plugin Plugin instance
			 */
			return apply_filters( sprintf( 'woodev_shipping_plugin_%s_accepted_currencies', $this->get_id_underscored() ), $this->currencies, $this );
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
			 * @param array $countries Accepted country codes
			 * @param Shipping_Plugin $plugin Plugin instance
			 */
			return apply_filters( sprintf( 'woodev_shipping_plugin_%s_accepted_countries', $this->get_id_underscored() ), $this->countries, $this );
		}
	}

endif;

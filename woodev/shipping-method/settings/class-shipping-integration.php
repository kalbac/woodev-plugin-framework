<?php

/**
 * Woodev Shipping Integration
 *
 * @package   Woodev/WooCommerce/Shipping-Method/Integration
 * @author    Maksim Martirosov
 */

namespace Woodev\Framework\Shipping\Settings;

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Order\Order_Automation;
use Woodev\Framework\Shipping\Shipping_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Shipping_Integration' ) ) :

	/**
	 * # WooCommerce Shipping Integration
	 *
	 * Base class for shipping method integration settings page.
	 *
	 * This class extends WC_Integration and provides a base level of functionality
	 * for plugin settings pages. The integration is only initialized if a subclass
	 * extends this class in the plugin.
	 *
	 * @since 1.4.0
	 */
	abstract class Shipping_Integration extends \WC_Integration {

		/** The production environment identifier */
		const ENVIRONMENT_PRODUCTION = 'production';

		/** The test environment identifier */
		const ENVIRONMENT_TEST = 'test';

		/** @var null|Shipping_Plugin the parent plugin class */
		protected ?Shipping_Plugin $plugin;

		/** @var string configuration option: whether to use a sibling plugins' connection/authentication settings */
		private string $inherit_settings;

		/** @var array of shared setting names, if any. */
		private array $shared_settings = [];

		/**
		 *
		 */
		private array $supports = [];

		public function __construct( ?Shipping_Plugin $plugin = null ) {

			// Read every field below off `$this->plugin`, NEVER off `$plugin`: the
			// argument is optional, and WooCommerce itself takes the null path —
			// `WC_Integrations::__construct()` instantiates every registered
			// integration with NO arguments. Dereferencing the parameter therefore
			// fataled with "Call to a member function get_id_underscored() on null"
			// for any integration WooCommerce constructed itself, which is every
			// integration that reaches the «Интеграции» tab.
			$this->plugin = $plugin ?? $this->init_plugin();

			$this->id                 = $this->plugin->get_id_underscored();
			$this->method_title       = sprintf( '%s (v%s)', $this->plugin->get_plugin_name(), $this->plugin->get_version() );
			$this->method_description = $this->get_method_description();

			$this->init_form_fields();
			$this->init_settings();

			add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );
		}

		public function admin_options() {
			$this->display_errors();

			$this->prepare_export_statuses_field();

			parent::admin_options();
		}

		/**
		 * The auto-export settings every carrier gets from the framework (#1007): whether orders are
		 * exported to the carrier on their own, and in which WooCommerce statuses.
		 *
		 * The keys are the ones the shipped v1 carrier plugins stored
		 * ({@see Order_Automation::SETTING_AUTO_EXPORT}, {@see Order_Automation::SETTING_EXPORT_STATUSES}),
		 * so a site that upgrades keeps what it had chosen. Default OFF (spec §12). Cancelling the carrier's
		 * shipment when the order is cancelled or fully refunded is NOT here: it is always on.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,array<string,mixed>>
		 */
		protected function get_auto_export_form_fields(): array {

			return [
				Order_Automation::SETTING_AUTO_EXPORT    => [
					'title'       => __( 'Автоэкспорт', 'woodev-plugin-framework' ),
					'type'        => 'checkbox',
					'default'     => 'no',
					'desc_tip'    => __( 'Когда заказ получает один из выбранных ниже статусов, он сам отправляется перевозчику — в фоне, покупатель ничего не ждёт. Вручную заказ можно выгрузить всегда.', 'woodev-plugin-framework' ),
					'description' => __( 'Отмена заказа и его полный возврат отменяют заявку у перевозчика сами, независимо от этой настройки.', 'woodev-plugin-framework' ),
				],
				Order_Automation::SETTING_EXPORT_STATUSES => [
					'title'             => __( 'Статусы для автоэкспорта', 'woodev-plugin-framework' ),
					'type'              => 'multiselect',
					'class'             => 'wc-enhanced-select',
					'css'               => 'width: 400px;',
					'default'           => [ 'wc-processing' ],
					'options'           => [],
					'desc_tip'          => __( 'Заказ выгружается в тот момент, когда переходит в один из этих статусов. Здесь только статусы, в которых заказ ещё можно отправить перевозчику.', 'woodev-plugin-framework' ),
					'custom_attributes' => [
						'data-placeholder' => __( 'Выберите статусы заказа', 'woodev-plugin-framework' ),
					],
				],
			];
		}

		/**
		 * The statuses auto-export may be set to: the ones «Экспорт» is offered in
		 * ({@see Order_Actions::EXPORTABLE_STATUSES}) — a later status would queue an export the gate refuses.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string> `wc-` status slug => its name.
		 */
		protected function get_export_status_options(): array {

			$options = [];

			foreach ( Order_Actions::EXPORTABLE_STATUSES as $status ) {
				$options[ 'wc-' . $status ] = wc_get_order_status_name( $status );
			}

			return $options;
		}

		/**
		 * The saved auto-export statuses the framework no longer exports on (#1007) — a v1 site could pick
		 * any non-final status, including a custom one, and v2 offers only
		 * {@see Order_Actions::EXPORTABLE_STATUSES}. The runner refuses such an order, so the merchant is
		 * told instead of finding out from orders that never reach the carrier.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string> `wc-` status slug => its name, for every saved status outside the allowed set.
		 */
		protected function get_unsupported_export_statuses(): array {

			$unsupported = [];

			foreach ( (array) $this->get_option( Order_Automation::SETTING_EXPORT_STATUSES, [] ) as $saved ) {

				if ( ! is_string( $saved ) || '' === $saved ) {
					continue;
				}

				$status = 0 === strpos( $saved, 'wc-' ) ? substr( $saved, 3 ) : $saved;

				if ( '' !== $status && ! in_array( $status, Order_Actions::EXPORTABLE_STATUSES, true ) ) {
					$unsupported[ 'wc-' . $status ] = wc_get_order_status_name( $status );
				}
			}

			return $unsupported;
		}

		/**
		 * Fills the auto-export status list when the form is drawn, and warns about a saved status the
		 * framework no longer exports on (#1007).
		 *
		 * The status names are read when the form is DRAWN, not when the integration is built: the
		 * integration is built early in every request, and order-status names are translated text. A
		 * saved status outside the allowed set stays in the list, marked by its name — a saved value the
		 * field cannot show is one the next save would erase without the merchant seeing it; it goes
		 * away only when the merchant deselects it (the v1 data contract).
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function prepare_export_statuses_field(): void {

			if ( ! isset( $this->form_fields[ Order_Automation::SETTING_EXPORT_STATUSES ] ) ) {
				return;
			}

			$unsupported = $this->get_unsupported_export_statuses();

			$this->form_fields[ Order_Automation::SETTING_EXPORT_STATUSES ]['options'] = array_merge(
				$this->get_export_status_options(),
				$unsupported
			);

			foreach ( $unsupported as $name ) {
				printf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: the order status name */
							__( 'Статус «%s» больше не поддерживается для автоэкспорта: заказы в нём не выгружаются автоматически. Выберите поддерживаемый статус.', 'woodev-plugin-framework' ),
							$name
						)
					)
				);
			}
		}

		/**
		 * Initializes the form fields.
		 *
		 * Merges base fields with custom plugin-specific fields.
		 *
		 * @since 1.5.0
		 */
		public function init_form_fields(): void {

			// debug mode

			$this->form_fields['enable_debug'] = [
				'title'       => esc_html__( 'Debug Mode', 'woodev-plugin-framework' ),
				'type'        => 'checkbox',
				'label'       => esc_html__( 'Enable debug mode/logging', 'woodev-plugin-framework' ),
				'default'     => 'no',
				'description' => sprintf( __( 'All requests and responses will be record to %s.', 'woodev-plugin-framework' ), sprintf( '<a href="%s">файл логов</a>', \Woodev_Helper::get_wc_log_file_url( $this->get_id() ) ) ),
			];

			// if there is more than just the production environment available
			if ( count( $this->get_environments() ) > 1 ) {
				$this->form_fields = $this->add_environment_form_fields( $this->form_fields );
			}

			// #1007: the carrier's export behaviour is the framework's, so every carrier plugin gets it
			// without code; a plugin that defines the same keys itself (the v1 ports) overrides these.
			$this->form_fields = array_merge( $this->form_fields, $this->get_auto_export_form_fields() );

			$this->form_fields = array_merge( $this->form_fields, $this->get_method_form_fields() );

			/**
			 * Shipping Plugin Settings Form Fields Filter.
			 *
			 * Allow actors to modify the settings screen form fields for this
			 * plugin's shared integration.
			 *
			 * A return that is not an array is discarded and the fields built
			 * above are kept instead — WooCommerce hands `$form_fields` to
			 * `array_map()` when rendering the settings screen, and a non-array
			 * value there is a fatal `TypeError`.
			 *
			 * @since 1.5.0
			 * @since 2.0.2 A non-array return is discarded; the pre-filter fields
			 *              are kept instead of trusting the return's type.
			 *
			 * @param array $form_fields the settings form fields built above
			 * @param Shipping_Integration $integration integration instance
			 */
			$filtered_form_fields = apply_filters( 'woodev_shipping_plugin_settings_' . $this->get_id() . '_form_fields', $this->form_fields, $this );

			if ( is_array( $filtered_form_fields ) ) {
				$this->form_fields = $filtered_form_fields;
			}
		}

		/**
		 * Gets the plugin instance.
		 *
		 * @return Shipping_Plugin
		 * @since 1.4.0
		 */
		public function get_plugin(): Shipping_Plugin {
			return $this->plugin;
		}

		/**
		 * Returns an array of form fields specific for this plugin.
		 *
		 * To add environment-dependent fields, include the 'class' form field argument
		 * with 'environment-field production-field' where "production" matches a
		 * key from the environments member
		 *
		 * @since 1.5.0
		 * @return array of form fields
		 */
		abstract protected function get_method_form_fields(): array;

		/**
		 * Adds the environment form fields
		 *
		 * @param array $form_fields  plugin settings form fields
		 *
		 * @return array $form_fields plugin settings form fields
		 * @since 1.5.0
		 */
		protected function add_environment_form_fields( array $form_fields ): array {

			$form_fields['environment'] = [
				'title'    => esc_html_x( 'Environment', 'Shipping plugin production or test environment modes', 'woodev-plugin-framework' ),
				'type'     => 'select',
				'default'  => key( $this->get_environments() ),  // default to first defined environment
				'desc_tip' => esc_html_x( 'Select the plugin environment to use.', 'Shipping plugin production or test environment modes', 'woodev-plugin-framework' ),
				'options'  => $this->get_environments(),
			];

			return $form_fields;
		}

		/**
		 * Checks if the integration is configured.
		 *
		 * The default is derived from the credentials this integration declares
		 * ({@see self::get_required_credential_keys()}): configured means every
		 * declared required secret holds a non-empty value. An integration that
		 * declares none — a carrier that needs no keys — reports `true`.
		 *
		 * Override this for a check the declared fields cannot express (a token
		 * exchange, a paired-field rule).
		 *
		 * @return bool
		 * @since 1.4.0
		 * @since 2.0.2 The default is derived from the declared credential fields
		 *              instead of an unconditional `true`.
		 */
		public function is_configured(): bool {

			foreach ( $this->get_required_credential_keys() as $key ) {

				$value = $this->get_option( $key );

				if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Lists the option keys of the credentials this integration declares as required.
		 *
		 * A declared form field counts when it is a `password` field (the framework's
		 * marker for a secret) or carries an explicit `'required' => true`; an
		 * explicit `'required' => false` opts a `password` field out (an optional
		 * webhook secret, say). Fields scoped to another environment through the
		 * `environment-field {env}-field` class convention
		 * ({@see self::get_method_form_fields()}) are skipped, so a test-only key
		 * does not block a production store.
		 *
		 * Read off `$this->form_fields` — the list already final after
		 * {@see self::init_form_fields()} and its filter.
		 *
		 * @since 2.0.2
		 *
		 * @return string[] option keys
		 */
		private function get_required_credential_keys(): array {

			$keys        = [];
			$environment = null;

			foreach ( $this->form_fields as $key => $field ) {

				if ( ! is_array( $field ) ) {
					continue;
				}

				if ( array_key_exists( 'required', $field ) ) {
					$required = (bool) $field['required'];
				} else {
					$required = 'password' === ( $field['type'] ?? '' );
				}

				if ( ! $required ) {
					continue;
				}

				$classes = preg_split( '/\s+/', trim( (string) ( $field['class'] ?? '' ) ) );

				if ( in_array( 'environment-field', $classes, true ) ) {

					$environment ??= $this->get_environment();

					if ( ! in_array( $environment . '-field', $classes, true ) ) {
						continue;
					}
				}

				$keys[] = (string) $key;
			}

			return $keys;
		}

		/**
		 * Checks if debug mode is enabled.
		 *
		 * @return bool True if debug mode is enabled, false otherwise.
		 */
		public function is_debug_enabled(): bool {
			return wc_string_to_bool( $this->get_option( 'enable_debug', 'no' ) );
		}

		/**
		 * Returns the shipping settings id
		 *
		 * @since 1.5.0
		 * @return string shipping settings id
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * Determines if the plugin supports sharing settings with sibling plugins.
		 *
		 * @since 1.5.0
		 * @return bool
		 */
		public function share_settings(): bool {
			return true;
		}


		/**
		 * Determines if settings should be inherited for this shipping plugin.
		 *
		 * @since 1.5.0
		 * @return bool
		 */
		public function inherit_settings(): bool {
			return wc_string_to_bool( $this->inherit_settings );
		}


		/**
		 * Adds support for the named feature or features.
		 *
		 * @since 1.5.0
		 *
		 * @param string|string[] $feature the feature name or names supported by this shipping plugin
		 */
		public function add_support( $feature ) {

			if ( ! is_array( $feature ) ) {
				$feature = [ $feature ];
			}

			foreach ( $feature as $name ) {

				if ( ! in_array( $name, $this->supports ) ) {

					$this->supports[] = $name;

					/**
					 * Shipping Plugin Add Support Action.
					 *
					 * Allows other actors (including ourselves) to take action when support is declared.
					 *
					 * @since 1.0.0
					 *
					 * @param Shipping_Integration $instance instance
					 * @param string $name of supported feature being added
					 */
					// Issue #399. This was `wc_payment_gateway_…`, copied from the payment
					// gateway along with the rest of this method. The prefix is proved wrong
					// by this class's OWN sibling: `remove_support()` already fires
					// `woodev_shipping_integration_…`. A shipping integration announcing
					// itself in the payment-gateway namespace both misleads an integrator —
					// the only way to hear it was to subscribe to a payment hook — and
					// collides outright if a shipping integration's id matches a gateway's.
					//
					// Hook names are a release-blocking contract (ADR-005), so this is
					// renamed rather than aliased ONLY because it is provably unheard:
					// `add_support()` and `remove_support()` have no caller anywhere in this
					// repository, so no subscriber can exist for a hook that never fires.
					// The verb `_supports_` matches `Shipping_Method` and the gateway; the
					// prefix now matches this class.
					do_action( 'woodev_shipping_integration_' . $this->get_id() . '_supports_' . str_replace( '-', '_', $name ), $this, $name );
				}
			}

			$this->supports = array_values( $this->supports );
		}


		/**
		 * Removes support for the named feature or features.
		 *
		 * @since 1.5.0
		 *
		 * @param string|string[] $feature feature name or names not supported by this plugin
		 */
		public function remove_support( $feature ) {

			if ( ! is_array( $feature ) ) {
				$feature = [ $feature ];
			}

			foreach ( $feature as $name ) {

				$key = array_search( $name, $this->supports );

				if ( $key !== false ) {

					unset( $this->supports[ $key ] );

					/**
					 * Shipping Plugin Remove Support Action.
					 *
					 * Allows other actors (including ourselves) to take action when support is removed.
					 *
					 * @since 1.5.0
					 *
					 * @param Shipping_Integration $instance instance
					 * @param string $name of supported feature being removed
					 */
					do_action( 'woodev_shipping_integration_' . $this->get_id() . '_removed_support_' . str_replace( '-', '_', $name ), $this, $name );
				}
			}

			// re-index the array
			$this->supports = array_values( $this->supports );
		}


		/**
		 * Set all features supported.
		 *
		 * @since 1.5.0
		 *
		 * @param string[]|string $features feature or array of supported feature names
		 */
		public function set_supports( $features ) {
			$this->supports = array_values( (array) $features );
		}

		/**
		 * Gets the set of environments supported by this plugin. Every shipping integration
		 * supports at least the production environment.
		 *
		 * ISSUE #391: this used to memoize into `private array $environments = []` behind an
		 * `! isset( $this->environments )` guard — and a TYPED property with an initialiser is
		 * never unset, so the seeding branch could not run and this returned `[]` forever.
		 * `Woodev_Payment_Gateway` uses the identical idiom and works only because its
		 * property is declared `private $environments;` with neither type nor initialiser
		 * (`class-payment-gateway.php:141`), leaving `isset()` false on the first call.
		 *
		 * The consequence was the whole test-environment feature: `init_form_fields()` gates
		 * the environment selector on `count( $this->get_environments() ) > 1`, which was
		 * `0 > 1`, so the selector never rendered — and `get_environment_name()` fell through
		 * to returning the raw id instead of a label.
		 *
		 * The memo is GONE rather than repaired. It guarded a single array literal, the
		 * property was private with no setter and no other reader, and a stale memo is a
		 * footgun a subclass override would have to work around.
		 *
		 * A PLUGIN DECLARES MORE ENVIRONMENTS BY OVERRIDING THIS METHOD. That is the only
		 * seam WooCommerce's construction model leaves: it instantiates every registered
		 * integration with NO arguments ({@see self::__construct()}), so the gateway's
		 * `$args['environments']` route is not available here.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, string> environment id => display name.
		 */
		public function get_environments(): array {

			return [
				self::ENVIRONMENT_PRODUCTION => esc_html_x( 'Production', 'software environment', 'woodev-plugin-framework' ),
			];
		}


		/**
		 * Returns the environment setting, one of the $environments keys, ie 'production'
		 *
		 * @since 1.5.0
		 * @return string the configured environment id
		 */
		public function get_environment(): string {
			return $this->get_option( 'environment', self::ENVIRONMENT_PRODUCTION );
		}


		/**
		 * Get the configured environment's display name.
		 *
		 * @since 1.5.0
		 * @return string The configured environment name
		 */
		public function get_environment_name(): string {

			$environments = $this->get_environments();

			$environment_id = $this->get_environment();

			return ( isset( $environments[ $environment_id ] ) ) ? $environments[ $environment_id ] : $environment_id;
		}


		/**
		 * Returns true if the current environment is $environment_id.
		 *
		 * @since 1.5.0
		 *
		 * @param string|mixed $environment_id
		 * @return bool
		 */
		public function is_environment( $environment_id ): bool {
			return $environment_id == $this->get_environment();
		}


		/**
		 * Returns true if the plugin environment is configured to 'production'.
		 *
		 * @param string|null $environment_id optional environment id to check, otherwise defaults to the plugin environment
		 *
		 * @return boolean true if $environment_id (if non-null) or otherwise the current environment is production
		 * @since 1.5.0
		 */
		public function is_production_environment( ?string $environment_id = null ): bool {

			// if an environment was passed in, see whether it's the production environment
			if ( ! is_null( $environment_id ) ) {
				return self::ENVIRONMENT_PRODUCTION == $environment_id;
			}

			// default: check the current environment
			return $this->is_environment( self::ENVIRONMENT_PRODUCTION );
		}


		/**
		 * Returns true if the current gateway environment is configured to 'test'
		 *
		 * @since 1.5.0
		 * @param string|null $environment_id optional environment id to check, otherwise defaults to the plugin current environment
		 * @return boolean true if $environment_id (if non-null) or otherwise the current environment is test
		 */
		public function is_test_environment( ?string $environment_id = null ): bool {

			// if an environment was passed in, see whether it's the production environment
			if ( ! is_null( $environment_id ) ) {
				return self::ENVIRONMENT_TEST == $environment_id;
			}

			// default: check the current environment
			return $this->is_environment( self::ENVIRONMENT_TEST );
		}

		/**
		 * Returns the error message for display if the plugin is not configured.
		 *
		 * @since 1.5.2
		 *
		 * @return string
		 */
		public function get_not_configured_error_message(): string {

			return sprintf(
				__( 'Heads up! Plugin %1$s is not fully configured and cannot calculate delivery. Please %2$sreview the documentation%3$s and configure the %4$sgeneral plugin settings%5$s.', 'woodev-plugin-framework' ),
				$this->get_plugin()->get_plugin_name(),
				'<a href="' . $this->get_plugin()->get_documentation_url() . '" target="_blank">',
				'</a>',
				'<a href="' . $this->get_plugin()->get_settings_url( $this->get_id() ) . '">',
				'</a>'
			);
		}

		abstract protected function init_plugin(): Shipping_Plugin;
	}

endif;

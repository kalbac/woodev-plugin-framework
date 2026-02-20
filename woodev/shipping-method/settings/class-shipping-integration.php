<?php

/**
 * Woodev Shipping Integration
 *
 * @package   Woodev/WooCommerce/Shipping-Method/Integration
 * @author    Maksim Martirosov
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Integration' ) ) :

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

		/** @var array associative array of environment id to display name, defaults to 'production' => 'Production' */
		private array $environments = [];

		/** @var string configuration option: whether to use a sibling plugins' connection/authentication settings */
		private string $inherit_settings;

		/** @var array of shared setting names, if any. */
		private array $shared_settings = [];

		/**
		 *
		 */
		private array $supports = [];

		/**
		 * Initializes the integration.
		 *
		 * @param  Shipping_Plugin|null  $plugin  the parent plugin class
		 *
		 * @since 1.4.0
		 */
		public function __construct( ?Shipping_Plugin $plugin = null ) {

			$this->plugin = $plugin ?? $this->init_plugin();

			$this->id                 = $plugin->get_id_underscored();
			$this->method_title       = sprintf( '%s (v%s)', $plugin->get_plugin_name(), $plugin->get_version() );
			$this->method_description = $this->get_method_description();

			$this->init_form_fields();
			$this->init_settings();

			add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );
		}

		/**
		 * Displays admin options.
		 *
		 * Override this method to add custom display logic.
		 *
		 * @since 1.4.0
		 */
		public function admin_options() {
			$this->display_errors();
			parent::admin_options();
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
				'description' => sprintf( __('All requests and responses will be record to %s.', 'woodev-plugin-framework' ), sprintf( '<a href="%s">файл логов</a>', \Woodev_Helper::get_wc_log_file_url( $this->get_id() ) ) ),
			];

			// if there is more than just the production environment available
			if ( count( $this->get_environments() ) > 1 ) {
				$this->form_fields = $this->add_environment_form_fields( $this->form_fields );
			}

			$this->form_fields = array_merge( $this->form_fields, $this->get_method_form_fields() );

			$this->form_fields = apply_filters( 'woodev_shipping_plugin_settings_' . $this->get_id() . '_form_fields', $this->form_fields, $this );
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
		 *@since 1.5.0
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
		 * Override this method to implement custom configuration checks.
		 *
		 * @return bool
		 * @since 1.4.0
		 */
		public function is_configured(): bool {
			// override this to check for subclass required settings (user names, passwords, secret keys, etc)
			return true;
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
					 * @param Shipping_Integration $this instance
					 * @param string $name of supported feature being added
					 */
					do_action( 'wc_payment_gateway_' . $this->get_id() . '_supports_' . str_replace( '-', '_', $name ), $this, $name );
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
					 * @param Shipping_Integration $this instance
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
		 * Gets the set of environments supported by this plugin.  All shipping methods support at least the production environment
		 *
		 * @since 1.5.0
		 * @return array associative array of environment id to name supported by this plugin
		 */
		public function get_environments(): array {

			if ( ! isset( $this->environments ) ) {
				$this->environments = [
					self::ENVIRONMENT_PRODUCTION => esc_html_x( 'Production', 'software environment', 'woodev-plugin-framework' )
				];
			}

			return $this->environments;
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
		 *@since 1.5.0
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

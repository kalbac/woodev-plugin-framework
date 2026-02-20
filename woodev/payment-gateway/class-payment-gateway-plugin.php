<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Woodev_Payment_Gateway_Plugin' ) ) :

	/**
	 * # WooCommerce Payment Gateway Plugin Framework
	 *
	 * A payment gateway refinement of the WooCommerce Plugin Framework
	 *
	 * This framework class provides a base level of configurable and overrideable
	 * functionality and features suitable for the implementation of a WooCommerce
	 * payment gateway.  This class handles all the non-gateway support tasks such
	 * as verifying dependencies are met, loading the text domain, etc.  It also
	 * loads the payment gateway when needed now that the gateway is only created
	 * on the checkout & settings pages / api hook.  The gateway can also be loaded
	 * in the following instances:
	 *
	 * + On the My Account page to display / change saved payment methods (if supports tokenization)
	 * + On the Admin User/Your Profile page to render/persist the customer ID field(s) (if supports customer_id)
	 * + On the Admin Order Edit page to render a merchant account transaction direct link (if supports transaction_link)
	 *
	 * ## Supports (zero or more):
	 *
	 * + `customer_id`             - adds actions to show/persist the "Customer ID" area of the admin User edit page
	 * + `transaction_link`        - adds actions to render the merchant account transaction direct link on the Admin Order Edit page.  (Don't forget to override the Woodev_Payment_Gateway::get_transaction_url() method!)
	 * + `capture_charge`          - adds actions to capture charge for authorization-only transactions
	 * + `my_payment_methods`      - adds actions to show/handle a "My Payment Methods" area on the customer's My Account page. This will show saved payment methods for all plugin gateways that support tokenization.
	 *
	 */

	abstract class Woodev_Payment_Gateway_Plugin extends Woodev_Plugin {

		/** Customer ID feature */
		const FEATURE_CUSTOMER_ID = 'customer_id';

		/** Charge capture feature */
		const FEATURE_CAPTURE_CHARGE = 'capture_charge';

		/** My Payment Methods feature */
		const FEATURE_MY_PAYMENT_METHODS = 'my_payment_methods';

		/** @var array optional associative array of gateway id to array( 'gateway_class_name' => string, 'gateway' => Woodev_Payment_Gateway ) */
		private $gateways;

		/** @var array optional array of currency codes this gateway is allowed for */
		private $currencies = array();

		/** @var array named features that this gateway supports which require action from the parent plugin, including 'tokenization' */
		private $supports = array();

		/** @var boolean true if this gateway requires SSL for processing transactions, false otherwise */
		private $require_ssl;

		/** @var Woodev_Payment_Gateway_Admin_Order order handler instance */
		protected $admin_order_handler;

		/** @var Woodev_Payment_Gateway_Admin_User_Handler user handler instance */
		protected $admin_user_handler;

		/** @var Woodev_Payment_Gateway_My_Payment_Methods adds My Payment Method functionality */
		private $my_payment_methods;


		/**
		 * Initializes the plugin.
		 *
		 * Optional args:
		 *
		 * + `require_ssl` - boolean true if this plugin requires SSL for proper functioning, false otherwise. Defaults to false
		 * + `gateways` - array associative array of gateway id to gateway class name.  A single plugin might support more than one gateway, ie credit card, echeck.  Note that the credit card gateway must always be the first one listed.
		 * + `currencies` -  array of currency codes this gateway is allowed for, defaults to all
		 * + `supports` - array named features that this gateway supports, including 'tokenization', 'transaction_link', 'customer_id', 'capture_charge'
		 *
		 * @param string $id plugin id
		 * @param string $version plugin version number
		 * @param array $args plugin arguments
		 *
		 * @see Woodev_Plugin::__construct()
		 * @since 1.0.0
		 *
		 */
		public function __construct( $id, $version, $args ) {

			parent::__construct( $id, $version, $args );

			$args = wp_parse_args( $args, array(
				'gateways'    => array(),
				'currencies'  => array(),
				'supports'    => array(),
				'require_ssl' => false,
			) );

			// add each gateway
			foreach ( $args['gateways'] as $gateway_id => $gateway_class_name ) {
				$this->add_gateway( $gateway_id, $gateway_class_name );
			}

			$this->currencies  = (array) $args['currencies'];
			$this->supports    = (array) $args['supports'];
			$this->require_ssl = (array) $args['require_ssl'];

			// require the files
			$this->includes();

			// add the action & filter hooks
			$this->add_hooks();
		}

		/**
		 * Builds the REST API handler instance.
		 *
		 * Gateway plugins can override this to add their own data and/or routes.
		 *
		 * @see Woodev_Plugin::init_rest_api_handler()
		 */
		protected function init_rest_api_handler() {

			require_once( $this->get_payment_gateway_framework_path() . '/rest-api/class-payment-gateway-plugin-rest-api.php' );

			$this->rest_api_handler = new Woodev_Payment_Gateway_REST_API( $this );
		}

		/**
		 * Adds the action & filter hooks.
		 */
		private function add_hooks() {

			// add classes to WC Payment Methods
			add_filter( 'woocommerce_payment_gateways', array( $this, 'load_gateways' ) );

			// adjust the available gateways in certain cases
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'adjust_available_gateways' ) );

			// my payment methods feature
			add_action( 'init', array( $this, 'maybe_init_my_payment_methods' ) );

			// add gateway information to the system status report
			add_action( 'woocommerce_system_status_report', array( $this, 'add_system_status_information' ) );
		}

		/**
		 * Initializes the plugin admin.
		 *
		 * @see Woodev_Plugin::init_admin()
		 */
		public function init_admin() {

			parent::init_admin();

			$this->admin_order_handler = new Woodev_Payment_Gateway_Admin_Order( $this );
			$this->admin_user_handler  = new Woodev_Payment_Gateway_Admin_User_Handler( $this );
		}


		/**
		 * Adds any gateways supported by this plugin to the list of available payment gateways.
		 *
		 * @param array $gateways
		 *
		 * @return array $gateways
		 * @internal
		 *
		 * @since 1.0.0
		 *
		 */
		public function load_gateways( $gateways ) {

			return array_merge( $gateways, $this->get_gateways() );
		}

		/**
		 * Adjust the available gateways in certain cases.
		 *
		 * @param array $available_gateways the available payment gateways
		 *
		 * @return array
		 */
		public function adjust_available_gateways( $available_gateways ) {

			if ( ! is_add_payment_method_page() ) {
				return $available_gateways;
			}

			foreach ( $this->get_gateways() as $gateway ) {

				if ( ! $gateway->supports_tokenization() || ! $gateway->supports_add_payment_method() || ! $gateway->tokenization_enabled() ) {
					unset( $available_gateways[ $gateway->id ] );
				}
			}

			return $available_gateways;
		}

		/**
		 * Include required files.
		 *
		 * @internal
		 *
		 * @since 1.0.0
		 */
		private function includes() {

			$payment_gateway_framework_path = $this->get_payment_gateway_framework_path();

			// interfaces
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-request.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-authorization-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-create-payment-token-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-get-tokenized-payment-methods-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-payment-notification-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-payment-notification-credit-card-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-payment-notification-echeck-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-payment-notification-loans-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-payment-notification-tokenization-response.php' );
			require_once( $payment_gateway_framework_path . '/api/interface-payment-gateway-api-customer-response.php' );

			// exceptions
			require_once( $payment_gateway_framework_path . '/exceptions/class-payment-gateway-exception.php' );

			// gateway
			require_once( $payment_gateway_framework_path . '/class-payment-gateway.php' );
			require_once( $payment_gateway_framework_path . '/class-payment-gateway-direct.php' );
			require_once( $payment_gateway_framework_path . '/class-payment-gateway-hosted.php' );
			require_once( $payment_gateway_framework_path . '/class-payment-gateway-payment-form.php' );
			require_once( $payment_gateway_framework_path . '/class-payment-gateway-my-payment-methods.php' );

			// handlers
			require_once( $payment_gateway_framework_path . '/handlers/abstract-payment-handler.php' );
			require_once( $payment_gateway_framework_path . '/handlers/abstract-hosted-payment-handler.php' );
			require_once( $payment_gateway_framework_path . '/handlers/capture.php' );

			// payment tokens
			require_once( $payment_gateway_framework_path . '/payment-tokens/class-payment-gateway-payment-token.php' );
			require_once( $payment_gateway_framework_path . '/payment-tokens/class-payment-gateway-payment-tokens-handler.php' );

			// helpers
			require_once( $payment_gateway_framework_path . '/api/class-payment-gateway-api-response-message-helper.php' );
			require_once( $payment_gateway_framework_path . '/class-payment-gateway-helper.php' );

			// admin
			require_once( $payment_gateway_framework_path . '/admin/class-payment-gateway-admin-order.php' );
			require_once( $payment_gateway_framework_path . '/admin/class-payment-gateway-admin-user-handler.php' );
			require_once( $payment_gateway_framework_path . '/admin/class-payment-gateway-admin-payment-token-editor.php' );
		}

		/**
		 * Instantiates the My Payment Methods table class instance when a user is
		 * logged in on an account page and tokenization is enabled for at least
		 * one of the active gateways.
		 *
		 * @internal
		 */
		public function maybe_init_my_payment_methods() {

			// bail if not frontend or an AJAX request
			if ( is_admin() && ! is_ajax() ) {
				return;
			}

			if ( $this->supports_my_payment_methods() && $this->tokenization_enabled() && is_user_logged_in() ) {
				$this->my_payment_methods = $this->get_my_payment_methods_instance();
			}
		}

		/**
		 * Returns true if tokenization is supported and enabled for at least one
		 * active gateway
		 *
		 * @return bool
		 */
		public function tokenization_enabled() {

			foreach ( $this->get_gateways() as $gateway ) {

				if ( $gateway->is_enabled() && $gateway->supports_tokenization() && $gateway->tokenization_enabled() ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Gets the My Payment Methods table instance.
		 *
		 * Overrideable by concrete gateway plugins to return a custom instance as needed
		 *
		 * @return Woodev_Payment_Gateway_My_Payment_Methods
		 */
		protected function get_my_payment_methods_instance() {
			return new Woodev_Payment_Gateway_My_Payment_Methods( $this );
		}

		/**
		 * Determines whether the My Payment Methods feature is supported.
		 *
		 * @return bool
		 */
		public function supports_my_payment_methods() {
			return $this->supports( self::FEATURE_MY_PAYMENT_METHODS );
		}

		/**
		 * Adds the gateway plugin action links.
		 *
		 * @param string[] $actions associative array of action names to anchor tags
		 *
		 * @return string[]
		 * @see Woodev_Plugin::plugin_action_links()
		 *
		 * @since 1.0.0
		 *
		 */
		public function plugin_action_links( $actions ) {

			$actions = parent::plugin_action_links( $actions );

			// remove the configure plugin link if it exists, since we'll be adding a link per available gateway
			if ( isset( $actions['configure'] ) ) {
				unset( $actions['configure'] );
			}

			// a configure link per gateway
			$custom_actions = array();

			foreach ( $this->get_gateway_ids() as $gateway_id ) {
				$custom_actions[ 'configure_' . $gateway_id ] = $this->get_settings_link( $gateway_id );
			}

			// add the links to the front of the actions list
			return array_merge( $custom_actions, $actions );
		}

		/**
		 * Determines if on the admin gateway settings screen for this plugin.
		 *
		 * Multi-gateway plugins will return true if on either settings page
		 *
		 * @return bool
		 * @see Woodev_Plugin::is_plugin_settings()
		 */
		public function is_plugin_settings() {

			foreach ( $this->get_gateways() as $gateway ) {
				if ( $this->is_payment_gateway_configuration_page( $gateway->get_id() ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Convenience method to add delayed admin notices, which may depend upon
		 * some setting being saved prior to determining whether to render.
		 *
		 * @see Woodev_Plugin::add_delayed_admin_notices()
		 */
		public function add_delayed_admin_notices() {

			parent::add_delayed_admin_notices();

			// reload all gateway settings so notices are correct after saving the settings
			foreach ( $this->get_gateways() as $gateway ) {
				$gateway->init_settings();
				$gateway->load_settings();
			}

			// notices for ssl requirement
			$this->add_ssl_admin_notices();

			// notices for currency issues
			$this->add_currency_admin_notices();

			// add notices about enabled debug logging
			$this->add_debug_setting_notices();

			// add notices about gateways not being configured
			$this->add_gateway_not_configured_notices();
		}

		/**
		 * Adds any SSL admin notices.
		 *
		 * Checks if SSL is required and not available and adds a dismissible admin
		 * notice if so.
		 *
		 * @see Woodev_Payment_Gateway_Plugin::add_admin_notices()
		 */
		protected function add_ssl_admin_notices() {

			if ( ! $this->requires_ssl() ) {
				return;
			}

			foreach ( $this->get_gateways() as $gateway ) {

				// don't display any notices for disabled gateways
				if ( ! $gateway->is_enabled() ) {
					continue;
				}

				// SSL check if gateway enabled/production mode
				if ( ! wc_checkout_is_https() ) {

					if ( $gateway->is_production_environment() && $this->get_admin_notice_handler()->should_display_notice( 'ssl-required' ) ) {

						/* translators: Placeholders: %1$s - plugin name, %2$s - <a> tag, %3$s - </a> tag */
						$message = sprintf( esc_html__( '%1$s: WooCommerce is not being forced over SSL; your customers\' payment data may be at risk. %2$sVerify your site URLs here%3$s', 'woodev-plugin-framework' ),
							'<strong>' . $this->get_plugin_name() . '</strong>',
							'<a href="' . admin_url( 'options-general.php' ) . '">',
							' &raquo;</a>'
						);

						$this->get_admin_notice_handler()->add_admin_notice( $message, 'ssl-required', array(
							'notice_class' => 'error',
						) );

						// just show the message once for plugins with multiple gateway support
						break;
					}

				} elseif ( $this->require_tls_1_2() && ! $this->is_tls_1_2_available() ) {

					/* translators: Placeholders: %s - payment gateway name */
					$message = sprintf( esc_html__( "%s will soon require TLS 1.2 support to process transactions and your server environment may need to be updated. Please contact your hosting provider to confirm that your site can send and receive TLS 1.2 connections and request they make any necessary updates.", 'woodev-plugin-framework' ), '<strong>' . $gateway->get_method_title() . '</strong>' );

					$this->get_admin_notice_handler()->add_admin_notice( $message, 'tls-1-2-required', array(
						'notice_class'            => 'notice-warning',
						'always_show_on_settings' => false,
					) );

					// just show the message once for plugins with multiple gateway support
					break;
				}
			}
		}

		/**
		 * Adds any currency admin notices.
		 *
		 * Checks if a particular currency is required and not being used and adds a
		 * dismissible admin notice if so.
		 *
		 * @see Woodev_Payment_Gateway_Plugin::render_admin_notices()
		 */
		protected function add_currency_admin_notices() {

			// report any currency issues
			if ( $this->get_accepted_currencies() ) {

				// we might have a currency issue, go through any gateways provided by this plugin and see which ones (or all) have any unmet currency requirements
				// (gateway classes will already be instantiated, so it's not like this is a huge deal)
				$gateways = array();
				foreach ( $this->get_gateways() as $gateway ) {
					if ( $gateway->is_enabled() && ! $gateway->currency_is_accepted() ) {
						$gateways[] = $gateway;
					}
				}

				if ( count( $gateways ) == 0 ) {
					// no active gateways with unmet currency requirements
					return;
				} elseif ( count( $gateways ) == 1 && count( $this->get_gateways() ) > 1 ) {
					// one gateway out of many has a currency issue
					$suffix              = '-' . $gateways[0]->get_id();
					$name                = $gateways[0]->get_method_title();
					$accepted_currencies = $gateways[0]->get_accepted_currencies();
				} else {
					// multiple gateways have a currency issue
					$suffix              = '';
					$name                = $this->get_plugin_name();
					$accepted_currencies = $this->get_accepted_currencies();
				}

				/* translators: [Plugin name] accepts payments in [currency/list of currencies] only */
				$message = sprintf(
				/* translators: Placeholders: %1$s - plugin name, %2$s - a currency/comma-separated list of currencies, %3$s - <a> tag, %4$s - </a> tag */
					_n(
						'%1$s accepts payment in %2$s only. %3$sConfigure%4$s WooCommerce to accept %2$s to enable this gateway for checkout.',
						'%1$s accepts payment in one of %2$s only. %3$sConfigure%4$s WooCommerce to accept one of %2$s to enable this gateway for checkout.',
						count( $accepted_currencies ),
						'woodev-plugin-framework'
					),
					$name,
					'<strong>' . implode( ', ', $accepted_currencies ) . '</strong>',
					'<a href="' . $this->get_general_configuration_url() . '">',
					'</a>'
				);

				$this->get_admin_notice_handler()->add_admin_notice( $message, 'accepted-currency' . $suffix, array(
					'notice_class' => 'error',
				) );

			}
		}

		/**
		 * Adds notices about enabled debug logging.
		 */
		protected function add_debug_setting_notices() {

			foreach ( $this->get_gateways() as $gateway ) {

				if ( $gateway->is_enabled() && $gateway->is_production_environment() && ! $gateway->debug_off() ) {

					$is_gateway_settings = $this->is_payment_gateway_configuration_page( $gateway->get_id() );

					$message = sprintf(
					/* translators: Placeholders: %1$s - payment gateway name, %2$s - opening <a> tag, %3$s - closing </a> tag */
						__( 'Heads up! %1$s is currently configured to log transaction data for debugging purposes. If you are not experiencing any problems with payment processing, we recommend %2$sturning off Debug Mode%3$s', 'woodev-plugin-framework' ),
						$gateway->get_method_title(),
						! $is_gateway_settings ? '<a href="' . esc_url( $this->get_payment_gateway_configuration_url( $gateway->get_id() ) ) . '">' : '', ! $is_gateway_settings ? ' &raquo;</a>' : ''
					);

					$this->get_admin_notice_handler()->add_admin_notice( $message, 'debug-in-production', array(
						'notice_class' => 'notice-warning',
					) );

					break;
				}
			}
		}


		/**
		 * Adds notices about gateways not being configured.
		 */
		protected function add_gateway_not_configured_notices() {

			$is_enhanced_admin_available = Woodev_Plugin_Compatibility::is_enhanced_admin_available();

			foreach ( $this->get_gateways() as $gateway ) {

				$note_name = $gateway->get_id_dasherized() . '-not-configured';

				if ( $gateway->is_enabled() && ! $gateway->is_configured() && ! $gateway->inherit_settings() ) {

					if ( $is_enhanced_admin_available ) {

						try {

							if ( $note = Woodev_Notes_Helper::get_note_with_name( $note_name ) ) {

								// if on the problem gateway's configuration page, revive the existing note that may have been dismissed
								if ( Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_ACTIONED === $note->get_status() && $this->is_payment_gateway_configuration_page( $gateway->get_id() ) ) {
									$note->set_status( Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_UNACTIONED );
								}

							} else {

								$note = new Automattic\WooCommerce\Admin\Notes\Note();

								$note->set_name( $note_name );
								$note->set_type( Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_ERROR );
								$note->set_source( $gateway->get_id_dasherized() );

								$note->set_title( sprintf(
								/* translators: Placeholders: %s - gateway name */
									__( '%s is not configured', 'woodev-plugin-framework' ),
									$gateway->get_method_title()
								) );

								$note->set_content( $gateway->get_not_configured_error_message() );
							}

							$note->set_actions( array() );

							// add the action buttons if not on the gateway's configuration page
							if ( ! $this->is_payment_gateway_configuration_page( $gateway->get_id() ) ) {
								$note->add_action( 'configure', __( 'Configure', 'woodev-plugin-framework' ), $this->get_settings_url( $gateway->get_id() ), Automattic\WooCommerce\Admin\Notes\Note::E_WC_ADMIN_NOTE_UNACTIONED, true );
								$note->add_action( 'dismiss', __( 'Dismiss', 'woodev-plugin-framework' ) );
							}

							$note->save();

						} catch ( Exception $exception ) {
						}
					}

					// if not an enhanced admin screen, output the legacy style notice
					if ( ! Woodev_Helper::is_enhanced_admin_screen() ) {

						$this->get_admin_notice_handler()->add_admin_notice( $gateway->get_not_configured_error_message(), $gateway->get_id() . '-not-configured', array(
							'notice_class' => 'error',
						) );
					}

					// if all's well with this gateway, make sure and delete any previously added notes
				} elseif ( $is_enhanced_admin_available && Woodev_Notes_Helper::note_with_name_exists( $note_name ) ) {
					Automattic\WooCommerce\Admin\Notes\Notes::delete_notes_with_name( $note_name );
				}
			}
		}

		/**
		 * Add gateway information to the system status report.
		 */
		public function add_system_status_information() {

			foreach ( $this->get_gateways() as $gateway ) {

				// Skip gateways that aren't enabled
				if ( ! $gateway->is_enabled() ) {
					continue;
				}

				$environment = $gateway->get_environment_name();

				include( $this->get_payment_gateway_framework_path() . '/admin/views/html-admin-gateway-status.php' );
			}
		}

		/**
		 * Determines if the plugin supports the capture charge feature.
		 *
		 * @return bool
		 */
		public function supports_capture_charge() {
			return $this->supports( self::FEATURE_CAPTURE_CHARGE );
		}


		/**
		 * Returns true if the gateway supports the named feature
		 *
		 * @param string $feature the feature
		 *
		 * @return boolean true if the named feature is supported
		 * @since 1.0.0
		 */
		public function supports( $feature ) {
			return in_array( $feature, $this->supports );
		}

		/**
		 * Get the admin order handler instance.
		 *
		 * @return Woodev_Payment_Gateway_Admin_Order
		 */
		public function get_admin_order_handler() {
			return $this->admin_order_handler;
		}


		/**
		 * Get the admin user handler instance.
		 *
		 * @return Woodev_Payment_Gateway_Admin_User_Handler
		 */
		public function get_admin_user_handler() {
			return $this->admin_user_handler;
		}


		/**
		 * Returns the gateway settings option name for the identified gateway.
		 * Defaults to woocommerce_{gateway id}_settings
		 *
		 * @param string $gateway_id
		 *
		 * @return string the gateway settings option name
		 * @since 1.0.0
		 *
		 */
		protected function get_gateway_settings_name( $gateway_id ) {
			return 'woocommerce_' . $gateway_id . '_settings';
		}

		/**
		 * Returns the settings array for the identified gateway.  Note that this
		 * will not include any defaults if the gateway has yet to be saved
		 *
		 * @param string $gateway_id gateway identifier
		 *
		 * @return array settings array
		 */
		public function get_gateway_settings( $gateway_id ) {
			return get_option( $this->get_gateway_settings_name( $gateway_id ) );
		}


		/**
		 * Returns true if this plugin requires SSL to function properly
		 *
		 * @return boolean true if this plugin requires ssl
		 * @since 1.0.0
		 *
		 */
		protected function requires_ssl() {
			return $this->require_ssl;
		}


		/**
		 * Gets the plugin configuration URL
		 *
		 * @param string $gateway_id the gateway identifier
		 *
		 * @return string gateway settings URL
		 * @since 1.0.0
		 *
		 * @see Woodev_Plugin::get_settings_url()
		 */
		public function get_settings_url( $gateway_id = null ) {

			// default to first gateway
			if ( is_null( $gateway_id ) || $gateway_id === $this->get_id() ) {
				reset( $this->gateways );
				$gateway_id = key( $this->gateways );
			}

			return $this->get_payment_gateway_configuration_url( $gateway_id );
		}

		/**
		 * Returns the admin configuration url for a gateway
		 *
		 * @param string $gateway_id the gateway ID
		 *
		 * @return string admin configuration url for the gateway
		 * @since 3.0.0
		 *
		 */
		public function get_payment_gateway_configuration_url( $gateway_id ) {
			return add_query_arg( array( 'page'    => 'wc-settings',
			                             'tab'     => 'checkout',
			                             'section' => $gateway_id
			), admin_url( 'admin.php' ) );
		}


		/**
		 * Returns true if the current page is the admin configuration page for a gateway
		 *
		 * @param string $gateway_id the gateway ID
		 *
		 * @return boolean true if the current page is the admin configuration page for the gateway
		 */
		public function is_payment_gateway_configuration_page( $gateway_id ) {

			return isset( $_GET['page'] ) && 'wc-settings' == $_GET['page'] &&
			       isset( $_GET['tab'] ) && 'checkout' == $_GET['tab'] &&
			       isset( $_GET['section'] ) && $gateway_id === $_GET['section'];
		}

		/**
		 * Adds the given gateway id and gateway class name as an available gateway
		 * supported by this plugin
		 *
		 * @param string $gateway_id the gateway identifier
		 * @param string $gateway_class_name the corresponding gateway class name
		 *
		 * @since 1.0.0
		 *
		 */
		public function add_gateway( $gateway_id, $gateway_class_name ) {
			$this->gateways[ $gateway_id ] = array( 'gateway_class_name' => $gateway_class_name, 'gateway' => null );
		}

		/**
		 * Gets all supported gateway class names; typically this will be just one,
		 * unless the plugin supports credit card and echeck variations
		 *
		 * @return array of string gateway class names
		 * @since 1.0.0
		 *
		 */
		public function get_gateway_class_names() {

			assert( ! empty( $this->gateways ) );

			$gateway_class_names = array();

			foreach ( $this->gateways as $gateway ) {
				$gateway_class_names[] = $gateway['gateway_class_name'];
			}

			return $gateway_class_names;
		}


		/**
		 * Gets the gateway class name for the given gateway id
		 *
		 * @param string $gateway_id the gateway identifier
		 *
		 * @return string gateway class name
		 * @since 1.0.0
		 *
		 */
		public function get_gateway_class_name( $gateway_id ) {

			assert( isset( $this->gateways[ $gateway_id ]['gateway_class_name'] ) );

			return $this->gateways[ $gateway_id ]['gateway_class_name'];
		}

		/**
		 * Gets all supported gateway objects; typically this will be just one,
		 * unless the plugin supports credit card and echeck variations
		 *
		 * @return Woodev_Payment_Gateway[]
		 * @since 1.0.0
		 *
		 */
		public function get_gateways() {

			assert( ! empty( $this->gateways ) );

			$gateways = array();

			foreach ( $this->get_gateway_ids() as $gateway_id ) {
				$gateways[] = $this->get_gateway( $gateway_id );
			}

			return $gateways;
		}


		/**
		 * Adds the given $gateway to the internal gateways store
		 *
		 * @param string $gateway_id the gateway identifier
		 * @param Woodev_Payment_Gateway $gateway the gateway object
		 */
		public function set_gateway( $gateway_id, $gateway ) {
			$this->gateways[ $gateway_id ]['gateway'] = $gateway;
		}

		/**
		 * Returns the identified gateway object
		 *
		 * @param string $gateway_id optional gateway identifier, defaults to first gateway, which will be the credit card gateway in plugins with support for both credit cards and echecks
		 *
		 * @return Woodev_Payment_Gateway the gateway object
		 * @since 1.0.0
		 *
		 */
		public function get_gateway( $gateway_id = null ) {

			// default to first gateway
			if ( is_null( $gateway_id ) ) {
				reset( $this->gateways );
				$gateway_id = key( $this->gateways );
			}

			if ( ! isset( $this->gateways[ $gateway_id ]['gateway'] ) ) {

				// instantiate and cache
				$gateway_class_name = $this->get_gateway_class_name( $gateway_id );
				$this->set_gateway( $gateway_id, new $gateway_class_name() );
			}

			return $this->gateways[ $gateway_id ]['gateway'];
		}


		/**
		 * Returns true if the plugin supports this gateway
		 *
		 * @param string $gateway_id the gateway identifier
		 *
		 * @return boolean true if the plugin has this gateway available, false otherwise
		 * @since 1.0.0
		 *
		 */
		public function has_gateway( $gateway_id ) {
			return isset( $this->gateways[ $gateway_id ] );
		}

		/**
		 * Returns all available gateway ids for the plugin
		 *
		 * @return array of gateway id strings
		 * @since 1.0.0
		 *
		 */
		public function get_gateway_ids() {

			assert( ! empty( $this->gateways ) );

			return array_keys( $this->gateways );
		}


		/**
		 * Returns the gateway for a given token
		 *
		 * @param string|int $user_id the user ID associated with the token
		 * @param string $token the token string
		 *
		 * @return Woodev_Payment_Gateway|null gateway if found, null otherwise
		 */
		public function get_gateway_from_token( $user_id, $token ) {

			foreach ( $this->get_gateways() as $gateway ) {

				if ( $gateway->get_payment_tokens_handler()->user_has_token( $user_id, $token ) ) {
					return $gateway;
				}
			}

			return null;
		}

		/**
		 * No-op the plugin class implementation so the payment gateway class can
		 * implement its own request logging. This is primarily done to keep the log
		 * files separated by gateway ID
		 *
		 * @see Woodev_Plugin::add_api_request_logging()
		 */
		public function add_api_request_logging() {}


		/**
		 * Returns the set of accepted currencies, or empty array if all currencies
		 * are accepted.  This is the intersection of all currencies accepted by
		 * any gateways this plugin supports.
		 *
		 * @return array of accepted currencies
		 * @since 1.0.0
		 *
		 */
		public function get_accepted_currencies() {
			return $this->currencies;
		}

		/**
		 * Returns the loaded payment gateway framework __FILE__
		 *
		 * @return string
		 */
		public function get_payment_gateway_framework_file() {
			return __FILE__;
		}


		/**
		 * Returns the loaded payment gateway framework path, without trailing slash.
		 *
		 * This is the highest version payment gateway framework that was loaded by
		 * the bootstrap.
		 *
		 * @return string
		 */
		public function get_payment_gateway_framework_path() {
			return untrailingslashit( plugin_dir_path( $this->get_payment_gateway_framework_file() ) );
		}


		/**
		 * Returns the absolute path to the loaded payment gateway framework image
		 * directory, without a trailing slash
		 *
		 * @return string relative path to framework image directory
		 */
		public function get_payment_gateway_framework_assets_path() {
			return $this->get_payment_gateway_framework_path() . '/assets';
		}


		/**
		 * Returns the loaded payment gateway framework assets URL, without a trailing slash
		 *
		 * @return string
		 */
		public function get_payment_gateway_framework_assets_url() {
			return untrailingslashit( plugins_url( '/assets', $this->get_payment_gateway_framework_file() ) );
		}

	}

endif;

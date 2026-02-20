<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Payment_Gateway_Admin_User_Handler' ) ) :

	/**
	 * Handle the admin user profile settings.
	 */
	class Woodev_Payment_Gateway_Admin_User_Handler {


		/** @var Woodev_Payment_Gateway_Plugin the plugin instance * */
		protected $plugin;

		/** @var array the token editor for each gateway * */
		protected $token_editors = array();


		/**
		 * Construct the user handler.
		 *
		 * @param Woodev_Payment_Gateway_Plugin $plugin The plugin instance
		 */
		public function __construct( Woodev_Payment_Gateway_Plugin $plugin ) {

			$this->plugin = $plugin;

			// Set up a token editor for each gateway
			add_action( 'admin_init', array( $this, 'init_token_editors' ) );

			// Add the settings section
			add_action( 'show_user_profile', array( $this, 'add_profile_section' ) );
			add_action( 'edit_user_profile', array( $this, 'add_profile_section' ) );

			// Save the settings
			add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
			add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );

			// Display the token editor markup inside the  profile section
			add_action( 'wc_payment_gateway_' . $this->get_plugin()->get_id() . '_user_profile', array(
				$this,
				'display_token_editors'
			) );

			// Display the customer ID field markup inside the  profile section
			add_action( 'wc_payment_gateway_' . $this->get_plugin()->get_id() . '_user_profile', array(
				$this,
				'display_customer_id_fields'
			) );
		}


		/**
		 * Set up a token editor for each gateway.
		 */
		public function init_token_editors() {

			foreach ( $this->get_tokenized_gateways() as $gateway ) {

				if ( ! $gateway->supports_token_editor() ) {
					continue;
				}

				$this->token_editors[ $gateway->get_id() ] = $gateway->get_payment_tokens_handler()->get_token_editor();
			}
		}


		/**
		 * Display the customer profile settings markup.
		 *
		 * @param WP_User $user The user object
		 */
		public function add_profile_section( $user ) {

			if ( ! $this->is_supported() || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$user_id             = $user->ID;
			$plugin_id           = $this->get_plugin()->get_id();
			$section_title       = $this->get_title();
			$section_description = $this->get_description();

			include( $this->get_plugin()->get_payment_gateway_framework_path() . '/admin/views/html-user-profile-section.php' );
		}


		/**
		 * Display the token editor markup.
		 *
		 * @param WP_User $user The user object
		 */
		public function display_token_editors( $user ) {

			foreach ( $this->get_token_editors() as $gateway_id => $editor ) {

				$gateway = $this->get_plugin()->get_gateway( $gateway_id );

				// if the gateway supports a customer ID but none is saved, don't display the token tables
				if ( $gateway && $gateway->supports_customer_id() && ! $gateway->get_customer_id( $user->ID, array( 'autocreate' => false ) ) ) {
					continue;
				}

				$editor->display( $user->ID );
			}
		}


		/**
		 * Display the customer ID field(s).
		 *
		 * @param WP_User $user the user object
		 */
		public function display_customer_id_fields( $user ) {

			foreach ( $this->get_customer_id_fields( $user->ID ) as $field ) {

				$label = $field['label'];
				$name  = $field['name'];
				$value = $field['value'];

				include( $this->get_plugin()->get_payment_gateway_framework_path() . '/admin/views/html-user-profile-field-customer-id.php' );
			}
		}


		/**
		 * Save the user profile section fields.
		 *
		 * @param int $user_id the user ID
		 */
		public function save_profile_fields( $user_id ) {

			if ( ! $this->is_supported() || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			// Save the token data from each token editor
			$this->save_tokens( $user_id );

			// Save the customer IDs
			$this->save_customer_ids( $user_id );
		}


		/**
		 * Save the token data from each token editor.
		 *
		 * @param int $user_id the user ID
		 */
		protected function save_tokens( $user_id ) {

			foreach ( $this->get_token_editors() as $gateway_id => $editor ) {
				$editor->save( $user_id );
			}
		}


		/**
		 * Save the customer IDs.
		 *
		 * @param int $user_id the user ID
		 */
		protected function save_customer_ids( $user_id ) {

			foreach ( $this->get_tokenized_gateways() as $gateway ) {

				if ( ! $gateway->supports_customer_id() ) {
					continue;
				}

				if ( isset( $_POST[ $gateway->get_customer_id_user_meta_name() ] ) ) {
					$gateway->update_customer_id( $user_id, trim( $_POST[ $gateway->get_customer_id_user_meta_name() ] ) );
				}
			}
		}

		/**
		 * Get the token editor section title.
		 *
		 * @return string
		 */
		protected function get_title() {

			$plugin_title = trim( str_replace( 'WooCommerce', '', $this->get_plugin()->get_plugin_name() ) );

			$title = sprintf( __( '%s Payment Tokens', 'woodev-plugin-framework' ), $plugin_title );

			/**
			 * Filter the admin token editor title.
			 *
			 * @param string $title The section title
			 * @param Woodev_Payment_Gateway_Plugin $plugin The gateway plugin instance
			 */
			return apply_filters( 'wc_payment_gateway_admin_user_profile_title', $title, $this->get_plugin() );
		}


		/**
		 * Get the token editor section description.
		 *
		 * @return string
		 */
		protected function get_description() {
			/**
			 * Filter the admin token editor description.
			 *
			 * @param string $description The section description
			 * @param Woodev_Payment_Gateway_Plugin $plugin The gateway plugin instance
			 */
			return apply_filters( 'wc_payment_gateway_admin_user_profile_description', '', $this->get_plugin() );
		}


		/**
		 * Get the token editor objects.
		 *
		 * @return array
		 */
		protected function get_token_editors() {
			return $this->token_editors;
		}


		/**
		 * Get the customer ID fields for the plugin's gateways.
		 *
		 * In most cases, this will be a single field unless the plugin has multiple gateways and they are set to different environments.
		 *
		 * @param int $user_id the user ID
		 *
		 * @return array {
		 *     The fields data
		 *
		 * @type string $label the field label
		 * @type string $name the input name
		 * @type string $value the input value
		 * }
		 */
		protected function get_customer_id_fields( $user_id ) {

			$unique_meta_key = '';

			$fields = array();

			foreach ( $this->get_tokenized_gateways() as $gateway ) {

				if ( ! $gateway->supports_customer_id() ) {
					continue;
				}

				$meta_key = $gateway->get_customer_id_user_meta_name();

				// If a field with this meta key has already been set, skip this gateway
				if ( $meta_key === $unique_meta_key ) {
					continue;
				}

				$label = __( 'Customer ID', 'woodev-plugin-framework' );

				// If the plugin has multiple gateways configured for multiple environments, append the environment name to keep things straight
				$label .= ( $this->has_multiple_environments() ) ? ' ' . sprintf( __( '(%s)', 'woodev-plugin-framework' ), $gateway->get_environment_name() ) : '';

				$fields[] = array(
					'label' => $label,
					'name'  => $meta_key,
					'value' => $gateway->get_customer_id( $user_id, array(
						'autocreate' => false,
					) ),
				);

				$unique_meta_key = $meta_key;
			}

			return $fields;
		}


		/**
		 * Get the unique environments between the plugin's gateways.
		 *
		 * @return array the environments in the format `$environment_id => $environment_name`
		 */
		protected function get_unique_environments() {

			$environments = array();

			foreach ( $this->get_tokenized_gateways() as $gateway ) {
				$environments[ $gateway->get_environment() ] = $gateway->get_environment_name();
			}

			return array_unique( $environments );
		}


		/**
		 * Get the gateways that support tokenization and are enabled.
		 *
		 * @return array
		 */
		protected function get_tokenized_gateways() {

			$gateways = array();

			foreach ( $this->get_plugin()->get_gateways() as $gateway ) {

				if ( $gateway->is_enabled() && $gateway->supports_tokenization() && ( $gateway->supports_token_editor() || $gateway->supports_customer_id() ) ) {
					$gateways[] = $gateway;
				}
			}

			return $gateways;
		}

		/**
		 * Determine if the user profile section is supported by at least one gateway.
		 *
		 * @return bool
		 */
		protected function is_supported() {

			$gateways = $this->get_tokenized_gateways();

			/**
			 * Filter whether the user profile section should be displayed for this gateway plugin.
			 *
			 * @param bool $display
			 * @param Woodev_Payment_Gateway_Plugin $plugin the gateway plugin instance
			 */
			return apply_filters( 'wc_payment_gateway_' . $this->get_plugin()->get_id() . '_display_user_profile', ! empty( $gateways ), $this->get_plugin() );
		}


		/**
		 * Determine if the plugin has varying environments between its gateways.
		 *
		 * @return bool
		 */
		public function has_multiple_environments() {
			return 1 < count( $this->get_unique_environments() );
		}


		/**
		 * Get the plugin instance.
		 *
		 * @return Woodev_Payment_Gateway_Plugin the plugin instance
		 */
		protected function get_plugin() {
			return $this->plugin;
		}


	}


endif;

<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Licensing_API' ) ) :

	class Woodev_Licensing_API extends Woodev_API_Base {

		/** @var Woodev_Plugin */
		private $plugin;

		/**
		 * The Software Licensing API URL.
		 *
		 * @since 1.2.1
		 *
		 * @var string
		 */
		private $api_url = 'https://woodev.ru/';

		public function __construct( Woodev_Plugin $plugin, $api_url = false ) {

			if ( wc_is_valid_url( $api_url ) ) {
				$this->api_url = $api_url;
			}

			$this->set_request_content_type_header( 'application/json' );
			$this->set_request_accept_header( 'application/json' );
			$this->set_response_handler( 'Woodev_Licencing_API_Response' );
			$this->plugin      = $plugin;
			$this->request_uri = $this->get_url();

		}

		/**
		 * Alert other actors that a request has been performed. This is primarily used for request logging.
		 */
		protected function broadcast_request() {
			if ( $this->is_debug_enabled() ) {

				$request  = $this->get_request_data_for_broadcast();
				$response = $this->get_response_data_for_broadcast();

				$this->plugin->log_api_request( $request, $response, sprintf( '%s_license_remote_data', $this->get_api_id() ) );
			}
		}

		/**
		 * Checks if enabled license requests logging
		 * To enable checking need to create WOODEV_LICENSE_DEBUG constant and set it with value true in wp-config.php file or set true via woodev_enable_license_logging hook
		 *
		 * @return bool
		 */
		public function is_debug_enabled() {
			return apply_filters( 'woodev_enable_license_logging', defined( 'WOODEV_LICENSE_DEBUG' ) && WOODEV_LICENSE_DEBUG );
		}

		/**
		 * Configures HTTP request args for licensing API requests.
		 *
		 * @param array $args Array of request params
		 *
		 * @return array
		 */
		protected function http_request_args( $args ) {
			return $args;
		}

		/**
		 * Gets the API URL.
		 *
		 * @since 1.2.1
		 * @return string
		 */
		public function get_url() {
			return $this->api_url;
		}

		/**
		 * Makes a request to the Software Licensing API.
		 *
		 * @param array $api_params The parameters for the API request.
		 *
		 * @since 1.2.1
		 * @return object|Woodev_Licencing_API_Response
		 * @throws Woodev_API_Exception
		 * @throws Woodev_Plugin_Exception
		 */
		public function make_request( array $api_params = array() ) {

			$request = $this->get_new_request();
			$request->get_license( $this->get_body( $api_params ) );

			return $this->perform_request( $request );
		}

		/**
		 * Updates the API parameters with the defaults.
		 *
		 * @param array $api_params The parameters for the specific request.
		 *
		 * @return array
		 */
		private function get_body( array $api_params ) {
			return wp_parse_args( $api_params, array(
					'url'    => home_url(),
					'author' => 'Woodev',
					'email'  => get_option( 'admin_email' )
				)
			);
		}

		/**
		 * Validates the response after parsing.
		 *
		 * @since 1.2.1
		 * @throws Woodev_API_Exception
		 */
		protected function do_post_parse_response_validation() {

			/** @var Woodev_Licencing_API_Response $response */
			$response = $this->get_response();

			//If we got response code as 400, throw an exception
			if ( intval( $this->get_response_code() ) >= 400 ) {
				throw new Woodev_API_Exception( $this->get_response_message(), $this->get_response_code() );
			}

			//If response doesnt exist or response data is empty, also throw an exception
			if ( ! $response || ! $response->get_response_data() ) {
				throw new Woodev_API_Exception( __( 'Cannot get license data', 'woodev-plugin-framework' ) );
			}
		}

		/**
		 * Build and return a new API request object.
		 *
		 * @param array $args
		 *
		 * @since 1.2.1
		 * @return Woodev_Licensing_API_Request the request object
		 * @see   Woodev_API_Base::get_new_request()
		 */
		protected function get_new_request( $args = array() ) {
			return new Woodev_Licensing_API_Request();
		}

		/**
		 * Get the plugin class instance associated with this API.
		 *
		 * @since 2.2.0
		 * @return Woodev_Plugin
		 */
		protected function get_plugin() {
			return $this->plugin;
		}
	}

endif;
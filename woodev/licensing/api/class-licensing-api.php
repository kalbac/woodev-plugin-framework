<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Licensing_API' ) ) :

	class Woodev_Licensing_API extends Woodev_API_Base {

		/** @var Woodev_Plugin */
		private Woodev_Plugin $plugin;

		/**
		 * The Software Licensing API URL.
		 *
		 * @since 1.2.1
		 *
		 * @var string
		 */
		private string $api_url = 'https://woodev.ru/';

		public function __construct( Woodev_Plugin $plugin, string $api_url = '' ) {

			if ( self::is_valid_url( $api_url ) ) {
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
		public function is_debug_enabled(): bool {
			return apply_filters( 'woodev_enable_license_logging', defined( 'WOODEV_LICENSE_DEBUG' ) && WOODEV_LICENSE_DEBUG );
		}

		/**
		 * Configures HTTP request args for licensing API requests.
		 *
		 * @param array $args Array of request params
		 *
		 * @return array
		 */
		protected function http_request_args( array $args ): array {
			return $args;
		}

		/**
		 * Gets the API base URL.
		 *
		 * The single override point for the licensing endpoint: filter
		 * `woodev_license_base_url` to point licensing requests (and the updater,
		 * which reads this method) at a self-hosted, staging, or local test store.
		 * The constructor's default is the built-in production endpoint
		 * (https://woodev.ru/); a constructor-supplied URL is honored only when
		 * syntactically valid (see {@see self::is_valid_url()}).
		 *
		 * @since 1.2.1
		 *
		 * @return string
		 */
		public function get_url(): string {
			/**
			 * Filters the licensing API base URL.
			 *
			 * @since 2.0.2
			 *
			 * @param string        $api_url The current API base URL.
			 * @param Woodev_Plugin $plugin  The plugin instance.
			 */
			return apply_filters( 'woodev_license_base_url', $this->api_url, $this->get_plugin() );
		}

		/**
		 * Validates API URLs using the previous WooCommerce helper semantics.
		 *
		 * @param mixed $url URL to validate.
		 * @return bool
		 */
		private static function is_valid_url( $url ): bool {

			if ( ! is_string( $url ) ) {
				return false;
			}

			if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
				return false;
			}

			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return false;
			}

			return true;
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
		public function make_request( array $api_params = [] ) {

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
			return wp_parse_args(
				$api_params,
				array(
					'url'               => home_url(),
					'author'            => 'Woodev',
					'email'             => get_option( 'admin_email' ),
					// Additive: tells the server which framework version the site runs —
					// e.g. whether it is webhook-capable (the woodev/v1/license-command
					// endpoint exists on framework >= 2.0.0). Every licensing request
					// funnels through here (dispatch() AND the updater), so the server
					// sees it on check_license, activate/deactivate and get_version.
					'framework_version' => Woodev_Plugin::VERSION,
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

			// If we got response code as 400, throw an exception
			if ( intval( $this->get_response_code() ) >= 400 ) {
				throw new Woodev_API_Exception( $this->get_response_message(), $this->get_response_code() );
			}

			// If response doesnt exist or response data is empty, also throw an exception
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
		protected function get_new_request( $args = [] ) {
			return new Woodev_Licensing_API_Request();
		}

		/**
		 * Get the plugin class instance associated with this API.
		 *
		 * @since 2.2.0
		 * @return Woodev_Plugin
		 */
		protected function get_plugin(): Woodev_Plugin {
			return $this->plugin;
		}
	}

endif;

<?php

defined( 'ABSPATH' ) or exit;

if( ! class_exists( 'Woodev_Async_Request' ) ) :

	/**
	 * Woodev Async Request class
	 *
	 * Based on the incredible work by deliciousbrains - most of the code is from
	 * here: https://github.com/A5hleyRich/wp-background-processing
	 *
	 * Use Woodev_Async_Request::set_data() to set request data, instead of ::data().
	 */
	abstract class Woodev_Async_Request {

		/** @var string request prefix */
		protected $prefix = 'wp';

		/** @var string request action name */
		protected $action = 'async_request';

		/** @var string request identifier */
		protected $identifier;

		/** @var array request data */
		protected $data = array();


		/**
		 * Initiate a new async request
		 */
		public function __construct() {
			$this->identifier = $this->prefix . '_' . $this->action;

			add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
			add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
		}


		/**
		 * Set data used during the async request
		 *
		 * @param array $data
		 *
		 * @return Woodev_Async_Request
		 */
		public function set_data( $data ) {
			$this->data = $data;

			return $this;
		}


		/**
		 * Dispatch the async request
		 *
		 * @return array|WP_Error
		 */
		public function dispatch() {

			$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
			$args = $this->get_request_args();

			return wp_safe_remote_get( esc_url_raw( $url ), $args );
		}


		/**
		 * Get query args
		 *
		 * @return array
		 */
		protected function get_query_args() {

			if( property_exists( $this, 'query_args' ) ) {
				return $this->query_args;
			}

			return array(
				'action' => $this->identifier,
				'nonce'  => wp_create_nonce( $this->identifier ),
			);
		}


		/**
		 * Get query URL
		 * @return string
		 */
		protected function get_query_url() {

			if( property_exists( $this, 'query_url' ) ) {
				return $this->query_url;
			}

			return admin_url( 'admin-ajax.php' );
		}


		/**
		 * Get request args
		 *
		 * @return array
		 */
		protected function get_request_args() {

			if( property_exists( $this, 'request_args' ) ) {
				return $this->request_args;
			}

			return array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'body'      => $this->data,
				'cookies'   => $_COOKIE,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			);
		}


		/**
		 * Maybe handle
		 *
		 * Check for correct nonce and pass to handler.
		 */
		public function maybe_handle() {
			check_ajax_referer( $this->identifier, 'nonce' );

			$this->handle();

			wp_die();
		}


		/**
		 * Handle
		 *
		 * Override this method to perform any actions required
		 * during the async request.
		 */
		abstract protected function handle();

	}


endif;

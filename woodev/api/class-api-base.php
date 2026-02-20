<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_API_Base' ) ) :

abstract class Woodev_API_Base {

	/** @var string request method, defaults to POST */
	protected $request_method = 'POST';

	/** @var string URI used for the request */
	protected $request_uri;

	/** @var array request headers */
	protected $request_headers = array();

	/** @var string request user-agent */
	protected $request_user_agent;

	/** @var string request HTTP version, defaults to 1.0 */
	protected $request_http_version = '1.0';

	/** @var string request duration */
	protected $request_duration;

	/** @var Woodev_API_Request|object request */
	protected $request;

	/** @var string response code */
	protected $response_code;

	/** @var string response message */
	protected $response_message;

	/** @var array response headers */
	protected $response_headers;

	/** @var string raw response body */
	protected $raw_response_body;

	/** @var string response handler class name */
	protected $response_handler;

	/** @var Woodev_API_Response|object response */
	protected $response;

	/**
	 * Perform the request and return the parsed response
	 *
	 * @param Woodev_API_Request|object $request class instance which implements Woodev_API_Request
	 * @return Woodev_API_Response|object class instance which implements Woodev_API_Response
	 * @throws Woodev_API_Exception|Woodev_Plugin_Exception may be thrown in implementations
	 */

	protected function perform_request( $request ) {

		$this->reset_response();
		$this->request = $request;
		$start_time = microtime( true );

		if ( $this->get_plugin()->require_tls_1_2() ) {
			add_action( 'http_api_curl', array( $this, 'set_tls_1_2_request' ), 10, 3 );
		}


		$response = $this->do_remote_request( $this->get_request_uri(), $this->get_request_args() );

		$this->request_duration = round( microtime( true ) - $start_time, 5 );

		try {

			$response = $this->handle_response( $response );

		} catch ( Woodev_Plugin_Exception $e ) {
			$this->broadcast_request();

			throw $e;
		}

		return $response;
	}

	/**
	 * Simple wrapper for wp_remote_request() so child classes can override this
	 * and provide their own transport mechanism if needed, e.g. a custom
	 * cURL implementation
	 *
	 * @param string $request_uri
	 * @param string $request_args
	 * @return array|WP_Error
	 */
	protected function do_remote_request( $request_uri, $request_args ) {
		return wp_safe_remote_request( $request_uri, $request_args );
	}

	/**
	 * Handle and parse the response
	 *
	 * @param array|WP_Error $response response data
	 * @throws Woodev_API_Exception network issues, timeouts, API errors, etc
	 * @return Woodev_API_Request|object request class instance that implements Woodev_API_Request
	 */
	protected function handle_response( $response ) {

		if ( is_wp_error( $response ) ) {
			throw new Woodev_API_Exception( $response->get_error_message(), (int) $response->get_error_code() );
		}

		$this->response_code     = wp_remote_retrieve_response_code( $response );
		$this->response_message  = wp_remote_retrieve_response_message( $response );
		$this->raw_response_body = wp_remote_retrieve_body( $response );

		$response_headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $response_headers ) ) {
			$response_headers = $response_headers->getAll();
		}

		$this->response_headers = $response_headers;

		$this->do_pre_parse_response_validation();

		$this->response = $this->get_parsed_response( $this->raw_response_body );

		$this->do_post_parse_response_validation();

		$this->broadcast_request();

		return $this->response;
	}

	/**
	 * Allow child classes to validate a response prior to instantiating the
	 * response object. Useful for checking response codes or messages, e.g.
	 * throw an exception if the response code is not 200.
	 *
	 * A child class implementing this method should simply return true if the response
	 * processing should continue, or throw a Woodev_API_Exception with a
	 * relevant error message & code to stop processing.
	 *
	 * Note: Child classes *must* sanitize the raw response body before throwing
	 * an exception, as it will be included in the broadcast_request() method
	 * which is typically used to log requests.
	 *
	 */
	protected function do_pre_parse_response_validation() {}

	/**
	 * Allow child classes to validate a response after it has been parsed
	 * and instantiated. This is useful for check error codes or messages that
	 * exist in the parsed response.
	 *
	 * A child class implementing this method should simply return true if the response
	 * processing should continue, or throw a Woodev_API_Exception with a
	 * relevant error message & code to stop processing.
	 *
	 * Note: Response body sanitization is handled automatically
	 *
	 */
	protected function do_post_parse_response_validation() {}

	/**
	 * Return the parsed response object for the request
	 *
	 * @param string $raw_response_body
	 * @return object|Woodev_API_Request response class instance which implements Woodev_API_Request
	 */
	protected function get_parsed_response( $raw_response_body ) {

		$handler_class = $this->get_response_handler();

		return new $handler_class( $raw_response_body );
	}

	/**
	 * Alert other actors that a request has been performed. This is primarily used
	 * for request logging.
	 *
	 */
	protected function broadcast_request() {

		$request_data  = $this->get_request_data_for_broadcast();
		$response_data = $this->get_response_data_for_broadcast();

		/**
		 * API Base Request Performed Action.
		 *
		 * Fired when an API request is performed via this base class. Plugins can
		 * hook into this to log request/response data.
		 *
		 * @param array $request_data {
		 *     @type string $method request method, e.g. POST
		 *     @type string $uri request URI
		 *     @type string $user-agent
		 *     @type string $headers request headers
		 *     @type string $body request body
		 *     @type string $duration in seconds
		 * }
		 * @param array $response data {
		 *     @type string $code response HTTP code
		 *     @type string $message response message
		 *     @type string $headers response HTTP headers
		 *     @type string $body response body
		 * }
		 * @param Woodev_API_Base $this instance
		 */

		do_action( 'woodev_' . $this->get_api_id() . '_api_request_performed', $request_data, $response_data, $this );
	}

	protected function reset_response() {
		$this->response_code     = null;
		$this->response_message  = null;
		$this->response_headers  = null;
		$this->raw_response_body = null;
		$this->response          = null;
		$this->request_duration  = null;
	}

	/**
	 * Get the request URI
	 *
	 * @return string
	 */
	protected function get_request_uri() {

		$uri = $this->request_uri . $this->get_request_path();

		return apply_filters( 'woodev_' . $this->get_api_id() . '_api_request_uri', $uri, $this );
	}

	/**
	 * Gets the request path.
	 *
	 * @return string
	 */
	protected function get_request_path() {

		return ( $this->get_request() ) ? $this->get_request()->get_path() : '';
	}

	protected function get_request_args() {

		$args = array(
			'method'      => $this->get_request_method(),
			'timeout'     => MINUTE_IN_SECONDS,
			'redirection' => 0,
			'httpversion' => $this->get_request_http_version(),
			'sslverify'   => apply_filters( 'woodev_sl_api_request_verify_ssl', true, $this ),
			'blocking'    => true,
			'user-agent'  => $this->get_request_user_agent(),
			'headers'     => $this->get_request_headers(),
			'body'        => $this->get_request_body(),
			'cookies'     => array(),
		);

		return apply_filters( 'woodev_' . $this->get_api_id() . '_http_request_args', $args, $this );
	}

	protected function get_request_method() {
		return $this->get_request() && $this->get_request()->get_method() ? $this->get_request()->get_method() : $this->request_method;
	}

	/**
	 * Gets the request body.
	 *
	 * @return string
	 */
	protected function get_request_body() {

		if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
			return '';
		}

		return ( $this->get_request() && $this->get_request()->to_string() ) ? $this->get_request()->to_string() : '';
	}


	/**
	 * Gets the sanitized request body, for logging.
	 *
	 * @return string
	 */
	protected function get_sanitized_request_body() {

		if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
			return '';
		}

		return ( $this->get_request() && $this->get_request()->to_string_safe() ) ? $this->get_request()->to_string_safe() : '';
	}

	protected function get_request_http_version() {
		return $this->request_http_version;
	}

	protected function get_request_headers() {
		return $this->request_headers;
	}

	protected function get_sanitized_request_headers() {

		$headers = $this->get_request_headers();

		if ( ! empty( $headers['Authorization'] ) ) {
			$headers['Authorization'] = str_repeat( '*', strlen( $headers['Authorization'] ) );
		}

		return $headers;
	}

	protected function get_request_user_agent() {

		$plugin_name = $this->get_plugin()->get_plugin_name();
		$plugin_version = $this->get_plugin()->get_version();
		$wc_version = Woodev_Helper::get_wc_version();
		$wp_version = $GLOBALS['wp_version'];

		if( ! is_null( $wc_version ) ) {
			return sprintf( '%s/%s (WooCommerce/%s; WordPress/%s)', str_replace( ' ', '-', $plugin_name ), $plugin_version, $wc_version, $wp_version );
		}

		return sprintf( '%s/%s (WordPress/%s)', str_replace( ' ', '-', $plugin_name ), $plugin_version, $wp_version );
	}

	protected function get_request_duration() {
		return $this->request_duration;
	}

	/**
	 * Gets the request data for broadcasting the request.
	 * Overriding this method allows child classes to customize the request data when broadcasting the request.
	 *
	 * @return array
	 */
	protected function get_request_data_for_broadcast() {
		return array(
			'method'     => $this->get_request_method(),
			'uri'        => $this->get_request_uri(),
			'user-agent' => $this->get_request_user_agent(),
			'headers'    => $this->get_sanitized_request_headers(),
			'body'       => $this->get_sanitized_request_body(),
			'duration'   => $this->get_request_duration() . 's', // seconds
		);
	}

	protected function get_response_handler() {
		return $this->response_handler;
	}

	protected function get_response_code() {
		return $this->response_code;
	}

	protected function get_response_message() {
		return $this->response_message;
	}

	protected function get_response_headers() {
		return $this->response_headers;
	}

	protected function get_raw_response_body() {
		return $this->raw_response_body;
	}

	protected function get_sanitized_response_body() {
		return is_callable( array( $this->get_response(), 'to_string_safe' ) ) ? $this->get_response()->to_string_safe() : null;
	}

	/**
	 * Gets the response data for broadcasting the request.
	 * Overriding this method allows child classes to customize the response data when broadcasting the request.
	 *
	 * @return array
	 */
	protected function get_response_data_for_broadcast() {
		return array(
			'code'    => $this->get_response_code(),
			'message' => $this->get_response_message(),
			'headers' => $this->get_response_headers(),
			'body'    => $this->get_sanitized_response_body() ? $this->get_sanitized_response_body() : $this->get_raw_response_body()
		);
	}

	public function get_request() {
		return $this->request;
	}

	public function get_response() {
		return $this->response;
	}

	protected function get_api_id() {

		return $this->get_plugin()->get_id();
	}

	abstract protected function get_new_request( $args = array() );

	abstract protected function get_plugin();

	protected function set_request_header( $name, $value ) {
		$this->request_headers[ $name ] = $value;
	}

	protected function set_request_headers( array $headers ) {

		foreach ( $headers as $name => $value ) {

			$this->request_headers[ $name ] = $value;
		}
	}

	protected function set_http_basic_auth( $username, $password ) {
		$this->request_headers['Authorization'] = sprintf( 'Basic %s', base64_encode( "{$username}:{$password}" ) );
	}

	protected function set_request_content_type_header( $content_type ) {
		$this->request_headers['content-type'] = $content_type;
	}

	protected function set_request_accept_header( $type ) {
		$this->request_headers['accept'] = $type;
	}

	protected function set_response_handler( $handler ) {
		$this->response_handler = $handler;
	}

	public function set_tls_1_2_request( $handle, $r, $url ) {

		if ( ! Woodev_Helper::str_starts_with( $url, 'https://' ) ) {
			return;
		}

		curl_setopt( $handle, CURLOPT_SSLVERSION, 6 );
	}

	public function require_tls_1_2() {
		wc_deprecated_function( __METHOD__, '1.1.6', 'Woodev_Plugin::require_tls_1_2()' );
		return $this->get_plugin()->require_tls_1_2();
	}

	public function is_tls_1_2_available() {
		return ( bool ) apply_filters( 'woodev_' . $this->get_plugin()->get_id() . '_api_is_tls_1_2_available', $this->get_plugin()->is_tls_1_2_available(), $this );
	}

}

endif;

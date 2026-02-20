<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Cacheable_API_Base' ) ) :

abstract class Woodev_Cacheable_API_Base extends Woodev_API_Base {

	/** @var bool whether the response was loaded from cache */
	protected $response_loaded_from_cache = false;

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

		if ( $this->is_request_cacheable() && ! $this->get_request()->should_refresh() && $response = $this->load_response_from_cache() ) {

			$this->response_loaded_from_cache = true;
			return $response;
		}

		return parent::do_remote_request( $request_uri, $request_args );
	}

	/**
	 * Handle and parse the response
	 *
	 * @param array|WP_Error $response response data
	 * @throws Woodev_API_Exception network issues, timeouts, API errors, etc
	 * @return Woodev_API_Request|object request class instance that implements Woodev_API_Request
	 */
	protected function handle_response( $response ) {

		parent::handle_response( $response );

		// cache the response
		if ( ! $this->is_response_loaded_from_cache() && $this->is_request_cacheable() ) {
			$this->save_response_to_cache( $response );
		}

		return $this->response; // this param is set by the parent method
	}

	/**
	 * Resets the API response members to their default values.
	 */
	protected function reset_response() {
		$this->response_loaded_from_cache = false;
		parent::reset_response();
	}

	/**
	 * Gets the request transient key for the current plugin and request data.
	 *
	 * Request transients can be disabled by using the filter below.
	 *
	 * @return string transient key
	 */
	protected function get_request_transient_key() {
		return sprintf( 'woodev_%s_api_response_%s', $this->get_plugin()->get_id(), md5( implode( '_', array(
			$this->get_request_uri(),
			$this->get_request_body(),
			$this->get_request_cache_lifetime()
		) ) ) );
	}

	/**
	 * Checks whether the current request is cacheable.
	 *
	 * @return bool
	 */
	protected function is_request_cacheable() {

		if ( ! in_array( Woodev_Cacheable_Request_Trait::class, class_uses( $this->get_request() ), true ) ) {
			return false;
		}

		return ( bool ) apply_filters( 'woodev_plugin_' . $this->get_plugin()->get_id() . '_api_request_is_cacheable', true, $this->get_request() );
	}

	/**
	 * Gets the cache lifetime for the current request.
	 *
	 * @return int
	 */
	protected function get_request_cache_lifetime() {
		return ( int ) apply_filters( 'woodev_plugin_' . $this->get_plugin()->get_id() . '_api_request_cache_lifetime' , $this->get_request()->get_cache_lifetime(), $this->get_request() );
	}

	/**
	 * Determine whether the response was loaded from cache or not.
	 *
	 * @return bool
	 */
	protected function is_response_loaded_from_cache() {
		return $this->response_loaded_from_cache;
	}


	/**
	 * Loads the response for the current request from the cache, if available.
	 *
	 * @return array|null
	 */
	protected function load_response_from_cache() {
		return get_transient( $this->get_request_transient_key() );
	}


	/**
	 * Saves the response to cache.
	 *
	 * @param array $response
	 */
	protected function save_response_to_cache( array $response ) {
		set_transient( $this->get_request_transient_key(), $response, $this->get_request_cache_lifetime() );
	}


	/**
	 * Gets the response data for broadcasting the request.
	 * Adds a flag to the response data indicating whether the response was loaded from cache.
	 *
	 * @return array
	 */
	protected function get_request_data_for_broadcast() {

		$request_data = parent::get_request_data_for_broadcast();

		if ( $this->is_request_cacheable() ) {
			$request_data = array_merge( $request_data, array(
				'force_refresh' => $this->get_request()->should_refresh(),
				'should_cache'  => $this->get_request()->should_cache()
			) );
		}

		return $request_data;
	}


	/**
	 * Gets the response data for broadcasting the request.
	 * Adds a flag to the response data indicating whether the response was loaded from cache.
	 *
	 * @return array
	 */
	protected function get_response_data_for_broadcast() {

		$response_data = parent::get_response_data_for_broadcast();

		if ( $this->is_request_cacheable() ) {
			$response_data = array_merge( $response_data, array( 'from_cache' => $this->is_response_loaded_from_cache() ) );
		}

		return $response_data;
	}
}

endif;

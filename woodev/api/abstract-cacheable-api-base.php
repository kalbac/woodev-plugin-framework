<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Cacheable_API_Base' ) ) :

	abstract class Woodev_Cacheable_API_Base extends Woodev_API_Base {

		/** Default cap on a cached response's serialized size, in bytes. @see get_request_cache_max_bytes() */
		public const DEFAULT_CACHE_MAX_BYTES = 524288;

		/** @var bool whether the response was loaded from cache */
		protected $response_loaded_from_cache = false;

		/** @var array<string, true> request keys already reported as too big to cache, this page load */
		private static $oversized_response_logged = [];

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

			// cache the response, unless the request opted out of the write (`set_should_cache( false )`, `bypass_cache()`) (#1004)
			if ( ! $this->is_response_loaded_from_cache() && $this->is_request_cacheable() && $this->get_request()->should_cache() ) {
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
			return sprintf(
				'woodev_%s_api_response_%s',
				$this->get_plugin()->get_id(),
				md5(
					implode(
						'_',
						[
							$this->get_request_uri(),
							$this->get_request_body(),
							$this->get_request_cache_lifetime(),
						]
					)
				)
			);
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

			return (bool) apply_filters( 'woodev_plugin_' . $this->get_plugin()->get_id() . '_api_request_is_cacheable', true, $this->get_request() );
		}

		/**
		 * Gets the cache lifetime for the current request.
		 *
		 * @return int
		 */
		protected function get_request_cache_lifetime() {
			return (int) apply_filters( 'woodev_plugin_' . $this->get_plugin()->get_id() . '_api_request_cache_lifetime', $this->get_request()->get_cache_lifetime(), $this->get_request() );
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
		 * Cache only the transport data that this API base consumes when it reparses
		 * a cached response: the body, response code/message and headers. Header
		 * values are redacted through the same seam as the request log, while parsed
		 * cookies and the HTTP response object are deliberately omitted: neither is
		 * used to resume a remote session when a request is served from this cache,
		 * and both can retain Set-Cookie credentials in the site database.
		 *
		 * The remaining non-secret headers are intentional. The shipped Edostavka
		 * plugin forwards its cached `X-Current-Page`, `X-Total-Elements` and
		 * `X-Total-Pages` headers to its delivery-points AJAX client. Transients are
		 * disposable, and no framework or shipped-plugin consumer reads their raw
		 * value directly; this reduced shape is therefore safe for existing entries
		 * and avoids treating a raw wp_remote_* result as an installed-site contract.
		 *
		 * A response whose serialized form is above the cap from
		 * {@see get_request_cache_max_bytes()} is not cached at all (#952); the
		 * request itself is unaffected. Any entry already stored under the same key
		 * is deleted, so a forced refresh never leaves a stale older response to be
		 * served on the next request.
		 *
		 * @since 2.0.2
		 * @param array $response
		 * @return void
		 */
		protected function save_response_to_cache( array $response ) {
			$cached_response = [
				'headers'  => (array) $this->get_sanitized_response_headers(),
				'body'     => $this->get_raw_response_body(),
				'response' => [
					'code'    => $this->get_response_code(),
					'message' => $this->get_response_message(),
				],
			];

			// Measure the serialized form that set_transient() will actually store.
			$max_bytes = $this->resolve_request_cache_max_bytes();
			$size      = $max_bytes > 0 ? strlen( maybe_serialize( $cached_response ) ) : 0;

			if ( $max_bytes > 0 && $size > $max_bytes ) {
				$this->log_oversized_response_skipped( $size, $max_bytes );

				// Drop the older entry too: this path is reached on a forced refresh, whose contract is "replace what is cached".
				delete_transient( $this->get_request_transient_key() );
				return;
			}

			set_transient( $this->get_request_transient_key(), $cached_response, $this->get_request_cache_lifetime() );
		}

		/**
		 * Gets the default cap, in bytes, on a response written to the response cache.
		 *
		 * A response whose serialized form is above the cap is not cached; the request
		 * itself still succeeds and returns normally. The cap applies to whatever
		 * backs transients. Without a persistent object cache that is a single
		 * `wp_options` row per response, and MySQL refuses a statement bigger than
		 * `max_allowed_packet` with «MySQL server has gone away» — 4 MB by default on
		 * MySQL 5.7, 64 MB on 8.0, and often lower on shared hosting. With an external
		 * object cache (Redis, Memcached) the same cap still applies, since such
		 * stores limit item size too (Memcached: 1 MB by default). One entry must
		 * stay far below those limits, so the default is 512 KB. A carrier reference
		 * dump (pickup points, a full location directory) is what crosses it.
		 *
		 * An API class overrides this method to set its own cap; the
		 * `woodev_plugin_{plugin_id}_api_request_cache_max_bytes` filter then has the
		 * last word (see {@see resolve_request_cache_max_bytes()}). `0` or a negative
		 * value means no cap.
		 *
		 * @since 2.0.2
		 *
		 * @return int maximum serialized size in bytes; 0 or negative for no cap
		 */
		protected function get_request_cache_max_bytes(): int {
			return self::DEFAULT_CACHE_MAX_BYTES;
		}

		/**
		 * Gets the cap that applies to the current request: the class's own value, filtered.
		 *
		 * A filter value that is not numeric (`false`, `null`, `''`, `'1M'`) is ignored
		 * and the class's own value is used, so a broken filter never lifts the cap.
		 *
		 * @return int maximum serialized size in bytes; 0 or negative for no cap
		 */
		private function resolve_request_cache_max_bytes(): int {

			$default  = $this->get_request_cache_max_bytes();
			$filtered = apply_filters( 'woodev_plugin_' . $this->get_plugin()->get_id() . '_api_request_cache_max_bytes', $default, $this->get_request() );

			return is_numeric( $filtered ) ? (int) $filtered : $default;
		}

		/**
		 * Logs, once per request key per page load, that a response was too big to cache.
		 *
		 * The message carries only the hashed transient key and the sizes: never the
		 * URI or the body, which can hold credentials.
		 *
		 * @param int $size      serialized size of the response, in bytes
		 * @param int $max_bytes the cap that was exceeded, in bytes
		 * @return void
		 */
		private function log_oversized_response_skipped( int $size, int $max_bytes ): void {

			$key = $this->get_request_transient_key();

			if ( isset( self::$oversized_response_logged[ $key ] ) ) {
				return;
			}

			self::$oversized_response_logged[ $key ] = true;

			$this->get_plugin()->log_debug(
				sprintf(
					'API response not cached: %1$d bytes exceeds the %2$d byte cache limit (request key %3$s).',
					$size,
					$max_bytes,
					$key
				)
			);
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
				$request_data = array_merge(
					$request_data,
					[
						'force_refresh' => $this->get_request()->should_refresh(),
						'should_cache'  => $this->get_request()->should_cache(),
					]
				);
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
				$response_data = array_merge( $response_data, [ 'from_cache' => $this->is_response_loaded_from_cache() ] );
			}

			return $response_data;
		}
	}

endif;

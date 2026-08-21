<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_API_JSON_Request' ) ) :

	abstract class Woodev_API_JSON_Request implements Woodev_API_Request {

		protected $method;

		protected $path;

		protected $params = array();

		public function get_method() {
			return $this->method;
		}

		public function get_path() {
			return $this->path;
		}

		public function get_params() {
			return $this->params;
		}

		public function to_string() {

			$params = $this->get_params();

			return ! empty( $params ) ? wp_json_encode( $params ) : '';
		}

		/**
		 * Gets the sanitized request body, for logging.
		 *
		 * Fail-safe default: masks every param named in
		 * {@see self::get_secret_param_names()} before serializing, instead of
		 * aliasing {@see self::to_string()} outright — the previous default,
		 * which meant any subclass that forgot to override this method logged
		 * its FULL raw body, credentials included, by default. A subclass with a
		 * concrete `to_string()` format of its own (e.g.
		 * {@see Woodev_Licensing_API_Request}, which uses `print_r()` rather than
		 * JSON) should still override this too, rather than rely on this generic
		 * fallback — see #395 (Blocking 1).
		 *
		 * @since 2.0.2 masks known secret params instead of aliasing {@see self::to_string()}.
		 *
		 * @return string
		 */
		public function to_string_safe() {

			$params = $this->get_params();

			if ( empty( $params ) ) {
				return $this->to_string();
			}

			$params = Woodev_API_Base::mask_secret_values( $params, $this->get_secret_param_names() );

			return wp_json_encode( $params );
		}

		/**
		 * Names of request params whose values carry a credential and must be
		 * masked before {@see self::to_string_safe()} hands the request off to
		 * logging. Defaults to the same generic list
		 * {@see Woodev_API_Base::get_secret_param_names()} uses for request
		 * paths/URIs, so a param name known to be secret is masked in the body
		 * the same way it is masked in the query string — one list, not two that
		 * can drift apart. Override to extend it, the same pattern as
		 * {@see Woodev_API_Base::get_secret_header_names()}.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int, string>
		 */
		protected function get_secret_param_names(): array {
			return Woodev_API_Base::get_default_secret_param_names();
		}
	}

endif;

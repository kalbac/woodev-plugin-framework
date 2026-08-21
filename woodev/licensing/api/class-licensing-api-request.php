<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Licensing_API_Request' ) ) :

	class Woodev_Licensing_API_Request extends Woodev_API_JSON_Request {

		public function get_license( $params ) {
			$this->method = 'POST';
			$this->params = $params;
		}

		public function get_path() {

			$path   = $this->path;
			$params = $this->get_params();

			if ( in_array( $this->get_method(), array( 'GET', 'POST' ) ) && ! empty( $params ) ) {
				$path .= '?' . http_build_query( $this->get_params(), '', '&' );
			}

			return $path;
		}


		/**
		 * Gets the sanitized request path, for logging.
		 *
		 * Same as {@see self::get_path()} but the query string is built from
		 * {@see self::get_masked_params()} instead of the raw params, so the
		 * `license` param never reaches the log — see #395. The actual request
		 * sent to the server is unaffected: {@see self::get_path()} is untouched
		 * and still builds the real query string with the real license key.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_path_safe() {

			$path   = $this->path;
			$params = $this->get_params();

			if ( in_array( $this->get_method(), array( 'GET', 'POST' ) ) && ! empty( $params ) ) {
				$path .= '?' . http_build_query( $this->get_masked_params(), '', '&' );
			}

			return $path;
		}

		public function to_string() {

			if ( in_array( $this->get_method(), array( 'GET', 'POST' ), true ) ) {
				// return wp_json_encode( $this->get_params() );
				return self::print_r( $this->get_params(), true );
			}
		}

		/**
		 * Gets the sanitized request body, for logging.
		 *
		 * Same as {@see self::to_string()} but dumps {@see self::get_masked_params()}
		 * instead of the raw params, so the `license` param never reaches the
		 * log — see #395. {@see self::to_string()} (used to build the real
		 * request body sent to the server) is untouched.
		 *
		 * @since 2.0.2 actually sanitizes, instead of aliasing {@see self::to_string()}.
		 *
		 * @return string|bool|null
		 */
		public function to_string_safe() {

			if ( in_array( $this->get_method(), array( 'GET', 'POST' ), true ) ) {
				return self::print_r( $this->get_masked_params(), true );
			}

			return null;
		}

		/**
		 * Names of request params whose values carry a credential and must be
		 * masked before {@see self::get_path_safe()} or {@see self::to_string_safe()}
		 * hand the request off to logging — see #395.
		 *
		 * `license` is already in the parent's generic default list (see
		 * {@see Woodev_API_Base::get_default_secret_param_names()}), so this
		 * override is redundant with it today — kept anyway, `protected` instead
		 * of `private`, so this class documents its OWN known secret explicitly
		 * and stays correct even if the generic default list ever changes.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 widened from `private` to `protected` and merged with the
		 *              parent's list instead of replacing it outright.
		 *
		 * @return array<int, string>
		 */
		protected function get_secret_param_names(): array {
			return array_merge( parent::get_secret_param_names(), [ 'license' ] );
		}

		/**
		 * Masks the VALUE of every param named in {@see self::get_secret_param_names()}.
		 *
		 * Reuses {@see Woodev_API_Base::mask_secret_values()} — the same fixed
		 * placeholder ({@see Woodev_API_Base::SECRET_VALUE_MASK}) already applied
		 * to request/response headers, so a log reader sees one consistent masking
		 * style everywhere instead of a second, differently-shaped one.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 reuses the renamed {@see Woodev_API_Base::mask_secret_values()}.
		 *
		 * @return array<string, mixed>
		 */
		private function get_masked_params(): array {
			return Woodev_API_Base::mask_secret_values( $this->get_params(), $this->get_secret_param_names() );
		}

		/**
		 * Stringifies request params without WooCommerce helper dependencies.
		 *
		 * @param mixed $expression Value to stringify.
		 * @param bool  $return Whether to return the output instead of printing it.
		 * @return string|bool
		 */
		private static function print_r( $expression, $return = false ) {

			if ( function_exists( 'wc_print_r' ) ) {
				return wc_print_r( $expression, $return );
			}

			$alternatives = [
				[
					'func' => 'print_r',
					'args' => [ $expression, true ],
				],
				[
					'func' => 'var_export',
					'args' => [ $expression, true ],
				],
				[
					'func' => 'json_encode',
					'args' => [ $expression ],
				],
				[
					'func' => 'serialize',
					'args' => [ $expression ],
				],
			];

			$alternatives = apply_filters( 'woocommerce_print_r_alternatives', $alternatives, $expression );

			foreach ( $alternatives as $alternative ) {

				if ( empty( $alternative['func'] ) || ! function_exists( $alternative['func'] ) ) {
					continue;
				}

				$result = $alternative['func']( ...( $alternative['args'] ?? [] ) );

				if ( $return ) {
					return $result;
				}

				echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			}

			return false;
		}
	}

endif;

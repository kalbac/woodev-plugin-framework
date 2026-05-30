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

		public function to_string() {

			if ( in_array( $this->get_method(), array( 'GET', 'POST' ), true ) ) {
				// return wp_json_encode( $this->get_params() );
				return self::print_r( $this->get_params(), true );
			}
		}

		public function to_string_safe() {
			return $this->to_string();
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

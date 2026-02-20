<?php

defined( 'ABSPATH' ) or exit;

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

			if( in_array( $this->get_method(), array( 'GET', 'POST' ), true ) ) {
				//return wp_json_encode( $this->get_params() );
				return wc_print_r( $this->get_params(), true );
			}
		}

		public function to_string_safe() {
			return $this->to_string();
		}
	}

endif;
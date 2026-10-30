<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_API_JSON_Response' ) ) :

	/**
	 * @property mixed $response_data Decoded response body, parsed on first access.
	 */
	abstract class Woodev_API_JSON_Response implements Woodev_API_Response {
		protected $raw_response_json;

		/**
		 * @var mixed|null
		 */
		private $decoded_response_data;

		/**
		 * @var bool
		 */
		private $response_data_decoded = false;

		public $response_data;

		public function __construct( $raw_response_json ) {
			$this->raw_response_json = $raw_response_json;
			unset( $this->response_data );
		}

		public function __get( $name ) {
			if ( 'response_data' === $name ) {
				return $this->get_decoded_response_data();
			}

			$response_data = $this->get_decoded_response_data();

			return isset( $response_data->$name ) ? $response_data->$name : null;
		}

		public function __isset( $name ) {
			if ( 'response_data' === $name ) {
				return null !== $this->get_decoded_response_data();
			}

			$response_data = $this->get_decoded_response_data();

			return isset( $response_data->$name );
		}

		/**
		 * Decode the raw response body.
		 *
		 * @since 2.0.6
		 * @return mixed
		 */
		protected function decode_response_data() {
			return json_decode( $this->raw_response_json );
		}

		/**
		 * Decode and cache the response body on demand.
		 *
		 * @since 2.0.6
		 * @return mixed
		 */
		private function get_decoded_response_data() {
			if ( ! $this->response_data_decoded ) {
				$this->decoded_response_data = $this->decode_response_data();
				$this->response_data_decoded = true;
			}

			return $this->decoded_response_data;
		}

		public function to_string() {
			return $this->raw_response_json;
		}

		public function to_string_safe() {
			return $this->to_string();
		}
	}

endif;

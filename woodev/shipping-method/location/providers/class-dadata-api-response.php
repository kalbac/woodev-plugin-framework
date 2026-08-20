<?php
/**
 * Woodev DaData API Response
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Providers\\Dadata_Api_Response' ) ) :

	/**
	 * DaData API response. Decodes JSON in OBJECT mode (parent default, matching
	 * every other `Woodev_API_JSON_Response` subclass in this codebase — see
	 * `Woodev_Licencing_API_Response`, `WD_Edostavka_Dadata_API_Response`) and
	 * converts to plain associative arrays lazily, per accessor, via
	 * {@see self::to_associative()} — so {@see Dadata_Provider}'s mapping code
	 * works with ordinary arrays without this class deviating from the
	 * established decode convention.
	 *
	 * @since 2.0.2
	 */
	class Dadata_Api_Response extends \Woodev_API_JSON_Response {

		/**
		 * Gets every raw suggestion from a `suggest/address` or `findById/address`
		 * response body (`{ suggestions: [ { value, unrestricted_value, data }, … ] }`).
		 *
		 * THROWS {@see \Woodev_API_Exception} (#405) when the decoded body is not that
		 * shape — malformed JSON (`json_decode()` failure leaves `$response_data` `null`),
		 * or valid JSON that is not an object carrying an array `suggestions` — instead
		 * of degrading to `[]`. A `200 OK` whose body could not be understood is a FAILED
		 * request, not an empty one; {@see Dadata_Provider::suggest()}'s own try/catch is
		 * what turns this into {@see \Woodev\Framework\Shipping\Location\Location_Provider_Exception},
		 * same as it already does for the HTTP-level failures
		 * {@see Dadata_Api_Client::do_post_parse_response_validation()} throws.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Throws on a malformed/wrongly-shaped body instead of degrading
		 *              to `[]` (#405).
		 *
		 * @return array<int, array<string, mixed>>
		 *
		 * @throws \Woodev_API_Exception
		 */
		public function get_suggestions(): array {
			if ( ! is_object( $this->response_data ) || ! isset( $this->response_data->suggestions ) || ! is_array( $this->response_data->suggestions ) ) {
				throw new \Woodev_API_Exception( 'DaData suggest response body is malformed or of the wrong shape.' );
			}

			return array_map( [ $this, 'to_associative' ], $this->response_data->suggestions );
		}

		/**
		 * Gets the raw `location` object from an `iplocate/address` response body
		 * (`{ location: { value, unrestricted_value, data } }`), or null when
		 * absent (DaData resolved nothing for the requested IP).
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>|null
		 */
		public function get_location(): ?array {
			if ( ! is_object( $this->response_data ) || ! isset( $this->response_data->location ) ) {
				return null;
			}

			return $this->to_associative( $this->response_data->location );
		}

		/**
		 * Gets the raw clean-result object from a `clean/address` response body.
		 * Defensively accepts either a top-level array (batch shape, matching the
		 * request's own array-of-queries body) or a single bare object — see
		 * {@see Dadata_Api_Client::clean_address()} for why this is not fully
		 * verified against a live capture.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>|null
		 */
		public function get_clean_result(): ?array {
			$data = $this->response_data;

			if ( is_array( $data ) ) {
				$data = $data[0] ?? null;
			}

			if ( ! is_object( $data ) ) {
				return null;
			}

			return $this->to_associative( $data );
		}

		/**
		 * Recursively converts a `json_decode()` (object-mode) value tree into
		 * plain associative arrays/scalars.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value A decoded JSON value (object, array, or scalar).
		 *
		 * @return mixed
		 */
		private function to_associative( $value ) {
			if ( is_object( $value ) ) {
				$value = get_object_vars( $value );
			}

			if ( is_array( $value ) ) {
				return array_map( [ $this, 'to_associative' ], $value );
			}

			return $value;
		}
	}

endif;

<?php
/**
 * Woodev DaData API Request
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Providers\\Dadata_Api_Request' ) ) :

	/**
	 * DaData API request. One instance per call; the public methods below each
	 * configure one endpoint (method, path, params) — mirrors
	 * `WD_Edostavka_Dadata_API_Request`'s own shape (one method per endpoint,
	 * `get_path()` appending a GET query string).
	 *
	 * @since 2.0.2
	 */
	class Dadata_Api_Request extends \Woodev_API_JSON_Request {

		/**
		 * Configures a `POST suggest/address` request.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $body Request body (`query`, `count`,
		 *                                    `from_bound`, `to_bound`, `locations`, …).
		 *
		 * @return void
		 */
		public function suggest_address( array $body ): void {
			$this->method = 'POST';
			$this->path   = '/suggest/address';
			$this->params = $body;
		}

		/**
		 * Configures a `GET iplocate/address` request.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $params Query params (`ip`).
		 *
		 * @return void
		 */
		public function iplocate_address( array $params ): void {
			$this->method = 'GET';
			$this->path   = '/iplocate/address';
			$this->params = $params;
		}

		/**
		 * Configures a `POST findById/address` request.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $body Request body (`query` = the FIAS id).
		 *
		 * @return void
		 */
		public function find_by_id_address( array $body ): void {
			$this->method = 'POST';
			$this->path   = '/findById/address';
			$this->params = $body;
		}

		/**
		 * Configures a `POST address` request against the cleaner host (full URL:
		 * `https://cleaner.dadata.ru/api/v1/clean/address`).
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, string> $body The batch query list (a JSON ARRAY, not
		 *                                  an object — see {@see Dadata_Api_Client::clean_address()}).
		 *
		 * @return void
		 */
		public function clean_address( array $body ): void {
			$this->method = 'POST';
			$this->path   = '/address';
			$this->params = $body;
		}

		/**
		 * {@inheritDoc}
		 *
		 * GET requests carry their params as a query string (mirrors
		 * `WD_Edostavka_Dadata_API_Request::get_path()` exactly); POST/PUT/DELETE
		 * carry them in the body ({@see self::to_string()}).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_path() {
			$path   = $this->path;
			$params = $this->get_params();

			if ( 'GET' === $this->get_method() && ! empty( $params ) ) {
				$path .= '?' . http_build_query( $params, '', '&' );
			}

			return $path;
		}

		/**
		 * {@inheritDoc}
		 *
		 * `wp_json_encode()` on an INDEXED array (as {@see self::clean_address()}
		 * builds) produces a JSON array (`["..."]`), matching the Clean API's own
		 * batch request shape without any special-casing here.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function to_string() {
			if ( 'GET' === $this->get_method() ) {
				return '';
			}

			$params = $this->get_params();

			return [] !== $params ? (string) wp_json_encode( $params ) : '';
		}
	}

endif;

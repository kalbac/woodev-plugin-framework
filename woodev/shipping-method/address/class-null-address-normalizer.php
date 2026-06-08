<?php
/**
 * Woodev Null Address Normalizer
 *
 * Framework default Address_Normalizer: a no-op Null Object used when no
 * provider-backed normalizer (e.g. DaData) is supplied by the host plugin.
 * It offers no suggestions and leaves addresses unchanged, so the shipping
 * module can always depend on an Address_Normalizer being present.
 *
 * Pure PHP — no WooCommerce calls. See
 * docs-internal/platform-v2-s1-shipping-spec.md §4.1.vii.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Address;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Address\\Null_Address_Normalizer' ) ) :

	/**
	 * No-op address normalizer.
	 *
	 * @since 1.5.0
	 */
	class Null_Address_Normalizer implements Address_Normalizer {

		/**
		 * Returns no suggestions.
		 *
		 * @since 1.5.0
		 *
		 * @param string $query ignored; the null normalizer has no provider to query
		 *
		 * @return array always empty
		 */
		public function suggest( string $query ): array {
			return [];
		}


		/**
		 * Returns the address unchanged.
		 *
		 * With no provider to parse against, the input is returned as-is wrapped
		 * in a single-element list, satisfying the array return contract without
		 * altering the address.
		 *
		 * @since 1.5.0
		 *
		 * @param string $address the address to normalize
		 *
		 * @return array the input address, unchanged
		 */
		public function normalize( string $address ): array {
			return [ $address ];
		}
	}

endif;

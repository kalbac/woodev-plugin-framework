<?php
/**
 * Woodev Address Normalizer Interface
 *
 * Defines the pluggable seam for address suggestion and normalization.
 * Address normalization (e.g. DaData) is never baked into the framework: a
 * concrete normalizer ships only in the plugin that holds the provider token.
 * The framework default is the no-op Null_Address_Normalizer.
 *
 * Pure PHP — no WooCommerce calls — so it stays unit-testable and reusable
 * off-WC. See docs-internal/platform-v2-s1-shipping-spec.md §4.1.vii.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Address;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Address\\Address_Normalizer' ) ) :

	/**
	 * Address normalization contract.
	 *
	 * Implementations wrap an address-data provider (such as DaData) to offer
	 * autocomplete suggestions and to resolve a free-form address into its
	 * structured components.
	 *
	 * @since 1.5.0
	 */
	interface Address_Normalizer {

		/**
		 * Returns address suggestions for a partial, free-form query.
		 *
		 * Used to power checkout address autocomplete. Implementations without a
		 * configured provider return an empty list.
		 *
		 * @since 1.5.0
		 *
		 * @param string $query partial address typed by the customer
		 *
		 * @return array list of suggestion entries; empty when no provider is configured
		 */
		public function suggest( string $query ): array;


		/**
		 * Normalizes a free-form address into structured components.
		 *
		 * Implementations without a configured provider return the input
		 * unchanged rather than attempting to parse it.
		 *
		 * @since 1.5.0
		 *
		 * @param string $address free-form address string
		 *
		 * @return array structured address data; the unchanged input when no provider is configured
		 */
		public function normalize( string $address ): array;
	}

endif;

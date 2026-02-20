<?php
/**
 * Woodev Shipping Method Postal
 *
 * Abstract base class for postal delivery methods.
 * Requires a valid postal code from the customer.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Method_Postal' ) ) :

	abstract class Shipping_Method_Postal extends Shipping_Method {

		/**
		 * Gets the delivery type.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		final public function get_delivery_type(): string {
			return self::TYPE_POSTAL;
		}

		/**
		 * Gets postal-specific form fields.
		 *
		 * @since 1.5.0
		 *
		 * @return array form fields definition
		 */
		protected function get_extra_form_fields(): array {

			return [
				'postal_section'      => [
					'title' => __( 'Postal delivery settings', 'woodev-plugin-framework' ),
					'type'  => 'title',
				],
				'require_postal_code' => [
					'title'   => __( 'Require postal code', 'woodev-plugin-framework' ),
					'type'    => 'checkbox',
					'label'   => __( 'Require postal code for rate calculation', 'woodev-plugin-framework' ),
					'default' => 'yes',
				],
			];
		}

		/**
		 * Checks if this postal method is available.
		 *
		 * Requires a postal code if configured.
		 *
		 * @since 1.5.0
		 *
		 * @param array $package WC shipping package
		 * @return bool
		 */
		public function is_available( $package ): bool {

			if ( ! parent::is_available( $package ) ) {
				return false;
			}

			if ( 'yes' === $this->get_option( 'require_postal_code', 'yes' ) ) {
				$postcode = Shipping_Helper::get_package_postcode( $package );
				if ( empty( $postcode ) ) {
					return false;
				}
			}

			return true;
		}
	}

endif;

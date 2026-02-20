<?php
/**
 * Woodev Shipping Method Pickup
 *
 * Abstract base class for pickup point delivery methods.
 * Requires the customer to select a pickup point at checkout.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Method_Pickup' ) ) :

	abstract class Shipping_Method_Pickup extends Shipping_Method {

		/**
		 * Gets the delivery type.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		final public function get_delivery_type(): string {
			return self::TYPE_PICKUP;
		}
	}

endif;

<?php
/**
 * Woodev Shipping Method Courier
 *
 * Abstract base class for courier (door-to-door) delivery methods.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Method_Courier' ) ) :

	abstract class Shipping_Method_Courier extends Shipping_Method {

		/**
		 * Gets the delivery type.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		final public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}
	}

endif;

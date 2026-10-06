<?php
/**
 * Delivery status change event seam.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Delivery_Status_Events' ) ) :
	/**
	 * Publishes canonical shipment status changes after carrier metadata is persisted.
	 *
	 * Carriers call this once after writing their raw status. The framework resolves the
	 * canonical state and emits one shared event consumed by status emails and extensions.
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Status_Events {
		/**
		 * Resolves and publishes a status change.
		 *
		 * Fires `woodev_shipping_delivery_status_changed` with the order, previous and current
		 * canonical states, and provider after a carrier stores its new raw status.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order             Shipment order.
		 * @param Orders_Provider $provider          Carrier descriptor.
		 * @param string|null     $previous_status   Previous canonical state, if known.
		 * @return string Current canonical state.
		 */
		public static function notify( \WC_Order $order, Orders_Provider $provider, ?string $previous_status = null ): string {
			$current_status = Order_Actions::resolve_canonical_status( $order, $provider );

			/**
			 * Fires when a carrier has persisted a shipment status change.
			 *
			 * @since 2.0.2
			 * @param \WC_Order       $order           Shipment order.
			 * @param string|null     $previous_status Previous canonical state.
			 * @param string          $current_status  Current canonical state.
			 * @param Orders_Provider $provider        Carrier descriptor.
			 */
			do_action( 'woodev_shipping_delivery_status_changed', $order, $previous_status, $current_status, $provider );

			return $current_status;
		}
	}
endif;

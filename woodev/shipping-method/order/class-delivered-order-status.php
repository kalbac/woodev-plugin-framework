<?php
/**
 * Moves a delivered order to the status the merchant chose.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Delivered_Order_Status' ) ) :
	/**
	 * Sets the carrier's «Статус доставленного заказа»
	 * ({@see \Woodev\Framework\Shipping\Settings\Export_Settings::get_delivered_status()}) on an order the
	 * moment its canonical delivery status becomes «delivered» — the v1 `status_delivered` behaviour, for every
	 * carrier, with no carrier code. It listens to `woodev_shipping_delivery_status_changed`, which fires once
	 * per REAL change ({@see Delivery_Status_Events}).
	 *
	 * The rule, in order:
	 *
	 * 1. Only the canonical state «delivered» counts; «Не менять» (or a status WooCommerce no longer has) does nothing.
	 * 2. ONCE per order and carrier: after the status was applied (or found already in place) a marker is kept,
	 *    so a re-sync that publishes «delivered» again — or a merchant who moved the order back by hand —
	 *    never triggers it a second time.
	 * 3. An order already in the chosen status is left alone (and counted as done).
	 * 4. NEVER touches an order that was cancelled, refunded, failed or trashed: «delivered» arriving late for
	 *    such an order must not bring it back to life (a refunded order «completed» again would also fire the
	 *    completed e-mail and sales reports). It is not marked done either.
	 *
	 * @since 2.0.2
	 */
	final class Delivered_Order_Status {
		/**
		 * Prefix of the order meta that records that the delivered status was applied for a carrier.
		 * Installed-site data contract: keep byte-for-byte.
		 *
		 * @var string
		 */
		public const APPLIED_META_PREFIX = '_woodev_delivered_status_applied_';

		/**
		 * Order statuses (without `wc-`) a delivered notice never overrides.
		 *
		 * @var string[]
		 */
		public const PROTECTED_STATUSES = [ 'cancelled', 'refunded', 'failed', 'trash' ];

		/**
		 * Starts listening. Idempotent: WordPress de-duplicates the same static callback.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public static function register(): void {
			add_action( 'woodev_shipping_delivery_status_changed', [ self::class, 'apply' ], 20, 4 );
		}

		/**
		 * Applies the delivered status to an order whose canonical state just changed.
		 *
		 * @since 2.0.2
		 * @param mixed $order    Shipment order.
		 * @param mixed $previous Previous canonical state.
		 * @param mixed $current  Current canonical state.
		 * @param mixed $provider Matched carrier.
		 * @return void
		 */
		public static function apply( $order, $previous, $current, $provider ): void {
			if ( Delivery_Status::DELIVERED !== $current || ! $order instanceof \WC_Order || ! $provider instanceof Orders_Provider ) {
				return;
			}

			$plugin = Orders_Registry::instance()->get_provider_plugin( $provider->get_id() );
			$target = null === $plugin ? null : $plugin->get_export_settings()->get_delivered_status();

			if ( null === $target || ! wc_is_order_status( 'wc-' . $target ) ) {
				return;
			}

			$marker = self::APPLIED_META_PREFIX . $provider->get_id();

			if ( '' !== (string) \Woodev_Order_Compatibility::get_order_meta( $order, $marker ) ) {
				return;
			}

			$status = $order->get_status();

			if ( in_array( $status, self::PROTECTED_STATUSES, true ) ) {
				return;
			}

			if ( $status !== $target ) {
				$order->update_status(
					$target,
					__( 'Перевозчик сообщил, что заказ доставлен.', 'woodev-plugin-framework' )
				);
			}

			\Woodev_Order_Compatibility::update_order_meta( $order, $marker, '1' );
		}
	}
endif;

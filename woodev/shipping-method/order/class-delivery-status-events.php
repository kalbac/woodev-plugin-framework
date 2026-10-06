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
	 * The framework itself watches the order meta a carrier writes its raw status into (and the
	 * framework's own cancellation marker) with {@see Delivery_Status_Watcher}, so a carrier gets the
	 * event — and the buyer emails built on it — without calling anything. {@see self::notify()}
	 * stays public for a carrier that records its status in a way the watcher cannot see.
	 *
	 * The event fires once per REAL change: the last canonical state published for an order is kept in
	 * order meta, and a call that finds the same state again publishes nothing.
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Status_Events {
		/**
		 * Prefix of the order meta that remembers the last canonical state published for a carrier.
		 * Installed-site data contract: keep byte-for-byte.
		 *
		 * @var string
		 */
		public const BASELINE_META_PREFIX = '_woodev_delivery_status_published_';

		/**
		 * Resolves and publishes a status change.
		 *
		 * Fires `woodev_shipping_delivery_status_changed` with the order, previous and current
		 * canonical states, and provider — only when the canonical state differs from the previous
		 * one. A null `$previous_status` means "use the last state this framework published for the
		 * order"; with none recorded it stays null (a first, unknown-before status).
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order           Shipment order.
		 * @param Orders_Provider $provider        Carrier descriptor.
		 * @param string|null     $previous_status Previous canonical state, if known.
		 * @return string Current canonical state.
		 */
		public static function notify( \WC_Order $order, Orders_Provider $provider, ?string $previous_status = null ): string {
			$current_status = Order_Actions::resolve_canonical_status( $order, $provider );
			$previous_status = $previous_status ?? self::baseline( $order, $provider );

			self::remember( $order, $provider, $current_status );

			if ( null !== $previous_status && $previous_status === $current_status ) {
				return $current_status;
			}

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

		/**
		 * Reconciles an order's canonical state with the last one published, after meta writes.
		 *
		 * Used by {@see Delivery_Status_Watcher}. With no recorded state the order is adopted silently
		 * — unless its status was written for the first time in this request — so shipments that were
		 * already in flight when the framework started watching never send a buyer email for an old
		 * status the first time their carrier re-polls them.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order       Shipment order.
		 * @param Orders_Provider $provider    Carrier descriptor.
		 * @param bool            $first_write Whether the carrier status was first written in this request.
		 * @return string|null The published current state, or null when nothing was published.
		 */
		public static function sync( \WC_Order $order, Orders_Provider $provider, bool $first_write = false ): ?string {
			$baseline = self::baseline( $order, $provider );
			$current  = Order_Actions::resolve_canonical_status( $order, $provider );

			if ( null === $baseline && ! $first_write ) {
				self::remember( $order, $provider, $current );
				return null;
			}

			if ( $baseline === $current ) {
				return null;
			}

			return self::notify( $order, $provider, $baseline );
		}

		/**
		 * The last canonical state published for this order and carrier, or null.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order    Shipment order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return string|null
		 */
		public static function baseline( \WC_Order $order, Orders_Provider $provider ): ?string {
			$value = (string) \Woodev_Order_Compatibility::get_order_meta( $order, self::BASELINE_META_PREFIX . $provider->get_id() );

			return '' !== $value ? $value : null;
		}

		/**
		 * Records the canonical state as published.
		 *
		 * @param \WC_Order       $order    Shipment order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @param string          $state    Canonical state.
		 * @return void
		 */
		private static function remember( \WC_Order $order, Orders_Provider $provider, string $state ): void {
			if ( self::baseline( $order, $provider ) !== $state ) {
				\Woodev_Order_Compatibility::update_order_meta( $order, self::BASELINE_META_PREFIX . $provider->get_id(), $state );
			}
		}
	}
endif;

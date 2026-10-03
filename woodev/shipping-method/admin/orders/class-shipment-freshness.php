<?php
/**
 * Shipping orders — is the shipment at the carrier still the order the merchant sees?
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\Shipment_Fingerprint;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Shipment_Freshness' ) ) :

	/**
	 * Takes the {@see Shipment_Fingerprint} of an order when the carrier request is built and says, later, whether
	 * the order has changed since (#947).
	 *
	 * The ONLY place that decides which pickup point id goes into the fingerprint, so the moment
	 * of the export and the moment of the comparison cannot disagree about it: the full point record
	 * under the carrier's declared `pickup_point_meta_key`, else the bare id in a pickup slot of the
	 * carrier's checkout handler (the same two places the order editor reads it from).
	 *
	 * It only informs. There is no «send again» behind it: the framework has no update call to the
	 * carrier, and a merchant who sees the warning checks the shipment in the carrier's account.
	 *
	 * @since 2.0.2
	 */
	final class Shipment_Freshness {

		/** @var Orders_Registry providers and plugins are resolved through it */
		private Orders_Registry $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry $registry the registry the order's carrier is resolved through.
		 */
		public function __construct( Orders_Registry $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Takes the pending snapshot of the order as the carrier request is being built.
		 *
		 * Subscribed (through {@see Orders_Registry::snapshot_shipment_fingerprint()}) to the
		 * framework-wide `woodev_shipping_order_export_requested` action, which fires just before the
		 * create call. An order no registered carrier claims is left alone: nothing would ever show its
		 * warning.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order the request is built from.
		 * @return void
		 */
		public function snapshot( \WC_Order $order ): void {

			$provider = $this->registry->resolve_provider_for_order( $order );

			if ( null === $provider ) {
				return;
			}

			Shipment_Fingerprint::snapshot( $order, $this->pickup_point_id( $order, $provider ) );
		}

		/**
		 * Promotes the pending snapshot to the stored fingerprint, once the export has succeeded.
		 *
		 * Subscribed (through {@see Orders_Registry::record_shipment_fingerprint()}) to the
		 * framework-wide `woodev_shipping_order_exported` action: every successful export — a first
		 * one, a retry, a reconciled one — passes through it. It never looks at the order as it is now:
		 * a reconciled export promotes the snapshot of the ORIGINAL request, or stores nothing
		 * («unknown») when that request left none.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the exported order.
		 * @return void
		 */
		public function record( \WC_Order $order ): void {
			Shipment_Fingerprint::promote( $order );
		}

		/**
		 * Whether the order has changed since it was handed to the carrier.
		 *
		 * False when it cannot be told: no fingerprint was stored (an order exported before this
		 * existed) or the stored one is of another version.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the order's carrier.
		 * @return bool
		 */
		public function is_outdated( \WC_Order $order, Orders_Provider $provider ): bool {

			// Cheap first: an order with nothing comparable never reads its pickup point.
			if ( ! Shipment_Fingerprint::is_comparable( $order ) ) {
				return false;
			}

			return Shipment_Fingerprint::is_outdated( $order, $this->pickup_point_id( $order, $provider ) );
		}

		/**
		 * The id of the pickup point the order carries, '' when it has none.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the order's carrier.
		 * @return string
		 */
		private function pickup_point_id( \WC_Order $order, Orders_Provider $provider ): string {

			$key = $provider->get_pickup_point_meta_key();

			if ( null !== $key ) {
				$stored = \Woodev_Order_Compatibility::get_order_meta( $order, $key );

				if ( is_array( $stored ) && isset( $stored['id'] ) && '' !== (string) $stored['id'] ) {
					return (string) $stored['id'];
				}
			}

			$plugin  = $this->registry->get_provider_plugin( $provider->get_id() );
			$handler = null !== $plugin ? $plugin->get_checkout_handler() : null;

			if ( null === $handler ) {
				return '';
			}

			$fields = $handler->read_values( $order );

			foreach ( $handler->pickup_field_ids() as $slot ) {
				if ( isset( $fields[ $slot ] ) && '' !== (string) $fields[ $slot ] ) {
					return (string) $fields[ $slot ];
				}
			}

			return '';
		}
	}

endif;

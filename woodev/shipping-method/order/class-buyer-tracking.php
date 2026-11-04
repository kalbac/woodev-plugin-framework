<?php
/**
 * Buyer-facing tracking block
 *
 * @since 2.0.25
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Buyer_Tracking' ) ) :

	/**
	 * Prints the tracking number (and where the parcel is now) under the order details, for every carrier plugin.
	 *
	 * WooCommerce fires `woocommerce_order_details_after_order_table` from the order-details table, which both the
	 * My Account «view order» page and the order-received page use. One listener serves every active carrier plugin:
	 * the carrier is resolved per order from its marker meta, and the parcels come from
	 * {@see Orders_Provider::get_tracking_shipments()}, the same source the shipment emails read.
	 *
	 * **No number — no output.** There is no setting and no off switch: a delivery that has a tracking number always
	 * shows it (operator rule, #1575). Nothing is printed, not even the heading, when the carrier has no parcel to show.
	 *
	 * **Status line.** A parcel's own status wins at any count. Without one, a single parcel shows the order's current
	 * status from {@see Delivery_Status_Log}; several parcels (an Ozon order) show no status text, because the log is per
	 * order and would put the same status on every line.
	 *
	 * The markup lives in `templates/order/tracking.php`; a theme overrides it at
	 * `{theme}/woocommerce/woodev/shipping/order/tracking.php`.
	 *
	 * @since 2.0.25
	 */
	final class Buyer_Tracking {
		/**
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Hooks the block once, however many carrier plugins ask for it.
		 *
		 * @since 2.0.25
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * @since 2.0.25
		 */
		private function __construct() {
			add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render' ], 10, 1 );
		}

		/**
		 * Prints the tracking block for an order, or nothing.
		 *
		 * @since 2.0.25
		 *
		 * @param \WC_Order|mixed $order the order; WooCommerce occasionally hands over something else.
		 * @return void
		 */
		public function render( $order ): void {
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$provider = Orders_Registry::instance()->resolve_provider_for_order( $order );
			if ( null === $provider ) {
				return;
			}

			$shipments = $provider->get_tracking_shipments( $order );
			if ( [] === $shipments ) {
				return;
			}

			wc_get_template(
				'order/tracking.php',
				[
					'order'    => $order,
					'provider' => $provider,
					'lines'    => $this->build_lines( $order, $provider, $shipments ),
				],
				'woodev/shipping/',
				dirname( __DIR__ ) . '/templates/'
			);
		}

		/**
		 * Turns the parcels into the plain rows the template prints.
		 *
		 * @param \WC_Order           $order     order.
		 * @param Orders_Provider     $provider  carrier descriptor.
		 * @param Tracking_Shipment[] $shipments parcels, at least one.
		 * @return array<int,array{caption:string,text:string,url:?string,status:string}>
		 */
		private function build_lines( \WC_Order $order, Orders_Provider $provider, array $shipments ): array {
			$count = count( $shipments );
			// A parcel's own status wins. Without one, a single parcel shows the order's status and several show none.
			$order_status = 1 === $count ? $this->order_status( $order, $provider ) : null;
			$lines        = [];

			foreach ( $shipments as $index => $shipment ) {
				$own    = $shipment->get_status_label();
				$status = null !== $own && '' !== $own
					? [ $own, $shipment->get_status_time() ]
					: $order_status;

				$lines[] = [
					'caption' => 1 === $count ? __( 'Tracking number', 'woodev-plugin-framework' ) : self::shipment_caption( $index + 1, $count ),
					'text'    => $shipment->get_display_text(),
					'url'     => $shipment->get_url(),
					'status'  => $this->status_text( $status[0] ?? null, $status[1] ?? null ),
				];
			}

			return $lines;
		}

		/**
		 * «Shipment N of M» — the caption of one parcel when an order has several. Shared with the shipment emails so the
		 * page and the mail word it the same way.
		 *
		 * @since 2.0.25
		 *
		 * @param int $position 1-based position of the parcel.
		 * @param int $count    how many parcels the order has (more than one).
		 * @return string
		 */
		public static function shipment_caption( int $position, int $count ): string {
			/* translators: 1: position of the parcel, 2: number of parcels in the order. */
			return sprintf( __( 'Shipment %1$d of %2$d', 'woodev-plugin-framework' ), $position, $count );
		}

		/**
		 * The order's current status from the status log: the carrier's own wording when it has one for the raw code,
		 * else the framework's buyer wording of the canonical state.
		 *
		 * @param \WC_Order       $order    order.
		 * @param Orders_Provider $provider carrier descriptor.
		 * @return array{0:string,1:int}|null `[ label, unix time ]`, null when the log is empty or says nothing a buyer can read.
		 */
		private function order_status( \WC_Order $order, Orders_Provider $provider ): ?array {
			$entries = Delivery_Status_Log::read( $order, $provider );
			if ( [] === $entries ) {
				return null;
			}

			list( $state, $time, $raw ) = end( $entries );

			$carrier_labels = $provider->get_status_labels();
			$label          = '' !== $raw && isset( $carrier_labels[ $raw ] ) && '' !== $carrier_labels[ $raw ]
				? (string) $carrier_labels[ $raw ]
				: Delivery_Status::buyer_label( $state );

			return null === $label ? null : [ $label, $time ];
		}

		/**
		 * «Current status: label, date» — or without the date when none is known; empty when there is no label.
		 *
		 * @param string|null $label status label.
		 * @param int|null    $time  unix time.
		 * @return string
		 */
		private function status_text( ?string $label, ?int $time ): string {
			if ( null === $label || '' === $label ) {
				return '';
			}

			$date = null !== $time && $time > 0 ? wp_date( wc_date_format(), $time ) : false;
			if ( ! is_string( $date ) || '' === $date ) {
				/* translators: %s: current delivery status, e.g. «In transit». */
				return sprintf( __( 'Current status: %s', 'woodev-plugin-framework' ), $label );
			}

			/* translators: 1: current delivery status, e.g. «In transit», 2: date of that status. */
			return sprintf( __( 'Current status: %1$s, %2$s', 'woodev-plugin-framework' ), $label, $date );
		}
	}

endif;

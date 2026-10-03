<?php
/**
 * The fingerprint of an order as it was handed to the carrier (#947).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Shipment_Fingerprint' ) ) :

	/**
	 * A hash of the order fields the carrier's request depends on, kept from the moment of the export.
	 *
	 * The framework does not push an edit of an exported order to the carrier (there is no update API
	 * behind the shipment), so the only honest thing it can do is notice that the order no longer
	 * matches what was sent and say so. At export the fingerprint is stored in {@see self::META};
	 * the order's shipping metabox later compares it with the order as it is NOW.
	 *
	 * **What the fingerprint covers** (all of it normalised — trimmed, digits-only phone, a stable
	 * order of lines and methods — so a re-save that changes nothing never changes the hash):
	 * the product and fee lines ({@see Carrier_Order_Lines::from_order()}, no reconciliation),
	 * the recipient's name and phone and the delivery address (shipping, each part falling back to
	 * billing exactly when the shipping address block is empty — the way the order shows it), the
	 * chosen shipping methods with their instance ids, the chosen pickup point id, and the order total.
	 * It deliberately does NOT cover notes, the order status, the payment method or any meta.
	 *
	 * **Versioned.** The stored value is `v1:<sha256>`. The definition above may change in a later
	 * release; a stored fingerprint of ANOTHER version is «unknown», never «changed» — the comparison
	 * cannot be made, so the merchant is not warned about an edit nobody made.
	 *
	 * Installed-site data contract (a meta key of the framework's own): keep {@see self::META} and the
	 * `v1` definition byte-for-byte; a changed definition gets a new version prefix.
	 *
	 * @since 2.0.2
	 */
	final class Shipment_Fingerprint {

		/**
		 * The order meta that holds the fingerprint taken at the last successful export. Sits beside
		 * {@see Shipment_Cancellation::CANCELLED_AT_META} and
		 * {@see Abstract_Shipment_Handler::EXPORT_UNKNOWN_META}. Removed when the shipment is
		 * cancelled.
		 *
		 * @var string
		 */
		public const META = '_woodev_shipment_fingerprint';

		/**
		 * The version of the definition of the fingerprint; the prefix of every stored value.
		 *
		 * @var string
		 */
		public const VERSION = 'v1';

		/**
		 * The fingerprint of an order as it is now.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order            the order.
		 * @param string    $pickup_point_id  the id of the chosen pickup point, '' when the order has none.
		 * @return string `v1:<sha256>`
		 */
		public static function for_order( \WC_Order $order, string $pickup_point_id = '' ): string {

			$lines = [];

			foreach ( Carrier_Order_Lines::from_order( $order ) as $line ) {
				$lines[] = [
					trim( $line->get_name() ),
					trim( $line->get_sku() ),
					$line->get_quantity(),
					$line->get_unit_price_minor(),
					$line->get_total_minor(),
				];
			}

			usort(
				$lines,
				static function ( array $a, array $b ): int {
					return strcmp( self::encode( $a ), self::encode( $b ) );
				}
			);

			$data = [
				'lines'     => $lines,
				'recipient' => self::recipient( $order ),
				'address'   => self::address( $order ),
				'methods'   => self::methods( $order ),
				'pickup'    => trim( $pickup_point_id ),
				'total'     => number_format( (float) $order->get_total(), max( 0, (int) wc_get_price_decimals() ), '.', '' ),
			];

			return self::VERSION . ':' . hash( 'sha256', self::encode( $data ) );
		}

		/**
		 * The fingerprint stored at the last export, '' when there is none.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return string
		 */
		public static function stored( \WC_Order $order ): string {

			$value = \Woodev_Order_Compatibility::get_order_meta( $order, self::META );

			return is_string( $value ) ? $value : '';
		}

		/**
		 * Whether the order carries a fingerprint of THIS version — the only kind that can be compared.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return bool
		 */
		public static function is_comparable( \WC_Order $order ): bool {
			return 0 === strpos( self::stored( $order ), self::VERSION . ':' );
		}

		/**
		 * Whether the order differs from what was handed to the carrier.
		 *
		 * False when there is nothing to compare with: no fingerprint, or one of another version.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order           the order.
		 * @param string    $pickup_point_id the id of the chosen pickup point now, '' when none.
		 * @return bool
		 */
		public static function is_outdated( \WC_Order $order, string $pickup_point_id = '' ): bool {

			if ( ! self::is_comparable( $order ) ) {
				return false;
			}

			return ! hash_equals( self::stored( $order ), self::for_order( $order, $pickup_point_id ) );
		}

		/**
		 * Records the order as it is now as the one handed to the carrier.
		 *
		 * HPOS-safe: goes through the order's own meta API, like the other framework-owned order metas.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order           the exported order.
		 * @param string    $pickup_point_id the id of the chosen pickup point, '' when none.
		 * @return void
		 */
		public static function record( \WC_Order $order, string $pickup_point_id = '' ): void {
			$order->update_meta_data( self::META, self::for_order( $order, $pickup_point_id ) );
			$order->save_meta_data();
		}

		/**
		 * Forgets the fingerprint — the order has no live shipment any more.
		 *
		 * A no-op, with no datastore write, when nothing was recorded. Deleted on `$fresh` (the order
		 * as the datastore holds it NOW) and on the caller's `$order`, so a later save of that copy
		 * cannot bring it back.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $fresh the order as the datastore holds it NOW.
		 * @param \WC_Order $order the caller's copy, kept in step.
		 * @return void
		 */
		public static function clear( \WC_Order $fresh, \WC_Order $order ): void {
			self::forget( $fresh );

			if ( $fresh !== $order ) {
				self::forget( $order );
			}
		}

		/**
		 * Deletes the fingerprint off one order object.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return void
		 */
		private static function forget( \WC_Order $order ): void {

			if ( '' === (string) $order->get_meta( self::META ) ) {
				return;
			}

			$order->delete_meta_data( self::META );
			$order->save_meta_data();
		}

		/**
		 * The recipient: shipping name and phone, each falling back to billing when empty.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return array<string, string>
		 */
		private static function recipient( \WC_Order $order ): array {

			$first = trim( (string) $order->get_shipping_first_name() );
			$last  = trim( (string) $order->get_shipping_last_name() );

			if ( '' === $first && '' === $last ) {
				$first = trim( (string) $order->get_billing_first_name() );
				$last  = trim( (string) $order->get_billing_last_name() );
			}

			$phone = trim( (string) $order->get_shipping_phone() );

			if ( '' === $phone ) {
				$phone = trim( (string) $order->get_billing_phone() );
			}

			return [
				'first_name' => $first,
				'last_name'  => $last,
				'phone'      => (string) preg_replace( '/\D+/', '', $phone ),
			];
		}

		/**
		 * The delivery address: the shipping one, or the billing one when the shipping address block is empty.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return array<string, string>
		 */
		private static function address( \WC_Order $order ): array {

			$shipping = [
				'country'   => $order->get_shipping_country(),
				'state'     => $order->get_shipping_state(),
				'city'      => $order->get_shipping_city(),
				'postcode'  => $order->get_shipping_postcode(),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
			];

			$shipping = array_map( static fn( $part ): string => trim( (string) $part ), $shipping );

			if ( '' !== $shipping['address_1'] || '' !== $shipping['city'] || '' !== $shipping['postcode'] ) {
				return $shipping;
			}

			return array_map(
				static fn( $part ): string => trim( (string) $part ),
				[
					'country'   => $order->get_billing_country(),
					'state'     => $order->get_billing_state(),
					'city'      => $order->get_billing_city(),
					'postcode'  => $order->get_billing_postcode(),
					'address_1' => $order->get_billing_address_1(),
					'address_2' => $order->get_billing_address_2(),
				]
			);
		}

		/**
		 * The chosen shipping methods as `method_id:instance_id`, sorted.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return string[]
		 */
		private static function methods( \WC_Order $order ): array {

			$methods = [];

			foreach ( $order->get_shipping_methods() as $item ) {
				$methods[] = trim( (string) $item->get_method_id() ) . ':' . (int) $item->get_instance_id();
			}

			sort( $methods );

			return $methods;
		}

		/**
		 * Encodes the normalised data the same way every time.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $data the data.
		 * @return string
		 */
		private static function encode( $data ): string {
			return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
	}

endif;

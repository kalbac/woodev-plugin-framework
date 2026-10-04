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
	 * matches what was sent and say so. The order's shipping metabox compares the stored fingerprint
	 * ({@see self::META}) with the order as it is NOW.
	 *
	 * **Request-time snapshot.** The fingerprint must describe what the carrier was SENT, not what the
	 * order looks like when the export is later confirmed. So it is taken in two steps: just before the
	 * create call the order is hashed into {@see self::PENDING_META} ({@see self::snapshot()}), and
	 * only when the export succeeds is that pending value promoted to {@see self::META}
	 * ({@see self::promote()}). A timed-out export keeps its pending value; if the delayed retry finds
	 * the shipment at the carrier (reconciliation, no new request), the ORIGINAL snapshot is promoted
	 * even if the order was edited in between — and the edit is warned about. A completion with no
	 * pending value stores nothing: «unknown», never the retry-time order certified as sent.
	 *
	 * **What the fingerprint covers** (all of it normalised — trimmed, digits-only phone, fixed
	 * decimals, a stable order of lines and methods — so a re-save that changes nothing never changes
	 * the hash): the order's own product lines (product id, variation id, name, quantity, line subtotal
	 * and total — straight from the order items, NEVER from the live product or its SKU, so catalogue
	 * maintenance or a deleted product is not an edit) and fee lines, the recipient's name and phone,
	 * the delivery address (shipping, each part falling back to billing exactly when the shipping
	 * address block is empty — the way the order shows it), the chosen shipping methods with their
	 * instance ids, the chosen pickup point id, and the order total. It deliberately does NOT cover
	 * notes, the order status, the payment method, refunds or any meta.
	 *
	 * **Versioned.** The stored value is `v1:<sha256>`. The definition above may change in a later
	 * release; a stored fingerprint of ANOTHER version is «unknown», never «changed» — the comparison
	 * cannot be made, so the merchant is not warned about an edit nobody made.
	 *
	 * Installed-site data contract (meta keys of the framework's own): keep {@see self::META},
	 * {@see self::PENDING_META} and the `v1` definition byte-for-byte; a changed definition gets a new
	 * version prefix.
	 *
	 * @since 2.0.2
	 */
	final class Shipment_Fingerprint {

		/**
		 * The order meta that holds the fingerprint of the request the last successful export was made from. Sits beside
		 * {@see Shipment_Cancellation::CANCELLED_AT_META} and
		 * {@see Abstract_Shipment_Handler::EXPORT_UNKNOWN_META}. Written only by
		 * {@see self::promote()}; removed when the shipment is cancelled.
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
		 * The order meta that holds the fingerprint of the LAST carrier request, until its export
		 * is known to have succeeded ({@see self::promote()}) — same `v1:<sha256>` format. Kept
		 * across a timed-out export so a later reconciliation can still promote the order as it
		 * was SENT, not as it is by then. Removed on promotion, on a refused export and on cancel.
		 *
		 * @var string
		 */
		public const PENDING_META = '_woodev_shipment_fingerprint_pending';

		public static function for_order( \WC_Order $order, string $pickup_point_id = '' ): string {

			$data = [
				'lines'     => self::lines( $order ),
				'recipient' => self::recipient( $order ),
				'address'   => self::address( $order ),
				'methods'   => self::methods( $order ),
				'pickup'    => trim( $pickup_point_id ),
				'total'     => self::amount( $order->get_total() ),
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
		 * Takes the snapshot of the order as the carrier request is being built — the PENDING fingerprint.
		 *
		 * Called just before the create call. It is only a candidate: it becomes THE stored
		 * fingerprint through {@see self::promote()} when the export succeeds. A later request
		 * overwrites it. HPOS-safe: goes through the order's own meta API.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order           the order the request is built from.
		 * @param string    $pickup_point_id the id of the chosen pickup point, '' when none.
		 * @return void
		 */
		public static function snapshot( \WC_Order $order, string $pickup_point_id = '' ): void {
			$order->update_meta_data( self::PENDING_META, self::for_order( $order, $pickup_point_id ) );
			$order->save_meta_data();
		}

		/**
		 * Promotes the pending snapshot of the request to THE stored fingerprint, once the export has succeeded.
		 *
		 * The stored value is only ever what a carrier request was built from. When this export has
		 * no pending snapshot — a reconciled export whose original request left none — nothing is
		 * stored, and a stale stored value is dropped: the freshness is «unknown», never the
		 * retry-time order certified as the one the carrier got. The pending value is always consumed.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the exported order.
		 * @return void
		 */
		public static function promote( \WC_Order $order ): void {

			$pending = self::pending( $order );

			if ( 0 !== strpos( $pending, self::VERSION . ':' ) ) {
				self::forget( $order );
				self::discard_pending( $order );

				return;
			}

			$order->update_meta_data( self::META, $pending );
			$order->delete_meta_data( self::PENDING_META );
			$order->save_meta_data();
		}

		/**
		 * The snapshot taken when the last carrier request was built, '' when there is none.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return string
		 */
		public static function pending( \WC_Order $order ): string {

			$value = $order->get_meta( self::PENDING_META );

			return is_string( $value ) ? $value : '';
		}

		/**
		 * Drops the pending snapshot — the request it described created nothing.
		 *
		 * A no-op, with no datastore write, when there is none.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return void
		 */
		public static function discard_pending( \WC_Order $order ): void {

			if ( '' === self::pending( $order ) ) {
				return;
			}

			$order->delete_meta_data( self::PENDING_META );
			$order->save_meta_data();
		}

		/**
		 * Forgets the stored AND the pending fingerprint — the order has no live shipment any more.
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

			$held = [];

			foreach ( [ self::META, self::PENDING_META ] as $key ) {
				if ( '' !== (string) $order->get_meta( $key ) ) {
					$held[] = $key;
				}
			}

			if ( [] === $held ) {
				return;
			}

			foreach ( $held as $key ) {
				$order->delete_meta_data( $key );
			}

			$order->save_meta_data();
		}

		/**
		 * The product and fee lines exactly as the ORDER stores them.
		 *
		 * Nothing is read from the live catalogue — no product object, no SKU — so a catalogue
		 * change or a deleted product never reads as an edit of the order. Product lines: product
		 * and variation id, name, quantity, line subtotal and total; fees: name and total. Sorted.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order.
		 * @return array<int, array<int, string|int>>
		 */
		private static function lines( \WC_Order $order ): array {

			$lines = [];

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$lines[] = [
					'product',
					(int) $item->get_product_id(),
					(int) $item->get_variation_id(),
					trim( (string) $item->get_name() ),
					self::amount( $item->get_quantity() ),
					self::amount( $item->get_subtotal() ),
					self::amount( $item->get_total() ),
				];
			}

			foreach ( $order->get_items( 'fee' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Fee ) {
					continue;
				}

				$lines[] = [ 'fee', trim( (string) $item->get_name() ), self::amount( $item->get_total() ) ];
			}

			usort(
				$lines,
				static function ( array $a, array $b ): int {
					return strcmp( self::encode( $a ), self::encode( $b ) );
				}
			);

			return $lines;
		}

		/**
		 * A stored number as a stable string, so float noise never reads as an edit.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value the number as the order returns it.
		 * @return string
		 */
		private static function amount( $value ): string {
			return number_format( (float) $value, 4, '.', '' );
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

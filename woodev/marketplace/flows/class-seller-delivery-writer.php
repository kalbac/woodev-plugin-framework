<?php
/**
 * Writes the delivery of an order the seller delivers.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * The part of {@see Wc_Order_Store} for an order the seller delivers ({@see Order_DTO::delivery()}):
 *
 * - **How it was paid** and **that it is paid**: the marketplace took the money, so no carrier plans cash on delivery.
 * - **The shipping line** of what the buyer paid for the delivery, marked {@see Seller_Order::ITEM_DELIVERY_LINE}. It is the only
 *   shipping line the framework creates, prices or changes; another writer's line is never touched.
 * - **What the marketplace says**, kept on the order and refreshed by every read ({@see Seller_Order}).
 * - **The order's own recipient, phone, address and comment**: seeded, then left alone. A group of fields is written while it is
 *   empty; afterwards only while it still holds exactly what the framework wrote and the marketplace now says something else. A
 *   field the merchant or a delivery wizard changed is theirs from then on, and so is a field filled before the marketplace told it.
 *
 * An order whose personal data was erased (WooCommerce's `_anonymized`) gets no buyer data again.
 *
 * Nothing is saved here: the order store saves the order.
 *
 * @since 2.1.29
 */
final class Seller_Delivery_Writer {
	/**
	 * The order fields that go together: a change of any of them makes the whole group the merchant's.
	 */
	private const FIELD_GROUPS = [
		'recipient' => [ 'billing_first_name', 'billing_last_name', 'shipping_first_name', 'shipping_last_name' ],
		'phone'     => [ 'billing_phone', 'shipping_phone' ],
		'address'   => [ 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country' ],
		'comment'   => [ 'customer_note' ],
	];

	/**
	 * Applies the delivery a read carries to the order.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @return bool Whether the shipping line changed, so the totals must be calculated again.
	 */
	public function apply( \WC_Order $wc_order, Order_DTO $order ): bool {
		$told = $order->delivery();

		if ( null === $told ) {
			return false;
		}

		$known = Seller_Order::delivery( $wc_order );
		$now   = $told->merged_over( $known );

		$wc_order->update_meta_data( Seller_Order::META_SELLER_DELIVERED, 'yes' );
		$this->mark_paid( $wc_order, $order, $now );

		if ( ! self::is_erased( $wc_order ) ) {
			$wc_order->update_meta_data( Seller_Order::META_DELIVERY, $now->to_array() );
			$this->keep_payload( $wc_order, $order );
			$this->seed_fields( $wc_order, $now );
		}

		return $this->write_line( $wc_order, $now );
	}

	/**
	 * Tells whether the buyer's data of an order was erased (WooCommerce marks such an order).
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $wc_order The order.
	 * @return bool
	 */
	public static function is_erased( \WC_Order $wc_order ): bool {
		return 'yes' === $wc_order->get_meta( '_anonymized' );
	}

	/**
	 * Names how the order was paid and marks it paid, once.
	 *
	 * @param \WC_Order       $wc_order The order.
	 * @param Order_DTO       $order    Whole order.
	 * @param Seller_Delivery $delivery What is known of the delivery.
	 * @return void
	 */
	private function mark_paid( \WC_Order $wc_order, Order_DTO $order, Seller_Delivery $delivery ): void {
		if ( '' === (string) $wc_order->get_payment_method_title( 'edit' ) ) {
			$wc_order->set_payment_method_title( $delivery->payment_title() );
		}

		if ( null === $wc_order->get_date_paid( 'edit' ) && ! $order->is_cancelled() ) {
			$created = $wc_order->get_date_created( 'edit' );

			$wc_order->set_date_paid( null === $created ? time() : $created->getTimestamp() );
		}
	}

	/**
	 * Keeps the marketplace's own data of every unit the read carries, over what was kept before: a value a later read leaves out
	 * stays, since a marketplace may stop sending it once the order is in work.
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @return void
	 */
	private function keep_payload( \WC_Order $wc_order, Order_DTO $order ): void {
		$payload = Seller_Order::payload( $wc_order );
		$sources = [] === $order->units() ? [ $order ] : $order->units();

		foreach ( $sources as $source ) {
			$delivery = $source->delivery();

			if ( null === $delivery || [] === $delivery->payload() ) {
				continue;
			}

			$key   = '#' . $source->external_id();
			$fresh = array_filter(
				$delivery->payload(),
				static function ( $value ): bool {
					return null !== $value && '' !== $value && [] !== $value;
				}
			);

			$payload[ $key ] = array_replace( $payload[ $key ] ?? [], $fresh );
		}

		if ( [] !== $payload ) {
			$wc_order->update_meta_data( Seller_Order::META_PAYLOAD, $payload );
		}
	}

	/**
	 * Writes the marketplace's values into the field groups the merchant has not made their own.
	 *
	 * @param \WC_Order       $wc_order The order.
	 * @param Seller_Delivery $delivery What is known of the delivery.
	 * @return void
	 */
	private function seed_fields( \WC_Order $wc_order, Seller_Delivery $delivery ): void {
		$seeded = Seller_Order::seeded( $wc_order );

		foreach ( self::wanted_values( $delivery ) as $group => $values ) {
			$current = self::read_group( $wc_order, $group );
			$written = $seeded[ $group ] ?? array_fill_keys( self::FIELD_GROUPS[ $group ], '' );

			if ( $current !== $written ) {
				continue; // The merchant's: filled or changed by somebody else.
			}

			foreach ( $values as $field => $value ) {
				$wc_order->{"set_$field"}( $value );
			}

			$seeded[ $group ] = self::read_group( $wc_order, $group );
		}

		if ( [] !== $seeded ) {
			$wc_order->update_meta_data( Seller_Order::META_SEEDED, $seeded );
		}
	}

	/**
	 * The values the marketplace tells, by group and order field. A group it tells nothing about is left out.
	 *
	 * @param Seller_Delivery $delivery What is known of the delivery.
	 * @return array<string,array<string,string>>
	 */
	private static function wanted_values( Seller_Delivery $delivery ): array {
		$name    = sanitize_text_field( $delivery->recipient() );
		$phone   = wc_sanitize_phone_number( $delivery->phone() );
		$address = sanitize_text_field( $delivery->address() );
		$comment = sanitize_textarea_field( $delivery->comment() );

		return array_filter(
			[
				'recipient' => '' === $name ? [] : [
					'billing_first_name'  => $name,
					'shipping_first_name' => $name,
				],
				'phone'     => '' === $phone ? [] : [
					'billing_phone'  => $phone,
					'shipping_phone' => $phone,
				],
				'address'   => '' === $address ? [] : [ 'shipping_address_1' => $address ],
				'comment'   => '' === $comment ? [] : [ 'customer_note' => $comment ],
			]
		);
	}

	/**
	 * Reads the stored values of a field group.
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param string    $group    Group of {@see self::FIELD_GROUPS}.
	 * @return array<string,string> Value by field.
	 */
	private static function read_group( \WC_Order $wc_order, string $group ): array {
		$values = [];

		foreach ( self::FIELD_GROUPS[ $group ] as $field ) {
			$values[ $field ] = (string) $wc_order->{"get_$field"}( 'edit' );
		}

		return $values;
	}

	/**
	 * Creates or brings up to date the framework's shipping line. A price in another currency than the order's is not written.
	 *
	 * @param \WC_Order       $wc_order The order.
	 * @param Seller_Delivery $delivery What is known of the delivery.
	 * @return bool Whether the line changed.
	 */
	private function write_line( \WC_Order $wc_order, Seller_Delivery $delivery ): bool {
		$price = $delivery->price();

		if ( null === $price || $price->currency() !== $wc_order->get_currency() ) {
			return false;
		}

		$line = self::own_line( $wc_order );

		if ( null === $line ) {
			$line = new \WC_Order_Item_Shipping();
			$line->set_method_title( $delivery->line_title() );
			$line->set_total( $price->to_string() );
			$line->update_meta_data( Seller_Order::ITEM_DELIVERY_LINE, '1' );
			$wc_order->add_item( $line );

			return true;
		}

		if ( $line->get_method_title( 'edit' ) === $delivery->line_title() && abs( (float) $line->get_total( 'edit' ) - (float) $price->to_string() ) < 0.00001 ) {
			return false;
		}

		$line->set_method_title( $delivery->line_title() );
		$line->set_total( $price->to_string() );
		$line->save();

		return true;
	}

	/**
	 * Returns the framework's own shipping line of an order.
	 *
	 * @param \WC_Order $wc_order The order.
	 * @return \WC_Order_Item_Shipping|null
	 */
	private static function own_line( \WC_Order $wc_order ): ?\WC_Order_Item_Shipping {
		foreach ( $wc_order->get_items( 'shipping' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Shipping && '1' === (string) $item->get_meta( Seller_Order::ITEM_DELIVERY_LINE ) ) {
				return $item;
			}
		}

		return null;
	}
}

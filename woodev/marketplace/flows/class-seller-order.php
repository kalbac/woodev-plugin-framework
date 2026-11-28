<?php
/**
 * What an order keeps about its delivery by the seller.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Orders\Seller_Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the marketplace's delivery data of a WooCommerce order, on both order storages: whether the seller delivers it, what the
 * marketplace last said about the delivery, its own data of the order, and its delivery scheme. {@see Seller_Delivery_Writer}
 * writes it; a carrier step reads the recipient's coordinates and the chosen day from {@see self::delivery()}.
 *
 * The recipient, phone and address the merchant works with are the order's own shipping fields, not these.
 *
 * @since 2.1.29
 */
final class Seller_Order {
	/**
	 * Order meta of an order the seller delivers, `yes`. It outlives an erasure of the buyer's data.
	 */
	public const META_SELLER_DELIVERED = '_woodev_mp_seller_delivered';

	/**
	 * Order meta of the delivery scheme code.
	 */
	public const META_SCHEME = '_woodev_mp_scheme';

	/**
	 * Order meta of what the marketplace last said about the delivery ({@see Seller_Delivery::to_array()}). Personal data.
	 */
	public const META_DELIVERY = '_woodev_mp_delivery';

	/**
	 * Order meta of the marketplace's own data of each unit, keyed by `#` and the unit id. Personal data.
	 */
	public const META_PAYLOAD = '_woodev_mp_delivery_payload';

	/**
	 * Order meta of the values the framework itself wrote into the order's fields, by group. Personal data.
	 */
	public const META_SEEDED = '_woodev_mp_delivery_seeded';

	/**
	 * Item meta of the shipping line the framework owns: what the buyer paid for the delivery.
	 */
	public const ITEM_DELIVERY_LINE = '_woodev_mp_delivery_line';

	/**
	 * Every meta that holds the buyer's data.
	 */
	private const BUYER_META = [ self::META_DELIVERY, self::META_PAYLOAD, self::META_SEEDED ];

	/**
	 * Tells whether the seller delivers an order.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_seller_delivered( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::META_SELLER_DELIVERED );
	}

	/**
	 * Tells whether an order is a marketplace's and the seller delivers it: the question every marketplace plugin asks before it
	 * touches an order, so that one never works on another's.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order       Order.
	 * @param string    $created_via What the marketplace's orders are created via ({@see Order_Meta_Contract::created_via()}).
	 * @return bool
	 */
	public static function belongs_to( \WC_Order $order, string $created_via ): bool {
		return self::is_seller_delivered( $order ) && $order->get_created_via() === $created_via;
	}

	/**
	 * Returns what the marketplace last said about the delivery.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return Seller_Delivery|null Null for an order that keeps none (an erased one included).
	 */
	public static function delivery( \WC_Order $order ): ?Seller_Delivery {
		return Seller_Delivery::from_array( $order->get_meta( self::META_DELIVERY ) );
	}

	/**
	 * Returns the delivery scheme of an order.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string The code; '' for an order with none.
	 */
	public static function scheme( \WC_Order $order ): string {
		return (string) $order->get_meta( self::META_SCHEME );
	}

	/**
	 * Returns the marketplace's own data of the order's units.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,array<string,mixed>> Data by `#` and the unit id.
	 */
	public static function payload( \WC_Order $order ): array {
		$stored = $order->get_meta( self::META_PAYLOAD );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Returns the values the framework itself wrote into the order's fields, by group: what the marketplace reported before the
	 * merchant made a group their own. Personal data.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,array<string,string>> Value by order field, by group.
	 */
	public static function seeded( \WC_Order $order ): array {
		$stored = $order->get_meta( self::META_SEEDED );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Removes the buyer's data the framework keeps on an order and saves the order when there was some. The order's own fields are
	 * not touched: WooCommerce's erasure tools anonymise those.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether anything was removed.
	 */
	public static function erase_buyer_data( \WC_Order $order ): bool {
		$removed = false;

		foreach ( self::BUYER_META as $key ) {
			if ( $order->meta_exists( $key ) ) {
				$order->delete_meta_data( $key );
				$removed = true;
			}
		}

		if ( $removed ) {
			$order->save_meta_data();
		}

		return $removed;
	}
}

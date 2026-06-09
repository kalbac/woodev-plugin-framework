<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_WC_Packer_Dispatcher' ) ) :

	/**
	 * WooCommerce-aware extension of Woodev_Packer_Dispatcher.
	 *
	 * Adds factory methods that convert WC cart items and order items into
	 * Woodev_Packer_Input_Item instances. All packing logic is inherited from
	 * the parent — this class only handles the WC-specific input conversion.
	 *
	 * Usage:
	 *
	 *     // In a shipping method rate calculation:
	 *     $items  = Woodev_WC_Packer_Dispatcher::from_cart_items( WC()->cart->get_cart() );
	 *     $result = Woodev_WC_Packer_Dispatcher::pack( 'virtual', $items );
	 *     $data   = $result->to_array();
	 *
	 * @since 1.4.1
	 */
	final class Woodev_WC_Packer_Dispatcher extends Woodev_Packer_Dispatcher {

		/**
		 * Converts WooCommerce cart items into Woodev_Packer_Input_Item instances.
		 *
		 * Skips virtual products (no physical dimensions). Returns an empty array
		 * if the cart contains only virtual items.
		 *
		 * @since  1.4.1
		 *
		 * @param  array $cart_contents Result of WC_Cart::get_cart().
		 * @return Woodev_Packer_Input_Item[]
		 */
		public static function from_cart_items( array $cart_contents ): array {
			$items = [];

			foreach ( $cart_contents as $cart_item ) {
				/** @var \WC_Product|false $product */
				$product = $cart_item['data'] ?? false;

				if ( ! $product instanceof \WC_Product || $product->is_virtual() ) {
					continue;
				}

				$qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

				$items[] = new Woodev_Packer_Input_Item(
					(float) $product->get_length(),
					(float) $product->get_width(),
					(float) $product->get_height(),
					(float) $product->get_weight(),
					$qty
				);
			}

			return $items;
		}

		/**
		 * Converts WooCommerce order items into Woodev_Packer_Input_Item instances.
		 *
		 * Skips virtual/downloadable products and items whose product no longer exists.
		 *
		 * @since  1.4.1
		 *
		 * @param  \WC_Order $order
		 * @return Woodev_Packer_Input_Item[]
		 */
		public static function from_order_items( \WC_Order $order ): array {
			$items = [];

			foreach ( $order->get_items() as $order_item ) {
				if ( ! $order_item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$product = $order_item->get_product();

				if ( ! $product instanceof \WC_Product || $product->is_virtual() ) {
					continue;
				}

				$qty = max( 1, (int) $order_item->get_quantity() );

				$items[] = new Woodev_Packer_Input_Item(
					(float) $product->get_length(),
					(float) $product->get_width(),
					(float) $product->get_height(),
					(float) $product->get_weight(),
					$qty
				);
			}

			return $items;
		}
	}

endif;

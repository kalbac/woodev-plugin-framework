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
		 * if the cart contains only virtual items. Dimensions and weight are converted
		 * from the store's units to the packer's cm / kg.
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

				$items[] = self::to_input_item( $product, $qty );
			}

			return $items;
		}

		/**
		 * Converts WooCommerce order items into Woodev_Packer_Input_Item instances.
		 *
		 * Goes through {@see self::order_to_cart_contents()} and {@see self::from_cart_items()}, so an
		 * order is read exactly as a cart package is. Dimensions and weight are converted from the
		 * store's units to the packer's cm / kg.
		 *
		 * @since  1.4.1
		 * @since  2.0.2 Refunded quantities are excluded, a fully refunded line is skipped, lines that need no shipping are skipped (#948).
		 *
		 * @param  \WC_Order $order
		 * @return Woodev_Packer_Input_Item[]
		 */
		public static function from_order_items( \WC_Order $order ): array {
			return self::from_cart_items( self::order_to_cart_contents( $order ) );
		}

		/**
		 * Converts an order's lines into the `contents` of a WooCommerce shipping package.
		 *
		 * The shape mirrors a cart's (`data` is the product, `quantity` what is still to be shipped), and
		 * a line is left out exactly where `WC_Cart::filter_items_needing_shipping()` leaves it out of a
		 * package: a product that does not need shipping (virtual, or suppressed by
		 * `woocommerce_product_needs_shipping`) and a deleted product. The quantity is the ordered one
		 * less the refunded one; a line refunded in full is skipped.
		 *
		 * @since  2.0.2
		 *
		 * @param  \WC_Order $order
		 * @return array<string, array<string, mixed>> package contents, keyed by order item id
		 */
		public static function order_to_cart_contents( \WC_Order $order ): array {
			$contents = [];

			foreach ( $order->get_items() as $order_item ) {
				if ( ! $order_item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$product = $order_item->get_product();

				if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
					continue;
				}

				// what is still to be shipped: a refunded unit is not packed. The sign of WooCommerce's
				// refunded quantity has changed between versions, so only its size is used.
				$qty = (int) $order_item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $order_item->get_id() ) );

				if ( $qty < 1 ) {
					continue;
				}

				$key = (string) $order_item->get_id();

				$contents[ $key ] = [
					'key'          => $key,
					'product_id'   => (int) $order_item->get_product_id(),
					'variation_id' => (int) $order_item->get_variation_id(),
					'quantity'     => $qty,
					'data'         => $product,
				];
			}

			return $contents;
		}

		/**
		 * Builds a packer input item from a product, converting the store's units to the packer's.
		 *
		 * The packer works in centimetres and kilograms, while WooCommerce stores product
		 * dimensions and weight in the store's own units (`woocommerce_dimension_unit`,
		 * `woocommerce_weight_unit` — mm, m, in, yd / g, lbs, oz). `wc_get_dimension()` and
		 * `wc_get_weight()` are WooCommerce's own conversion authority. The values converted are the
		 * EFFECTIVE ones ({@see self::get_effective_values()}): a missing dimension or weight is
		 * replaced by the store's default ({@see Default_Dimensions_Settings}, always positive).
		 *
		 * @since  2.0.2
		 *
		 * @param  \WC_Product $product
		 * @param  int         $quantity
		 * @return Woodev_Packer_Input_Item
		 */
		private static function to_input_item( \WC_Product $product, int $quantity ): Woodev_Packer_Input_Item {
			$values = self::get_effective_values( $product );

			return new Woodev_Packer_Input_Item(
				(float) wc_get_dimension( $values['length'], 'cm' ),
				(float) wc_get_dimension( $values['width'], 'cm' ),
				(float) wc_get_dimension( $values['height'], 'cm' ),
				(float) wc_get_weight( $values['weight'], 'kg' ),
				$quantity
			);
		}

		/**
		 * The length, width, height and weight a product is packed with, in the STORE's units.
		 *
		 * A product's own value wins; when it is missing the store's default for that one dimension
		 * ({@see Default_Dimensions_Settings}, #955) takes its place. «Missing» is empty (WooCommerce
		 * stores '' for an unset field) OR not positive — a product saved with 0 ships as the same
		 * zero-size parcel the carrier refuses, so it is treated as unset. Each of the four is decided
		 * on its own: a product with a weight and no size keeps its weight. Outside the shipping framework
		 * (the settings class is not loaded) there is no store default and a missing value stays 0.0.
		 *
		 * Public because the shipping rate cache keys on what is packed, not on what the product has
		 * ({@see \Woodev\Framework\Shipping\Shipping_Rate_Cache}): the two must not disagree.
		 *
		 * @since  2.0.2
		 *
		 * @param  \WC_Product $product
		 * @return array{length: float, width: float, height: float, weight: float}
		 */
		public static function get_effective_values( \WC_Product $product ): array {
			$defaults = class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Default_Dimensions_Settings' )
				? \Woodev\Framework\Shipping\Settings\Default_Dimensions_Settings::current()
				: null;

			$values = [];

			foreach (
				[
					'length' => $product->get_length(),
					'width'  => $product->get_width(),
					'height' => $product->get_height(),
					'weight' => $product->get_weight(),
				] as $key => $own
			) {
				$own = is_numeric( $own ) ? (float) $own : 0.0;

				$values[ $key ] = $own > 0 || null === $defaults ? max( 0.0, $own ) : $defaults->get_fallback( $key );
			}

			return $values;
		}
	}

endif;

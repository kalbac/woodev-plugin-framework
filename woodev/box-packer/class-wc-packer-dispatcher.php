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
	 * The `boxes` algorithm packs into the store's own boxes — the list a merchant keeps in «Доставка» →
	 * «Коробки» ({@see \Woodev\Framework\Shipping\Settings\Boxes_Settings}) — read here, in the store's units,
	 * and handed to the packer in its cm / kg.
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

			foreach ( $cart_contents as $cart_key => $cart_item ) {
				/** @var \WC_Product|false $product */
				$product = $cart_item['data'] ?? false;

				if ( ! $product instanceof \WC_Product || $product->is_virtual() ) {
					continue;
				}

				$qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

				// the line's own key, which the packed result reports back as the item it packed (#1138)
				$key        = (string) ( $cart_item['key'] ?? $cart_key );
				$product_id = (int) ( $cart_item['variation_id'] ?? 0 ) ?: (int) ( $cart_item['product_id'] ?? 0 );

				$items[] = self::to_input_item( $product, $qty, $key, $product_id );
			}

			return $items;
		}

		/**
		 * Packs items with the named algorithm — `boxes` into the STORE's list of boxes unless `$boxes` is given.
		 *
		 * @since  2.0.2
		 *
		 * @param  string                        $algorithm_id One of the ALGORITHM_* constants.
		 * @param  Woodev_Packer_Packable_Item[] $items        Item data. Must not be empty.
		 * @param  Woodev_Box_Packer_Box[]|null  $boxes        Overrides the store's boxes for `boxes`; null reads
		 *                                                     {@see self::get_store_boxes()}.
		 * @param string                        $leftovers Single or separately for units that fit no box.
		 * @return Woodev_Packer_Result
		 *
		 * @throws Woodev_Packer_Exception If `$items` is empty or `$algorithm_id` is not registered.
		 */
		public static function pack( string $algorithm_id, array $items, ?array $boxes = null, string $leftovers = self::ALGORITHM_SEPARATELY ): Woodev_Packer_Result {

			if ( self::ALGORITHM_BOXES === $algorithm_id && null === $boxes ) {
				$boxes = self::get_store_boxes();
			}

			return parent::pack( $algorithm_id, $items, $boxes, $leftovers );
		}

		/**
		 * The store's boxes as packer boxes, converted from the store's units to the packer's cm / kg.
		 *
		 * The list is the store-wide one of {@see \Woodev\Framework\Shipping\Settings\Boxes_Settings}; outside the
		 * shipping framework (that class is not loaded) there is none and the result is empty. A box's
		 * `max_weight` is null — no limit — when the merchant left it empty or 0.
		 *
		 * @since  2.0.2
		 *
		 * @return Woodev_Packer_Box_Implementation[]
		 */
		public static function get_store_boxes(): array {

			if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Settings\\Boxes_Settings' ) ) {
				return [];
			}

			$boxes = [];

			foreach ( \Woodev\Framework\Shipping\Settings\Boxes_Settings::current()->get_boxes() as $box ) {
				if ( ! $box['enabled'] ) {
					continue;
				}
				$boxes[] = new Woodev_Packer_Box_Implementation(
					(float) wc_get_dimension( $box['length'], 'cm' ),
					(float) wc_get_dimension( $box['width'], 'cm' ),
					(float) wc_get_dimension( $box['height'], 'cm' ),
					(float) wc_get_weight( $box['box_weight'], 'kg' ),
					$box['max_weight'] > 0 ? (float) wc_get_weight( $box['max_weight'], 'kg' ) : null,
					$box['id'],
					$box['name'],
					[
						'origin' => 'store',
						'cost_mode' => 'merchant',
						'cost' => $box['cost'],
					]
				);
			}

			return $boxes;
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
		 * @since  2.0.2 Carries the line's key and product id (#1138).
		 *
		 * @param  \WC_Product $product
		 * @param  int         $quantity
		 * @param  string      $key        the cart line's key, or the order item's id
		 * @param  int         $product_id the product's id, the variation's when there is one
		 * @return Woodev_Packer_Input_Item
		 */
		private static function to_input_item( \WC_Product $product, int $quantity, string $key = '', int $product_id = 0 ): Woodev_Packer_Input_Item {
			$values = self::get_effective_values( $product );

			return new Woodev_Packer_Input_Item(
				(float) wc_get_dimension( $values['length'], 'cm' ),
				(float) wc_get_dimension( $values['width'], 'cm' ),
				(float) wc_get_dimension( $values['height'], 'cm' ),
				(float) wc_get_weight( $values['weight'], 'kg' ),
				$quantity,
				$key,
				$product_id
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

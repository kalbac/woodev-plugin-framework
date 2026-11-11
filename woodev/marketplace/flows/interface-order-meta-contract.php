<?php
/**
 * A marketplace's order meta contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;

defined( 'ABSPATH' ) || exit;

/**
 * What {@see Wc_Order_Store} and {@see Label_Download} ask the marketplace: which meta identify its order, which meta it writes,
 * which product a line is, how two lines compare, what the orders are stamped with and which account owns an order. The adapter
 * answers it through `order_meta()`; the answer of a marketplace that sold on an earlier plugin version is that version's meta, so
 * the orders already in the shop are found again.
 *
 * @since 2.1.0
 */
interface Order_Meta_Contract {
	/**
	 * Returns the `created_via` value of the orders the marketplace's plugin makes.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function created_via(): string;

	/**
	 * Returns the `meta_query` clauses that identify the WooCommerce order of a remote order group.
	 *
	 * @since 2.1.0
	 *
	 * @param Order_DTO $order Whole order.
	 * @return array<int,array{key:string,value:string}>
	 */
	public function identity( Order_DTO $order ): array;

	/**
	 * Writes the marketplace's order meta (the identity included) before the order is saved, and adds the notes a change
	 * deserves.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @param bool      $created  True for an order being created.
	 * @return void
	 */
	public function write_meta( \WC_Order $wc_order, Order_DTO $order, bool $created ): void;

	/**
	 * Runs after the order was saved with its data (public hooks).
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @param bool      $created  True for the first real save.
	 * @return void
	 */
	public function after_save( \WC_Order $wc_order, Order_DTO $order, bool $created ): void;

	/**
	 * Returns the WooCommerce product a line sells.
	 *
	 * @since 2.1.0
	 *
	 * @param Order_DTO  $order Whole order.
	 * @param Order_Line $line  Line.
	 * @return \WC_Product|null Null for a product the shop has not linked.
	 */
	public function product_for( Order_DTO $order, Order_Line $line ): ?\WC_Product;

	/**
	 * Writes the marketplace's item meta of a line.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Order_Item_Product $item  Item.
	 * @param Order_DTO              $order Whole order (financial data per product lives there).
	 * @param Order_Line             $line  Line.
	 * @return void
	 */
	public function write_line_meta( \WC_Order_Item_Product $item, Order_DTO $order, Order_Line $line ): void;

	/**
	 * Tells whether an existing item already is the line: same marketplace product, quantity and unit price.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @param Order_Line             $line Line.
	 * @return bool
	 */
	public function matches( \WC_Order_Item_Product $item, Order_Line $line ): bool;

	/**
	 * Returns the value an account stamps on its orders to prove they are its own: for a marketplace whose orders carry the
	 * seller's cabinet number, that number.
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account.
	 * @return string Empty when the account has none (then it owns no order).
	 */
	public function account_owner( Account $account ): string;

	/**
	 * Returns the owner stamp an order carries, to compare with {@see self::account_owner()}.
	 *
	 * @since 2.1.0
	 *
	 * @param int $order_id Order id.
	 * @return string Empty when the order carries none.
	 */
	public function order_owner( int $order_id ): string;
}

<?php
/**
 * WooCommerce order persistence behind the order writer.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * What the order writer needs from WooCommerce, kept behind an interface so the writer's claim rules stay in the layer and
 * the WordPress calls (order lookup by meta on both stores, stock functions, notes) stay in the plugin.
 *
 * The DTO handed to the store is always the whole WooCommerce order: one Ozon posting, or the live members of a
 * Wildberries cart folded into one DTO by the writer.
 *
 * @since 2.0.29
 */
interface Order_Store {
	/**
	 * Finds the order of a group by its identity meta, ignoring trashed orders.
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO $order Whole order.
	 * @return \WC_Order|null The oldest match, or null.
	 */
	public function find( Order_DTO $order ): ?\WC_Order;

	/**
	 * Loads an order by id.
	 *
	 * @since 2.0.29
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null Null when it does not exist or is trashed.
	 */
	public function get( int $order_id ): ?\WC_Order;

	/**
	 * Saves a new order that has **no stock effect yet**: identity meta, lines and totals in an inert status. It takes stock
	 * only when {@see self::update()} gives it its real status, which the writer does after the order's registration won, so an
	 * order that loses the registration and is discarded never reduced anything (critic R3 finding 2).
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO $order Whole order.
	 * @return \WC_Order
	 */
	public function create( Order_DTO $order ): \WC_Order;

	/**
	 * Tells whether an order was created by {@see self::create()} and has not had its first real status yet (its writer died
	 * or lost before {@see self::update()}): whoever registers or adopts it gives it that status.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Order $wc_order The order.
	 * @return bool
	 */
	public function is_provisional( \WC_Order $wc_order ): bool;

	/**
	 * Brings an order in line with the DTO and saves it: marketplace meta, the line set (replaced only when it differs, with
	 * the stock the old lines took given back first), the status and the member registry.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Order                                                 $wc_order  The order.
	 * @param Order_DTO                                                 $order     Whole order.
	 * @param string                                                    $wc_status WooCommerce status, without `wc-`.
	 * @param array<string,array{status_canonical:string,quantity:int}> $members  Member registry for `_woodev_mp_group_members`.
	 * @param bool                                                      $created   True right after {@see self::create()}.
	 * @return void
	 */
	public function update( \WC_Order $wc_order, Order_DTO $order, string $wc_status, array $members, bool $created ): void;

	/**
	 * Discards an order that lost its registration (moves it to the trash).
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Order $wc_order The order.
	 * @return void
	 */
	public function discard( \WC_Order $wc_order ): void;
}

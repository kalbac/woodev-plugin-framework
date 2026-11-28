<?php
/**
 * A marketplace whose WooCommerce orders hold units.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * An optional marker of the adapter: its orders are grouped — one WooCommerce order per cart, one line per piece of goods
 * ({@see \Woodev\Framework\Marketplace\Orders\Order_Writer::upsert_member()}) — so the «Заказы» table lets an order be opened into
 * its units, cancelled ones included ({@see \Woodev\Framework\Marketplace\Flows\Order_Units}), with the actions offered on one
 * unit. The order screen lists the units of any order that keeps them, with or without this marker.
 *
 * @since 2.1.28
 */
interface Unit_Orders {
}

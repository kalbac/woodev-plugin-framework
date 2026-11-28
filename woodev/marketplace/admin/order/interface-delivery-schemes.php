<?php
/**
 * A marketplace that sells under several delivery schemes.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * An optional interface of the adapter: the schemes its orders are sold under, each a code its order DTOs name
 * ({@see \Woodev\Framework\Marketplace\Orders\Order_DTO::with_scheme()}) and the name the merchant knows it by. The order screen
 * shows an order's scheme, and with more than one scheme the «Заказы» table gets a scheme column and filter.
 *
 * Which scheme the seller delivers is not said here: a DTO of such an order carries the delivery
 * ({@see \Woodev\Framework\Marketplace\Orders\Order_DTO::with_delivery()}).
 *
 * @since 2.1.29
 */
interface Delivery_Schemes {
	/**
	 * Returns the schemes the marketplace sells under.
	 *
	 * @since 2.1.29
	 *
	 * @return array<string,string> Name by code, in the order the filter lists them. A code is lower-case letters, digits and `_`, at
	 *                              most 16 characters; a name is translated in the plugin's text domain.
	 */
	public function delivery_schemes(): array;
}

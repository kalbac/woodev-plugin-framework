<?php
/**
 * Shipping orders — delivery for an order another module owns
 *
 * @since 2.1.29
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Delivery_Entry' ) ) :

	/**
	 * Offers the order wizard's «Оформить доставку» for orders that already exist and are somebody else's (#2010).
	 *
	 * Another module registers one with {@see Orders_Registry::register_delivery_entry()} for the orders it creates itself and the
	 * shop delivers: the manager picks a carrier's tariff or pickup point, and the carrier's shipping line is saved onto the same
	 * order. For an order an entry claims, the wizard edits the DELIVERY only — the recipient, the phone, the shipping address and
	 * the carrier's line — also when the order is later edited from «Заказы доставки». Its items, prices, totals, payment, status
	 * and the shipping lines that are not a carrier's are never written, and the carrier's line adds nothing to the order total:
	 * its price is kept on the line as the shop's own cost ({@see Order_Editor::SELLER_COST_ITEM_META}).
	 *
	 * Open the wizard through {@see Orders_Registry::get_delivery_url()}.
	 *
	 * @since 2.1.29
	 */
	interface Delivery_Entry {
		/**
		 * Whether an order is one of this entry's: only its delivery may be changed through the order wizard.
		 *
		 * Answer from what the order itself stores and make no remote call: it is asked for every row of the orders page.
		 *
		 * @since 2.1.29
		 *
		 * @param \WC_Order $order the order.
		 * @return bool
		 */
		public function claims( \WC_Order $order ): bool;

		/**
		 * Why a claimed order's delivery cannot be arranged or changed right now, in one sentence for the manager. The framework
		 * checks on its own that the order was not exported and is not in a final status.
		 *
		 * @since 2.1.29
		 *
		 * @param \WC_Order $order an order this entry claims.
		 * @return string '' when it can.
		 */
		public function refusal_reason( \WC_Order $order ): string;

		/**
		 * What the manager must know before choosing the delivery — shown above the address in the wizard, one warning each: a
		 * phone number that needs an extension, the day the buyer chose.
		 *
		 * @since 2.1.29
		 *
		 * @param \WC_Order $order an order this entry claims.
		 * @return string[] plain text sentences.
		 */
		public function notices( \WC_Order $order ): array;
	}

endif;

<?php
/**
 * The actions a seller takes on the orders of a marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * An optional capability of the adapter (additive, like {@see Order_Canceller}): the marketplace lets the seller move an order
 * along («Принять в работу», «Передать в доставку»), or write something about one of its goods (a marking code). The adapter
 * declares each action once ({@see Seller_Action}); the «Заказы» table, the details box of the order and the server-side rule
 * ({@see \Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate::seller_reason()}) all read that declaration.
 *
 * The framework offers an action only on a unit that has it and stands in one of its statuses, asks the adapter's own stored
 * refusal, checks the account and the licence, validates the typed input, and only then calls {@see self::run_seller_action()}.
 * Every method but that one answers from what the plugin has stored: none of them calls the marketplace.
 *
 * @since 2.1.27
 */
interface Seller_Actions {
	/**
	 * Declares the actions, in the order the screens show them.
	 *
	 * @since 2.1.27
	 *
	 * @return Seller_Action[] At most one action per id.
	 */
	public function seller_actions(): array;

	/**
	 * Lists the units of the marketplace order or orders a WooCommerce order stands for: the things an action is applied to and
	 * answered for. A marketplace that acts on a whole posting gives one unit for it.
	 *
	 * The list is read for every row of the table, so it comes from the stored order and nothing else.
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Order $order The order.
	 * @return Posting_Unit[] Empty when the plugin stored none for this order.
	 */
	public function posting_units( \WC_Order $order ): array;

	/**
	 * Tells why the marketplace does not let an action be applied to a unit although its status allows it (the unit cannot be
	 * cancelled any more, it already has a delivery). The answer comes from stored data.
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Order    $order     The order.
	 * @param Posting_Unit $unit      One of {@see self::posting_units()}.
	 * @param string       $action_id Id of a declared action.
	 * @return string The sentence for the merchant, in Russian; empty when the marketplace has no objection.
	 */
	public function seller_action_refusal( \WC_Order $order, Posting_Unit $unit, string $action_id ): string;

	/**
	 * Checks what the merchant typed, before anything is sent. The framework has already checked the declared shape (required,
	 * length, an option of a fixed list); this is the marketplace's own rule, for example the format of a marking code. It must
	 * not call the marketplace.
	 *
	 * @since 2.1.27
	 *
	 * @param Account            $account Account of the orders.
	 * @param Seller_Action_Call $call    The action, the units it would be applied to and the typed values.
	 * @return array<string,string> Field id => the sentence for the merchant, in Russian; empty when the input can be sent.
	 */
	public function check_seller_input( Account $account, Seller_Action_Call $call ): array;

	/**
	 * Applies an action on the marketplace and answers for every unit. One call carries every unit of the request — one order
	 * or a whole selection — so the adapter can batch them within the marketplace's limits.
	 *
	 * A unit the answer does not mention is reported to the merchant as unanswered, never as done: a marketplace may refuse a
	 * unit inside a successful HTTP answer.
	 *
	 * @since 2.1.27
	 *
	 * @param Account            $account Account of the orders.
	 * @param Seller_Action_Call $call    The action, the units and the typed values (as typed: the adapter normalises them).
	 * @return Seller_Unit_Result[] One answer per unit of {@see Seller_Action_Call::targets()}.
	 * @throws Seller_Action_Refused When nothing at all was done, with the sentence for the merchant. When only a part of a batch
	 *                               failed, answer with a result per unit instead.
	 */
	public function run_seller_action( Account $account, Seller_Action_Call $call ): array;

	/**
	 * Queues the refresh that brings the new state of these orders into the shop. The framework changes no order itself; it
	 * calls this after an action that may have changed something, and a failure here is only logged.
	 *
	 * @since 2.1.27
	 *
	 * @param Account     $account Account.
	 * @param \WC_Order[] $orders  Orders to refresh.
	 * @return void
	 */
	public function refresh_after_action( Account $account, array $orders ): void;
}

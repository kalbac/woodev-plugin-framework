<?php
/**
 * What a delivery service's news about a seller-delivered order means for the marketplace.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * An optional companion of {@see Seller_Actions} for a marketplace with orders the seller delivers
 * ({@see \Woodev\Framework\Marketplace\Orders\Seller_Delivery}): it names which declared action each piece of news from a delivery
 * service stands for. With it, such an order gets «Оформить доставку» (a delivery service's tariff or pickup point is added to
 * the order), and the shipment's progress is passed on to the marketplace
 * ({@see \Woodev\Framework\Marketplace\Flows\Carrier_Bridge}). Without it nothing of that happens.
 *
 * The actions run exactly as a click would ({@see Seller_Action_Runner}): the same statuses, refusals, claims and order notes. The
 * framework sends them with **no input**, so a field of such an action cannot be required and
 * {@see Seller_Actions::check_seller_input()} must accept the empty values. A call that ends in {@see Seller_Action_Refused} or
 * any other throwable is taken as undecided and is repeated by the framework's job, so answer a refusal the marketplace will give
 * again per unit ({@see Seller_Unit_Result::refused()}).
 *
 * @since 2.1.29
 */
interface Carrier_Actions extends Seller_Actions {
	/**
	 * The delivery service took the order over: sent by itself. «Оформить доставку» is on only while EVERY unit that lists this
	 * action ({@see Posting_Unit}) is in a status that offers it — cancelled units ({@see \Woodev\Framework\Marketplace\Flows\Order_Units})
	 * do not count. So list it for a unit only while that unit can still be handed over: a unit that lists it in any other status
	 * keeps the button off, with the reason.
	 */
	public const HANDED_OVER = 'handed_over';

	/**
	 * The delivery service reports the order delivered: sent by itself, once.
	 *
	 * A refusal that means «the buyer's confirmation code is needed» is answered with {@see Seller_Unit_Result::code_required()}
	 * for each unit — also when the marketplace refused the request as a whole. The framework then asks the merchant to confirm
	 * the delivery with the code and stops sending the action by itself for that account. Any other refusal is answered with
	 * {@see Seller_Unit_Result::refused()}.
	 */
	public const DELIVERED = 'delivered';

	/**
	 * The delivery service reports the order on its way back: never sent by itself, the merchant is asked to run it.
	 */
	public const RETURNED = 'returned';

	/**
	 * Names the action of each piece of news.
	 *
	 * @since 2.1.29
	 *
	 * @return array<string,string> One of the constants of this interface => id of a declared action. News left out sends and asks
	 *                              nothing; without {@see self::HANDED_OVER} the order gets no «Оформить доставку».
	 */
	public function carrier_actions(): array;
}

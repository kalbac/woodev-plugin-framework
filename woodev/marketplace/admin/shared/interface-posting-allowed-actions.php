<?php
/**
 * The marketplace's say on which write actions a posting allows.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * An optional capability of the adapter (additive, like {@see Label_Readiness}): a marketplace that tells, per posting, what may be done
 * with it right now (Ozon sends `available_actions` with every posting) says so here. An action the marketplace did not list is not
 * offered for that posting — «Отменить» and «Этикетки» are disabled with the reason — and the framework refuses it again before any
 * remote call ({@see Posting_Action_Gate}). An adapter without it is asked nothing: the buttons stay on, and the marketplace's own
 * refusal is shown after the click.
 *
 * The answer comes from what the plugin stored when it read the posting, so it never calls the marketplace.
 *
 * @since 2.1.17
 */
interface Posting_Allowed_Actions {
	/**
	 * The action id of cancelling the posting.
	 */
	public const CANCEL = 'cancel';

	/**
	 * The action id of making and downloading the labels of the posting.
	 */
	public const LABEL = 'label';

	/**
	 * Lists the framework actions the marketplace allows for the posting of an order.
	 *
	 * @since 2.1.17
	 *
	 * @param \WC_Order $order The order of the posting.
	 * @return string[]|null The allowed ones among {@see self::CANCEL} and {@see self::LABEL}; an empty list means none of them. Null when
	 *                       the marketplace has not said for this posting (an order stored before the plugin kept the list): the actions
	 *                       are then offered as if the adapter had no say.
	 */
	public function allowed_posting_actions( \WC_Order $order ): ?array;
}

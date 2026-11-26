<?php
/**
 * A cancellation that takes no reason.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * An optional form of {@see Order_Canceller} for a marketplace whose cancellation takes no reason. The framework then offers no
 * reason list: it never calls {@see Order_Canceller::reasons()} or {@see Order_Canceller::other_reason_id()} (both may answer an
 * empty list and 0), and calls {@see Order_Canceller::cancel()} with the reason id 0 and an empty message. The `title` that call
 * returns may be empty: the order note then names no reason.
 *
 * An empty list from `reasons()` of a canceller that is not one of these still means «the marketplace gave no reasons, try later».
 *
 * @since 2.1.27
 */
interface Reasonless_Canceller extends Order_Canceller {
	/**
	 * Says what the merchant should know before confirming, for example that a seller's cancellation is penalised.
	 *
	 * @since 2.1.27
	 *
	 * @return string The sentence for the merchant, in Russian; empty for none.
	 */
	public function cancel_warning(): string;
}

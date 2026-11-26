<?php
/**
 * Labels a marketplace answers at once.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * An optional {@see Label_Provider} of a marketplace whose label call answers with the labels themselves: there is no task to make
 * and none to poll. {@see Label_Service} asks it through `labels_now()` and stores the document right away; `request()` and
 * `fetch()` are never called, so such a provider leaves them empty (`return '';` and `return Label_Answer::pending();`).
 *
 * @since 2.1.28
 */
interface Immediate_Label_Provider extends Label_Provider {
	/**
	 * Asks the marketplace for the labels of remote orders.
	 *
	 * @since 2.1.28
	 *
	 * @param Account  $account       Account the orders belong to.
	 * @param string[] $external_keys Remote-order keys, cleaned and within {@see Label_Provider::max_keys()}.
	 * @return Label_Answer A file or files; anything else ends the request with its message.
	 * @throws Label_Exception With a sentence for the merchant.
	 */
	public function labels_now( Account $account, array $external_keys ): Label_Answer;
}

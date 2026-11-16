<?php
/**
 * A stock reader that tells how many HTTP requests its last read sent.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Optional part of a {@see Stock_Pusher}. A `read()` may page through the marketplace's answer in several HTTP requests while
 * the run counts it as one; a pusher that implements this interface lets the daily reconcile count the requests that really
 * reach the marketplace against its budget, so the budget is a ceiling on traffic and not on calls of the adapter.
 *
 * @since 2.1.8
 */
interface Reports_Read_Requests {
	/**
	 * Returns the HTTP requests the last {@see Stock_Pusher::read()} sent.
	 *
	 * @since 2.1.8
	 *
	 * @return int At least 1 after a read.
	 */
	public function last_read_requests(): int;
}

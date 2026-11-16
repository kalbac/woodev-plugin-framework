<?php
/**
 * A stock reader that states and reports the HTTP requests of its reads.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Stock;

use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Optional part of a {@see Stock_Pusher}. A `read()` may page through the marketplace's answer in several HTTP requests while
 * the run counts it as one. A pusher that can page **must** implement this interface: the daily reconcile then starts a read only
 * when the run's request budget still covers {@see self::max_read_requests()}, and charges the requests the read really sent
 * ({@see self::last_read_requests()}). A pusher that does not implement it is taken to send one HTTP request per read.
 *
 * The bound is a promise: a read never sends more HTTP requests than `max_read_requests()` said for the same warehouse and links.
 *
 * @since 2.1.8
 */
interface Reports_Read_Requests {
	/**
	 * Returns the most HTTP requests {@see Stock_Pusher::read()} will send for these links.
	 *
	 * @since 2.1.8
	 *
	 * @param int            $warehouse_id Marketplace warehouse id.
	 * @param Product_Link[] $links        The links the read is for.
	 * @return int At least 1.
	 */
	public function max_read_requests( int $warehouse_id, array $links ): int;

	/**
	 * Returns the HTTP requests the last {@see Stock_Pusher::read()} sent.
	 *
	 * @since 2.1.8
	 *
	 * @return int At least 1 after a read, and never more than the bound given for it.
	 */
	public function last_read_requests(): int;
}

<?php
/**
 * Stock push contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Stock;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Links\Product_Link;

defined( 'ABSPATH' ) || exit;

/**
 * Sends absolute stock quantities to a marketplace warehouse and reads them back. The adapter resolves the marketplace
 * key from each link's `remote_ids` (Ozon `offer_id` / `product_id`; Wildberries `chrtID`) and does the batching.
 *
 * @since 2.0.29
 */
interface Stock_Pusher {
	/**
	 * Sets stock for several links in one warehouse.
	 *
	 * @since 2.0.29
	 *
	 * @param Account      $account      Account.
	 * @param int          $warehouse_id Marketplace warehouse id.
	 * @param Stock_Line[] $lines        Quantities to set.
	 * @return Item_Result[] One result per line, keyed by link id.
	 */
	public function push( Account $account, int $warehouse_id, array $lines ): array;

	/**
	 * Reads the marketplace's current stock.
	 *
	 * @since 2.0.29
	 *
	 * @param Account        $account      Account.
	 * @param int            $warehouse_id Marketplace warehouse id.
	 * @param Product_Link[] $links        Links to read.
	 * @return array<int,array{present:int,reserved:int}> Keyed by link id.
	 */
	public function read( Account $account, int $warehouse_id, array $links ): array;
}

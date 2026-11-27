<?php
/**
 * Deletes an account's postings with the buyer data their orders keep.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The «postings» step of an account's deletion. The WooCommerce orders stay, with their recipient and address; the marketplace's
 * own data about the buyer that the framework keeps on them ({@see Seller_Order}) goes, before the posting row that names the
 * order is removed. An interrupted run repeats the batch: erasing twice changes nothing.
 *
 * @since 2.1.29
 */
final class Posting_Eraser {
	/**
	 * Most postings one call handles: every one with an order loads it.
	 */
	private const BATCH = 100;

	/**
	 * @var Order_Registry
	 */
	private $registry;

	/**
	 * @since 2.1.29
	 *
	 * @param Order_Registry $registry The postings registry.
	 */
	public function __construct( Order_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Erases and deletes a batch of an account's postings.
	 *
	 * @since 2.1.29
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		$batch = $this->registry->batch_of_account( $account_id, min( $limit, self::BATCH ) );

		foreach ( $batch as $order_id ) {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

			if ( $order instanceof \WC_Order ) {
				Seller_Order::erase_buyer_data( $order );
			}
		}

		return $this->registry->delete_postings( $account_id, array_map( 'strval', array_keys( $batch ) ) );
	}
}

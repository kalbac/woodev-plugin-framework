<?php
/**
 * Deletes an account's postings with the buyer data their orders keep.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The «postings» step of an account's deletion. The WooCommerce orders stay, with their recipient and address; the marketplace's
 * own data about the buyer that the framework keeps on them ({@see Seller_Order}) goes, before the posting row that names the
 * order is removed. The row is the only way back to the order, so it stays until the order's data is gone: an order that cannot
 * be loaded fails the run, which is retried, unless the order store confirms that there is no such order. An interrupted run
 * repeats the batch: erasing twice changes nothing.
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
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Where the orders live; the shop's current store when null.
	 *
	 * @var Site_Orders_Storage|null
	 */
	private $storage;

	/**
	 * @since 2.1.29
	 *
	 * @param Order_Registry           $registry The postings registry.
	 * @param \wpdb                    $db       Database.
	 * @param Site_Orders_Storage|null $storage  Where the orders live; the shop's current order store when null.
	 */
	public function __construct( Order_Registry $registry, \wpdb $db, ?Site_Orders_Storage $storage = null ) {
		$this->registry = $registry;
		$this->db       = $db;
		$this->storage  = $storage;
	}

	/**
	 * Erases and deletes a batch of an account's postings.
	 *
	 * @since 2.1.29
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when the account has none left.
	 * @throws \RuntimeException When the postings or one of their orders cannot be read; no posting of the batch is deleted.
	 */
	public function delete_account( int $account_id, int $limit ): int {
		$batch = $this->registry->batch_of_account( $account_id, min( $limit, self::BATCH ) );

		foreach ( $batch as $order_id ) {
			if ( $order_id > 0 ) {
				$this->erase_order( $order_id );
			}
		}

		return $this->registry->delete_postings( $account_id, array_map( 'strval', array_keys( $batch ) ) );
	}

	/**
	 * Removes the buyer's data of one posting's order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 * @throws \RuntimeException When the order cannot be loaded and is not confirmed gone.
	 */
	private function erase_order( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			Seller_Order::erase_buyer_data( $order );

			return;
		}

		// WooCommerce answers false for a deleted order and for one it failed to read.
		if ( ! $this->is_gone( $order_id ) ) {
			throw new \RuntimeException( 'The order ' . $order_id . ' of a posting could not be loaded.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Asks the order store whether it has no such order. Only a successful read that finds nothing says so.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private function is_gone( int $order_id ): bool {
		$storage = $this->storage ?? Site_Orders_Storage::current();
		$found   = $this->db->get_var( 'SELECT COUNT(*) FROM ' . $storage->orders_from( $this->db ) . $this->db->prepare( ' WHERE ' . $storage->id_column() . ' = %d', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared or fixed fragments; the deletion reads the current store.

		return '' === (string) $this->db->last_error && '0' === (string) $found;
	}
}

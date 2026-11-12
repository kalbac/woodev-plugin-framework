<?php
/**
 * Deletes the articles an account pinned to products.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Ledger\Account_Rows;

defined( 'ABSPATH' ) || exit;

/**
 * The article a merchant fixed on a product for one account lives in the product's meta under {@see Pinned_Offer_Id::meta_key()}.
 * Deleting the account deletes the meta of every product, in batches.
 *
 * @since 2.1.5
 */
final class Pinned_Offer_Eraser {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Marketplace id (the adapter's `id()`).
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Creates the eraser.
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb  $db             Database.
	 * @param string $marketplace_id The adapter's id.
	 */
	public function __construct( \wpdb $db, string $marketplace_id ) {
		$this->db             = $db;
		$this->marketplace_id = $marketplace_id;
	}

	/**
	 * Deletes a batch of the account's pinned articles.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @param int $limit      Most rows to delete in this call.
	 * @return int Rows deleted; 0 when none is left.
	 */
	public function erase( int $account_id, int $limit ): int {
		$key = Pinned_Offer_Id::meta_prefix( $this->marketplace_id ) . $account_id;

		return Account_Rows::delete_where( $this->db, $this->db->postmeta, 'meta_key = %s', [ $key ], $limit );
	}
}

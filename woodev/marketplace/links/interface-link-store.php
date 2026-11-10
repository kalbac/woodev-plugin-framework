<?php
/**
 * Product link persistence contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes product links.
 *
 * @since 2.0.29
 */
interface Link_Store {
	/**
	 * Finds the link of a WooCommerce product or variation.
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id    Account id.
	 * @param int $wc_product_id Product or variation id.
	 * @return Product_Link|null
	 */
	public function by_product( int $account_id, int $wc_product_id ): ?Product_Link;

	/**
	 * Finds a link by the seller article.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $account_id Account id.
	 * @param string $sku        Seller article.
	 * @return Product_Link|null
	 */
	public function by_seller_sku( int $account_id, string $sku ): ?Product_Link;

	/**
	 * Finds a link by one of the marketplace's identifiers.
	 *
	 * @since 2.0.29
	 *
	 * @param int        $account_id Account id.
	 * @param string     $key        Identifier name (`sku`, `chrtID`, ...).
	 * @param int|string $value      Identifier value.
	 * @return Product_Link|null
	 */
	public function by_remote_id( int $account_id, string $key, $value ): ?Product_Link;

	/**
	 * Inserts or updates a link.
	 *
	 * @since 2.0.29
	 *
	 * @param Product_Link $link Link to save; a null link id inserts.
	 * @return int Saved link id.
	 */
	public function save( Product_Link $link ): int;

	/**
	 * Changes the state of a link and records the last error text.
	 *
	 * @since 2.0.29
	 *
	 * @param int         $link_id Link id.
	 * @param string      $state   New state.
	 * @param string|null $error   Marketplace error text, or null to clear it.
	 * @return void
	 */
	public function set_state( int $link_id, string $state, ?string $error = null ): void;
}

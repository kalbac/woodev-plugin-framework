<?php
/**
 * In-memory link store.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Links\Link_Store;
use Woodev\Framework\Marketplace\Links\Product_Link;

/**
 * Keeps links in memory and finds them by product, article or any remote identifier.
 */
final class Fake_Link_Store implements Link_Store {
	/**
	 * Links by id.
	 *
	 * @var array<int,Product_Link>
	 */
	private $links = array();

	/**
	 * Last error text by link id.
	 *
	 * @var array<int,string|null>
	 */
	private $errors = array();

	/**
	 * Finds a link by product.
	 *
	 * @param int $account_id    Account id.
	 * @param int $wc_product_id Product id.
	 * @return Product_Link|null
	 */
	public function by_product( int $account_id, int $wc_product_id ): ?Product_Link {
		foreach ( $this->links as $link ) {
			if ( $link->account_id() === $account_id && $link->wc_product_id() === $wc_product_id ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Finds a link by seller article.
	 *
	 * @param int    $account_id Account id.
	 * @param string $sku        Article.
	 * @return Product_Link|null
	 */
	public function by_seller_sku( int $account_id, string $sku ): ?Product_Link {
		foreach ( $this->links as $link ) {
			if ( $link->account_id() === $account_id && $link->seller_sku() === $sku ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Finds a link by a remote identifier.
	 *
	 * @param int        $account_id Account id.
	 * @param string     $key        Identifier name.
	 * @param int|string $value      Identifier value.
	 * @return Product_Link|null
	 */
	public function by_remote_id( int $account_id, string $key, $value ): ?Product_Link {
		foreach ( $this->links as $link ) {
			if ( $link->account_id() === $account_id && (string) $link->remote_id( $key ) === (string) $value ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Saves a link; a link without id gets the next one.
	 *
	 * @param Product_Link $link Link.
	 * @return int
	 */
	public function save( Product_Link $link ): int {
		$id = $link->link_id();
		if ( null === $id ) {
			$id   = count( $this->links ) + 1;
			$link = $link->with_link_id( $id );
		}

		$this->links[ $id ] = $link;

		return $id;
	}

	/**
	 * Changes the state of a link.
	 *
	 * @param int         $link_id Link id.
	 * @param string      $state   State.
	 * @param string|null $error   Error text.
	 * @return void
	 */
	public function set_state( int $link_id, string $state, ?string $error = null ): void {
		$this->links[ $link_id ]  = $this->links[ $link_id ]->with_state( $state );
		$this->errors[ $link_id ] = $error;
	}

	/**
	 * Returns the last error text of a link.
	 *
	 * @param int $link_id Link id.
	 * @return string|null
	 */
	public function error_of( int $link_id ): ?string {
		return $this->errors[ $link_id ] ?? null;
	}
}

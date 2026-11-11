<?php
/**
 * WooCommerce stock changes → `sync_state` intents.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The request-time half of `site` stock mode and of the archive flow. **Nothing here calls the marketplace**: a change only
 * becomes the pending payload of its pair, and the account's flusher sends it.
 *
 * - **A stock change** (`woocommerce_product_set_stock` / `woocommerce_variation_set_stock`, fired by every WooCommerce stock
 *   write: the product form, an order's reduce or restore, our own writes) records `{stock}` for each offer link of the product
 *   in an account in `site` mode with `sync_stock` on and a warehouse ({@see Stock_Warehouse}). The quantity follows
 *   {@see Stock_Policy}. A variable product that manages its variations' stock records for every variation it manages.
 * - **A product trashed or deleted** with a `created` link records `{stock: 0, archive: true}`; the flusher archives the card
 *   once the marketplace has accepted the zero. A pair without a warehouse is archived without the zero. Taking the product out
 *   of the trash records its stock again, which cancels an archive not yet done.
 *
 * Accounts in `marketplace` mode get nothing: the marketplace's stock is the truth there, and the plugin's own mirror write
 * must not echo back.
 *
 * @since 2.0.30
 */
final class Stock_Intents {
	public const ARCHIVE_FLAG = 'archive';

	/**
	 * Returns an account: `function ( int $account_id ): ?Account`.
	 *
	 * @var callable
	 */
	private $accounts;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Pending side of `sync_state`.
	 *
	 * @var Sync_State_Store
	 */
	private $intents;

	/**
	 * Readback side of `sync_state` (for the reservation of the `wc_plus_reserved` formula).
	 *
	 * @var Sync_State_Queue
	 */
	private $queue;

	/**
	 * Formula.
	 *
	 * @var Stock_Policy
	 */
	private $policy;

	/**
	 * Creates the recorder.
	 *
	 * @since 2.0.30
	 *
	 * @param callable         $accounts `function ( int $account_id ): ?Account`.
	 * @param Wpdb_Link_Store  $links    Links.
	 * @param Sync_State_Store $intents  Pending side of `sync_state`.
	 * @param Sync_State_Queue $queue    Readback side of `sync_state`.
	 * @param Stock_Policy     $policy   The formula in force (the adapter's `stock_policy()`).
	 */
	public function __construct( callable $accounts, Wpdb_Link_Store $links, Sync_State_Store $intents, Sync_State_Queue $queue, Stock_Policy $policy ) {
		$this->accounts = $accounts;
		$this->links    = $links;
		$this->intents  = $intents;
		$this->queue    = $queue;
		$this->policy   = $policy;
	}

	/**
	 * Hooks the recorder.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_change' ] );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'on_stock_change' ] );
		add_action( 'wp_trash_post', [ $this, 'on_removed' ] );
		add_action( 'before_delete_post', [ $this, 'on_removed' ] );
		add_action( 'untrashed_post', [ $this, 'on_restored' ] );
	}

	/**
	 * Records the new stock of a product for every pair it feeds.
	 *
	 * @since 2.0.30
	 *
	 * @param mixed $product The product whose stock changed.
	 * @return void
	 */
	public function on_stock_change( $product ): void {
		if ( ! $product instanceof \WC_Product || 'trash' === get_post_status( $product->get_id() ) ) {
			return;
		}

		foreach ( $this->links->offers_of_product( $product->get_id() ) as $link ) {
			if ( $this->fed_by( $link, $product ) ) {
				$this->record_stock( $link );
			}
		}
	}

	/**
	 * Records the archive intent of a trashed or deleted product's created cards.
	 *
	 * @since 2.0.30
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public function on_removed( $post_id ): void {
		if ( ! self::is_product( (int) $post_id ) ) {
			return;
		}

		foreach ( $this->links->offers_of_product( (int) $post_id ) as $link ) {
			$this->record_archive( $link );
		}
	}

	/**
	 * Records the archive intent of one link: stock zero, then the card goes to the marketplace's archive. Only a `created`
	 * card is archived. The product screen's archive action calls this, and so does the trash hook.
	 *
	 * @since 2.0.30
	 *
	 * @param Link_Record $link Link.
	 * @return bool True when the intent was recorded.
	 */
	public function record_archive( Link_Record $link ): bool {
		$account = $this->account( $link->account_id() );

		if ( null === $account || Link_Record::STATE_CREATED !== $link->card_state() || null === $link->link_id() ) {
			return false;
		}

		return $this->intents->record(
			$account->id(),
			(int) $link->link_id(),
			(int) Stock_Warehouse::for_link( $account, $link ),
			Sync_State_Store::KIND_STOCK,
			[
				'stock'            => 0,
				self::ARCHIVE_FLAG => true,
			]
		);
	}

	/**
	 * Records the stock of a product taken out of the trash again.
	 *
	 * @since 2.0.30
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public function on_restored( $post_id ): void {
		$product = self::is_product( (int) $post_id ) ? wc_get_product( (int) $post_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		foreach ( $this->links->offers_of_product( $product->get_id() ) as $link ) {
			$this->record_stock( $link );
		}
	}

	/**
	 * Records the current stock of one link when its account pushes stock.
	 *
	 * @since 2.0.30
	 *
	 * @param Link_Record $link Link.
	 * @return bool True when an intent was recorded.
	 */
	public function record_stock( Link_Record $link ): bool {
		$account = $this->account( $link->account_id() );

		if ( null === $account || null === $link->link_id() || ! $link->sync_stock() || ! Stock_Mode::is_site( $account ) ) {
			return false;
		}

		$warehouse = Stock_Warehouse::for_link( $account, $link );
		$quantity  = self::managed_quantity( wc_get_product( $link->wc_product_id() ) );

		if ( null === $warehouse || null === $quantity ) {
			return false; // «Не выбран склад» or no managed stock: the diagnostics tell the merchant; nothing is sent.
		}

		$key      = Sync_State_Queue::key( $account->id(), (int) $link->link_id(), $warehouse, Sync_State_Store::KIND_STOCK );
		$reserved = Stock_Policy::FORMULA_PLUS_RESERVED === $this->policy->formula() ? $this->queue->remote_reserved( $key ) : null;

		return $this->intents->record( $account->id(), (int) $link->link_id(), $warehouse, Sync_State_Store::KIND_STOCK, [ 'stock' => $this->policy->pushed_quantity( $quantity, $reserved ) ] );
	}

	/**
	 * Returns the stock a product sells from, or null when nothing manages it. A variation whose parent manages the stock reads
	 * the parent's pool, like {@see self::fed_by()}.
	 *
	 * @param mixed $product What `wc_get_product()` returned.
	 * @return int|null
	 */
	private static function managed_quantity( $product ): ?int {
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$managed = $product->managing_stock() || (int) $product->get_stock_managed_by_id() !== $product->get_id();
		$stock   = $managed ? $product->get_stock_quantity() : null;

		return null === $stock ? null : (int) $stock;
	}

	/**
	 * Tells whether a link's stock comes from the product that changed: its own product, or the variable parent that manages it.
	 *
	 * @param Link_Record $link    Link.
	 * @param \WC_Product $product Product whose stock changed.
	 * @return bool
	 */
	private function fed_by( Link_Record $link, \WC_Product $product ): bool {
		if ( $link->wc_product_id() === $product->get_id() ) {
			return true;
		}

		$variation = wc_get_product( $link->wc_product_id() );

		return $variation instanceof \WC_Product && $variation->get_stock_managed_by_id() === $product->get_id();
	}

	/**
	 * Tells whether a post is a product or a variation.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function is_product( int $post_id ): bool {
		return in_array( get_post_type( $post_id ), [ 'product', 'product_variation' ], true );
	}

	/**
	 * Loads an account.
	 *
	 * @param int $account_id Account id.
	 * @return Account|null
	 */
	private function account( int $account_id ): ?Account {
		$account = call_user_func( $this->accounts, $account_id );

		return $account instanceof Account ? $account : null;
	}
}

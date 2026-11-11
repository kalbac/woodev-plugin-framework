<?php
/**
 * Price intents for the `to_marketplace` direction.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Request-time half of the price flow: it works out **what price a card should carry on the marketplace** and writes that wish
 * into `sync_state(kind = price).pending_payload` as the whole payload `{price, old_price, min_price, currency_code}` with a
 * new revision. **It calls no marketplace method**; {@see \Woodev\Framework\Marketplace\Jobs\Price_Flush_Runner} sends what
 * differs from the sent payload.
 *
 * Triggers:
 * - WooCommerce saved a product with a changed `regular_price`, `sale_price`, `price` or `date_on_sale_*`
 *   ({@see self::on_product_updated()}, hooked to `woocommerce_product_object_updated_props`);
 * - a rule changed on the account, a profile or a link: the account's links are re-worked in pages
 *   ({@see self::recompute_page()}, driven by the recompute job; {@see self::change_override()} for one link);
 * - the card reached `created`: the export flow writes the same payload.
 *
 * A link is considered when its account pushes prices (`price_direction = to_marketplace`), the link has `sync_price` on and
 * its card is `created`. A write of the payload a row already holds as pending changes nothing, so recomputing everything after
 * a rule edit does not inflate revisions.
 *
 * @since 2.0.30
 */
final class Price_Intents {
	public const RECORDED    = 'recorded';
	public const OFF         = 'off';
	public const NOT_CREATED = 'not_created';
	public const NO_PRICE    = 'no_price';
	public const INVALID     = 'invalid';

	/**
	 * Product properties whose change can change what the marketplace should show.
	 *
	 * @var string[]
	 */
	public const WATCHED_PROPS = [ 'regular_price', 'sale_price', 'price', 'date_on_sale_from', 'date_on_sale_to' ];

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * The adapter's id, which the accounts are listed by.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The export side's price answers (mapping, profile, rule, rate).
	 *
	 * @var Price_Context
	 */
	private $contexts;

	/**
	 * Intents.
	 *
	 * @var Sync_State_Store
	 */
	private $store;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Accounts that push prices, read once per request.
	 *
	 * @var Account[]|null
	 */
	private $pushing;

	/**
	 * Creates the recorder.
	 *
	 * @since 2.0.30
	 *
	 * @param Wpdb_Account_Store $accounts       Accounts.
	 * @param string             $marketplace_id The adapter's id.
	 * @param Wpdb_Link_Store    $links          Links.
	 * @param Price_Context      $contexts       The export side's price answers.
	 * @param Sync_State_Store   $store          Intents.
	 * @param callable           $log_error      `function ( string $line ): void`.
	 */
	public function __construct( Wpdb_Account_Store $accounts, string $marketplace_id, Wpdb_Link_Store $links, Price_Context $contexts, Sync_State_Store $store, callable $log_error ) {
		$this->accounts       = $accounts;
		$this->marketplace_id = $marketplace_id;
		$this->links          = $links;
		$this->contexts       = $contexts;
		$this->store          = $store;
		$this->log_error      = $log_error;
	}

	/**
	 * Hooks the recorder to WooCommerce's product save.
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_product_object_updated_props', [ $this, 'on_product_updated' ], 10, 2 );
	}

	/**
	 * Tells whether an account pushes its prices to the marketplace.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public static function pushes( Account $account ): bool {
		return Wpdb_Account_Store::PRICE_TO_MARKETPLACE === (string) $account->setting( 'price_direction', Wpdb_Account_Store::PRICE_TO_MARKETPLACE );
	}

	/**
	 * Handles `woocommerce_product_object_updated_props`: re-works the price of the product's links in every account that
	 * pushes prices. Ignored while a `from_marketplace` write is saving the product ({@see Price_Refresh_Guard}).
	 *
	 * @since 2.0.30
	 *
	 * @param mixed $product       The saved product.
	 * @param mixed $updated_props Names of the properties that changed.
	 * @return void
	 */
	public function on_product_updated( $product, $updated_props = [] ): void {
		if ( ! $product instanceof \WC_Product || Price_Refresh_Guard::is_active() || [] === array_intersect( self::WATCHED_PROPS, (array) $updated_props ) ) {
			return;
		}

		try {
			foreach ( $this->pushing_accounts() as $account ) {
				$link = $this->links->find( $account->id(), (int) $product->get_id() );

				if ( null !== $link && $link->is_offer() ) {
					$this->record_for_link( $account, $link, $product );
				}
			}
		} catch ( \Throwable $e ) {
			call_user_func( $this->log_error, 'price intent after a product save failed: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Works out one link's price and writes it as the pending payload.
	 *
	 * @since 2.0.30
	 *
	 * @param Account          $account Account.
	 * @param Link_Record      $link    Offer row.
	 * @param \WC_Product|null $product Product, loaded when null.
	 * @return string `RECORDED`, or why nothing was written: `OFF` (the account or the link does not push prices),
	 *                `NOT_CREATED` (the card is not created yet; the creation seeds the price), `NO_PRICE` (the product has no
	 *                price), `INVALID` (the rule, the rate or the override cannot be read; logged).
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function record_for_link( Account $account, Link_Record $link, ?\WC_Product $product = null ): string {
		if ( ! self::pushes( $account ) || ! $link->sync_price() || null === $link->link_id() ) {
			return self::OFF;
		}

		if ( Link_Record::STATE_CREATED !== $link->card_state() ) {
			return self::NOT_CREATED;
		}

		$product = $product ?? $this->contexts->product( $link->wc_product_id() );

		if ( null === $product ) {
			return self::NO_PRICE;
		}

		try {
			$pair = $this->contexts->price_pair( $account, $product, $link );
		} catch ( \InvalidArgumentException $e ) {
			call_user_func( $this->log_error, 'price intent of link ' . $link->link_id() . ' not recorded: ' . $e->getMessage() );

			return self::INVALID;
		}

		if ( null === $pair ) {
			return self::NO_PRICE;
		}

		$this->store->record( $account->id(), (int) $link->link_id(), 0, Sync_State_Store::KIND_PRICE, $this->contexts->payload( $pair ) );

		return self::RECORDED;
	}

	/**
	 * Re-works one page of an account's links after a rule changed.
	 *
	 * @since 2.0.30
	 *
	 * @param Account $account       Account.
	 * @param int     $after_link_id Last link id already re-worked; 0 starts at the beginning.
	 * @param int     $limit         Links per page.
	 * @return int The last link id of the page to continue after, 0 when the account is done.
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function recompute_page( Account $account, int $after_link_id, int $limit ): int {
		if ( ! self::pushes( $account ) ) {
			return 0;
		}

		$page = $this->links->page( $account->id(), $after_link_id, $limit );

		foreach ( $page as $link ) {
			if ( $link->is_offer() ) {
				$this->record_for_link( $account, $link );
			}
		}

		return count( $page ) < $limit || [] === $page ? 0 : (int) end( $page )->link_id();
	}

	/**
	 * Stores a link's price override and re-works its price (the product screen and the tools call this).
	 *
	 * @since 2.0.30
	 *
	 * @param Account                  $account  Account.
	 * @param Link_Record              $link     Offer row.
	 * @param array<string,mixed>|null $override New override; null or empty clears it.
	 * @return string The outcome of {@see self::record_for_link()}.
	 * @throws \InvalidArgumentException When the override is malformed (nothing is stored).
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function change_override( Account $account, Link_Record $link, ?array $override ): string {
		$this->links->set_price_override( (int) $link->link_id(), $override );

		$fresh = $this->links->get( (int) $link->link_id() );

		return null === $fresh ? self::OFF : $this->record_for_link( $account, $fresh );
	}

	/**
	 * Lists the enabled accounts that push prices.
	 *
	 * @return Account[]
	 */
	private function pushing_accounts(): array {
		if ( null === $this->pushing ) {
			$this->pushing = array_values( array_filter( $this->accounts->all( $this->marketplace_id, true ), [ self::class, 'pushes' ] ) );
		}

		return $this->pushing;
	}
}

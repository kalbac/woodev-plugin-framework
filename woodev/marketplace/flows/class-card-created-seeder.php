<?php
/**
 * The first stock and price intents of a created card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Stock_Warehouse;

defined( 'ABSPATH' ) || exit;

/**
 * The transition to `created` itself seeds two `sync_state` rows, so a product exported once and never edited again still gets its
 * minimum price (which a marketplace may ignore on import) and its stock:
 *
 * - **price** — the card's own price pair ({@see Export_Price_Policy}, already in the cabinet currency, rendered by
 *   {@see Export_Context_Builder::payload()}) — when the account's `price_direction` is `to_marketplace` and the link's `sync_price`
 *   is on;
 * - **stock** `{stock}` — the WooCommerce stock quantity — when the account's `stock_mode` is `site`, the link's `sync_stock` is on,
 *   a warehouse resolves ({@see Stock_Warehouse}; none = nothing) and the product manages its stock. The quantity follows the
 *   marketplace's {@see Stock_Policy}; no reservation is known yet, so the formula that adds it adds 0 too.
 *
 * The writes are idempotent ({@see Sync_State_Store::record()}); the poll calls this before it marks the link `created`, so a failed
 * seeding is retried by the next poll. Sending is the flushers' job.
 *
 * @since 2.1.0
 */
final class Card_Created_Seeder {
	/**
	 * Most times one kind is rebuilt and stored again after another writer won its revision.
	 */
	private const STORE_ATTEMPTS = 3;

	/**
	 * Why a kind has nothing to store; see {@see self::intent()}.
	 */
	public const NO_PRODUCT   = 'no_product';
	public const NO_PRICE     = 'no_price';
	public const NO_STOCK     = 'no_stock_quantity';
	public const NO_WAREHOUSE = 'no_warehouse';

	/**
	 * Intents.
	 *
	 * @var Sync_State_Store
	 */
	private $intents;

	/**
	 * Export inputs.
	 *
	 * @var Export_Context_Builder
	 */
	private $contexts;

	/**
	 * The marketplace's stock formula.
	 *
	 * @var Stock_Policy
	 */
	private $stock_policy;

	/**
	 * Creates the seeder.
	 *
	 * @since 2.1.0
	 *
	 * @param Sync_State_Store       $intents      Intents.
	 * @param Export_Context_Builder $contexts     Export inputs.
	 * @param Stock_Policy           $stock_policy The adapter's {@see \Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter::stock_policy()}.
	 */
	public function __construct( Sync_State_Store $intents, Export_Context_Builder $contexts, Stock_Policy $stock_policy ) {
		$this->intents      = $intents;
		$this->contexts     = $contexts;
		$this->stock_policy = $stock_policy;
	}

	/**
	 * Seeds the intents of a link.
	 *
	 * A kind is reported as seeded only when its row really holds the payload. When another writer takes the revision first (the
	 * store's UPDATE is keyed on the revision it read) the payload is rebuilt from freshly loaded product data and stored again, up
	 * to {@see self::STORE_ATTEMPTS} times, so the newest data wins instead of a stale copy.
	 *
	 * @since 2.1.0
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link becoming `created`.
	 * @param string[]    $kinds   Kinds to seed (`price`, `stock`); both when empty. The products screen passes one kind for «Отправить цены» / «Отправить остатки».
	 * @return string[] Kinds seeded.
	 * @throws \RuntimeException When an intent cannot be stored, or another writer kept taking its revision.
	 */
	public function seed( Account $account, Link_Record $link, array $kinds = [] ): array {
		if ( null === $link->link_id() ) {
			return [];
		}

		$seeded = [];

		foreach ( [ Sync_State_Store::KIND_PRICE, Sync_State_Store::KIND_STOCK ] as $kind ) {
			if ( $this->kind_asked( $kinds, $kind ) && $this->wanted( $account, $link, $kind ) && $this->store( $account, $link, $kind ) ) {
				$seeded[] = $kind;
			}
		}

		return $seeded;
	}

	/**
	 * Builds the payload of a kind from the product as it is now, without storing anything.
	 *
	 * The products screen asks it for its preview, so the preview and the write agree about what can be sent.
	 *
	 * @since 2.1.0
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @param string      $kind    `price` or `stock`.
	 * @return array{warehouse:int,payload:?array<string,mixed>,reason:string} `reason` is one of the `NO_*` constants when there is no payload, else empty.
	 */
	public function intent( Account $account, Link_Record $link, string $kind ): array {
		$product = $this->contexts->product( $link->wc_product_id() );

		if ( null === $product ) {
			return [
				'warehouse' => 0,
				'payload'   => null,
				'reason'    => self::NO_PRODUCT,
			];
		}

		if ( Sync_State_Store::KIND_PRICE === $kind ) {
			$payload = $this->price_payload( $account, $product, $link );

			return [
				'warehouse' => 0,
				'payload'   => $payload,
				'reason'    => null === $payload ? self::NO_PRICE : '',
			];
		}

		$warehouse = Stock_Warehouse::for_link( $account, $link );
		$quantity  = $product->get_stock_quantity();
		$reason    = null === $warehouse ? self::NO_WAREHOUSE : ( null === $quantity ? self::NO_STOCK : '' );

		return [
			'warehouse' => (int) $warehouse,
			'payload'   => '' === $reason ? [ 'stock' => $this->stock_policy->pushed_quantity( (int) $quantity, null ) ] : null,
			'reason'    => $reason,
		];
	}

	/**
	 * Stores one kind, rebuilding it when the revision was lost.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @param string      $kind    `price` or `stock`.
	 * @return bool True when stored; false when there is nothing to store.
	 * @throws \RuntimeException When another writer kept taking the revision.
	 */
	private function store( Account $account, Link_Record $link, string $kind ): bool {
		for ( $attempt = 1; $attempt <= self::STORE_ATTEMPTS; $attempt++ ) {
			$intent = $this->intent( $account, $link, $kind );

			if ( null === $intent['payload'] ) {
				return false;
			}

			if ( $this->intents->record( $account->id(), (int) $link->link_id(), $intent['warehouse'], $kind, $intent['payload'] ) ) {
				return true;
			}
		}

		throw new \RuntimeException( 'The ' . $kind . ' intent of link ' . (int) $link->link_id() . ' was changed by another request while it was stored.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
	}

	/**
	 * Tells whether the account and the link want a kind sent.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @param string      $kind    `price` or `stock`.
	 * @return bool
	 */
	private function wanted( Account $account, Link_Record $link, string $kind ): bool {
		if ( Sync_State_Store::KIND_PRICE === $kind ) {
			return Wpdb_Account_Store::PRICE_TO_MARKETPLACE === (string) $account->setting( 'price_direction', Wpdb_Account_Store::PRICE_TO_MARKETPLACE ) && $link->sync_price();
		}

		return Wpdb_Account_Store::STOCK_SITE === (string) $account->setting( 'stock_mode', Wpdb_Account_Store::STOCK_SITE ) && $link->sync_stock();
	}

	/**
	 * Tells whether a kind was asked for.
	 *
	 * @param string[] $kinds Kinds asked for; none means all.
	 * @param string   $kind  Kind.
	 * @return bool
	 */
	private function kind_asked( array $kinds, string $kind ): bool {
		return [] === $kinds || in_array( $kind, $kinds, true );
	}

	/**
	 * Builds the full price payload, or null when the product has no usable price.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product.
	 * @param Link_Record $link    Link.
	 * @return array<string,string|null>|null
	 */
	private function price_payload( Account $account, \WC_Product $product, Link_Record $link ): ?array {
		try {
			$pair = $this->contexts->price_pair( $account, $product, $link );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}

		return null === $pair ? null : $this->contexts->payload( $pair );
	}
}

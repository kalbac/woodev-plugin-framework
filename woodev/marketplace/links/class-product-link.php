<?php
/**
 * Product link value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Links;

defined( 'ABSPATH' ) || exit;

/**
 * The tie between one WooCommerce product (or variation) and one marketplace card, per account.
 *
 * Only `seller_sku` is shared by every marketplace; the marketplace's own identifiers live in the `remote_ids` bag
 * (Ozon: `product_id`, `sku`, `model_id`; Wildberries: `nmID`, `imtID`, `chrtID`). A new card has no remote ids yet and
 * sits in `STATE_PENDING_REMOTE_IDS`, which Wildberries needs for its 30-minute card quarantine.
 *
 * @since 2.0.29
 */
final class Product_Link {
	public const STATE_UNLINKED           = 'unlinked';
	public const STATE_PENDING_REMOTE_IDS = 'pending_remote_ids';
	public const STATE_CREATED            = 'created';

	/**
	 * Local id, null before the link is stored.
	 *
	 * @var int|null
	 */
	private $link_id;

	/**
	 * Account id.
	 *
	 * @var int
	 */
	private $account_id;

	/**
	 * WooCommerce product or variation id.
	 *
	 * @var int
	 */
	private $wc_product_id;

	/**
	 * Parent product id, 0 for a simple product.
	 *
	 * @var int
	 */
	private $wc_parent_id;

	/**
	 * Seller article.
	 *
	 * @var string
	 */
	private $seller_sku;

	/**
	 * Marketplace identifiers.
	 *
	 * @var array<string,int|string>
	 */
	private $remote_ids;

	/**
	 * Card state; adapters may add states of their own.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Per-link switches (`sync_stock`, `sync_price`, ...).
	 *
	 * @var array<string,bool>
	 */
	private $flags;

	/**
	 * Warehouse override, null for the account default.
	 *
	 * @var int|null
	 */
	private $warehouse_id;

	/**
	 * Creates a link.
	 *
	 * @since 2.0.29
	 *
	 * @param int                      $account_id    Account id.
	 * @param int                      $wc_product_id WooCommerce product or variation id.
	 * @param int                      $wc_parent_id  Parent id, 0 for a simple product.
	 * @param string                   $seller_sku    Seller article.
	 * @param array<string,int|string> $remote_ids    Marketplace identifiers.
	 * @param string                   $state         Card state.
	 * @param array<string,bool>       $flags         Per-link switches.
	 * @param int|null                 $warehouse_id  Warehouse override.
	 * @param int|null                 $link_id       Local id once stored.
	 */
	public function __construct( int $account_id, int $wc_product_id, int $wc_parent_id, string $seller_sku, array $remote_ids = [], string $state = self::STATE_UNLINKED, array $flags = [], ?int $warehouse_id = null, ?int $link_id = null ) {
		$this->link_id       = $link_id;
		$this->account_id    = $account_id;
		$this->wc_product_id = $wc_product_id;
		$this->wc_parent_id  = $wc_parent_id;
		$this->seller_sku    = $seller_sku;
		$this->remote_ids    = $remote_ids;
		$this->state         = $state;
		$this->flags         = $flags;
		$this->warehouse_id  = $warehouse_id;
	}

	/**
	 * Returns a copy in another state.
	 *
	 * @since 2.0.29
	 *
	 * @param string $state New state.
	 * @return self
	 */
	public function with_state( string $state ): self {
		return new self( $this->account_id, $this->wc_product_id, $this->wc_parent_id, $this->seller_sku, $this->remote_ids, $state, $this->flags, $this->warehouse_id, $this->link_id );
	}

	/**
	 * Returns a copy with remote identifiers merged over the current ones.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,int|string> $remote_ids Identifiers to add or replace.
	 * @return self
	 */
	public function with_remote_ids( array $remote_ids ): self {
		return new self( $this->account_id, $this->wc_product_id, $this->wc_parent_id, $this->seller_sku, array_merge( $this->remote_ids, $remote_ids ), $this->state, $this->flags, $this->warehouse_id, $this->link_id );
	}

	/**
	 * Returns a copy that carries the stored id.
	 *
	 * @since 2.0.29
	 *
	 * @param int $link_id Local id.
	 * @return self
	 */
	public function with_link_id( int $link_id ): self {
		return new self( $this->account_id, $this->wc_product_id, $this->wc_parent_id, $this->seller_sku, $this->remote_ids, $this->state, $this->flags, $this->warehouse_id, $link_id );
	}

	/**
	 * Tells whether the marketplace has finished creating the card, so stock and price may be pushed.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_created(): bool {
		return self::STATE_CREATED === $this->state;
	}

	/**
	 * Reads one remote identifier.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key Identifier name.
	 * @return int|string|null Null when the marketplace has not assigned it.
	 */
	public function remote_id( string $key ) {
		return $this->remote_ids[ $key ] ?? null;
	}

	/**
	 * Reads one per-link switch.
	 *
	 * @since 2.0.29
	 *
	 * @param string $name    Switch name.
	 * @param bool   $fallback Value when the switch is not set.
	 * @return bool
	 */
	public function flag( string $name, bool $fallback = false ): bool {
		return $this->flags[ $name ] ?? $fallback;
	}

	/**
	 * Returns the local id, or null before the link is stored.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function link_id(): ?int {
		return $this->link_id;
	}

	/**
	 * Returns the account id.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function account_id(): int {
		return $this->account_id;
	}

	/**
	 * Returns the WooCommerce product or variation id.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function wc_product_id(): int {
		return $this->wc_product_id;
	}

	/**
	 * Returns the parent product id, 0 for a simple product.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function wc_parent_id(): int {
		return $this->wc_parent_id;
	}

	/**
	 * Returns the seller article.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function seller_sku(): string {
		return $this->seller_sku;
	}

	/**
	 * Returns every remote identifier.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,int|string>
	 */
	public function remote_ids(): array {
		return $this->remote_ids;
	}

	/**
	 * Returns the card state.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Returns the warehouse override, or null for the account default.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function warehouse_id(): ?int {
		return $this->warehouse_id;
	}
}

<?php
/**
 * One row of the `links` table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The whole link row, as the migration, the card builder and the validator read it.
 *
 * The marketplace layer's `Product_Link` carries only what every marketplace shares; this value adds the columns only the store
 * needs (the three remote-id slots, the two per-link overrides, the mapping). The slots are `product_id`, `sku` and `model_id` of
 * the link value; an adapter maps its own identifiers onto them. A row is one of two kinds:
 *
 * - `offer`: a sellable marketplace card (a simple product or a variation) with an `offer_id` as long as the adapter's article
 *   policy allows;
 * - `parent`: the configuration row of a variable product. It has **no** `offer_id` (NULL: the unique index allows many), never
 *   exports and never pushes stock or price; its variations read its overrides and mapping through the builder precedence.
 *
 * Built through {@see self::offer()} / {@see self::parent_row()}; every `with_*` returns a copy.
 */
final class Link_Record {
	public const KIND_OFFER  = 'offer';
	public const KIND_PARENT = 'parent';

	/**
	 * Card states.
	 *
	 * @var string
	 */
	public const STATE_UNLINKED             = 'unlinked';
	public const STATE_QUEUED               = 'queued';
	public const STATE_SUBMITTED            = 'submitted';
	public const STATE_IMPORTED_WITH_ERRORS = 'imported_with_errors';
	public const STATE_OFFER_VALIDATED      = 'offer_validated';
	public const STATE_PICS_DELIVERED       = 'pics_delivered';
	public const STATE_SKU_CREATED          = 'sku_created';
	public const STATE_PRICE_SENT           = 'price_sent';
	public const STATE_CREATED              = 'created';
	public const STATE_ARCHIVED             = 'archived';
	public const STATE_FAILED               = 'failed';

	/**
	 * States between a successful import and `created`: the card exists on the marketplace but cannot sell yet.
	 *
	 * @var string[]
	 */
	public const LIFECYCLE_STATES = [ self::STATE_OFFER_VALIDATED, self::STATE_PICS_DELIVERED, self::STATE_SKU_CREATED, self::STATE_PRICE_SENT ];

	/**
	 * States in which the article has already reached the marketplace and must not change any more.
	 *
	 * @var string[]
	 */
	private const SENT_STATES = [
		self::STATE_SUBMITTED,
		self::STATE_IMPORTED_WITH_ERRORS,
		self::STATE_OFFER_VALIDATED,
		self::STATE_PICS_DELIVERED,
		self::STATE_SKU_CREATED,
		self::STATE_PRICE_SENT,
		self::STATE_CREATED,
		self::STATE_ARCHIVED,
	];

	/**
	 * Column => value.
	 *
	 * @var array<string,mixed>
	 */
	private $fields;

	/**
	 * Use {@see self::offer()}, {@see self::parent_row()} or {@see self::from_row()}.
	 *
	 * @param array<string,mixed> $fields Normalised columns.
	 */
	private function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Builds an offer row.
	 *
	 * @param int                 $account_id    Account id.
	 * @param int                 $wc_product_id Product or variation id.
	 * @param int                 $wc_parent_id  Parent product id, 0 for a simple product.
	 * @param string              $offer_id      Seller article.
	 * @param array<string,mixed> $columns       Any other column of the table, by name.
	 * @return self
	 */
	public static function offer( int $account_id, int $wc_product_id, int $wc_parent_id, string $offer_id, array $columns = [] ): self {
		return self::from_row(
			array_merge(
				$columns,
				[
					'account_id'    => $account_id,
					'wc_product_id' => $wc_product_id,
					'wc_parent_id'  => $wc_parent_id,
					'kind'          => self::KIND_OFFER,
					'offer_id'      => $offer_id,
				]
			)
		);
	}

	/**
	 * Builds a parent (configuration) row; its `offer_id` is NULL.
	 *
	 * @param int                 $account_id    Account id.
	 * @param int                 $wc_product_id Variable product id.
	 * @param array<string,mixed> $columns       Any other column of the table, by name.
	 * @return self
	 */
	public static function parent_row( int $account_id, int $wc_product_id, array $columns = [] ): self {
		return self::from_row(
			array_merge(
				$columns,
				[
					'account_id'    => $account_id,
					'wc_product_id' => $wc_product_id,
					'wc_parent_id'  => 0,
					'kind'          => self::KIND_PARENT,
					'offer_id'      => null,
				]
			)
		);
	}

	/**
	 * Builds a record from table columns (database strings or typed values).
	 *
	 * @param array<string,mixed> $row Columns.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$offer_id = $row['offer_id'] ?? null;

		return new self(
			[
				'link_id'             => isset( $row['link_id'] ) ? (int) $row['link_id'] : null,
				'account_id'          => (int) ( $row['account_id'] ?? 0 ),
				'wc_product_id'       => (int) ( $row['wc_product_id'] ?? 0 ),
				'wc_parent_id'        => (int) ( $row['wc_parent_id'] ?? 0 ),
				'kind'                => self::KIND_PARENT === ( $row['kind'] ?? self::KIND_OFFER ) ? self::KIND_PARENT : self::KIND_OFFER,
				'offer_id'            => null === $offer_id || '' === $offer_id ? null : (string) $offer_id,
				'remote_product_id'     => (int) ( $row['remote_product_id'] ?? 0 ),
				'remote_sku'            => (int) ( $row['remote_sku'] ?? 0 ),
				'remote_model_id'            => (int) ( $row['remote_model_id'] ?? 0 ),
				'card_state'          => (string) ( $row['card_state'] ?? self::STATE_UNLINKED ),
				'is_created'          => (int) ( $row['is_created'] ?? 0 ),
				'sync_stock'          => (int) ( $row['sync_stock'] ?? 0 ),
				'sync_price'          => (int) ( $row['sync_price'] ?? 0 ),
				'warehouse_id'        => self::nullable_int( $row['warehouse_id'] ?? null ),
				'is_oversize'         => (int) ( $row['is_oversize'] ?? 0 ),
				'price_rule_override' => self::decoded( $row['price_rule_override'] ?? null ),
				'attribute_overrides' => self::decoded( $row['attribute_overrides'] ?? null ),
				'mapping_id'          => self::nullable_int( $row['mapping_id'] ?? null ),
			]
		);
	}

	/**
	 * Returns a copy with some columns replaced.
	 *
	 * @param array<string,mixed> $columns Column => value.
	 * @return self
	 */
	public function with( array $columns ): self {
		return self::from_row( array_merge( $this->fields, $columns ) );
	}

	/**
	 * Returns the columns as the database stores them (JSON columns encoded, NULLs kept), without `link_id`.
	 *
	 * @return array<string,mixed>
	 */
	public function to_columns(): array {
		$columns = $this->fields;
		unset( $columns['link_id'] );

		foreach ( [ 'price_rule_override', 'attribute_overrides' ] as $json ) {
			$columns[ $json ] = null === $columns[ $json ] ? null : (string) wp_json_encode( $columns[ $json ] );
		}

		return $columns;
	}

	/**
	 * Link id, null before the row is stored.
	 *
	 * @return int|null
	 */
	public function link_id(): ?int {
		return $this->fields['link_id'];
	}

	/**
	 * Account id.
	 *
	 * @return int
	 */
	public function account_id(): int {
		return $this->fields['account_id'];
	}

	/**
	 * Product or variation id.
	 *
	 * @return int
	 */
	public function wc_product_id(): int {
		return $this->fields['wc_product_id'];
	}

	/**
	 * Parent product id, 0 for a simple product and for a parent row.
	 *
	 * @return int
	 */
	public function wc_parent_id(): int {
		return $this->fields['wc_parent_id'];
	}

	/**
	 * Tells whether this is a sellable card.
	 *
	 * @return bool
	 */
	public function is_offer(): bool {
		return self::KIND_OFFER === $this->fields['kind'];
	}

	/**
	 * Seller article; null for a parent row.
	 *
	 * @return string|null
	 */
	public function offer_id(): ?string {
		return $this->fields['offer_id'];
	}

	/**
	 * Returns the article once it is frozen: the marketplace knows the card (a product id) or a submit has carried it. Before that
	 * the article follows the WooCommerce SKU.
	 *
	 * @return string|null
	 */
	public function frozen_offer_id(): ?string {
		$sent = $this->remote_product_id() > 0 || in_array( $this->card_state(), self::SENT_STATES, true );

		return $sent ? $this->offer_id() : null;
	}

	/**
	 * Remote product id, 0 until known.
	 *
	 * @return int
	 */
	public function remote_product_id(): int {
		return $this->fields['remote_product_id'];
	}

	/**
	 * Remote SKU, 0 until the card is created.
	 *
	 * @return int
	 */
	public function remote_sku(): int {
		return $this->fields['remote_sku'];
	}

	/**
	 * Remote model id, 0 until the card is created.
	 *
	 * @return int
	 */
	public function remote_model_id(): int {
		return $this->fields['remote_model_id'];
	}

	/**
	 * Card state.
	 *
	 * @return string
	 */
	public function card_state(): string {
		return $this->fields['card_state'];
	}

	/**
	 * Per-product stock switch.
	 *
	 * @return bool
	 */
	public function sync_stock(): bool {
		return 1 === $this->fields['sync_stock'];
	}

	/**
	 * Per-product price switch.
	 *
	 * @return bool
	 */
	public function sync_price(): bool {
		return 1 === $this->fields['sync_price'];
	}

	/**
	 * Per-product warehouse override.
	 *
	 * @return int|null
	 */
	public function warehouse_id(): ?int {
		return $this->fields['warehouse_id'];
	}

	/**
	 * Oversize flag.
	 *
	 * @return bool
	 */
	public function is_oversize(): bool {
		return 1 === $this->fields['is_oversize'];
	}

	/**
	 * Per-link price rule override, field by field, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function price_rule_override(): ?array {
		return $this->fields['price_rule_override'];
	}

	/**
	 * Per-link remote attribute values: `attribute_id => [ { dictionary_value_id, value } ]`, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function attribute_overrides(): ?array {
		return $this->fields['attribute_overrides'];
	}

	/**
	 * Explicit mapping id, or null to resolve from the product's categories.
	 *
	 * @return int|null
	 */
	public function mapping_id(): ?int {
		return $this->fields['mapping_id'];
	}

	/**
	 * Converts a nullable integer column; 0 and '' are NULL (a warehouse or mapping id of 0 means «none»).
	 *
	 * @param mixed $value Column value.
	 * @return int|null
	 */
	private static function nullable_int( $value ): ?int {
		return null === $value || '' === $value || 0 === (int) $value ? null : (int) $value;
	}

	/**
	 * Decodes a JSON column; an empty array is NULL so «no override» has one shape.
	 *
	 * @param mixed $value Column value (JSON string or already decoded).
	 * @return array<string,mixed>|null
	 */
	private static function decoded( $value ): ?array {
		if ( is_string( $value ) ) {
			$value = '' === $value ? null : json_decode( $value, true );
		}

		return is_array( $value ) && [] !== $value ? $value : null;
	}
}

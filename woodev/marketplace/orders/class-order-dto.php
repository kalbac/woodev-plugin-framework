<?php
/**
 * Normalised remote order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

use Woodev\Framework\Marketplace\Price\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A remote order in the layer's vocabulary: the same shape for an Ozon posting and a Wildberries assembly order.
 *
 * `group_key` says which WooCommerce order the DTO belongs to. For Ozon it is the `external_id` (one posting, one
 * order, many lines). For Wildberries it is the cart's `orderUid`: every assembly order (one item unit each) shares the
 * key and is one member of one WooCommerce order.
 *
 * Money is `Money` everywhere; no float is ever used for an amount.
 *
 * @since 2.0.29
 */
final class Order_DTO {
	/**
	 * Account id.
	 *
	 * @var int
	 */
	private $account_id;

	/**
	 * Marketplace order id (Ozon `posting_number`; Wildberries assembly order `id`).
	 *
	 * @var string
	 */
	private $external_id;

	/**
	 * Key of the WooCommerce order this DTO belongs to.
	 *
	 * @var string
	 */
	private $group_key;

	/**
	 * Marketplace status, verbatim.
	 *
	 * @var string
	 */
	private $status_raw;

	/**
	 * One of `Status_Map::canonical()`.
	 *
	 * @var string
	 */
	private $status_canonical;

	/**
	 * Lines.
	 *
	 * @var Order_Line[]
	 */
	private $lines;

	/**
	 * Labelled totals (`items`, `shipping`, `total`).
	 *
	 * @var array<string,Money>
	 */
	private $totals;

	/**
	 * Buyer fields when the marketplace shows them.
	 *
	 * @var array<string,mixed>|null
	 */
	private $buyer;

	/**
	 * Dates (`created_at`, `updated_at`, ...) as ISO 8601 strings with an explicit zone (`Z` or an offset).
	 *
	 * @var array<string,string>
	 */
	private $dates;

	/**
	 * Shipment data (dates, tracking number, integration type).
	 *
	 * @var array<string,mixed>
	 */
	private $shipment;

	/**
	 * The marketplace's untouched answer, kept for diagnostics and financial data.
	 *
	 * @var array<string,mixed>
	 */
	private $raw;

	/**
	 * Creates an order DTO.
	 *
	 * @since 2.0.29
	 *
	 * @param int                      $account_id       Account id.
	 * @param string                   $external_id      Marketplace order id, not empty.
	 * @param string                   $group_key        Group key; an empty string means the `external_id`.
	 * @param string                   $status_raw       Marketplace status.
	 * @param string                   $status_canonical Canonical status.
	 * @param Order_Line[]             $lines            Lines.
	 * @param array<string,Money>      $totals           Labelled totals.
	 * @param array<string,mixed>|null $buyer            Buyer fields.
	 * @param array<string,string>     $dates            ISO 8601 dates in UTC.
	 * @param array<string,mixed>      $shipment         Shipment data.
	 * @param array<string,mixed>      $raw              Untouched marketplace answer.
	 * @throws \InvalidArgumentException When the external id is empty, the canonical status is unknown or `updated_at` is not a time.
	 */
	public function __construct(
		int $account_id,
		string $external_id,
		string $group_key,
		string $status_raw,
		string $status_canonical,
		array $lines,
		array $totals = [],
		?array $buyer = null,
		array $dates = [],
		array $shipment = [],
		array $raw = []
	) {
		if ( '' === $external_id ) {
			throw new \InvalidArgumentException( 'An order needs an external id.' );
		}

		if ( ! Status_Map::is_canonical( $status_canonical ) ) {
			throw new \InvalidArgumentException( 'Unknown canonical order status.' );
		}

		$updated_at = $dates['updated_at'] ?? '';
		if ( '' !== $updated_at && null === self::parse_instant( $updated_at ) ) {
			throw new \InvalidArgumentException( 'The updated_at date is not a valid ISO 8601 time.' );
		}

		$this->account_id       = $account_id;
		$this->external_id      = $external_id;
		$this->group_key        = '' === $group_key ? $external_id : $group_key;
		$this->status_raw       = $status_raw;
		$this->status_canonical = $status_canonical;
		$this->lines            = $lines;
		$this->totals           = $totals;
		$this->buyer            = $buyer;
		$this->dates            = $dates;
		$this->shipment         = $shipment;
		$this->raw              = $raw;
	}

	/**
	 * Tells whether the order is cancelled.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_cancelled(): bool {
		return Status_Map::CANCELLED === $this->status_canonical;
	}

	/**
	 * Returns the units on all lines.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function quantity(): int {
		$units = 0;
		foreach ( $this->lines as $line ) {
			$units += $line->quantity();
		}

		return $units;
	}

	/**
	 * Returns the time of the marketplace's last change as the ISO 8601 text it sent, or null when it did not say.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function updated_at(): ?string {
		return $this->dates['updated_at'] ?? null;
	}

	/**
	 * Returns the time of the marketplace's last change as an instant, so offsets, `Z` and fractions compare correctly.
	 *
	 * @since 2.0.29
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function updated_at_instant(): ?\DateTimeImmutable {
		return isset( $this->dates['updated_at'] ) ? self::parse_instant( $this->dates['updated_at'] ) : null;
	}

	/**
	 * Parses an ISO 8601 time.
	 *
	 * @param string $value Time text.
	 * @return \DateTimeImmutable|null Null when the text is not a time with an explicit zone (`Z` or an offset).
	 */
	private static function parse_instant( string $value ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
			return null;
		}

		try {
			return new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}
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
	 * Returns the marketplace order id.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function external_id(): string {
		return $this->external_id;
	}

	/**
	 * Returns the group key.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function group_key(): string {
		return $this->group_key;
	}

	/**
	 * Returns the marketplace status, verbatim.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function status_raw(): string {
		return $this->status_raw;
	}

	/**
	 * Returns the canonical status.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function status_canonical(): string {
		return $this->status_canonical;
	}

	/**
	 * Returns the lines.
	 *
	 * @since 2.0.29
	 *
	 * @return Order_Line[]
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * Returns the labelled totals.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,Money>
	 */
	public function totals(): array {
		return $this->totals;
	}

	/**
	 * Returns the buyer fields, or null when the marketplace hides them.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>|null
	 */
	public function buyer(): ?array {
		return $this->buyer;
	}

	/**
	 * Returns the dates.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,string>
	 */
	public function dates(): array {
		return $this->dates;
	}

	/**
	 * Returns the shipment data.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function shipment(): array {
		return $this->shipment;
	}

	/**
	 * Returns the untouched marketplace answer.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function raw(): array {
		return $this->raw;
	}
}

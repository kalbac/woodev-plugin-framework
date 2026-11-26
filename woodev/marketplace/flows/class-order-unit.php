<?php
/**
 * One unit of a grouped marketplace order, as the shop's order keeps it.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Price\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A member of a grouped order (Wildberries: one assembly order, one piece of goods): its goods, price and the marketplace's
 * status. A cancelled unit has no line item in the order, so this record is all the order keeps of it — taken when the unit was
 * first seen cancelled and never rewritten ({@see Order_Units}).
 *
 * @since 2.1.28
 */
final class Order_Unit {
	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $status_raw;

	/**
	 * @var string
	 */
	private $cancelled_at;

	/**
	 * @var array<int,array{product_id:int,offer_id:string,name:string,quantity:int,unit_minor:int,currency:string}>
	 */
	private $lines;

	/**
	 * Describes a unit.
	 *
	 * @since 2.1.28
	 *
	 * @param string                         $id           The marketplace's id of the unit (the member's external id); never shown
	 *                                                     to the merchant.
	 * @param string                         $status_raw   The marketplace's status, verbatim.
	 * @param string                         $cancelled_at When the unit was cancelled, `Y-m-d\TH:i:s\Z`; '' for a live unit.
	 * @param array<int,array<string,mixed>> $lines Its goods, each `product_id` (0 for a line no shop product was found for),
	 *                                              `offer_id` (the seller article key), `name`, `quantity`, `unit_minor` and `currency`.
	 */
	public function __construct( string $id, string $status_raw, string $cancelled_at, array $lines ) {
		$this->id           = $id;
		$this->status_raw   = $status_raw;
		$this->cancelled_at = $cancelled_at;
		$this->lines        = array_values( $lines );
	}

	/**
	 * Reads a unit from the array {@see self::to_array()} stores.
	 *
	 * @since 2.1.28
	 *
	 * @param mixed $stored Stored value.
	 * @return self|null Null for a value that is not a unit.
	 */
	public static function from_array( $stored ): ?self {
		if ( ! is_array( $stored ) || '' === (string) ( $stored['id'] ?? '' ) ) {
			return null;
		}

		$lines = [];

		foreach ( (array) ( $stored['lines'] ?? [] ) as $line ) {
			$line    = (array) $line;
			$lines[] = [
				'product_id' => (int) ( $line['product_id'] ?? 0 ),
				'offer_id'   => (string) ( $line['offer_id'] ?? '' ),
				'name'       => (string) ( $line['name'] ?? '' ),
				'quantity'   => (int) ( $line['quantity'] ?? 0 ),
				'unit_minor' => (int) ( $line['unit_minor'] ?? 0 ),
				'currency'   => (string) ( $line['currency'] ?? '' ),
			];
		}

		return new self( (string) $stored['id'], (string) ( $stored['status_raw'] ?? '' ), (string) ( $stored['cancelled_at'] ?? '' ), $lines );
	}

	/**
	 * Returns the unit as order meta holds it.
	 *
	 * @since 2.1.28
	 *
	 * @return array{id:string,status_raw:string,cancelled_at:string,lines:array<int,array<string,mixed>>}
	 */
	public function to_array(): array {
		return [
			'id'           => $this->id,
			'status_raw'   => $this->status_raw,
			'cancelled_at' => $this->cancelled_at,
			'lines'        => $this->lines,
		];
	}

	/**
	 * Returns the marketplace's id of the unit.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the marketplace's status.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function status_raw(): string {
		return $this->status_raw;
	}

	/**
	 * Tells whether the unit is cancelled.
	 *
	 * @since 2.1.28
	 *
	 * @return bool
	 */
	public function is_cancelled(): bool {
		return '' !== $this->cancelled_at;
	}

	/**
	 * Returns when the unit was cancelled.
	 *
	 * @since 2.1.28
	 *
	 * @return string `Y-m-d\TH:i:s\Z`; '' for a live unit.
	 */
	public function cancelled_at(): string {
		return $this->cancelled_at;
	}

	/**
	 * Returns the goods of the unit.
	 *
	 * @since 2.1.28
	 *
	 * @return array<int,array{product_id:int,offer_id:string,name:string,quantity:int,unit_minor:int,currency:string}>
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * Returns what the unit costs: the sum of its lines.
	 *
	 * @since 2.1.28
	 *
	 * @return Money|null Null for a unit without a priced line.
	 */
	public function total(): ?Money {
		$total = null;

		foreach ( $this->lines as $line ) {
			if ( '' === $line['currency'] ) {
				continue;
			}

			$sum   = Money::of( $line['unit_minor'], $line['currency'] )->mul( $line['quantity'] );
			$total = null === $total ? $sum : $total->add( $sum );
		}

		return $total;
	}
}

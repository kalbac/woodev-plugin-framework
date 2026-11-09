<?php
/**
 * The stock formulas.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Which number goes to the marketplace when the shop's stock is the truth, and which number the shop takes when the
 * marketplace's is.
 *
 * Which pushed formula is in force is the marketplace's decision, not the layer's: one marketplace keeps the reserved unit out of
 * the shop's stock until shipment and needs «WC stock + the reservation the marketplace last reported» (formula A), another is
 * served by plain WC stock (formula B). The layer ships both and the adapter names the one in force.
 *
 * @since 2.0.29
 */
final class Stock_Policy {
	/**
	 * Formula A: WC stock plus the last known reservation.
	 */
	public const FORMULA_PLUS_RESERVED = 'wc_plus_reserved';

	/**
	 * Formula B: plain WC stock.
	 */
	public const FORMULA_WC_ONLY = 'wc_only';

	/**
	 * Formula used by this instance.
	 *
	 * @var string
	 */
	private $formula;

	/**
	 * Creates the policy.
	 *
	 * @since 2.0.29
	 *
	 * @param string $formula One of the `FORMULA_*` constants, the one the adapter says is in force.
	 * @throws \InvalidArgumentException For an unknown formula.
	 */
	public function __construct( string $formula ) {
		if ( ! in_array( $formula, [ self::FORMULA_PLUS_RESERVED, self::FORMULA_WC_ONLY ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown stock formula: ' . $formula ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->formula = $formula;
	}

	/**
	 * Returns the formula of this instance.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function formula(): string {
		return $this->formula;
	}

	/**
	 * The quantity to set on the marketplace when the shop's stock is the truth.
	 *
	 * @since 2.0.29
	 *
	 * @param int      $wc_stock        WooCommerce stock quantity (a negative backorder count sends 0).
	 * @param int|null $remote_reserved Reservation the marketplace last reported; null when never read (counts as 0).
	 * @return int
	 */
	public function pushed_quantity( int $wc_stock, ?int $remote_reserved ): int {
		$reserved = self::FORMULA_PLUS_RESERVED === $this->formula ? max( 0, (int) $remote_reserved ) : 0;

		return max( 0, $wc_stock ) + $reserved;
	}

	/**
	 * The shop's stock quantity when the marketplace's stock is the truth: what the marketplace can still sell.
	 *
	 * @since 2.0.29
	 *
	 * @param int $present  Units on the marketplace warehouse.
	 * @param int $reserved Units reserved by its open postings.
	 * @return int
	 */
	public static function mirrored_quantity( int $present, int $reserved ): int {
		return max( 0, $present - $reserved );
	}
}

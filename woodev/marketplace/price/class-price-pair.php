<?php
/**
 * Calculated price triple.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * The result of `Price_Calculator::calculate()`: the price to sell at, the old (crossed-out) price and the minimum price.
 *
 * A null old price means «no old price» (Ozon clears it with 0); a null minimum price means «send no minimum».
 *
 * @since 2.0.29
 */
final class Price_Pair {
	/**
	 * Selling price.
	 *
	 * @var Money
	 */
	private $price;

	/**
	 * Old price, or null.
	 *
	 * @var Money|null
	 */
	private $old_price;

	/**
	 * Minimum price, or null.
	 *
	 * @var Money|null
	 */
	private $min_price;

	/**
	 * Creates the pair.
	 *
	 * @since 2.0.29
	 *
	 * @param Money      $price     Selling price.
	 * @param Money|null $old_price Old price, above the selling price, or null.
	 * @param Money|null $min_price Minimum price, or null.
	 */
	public function __construct( Money $price, ?Money $old_price = null, ?Money $min_price = null ) {
		$this->price     = $price;
		$this->old_price = $old_price;
		$this->min_price = $min_price;
	}

	/**
	 * Returns the selling price.
	 *
	 * @since 2.0.29
	 *
	 * @return Money
	 */
	public function price(): Money {
		return $this->price;
	}

	/**
	 * Returns the old price, or null when none is sent.
	 *
	 * @since 2.0.29
	 *
	 * @return Money|null
	 */
	public function old_price(): ?Money {
		return $this->old_price;
	}

	/**
	 * Returns the minimum price, or null when none is sent.
	 *
	 * @since 2.0.29
	 *
	 * @return Money|null
	 */
	public function min_price(): ?Money {
		return $this->min_price;
	}

	/**
	 * Tells whether another pair carries exactly the same three values.
	 *
	 * @since 2.0.29
	 *
	 * @param Price_Pair $other Pair to compare with.
	 * @return bool
	 */
	public function equals( Price_Pair $other ): bool {
		return $this->price->equals( $other->price )
			&& self::same( $this->old_price, $other->old_price )
			&& self::same( $this->min_price, $other->min_price );
	}

	/**
	 * Compares two optional amounts.
	 *
	 * @param Money|null $left  First amount.
	 * @param Money|null $right Second amount.
	 * @return bool
	 */
	private static function same( ?Money $left, ?Money $right ): bool {
		if ( null === $left || null === $right ) {
			return $left === $right;
		}

		return $left->equals( $right );
	}
}

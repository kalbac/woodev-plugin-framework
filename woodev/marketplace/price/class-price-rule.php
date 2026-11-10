<?php
/**
 * Pricing policy value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant's pricing policy for one account, profile or link. Pure data; `Price_Calculator` applies it.
 *
 * - `base`: which WooCommerce price the policy starts from. `active` is the price a shopper pays now; `regular` ignores
 *   sales («игнорировать распродажу»).
 * - `markup_kind` / `markup`: `none`; `fixed` adds an amount in major units («150» or «150.50»); `percent` adds a
 *   percentage («20», «12.5», may be negative but stays above -100).
 * - `rounding`: `none`, `to_1` (whole units), `to_10` (tens) or `ends_99` (the next whole-unit price ending in 99).
 * - `old_price_rule`: `on_sale` shows the marked-up regular price as the old price while the product is on sale;
 *   `off` never sends an old price.
 * - `min_percent`: minimum price as a percentage of the price, above 0 and at most 100; null sends no minimum price.
 *
 * @since 2.0.29
 */
final class Price_Rule {
	public const BASE_ACTIVE  = 'active';
	public const BASE_REGULAR = 'regular';

	public const MARKUP_NONE    = 'none';
	public const MARKUP_FIXED   = 'fixed';
	public const MARKUP_PERCENT = 'percent';

	public const ROUND_NONE    = 'none';
	public const ROUND_TO_1    = 'to_1';
	public const ROUND_TO_10   = 'to_10';
	public const ROUND_ENDS_99 = 'ends_99';

	public const OLD_PRICE_ON_SALE = 'on_sale';
	public const OLD_PRICE_OFF     = 'off';

	/**
	 * Base price choice.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Markup kind.
	 *
	 * @var string
	 */
	private $markup_kind;

	/**
	 * Markup value as a decimal string.
	 *
	 * @var string
	 */
	private $markup;

	/**
	 * Rounding mode.
	 *
	 * @var string
	 */
	private $rounding;

	/**
	 * Old price rule.
	 *
	 * @var string
	 */
	private $old_price_rule;

	/**
	 * Minimum price percentage, or null.
	 *
	 * @var string|null
	 */
	private $min_percent;

	/**
	 * Creates a validated rule.
	 *
	 * @since 2.0.29
	 *
	 * @param string      $base           `BASE_*`.
	 * @param string      $markup_kind    `MARKUP_*`.
	 * @param string      $markup         Decimal string; ignored for `none`.
	 * @param string      $rounding       `ROUND_*`.
	 * @param string      $old_price_rule `OLD_PRICE_*`.
	 * @param string|null $min_percent    Decimal percentage or null.
	 * @throws \InvalidArgumentException When a value is outside its set or a number is malformed.
	 */
	public function __construct(
		string $base = self::BASE_ACTIVE,
		string $markup_kind = self::MARKUP_NONE,
		string $markup = '0',
		string $rounding = self::ROUND_NONE,
		string $old_price_rule = self::OLD_PRICE_ON_SALE,
		?string $min_percent = null
	) {
		self::assert_in( $base, [ self::BASE_ACTIVE, self::BASE_REGULAR ], 'base' );
		self::assert_in( $markup_kind, [ self::MARKUP_NONE, self::MARKUP_FIXED, self::MARKUP_PERCENT ], 'markup kind' );
		self::assert_in( $rounding, [ self::ROUND_NONE, self::ROUND_TO_1, self::ROUND_TO_10, self::ROUND_ENDS_99 ], 'rounding' );
		self::assert_in( $old_price_rule, [ self::OLD_PRICE_ON_SALE, self::OLD_PRICE_OFF ], 'old price rule' );
		$this->assert_markup( $markup_kind, $markup );
		$this->assert_min_percent( $min_percent );

		$this->base           = $base;
		$this->markup_kind    = $markup_kind;
		$this->markup         = $markup;
		$this->rounding       = $rounding;
		$this->old_price_rule = $old_price_rule;
		$this->min_percent    = $min_percent;
	}

	/**
	 * Builds a rule from stored settings (account / profile JSON); missing keys take the defaults.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $settings Keys `base`, `markup_kind`, `markup`, `rounding`, `old_price_rule`, `min_percent`.
	 * @return self
	 * @throws \InvalidArgumentException On an invalid value.
	 */
	public static function from_array( array $settings ): self {
		$min_percent = $settings['min_percent'] ?? null;

		return new self(
			(string) ( $settings['base'] ?? self::BASE_ACTIVE ),
			(string) ( $settings['markup_kind'] ?? self::MARKUP_NONE ),
			(string) ( $settings['markup'] ?? '0' ),
			(string) ( $settings['rounding'] ?? self::ROUND_NONE ),
			(string) ( $settings['old_price_rule'] ?? self::OLD_PRICE_ON_SALE ),
			null === $min_percent || '' === $min_percent ? null : (string) $min_percent
		);
	}

	/**
	 * Returns the base price choice.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function base(): string {
		return $this->base;
	}

	/**
	 * Returns the markup kind.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function markup_kind(): string {
		return $this->markup_kind;
	}

	/**
	 * Returns the markup as a decimal string.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function markup(): string {
		return $this->markup;
	}

	/**
	 * Returns the rounding mode.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function rounding(): string {
		return $this->rounding;
	}

	/**
	 * Returns the old price rule.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function old_price_rule(): string {
		return $this->old_price_rule;
	}

	/**
	 * Returns the minimum price percentage, or null when no minimum price is sent.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function min_percent(): ?string {
		return $this->min_percent;
	}

	/**
	 * Throws when a value is not in its allowed set.
	 *
	 * @param string   $value   Value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $label   Field label for the message.
	 * @return void
	 * @throws \InvalidArgumentException When the value is not allowed.
	 */
	private static function assert_in( string $value, array $allowed, string $label ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Unknown price rule ' . $label . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Validates the markup value for its kind.
	 *
	 * @param string $kind   Markup kind.
	 * @param string $markup Markup value.
	 * @return void
	 * @throws \InvalidArgumentException When the value is malformed or out of range.
	 */
	private function assert_markup( string $kind, string $markup ): void {
		if ( self::MARKUP_FIXED === $kind && 1 !== preg_match( '/^\d{1,12}(?:[.,]\d{1,2})?$/', $markup ) ) {
			throw new \InvalidArgumentException( 'A fixed markup is a non-negative amount with at most two decimals.' );
		}

		if ( self::MARKUP_PERCENT === $kind ) {
			list( $numerator, $denominator ) = Money::percent_ratio( $markup );
			if ( $numerator + $denominator <= 0 ) {
				throw new \InvalidArgumentException( 'A percent markup must stay above -100.' );
			}
		}
	}

	/**
	 * Validates the minimum price percentage.
	 *
	 * @param string|null $min_percent Percentage or null.
	 * @return void
	 * @throws \InvalidArgumentException When it is not in (0, 100].
	 */
	private function assert_min_percent( ?string $min_percent ): void {
		if ( null === $min_percent ) {
			return;
		}

		list( $numerator, $denominator ) = Money::percent_ratio( $min_percent );
		if ( $numerator <= 0 || $numerator > $denominator ) {
			throw new \InvalidArgumentException( 'Minimum price percentage must be above 0 and at most 100.' );
		}
	}
}

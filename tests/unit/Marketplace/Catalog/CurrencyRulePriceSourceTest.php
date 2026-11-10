<?php
/**
 * The currency rule hands shapes it does not know to the marketplace's price source.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Source;

/**
 * D1 of the marketplace design. `Currency_Rule` judges the empty, `fixed:` and `rate:` shapes itself and passes every other shape to
 * the adapter's {@see Price_Source}; with no source behind a shape the profile's rule is reported as unsupported, never priced.
 */
final class CurrencyRulePriceSourceTest extends Validator_Test_Case {
	/**
	 * Builds a source that owns the shape `table:NAME` and says what it is told to.
	 *
	 * @param string|null $problem What `problem()` answers.
	 * @return Price_Source
	 */
	private function table_source( ?string $problem ): Price_Source {
		return new class( $problem ) implements Price_Source {
			/**
			 * What `problem()` answers.
			 *
			 * @var string|null
			 */
			private $problem;

			/**
			 * Creates the source.
			 *
			 * @param string|null $problem Answer of `problem()`.
			 */
			public function __construct( ?string $problem ) {
				$this->problem = $problem;
			}

			/**
			 * Label.
			 *
			 * @return string
			 */
			public function label(): string {
				return 'Цена из таблицы';
			}

			/**
			 * Owns `table:NAME`.
			 *
			 * @param string $rule Rule.
			 * @return bool
			 */
			public function supports( string $rule ): bool {
				return 0 === strpos( $rule, 'table:' );
			}

			/**
			 * Problem.
			 *
			 * @param string           $rule           Rule.
			 * @param \WC_Product      $product        Product.
			 * @param \WC_Product|null $parent_product Parent.
			 * @return string|null
			 */
			public function problem( string $rule, \WC_Product $product, ?\WC_Product $parent_product ): ?string {
				return $this->problem;
			}

			/**
			 * Price.
			 *
			 * @param string           $rule           Rule.
			 * @param string           $currency       Currency.
			 * @param \WC_Product      $product        Product.
			 * @param \WC_Product|null $parent_product Parent.
			 * @return Money
			 */
			public function price( string $rule, string $currency, \WC_Product $product, ?\WC_Product $parent_product ): Money {
				return Money::from_string( '1', $currency );
			}
		};
	}

	/**
	 * Validates a product under a currency rule with a cabinet in CNY.
	 *
	 * @param string              $rule    Currency rule.
	 * @param array<string,mixed> $context Context keys that replace the defaults.
	 * @return string[] Error codes.
	 */
	private function codes_for( string $rule, array $context = array() ): array {
		$profile = new Profile( null, 'П', array( 'currency_rule' => $rule ) );

		return $this->error_codes( $this->validate( $this->product(), $this->mapping(), $this->context( array_merge( array( 'account_currency' => 'CNY' ), $context ) ), $profile ) );
	}

	/**
	 * With no source in the context a marketplace shape is unsupported: the layer does not guess what `field:` means.
	 *
	 * @return void
	 */
	public function test_a_marketplace_shape_without_a_source_is_unsupported(): void {
		$this->assertSame( array( 'currency_mismatch' ), $this->codes_for( 'field:_cny_price', array( 'price_source' => null ) ) );
		$this->assertSame( array( 'currency_mismatch' ), $this->codes_for( 'table:rates', array( 'price_source' => null ) ) );
	}

	/**
	 * The unsupported message names the rule, so the merchant can find it in the profile.
	 *
	 * @return void
	 */
	public function test_the_unsupported_message_names_the_rule(): void {
		$profile = new Profile( null, 'П', array( 'currency_rule' => 'table:rates' ) );
		$report  = $this->validate( $this->product(), $this->mapping(), $this->context( array( 'account_currency' => 'CNY', 'price_source' => null ) ), $profile );

		$this->assertStringContainsString( 'table:rates', $report->errors()[0]['message'] );
		$this->assertStringContainsString( 'не распознано', $report->errors()[0]['message'] );
	}

	/**
	 * A source that owns the shape and finds the product priceable lets the rule pass: the shape needs no code in the layer.
	 *
	 * @return void
	 */
	public function test_a_shape_the_source_owns_passes_when_it_has_no_problem(): void {
		$this->assertSame( array(), $this->codes_for( 'table:rates', array( 'price_source' => $this->table_source( null ) ) ) );
	}

	/**
	 * The source's sentence reaches the merchant verbatim, already translated by the adapter.
	 *
	 * @return void
	 */
	public function test_the_sources_problem_is_the_merchants_message(): void {
		$profile = new Profile( null, 'П', array( 'currency_rule' => 'table:rates' ) );
		$context = $this->context(
			array(
				'account_currency' => 'CNY',
				'price_source'     => $this->table_source( 'В таблице нет курса для этого товара.' ),
			)
		);

		$report = $this->validate( $this->product(), $this->mapping(), $context, $profile );

		$this->assertSame( array( 'currency_mismatch' ), $this->error_codes( $report ) );
		$this->assertSame( 'В таблице нет курса для этого товара.', $report->errors()[0]['message'] );
	}

	/**
	 * Neutral rules never reach the source, with or without one.
	 *
	 * @return void
	 */
	public function test_neutral_rules_do_not_need_a_source(): void {
		$this->assertSame( array(), $this->codes_for( 'rate:0.0125', array( 'price_source' => null ) ) );
		$this->assertSame( array(), $this->codes_for( 'fixed:CNY', array( 'price_source' => null ) ) );
		$this->assertSame( array( 'currency_mismatch' ), $this->codes_for( 'fixed:USD', array( 'price_source' => null ) ), 'a wrong fixed currency is still the layer\'s error' );
		$this->assertSame( array( 'currency_mismatch' ), $this->codes_for( '', array( 'price_source' => null ) ), 'RUB shop and CNY cabinet with no rule' );
	}
}

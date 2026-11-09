<?php
/**
 * Tests for the currency terms of a product's price.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Price;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Calculator;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Price\Price_Source;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Field_Price_Source;

/**
 * D1 of the marketplace design: the layer converts by the neutral shapes (empty, `fixed:`, `rate:`) and asks the marketplace's
 * {@see Price_Source} for any other. The source here is the fake marketplace's `field:KEY`, with the values the Ozon adapter passes
 * in, so every expectation is the one the layer had before the move.
 */
final class PriceCurrencyTest extends TestCase {
	/**
	 * Closes Mockery.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\Mockery::close();
		parent::tearDown();
	}

	/**
	 * Builds a product double.
	 *
	 * @param array<string,string> $meta Meta by key.
	 * @return \WC_Product
	 */
	private function product( array $meta = array() ): \WC_Product {
		$product = \Mockery::mock( 'WC_Product' )->shouldIgnoreMissing();
		$product->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( string $key ) use ( $meta ): string {
				return $meta[ $key ] ?? '';
			}
		);

		return $product;
	}

	/**
	 * Currency terms of a RUB shop and a CNY cabinet.
	 *
	 * @param string            $rule    Currency rule.
	 * @param \WC_Product       $product Product.
	 * @param Price_Source|null $source  The marketplace's price source.
	 * @return Price_Currency
	 */
	private function cny( string $rule, \WC_Product $product, ?Price_Source $source = null ): Price_Currency {
		return Price_Currency::for_product( 'RUB', 'CNY', $rule, $product, null, $source );
	}

	/**
	 * `rate:N` multiplies; the price rule then works in the cabinet currency, so rounding survives the conversion (1 000 RUB × 0.0125
	 * = 12.50 CNY, rounded to a whole yuan is 13; rounding in roubles first would leave 12.50).
	 *
	 * @return void
	 */
	public function test_the_rule_works_on_the_converted_price(): void {
		$currency = $this->cny( 'rate:0.0125', $this->product() );
		$rule     = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', Price_Rule::ROUND_TO_1 );

		$pair = Price_Calculator::calculate( $currency->from_shop( '1000' ), null, $rule );

		$this->assertSame( '13.00', $pair->price()->to_string() );
		$this->assertSame( 'CNY', $pair->price()->currency() );
		$this->assertFalse( $currency->is_one_way() );
	}

	/**
	 * A fixed markup is an amount in the cabinet currency; a percent markup is a percent.
	 *
	 * @return void
	 */
	public function test_a_fixed_markup_is_in_the_cabinet_currency(): void {
		$currency = $this->cny( 'rate:0.0125', $this->product() );
		$fixed    = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_FIXED, '5' );
		$percent  = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '10' );

		$this->assertSame( '17.50', Price_Calculator::calculate( $currency->from_shop( '1000' ), null, $fixed )->price()->to_string() );
		$this->assertSame( '13.75', Price_Calculator::calculate( $currency->from_shop( '1000' ), null, $percent )->price()->to_string() );
	}

	/**
	 * The way back divides by the rate.
	 *
	 * @return void
	 */
	public function test_the_rate_divides_on_the_way_back(): void {
		$currency = $this->cny( 'rate:0.0125', $this->product() );

		$this->assertSame( '1000.00', $currency->to_shop( Money::from_string( '12.5', 'CNY' ) )->to_string() );
		$this->assertSame( 'RUB', $currency->to_shop( Money::from_string( '12.5', 'CNY' ) )->currency() );
	}

	/**
	 * `field:KEY` holds the price on the marketplace in the cabinet currency: it replaces the shop price, the price rule still applies,
	 * and such a card has no regular price, so no old price even while the shop product is on sale.
	 *
	 * @return void
	 */
	public function test_a_source_rule_supplies_the_base_price(): void {
		$product  = $this->product( array( '_cny_price' => '88,5' ) );
		$currency = $this->cny( 'field:_cny_price', $product, new Fake_Field_Price_Source() );
		$rule     = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '10' );

		$field_price = $currency->field_price();

		$this->assertTrue( $currency->is_one_way() );
		$this->assertNotNull( $field_price );
		$this->assertSame( '88.50', $field_price->to_string() );
		$this->assertSame( '97.35', Price_Calculator::calculate( $field_price, null, $rule )->price()->to_string() );
	}

	/**
	 * A source rule on a product whose field holds no price is an error, never a silent shop price.
	 *
	 * @dataProvider unusable_field_values
	 * @param string $value Field value.
	 * @return void
	 */
	public function test_an_empty_price_field_is_an_error( string $value ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->cny( 'field:_cny_price', $this->product( array( '_cny_price' => $value ) ), new Fake_Field_Price_Source() );
	}

	/**
	 * Values a price field may not hold.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unusable_field_values(): array {
		return array(
			'empty'    => array( '' ),
			'zero'     => array( '0' ),
			'text'     => array( 'дорого' ),
			'negative' => array( '-5' ),
		);
	}

	/**
	 * A rule of the marketplace's own shape with no source behind it is refused: pricing it as the shop price would send the wrong
	 * number. The source decides which shapes are its own, so the layer never reads `field:` by itself.
	 *
	 * @return void
	 */
	public function test_a_marketplace_shape_without_a_source_is_refused(): void {
		$product = $this->product( array( '_cny_price' => '88' ) );

		$this->assertTrue( Price_Currency::is_marketplace_shape( 'field:_cny_price' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->cny( 'field:_cny_price', $product );
	}

	/**
	 * A source answers only for its own shapes; a neutral rule never reaches it.
	 *
	 * @return void
	 */
	public function test_a_neutral_rule_never_reaches_the_source(): void {
		$source = \Mockery::mock( Price_Source::class );
		$source->shouldReceive( 'supports' )->with( 'rate:2' )->andReturn( false );
		$source->shouldNotReceive( 'price' );

		$currency = $this->cny( 'rate:2', $this->product(), $source );

		$this->assertFalse( $currency->is_one_way() );
		$this->assertSame( '20.00', $currency->from_shop( '10' )->to_string() );
		$this->assertFalse( Price_Currency::is_marketplace_shape( 'rate:2' ) );
		$this->assertFalse( Price_Currency::is_marketplace_shape( 'fixed:CNY' ) );
		$this->assertFalse( Price_Currency::is_marketplace_shape( '' ) );
	}

	/**
	 * An empty rule leaves the number as it is, and says so when the currencies differ.
	 *
	 * @return void
	 */
	public function test_an_empty_rule_is_unresolved_when_the_currencies_differ(): void {
		$this->assertTrue( $this->cny( '', $this->product() )->is_unresolved() );
		$this->assertFalse( $this->cny( 'fixed:CNY', $this->product() )->is_unresolved() );
		$this->assertFalse( Price_Currency::none( 'RUB' )->is_unresolved() );
		$this->assertFalse( Price_Currency::for_product( 'RUB', '', '', $this->product() )->is_unresolved() );
	}

	/**
	 * The source's label is data the adapter supplies for the profile screen.
	 *
	 * @return void
	 */
	public function test_the_source_names_its_choice(): void {
		$this->assertSame( 'Взять цену из поля товара', ( new Fake_Field_Price_Source() )->label() );
	}
}

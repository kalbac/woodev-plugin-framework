<?php
/**
 * The effective price of a card — rule, currency terms and link override.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Override;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Field_Price_Source;
use Woodev\Framework\Marketplace\Flows\Export_Price_Policy;

/**
 * `rate:N` multiplies and `field:KEY` is the price the product holds for the marketplace; the
 * price rule works on the converted price, so a fixed markup, the rounding and the override amounts are in the cabinet currency.
 */
final class ExportPricePolicyTest extends TestCase {
	/**
	 * Builds a product double.
	 *
	 * @param string               $price   Active price.
	 * @param string               $regular Regular price.
	 * @param array<string,string> $meta    Meta by key.
	 * @return \WC_Product
	 */
	private function product( string $price, string $regular, array $meta = [] ): \WC_Product {
		$product = \Mockery::mock( 'WC_Product' )->shouldIgnoreMissing();
		$product->shouldReceive( 'get_price' )->andReturn( $price );
		$product->shouldReceive( 'get_regular_price' )->andReturn( $regular );
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
	 * @param string      $rule    Currency rule.
	 * @param \WC_Product $product Product.
	 * @return Price_Currency
	 */
	private function cny( string $rule, \WC_Product $product ): Price_Currency {
		return Price_Currency::for_product( 'RUB', 'CNY', $rule, $product, null, new Fake_Field_Price_Source() );
	}

	/**
	 * `rate:N` multiplies; the rule then works in the cabinet currency, so rounding survives the conversion (1 000 RUB × 0.0125 =
	 * 12.50 CNY, rounded to a whole yuan is 13; rounding in roubles first would leave 12.50).
	 *
	 * @return void
	 */
	public function test_the_rule_works_on_the_converted_price(): void {
		$product = $this->product( '1000', '1000' );
		$rule    = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', Price_Rule::ROUND_TO_1 );

		$pair = Export_Price_Policy::pair( $product, $rule, null, $this->cny( 'rate:0.0125', $product ) );

		$this->assertSame( '13.00', $pair->price()->to_string() );
		$this->assertSame( 'CNY', $pair->price()->currency() );
	}

	/**
	 * A fixed markup is an amount in the cabinet currency; a percent markup is a percent.
	 *
	 * @return void
	 */
	public function test_a_fixed_markup_is_in_the_cabinet_currency(): void {
		$product = $this->product( '1000', '1000' );
		$fixed   = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_FIXED, '5' );
		$percent = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '10' );

		$this->assertSame( '17.50', Export_Price_Policy::pair( $product, $fixed, null, $this->cny( 'rate:0.0125', $product ) )->price()->to_string() );
		$this->assertSame( '13.75', Export_Price_Policy::pair( $product, $percent, null, $this->cny( 'rate:0.0125', $product ) )->price()->to_string() );
	}

	/**
	 * A sale converts both prices: the old price is the converted regular price.
	 *
	 * @return void
	 */
	public function test_a_sale_converts_price_and_old_price(): void {
		$product = $this->product( '800', '1000' );

		$pair = Export_Price_Policy::pair( $product, new Price_Rule(), null, $this->cny( 'rate:0.0125', $product ) );

		$this->assertSame( '10.00', $pair->price()->to_string() );
		$this->assertSame( '12.50', $pair->old_price()->to_string() );
	}

	/**
	 * `field:KEY` holds the price for the marketplace in the cabinet currency: it replaces the shop price, the rule still applies, and such a
	 * card has no regular price, so no old price even while the shop product is on sale.
	 *
	 * @return void
	 */
	public function test_a_field_rule_supplies_the_price_for_the_marketplace(): void {
		$product  = $this->product( '800', '1000', [ '_cny_price' => '88,5' ] );
		$currency = $this->cny( 'field:_cny_price', $product );
		$rule     = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '10' );

		$pair = Export_Price_Policy::pair( $product, $rule, null, $currency );

		$this->assertTrue( $currency->is_one_way() );
		$this->assertSame( '97.35', $pair->price()->to_string() );
		$this->assertNull( $pair->old_price() );
	}

	/**
	 * A field rule on a product whose field holds no price is an error, never a silent shop price.
	 *
	 * @dataProvider unusable_field_values
	 * @param string $value Field value.
	 * @return void
	 */
	public function test_an_empty_price_field_is_an_error( string $value ): void {
		$product = $this->product( '800', '1000', [ '_cny_price' => $value ] );

		$this->expectException( \InvalidArgumentException::class );
		$this->cny( 'field:_cny_price', $product );
	}

	/**
	 * Values a price field may not hold.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unusable_field_values(): array {
		return [
			'empty'    => [ '' ],
			'zero'     => [ '0' ],
			'text'     => [ 'дорого' ],
			'negative' => [ '-5' ],
		];
	}

	/**
	 * The link's override replaces the rule field by field.
	 *
	 * @return void
	 */
	public function test_the_override_replaces_the_rule_field_by_field(): void {
		$product  = $this->product( '800', '1000' );
		$currency = Price_Currency::none( 'RUB' );
		$rule     = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '20', Price_Rule::ROUND_NONE, Price_Rule::OLD_PRICE_ON_SALE, '80' );

		$none = Export_Price_Policy::pair( $product, $rule, [ 'price' => [ 'kind' => 'none' ] ], $currency );
		$this->assertSame( '800.00', $none->price()->to_string(), '«none» removes the markup for this product' );
		$this->assertSame( '640.00', $none->min_price()->to_string(), 'the rule\'s minimum percent still applies' );

		$percent = Export_Price_Policy::pair(
			$product,
			$rule,
			[
				'price' => [
					'kind'  => 'percent',
					'value' => '50',
				],
			],
			$currency
		);
		$this->assertSame( '1200.00', $percent->price()->to_string(), 'a percent stays a percent' );
		$this->assertSame( '1500.00', $percent->old_price()->to_string(), 'the old price follows the same markup' );

		$fixed = Export_Price_Policy::pair(
			$product,
			$rule,
			[
				'price' => [
					'kind'  => 'fixed',
					'value' => '999',
				],
			],
			$currency
		);
		$this->assertSame( '999.00', $fixed->price()->to_string(), 'a fixed amount wins over the formula' );
		$this->assertSame( '1200.00', $fixed->old_price()->to_string() );
		$this->assertSame( '799.20', $fixed->min_price()->to_string(), 'the minimum price follows the final price' );

		$no_old = Export_Price_Policy::pair( $product, $rule, [ 'old_price' => [ 'kind' => 'none' ] ], $currency );
		$this->assertNull( $no_old->old_price() );

		$old = Export_Price_Policy::pair(
			$product,
			$rule,
			[
				'old_price' => [
					'kind'  => 'fixed',
					'value' => '2000',
				],
				'min_price' => [
					'kind'  => 'fixed',
					'value' => '700',
				],
			],
			$currency
		);
		$this->assertSame( '2000.00', $old->old_price()->to_string() );
		$this->assertSame( '700.00', $old->min_price()->to_string() );

		$min = Export_Price_Policy::pair(
			$product,
			$rule,
			[
				'min_price' => [
					'kind'  => 'percent',
					'value' => '50',
				],
			],
			$currency
		);
		$this->assertSame( '480.00', $min->min_price()->to_string() );
	}

	/**
	 * An override that cannot be read is an error, not «no override».
	 *
	 * @dataProvider malformed_overrides
	 * @param array<string,mixed> $override Override.
	 * @return void
	 */
	public function test_a_malformed_override_is_refused( array $override ): void {
		$this->expectException( \InvalidArgumentException::class );
		Price_Override::from_array( $override );
	}

	/**
	 * Shapes the override refuses.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function malformed_overrides(): array {
		return [
			'unknown field'        => [
				[
					'discount' => [
						'kind'  => 'fixed',
						'value' => '1',
					],
				],
			],
			'unknown kind'         => [
				[
					'price' => [
						'kind'  => 'double',
						'value' => '2',
					],
				],
			],
			'fixed zero'           => [
				[
					'price' => [
						'kind'  => 'fixed',
						'value' => '0',
					],
				],
			],
			'fixed text'           => [
				[
					'price' => [
						'kind'  => 'fixed',
						'value' => 'abc',
					],
				],
			],
			'three decimals'       => [
				[
					'price' => [
						'kind'  => 'fixed',
						'value' => '1.234',
					],
				],
			],
			'markup at -100'       => [
				[
					'price' => [
						'kind'  => 'percent',
						'value' => '-100',
					],
				],
			],
			'min percent over 100' => [
				[
					'min_price' => [
						'kind'  => 'percent',
						'value' => '101',
					],
				],
			],
			'min price none'       => [ [ 'min_price' => [ 'kind' => 'none' ] ] ],
			'missing value'        => [ [ 'old_price' => [ 'kind' => 'fixed' ] ] ],
		];
	}

	/**
	 * Stored overrides of the v1 migration (ints, floats, comma decimals) read as canonical strings and round-trip.
	 *
	 * @return void
	 */
	public function test_stored_overrides_are_read_and_normalised(): void {
		$override = Price_Override::from_array(
			[
				'price'     => [
					'kind'  => 'percent',
					'value' => 12.5,
				],
				'old_price' => [
					'kind'  => 'fixed',
					'value' => '1999,90',
				],
				'min_price' => [
					'kind'  => 'fixed',
					'value' => 600,
				],
			]
		);

		$this->assertSame(
			[
				'price'     => [
					'kind'  => 'percent',
					'value' => '12.5',
				],
				'old_price' => [
					'kind'  => 'fixed',
					'value' => '1999.90',
				],
				'min_price' => [
					'kind'  => 'fixed',
					'value' => '600',
				],
			],
			$override->to_array()
		);
		$this->assertTrue( Price_Override::from_array( [] )->is_empty() );
		$this->assertNull( Price_Override::from_array( null )->to_array() );
	}

	/**
	 * Both stored shapes of a rule are read; the profile's rule wins over the account's; an unreadable one is an error.
	 *
	 * @return void
	 */
	public function test_the_rule_comes_from_the_profile_else_the_account_in_either_stored_shape(): void {
		$account = new Account(
			7,
			'fake',
			'T',
			true,
			[],
			null,
			[
				'price_rule' => [
					'markup_kind' => 'percent',
					'markup'      => '10',
					'rounding'    => 'to_10',
				],
			]
		);

		$from_account = Export_Price_Policy::rule( $account, new Profile( null, 'П' ) );
		$this->assertSame( [ 'percent', '10', 'to_10' ], [ $from_account->markup_kind(), $from_account->markup(), $from_account->rounding() ] );

		$migrated = Export_Price_Policy::rule(
			$account,
			new Profile(
				null,
				'П',
				[
					'price_rule' => [
						'markup' => [
							'kind'  => 'fixed',
							'value' => 150,
						],
					],
				]
			)
		);
		$this->assertSame( [ 'fixed', '150', 'none' ], [ $migrated->markup_kind(), $migrated->markup(), $migrated->rounding() ], 'the profile wins and the migrated shape is read' );

		$this->expectException( \InvalidArgumentException::class );
		Export_Price_Policy::rule(
			$account,
			new Profile(
				null,
				'П',
				[
					'price_rule' => [
						'markup_kind' => 'percent',
						'markup'      => 'abc',
					],
				]
			)
		);
	}

	/**
	 * The inverse divides a percent markup and the rate; it cannot undo a price taken from a field or fixed by the override.
	 *
	 * @return void
	 */
	public function test_the_inverse_divides_and_refuses_one_way_prices(): void {
		$rule    = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '25' );
		$remote  = Money::from_string( '15.625', 'CNY' );
		$product = $this->product( '1000', '1000' );

		$restored = Export_Price_Policy::invert( $remote, null, $rule, null, $this->cny( 'rate:0.0125', $product ) );
		$this->assertSame( '1000.00', $restored['regular']->to_string(), '15.63 / 1.25 / 0.0125' );
		$this->assertSame( 'RUB', $restored['regular']->currency() );
		$this->assertNull( $restored['sale'] );

		$this->assertNull(
			Export_Price_Policy::invert(
				$remote,
				null,
				$rule,
				[
					'price' => [
						'kind'  => 'fixed',
						'value' => '9',
					],
				],
				$this->cny( 'rate:0.0125', $product )
			)
		);

		$field = $this->product( '1000', '1000', [ '_cny_price' => '15' ] );
		$this->assertNull( Export_Price_Policy::invert( $remote, null, $rule, null, $this->cny( 'field:_cny_price', $field ) ) );
	}

	/**
	 * A fixed markup is subtracted on the way back; a markup larger than the price gives zero, never a negative price.
	 *
	 * @return void
	 */
	public function test_the_inverse_of_a_fixed_markup_subtracts(): void {
		$rule = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_FIXED, '150' );

		$this->assertSame( '850.00', Export_Price_Policy::invert( Money::from_string( '1000', 'RUB' ), null, $rule, null, Price_Currency::none( 'RUB' ) )['regular']->to_string() );
		$this->assertTrue( Export_Price_Policy::invert( Money::from_string( '100', 'RUB' ), null, $rule, null, Price_Currency::none( 'RUB' ) )['regular']->is_zero() );
	}

	/**
	 * The payload is the stored shape: strings, `old_price` 0 clears it, `min_price` null is absent.
	 *
	 * @return void
	 */
	public function test_the_payload_has_the_stored_shape(): void {
		$product = $this->product( '800', '1000' );
		$rule    = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', Price_Rule::ROUND_NONE, Price_Rule::OLD_PRICE_ON_SALE, '90' );

		$this->assertSame(
			[
				'price'         => '800.00',
				'old_price'     => '1000.00',
				'min_price'     => '720.00',
				'currency_code' => 'RUB',
			],
			Export_Price_Policy::payload( Export_Price_Policy::pair( $product, $rule, null, Price_Currency::none( 'RUB' ) ) )
		);
		$this->assertSame( '0', Export_Price_Policy::payload( Export_Price_Policy::pair( $this->product( '800', '800' ), $rule, null, Price_Currency::none( 'RUB' ) ) )['old_price'] );
	}

	/**
	 * «До 99» keeps the assumption of issue #37: the smallest whole-unit price ending in 99 that is not below the marked-up price.
	 *
	 * @return void
	 */
	public function test_ends_99_keeps_the_documented_assumption(): void {
		$product = $this->product( '1234.56', '1234.56' );
		$rule    = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_NONE, '0', Price_Rule::ROUND_ENDS_99 );

		$this->assertSame( '1299.00', Export_Price_Policy::pair( $product, $rule, null, Price_Currency::none( 'RUB' ) )->price()->to_string() );
	}


	/**
	 * Review finding 2: the way back undoes every price the way it was made. Regular 1000 / sale 800, +20 %, old price +50 %: the
	 * pair is 960 / 1500 and the inverse is 1000 / 800 again (it used to be 1250 / 800).
	 *
	 * @return void
	 */
	public function test_the_inverse_undoes_the_old_price_the_way_it_was_made(): void {
		$product  = $this->product( '800', '1000' );
		$rule     = new Price_Rule( Price_Rule::BASE_ACTIVE, Price_Rule::MARKUP_PERCENT, '20' );
		$override = [
			'old_price' => [
				'kind'  => 'percent',
				'value' => '50',
			],
		];
		$currency = Price_Currency::none( 'RUB' );

		$pair = Export_Price_Policy::pair( $product, $rule, $override, $currency );
		$this->assertSame( [ '960.00', '1500.00' ], [ $pair->price()->to_string(), $pair->old_price()->to_string() ] );

		$back = Export_Price_Policy::invert( $pair->price(), $pair->old_price(), $rule, $override, $currency );
		$this->assertSame( [ '1000.00', '800.00' ], [ $back['regular']->to_string(), $back['sale']->to_string() ] );

		$fixed = [
			'old_price' => [
				'kind'  => 'fixed',
				'value' => '2000',
			],
		];
		$back  = Export_Price_Policy::invert( Money::from_string( '960', 'RUB' ), Money::from_string( '2000', 'RUB' ), $rule, $fixed, $currency );
		$this->assertNull( $back['regular'], 'an absolute old price says nothing about the regular price' );
		$this->assertSame( '800.00', $back['sale']->to_string() );

		$plain = Export_Price_Policy::invert( Money::from_string( '960', 'RUB' ), Money::from_string( '1200', 'RUB' ), $rule, null, $currency );
		$this->assertSame( [ '1000.00', '800.00' ], [ $plain['regular']->to_string(), $plain['sale']->to_string() ], 'with no override the rule made the old price too' );
	}
}

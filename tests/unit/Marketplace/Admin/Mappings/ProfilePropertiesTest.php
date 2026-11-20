<?php
/**
 * What a profile sets, as the profiles list shows it.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Choices;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Properties;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Properties
 */
final class ProfilePropertiesTest extends TestCase {
	/**
	 * The shop measures in centimetres and kilograms.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return $default;
			}
		);
	}

	/**
	 * @param array<string,mixed> $settings Stored settings.
	 * @return string[]
	 */
	private function describe( array $settings ): array {
		return Profile_Properties::describe( $settings, Profile_Choices::standard() );
	}

	/**
	 * A profile with nothing set gives no lines: the list says «Не настроен».
	 *
	 * @return void
	 */
	public function test_a_profile_with_nothing_set_has_no_lines(): void {
		$this->assertSame( [], $this->describe( [] ) );
		$this->assertSame( [], $this->describe( [ 'fallback_width' => '', 'vat_rate' => '', 'price_rule' => [] ] ) );
	}

	/**
	 * The v1 shape: dimensions, weight and VAT with their values.
	 *
	 * @return void
	 */
	public function test_the_lines_carry_the_values_with_their_units(): void {
		$lines = $this->describe(
			[
				'fallback_width'  => '20',
				'fallback_height' => '15',
				'fallback_depth'  => '2',
				'fallback_weight' => '300',
				'units'           => [
					'dimension' => 'cm',
					'weight'    => 'g',
				],
				'vat_rate'        => '0.2',
			]
		);

		$this->assertSame( [ 'Габариты: 20×15×2 см', 'Вес: 300 г', 'НДС: 20%' ], $lines );
	}

	/**
	 * A side that is not set is a dash, and a unit the profile leaves out is the shop's own.
	 *
	 * @return void
	 */
	public function test_a_missing_side_is_a_dash_and_the_unit_is_the_shops(): void {
		$this->assertSame( [ 'Габариты: 20×—×— см' ], $this->describe( [ 'fallback_width' => '20' ] ) );
		$this->assertSame( [ 'Вес: 2 кг' ], $this->describe( [ 'fallback_weight' => '2' ] ) );
	}

	/**
	 * «Без НДС» is a rate the merchant chose: the zero is a value, not an empty field.
	 *
	 * @return void
	 */
	public function test_a_zero_vat_rate_is_a_value(): void {
		$this->assertSame( [ 'НДС: Без НДС' ], $this->describe( [ 'vat_rate' => '0' ] ) );
	}

	/**
	 * Units alone are named only when no size or weight shows them.
	 *
	 * @return void
	 */
	public function test_units_alone_are_named_and_units_with_values_are_not(): void {
		$units = [
			'dimension' => 'mm',
			'weight'    => 'kg',
		];

		$this->assertSame( [ 'Единицы измерения: мм, кг' ], $this->describe( [ 'units' => $units ] ) );
		$this->assertSame( [ 'Вес: 1 кг' ], $this->describe( [ 'units' => $units, 'fallback_weight' => '1' ] ) );
	}

	/**
	 * The currency and the price rule, in a line each.
	 *
	 * @return void
	 */
	public function test_currency_and_price_lines(): void {
		$lines = $this->describe(
			[
				'currency_rule' => 'rate:90',
				'price_rule'    => [
					'markup_kind' => 'percent',
					'markup'      => '10',
				],
			]
		);

		$this->assertSame( [ 'Валюта: по курсу 90', 'Цена: наценка 10%' ], $lines );
		$this->assertSame( [ 'Цена: своё правило' ], $this->describe( [ 'price_rule' => [ 'markup_kind' => 'fixed', 'markup' => '100' ] ] ) );
		$this->assertSame( [ 'Валюта: KZT' ], $this->describe( [ 'currency_rule' => 'fixed:KZT' ] ) );
	}

	/**
	 * Only a source the merchant changed from the default is a property: a profile saved with the defaults is not configured.
	 *
	 * @return void
	 */
	public function test_only_changed_sources_count(): void {
		$this->assertSame( [], $this->describe( [ 'offer_id_source' => 'sku', 'images_source' => 'gallery' ] ) );
		$this->assertSame( [ 'Источники данных: изменены (2)' ], $this->describe( [ 'offer_id_source' => 'meta:article', 'images_source' => 'none' ] ) );
	}
}

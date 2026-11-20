<?php
/**
 * The sentences and option lists the mappings view reads from PHP.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\View_Config;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\View_Config
 */
final class ViewConfigTest extends TestCase {
	/**
	 * The default unit of a size or weight select names the unit the shop has.
	 *
	 * @dataProvider shop_units
	 *
	 * @param string $dimension Dimension unit option.
	 * @param string $weight    Weight unit option.
	 * @param string $expected_dimension Sentence for the size select.
	 * @param string $expected_weight    Sentence for the weight select.
	 * @return void
	 */
	public function test_default_units_name_the_unit_of_the_shop( string $dimension, string $weight, string $expected_dimension, string $expected_weight ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $dimension, $weight ) {
				$stored = [
					'woocommerce_dimension_unit' => $dimension,
					'woocommerce_weight_unit'    => $weight,
				];

				return $stored[ $name ] ?? $default;
			}
		);

		$messages = View_Config::messages();

		$this->assertSame( $expected_dimension, $messages['unitDefaultDimension'] );
		$this->assertSame( $expected_weight, $messages['unitDefaultWeight'] );
	}

	/**
	 * Units and the sentences they give.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function shop_units(): array {
		return [
			'metric'              => [ 'cm', 'kg', 'как в магазине (см)', 'как в магазине (кг)' ],
			'small metric units'  => [ 'mm', 'g', 'как в магазине (мм)', 'как в магазине (г)' ],
			'a unit with no name' => [ 'in', 'lbs', 'как в магазине (in)', 'как в магазине (lbs)' ],
		];
	}

	/**
	 * The words of the sidebar layout are gone and the words of the list-and-form layout are there.
	 *
	 * @return void
	 */
	public function test_messages_carry_the_list_and_form_words(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return $default;
			}
		);

		$messages = View_Config::messages();

		foreach ( [ 'backToMappings', 'backToProfiles', 'newMapping', 'newProfile', 'groupCategories', 'groupPrice', 'fallbackDimensions', 'checkHeading' ] as $key ) {
			$this->assertNotSame( '', $messages[ $key ] ?? '', $key );
		}

		foreach ( [ 'chooseMapping', 'chooseProfile', 'unitDefault' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $messages );
		}
	}
}

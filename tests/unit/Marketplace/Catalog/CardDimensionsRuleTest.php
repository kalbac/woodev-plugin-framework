<?php
/**
 * Variations of one card must agree on weight and dimensions.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Rules\Card_Dimensions_Rule;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;

/**
 * Here the card of a variation is its colour, which the doubles keep in `get_sku`: `red-M` and `red-L` share a card.
 *
 * @covers \Woodev\Framework\Marketplace\Catalog\Rules\Card_Dimensions_Rule
 */
final class CardDimensionsRuleTest extends Validator_Test_Case {
	/**
	 * Validates a variation against its siblings.
	 *
	 * @param array<string,mixed>            $own      Getters of the variation.
	 * @param array<int,array<string,mixed>> $siblings Getters of each sibling.
	 * @param array<string,mixed>            $extra    Further context.
	 * @param Profile|null                   $profile  Profile.
	 * @return Validation_Report
	 */
	private function report( array $own, array $siblings, array $extra = [], ?Profile $profile = null ): Validation_Report {
		$mapping = $this->mapping();
		$rule    = new Card_Dimensions_Rule(
			static function ( \WC_Product $product, Mapping $mapping ): string {
				return explode( '-', (string) $product->get_sku() )[0];
			}
		);
		$rows    = [];

		foreach ( $siblings as $sibling ) {
			$rows[] = [
				'product' => $this->product( $sibling + [ 'get_sku' => 'red-L' ] ),
				'mapping' => $mapping,
			];
		}

		return ( new Export_Validator( [ $rule ] ) )->validate(
			$this->product( $own + [ 'get_sku' => 'red-M' ] ),
			$mapping,
			$profile ?? new Profile( null, 'П' ),
			[ 'siblings' => $rows ] + $extra
		);
	}

	public function test_sizes_that_agree_pass_however_the_number_is_written(): void {
		$this->assertTrue( $this->report( [ 'get_weight' => '2' ], [ [ 'get_weight' => '2.0' ] ] )->is_valid() );
		$this->assertTrue( $this->report( [], [] )->is_valid(), 'a product with no siblings' );
	}

	public function test_sizes_of_one_card_that_differ_are_refused_naming_what_differs(): void {
		$report = $this->report(
			[],
			[ [ 'get_height' => '11' ], [ 'get_weight' => '3' ] ],
			[ 'marketplace_name' => 'Тестмаркет' ]
		);

		$this->assertSame( [ Card_Dimensions_Rule::CODE ], $this->error_codes( $report ) );
		$this->assertStringContainsString( 'различаются: вес, высота.', $report->first_error()['message'] );
		$this->assertStringContainsString( 'В карточке Тестмаркет', $report->first_error()['message'] );
		$this->assertSame( Validation_Report::FIX_PRODUCT, $report->first_error()['fix'] );
	}

	public function test_both_sides_of_a_difference_are_refused(): void {
		$this->assertFalse( $this->report( [ 'get_width' => '25' ], [ [] ] )->is_valid() );
		$this->assertFalse( $this->report( [], [ [ 'get_width' => '25' ] ] )->is_valid() );
	}

	public function test_another_card_of_the_same_product_may_differ(): void {
		$report = $this->report(
			[],
			[
				[
					'get_sku'    => 'blue-M',
					'get_weight' => '9',
				],
			]
		);

		$this->assertTrue( $report->is_valid() );
	}

	public function test_an_empty_value_reads_the_parent_then_the_profile(): void {
		$parent = $this->product( [ 'get_weight' => '2' ] );

		$this->assertTrue( $this->report( [ 'get_weight' => '' ], [ [ 'get_weight' => '2' ] ], [ 'parent_product' => $parent ] )->is_valid() );
		$this->assertFalse( $this->report( [ 'get_weight' => '' ], [ [ 'get_weight' => '5' ] ], [ 'parent_product' => $parent ] )->is_valid() );

		$profile = new Profile( null, 'П', [ 'fallback_weight' => 2 ] );

		$this->assertTrue( $this->report( [ 'get_weight' => '' ], [ [ 'get_weight' => '2' ] ], [], $profile )->is_valid() );
		$this->assertFalse( $this->report( [ 'get_weight' => '' ], [ [ 'get_weight' => '5' ] ], [], $profile )->is_valid() );
	}

	public function test_a_value_nobody_has_is_left_to_the_dimensions_rule(): void {
		$this->assertTrue( $this->report( [ 'get_weight' => '' ], [ [] ] )->is_valid() );
	}
}

<?php
/**
 * Unit tests for the declared shape of the product tab's posted body.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Admin\Product\Link_Form_Input;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\TestCase;

final class LinkFormInputTest extends TestCase {
	public function test_a_well_formed_block_is_parsed_and_an_absent_key_stays_absent(): void {
		$parsed = $this->input()->parse(
			[
				'sync_stock'   => '1',
				'sync_price'   => '0',
				'warehouse_id' => '22',
				'mapping_id'   => '',
				'offer_id'     => '  ART-1 ',
			]
		);

		$this->assertSame( [], $parsed['errors'] );
		$this->assertSame(
			[
				'sync_stock'   => true,
				'sync_price'   => false,
				'warehouse_id' => 22,
				'mapping_id'   => null,
				'offer_id'     => 'ART-1',
			],
			$parsed['values']
		);
	}

	public function test_a_body_of_the_wrong_shape_is_an_error_and_yields_no_values(): void {
		foreach ( [ 'a string', null, [ 'sync_stock' => 'yes' ], [ 'warehouse_id' => '1.5' ], [ 'mapping_id' => [ '3' ] ], [ 'warehouse_id' => '0' ], [ 'offer_id' => [ 'x' ] ] ] as $raw ) {
			$parsed = $this->input()->parse( $raw );

			$this->assertNotSame( [], $parsed['errors'], (string) json_encode( $raw ) );
			$this->assertSame( [], $parsed['values'] );
		}
	}

	public function test_an_article_the_policy_rejects_is_an_error(): void {
		$parsed = $this->input()->parse( [ 'offer_id' => 'has spaces' ] );

		$this->assertCount( 1, $parsed['errors'] );
		$this->assertSame( [], $parsed['values'] );
	}

	public function test_an_empty_article_clears_the_choice(): void {
		$this->assertSame( [ 'offer_id' => null ], $this->input()->parse( [ 'offer_id' => '' ] )['values'] );
	}

	public function test_the_price_override_keeps_only_the_fields_the_merchant_chose(): void {
		$parsed = $this->input()->parse(
			[
				'price' => [
					'price'     => [
						'kind'  => 'fixed',
						'value' => '1500',
					],
					'old_price' => [
						'kind'  => '',
						'value' => '',
					],
					'min_price' => [
						'kind'  => '',
						'value' => '',
					],
				],
			]
		);

		$this->assertSame( [], $parsed['errors'] );
		$this->assertSame( [ 'price' ], array_keys( $parsed['values']['price_override'] ) );
	}

	public function test_a_bad_price_is_an_error_naming_the_field(): void {
		$parsed = $this->input()->parse(
			[
				'price' => [
					'price' => [
						'kind'  => 'fixed',
						'value' => 'abc',
					],
				],
			]
		);

		$this->assertCount( 1, $parsed['errors'] );
		$this->assertStringContainsString( 'Цена', $parsed['errors'][0] );
		$this->assertSame( [], $parsed['values'] );
	}

	/**
	 * Builds the reader.
	 *
	 * @return Link_Form_Input
	 */
	private function input(): Link_Form_Input {
		return new Link_Form_Input( new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() ) );
	}
}

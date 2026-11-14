<?php
/**
 * The card value an adapter hands the importer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pair;

/**
 * Card I1 acceptance: the value is pure and reads an unknown member as unknown, so the importer can tell «the marketplace said
 * nothing» from «the marketplace said zero».
 */
final class CardContentTest extends TestCase {
	/**
	 * A card with nothing known reads every member as unknown.
	 *
	 * @return void
	 */
	public function test_an_empty_card_reads_every_member_as_unknown(): void {
		$content = new Card_Content( [ 'offer_id' => 'A-1' ] );

		$this->assertSame( [ 'offer_id' => 'A-1' ], $content->card() );
		$this->assertSame( '', $content->name() );
		$this->assertSame( '', $content->description() );
		$this->assertNull( $content->prices() );
		$this->assertNull( $content->weight_g() );
		$this->assertNull( $content->length_mm() );
		$this->assertNull( $content->width_mm() );
		$this->assertNull( $content->height_mm() );
		$this->assertSame( '', $content->primary_image() );
		$this->assertSame( [], $content->images() );
		$this->assertSame( [], $content->remote_category() );
		$this->assertSame( [], $content->category_path() );
		$this->assertSame(
			[
				'key'     => '',
				'size'    => 0,
				'aspects' => [],
			],
			$content->group()
		);
	}

	/**
	 * Zero is a known value: a card that says 0 g weighs 0 g, it does not lose the number.
	 *
	 * @return void
	 */
	public function test_zero_is_a_known_number_and_not_unknown(): void {
		$content = new Card_Content( [ 'offer_id' => 'A-1' ], [ 'weight_g' => 0, 'length_mm' => 0 ] );

		$this->assertSame( 0, $content->weight_g() );
		$this->assertSame( 0, $content->length_mm() );
		$this->assertNull( $content->width_mm() );
	}

	/**
	 * Every member returns what the adapter put in.
	 *
	 * @return void
	 */
	public function test_members_return_the_values_the_adapter_gave(): void {
		$prices  = new Price_Pair( Money::of( 90000, 'RUB' ), Money::of( 120000, 'RUB' ) );
		$content = new Card_Content(
			[ 'offer_id' => 'A-1', 'is_created' => true ],
			[
				'name'            => 'Chair',
				'description'     => '<p>Oak</p>',
				'prices'          => $prices,
				'weight_g'        => 4500,
				'length_mm'       => 600,
				'width_mm'        => 550,
				'height_mm'       => 900,
				'primary_image'   => 'https://img.example/1.jpg',
				'images'          => [ 'https://img.example/2.jpg' ],
				'remote_category' => [ 'id' => 7 ],
				'category_path'   => [ 'Furniture', 'Chairs' ],
				'group'           => [
					'key'     => 'G-1',
					'size'    => 3,
					'aspects' => [ 'color' => 'red' ],
				],
			]
		);

		$this->assertSame( 'Chair', $content->name() );
		$this->assertSame( '<p>Oak</p>', $content->description() );
		$this->assertSame( $prices, $content->prices() );
		$this->assertSame( 4500, $content->weight_g() );
		$this->assertSame( 600, $content->length_mm() );
		$this->assertSame( 550, $content->width_mm() );
		$this->assertSame( 900, $content->height_mm() );
		$this->assertSame( 'https://img.example/1.jpg', $content->primary_image() );
		$this->assertSame( [ 'https://img.example/2.jpg' ], $content->images() );
		$this->assertSame( [ 'id' => 7 ], $content->remote_category() );
		$this->assertSame( [ 'Furniture', 'Chairs' ], $content->category_path() );
		$this->assertSame(
			[
				'key'     => 'G-1',
				'size'    => 3,
				'aspects' => [ 'color' => 'red' ],
			],
			$content->group()
		);
		$this->assertTrue( $content->card()['is_created'] );
	}

	/**
	 * A value that is not a price is not a price: the accessor never hands out something else.
	 *
	 * @return void
	 */
	public function test_a_price_that_is_not_a_price_pair_reads_as_none(): void {
		$content = new Card_Content( [ 'offer_id' => 'A-1' ], [ 'prices' => '1500' ] );

		$this->assertNull( $content->prices() );
	}

	/**
	 * Lists keep only strings, in order: a broken entry in the adapter's answer does not reach the writer.
	 *
	 * @return void
	 */
	public function test_lists_keep_only_strings_in_order(): void {
		$content = new Card_Content(
			[ 'offer_id' => 'A-1' ],
			[
				'images'        => [ 'https://a', null, 'https://b' ],
				'category_path' => [ 'Root', 42, 'Leaf' ],
			]
		);

		$this->assertSame( [ 'https://a', 'https://b' ], $content->images() );
		$this->assertSame( [ 'Root', 'Leaf' ], $content->category_path() );
	}
}

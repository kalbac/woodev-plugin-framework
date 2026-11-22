<?php
/**
 * The characteristics of an imported card, written into a product as its attributes.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Flows\Card_Attributes;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Shop;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Attributes
 * @covers \Woodev\Framework\Marketplace\Catalog\Card_Content::characteristics
 */
final class CardAttributesTest extends TestCase {
	/**
	 * The shop the products live in.
	 *
	 * @var Fake_Shop
	 */
	private $shop;

	/**
	 * Boots the stubs and an empty shop.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->shop = new Fake_Shop();
		$this->shop->install();
	}

	/**
	 * Tears the stubs down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a card with the characteristics an adapter gave and, when given, the aspects of its group.
	 *
	 * @param array<int,array<string,mixed>> $characteristics Characteristics, as the adapter gives them.
	 * @param array<string,string>           $aspects         Aspects of the card's group.
	 * @return Card_Content
	 */
	private function card( array $characteristics, array $aspects = [] ): Card_Content {
		return new Card_Content(
			[ 'offer_id' => 'A-1' ],
			[
				'characteristics' => $characteristics,
				'group'           => [] === $aspects ? [] : [
					'key'     => 'model-1',
					'size'    => 2,
					'aspects' => $aspects,
				],
			]
		);
	}

	/**
	 * What a product holds as attributes: `[ name, values, shown on the page, used for variations ]` each, in order.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,array{0:string,1:array<int,string>,2:bool,3:bool}>
	 */
	private function held( \WC_Product $product ): array {
		return array_values(
			array_map(
				static function ( $attribute ): array {
					return [ $attribute->get_name(), $attribute->get_options(), $attribute->get_visible(), $attribute->get_variation() ];
				},
				$product->get_attributes( 'edit' )
			)
		);
	}

	/**
	 * The adapter's list is cleaned: no name or no value drops a member, a name is kept once (case ignored), values are distinct.
	 *
	 * @return void
	 */
	public function test_the_card_reads_only_characteristics_with_a_name_and_a_value(): void {
		$content = $this->card(
			[
				[
					'id'     => 85,
					'name'   => ' Бренд ',
					'values' => [ 'Acme', 'Acme', ' ' ],
				],
				[
					'name'   => 'без значения',
					'values' => [ '' ],
				],
				[
					'name'   => '',
					'values' => [ 'x' ],
				],
				[
					'name'   => 'бренд',
					'values' => [ 'Other' ],
				],
				'not a member',
			]
		);

		$this->assertSame(
			[
				[
					'id'     => '85',
					'name'   => 'Бренд',
					'values' => [ 'Acme' ],
				],
			],
			$content->characteristics()
		);
	}

	/**
	 * Every characteristic becomes a visible attribute that is no variation's — plain and dictionary values alike.
	 *
	 * @return void
	 */
	public function test_every_characteristic_becomes_a_visible_attribute_of_the_product(): void {
		$product = $this->shop->product( 'simple' );

		$changed = Card_Attributes::write(
			$product,
			$this->card(
				[
					[
						'name'   => 'Бренд',
						'values' => [ 'Acme' ],
					],
					[
						'name'   => 'Кол-во упаковок',
						'values' => [ '2' ],
					],
					[
						'name'   => 'Материал',
						'values' => [ 'сталь', 'алюминий' ],
					],
				]
			)
		);

		$this->assertTrue( $changed );
		$this->assertSame(
			[
				[ 'Бренд', [ 'Acme' ], true, false ],
				[ 'Кол-во упаковок', [ '2' ], true, false ],
				[ 'Материал', [ 'сталь', 'алюминий' ], true, false ],
			],
			$this->held( $product )
		);
	}

	/**
	 * Running it again, or resuming a cut run, adds nothing: an attribute the product has is not written twice or changed.
	 *
	 * @return void
	 */
	public function test_writing_twice_does_not_duplicate_or_change_an_attribute(): void {
		$product = $this->shop->product( 'simple' );
		$content = $this->card(
			[
				[
					'name'   => 'Brand',
					'values' => [ 'Acme' ],
				],
			]
		);

		Card_Attributes::write( $product, $content );

		$again = $this->card(
			[
				[
					'name'   => 'brand', // WooCommerce names an attribute by its slug, so the case of a name does not matter.
					'values' => [ 'Other' ],
				],
				[
					'name'   => 'Цвет',
					'values' => [ 'красный' ],
				],
			]
		);

		$this->assertTrue( Card_Attributes::write( $product, $again ), 'the new «Цвет» is added' );
		$this->assertFalse( Card_Attributes::write( $product, $again ), 'nothing is new the second time' );
		$this->assertSame( [ [ 'Brand', [ 'Acme' ], true, false ], [ 'Цвет', [ 'красный' ], true, false ] ], $this->held( $product ) );
	}

	/**
	 * An aspect of the card's group is written with its own value, once, even when the adapter lists it among the characteristics.
	 *
	 * @return void
	 */
	public function test_an_aspect_is_in_the_list_once_with_its_own_value(): void {
		$list = Card_Attributes::of(
			$this->card(
				[
					[
						'id'     => '10096',
						'name'   => 'цвет',
						'values' => [ 'красный', 'синий' ],
					],
					[
						'id'     => '85',
						'name'   => 'Бренд',
						'values' => [ 'Acme' ],
					],
				],
				[ 'Цвет' => 'красный' ]
			)
		);

		$this->assertSame(
			[
				[
					'id'     => '',
					'name'   => 'Цвет',
					'values' => [ 'красный' ],
				],
				[
					'id'     => '85',
					'name'   => 'Бренд',
					'values' => [ 'Acme' ],
				],
			],
			$list
		);
	}

	/**
	 * A card the adapter gave no characteristics for still has its aspects.
	 *
	 * @return void
	 */
	public function test_a_card_without_characteristics_still_writes_its_aspects(): void {
		$product = $this->shop->product( 'simple' );

		Card_Attributes::write( $product, $this->card( [], [ 'Цвет' => 'красный' ] ) );

		$this->assertSame( [ [ 'Цвет', [ 'красный' ], true, false ] ], $this->held( $product ) );
	}

	/**
	 * A card with nothing to write leaves the product, and so its save, alone.
	 *
	 * @return void
	 */
	public function test_a_card_with_nothing_to_write_changes_nothing(): void {
		$product = $this->shop->product( 'simple' );

		$this->assertFalse( Card_Attributes::write( $product, $this->card( [] ) ) );
		$this->assertSame( [], $this->held( $product ) );
	}

	/**
	 * WooCommerce splits a custom attribute's values at «|»: a value holding one is written with a slash, and markup is stripped.
	 *
	 * @return void
	 */
	public function test_a_value_cannot_split_in_two_or_carry_markup(): void {
		$product = $this->shop->product( 'simple' );

		Card_Attributes::write(
			$product,
			$this->card(
				[
					[
						'name'   => 'Размер',
						'values' => [ '10|20', '<b>30</b>' ],
					],
				]
			)
		);

		$this->assertSame( [ [ 'Размер', [ '10/20', '30' ], true, false ] ], $this->held( $product ) );
	}
}

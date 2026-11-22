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
use Woodev\Tests\Unit\Marketplace\Support\Wc_Attribute_Functions;

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

	private const LONG_M  = 'Максимальная длина соединительного кабеля, м';
	private const LONG_CM = 'Максимальная длина соединительного кабеля, см';

	/**
	 * The two long names of the marketplace that WordPress's `sanitize_title()` makes one slug of: it cuts at 200 characters of the
	 * URL-encoded name, about 33 Cyrillic letters.
	 *
	 * @return Card_Content
	 */
	private function cable_card(): Card_Content {
		return $this->card(
			[
				[
					'id'     => '1',
					'name'   => self::LONG_M,
					'values' => [ '10' ],
				],
				[
					'id'     => '2',
					'name'   => self::LONG_CM,
					'values' => [ '1000' ],
				],
			]
		);
	}

	/**
	 * Distinct characteristics that share a slug stay distinct attributes: the second is stored under a prefixed name, the one the
	 * mapping will link too, and each keeps its own value.
	 *
	 * @return void
	 */
	public function test_two_names_that_share_a_slug_are_two_attributes_with_their_own_values(): void {
		Wc_Attribute_Functions::lossy_slug();

		$this->assertSame( sanitize_title( self::LONG_M ), sanitize_title( self::LONG_CM ), 'the premise: one slug' );

		$product = $this->shop->product( 'simple' );

		Card_Attributes::write( $product, $this->cable_card() );

		$this->assertSame(
			[
				[ self::LONG_M, [ '10' ], true, false ],
				[ '[2] ' . self::LONG_CM, [ '1000' ], true, false ],
			],
			$this->held( $product )
		);
		$this->assertSame( [ self::LONG_M, '[2] ' . self::LONG_CM ], array_column( Card_Attributes::of( $this->cable_card(), $product ), 'name' ), 'the product already holds both: the same names, nothing new' );
		$this->assertFalse( Card_Attributes::write( $product, $this->cable_card() ), 'and a second write adds nothing' );
	}

	/**
	 * A card that lists the same two names the other way round, onto a product that holds them, finds the same attributes.
	 *
	 * @return void
	 */
	public function test_the_order_of_the_card_does_not_rename_what_the_product_holds(): void {
		Wc_Attribute_Functions::lossy_slug();

		$product = $this->shop->product( 'simple' );

		Card_Attributes::write( $product, $this->cable_card() );

		$reversed = $this->card(
			[
				[
					'id'     => '2',
					'name'   => self::LONG_CM,
					'values' => [ '1000' ],
				],
				[
					'id'     => '1',
					'name'   => self::LONG_M,
					'values' => [ '10' ],
				],
			]
		);

		$this->assertSame( [ '[2] ' . self::LONG_CM, self::LONG_M ], array_column( Card_Attributes::of( $reversed, $product ), 'name' ) );
		$this->assertFalse( Card_Attributes::write( $product, $reversed ) );
	}

	/**
	 * An attribute of the merchant's that holds the slug is not changed, and the characteristic gets a free name instead of being
	 * dropped or answering with the merchant's value.
	 *
	 * @return void
	 */
	public function test_a_merchants_attribute_that_holds_the_slug_is_kept_and_ours_is_made_beside_it(): void {
		Wc_Attribute_Functions::lossy_slug();

		$product  = $this->shop->product( 'simple' );
		$merchant = new \WC_Product_Attribute();

		$merchant->set_name( self::LONG_M );
		$merchant->set_options( [ 'merchant\'s own' ] );
		$product->set_attributes( [ $merchant ] );

		Card_Attributes::write(
			$product,
			$this->card(
				[
					[
						'id'     => '2',
						'name'   => self::LONG_CM,
						'values' => [ '1000' ],
					],
				]
			)
		);

		$this->assertSame(
			[
				[ self::LONG_M, [ 'merchant\'s own' ], false, false ],
				[ '[2] ' . self::LONG_CM, [ '1000' ], true, false ],
			],
			$this->held( $product )
		);
	}

	/**
	 * A characteristic with no field id (an aspect) is told apart by a short hash of its name.
	 *
	 * @return void
	 */
	public function test_a_collision_without_a_field_id_is_prefixed_by_a_hash_of_the_name(): void {
		Wc_Attribute_Functions::lossy_slug();

		$names = array_column( Card_Attributes::of( $this->card( [], [ self::LONG_M => '10', self::LONG_CM => '1000' ] ) ), 'name' );

		$this->assertSame( self::LONG_M, $names[0] );
		$this->assertMatchesRegularExpression( '/^\[[0-9a-f]{6}\] ' . preg_quote( self::LONG_CM, '/' ) . '$/u', $names[1] );
	}

	/**
	 * The shop's global attribute of the characteristic's name is the same attribute: no custom twin is made beside it, and the name
	 * the mapping links stays the characteristic's own.
	 *
	 * @return void
	 */
	public function test_a_global_attribute_of_the_same_name_is_not_duplicated(): void {
		$product = $this->shop->product( 'simple' );
		$global  = new \WC_Product_Attribute();

		$global->set_name( 'pa_brand' );
		$global->set_options( [ 12 ] );
		$product->set_attributes( [ $global ] );

		$card = $this->card(
			[
				[
					'name'   => 'Brand',
					'values' => [ 'Acme' ],
				],
				[
					'name'   => 'Цвет',
					'values' => [ 'красный' ],
				],
			]
		);

		$this->assertTrue( Card_Attributes::write( $product, $card ), 'the colour is new' );
		$this->assertSame( [ 'pa_brand', sanitize_title( 'Цвет' ) ], array_keys( $product->get_attributes( 'edit' ) ), 'one global, one custom: no «brand» beside «pa_brand»' );
		$this->assertSame( [ 'Brand', 'Цвет' ], array_column( Card_Attributes::of( $card, $product ), 'name' ) );
		$this->assertSame( [ 12 ], $product->get_attributes( 'edit' )['pa_brand']->get_options(), 'its terms are not touched' );
	}
}

<?php
/**
 * A variation reads the model's characteristics from its parent: what the card importer stores on the variable product.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Catalog\Source_Resolver;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Shop;

/**
 * WooCommerce's variation does not read its parent's attributes, so a linked characteristic of the model would resolve to nothing
 * for every variation — and a required one would fail validation — without the parent fallback of the resolver.
 *
 * @covers \Woodev\Framework\Marketplace\Catalog\Source_Resolver
 */
final class SourceResolverParentAttributeTest extends TestCase {
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
	 * Builds an attribute.
	 *
	 * @param string   $name      Name (the taxonomy for a global attribute).
	 * @param string[] $options   Values.
	 * @param bool     $variation Whether it is offered for variations.
	 * @return \WC_Product_Attribute
	 */
	private function attribute( string $name, array $options, bool $variation = false ): \WC_Product_Attribute {
		$attribute = new \WC_Product_Attribute();

		$attribute->set_name( $name );
		$attribute->set_options( $options );
		$attribute->set_variation( $variation );

		return $attribute;
	}

	/**
	 * Builds the variable product with some attributes and one variation that holds the given chosen values.
	 *
	 * @param \WC_Product_Attribute[] $attributes The parent's attributes.
	 * @param array<string,string>    $chosen     What the variation holds: slug => value.
	 * @return \WC_Product[] The parent and the variation.
	 */
	private function pair( array $attributes, array $chosen ): array {
		$parent = $this->shop->product( 'variable' );

		$parent->set_attributes( $attributes );
		$parent->save();

		$variation = $this->shop->product( 'variation' );

		$variation->set_parent_id( (int) $parent->get_id() );
		$variation->set_attributes( $chosen );
		$variation->save();

		return [ $parent, $variation ];
	}

	/**
	 * What a source named `$key` gives for the variation, the parent supplied.
	 *
	 * @param string      $key       The attribute named by the source.
	 * @param \WC_Product $variation Variation.
	 * @param \WC_Product $parent    Parent.
	 * @return string[]
	 */
	private function values( string $key, \WC_Product $variation, \WC_Product $parent ): array {
		return Source_Resolver::values( new Source( Source::WC_ATTRIBUTE, '', $key ), $variation, $parent );
	}

	public function test_a_variation_reads_a_model_characteristic_from_its_parent(): void {
		[ $parent, $variation ] = $this->pair( [ $this->attribute( 'Бренд', [ 'Acme' ] ), $this->attribute( 'Материал', [ 'сталь', 'алюминий' ] ) ], [] );

		$this->assertSame( [ 'Acme' ], $this->values( 'Бренд', $variation, $parent ) );
		$this->assertSame( [ 'сталь', 'алюминий' ], $this->values( 'Материал', $variation, $parent ), 'every value of a multi-value characteristic' );
		$this->assertSame( [], Source_Resolver::values( new Source( Source::WC_ATTRIBUTE, '', 'Бренд' ), $variation ), 'with no parent supplied there is nothing to read' );
	}

	public function test_the_variations_own_value_wins_over_the_parents(): void {
		[ $parent, $variation ] = $this->pair( [ $this->attribute( 'Бренд', [ 'Acme' ] ) ], [ sanitize_title( 'Бренд' ) => 'Own' ] );

		$this->assertSame( [ 'Own' ], $this->values( 'Бренд', $variation, $parent ) );
	}

	public function test_the_choices_a_parent_offers_for_variations_are_not_the_variations_value(): void {
		[ $parent, $variation ] = $this->pair( [ $this->attribute( 'Цвет', [ 'красный', 'чёрный' ], true ) ], [] );

		$this->assertSame( [], $this->values( 'Цвет', $variation, $parent ), 'a variation with no colour has none: it is not «красный, чёрный»' );
	}

	public function test_a_global_attribute_of_the_parent_is_found_by_its_name(): void {
		[ $parent, $variation ] = $this->pair( [ $this->attribute( 'pa_brand', [ 'Merchant' ] ) ], [] );

		$this->assertSame( [ 'Merchant' ], $this->values( 'Brand', $variation, $parent ) );
	}
}

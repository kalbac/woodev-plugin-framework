<?php
/**
 * Which mapping a product uses and which values override it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Category_Ancestry;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Link_Context_Resolver
 * @covers \Woodev\Framework\Marketplace\Flows\Category_Ancestry
 */
final class LinkContextResolverTest extends Export_Test_Case {
	/**
	 * Categories are walked closest first: all direct ones, then their parents, each term once, and a cycle ends.
	 *
	 * @return void
	 */
	public function test_ancestry_is_closest_first_and_survives_a_cycle(): void {
		$parents = [
			1 => 10,
			2 => 10,
			10 => 100,
			100 => 0,
		];

		$ordered = Category_Ancestry::expand(
			[ 1, 2, 1 ],
			static function ( int $term ) use ( $parents ): int {
				return $parents[ $term ] ?? 0;
			}
		);

		$this->assertSame( [ 1, 2, 10, 100 ], $ordered );

		$loop = Category_Ancestry::expand(
			[ 5 ],
			static function ( int $term ): int {
				return 5 === $term ? 6 : 5;
			}
		);

		$this->assertSame( [ 5, 6 ], $loop );
	}

	/**
	 * The mapping comes from the link's explicit id, else the parent row's, else the product's categories.
	 *
	 * @return void
	 */
	public function test_the_mapping_is_explicit_then_parent_then_category(): void {
		$explicit = $this->mappings()->create( 'Явная', null, [ 'category_ref' => 'c-2' ], null, [] );
		$parented = $this->mappings()->create( 'Родитель', null, [ 'category_ref' => 'c-3' ], null, [] );
		$resolver = new Link_Context_Resolver( $this->links(), $this->mappings() );

		$own = $this->add_product();
		$this->add_link( $own, Link_Record::STATE_UNLINKED, [ 'mapping_id' => $explicit ] );
		$this->assertSame( $explicit, $resolver->effective_mapping( $this->account_id, (int) $own->get_id(), [ self::TERM ] )->id() );

		$parent = $this->add_product( [ 'id' => 500, 'type' => 'variable' ] );
		$this->links()->insert_if_absent( Link_Record::parent_row( $this->account_id, 500, [ 'mapping_id' => $parented ] ) );
		$variation = $this->add_product( [ 'parent_id' => 500 ] );
		$this->assertSame( $parented, $resolver->effective_mapping( $this->account_id, (int) $variation->get_id(), [ self::TERM ], 500 )->id(), 'a variation without a row follows its parent row' );

		$plain = $this->add_product();
		$this->assertSame( $this->mapping_id, $resolver->effective_mapping( $this->account_id, (int) $plain->get_id(), [ 999, self::TERM ] )->id() );
		$this->assertNull( $resolver->effective_mapping( $this->account_id, (int) $plain->get_id(), [ 999 ] ) );
		$this->assertSame( $parent->get_id(), 500 );
	}

	/**
	 * A variation's own override wins over the parent's, attribute by attribute.
	 *
	 * @return void
	 */
	public function test_the_variations_override_wins_over_the_parents(): void {
		$resolver = new Link_Context_Resolver( $this->links(), $this->mappings() );

		$this->links()->insert_if_absent(
			Link_Record::parent_row(
				$this->account_id,
				600,
				[
					'attribute_overrides' => [
						'1' => [ [ 'dictionary_value_id' => 7, 'value' => 'parent' ] ],
						'2' => [ [ 'dictionary_value_id' => 0, 'value' => 'only parent' ] ],
					],
				]
			)
		);
		$variation = $this->add_product( [ 'parent_id' => 600 ] );
		$this->add_link( $variation, Link_Record::STATE_UNLINKED, [ 'attribute_overrides' => [ '1' => [ [ 'dictionary_value_id' => 0, 'value' => 'own' ] ] ] ] );

		$values = $resolver->override_values( $this->account_id, (int) $variation->get_id() );
		$this->assertSame( 'own', $values['1'][0]['value'] );
		$this->assertSame( 'only parent', $values['2'][0]['value'] );

		$mapping = $resolver->effective_mapping( $this->account_id, (int) $variation->get_id(), [ self::TERM ] );
		$this->assertSame( Source::FIXED, $mapping->source_for( '1' )->kind() );
		$this->assertSame( 'own', $mapping->source_for( '1' )->value() );
	}
}

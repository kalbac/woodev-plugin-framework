<?php
/**
 * The category descriptors that mappings use.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Flows\Mapped_Pairs;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Mapped_Pairs
 */
final class MappedPairsTest extends Export_Test_Case {
	/**
	 * Builds the reader over the test database.
	 *
	 * @return Mapped_Pairs
	 */
	private function pairs(): Mapped_Pairs {
		return new Mapped_Pairs( $this->db, Sqlite_Tables::table( 'mappings' ) );
	}

	/**
	 * Each descriptor is listed once, whatever the order its keys were written in, and in the order of its cache key; a mapping
	 * without a category yet is left out.
	 *
	 * @return void
	 */
	public function test_distinct_descriptors_in_a_stable_order(): void {
		$this->mappings()->create( 'Тетради', null, [ 'type_ref' => 22, 'category_ref' => 11 ], null, [] );
		$this->mappings()->create( 'Тетради ещё раз', null, [ 'category_ref' => 11, 'type_ref' => 22 ], null, [] );
		$this->mappings()->create( 'Ручки', null, [ 'category_ref' => 5, 'type_ref' => 9 ], null, [] );
		$this->mappings()->create( 'Без категории', null, [], null, [] );

		$this->assertSame(
			[
				[ 'category_ref' => 11, 'type_ref' => 22 ],
				[ 'category_ref' => 5, 'type_ref' => 9 ],
				[ 'category_ref' => 'c-1' ],
			],
			$this->pairs()->all()
		);
	}

	/**
	 * Shops that map nothing give an empty list.
	 *
	 * @return void
	 */
	public function test_no_mappings_no_descriptors(): void {
		$this->db->query( 'DELETE FROM ' . Sqlite_Tables::table( 'mappings' ) );

		$this->assertSame( [], $this->pairs()->all() );
	}
}

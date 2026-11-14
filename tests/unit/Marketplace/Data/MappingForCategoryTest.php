<?php
/**
 * The mapping a marketplace card belongs to, found backwards from the card's category descriptor.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * `Wpdb_Mapping_Store::for_category()` compares the descriptor as a value, so the adapter's key order does not matter.
 */
final class MappingForCategoryTest extends TestCase {
	/**
	 * Mapping store.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Boots the stubs and the tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );
		$this->mappings = new Wpdb_Mapping_Store( $db, Sqlite_Tables::table( 'mappings' ), Sqlite_Tables::table( 'mapping_terms' ) );
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
	 * A stored descriptor matches whatever the key order of the adapter's.
	 *
	 * @return void
	 */
	public function test_the_key_order_of_the_descriptor_does_not_matter(): void {
		$id = $this->mappings->create( 'Футболки', null, [ 'description_category_id' => 17028973, 'type_id' => 91565 ], 7, [] );
		$this->mappings->link_term_if_absent( 31, $id );
		$this->mappings->link_term_if_absent( 32, $id );

		$found = $this->mappings->for_category( [ 'type_id' => 91565, 'description_category_id' => 17028973 ] );

		$this->assertNotNull( $found );
		$this->assertSame( $id, $found['mapping']['mapping_id'] );
		$this->assertSame( 7, $found['mapping']['profile_id'] );
		$this->assertSame( [ 31, 32 ], $found['term_ids'] );
	}

	/**
	 * Numbers and numeric strings are the same id, and nested keys are sorted too.
	 *
	 * @return void
	 */
	public function test_scalar_types_and_nested_key_order_do_not_matter(): void {
		$id = $this->mappings->create( 'Вложенное', null, [ 'ids' => [ 'b' => 2, 'a' => 1 ], 'name' => 'x' ], null, [] );
		$this->mappings->link_term_if_absent( 40, $id );

		$found = $this->mappings->for_category( [ 'name' => 'x', 'ids' => [ 'a' => '1', 'b' => '2' ] ] );

		$this->assertNotNull( $found );
		$this->assertSame( $id, $found['mapping']['mapping_id'] );
	}

	/**
	 * The lowest mapping id wins when two mappings hold the same category.
	 *
	 * @return void
	 */
	public function test_the_lowest_mapping_id_wins(): void {
		$first  = $this->mappings->create( 'Первое', null, [ 'type_id' => 5 ], 1, [] );
		$second = $this->mappings->create( 'Второе', null, [ 'type_id' => 5 ], 2, [] );
		$this->mappings->link_term_if_absent( 50, $second );
		$this->mappings->link_term_if_absent( 51, $first );

		$this->assertSame( $first, $this->mappings->for_category( [ 'type_id' => 5 ] )['mapping']['mapping_id'] );
	}

	/**
	 * An empty descriptor, an unknown one, a mapping with no shop category and a product-scoped mapping find nothing.
	 *
	 * @return void
	 */
	public function test_nothing_is_found_without_a_term_bound_mapping_that_owns_a_category(): void {
		$this->mappings->create( 'Без категорий магазина', null, [ 'type_id' => 6 ], null, [] );
		$scoped = $this->mappings->create( 'Одного товара', 99, [ 'type_id' => 7 ], null, [] );
		$this->mappings->link_term_if_absent( 60, $scoped );

		$this->assertNull( $this->mappings->for_category( [] ) );
		$this->assertNull( $this->mappings->for_category( [ 'type_id' => 404 ] ) );
		$this->assertNull( $this->mappings->for_category( [ 'type_id' => 6 ] ) );
		$this->assertNull( $this->mappings->for_category( [ 'type_id' => 7 ] ) );
	}
}

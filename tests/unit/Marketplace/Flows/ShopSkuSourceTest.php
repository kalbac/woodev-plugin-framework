<?php
/**
 * The shop's products with an article, read from WooCommerce's lookup table.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Flows\Shop_Sku_Source;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * The table layout is WordPress's and WooCommerce's, made by hand in SQLite: what matters here is which rows the query keeps.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Shop_Sku_Source
 */
final class ShopSkuSourceTest extends TestCase {
	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Builds the tables and the shop.
	 *
	 * Products: 10 simple (category 5), 20 variable with variations 21 and 22 (category 6), 30 variable without a variation yet
	 * (category 6), 40 trashed, 50 simple without an article, 60 simple in category 7 (a child of 5).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->db                     = new Sqlite_Wpdb();
		$this->db->posts              = 'wp_posts';
		$this->db->term_relationships = 'wp_term_relationships';
		$this->db->term_taxonomy      = 'wp_term_taxonomy';

		$this->db->define( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_parent INTEGER, post_type TEXT, post_status TEXT)' );
		$this->db->define( 'CREATE TABLE wp_wc_product_meta_lookup (product_id INTEGER PRIMARY KEY, sku TEXT)' );
		$this->db->define( 'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)' );
		$this->db->define( 'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT)' );

		$posts = [
			[ 10, 0, 'product', 'publish', 'SIMPLE-10' ],
			[ 20, 0, 'product', 'publish', '' ],
			[ 21, 20, 'product_variation', 'publish', 'VAR-21' ],
			[ 22, 20, 'product_variation', 'private', 'VAR-22' ],
			[ 30, 0, 'product', 'publish', 'EMPTY-VARIABLE-30' ],
			[ 40, 0, 'product', 'trash', 'TRASHED-40' ],
			[ 50, 0, 'product', 'publish', '' ],
			[ 60, 0, 'product', 'draft', 'SIMPLE-60' ],
		];

		foreach ( $posts as list( $id, $parent, $type, $status, $sku ) ) {
			$this->db->query( "INSERT INTO wp_posts VALUES ({$id}, {$parent}, '{$type}', '{$status}')" );
			$this->db->query( "INSERT INTO wp_wc_product_meta_lookup VALUES ({$id}, '{$sku}')" );
		}

		foreach ( [ [ 1, 5 ], [ 2, 6 ], [ 3, 7 ] ] as list( $taxonomy_id, $term_id ) ) {
			$this->db->query( "INSERT INTO wp_term_taxonomy VALUES ({$taxonomy_id}, {$term_id}, 'product_cat')" );
		}

		foreach ( [ [ 10, 1 ], [ 20, 2 ], [ 30, 2 ], [ 60, 3 ] ] as list( $object_id, $taxonomy_id ) ) {
			$this->db->query( "INSERT INTO wp_term_relationships VALUES ({$object_id}, {$taxonomy_id})" );
		}

		Functions\when( 'get_term_children' )->alias(
			static function ( int $term_id ): array {
				return 5 === $term_id ? [ 7 ] : [];
			}
		);
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
	 * The whole shop: simple products and variations with an article, never a variable parent, never a trashed or article-less row.
	 * A variable product with no variation yet counts as simple (it has an article of its own).
	 *
	 * @return void
	 */
	public function test_the_whole_shop_lists_what_can_carry_a_card(): void {
		$list = ( new Shop_Sku_Source( $this->db ) )->products();

		$this->assertSame( [ 10, 21, 22, 30, 60 ], array_column( $list, 'id' ) );
		$this->assertSame( [ 0, 20, 20, 0, 0 ], array_column( $list, 'parent_id' ) );
		$this->assertSame( [ 'SIMPLE-10', 'VAR-21', 'VAR-22', 'EMPTY-VARIABLE-30', 'SIMPLE-60' ], array_column( $list, 'sku' ) );
	}

	/**
	 * A category brings its children; a variation belongs to its parent's categories.
	 *
	 * @return void
	 */
	public function test_a_category_scopes_the_list_to_its_subtree(): void {
		$source = new Shop_Sku_Source( $this->db );

		$this->assertSame( [ 10, 60 ], array_column( $source->products( 5 ), 'id' ), 'category 5 and its child 7' );
		$this->assertSame( [ 21, 22, 30 ], array_column( $source->products( 6 ), 'id' ), 'the variations follow the variable parent' );
		$this->assertSame( [ 60 ], array_column( $source->products( 7 ), 'id' ) );
	}
}

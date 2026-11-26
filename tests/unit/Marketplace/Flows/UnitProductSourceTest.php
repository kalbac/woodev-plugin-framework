<?php
/**
 * The shop's products a unit card may be found among.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Flows\Unit_Product_Source;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * Runs the source's real SQL on SQLite over the three WordPress tables it reads.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Unit_Product_Source
 */
final class UnitProductSourceTest extends Export_Test_Case {
	protected function setUp(): void {
		parent::setUp();

		$this->db->posts    = 'wp_posts';
		$this->db->postmeta = 'wp_postmeta';
		$this->db->define( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_parent INTEGER, post_type TEXT, post_status TEXT)' );
		$this->db->define( 'CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$this->db->define( 'CREATE TABLE wp_wc_product_meta_lookup (product_id INTEGER PRIMARY KEY, sku TEXT)' );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'do_action' )->justReturn( null );

		$this->profiles()->create( 'Со своим полем', [ 'barcode_source' => 'meta:_my_barcode' ] );

		$posts = [
			[ 1, 0, 'product', 'publish', 'A' ],
			[ 2, 0, 'product', 'publish', 'PARENT' ],
			[ 3, 2, 'product_variation', 'publish', '' ],
			[ 4, 0, 'product', 'draft', '' ],
			[ 5, 0, 'product', 'trash', 'TRASHED' ],
			[ 6, 0, 'product', 'publish', '' ],
			[ 7, 0, 'product', 'draft', '' ],
			[ 8, 0, 'product', 'draft', 'FOREIGN' ],
			[ 9, 0, 'product', 'draft', '' ],
		];

		foreach ( $posts as list( $id, $parent, $type, $status, $sku ) ) {
			$this->db->query( "INSERT INTO wp_posts VALUES ({$id}, {$parent}, '{$type}', '{$status}')" );
			$this->db->query( "INSERT INTO wp_wc_product_meta_lookup VALUES ({$id}, '{$sku}')" );
		}

		$meta = [
			[ 3, '_global_unique_id', '4006381333931' ],
			[ 4, '_my_barcode', '12345Ejf5' ],
			[ 4, '_another_field', 'not a barcode' ],
			[ 7, '_woodev_fake_import_unit', $this->account_id . ':coat|M' ],
			[ 8, '_woodev_fake_import_unit', '99:coat|M' ],
			[ 9, '_woodev_fake_unit', $this->account_id . ':coat|L' ],
			[ 1, '_woodev_fake_unit', '99:coat|L' ],
		];

		foreach ( $meta as list( $id, $key, $value ) ) {
			$this->db->query( $this->db->prepare( 'INSERT INTO wp_postmeta VALUES (%d, %s, %s)', $id, $key, $value ) );
		}
	}

	/**
	 * The source.
	 *
	 * @return Unit_Product_Source
	 */
	private function source(): Unit_Product_Source {
		return new Unit_Product_Source( $this->db, $this->profiles(), Sqlite_Tables::MARKETPLACE_ID );
	}

	/**
	 * Products with a SKU, a GTIN, a profile's barcode field, or a unit of this account being or been imported into them; never a
	 * variable parent, a trashed or an empty one, nor one another account's import is still making.
	 *
	 * @return void
	 */
	public function test_the_whole_shop_lists_what_a_key_could_find(): void {
		$products = array_column( $this->source()->products( null, $this->account_id ), null, 'id' );

		$this->assertSame( [ 1, 3, 4, 7, 9 ], array_keys( $products ) );
		$this->assertSame( 'coat|L', $products[9]['made_for'] );
		$this->assertSame( '', $products[1]['made_for'], 'another account made it: an ordinary product here' );
		$this->assertSame( [ '4006381333931' ], $products[3]['barcodes'] );
		$this->assertSame( 2, $products[3]['parent_id'] );
		$this->assertSame( [ '12345Ejf5' ], $products[4]['barcodes'] );
		$this->assertSame( 'coat|M', $products[7]['importing'] );
	}

	/**
	 * Only the products some value or pending unit could find.
	 *
	 * @return void
	 */
	public function test_matching_reads_only_the_products_the_cards_could_find(): void {
		$ids = static function ( array $products ): array {
			return array_column( $products, 'id' );
		};

		$this->assertSame( [ 1, 4 ], $ids( $this->source()->matching( $this->account_id, [ 'A', '12345Ejf5', 'TRASHED', 'not a barcode', '' ], [] ) ) );
		$this->assertSame( [ 3, 7 ], $ids( $this->source()->matching( $this->account_id, [ '4006381333931' ], [ 'coat|M' ] ) ) );
		$this->assertSame( [ 9 ], $ids( $this->source()->matching( $this->account_id, [], [ 'coat|L' ] ) ) );
		$this->assertSame( [], $this->source()->matching( $this->account_id, [ 'FOREIGN' ], [] ), 'half-made by another account: not even by its SKU' );
		$this->assertSame( [], $this->source()->matching( $this->account_id, [], [] ) );
	}
}

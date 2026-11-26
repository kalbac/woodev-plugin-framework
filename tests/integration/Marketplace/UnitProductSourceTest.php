<?php
/**
 * The unit product lookup on a real WordPress, WooCommerce and MySQL: the posts, the SKU lookup table and the post meta it joins,
 * under the server's collation and SQL mode.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Unit_Product_Source;
use Woodev\Framework\Marketplace\Flows\Unit_Products;
use Woodev\Tests\Integration\TestCase;

class UnitProductSourceTest extends TestCase {
	private const MARKETPLACE = 'itestunits';

	/**
	 * Schema under test.
	 *
	 * @var Schema
	 */
	private $schema;

	protected function setUp(): void {
		$this->schema = new Schema( self::MARKETPLACE );
		$this->drop_tables();
		( new Lifecycle( $this->get_test_plugin(), $this->schema ) )->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	protected function tearDown(): void {
		parent::tearDown();

		$this->drop_tables();
	}

	public function test_it_finds_products_by_sku_barcode_field_and_pending_mark(): void {
		global $wpdb;

		$profiles = new Wpdb_Profile_Store( $wpdb, $this->schema->table_name( 'profiles', $wpdb->prefix ), Wpdb_Profile_Store::price_changed_action( self::MARKETPLACE ) );
		$profiles->create( 'Свой штрихкод', [ 'barcode_source' => 'meta:_my_barcode' ] );

		$by_sku     = $this->product( 'UNIT-SKU', [] );
		$by_barcode = $this->product( '', [ '_my_barcode' => '12345Ejf5' ] );
		$pending    = $this->product( '', [ Unit_Products::pending_key( self::MARKETPLACE ) => '5:coat|M' ] );
		$source     = new Unit_Product_Source( $wpdb, $profiles, self::MARKETPLACE );

		$found = array_column( $source->matching( 5, [ 'UNIT-SKU', '12345Ejf5' ], [ 'coat|M' ] ), null, 'id' );

		$this->assertSame( [ $by_sku, $by_barcode, $pending ], array_keys( $found ) );
		$this->assertSame( 'UNIT-SKU', $found[ $by_sku ]['sku'] );
		$this->assertSame( [ '12345Ejf5' ], $found[ $by_barcode ]['barcodes'] );
		$this->assertSame( 'coat|M', $found[ $pending ]['importing'] );
		$this->assertEmpty( array_diff( [ $by_sku, $by_barcode, $pending ], array_column( $source->products( null, 5 ), 'id' ) ) );
	}

	/**
	 * Saves a simple product.
	 *
	 * @param string               $sku  SKU.
	 * @param array<string,string> $meta Meta.
	 * @return int
	 */
	private function product( string $sku, array $meta ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Товар' );
		$product->set_sku( $sku );

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		return $product->save();
	}

	private function drop_tables(): void {
		global $wpdb;

		foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}
}

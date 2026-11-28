<?php
/**
 * The delivery scheme column and filter of the «Заказы» table.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Filter;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * Only a marketplace with more than one scheme gets the column and the filter, and the filter reads the registry on both order
 * stores.
 *
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Definition
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Filter
 */
final class SiteOrdersSchemeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_date' )->justReturn( '2026-11-28' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	public function test_a_marketplace_with_two_schemes_gets_the_column_and_the_filter(): void {
		$definition = self::definition( [ 'fbs' => 'FBS', 'dbs' => 'DBS' ] );

		$this->assertSame( [ 'order', 'posting', 'scheme' ], array_slice( array_column( $definition['columns'], 'id' ), 0, 3 ) );
		$filter = $definition['filters'][ array_search( 'scheme', array_column( $definition['filters'], 'id' ), true ) ];
		$this->assertSame(
			[
				[
					'value' => 'fbs',
					'label' => 'FBS',
				],
				[
					'value' => 'dbs',
					'label' => 'DBS',
				],
			],
			$filter['options']
		);
	}

	public function test_one_scheme_or_none_adds_nothing(): void {
		foreach ( [ [], [ 'fbs' => 'FBS' ] ] as $schemes ) {
			$definition = self::definition( $schemes );

			$this->assertNotContains( 'scheme', array_column( $definition['columns'], 'id' ) );
			$this->assertNotContains( 'scheme', array_column( $definition['filters'], 'id' ) );
		}
	}

	public function test_the_filter_takes_only_a_scheme_code(): void {
		$this->assertSame( 'dbs', Site_Orders_Filter::from_query( 1, [ 'filters' => [ 'scheme' => 'dbs' ] ] )->scheme() );
		$this->assertSame( '', Site_Orders_Filter::from_query( 1, [ 'filters' => [ 'scheme' => "dbs' OR 1" ] ] )->scheme() );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_reader_filters_by_scheme( bool $hpos ): void {
		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );
		$shop  = new Orders_Shop( $db, $hpos );
		$table = Sqlite_Tables::table( 'postings' );
		$db->query( "UPDATE {$table} SET scheme = 'fbs'" );
		$db->query( "UPDATE {$table} SET scheme = 'dbs' WHERE external_key IN ('P-102', 'P-104')" );

		$reader = new Site_Orders_Reader( $db, $table, $shop->storage() );
		$page   = $reader->page( Site_Orders_Filter::from_query( Orders_Shop::ACCOUNT, [ 'filters' => [ 'scheme' => 'dbs' ] ] ), 1, 20 );

		$this->assertSame( [ 104, 102 ], array_column( $page['items'], 'order_id' ) );
		$this->assertSame( [ 'dbs', 'dbs' ], array_column( $page['items'], 'scheme' ) );
		$this->assertSame( 2, $page['total'] );
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public function storages(): array {
		return [
			'posts table (CPT)' => [ false ],
			'HPOS table'        => [ true ],
		];
	}

	private static function definition( array $schemes ): array {
		return Site_Orders_Definition::build( [], [], [ 'open' ], null, null, null, false, $schemes );
	}
}

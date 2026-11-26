<?php
/**
 * Unit tests for the units the registry order writer folds into a grouped order.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Orders;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Orders\Order_Store;
use Woodev\Framework\Marketplace\Orders\Registry_Order_Writer;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * One line per live unit, each named after its unit; every unit, the cancelled ones included, travels beside the lines. A posting
 * that is one order has neither. The registry is the real one, on SQLite.
 *
 * @covers \Woodev\Framework\Marketplace\Orders\Registry_Order_Writer
 * @covers \Woodev\Framework\Marketplace\Orders\Order_DTO
 * @covers \Woodev\Framework\Marketplace\Orders\Order_Line
 */
final class RegistryOrderWriterUnitsTest extends TestCase {
	/**
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * What the store was asked to write, newest last.
	 *
	 * @var Order_DTO[]
	 */
	private $written = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db      = new Sqlite_Wpdb();
		$this->written = [];
		Sqlite_Tables::install( $this->db );
	}

	public function test_a_cancelled_member_loses_its_line_and_stays_among_the_units(): void {
		$writer = $this->writer();

		$writer->upsert_member( self::member( '11', Status_Map::NEW ), new Status_Map() );
		$writer->upsert_member( self::member( '12', Status_Map::NEW ), new Status_Map() );
		$writer->upsert_member( self::member( '11', Status_Map::CANCELLED ), new Status_Map() );

		$order = end( $this->written );

		$this->assertSame( [ '12' ], self::units_of_lines( $order ) );
		$this->assertSame( [ '11', '12' ], self::ids( $order->units() ) );
		$this->assertTrue( $order->units()[0]->is_cancelled() );
		$this->assertSame( '100.00', $order->totals()['items']->to_string() );
	}

	public function test_two_equal_units_are_two_lines_each_of_its_own_unit(): void {
		$writer = $this->writer();

		$writer->upsert_member( self::member( '21', Status_Map::NEW ), new Status_Map() );
		$writer->upsert_member( self::member( '22', Status_Map::NEW ), new Status_Map() );

		$order = end( $this->written );

		$this->assertSame( [ '21', '22' ], self::units_of_lines( $order ) );
		$this->assertSame( '22', $order->lines()[1]->with_quantity( 3 )->unit_id(), 'A copy keeps its unit.' );
	}

	public function test_an_order_of_cancelled_units_only_is_cancelled_and_has_no_lines(): void {
		$writer = $this->writer();

		$writer->upsert_member( self::member( '31', Status_Map::CANCELLED ), new Status_Map() );

		$order = end( $this->written );

		$this->assertSame( [], $order->lines() );
		$this->assertSame( Status_Map::CANCELLED, $order->status_canonical() );
		$this->assertSame( [ '31' ], self::ids( $order->units() ) );
	}

	public function test_a_one_order_posting_has_no_units(): void {
		$this->writer()->upsert( self::member( '41', Status_Map::NEW ), new Status_Map() );

		$order = end( $this->written );

		$this->assertSame( [], $order->units() );
		$this->assertSame( [ '' ], self::units_of_lines( $order ) );
	}

	private function writer(): Registry_Order_Writer {
		$wc_order = \Mockery::mock( '\WC_Order' );
		$wc_order->shouldReceive( 'get_id' )->andReturn( 700 );
		$wc_order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		$store = \Mockery::mock( Order_Store::class );
		$store->shouldReceive( 'find' )->andReturn( null );
		$store->shouldReceive( 'create' )->andReturn( $wc_order );
		$store->shouldReceive( 'get' )->andReturn( $wc_order );
		$store->shouldReceive( 'is_provisional' )->andReturn( false );
		$store->shouldReceive( 'update' )->andReturnUsing(
			function ( \WC_Order $order, Order_DTO $dto ): void {
				$this->written[] = $dto;
			}
		);

		return new Registry_Order_Writer(
			new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) ),
			$store,
			static function (): string {
				return 'wc-processing';
			}
		);
	}

	private static function member( string $id, string $canonical ): Order_DTO {
		$line = new Order_Line( 'tee|M', [ 'item' => 1 ], 1, Money::from_string( '100.00', 'RUB' ), 'Футболка' );

		return new Order_DTO( 1, $id, 'cart-1', $canonical, $canonical, [ $line ] );
	}

	/**
	 * @param Order_DTO $order Order.
	 * @return string[]
	 */
	private static function units_of_lines( Order_DTO $order ): array {
		return array_map(
			static function ( Order_Line $line ): string {
				return $line->unit_id();
			},
			$order->lines()
		);
	}

	/**
	 * @param Order_DTO[] $units Units.
	 * @return string[]
	 */
	private static function ids( array $units ): array {
		return array_map(
			static function ( Order_DTO $unit ): string {
				return $unit->external_id();
			},
			$units
		);
	}
}

<?php
/**
 * Unit tests for the delivery and the scheme the registry order writer carries.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Orders;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Orders\Order_Store;
use Woodev\Framework\Marketplace\Orders\Registry_Order_Writer;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * The folded order carries the delivery of the member being written, never of an outdated snapshot, and the registry row keeps the
 * scheme.
 *
 * @covers \Woodev\Framework\Marketplace\Orders\Registry_Order_Writer
 * @covers \Woodev\Framework\Marketplace\Orders\Order_Registry
 */
final class RegistryOrderWriterDeliveryTest extends TestCase {
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

	public function test_the_folded_order_carries_the_members_delivery_and_scheme(): void {
		$writer = $this->writer();

		$writer->upsert_member( self::member( 'u1', '2026-11-02T10:00:00Z', 'first' ), new Status_Map() );
		$writer->upsert_member( self::member( 'u2', '2026-11-02T10:00:00Z', 'second' ), new Status_Map() );

		$order = end( $this->written );

		$this->assertSame( 'dbs', $order->scheme() );
		$this->assertSame( 'second', $order->delivery()->recipient() );
		$this->assertSame( 'second', $order->units()[1]->delivery()->recipient() );
		$this->assertNull( $order->units()[0]->delivery(), 'A member rebuilt from the registry carries none.' );
		$this->assertSame( 'dbs', $this->stored_scheme() );
	}

	public function test_an_outdated_snapshot_brings_no_delivery(): void {
		$writer = $this->writer();

		$writer->upsert_member( self::member( 'u1', '2026-11-03T10:00:00Z', 'new' ), new Status_Map() );
		$writer->upsert_member( self::member( 'u1', '2026-11-02T10:00:00Z', 'old' ), new Status_Map() );

		$this->assertNull( end( $this->written )->delivery() );
	}

	public function test_a_read_without_a_scheme_keeps_the_stored_one(): void {
		$writer = $this->writer();

		$writer->upsert_member( self::member( 'u1', '2026-11-02T10:00:00Z', 'a' ), new Status_Map() );
		$writer->upsert_member( new Order_DTO( 1, 'u1', 'cart-1', 'new', Status_Map::NEW, [], [], null, [ 'updated_at' => '2026-11-03T10:00:00Z' ] ), new Status_Map() );

		$this->assertSame( 'dbs', $this->stored_scheme() );
	}

	public function test_a_one_order_posting_keeps_its_delivery_too(): void {
		$this->writer()->upsert( self::member( 'u1', '2026-11-02T10:00:00Z', 'a' ), new Status_Map() );

		$this->assertSame( 'a', end( $this->written )->delivery()->recipient() );
		$this->assertSame( 'dbs', $this->stored_scheme() );
	}

	private function stored_scheme(): string {
		return (string) $this->db->get_var( 'SELECT scheme FROM ' . Sqlite_Tables::table( 'postings' ) );
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

	private static function member( string $id, string $updated_at, string $recipient ): Order_DTO {
		$order = new Order_DTO( 1, $id, 'cart-1', 'new', Status_Map::NEW, [], [], null, [ 'updated_at' => $updated_at ] );

		return $order->with_scheme( 'dbs' )->with_delivery( ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )->with_recipient( $recipient ) );
	}
}

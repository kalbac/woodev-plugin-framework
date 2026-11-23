<?php
/**
 * Unit tests for the optional «processing since» date of a posting (#1933): what the DTO makes of it and how the registry keeps it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Orders;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Orders\Order_Store;
use Woodev\Framework\Marketplace\Orders\Registry_Order_Writer;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * An adapter that knows when the marketplace took a posting into processing puts it among the DTO's dates as `processing_since`
 * (Ozon: `in_process_at`). The registry stores it in GMT beside `first_seen_at`; a refresh that does not carry it leaves the stored one,
 * and one that carries a new one replaces it. The registry and the writer are the real ones, on SQLite.
 */
final class OrderProcessingSinceTest extends TestCase {
	private const KEY = '77000001-0001-1';

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	protected function setUp(): void {
		parent::setUp();

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
	}

	/**
	 * A posting with the given dates.
	 *
	 * @param array<string,string> $dates Dates.
	 * @return Order_DTO
	 */
	private function posting( array $dates ): Order_DTO {
		return new Order_DTO( 1, self::KEY, self::KEY, 'awaiting_packaging', Status_Map::AWAITING_SHIPMENT, [], [], null, $dates );
	}

	/**
	 * Writes a posting as a new order, the store being a double that creates order 700.
	 *
	 * @param Order_DTO $order Posting.
	 * @return void
	 */
	private function write( Order_DTO $order ): void {
		$wc_order = \Mockery::mock( '\WC_Order' );
		$wc_order->shouldReceive( 'get_id' )->andReturn( 700 );
		$wc_order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		$store = \Mockery::mock( Order_Store::class );
		$store->shouldReceive( 'find' )->andReturn( null );
		$store->shouldReceive( 'get' )->andReturn( $wc_order );
		$store->shouldReceive( 'create' )->andReturn( $wc_order );
		$store->shouldReceive( 'is_provisional' )->andReturn( false );
		$store->shouldReceive( 'update' );

		$writer = new Registry_Order_Writer(
			new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) ),
			$store,
			static function (): string {
				return 'wc-processing';
			}
		);

		$writer->upsert( $order, new Status_Map() );
	}

	/**
	 * What the registry row holds.
	 *
	 * @return string The date; an empty string while the column is empty.
	 */
	private function stored(): string {
		return (string) $this->db->get_var( $this->db->prepare( 'SELECT processing_since FROM %i WHERE account_id = 1 AND external_key = %s', Sqlite_Tables::table( 'postings' ), self::KEY ) );
	}

	public function test_the_dto_gives_the_date_in_gmt(): void {
		$this->assertSame( '2026-11-05 08:00:00', $this->posting( [ 'processing_since' => '2026-11-05T11:00:00+03:00' ] )->processing_since() );
		$this->assertSame( '2026-11-05 08:00:00', $this->posting( [ 'processing_since' => '2026-11-05T08:00:00.250Z' ] )->processing_since() );
	}

	public function test_the_dto_without_the_date_or_with_a_value_that_is_not_a_time_has_none(): void {
		$this->assertNull( $this->posting( [] )->processing_since() );
		$this->assertNull( $this->posting( [ 'created_at' => '2026-11-05T08:00:00Z' ] )->processing_since() );
		$this->assertNull( $this->posting( [ 'processing_since' => '2026-11-05 08:00:00' ] )->processing_since(), 'No zone: not a time the layer reads.' );
		$this->assertNull( $this->posting( [ 'processing_since' => 'yesterday' ] )->processing_since() );
	}

	public function test_a_posting_that_carries_the_date_is_stored_with_it_and_first_seen_stays_apart(): void {
		$this->write( $this->posting( [ 'processing_since' => '2026-11-05T11:00:00+03:00' ] ) );

		$this->assertSame( '2026-11-05 08:00:00', $this->stored() );

		$first_seen = (string) $this->db->get_var( $this->db->prepare( 'SELECT first_seen_at FROM %i WHERE external_key = %s', Sqlite_Tables::table( 'postings' ), self::KEY ) );

		$this->assertNotSame( '', $first_seen, 'first_seen_at keeps its service use.' );
	}

	public function test_a_posting_without_the_date_leaves_the_column_empty(): void {
		$this->write( $this->posting( [] ) );

		$this->assertSame( '', $this->stored() );
	}

	public function test_a_refresh_without_the_date_keeps_the_stored_one_and_a_new_date_replaces_it(): void {
		$this->write( $this->posting( [ 'processing_since' => '2026-11-05T08:00:00Z' ] ) );
		$this->write( $this->posting( [] ) );

		$this->assertSame( '2026-11-05 08:00:00', $this->stored(), 'An answer that does not say leaves what was said before.' );

		$this->write( $this->posting( [ 'processing_since' => '2026-11-06T09:30:00Z' ] ) );

		$this->assertSame( '2026-11-06 09:30:00', $this->stored(), 'The marketplace moved the date: the registry follows.' );
	}
}

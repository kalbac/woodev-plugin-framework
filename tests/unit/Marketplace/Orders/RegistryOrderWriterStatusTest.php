<?php
/**
 * Unit tests for the WooCommerce status rule of the registry order writer (#1920).
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
 * An existing order takes its mapped WooCommerce status only when the marketplace status of its posting changed since the last
 * refresh the registry row recorded; a new order always does. What the store is asked to set is what the order ends up with
 * (an order already in that status is left as it is). The registry is the real one, on SQLite.
 */
final class RegistryOrderWriterStatusTest extends TestCase {
	private const KEY = '77000001-0001-1';

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * The status the store was asked to set; null until `update()` is reached.
	 *
	 * @var string|null
	 */
	private $applied;

	/**
	 * Opens the database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db      = new Sqlite_Wpdb();
		$this->applied = null;
		Sqlite_Tables::install( $this->db );
	}

	/**
	 * Registers a posting with its order, as a previous refresh left it.
	 *
	 * @param string $last_seen Marketplace status the row saw last; '' for a row that saw none.
	 * @return void
	 */
	private function seed_row( string $last_seen ): void {
		$this->db->insert(
			Sqlite_Tables::table( 'postings' ),
			[
				'account_id'    => 1,
				'external_key'  => self::KEY,
				'wc_order_id'   => 700,
				'status_raw'    => $last_seen,
				'first_seen_at' => '2026-11-20 10:00:00',
			]
		);
	}

	/**
	 * Reads the status the registry row holds.
	 *
	 * @return string
	 */
	private function recorded_status(): string {
		return ( new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) ) )->find( 1, self::KEY )['status_raw'];
	}

	/**
	 * Writes a posting over an order that already exists, in the given WooCommerce status.
	 *
	 * @param string $wc_status   WooCommerce status of the order now, without `wc-`.
	 * @param string $raw         Marketplace status of the posting now.
	 * @param string $canonical   Its canonical status.
	 * @param bool   $provisional Whether the order still waits for its first real status.
	 * @return void
	 */
	private function write_existing( string $wc_status, string $raw, string $canonical, bool $provisional = false ): void {
		$wc_order = \Mockery::mock( '\WC_Order' );
		$wc_order->shouldReceive( 'get_status' )->andReturn( $wc_status );

		$store = \Mockery::mock( Order_Store::class );
		$store->shouldReceive( 'get' )->with( 700 )->andReturn( $wc_order );
		$store->shouldReceive( 'is_provisional' )->andReturn( $provisional );
		$this->expect_update( $store );

		$this->writer( $store )->upsert( $this->posting( $raw, $canonical ), new Status_Map() );
	}

	/**
	 * Makes the store record the status it is given.
	 *
	 * @param \Mockery\MockInterface $store Store double.
	 * @return void
	 */
	private function expect_update( $store ): void {
		$store->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( \WC_Order $wc_order, Order_DTO $order, string $wc_status ): void {
				$this->applied = $wc_status;
			}
		);
	}

	/**
	 * The writer over the real registry; the account's «Статус нового заказа» is `processing`.
	 *
	 * @param Order_Store $store Store double.
	 * @return Registry_Order_Writer
	 */
	private function writer( Order_Store $store ): Registry_Order_Writer {
		return new Registry_Order_Writer(
			new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) ),
			$store,
			static function (): string {
				return 'wc-processing';
			}
		);
	}

	/**
	 * A posting in the given marketplace status.
	 *
	 * @param string $raw       Marketplace status.
	 * @param string $canonical Canonical status.
	 * @return Order_DTO
	 */
	private function posting( string $raw, string $canonical ): Order_DTO {
		return new Order_DTO( 1, self::KEY, self::KEY, $raw, $canonical, [] );
	}

	public function test_a_manual_status_survives_a_repeated_marketplace_status(): void {
		$this->seed_row( 'awaiting_packaging' );

		$this->write_existing( 'on-hold', 'awaiting_packaging', Status_Map::AWAITING_SHIPMENT );

		$this->assertSame( 'on-hold', $this->applied );
	}

	public function test_a_real_transition_applies_the_mapped_status_over_a_manual_one(): void {
		$this->seed_row( 'awaiting_packaging' );

		$this->write_existing( 'on-hold', 'delivering', Status_Map::DELIVERING );

		$this->assertSame( 'processing', $this->applied );
		$this->assertSame( 'delivering', $this->recorded_status() );
	}

	public function test_delivered_and_cancelled_transitions_reach_the_order(): void {
		$this->seed_row( 'delivering' );
		$this->write_existing( 'on-hold', 'delivered', Status_Map::DELIVERED );
		$this->assertSame( 'completed', $this->applied );

		$this->applied = null;
		$this->seed_row_again( 'delivering' );
		$this->write_existing( 'on-hold', 'cancelled', Status_Map::CANCELLED );
		$this->assertSame( 'cancelled', $this->applied );
	}

	public function test_a_repeated_delivered_after_a_manual_change_is_left_alone(): void {
		$this->seed_row( 'delivered' );

		$this->write_existing( 'refunded', 'delivered', Status_Map::DELIVERED );

		$this->assertSame( 'refunded', $this->applied );
	}

	public function test_a_status_the_registry_keeps_cut_to_32_characters_is_not_read_as_a_change(): void {
		$long = 'awaiting_deliver_with_a_very_long_marketplace_status';
		$this->seed_row( substr( $long, 0, 32 ) );

		$this->write_existing( 'on-hold', $long, Status_Map::AWAITING_SHIPMENT );

		$this->assertSame( 'on-hold', $this->applied );
	}

	public function test_a_new_order_gets_the_configured_status(): void {
		$wc_order = \Mockery::mock( '\WC_Order' );
		$wc_order->shouldReceive( 'get_id' )->andReturn( 700 );
		$wc_order->shouldReceive( 'get_status' )->andReturn( 'pending' );

		$store = \Mockery::mock( Order_Store::class );
		$store->shouldReceive( 'find' )->andReturn( null );
		$store->shouldReceive( 'create' )->once()->andReturn( $wc_order );
		$store->shouldReceive( 'is_provisional' )->andReturn( false );
		$this->expect_update( $store );

		$this->writer( $store )->upsert( $this->posting( 'awaiting_packaging', Status_Map::AWAITING_SHIPMENT ), new Status_Map() );

		$this->assertSame( 'processing', $this->applied );
		$this->assertSame( 'awaiting_packaging', $this->recorded_status() );
	}

	public function test_an_order_that_waited_for_its_first_real_status_gets_the_configured_status_even_on_a_repeated_status(): void {
		$this->seed_row( 'awaiting_packaging' );

		$this->write_existing( 'pending', 'awaiting_packaging', Status_Map::AWAITING_SHIPMENT, true );

		$this->assertSame( 'processing', $this->applied );
	}

	public function test_an_existing_order_whose_row_saw_no_status_takes_the_mapped_status(): void {
		$this->seed_row( '' );

		$this->write_existing( 'pending', 'awaiting_packaging', Status_Map::AWAITING_SHIPMENT );

		$this->assertSame( 'processing', $this->applied );
	}

	/**
	 * Puts the row back to a last-seen status after a write moved it, so a test can run a second write from the same start.
	 *
	 * @param string $last_seen Marketplace status.
	 * @return void
	 */
	private function seed_row_again( string $last_seen ): void {
		$this->db->update(
			Sqlite_Tables::table( 'postings' ),
			[ 'status_raw' => $last_seen ],
			[
				'account_id'   => 1,
				'external_key' => self::KEY,
			]
		);
	}
}

<?php
/**
 * Unit tests for the store of what was sent to the marketplace for the units of orders.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store
 */
final class SellerActionStoreTest extends TestCase {
	private const FIRST  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const SECOND = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/**
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Unix time.
	 *
	 * @var int
	 */
	private $now = 1795000000;

	protected function setUp(): void {
		parent::setUp();

		$this->db  = new Sqlite_Wpdb();
		$this->now = 1795000000;

		Sqlite_Tables::install( $this->db );
	}

	public function test_only_one_request_takes_a_unit_whatever_it_wants_to_send(): void {
		$store = $this->store();

		$this->assertNotNull( $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST ) );
		$this->assertNull( $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST ) );
		$this->assertNull( $store->claim( 1, 10, 'u-1', 'confirm', self::SECOND ), 'Another input waits for the answer too.' );
		$this->assertSame( Wpdb_Seller_Action_Store::SENDING, $store->blocking_state( 1, 10, 'u-1', 'confirm', self::SECOND ) );
	}

	public function test_another_unit_order_account_or_action_is_not_held(): void {
		$store = $this->store();
		$store->claim( 1, 10, 'u-1', 'confirm', self::FIRST );

		$this->assertNotNull( $store->claim( 1, 10, 'u-2', 'confirm', self::FIRST ) );
		$this->assertNotNull( $store->claim( 1, 11, 'u-1', 'confirm', self::FIRST ) );
		$this->assertNotNull( $store->claim( 2, 10, 'u-1', 'confirm', self::FIRST ) );
		$this->assertNotNull( $store->claim( 1, 10, 'u-1', 'receive', self::FIRST ) );
	}

	public function test_a_kept_action_holds_the_same_identity_only(): void {
		$store = $this->store();
		$store->keep( (string) $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST ), Wpdb_Seller_Action_Store::SENT );

		$this->assertSame( Wpdb_Seller_Action_Store::SENT, $store->blocking_state( 1, 10, 'u-1', 'confirm', self::FIRST ) );
		$this->assertNull( $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST ) );
		$this->assertSame( '', $store->blocking_state( 1, 10, 'u-1', 'confirm', self::SECOND ) );
		$this->assertNotNull( $store->claim( 1, 10, 'u-1', 'confirm', self::SECOND ) );
	}

	public function test_a_released_action_can_be_sent_at_once(): void {
		$store = $this->store();
		$store->release( (string) $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST ) );

		$this->assertNotNull( $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST ) );
	}

	public function test_a_request_that_died_stops_holding_after_its_time_and_cannot_write_over_the_next_one(): void {
		$store = $this->store();
		$dead  = (string) $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST );

		$this->now += Wpdb_Seller_Action_Store::SENDING_TTL;
		$next       = $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST );

		$this->assertNotNull( $next );

		$store->release( $dead );

		$this->assertSame( Wpdb_Seller_Action_Store::SENDING, $store->blocking_state( 1, 10, 'u-1', 'confirm', self::FIRST ) );
	}

	public function test_expired_rows_are_swept_and_an_account_is_erased(): void {
		$store = $this->store();
		$store->keep( (string) $store->claim( 1, 10, 'u-1', 'confirm', self::FIRST ), Wpdb_Seller_Action_Store::UNKNOWN );
		$store->claim( 2, 10, 'u-1', 'confirm', self::FIRST );

		$this->now += Wpdb_Seller_Action_Store::KEPT_TTL;
		$store->forget_expired( 1 );

		$this->assertSame( [ '2' ], $this->accounts_left() );
		$this->assertSame( 1, $store->delete_account( 2, 100 ) );
		$this->assertSame( [], $this->accounts_left() );
	}

	/**
	 * @return Wpdb_Seller_Action_Store
	 */
	private function store(): Wpdb_Seller_Action_Store {
		return new Wpdb_Seller_Action_Store(
			$this->db,
			Sqlite_Tables::table( 'seller_actions' ),
			function (): int {
				return $this->now;
			}
		);
	}

	/**
	 * @return string[] Account ids that still have a row.
	 */
	private function accounts_left(): array {
		return array_map( 'strval', (array) $this->db->get_col( 'SELECT account_id FROM ' . Sqlite_Tables::table( 'seller_actions' ) . ' ORDER BY account_id' ) );
	}
}

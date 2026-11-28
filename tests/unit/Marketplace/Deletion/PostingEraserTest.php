<?php
/**
 * Unit tests for the postings step of an account's deletion.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Deletion;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage;
use Woodev\Framework\Marketplace\Deletion\Posting_Eraser;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\Marketplace\Support\Stateful_Order;
use Woodev\Tests\Unit\TestCase;

/**
 * The buyer's data of an account's orders goes before the posting that names the order, which stays while the data may be there.
 *
 * @covers \Woodev\Framework\Marketplace\Deletion\Posting_Eraser
 * @covers \Woodev\Framework\Marketplace\Orders\Order_Registry
 */
final class PostingEraserTest extends TestCase {
	/**
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * @var Order_Registry
	 */
	private $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		// The two order stores, as far as «is there such an order» asks.
		$this->db->define( 'CREATE TABLE wp_wc_orders (id INTEGER PRIMARY KEY)' );
		$this->db->define( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY)' );
		$this->registry = new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) );
	}

	public function test_the_orders_lose_the_buyers_data_and_the_accounts_postings_go(): void {
		$order = self::order_with_data();
		self::load( [ 700 => $order ] );

		$this->posting( 1, '11', 700 );
		$this->posting( 1, 'b', null );
		$this->posting( 2, 'c', null );

		$this->assertSame( 2, $this->eraser()->delete_account( 1, 500 ) );
		$this->assertSame( 0, $this->eraser()->delete_account( 1, 500 ) );
		$this->assertSame( 0, $this->registry->count_for_account( 1 ) );
		$this->assertSame( 1, $this->registry->count_for_account( 2 ) );
		$this->assertSame( [ Seller_Order::META_SELLER_DELIVERED => 'yes' ], $order->meta );
		$this->assertSame( '', $order->props['shipping_phone'], 'The order fields are not touched.' );
	}

	public function test_an_order_that_fails_to_load_keeps_its_posting_for_the_retry(): void {
		$order = self::order_with_data();
		$calls = 0;
		Functions\when( 'wc_get_order' )->alias(
			static function () use ( $order, &$calls ) {
				return 1 === ++$calls ? false : $order->order;
			}
		);
		$this->db->insert( 'wp_wc_orders', [ 'id' => 700 ] );
		$this->posting( 1, 'cart-1', 700 );

		$this->assert_the_run_fails();
		$this->assertArrayHasKey( Seller_Order::META_PAYLOAD, $order->meta );
		$this->assertSame( 1, $this->registry->count_for_account( 1 ), 'The posting is the only way back to the order.' );

		$this->assertSame( 1, $this->eraser()->delete_account( 1, 500 ) );
		$this->assertArrayNotHasKey( Seller_Order::META_PAYLOAD, $order->meta );
		$this->assertSame( 0, $this->registry->count_for_account( 1 ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_the_posting_of_an_order_that_no_longer_exists_goes( bool $hpos ): void {
		self::load( [] );
		$this->posting( 1, 'cart-1', 700 );

		$this->assertSame( 1, $this->eraser( $hpos )->delete_account( 1, 500 ) );
		$this->assertSame( 0, $this->registry->count_for_account( 1 ) );
	}

	/**
	 * @dataProvider storages
	 */
	public function test_an_existing_order_the_shop_cannot_load_keeps_its_posting( bool $hpos ): void {
		self::load( [] );
		$this->db->insert( $hpos ? 'wp_wc_orders' : 'wp_posts', [ $hpos ? 'id' : 'ID' => 700 ] );
		$this->posting( 1, 'cart-1', 700 );

		$this->assert_the_run_fails( $hpos );
		$this->assertSame( 1, $this->registry->count_for_account( 1 ) );
	}

	public function test_a_refused_question_about_the_order_does_not_say_it_is_gone(): void {
		self::load( [] );
		$this->posting( 1, 'cart-1', 700 );
		$this->db->fail_read = static function ( string $query ): bool {
			return false !== strpos( $query, 'wp_wc_orders' );
		};

		$this->assert_the_run_fails();
		$this->db->fail_read = null;
		$this->assertSame( 1, $this->registry->count_for_account( 1 ) );
	}

	public function test_a_refused_read_is_an_error_and_not_the_end_of_the_postings(): void {
		$this->posting( 1, 'cart-1', null );
		$this->db->fail_reads = true;

		try {
			$this->eraser()->delete_account( 1, 500 );
			$this->fail( 'A refused read must not answer «nothing left».' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not be read', $e->getMessage() );
		}

		$this->db->fail_reads = false;
		$this->assertSame( 1, $this->registry->count_for_account( 1 ) );
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

	private function assert_the_run_fails( bool $hpos = true ): void {
		try {
			$this->eraser( $hpos )->delete_account( 1, 500 );
			$this->fail( 'The run must fail, so that it is retried.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not be loaded', $e->getMessage() );
		}
	}

	private function eraser( bool $hpos = true ): Posting_Eraser {
		return new Posting_Eraser( $this->registry, $this->db, new Site_Orders_Storage( $hpos, $hpos ? 'wp_wc_orders' : 'wp_posts' ) );
	}

	private function posting( int $account_id, string $key, ?int $order_id ): void {
		$this->registry->reserve( $account_id, $key );

		if ( null !== $order_id ) {
			$table = Sqlite_Tables::table( 'postings' );
			$this->db->query( "UPDATE {$table} SET wc_order_id = {$order_id} WHERE external_key = '{$key}'" );
		}
	}

	/**
	 * @param array<int,Stateful_Order> $orders Orders the shop can load.
	 */
	private static function load( array $orders ): void {
		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( $orders ) {
				return isset( $orders[ $id ] ) ? $orders[ $id ]->order : false;
			}
		);
	}

	private static function order_with_data(): Stateful_Order {
		$order = new Stateful_Order( 700 );

		$order->meta[ Seller_Order::META_SELLER_DELIVERED ] = 'yes';
		$order->meta[ Seller_Order::META_PAYLOAD ]          = [ '#u1' => [ 'phone' => '+7' ] ];

		return $order;
	}
}

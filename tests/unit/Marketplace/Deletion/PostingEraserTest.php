<?php
/**
 * Unit tests for the postings step of an account's deletion.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Deletion;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Deletion\Posting_Eraser;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\Marketplace\Support\Stateful_Order;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Deletion\Posting_Eraser
 * @covers \Woodev\Framework\Marketplace\Orders\Order_Registry
 */
final class PostingEraserTest extends TestCase {

	public function test_the_orders_lose_the_buyers_data_and_the_accounts_postings_go(): void {
		$db       = new Sqlite_Wpdb();
		$registry = new Order_Registry( $db, Sqlite_Tables::table( 'postings' ) );
		Sqlite_Tables::install( $db );

		$order = new Stateful_Order( 700 );
		$order->meta[ Seller_Order::META_SELLER_DELIVERED ] = 'yes';
		$order->meta[ Seller_Order::META_PAYLOAD ]          = [ '#u1' => [ 'phone' => '+7' ] ];

		Functions\when( 'wc_get_order' )->alias(
			static function ( int $id ) use ( $order ) {
				return 700 === $id ? $order->order : false;
			}
		);

		foreach ( [ [ 1, '11' ], [ 1, 'b' ], [ 2, 'c' ] ] as $row ) {
			$registry->reserve( $row[0], $row[1] );
		}

		$table = Sqlite_Tables::table( 'postings' );
		$db->query( "UPDATE {$table} SET wc_order_id = 700 WHERE external_key = '11'" );
		$db->query( "UPDATE {$table} SET wc_order_id = 999 WHERE external_key = 'b'" );

		$eraser = new Posting_Eraser( $registry );

		$this->assertSame( 2, $eraser->delete_account( 1, 500 ) );
		$this->assertSame( 0, $eraser->delete_account( 1, 500 ) );
		$this->assertSame( 0, $registry->count_for_account( 1 ) );
		$this->assertSame( 1, $registry->count_for_account( 2 ) );
		$this->assertSame( [ Seller_Order::META_SELLER_DELIVERED => 'yes' ], $order->meta );
		$this->assertSame( '', $order->props['shipping_phone'], 'The order fields are not touched.' );
	}

	public function test_a_refused_read_is_an_error_and_not_the_end_of_the_postings(): void {
		$db       = new Sqlite_Wpdb();
		$registry = new Order_Registry( $db, Sqlite_Tables::table( 'postings' ) );
		Sqlite_Tables::install( $db );
		$registry->reserve( 1, 'cart-1' );
		$db->fail_reads = true;

		try {
			( new Posting_Eraser( $registry ) )->delete_account( 1, 500 );
			$this->fail( 'A refused read must not answer «nothing left».' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not be read', $e->getMessage() );
		}

		$db->fail_reads = false;
		$this->assertSame( 1, $registry->count_for_account( 1 ) );
	}
}

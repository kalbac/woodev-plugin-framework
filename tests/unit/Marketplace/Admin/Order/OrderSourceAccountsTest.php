<?php
/**
 * Unit tests for the cabinets of a marketplace, as the orders list sees them.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Order\Order_Source_Accounts;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * The account of an order and the orders of an account come from the postings registry, the table the order screen reads too.
 */
final class OrderSourceAccountsTest extends TestCase {
	/**
	 * @var Sqlite_Wpdb
	 */
	private $db;

	protected function setUp(): void {
		parent::setUp();

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );

		foreach ( [
			[ 1, 'P-1', 700 ],
			[ 2, 'P-2', 701 ],
			[ 9, 'P-3', 702 ],
		] as [ $account, $key, $order ] ) {
			$this->db->insert(
				Sqlite_Tables::table( 'postings' ),
				[
					'account_id'    => $account,
					'external_key'  => $key,
					'wc_order_id'   => $order,
					'status_raw'    => 'awaiting_deliver',
					'first_seen_at' => '2026-11-20 10:00:00',
				]
			);
		}
	}

	/**
	 * Builds the lookup over two accounts, one of them switched off.
	 *
	 * @return Order_Source_Accounts
	 */
	private function accounts(): Order_Source_Accounts {
		$store = Mockery::mock( Account_Store::class );
		$store->shouldReceive( 'all' )->with( 'fake-seller', false )->atMost()->once()->andReturn(
			[
				new Account( 1, 'fake-seller', 'Основной', true ),
				new Account( 2, 'fake-seller', 'Запасной', false ),
			]
		);

		return new Order_Source_Accounts( $store, new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) ), 'fake-seller' );
	}

	public function test_every_account_is_named_the_switched_off_ones_too(): void {
		$this->assertSame(
			[
				1 => 'Основной',
				2 => 'Запасной',
			],
			$this->accounts()->names()
		);
	}

	public function test_an_order_is_named_after_the_account_of_its_posting(): void {
		$accounts = $this->accounts();

		$this->assertSame( 'Основной', $accounts->name_of_order( 700 ) );
		$this->assertSame( 'Запасной', $accounts->name_of_order( 701 ) );
	}

	public function test_an_order_without_a_posting_or_whose_account_is_gone_has_no_name(): void {
		$accounts = $this->accounts();

		$this->assertSame( '', $accounts->name_of_order( 999 ) );
		$this->assertSame( '', $accounts->name_of_order( 702 ), 'Account 9 no longer exists.' );
	}

	public function test_the_orders_of_an_account_are_the_registry_ones(): void {
		$this->assertSame( [ 701 ], $this->accounts()->order_ids( 2 ) );
	}
}

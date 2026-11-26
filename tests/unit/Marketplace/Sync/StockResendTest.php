<?php
/**
 * A cancelled unit makes its stock go out again.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The module sends a pair only when its value differs from the accepted one, and a marketplace need not give a cancelled unit
 * back: a cancellation makes the pair due although the number may equal the last one sent.
 *
 * @covers \Woodev\Framework\Marketplace\Sync\Stock_Intents
 * @covers \Woodev\Framework\Marketplace\Stock\Sync_State_Store
 */
final class StockResendTest extends Sync_Test_Case {
	/**
	 * @return void
	 */
	public function test_a_cancelled_unit_sends_the_same_number_again_once(): void {
		$product = $this->stock_product( 'cap|', 101 );
		$stock   = $this->hook_stock();
		$this->set_stock( $product, 7 );
		$this->flush();

		$stock->on_units_cancelled( $this->account_id, [ 'cap|' ] );
		$this->flush();
		$this->assertSame( 0, $this->flush()->requests, 'Once is enough.' );

		$this->assertSame( [ [ 'cap|' => 7 ], [ 'cap|' => 7 ] ], $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
		$this->assertSame( [ 'stock' => 7 ], $this->stock_row( $product )['sent_payload'] );
	}

	/**
	 * An order cancelled before it was ingested changed nothing in the shop: the pair that never had an intent still goes out.
	 *
	 * @return void
	 */
	public function test_a_unit_cancelled_before_any_send_records_and_sends_the_stock(): void {
		$this->stock_product( 'cap|', 101, 4 );

		$this->hook_stock()->on_units_cancelled( $this->account_id, [ 'cap|' ] );
		$this->flush();

		$this->assertSame( [ [ 'cap|' => 4 ] ], $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
	}

	/**
	 * The cancellation arrives while the old number is on its way: accepting that send must not settle the pair.
	 *
	 * @return void
	 */
	public function test_a_cancellation_during_a_send_keeps_the_pair_due(): void {
		$product = $this->stock_product( 'cap|', 101 );
		$stock   = $this->hook_stock();
		$this->set_stock( $product, 7 );

		$this->market->script[] = function () use ( $stock ) {
			$stock->on_units_cancelled( $this->account_id, [ 'cap|' ] );

			return null;
		};
		$this->flush();
		$this->flush();
		$this->flush();

		$this->assertSame( [ [ 'cap|' => 7 ], [ 'cap|' => 7 ] ], $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
	}

	/**
	 * What the warehouse was last seen to hold is kept: a warehouse the product leaves afterwards is still zeroed.
	 *
	 * @return void
	 */
	public function test_the_readback_of_the_pair_survives_the_cancellation(): void {
		$product = $this->stock_product( 'cap|', 101 );
		$stock   = $this->hook_stock();
		$this->set_stock( $product, 7 );
		$this->flush();

		$stock->on_units_cancelled( $this->account_id, [ 'cap|' ] );

		$this->assertSame( [ self::WAREHOUSE ], $this->queue()->warehouses_with_stock( $this->account_id, (int) $this->links()->find_by_offer_id( $this->account_id, 'cap|' )->link_id() ) );
	}

	/**
	 * @return void
	 */
	public function test_nothing_is_sent_for_a_unit_the_shop_does_not_send_stock_of(): void {
		$this->stock_product( 'off|', 101, 4, [ 'sync_stock' => false ] );
		$stock = $this->hook_stock();

		$stock->on_units_cancelled( $this->account_id, [ 'off|', 'unlinked|' ] );

		$this->assertSame( 0, $this->flush()->requests );
	}

	/**
	 * The shop stopped managing the stock after a send: there is no current number, and the old one must not go out again.
	 *
	 * @return void
	 */
	public function test_an_old_number_is_not_sent_again_for_a_product_whose_stock_is_no_longer_managed(): void {
		$product = $this->stock_product( 'cap|', 101 );
		$stock   = $this->hook_stock();
		$this->set_stock( $product, 7 );
		$this->flush();
		$unmanaged = \Mockery::mock( 'WC_Product' );
		$unmanaged->shouldReceive( 'get_id', 'get_stock_managed_by_id' )->andReturn( $product );
		$unmanaged->shouldReceive( 'managing_stock' )->andReturn( false );
		$this->products[ $product ] = $unmanaged;

		$stock->on_units_cancelled( $this->account_id, [ 'cap|' ] );

		$this->assertSame( 0, $this->flush()->requests );
	}

	/**
	 * @return void
	 */
	public function test_nothing_is_sent_while_the_marketplace_leads_the_stock(): void {
		$this->stock_product( 'cap|', 101, 4 );
		$account = $this->accounts()->get( $this->account_id );
		$this->accounts()->save(
			new Account(
				$account->id(),
				$account->adapter_id(),
				$account->name(),
				true,
				$account->credentials(),
				null,
				[ 'stock_mode' => Wpdb_Account_Store::STOCK_MARKETPLACE ] + $account->settings()
			)
		);

		$this->hook_stock()->on_units_cancelled( $this->account_id, [ 'cap|' ] );

		$this->assertSame( 0, $this->flush()->requests );
	}
}

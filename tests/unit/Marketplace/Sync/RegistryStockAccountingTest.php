<?php
/**
 * Stock accounting of marketplace orders that create no WooCommerce order.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Orders\Order_Claim_Exception;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Sync\Registry_Stock_Accounting;
use Woodev\Framework\Marketplace\Sync\Stock_Transaction;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `RegistryStockAccountingTest` and `StockCrashWindowTest`: a sale and its cancellation count
 * once, a failure is retried on the failed line only, the baseline of an earlier generation exempts only the sales before it,
 * and a death between the stock write and its accounting row loses no unit.
 */
final class RegistryStockAccountingTest extends Sync_Test_Case {
	/**
	 * A sale decrements once however often it is refreshed, its cancellation restores once, and the row ends finished.
	 *
	 * @return void
	 */
	public function test_a_sale_and_its_cancellation_count_once(): void {
		$product = $this->stock_product( 'a', 1001 );
		$account = $this->account_now();

		$this->accounting()->on_order( $this->order( 'off-1', Status_Map::AWAITING_SHIPMENT ), $account );
		$this->accounting()->on_order( $this->order( 'off-1', Status_Map::AWAITING_SHIPMENT ), $account );

		$this->assertSame( 9, $this->wc_stock( $product ) );

		$this->accounting()->on_order( $this->order( 'off-1', Status_Map::CANCELLED ), $account );
		$this->accounting()->on_order( $this->order( 'off-1', Status_Map::CANCELLED ), $account );

		$this->assertSame( 10, $this->wc_stock( $product ) );

		$row = $this->registry()->find( $this->account_id, 'off-1' );
		$this->assertTrue( $row['is_terminal'] );
		$this->assertSame( 0, $row['lines'][0]['qty_applied'] );
		$this->assertSame( [], $this->retries );
		$this->assertSame( [], $this->log );
	}

	/**
	 * The accounting listens to the action the order refresh fires for an order without a WooCommerce order.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_action_it_is_given(): void {
		$accounting = $this->accounting();

		$accounting->register( 'woodev_fake_seller_order_without_wc_order' );

		$this->assertSame( 10, has_action( 'woodev_fake_seller_order_without_wc_order', [ $accounting, 'on_order' ] ) );
	}

	/**
	 * A refresh that turns A×1 into A×2 takes one more unit, not a second ×1; the cancellation gives back exactly two.
	 *
	 * @return void
	 */
	public function test_a_quantity_change_takes_the_delta_and_the_cancellation_restores_all(): void {
		$product = $this->stock_product( 'a', 1001 );

		$this->accounting()->account( $this->order( 'q-1', Status_Map::AWAITING_SHIPMENT, 1 ), $this->account_now() );
		$this->assertSame( 9, $this->wc_stock( $product ) );

		$this->accounting()->account( $this->order( 'q-1', Status_Map::AWAITING_SHIPMENT, 2 ), $this->account_now() );
		$this->assertSame( 8, $this->wc_stock( $product ) );

		$this->accounting()->account( $this->order( 'q-1', Status_Map::CANCELLED, 2 ), $this->account_now() );
		$this->assertSame( 10, $this->wc_stock( $product ) );
	}

	/**
	 * A failure on line B after line A was accounted leaves A done; the retry touches only B.
	 *
	 * @return void
	 */
	public function test_a_mid_order_failure_is_retried_on_the_failed_line_only(): void {
		$a     = $this->stock_product( 'a', 1001 );
		$b     = $this->stock_product( 'b', 1002 );
		$order = $this->two_lines( 'mid-1', Status_Map::AWAITING_SHIPMENT, 1, 2 );

		$failing = $this->accounting(
			static function ( string $point, string $line ): void {
				if ( 'before_line' === $point && 0 === strpos( $line, '1002|' ) ) {
					throw new \RuntimeException( 'Simulated failure of line B' );
				}
			}
		);

		try {
			$failing->account( $order, $this->account_now() );
			$this->fail( 'Line B must fail.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Simulated failure of line B', $e->getMessage() );
		}

		$this->assertSame( 9, $this->wc_stock( $a ) );
		$this->assertSame( 10, $this->wc_stock( $b ) );
		$this->assertFalse( $this->registry()->find( $this->account_id, 'mid-1' )['is_terminal'] );

		$touched = [];
		$this->accounting(
			static function ( string $point, string $line ) use ( &$touched ): void {
				if ( 'before_line' === $point ) {
					$touched[] = $line;
				}
			}
		)->account( $order, $this->account_now() );

		$this->assertSame( [ '1002|b' ], $touched, 'only line B is written again' );
		$this->assertSame( 9, $this->wc_stock( $a ) );
		$this->assertSame( 8, $this->wc_stock( $b ) );
	}

	/**
	 * A failure inside the hook is reported where support looks and the order is queued again; nothing is thrown into the refresh.
	 *
	 * @return void
	 */
	public function test_a_failure_in_the_hook_is_reported_and_retried(): void {
		$this->stock_product( 'a', 1001 );

		$this->accounting(
			static function ( string $point ): void {
				if ( 'before_line' === $point ) {
					throw new \RuntimeException( 'database is away' );
				}
			}
		)->on_order( $this->order( 'hook-1', Status_Map::AWAITING_SHIPMENT ), $this->account_now() );

		$this->assertSame( [ [ $this->account_id, 'hook-1', Order_Claim_Exception::RETRY_SECONDS ] ], $this->retries );
		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'hook-1', $this->log[0] );
		$this->assertStringContainsString( 'database is away', $this->log[0] );
	}

	/**
	 * While another run holds the order's row the accounting does nothing and asks to be run again.
	 *
	 * @return void
	 */
	public function test_a_busy_row_is_retried_later(): void {
		$product = $this->stock_product( 'a', 1001 );

		$this->registry()->reserve( $this->account_id, 'busy-1' );
		$this->assertNotNull( $this->registry()->claim( $this->account_id, 'busy-1' ) );

		$this->accounting()->on_order( $this->order( 'busy-1', Status_Map::AWAITING_SHIPMENT ), $this->account_now() );

		$this->assertSame( 10, $this->wc_stock( $product ) );
		$this->assertSame( [ [ $this->account_id, 'busy-1', Order_Claim_Exception::RETRY_SECONDS ] ], $this->retries );
		$this->assertSame( [], $this->log, 'a busy row is not an error' );
	}

	/**
	 * A run whose claim on the row was taken over before a line writes no stock for it.
	 *
	 * @return void
	 */
	public function test_a_run_that_lost_the_row_claim_writes_no_stock(): void {
		$product = $this->stock_product( 'a', 1001 );

		$stale = $this->accounting(
			function ( string $point ): void {
				if ( 'before_line' === $point ) {
					$this->now += Job_Gate::CLAIM_TTL + 1;
					$this->assertNotNull( $this->registry()->claim( $this->account_id, 'lost-1' ), 'another run takes the expired claim' );
				}
			}
		);

		try {
			$stale->account( $this->order( 'lost-1', Status_Map::AWAITING_SHIPMENT ), $this->account_now() );
			$this->fail( 'The stale run must stop.' );
		} catch ( Order_Claim_Exception $e ) {
			$this->assertFalse( $e->is_busy() );
		}

		$this->assertSame( 10, $this->wc_stock( $product ) );
	}

	/**
	 * At the takeover WC and the marketplace hold 10. An old order (before the takeover) was already taken by the earlier
	 * generation; a new one arrives after it. The first catch-up sees both: the old one takes nothing, the new one decrements
	 * once, repeated polls change nothing, and each cancellation restores once.
	 *
	 * @return void
	 */
	public function test_the_baseline_exempts_only_sales_before_the_takeover(): void {
		$takeover = $this->now - 3600;
		$this->set_account( [ 'takeover_at' => $takeover ] );

		$product = $this->stock_product( 'a', 1001, 9 ); // The earlier generation already took the old sale: 10 → 9.
		$old     = [ 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $takeover - 86400 ) ];
		$new     = [ 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $takeover + 600 ) ];

		for ( $poll = 0; $poll < 3; $poll++ ) {
			$this->accounting()->account( $this->order( 'old-1', Status_Map::SHIPPED, 1, $old ), $this->account_now() );
			$this->accounting()->account( $this->order( 'new-1', Status_Map::AWAITING_SHIPMENT, 1, $new ), $this->account_now() );

			$this->assertSame( 8, $this->wc_stock( $product ), 'poll ' . $poll . ': the new sale decrements once, the old one not at all' );
		}

		$this->assertTrue( $this->registry()->find( $this->account_id, 'old-1' )['baseline'] );
		$this->assertFalse( $this->registry()->find( $this->account_id, 'new-1' )['baseline'] );

		for ( $poll = 0; $poll < 2; $poll++ ) {
			$this->accounting()->account( $this->order( 'new-1', Status_Map::CANCELLED, 1, $new ), $this->account_now() );
		}

		$this->assertSame( 9, $this->wc_stock( $product ), 'the new sale is restored once' );

		for ( $poll = 0; $poll < 2; $poll++ ) {
			$this->accounting()->account( $this->order( 'old-1', Status_Map::CANCELLED, 1, $old ), $this->account_now() );
		}

		$this->assertSame( 10, $this->wc_stock( $product ), 'the baseline sale is restored once' );
	}

	/**
	 * A finished and an open order from before the takeover subtract nothing; the open one restores on a later cancellation, the
	 * finished one never moves stock, and one first seen already cancelled gives nothing back.
	 *
	 * @return void
	 */
	public function test_orders_from_before_the_takeover_subtract_nothing(): void {
		$takeover = $this->now - 3600;
		$this->set_account( [ 'takeover_at' => $takeover ] );

		$product = $this->stock_product( 'a', 1001, 8 );
		$before  = [ 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', $takeover - 7200 ) ];

		$this->accounting()->account( $this->order( 'done-1', Status_Map::DELIVERED, 1, $before ), $this->account_now() );
		$this->accounting()->account( $this->order( 'open-1', Status_Map::AWAITING_SHIPMENT, 1, $before ), $this->account_now() );

		$this->assertSame( 8, $this->wc_stock( $product ) );
		$this->assertSame( 1, $this->registry()->find( $this->account_id, 'done-1' )['lines'][0]['qty_applied'], 'the delivered sale stands as already taken' );

		$this->accounting()->account( $this->order( 'open-1', Status_Map::CANCELLED, 1, $before ), $this->account_now() );
		$this->accounting()->account( $this->order( 'done-1', Status_Map::DELIVERED, 1, $before ), $this->account_now() );
		$this->accounting()->account( $this->order( 'gone-1', Status_Map::CANCELLED, 1, $before ), $this->account_now() );

		$this->assertSame( 9, $this->wc_stock( $product ) );
	}

	/**
	 * A plugin without an earlier generation has no baseline: an old order is a sale like any other.
	 *
	 * @return void
	 */
	public function test_without_a_takeover_there_is_no_baseline(): void {
		$product = $this->stock_product( 'a', 1001 );

		$this->accounting()->account( $this->order( 'fresh-1', Status_Map::AWAITING_SHIPMENT, 1, [ 'created_at' => '2020-01-01T00:00:00Z' ] ), $this->account_now() );

		$this->assertSame( 9, $this->wc_stock( $product ) );
		$this->assertFalse( $this->registry()->find( $this->account_id, 'fresh-1' )['baseline'] );
	}

	/**
	 * In `marketplace` mode the marketplace's stock notifications set WC stock; the registry accounting does nothing.
	 *
	 * @return void
	 */
	public function test_marketplace_mode_is_left_to_the_stock_notifications(): void {
		$this->set_account( [ 'stock_mode' => 'marketplace' ] );
		$product = $this->stock_product( 'a', 1001 );

		$result = $this->accounting()->account( $this->order( 'mp-1', Status_Map::AWAITING_SHIPMENT ), $this->account_now() );

		$this->assertSame( Registry_Stock_Accounting::RESULT_SKIPPED, $result );
		$this->assertSame( 10, $this->wc_stock( $product ) );
	}

	/**
	 * A line whose product is not linked, or does not manage stock, takes nothing and blocks nothing.
	 *
	 * @return void
	 */
	public function test_an_unlinked_line_takes_nothing(): void {
		$linked = $this->stock_product( 'a', 1001 );
		$order  = $this->market_order(
			'mix-1',
			Status_Map::AWAITING_SHIPMENT,
			[
				'stranger' => [ 4040, 3 ],
				'a'        => [ 1001, 1 ],
			]
		);

		$this->assertSame( Registry_Stock_Accounting::RESULT_APPLIED, $this->accounting()->account( $order, $this->account_now() ) );
		$this->assertSame( 9, $this->wc_stock( $linked ) );
	}

	/**
	 * The death on line B, between its stock write and its accounting row, leaves line A accounted and line B as if never
	 * started; the retry applies only B; the flush and the readback agree with WC; the cancellation gives back exactly what was
	 * taken.
	 *
	 * @return void
	 */
	public function test_a_death_between_the_two_writes_loses_no_unit(): void {
		$products = $this->crash_products();
		$touched  = $this->crash_then_retry( $products, null );

		$this->assertSame( [ '1002|b' ], $touched, 'the retry re-applies only the line that died' );
		$this->after_flush_and_readback( $products, 9, 'sale' );

		$this->accounting()->account( $this->two_lines( 'crash-1', Status_Map::CANCELLED, 1, 1 ), $this->account_now() );
		$this->after_flush_and_readback( $products, 10, 'cancellation' );
	}

	/**
	 * Bite check: with the two writes outside one transaction the same run ends with B at 8 after the sale — the silent loss the
	 * transaction closes; the readback sees no drift because the flush already sent the wrong value.
	 *
	 * @return void
	 */
	public function test_without_the_transaction_the_crash_loses_a_unit(): void {
		$products = $this->crash_products();

		$this->crash_then_retry(
			$products,
			new class( $this->db ) extends Stock_Transaction {
				/**
				 * Runs the work with no transaction.
				 *
				 * @param callable $work    Work.
				 * @param int[]    $touched Unused.
				 * @return mixed
				 */
				public function run( callable $work, array $touched = [] ) {
					return call_user_func( $work );
				}
			}
		);

		$this->flush();

		$this->assertSame( [], $this->reconcile(), 'the readback compares 8 with 8' );
		$this->assertSame( 8, $this->wc_stock( $products['b'] ) );
	}

	/**
	 * Products A and B of an account that creates no orders, with the ordinary stock hook.
	 *
	 * @return array{a:int,b:int}
	 */
	private function crash_products(): array {
		$this->set_account( [ 'orders_enabled' => false ] );

		$products = [
			'a' => $this->stock_product( 'a', 1001 ),
			'b' => $this->stock_product( 'b', 1002 ),
		];
		$this->hook_stock();

		return $products;
	}

	/**
	 * Accounts the order, killing the process between line B's two writes, then retries it as a fresh process would.
	 *
	 * @param array{a:int,b:int}     $products    Products.
	 * @param Stock_Transaction|null $transaction Transaction; the real one when null.
	 * @return string[] Lines the retry wrote.
	 */
	private function crash_then_retry( array $products, ?Stock_Transaction $transaction ): array {
		$killed = false;

		try {
			$this->accounting(
				function ( string $point, string $line ) use ( &$killed ): void {
					if ( ! $killed && 'between_writes' === $point && 0 === strpos( $line, '1002|' ) ) {
						$killed = true;

						// The connection dies with the process: the server rolls back what was not committed.
						$this->db->query( 'ROLLBACK' );

						throw new \Error( 'Simulated process death' );
					}
				},
				$transaction
			)->account( $this->two_lines( 'crash-1', Status_Map::AWAITING_SHIPMENT, 1, 1 ), $this->account_now() );
			$this->fail( 'The process must die on line B.' );
		} catch ( \Error $e ) {
			$this->assertSame( 'Simulated process death', $e->getMessage() );
		}

		// A new process: the dead one's claim has expired.
		$this->now += Job_Gate::CLAIM_TTL + 1;

		$this->assertSame( 9, $this->wc_stock( $products['a'] ), 'line A was accounted before the death' );

		if ( null === $transaction ) {
			$this->assertSame( 10, $this->wc_stock( $products['b'] ), 'line B left no trace' );
			$this->assertSame( [ 1, 0 ], $this->applied( 'crash-1' ) );
		}

		$touched = [];
		$this->accounting(
			static function ( string $point, string $line ) use ( &$touched ): void {
				if ( 'before_line' === $point ) {
					$touched[] = $line;
				}
			},
			$transaction
		)->account( $this->two_lines( 'crash-1', Status_Map::AWAITING_SHIPMENT, 1, 1 ), $this->account_now() );

		return $touched;
	}

	/**
	 * Runs the flush and the readback, then checks WC and the marketplace.
	 *
	 * @param array{a:int,b:int} $products Products.
	 * @param int                $expected Stock of both products.
	 * @param string             $stage    Stage name for the messages.
	 * @return void
	 */
	private function after_flush_and_readback( array $products, int $expected, string $stage ): void {
		$this->flush();

		$this->assertSame( [], $this->reconcile(), $stage . ': the readback finds the marketplace equal to WC' );
		$this->assertSame( $expected, $this->wc_stock( $products['a'] ), $stage . ': A' );
		$this->assertSame( $expected, $this->wc_stock( $products['b'] ), $stage . ': B' );
		$this->assertSame( $expected, $this->market->present['a'], $stage . ': A on the marketplace' );
		$this->assertSame( $expected, $this->market->present['b'], $stage . ': B on the marketplace' );
		$this->assertNotEmpty( $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
	}

	/**
	 * A one-line order of product A.
	 *
	 * @param string               $number   Order number.
	 * @param string               $status   Canonical status.
	 * @param int                  $quantity Quantity.
	 * @param array<string,string> $dates    Dates.
	 * @return Order_DTO
	 */
	private function order( string $number, string $status, int $quantity = 1, array $dates = [] ): Order_DTO {
		return $this->market_order( $number, $status, [ 'a' => [ 1001, $quantity ] ], $dates );
	}

	/**
	 * An order of A and B.
	 *
	 * @param string $number Order number.
	 * @param string $status Canonical status.
	 * @param int    $a      Quantity of A.
	 * @param int    $b      Quantity of B.
	 * @return Order_DTO
	 */
	private function two_lines( string $number, string $status, int $a, int $b ): Order_DTO {
		return $this->market_order(
			$number,
			$status,
			[
				'a' => [ 1001, $a ],
				'b' => [ 1002, $b ],
			]
		);
	}

	/**
	 * Returns `qty_applied` of an order's lines.
	 *
	 * @param string $number Order number.
	 * @return int[]
	 */
	private function applied( string $number ): array {
		return array_map(
			static function ( array $line ): int {
				return (int) $line['qty_applied'];
			},
			$this->registry()->find( $this->account_id, $number )['lines']
		);
	}
}

<?php
/**
 * Unit: how many orders are being exported in the background right now, and how that is worded (#1007).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Order\Export_Queue;
use Woodev\Framework\Shipping\Order\Export_Retry;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Order\Export_Queue
 * @covers \Woodev\Framework\Shipping\Order\Export_Retry::is_running
 * @covers \Woodev\Framework\Shipping\Order\Export_Retry::is_give_up_text
 */
final class ExportQueueTest extends TestCase {

	/** @var array<int,array<string,mixed>> the queries `as_get_scheduled_actions()` received. */
	private array $queries = [];

	/**
	 * @param int[] $running ids of the export actions being carried out.
	 * @param int[] $due     ids of the export actions that are due and waiting for the runner.
	 */
	private function queue( array $running, array $due ): void {
		$this->queries = [];

		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args, string $return ) use ( $running, $due ) {
				$this->queries[] = $args;

				return 'in-progress' === $args['status'] ? $running : $due;
			}
		);
	}

	public function test_nothing_in_the_queue_counts_zero(): void {
		$this->queue( [], [] );

		$this->assertSame( 0, Export_Queue::count_in_progress() );
	}

	public function test_running_and_due_exports_are_counted_together(): void {
		$this->queue( [ 11 ], [ 12, 13 ] );

		$this->assertSame( 3, Export_Queue::count_in_progress() );
	}

	public function test_only_the_frameworks_export_action_is_counted_and_only_what_is_due_now(): void {
		$this->queue( [], [] );

		$before = time();
		Export_Queue::count_in_progress();

		$this->assertCount( 2, $this->queries );

		foreach ( $this->queries as $query ) {
			$this->assertSame( Export_Retry::HOOK, $query['hook'], 'a cancellation is not an export' );
			$this->assertSame( Export_Retry::GROUP, $query['group'] );
			$this->assertSame( -1, $query['per_page'] );
		}

		$pending = array_values( array_filter( $this->queries, static fn( $query ) => 'pending' === $query['status'] ) );
		$this->assertCount( 1, $pending );
		$this->assertSame( '<=', $pending[0]['date_compare'], 'a retry that waits for a later time is not being exported now' );
		$this->assertEqualsWithDelta( $before, $pending[0]['date'], 3 );
	}

	public function test_an_order_seen_running_and_due_at_once_counts_once(): void {
		$this->queue( [ 11 ], [ 11 ] );

		$this->assertSame( 1, Export_Queue::count_in_progress() );
	}

	public function test_nothing_is_counted_when_action_scheduler_is_unavailable(): void {
		// `function_exists()` is redefinable (patchwork.json): the queue functions are "not there".
		Functions\when( 'function_exists' )->justReturn( false );

		$this->assertSame( 0, Export_Queue::count_in_progress() );
	}

	public function test_zero_orders_say_nothing(): void {
		$this->assertSame( '', Export_Queue::notice_text( 0 ) );
	}

	public function test_the_sentence_carries_the_count(): void {
		$this->assertSame( 'Сейчас выгружается 1 заказ перевозчику', Export_Queue::notice_text( 1 ) );
		$this->assertStringContainsString( '5', Export_Queue::notice_text( 5 ) );
	}

	public function test_the_attempts_ran_out_sentence_is_recognised_by_its_text(): void {
		$this->assertTrue( Export_Retry::is_give_up_text( Export_Retry::give_up_text( 'Too Many Requests' ) ) );
		$this->assertTrue( Export_Retry::is_give_up_text( trim( Export_Retry::give_up_text( '' ) ) ), 'the result trims its message' );
		$this->assertFalse( Export_Retry::is_give_up_text( 'Перевозчик временно ограничил число запросов.' ) );
		$this->assertFalse( Export_Retry::is_give_up_text( '' ) );
	}

	public function test_a_running_attempt_of_the_order_is_asked_for_by_its_hook_group_and_order(): void {
		$captured = null;

		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( array $args ) use ( &$captured ) {
				$captured = $args;

				return [ 5 ];
			}
		);

		$this->assertTrue( Export_Retry::is_running( 123 ) );
		$this->assertSame( Export_Retry::HOOK, $captured['hook'] );
		$this->assertSame( [ 123 ], $captured['args'] );
		$this->assertSame( Export_Retry::GROUP, $captured['group'] );
		$this->assertSame( 'in-progress', $captured['status'] );
	}

	public function test_no_running_attempt(): void {
		Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );

		$this->assertFalse( Export_Retry::is_running( 123 ) );
	}
}

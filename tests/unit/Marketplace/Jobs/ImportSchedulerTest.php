<?php
/**
 * The scheduling of the card import job.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Action_Scheduler;
use Woodev\Tests\Unit\TestCase;

/**
 * Card I1 acceptance: an import is queued in chunks of ten, a chunk already waiting is not queued twice, a refused queue throws,
 * and the rows being imported are read from the queue.
 */
final class ImportSchedulerTest extends TestCase {
	/**
	 * Action Scheduler fake.
	 *
	 * @var Fake_Action_Scheduler
	 */
	private $queue;

	/**
	 * Scheduler under test.
	 *
	 * @var Import_Scheduler
	 */
	private $scheduler;

	/**
	 * Installs the fake queue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->queue     = Fake_Action_Scheduler::install();
		$this->scheduler = new Import_Scheduler( new Job_Names( 'fake-seller' ) );
	}

	/**
	 * Articles are queued ten to an action, each action with attempt 0.
	 *
	 * @return void
	 */
	public function test_articles_are_queued_in_chunks_of_ten(): void {
		$offer_ids = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$offer_ids[] = 'SKU-' . $i;
		}

		$this->scheduler->import( 3, $offer_ids );

		$actions = array_values( $this->queue->of( $this->scheduler->hook() ) );
		$this->assertCount( 3, $actions );
		$this->assertSame(
			[ 10, 10, 5 ],
			array_map(
				static function ( array $action ): int {
					return count( $action['args'][1] );
				},
				$actions
			)
		);
		$this->assertSame( [ 3, [ 'SKU-1', 'SKU-2', 'SKU-3', 'SKU-4', 'SKU-5', 'SKU-6', 'SKU-7', 'SKU-8', 'SKU-9', 'SKU-10' ], 0 ], $actions[0]['args'] );
	}

	/**
	 * A chunk that is already waiting with the same arguments is not queued twice, and a repeated article is queued once.
	 *
	 * @return void
	 */
	public function test_a_waiting_chunk_is_not_queued_twice(): void {
		$this->scheduler->import( 3, [ 'SKU-1', 'SKU-2' ] );
		$this->scheduler->import( 3, [ 'SKU-1', 'SKU-2', 'SKU-2' ] );

		$this->assertCount( 1, $this->queue->of( $this->scheduler->hook() ) );
	}

	/**
	 * Numeric articles are queued as strings, so an int and its string are one chunk and not two.
	 *
	 * @return void
	 */
	public function test_articles_are_queued_as_strings(): void {
		$this->scheduler->import( 3, [ 123, '123' ] );

		$actions = array_values( $this->queue->of( $this->scheduler->hook() ) );
		$this->assertCount( 1, $actions );
		$this->assertSame( [ 3, [ '123' ], 0 ], $actions[0]['args'] );
	}

	/**
	 * A queue that refuses the action throws, so the caller's retry applies.
	 *
	 * @return void
	 */
	public function test_a_refused_queue_throws(): void {
		$this->queue->zero_for[ $this->scheduler->hook() ] = true;

		$this->expectException( Persistence_Exception::class );
		$this->scheduler->import( 3, [ 'SKU-1' ] );
	}

	/**
	 * A retry keeps the articles and the attempt it is given.
	 *
	 * @return void
	 */
	public function test_a_retry_keeps_the_attempt(): void {
		$this->scheduler->retry( 3, [ 'SKU-1' ], 2, 60 );

		$actions = array_values( $this->queue->of( $this->scheduler->hook() ) );
		$this->assertCount( 1, $actions );
		$this->assertSame( [ 3, [ 'SKU-1' ], 2 ], $actions[0]['args'] );
	}

	/**
	 * A job that requeues its own unfinished chunk while that chunk is running still gets its work queued: the running action is
	 * not a waiting one (gotcha `as-has-scheduled-action-matches-the-running-action`).
	 *
	 * @return void
	 */
	public function test_a_requeue_from_the_running_chunk_is_queued(): void {
		$this->scheduler->import( 3, [ 'SKU-1' ] );
		$this->queue->start_running( $this->scheduler->hook() );

		$this->scheduler->retry( 3, [ 'SKU-1' ], 0, 0 );

		$this->assertCount( 1, $this->queue->matching( $this->scheduler->hook(), [ 3, [ 'SKU-1' ], 0 ], 'pending' ) );
	}

	/**
	 * The rows being imported are the articles of the account's pending or running import actions, and nothing of another account.
	 *
	 * @return void
	 */
	public function test_pending_offer_ids_come_from_the_queue_of_the_account(): void {
		$hook    = $this->scheduler->hook();
		$pending = [
			$this->action( [ 3, [ 'SKU-1', 'SKU-2' ], 0 ] ),
			$this->action( [ 3, [ 'SKU-2', 'SKU-3' ], 1 ] ),
			$this->action( [ 4, [ 'SKU-9' ], 0 ] ),
		];

		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( array $query ) use ( $pending, $hook ): array {
				return 'pending' === ( $query['status'][0] ?? '' ) && $hook === $query['hook'] ? $pending : [];
			}
		);

		$this->assertSame( [ 'SKU-1', 'SKU-2', 'SKU-3' ], $this->scheduler->pending_offer_ids( 3 ) );
	}

	/**
	 * Builds a queue action as Action Scheduler returns it to a query.
	 *
	 * @param array<int,mixed> $args Arguments.
	 * @return object
	 */
	private function action( array $args ): object {
		return new class( $args ) {
			/**
			 * Arguments.
			 *
			 * @var array<int,mixed>
			 */
			private $args;

			/**
			 * Creates the action.
			 *
			 * @param array<int,mixed> $args Arguments.
			 */
			public function __construct( array $args ) {
				$this->args = $args;
			}

			/**
			 * Returns the arguments.
			 *
			 * @return array<int,mixed>
			 */
			public function get_args(): array {
				return $this->args;
			}
		};
	}
}

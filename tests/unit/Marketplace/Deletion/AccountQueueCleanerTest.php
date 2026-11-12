<?php
/**
 * Taking an account's queued jobs off the queue.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Deletion;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Deletion\Account_Queue_Cleaner;

/**
 * The Action Scheduler store is a small stand-in that pages like the real one and forgets an action once it is cancelled.
 */
final class AccountQueueCleanerTest extends TestCase {
	private const GROUP  = 'woodev-fake-seller';
	private const PREFIX = 'woodev_fake_seller_';

	/**
	 * Pending actions by id: `[ hook, args ]`.
	 *
	 * @var array<int,array{0:string,1:array}>
	 */
	private $pending = [];

	/**
	 * Ids the cleaner cancelled.
	 *
	 * @var int[]
	 */
	private $cancelled = [];

	/**
	 * Starts with an empty queue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->pending   = [];
		$this->cancelled = [];
	}

	/**
	 * Only the account's own jobs go: the sweeps that serve every account, the label poll, the deletion job itself,
	 * another account's jobs and another plugin's jobs stay.
	 *
	 * @return void
	 */
	public function test_only_the_accounts_own_jobs_are_cancelled(): void {
		$ours = [
			$this->add( 'flush_sync', [ 7 ] ),
			$this->add( 'link_cards', [ 7, 0, 0 ] ),
			$this->add( 'refresh_posting', [ '7', 'P-1' ] ),
			$this->add( 'recompute_prices', [ 7, 0 ] ),
		];
		$kept = [
			$this->add( 'flush_sync', [ 8 ] ),
			$this->add( 'poll_task', [ 7, 0 ] ),
			$this->add( 'delete_account', [ 7, 0 ] ),
			$this->add( 'poll_exports', [ 0, 0 ] ),
			$this->add( 'link_cards', [ 78, 0, 0 ] ),
			$this->add( 'flush_sync', [] ),
			$this->add( 'flush_sync', [ 'seven' ] ),
			$this->add( 'x', [ 7 ], 'woodev_other_plugin_' ),
		];

		$this->assertSame( 4, $this->cleaner()->cancel_for( 7 ) );
		$this->assertSame( $ours, $this->cancelled );
		$this->assertSame( $kept, array_keys( $this->pending ) );
	}

	/**
	 * A queue longer than one page is read to the end, and a job that follows a page of kept ones is still found.
	 *
	 * @return void
	 */
	public function test_every_page_of_the_queue_is_read(): void {
		for ( $i = 0; $i < 230; $i++ ) {
			$this->add( 'flush_sync', [ 100 + $i ] );
		}
		$late = $this->add( 'flush_sync', [ 7 ] );

		$this->assertSame( 1, $this->cleaner()->cancel_for( 7 ) );
		$this->assertSame( [ $late ], $this->cancelled );
		$this->assertCount( 230, $this->pending );
	}

	/**
	 * Account 0 is «every account»: nothing is cancelled for it, and without Action Scheduler nothing happens.
	 *
	 * @return void
	 */
	public function test_account_zero_and_a_missing_scheduler_cancel_nothing(): void {
		$this->add( 'flush_sync', [ 0 ] );

		$this->assertSame( 0, $this->cleaner()->cancel_for( 0 ) );
		$this->assertSame( 0, ( new Account_Queue_Cleaner( self::GROUP, self::PREFIX, [] ) )->cancel_for( 7 ) );
	}

	/**
	 * Builds the cleaner over the stand-in store.
	 *
	 * @return Account_Queue_Cleaner
	 */
	private function cleaner(): Account_Queue_Cleaner {
		$test  = $this;
		$store = new class( $test ) {
			/**
			 * The test.
			 *
			 * @var AccountQueueCleanerTest
			 */
			private $test;

			/**
			 * Creates the store.
			 *
			 * @param AccountQueueCleanerTest $test The test.
			 */
			public function __construct( AccountQueueCleanerTest $test ) {
				$this->test = $test;
			}

			/**
			 * Lists pending ids, a page at a time.
			 *
			 * @param array  $query  Query.
			 * @param string $return Return type.
			 * @return int[]
			 */
			public function query_actions( array $query, string $return = 'ids' ): array {
				return $this->test->page( (int) $query['offset'], (int) $query['per_page'] );
			}

			/**
			 * Loads an action.
			 *
			 * @param int $id Id.
			 * @return object
			 */
			public function fetch_action( $id ) {
				return $this->test->action( (int) $id );
			}

			/**
			 * Cancels an action.
			 *
			 * @param int $id Id.
			 * @return void
			 */
			public function cancel_action( $id ): void {
				$this->test->cancel( (int) $id );
			}
		};

		return new Account_Queue_Cleaner( self::GROUP, self::PREFIX, [ self::PREFIX . 'poll_task', self::PREFIX . 'delete_account' ], $store );
	}

	/**
	 * Adds a pending action.
	 *
	 * @param string $job    Job name.
	 * @param array  $args   Arguments.
	 * @param string $prefix Hook prefix.
	 * @return int Id.
	 */
	private function add( string $job, array $args, string $prefix = self::PREFIX ): int {
		$id                   = count( $this->pending ) + count( $this->cancelled ) + 1;
		$this->pending[ $id ] = [ $prefix . $job, $args ];

		return $id;
	}

	/**
	 * One page of the pending list.
	 *
	 * @internal Called by the stand-in store.
	 *
	 * @param int $offset Offset.
	 * @param int $size   Page size.
	 * @return int[]
	 */
	public function page( int $offset, int $size ): array {
		return array_slice( array_keys( $this->pending ), $offset, $size );
	}

	/**
	 * An action as Action Scheduler returns it.
	 *
	 * @internal Called by the stand-in store.
	 *
	 * @param int $id Id.
	 * @return object
	 */
	public function action( int $id ): object {
		list( $hook, $args ) = $this->pending[ $id ];

		return new class( $hook, $args ) {
			/**
			 * Hook.
			 *
			 * @var string
			 */
			private $hook;

			/**
			 * Arguments.
			 *
			 * @var array
			 */
			private $args;

			/**
			 * Creates the action.
			 *
			 * @param string $hook Hook.
			 * @param array  $args Arguments.
			 */
			public function __construct( string $hook, array $args ) {
				$this->hook = $hook;
				$this->args = $args;
			}

			/**
			 * Hook.
			 *
			 * @return string
			 */
			public function get_hook(): string {
				return $this->hook;
			}

			/**
			 * Arguments.
			 *
			 * @return array
			 */
			public function get_args(): array {
				return $this->args;
			}
		};
	}

	/**
	 * Cancels an action: it leaves the pending list.
	 *
	 * @internal Called by the stand-in store.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function cancel( int $id ): void {
		$this->cancelled[] = $id;
		unset( $this->pending[ $id ] );
	}
}

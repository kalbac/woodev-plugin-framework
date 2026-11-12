<?php
/**
 * The `delete_account` job: the claim, the order, the resume and the refusals.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Deletion;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Deletion\Account_Data_Cleaner;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Job;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Progress;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Runs the job over a real account store and claim (SQLite), with scripted steps standing in for the stores (the stores have
 * their own test), so what is proved here is the job's behaviour: it claims, it runs the steps in order, the account row is the last
 * thing to go, and an interruption leaves everything the next run needs.
 */
final class AccountDeletionJobTest extends Sync_Test_Case {
	/**
	 * Option and transient storage.
	 *
	 * @var array<string,mixed>
	 */
	private $storage = [];

	/**
	 * Progress.
	 *
	 * @var Account_Deletion_Progress
	 */
	private $progress;

	/**
	 * The steps in the order they were called, each with whether the account row was still there.
	 *
	 * @var string[]
	 */
	private $calls = [];

	/**
	 * Rows each scripted step still has to remove.
	 *
	 * @var array<string,int>
	 */
	private $left = [];

	/**
	 * Backs the WordPress storage functions with an array and switches the account off.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->storage = [];
		$this->calls   = [];
		$this->left    = [];
		$read          = function ( string $name, $fallback = false ) {
			return array_key_exists( $name, $this->storage ) ? $this->storage[ $name ] : $fallback;
		};
		$write         = function ( string $name, $value ): bool {
			$this->storage[ $name ] = $value;

			return true;
		};
		$drop          = function ( string $name ): bool {
			unset( $this->storage[ $name ] );

			return true;
		};

		Functions\when( 'get_option' )->alias( $read );
		Functions\when( 'get_transient' )->alias( $read );
		Functions\when( 'update_option' )->alias( $write );
		Functions\when( 'set_transient' )->alias( $write );
		Functions\when( 'delete_option' )->alias( $drop );
		Functions\when( 'delete_transient' )->alias( $drop );

		$this->progress = new Account_Deletion_Progress( 'woodev_fake_seller_', $this->clock() );
		$this->accounts()->set_enabled( $this->account_id, false );
	}

	/**
	 * The steps run in order, each until it removes nothing, and the account row goes after the last one.
	 *
	 * @return void
	 */
	public function test_the_account_row_is_deleted_last(): void {
		$this->job( $this->steps( [ 'links' => 2, 'tasks' => 1 ] ) )->run( $this->account_id, 0 );

		$this->assertSame(
			[ 'links:row', 'links:row', 'tasks:row', 'tasks:row' ],
			$this->calls,
			'each step runs until it removes nothing, and the account row is there for all of them'
		);
		$this->assertNull( $this->accounts()->get( $this->account_id ), 'the account row is gone' );
		$this->assertSame( Account_Deletion_Progress::DONE, $this->progress->get( $this->account_id )['state'] );
		$this->assertSame( [ 'links' => 2, 'tasks' => 1 ], $this->progress->get( $this->account_id )['removed'] );
	}

	/**
	 * Nothing runs for an account that is switched on, and the merchant is told.
	 *
	 * @return void
	 */
	public function test_an_enabled_account_is_refused(): void {
		$this->accounts()->set_enabled( $this->account_id, true );

		$this->job( $this->steps( [ 'links' => 5 ] ) )->run( $this->account_id, 0 );

		$this->assertSame( [], $this->calls, 'no step ran' );
		$this->assertNotNull( $this->accounts()->get( $this->account_id ) );
		$this->assertSame( Account_Deletion_Progress::FAILED, $this->progress->get( $this->account_id )['state'] );
		$this->assertSame( [], $this->scheduler->summary(), 'nothing was queued either' );
	}

	/**
	 * An account switched on while its data was being deleted keeps its row, and the progress says why it stopped.
	 *
	 * @return void
	 */
	public function test_an_account_switched_on_during_the_run_keeps_its_row(): void {
		$steps = [
			'links' => function () {
				$this->accounts()->set_enabled( $this->account_id, true );

				return 0;
			},
		];

		$this->job( $steps )->run( $this->account_id, 0 );

		$this->assertNotNull( $this->accounts()->get( $this->account_id ) );
		$this->assertSame( Account_Deletion_Progress::FAILED, $this->progress->get( $this->account_id )['state'] );
	}

	/**
	 * While it works the job holds the account's claim, so a sender or tool is refused; it lets go afterwards.
	 *
	 * @return void
	 */
	public function test_the_claim_is_held_during_the_run_and_released_after_a_failure(): void {
		$refused = null;
		$steps   = [
			'links' => function () use ( &$refused ) {
				$refused = $this->accounts()->claim( $this->account_id, 120 );

				throw new \RuntimeException( 'database gone away' );
			},
		];

		$this->job( $steps )->run( $this->account_id, 0 );

		$this->assertNull( $refused, 'another holder is refused while the deletion runs' );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ), 'the claim is released when the run ends' );
	}

	/**
	 * A claim held by someone else puts the job back for half a minute; nothing is deleted.
	 *
	 * @return void
	 */
	public function test_a_busy_account_is_retried_later(): void {
		$this->accounts()->claim( $this->account_id, 120 );

		$this->job( $this->steps( [ 'links' => 3 ] ) )->run( $this->account_id, 0 );

		$this->assertSame( [], $this->calls );
		$later = $this->scheduler->of( 'woodev_fake_seller_delete_account' );
		$this->assertCount( 1, $later );
		$this->assertSame( [ $this->account_id, 0 ], $later[0]['args'] );
		$this->assertEqualsWithDelta( Job_Gate::BUSY_DELAY, $later[0]['delay'], 1 );
	}

	/**
	 * An interruption leaves the row and queues the next run; the next run starts the steps again and finishes.
	 *
	 * @return void
	 */
	public function test_an_interrupted_deletion_resumes(): void {
		$broken = true;
		$steps  = $this->steps( [ 'links' => 2, 'tasks' => 2 ] );
		$tasks  = $steps['tasks'];

		$steps['tasks'] = function ( int $account_id, int $limit ) use ( &$broken, $tasks ) {
			if ( $broken ) {
				throw new \RuntimeException( 'the server went away' );
			}

			return $tasks( $account_id, $limit );
		};

		$this->job( $steps )->run( $this->account_id, 0 );

		$this->assertNotNull( $this->accounts()->get( $this->account_id ), 'the row stays while data is left' );
		$next = $this->scheduler->of( 'woodev_fake_seller_delete_account' );
		$this->assertSame( [ $this->account_id, 1 ], $next[0]['args'], 'the next attempt is queued' );
		$this->assertSame( Account_Deletion_Progress::RUNNING, $this->progress->get( $this->account_id )['state'] );

		$broken = false;
		$this->calls = [];
		$this->job( $steps )->run( $this->account_id, 1 );

		$this->assertSame( [ 'links:row', 'tasks:row', 'tasks:row' ], $this->calls, 'the finished step finds nothing, the other one goes on' );
		$this->assertNull( $this->accounts()->get( $this->account_id ) );
		$this->assertSame( Account_Deletion_Progress::DONE, $this->progress->get( $this->account_id )['state'] );
	}

	/**
	 * After the last retry the merchant is told it failed, and the account stays.
	 *
	 * @return void
	 */
	public function test_the_last_failed_attempt_is_reported(): void {
		$steps = [
			'links' => static function () {
				throw new \RuntimeException( 'nope' );
			},
		];

		$this->job( $steps )->run( $this->account_id, Account_Deletion_Job::MAX_RETRIES - 1 );

		$this->assertSame( [], $this->scheduler->summary(), 'no further attempt' );
		$this->assertSame( Account_Deletion_Progress::FAILED, $this->progress->get( $this->account_id )['state'] );
		$this->assertNotNull( $this->accounts()->get( $this->account_id ) );
		$this->assertStringContainsString( 'RuntimeException', implode( "\n", $this->log ), 'the log names the class' );
		$this->assertStringNotContainsString( 'nope', implode( "\n", $this->log ), 'and not the message' );
	}

	/**
	 * A deletion with more rows than one run may handle stops at the time limit and queues itself.
	 *
	 * @return void
	 */
	public function test_a_long_deletion_queues_itself_when_the_run_is_out_of_time(): void {
		$steps = [
			'links' => function () {
				$this->now += Account_Deletion_Job::RUN_SECONDS + 1;

				return 500;
			},
		];

		$this->job( $steps )->run( $this->account_id, 0 );

		$next = $this->scheduler->of( 'woodev_fake_seller_delete_account' );
		$this->assertCount( 1, $next );
		$this->assertSame( [ $this->account_id, 0 ], $next[0]['args'] );
		$this->assertNotNull( $this->accounts()->get( $this->account_id ), 'the row waits for the rest' );
	}

	/**
	 * A run that made progress starts the failure count again, so transient errors spread over a long deletion never add up to the
	 * limit.
	 *
	 * @return void
	 */
	public function test_progress_resets_the_failure_count(): void {
		$steps = [
			'links' => function () {
				$this->now += Account_Deletion_Job::RUN_SECONDS + 1;

				return 500;
			},
		];

		$this->job( $steps )->run( $this->account_id, Account_Deletion_Job::MAX_RETRIES - 1 );

		$next = $this->scheduler->of( 'woodev_fake_seller_delete_account' );
		$this->assertSame( [ $this->account_id, 0 ], $next[0]['args'] );
	}

	/**
	 * A deleted account is not an error: a late duplicate of the job does nothing.
	 *
	 * @return void
	 */
	public function test_a_job_for_a_missing_account_does_nothing(): void {
		$this->job( $this->steps( [ 'links' => 1 ] ) )->run( 999, 0 );

		$this->assertSame( [], $this->calls );
		$this->assertSame( [], $this->scheduler->summary() );
	}

	/**
	 * The adapter's cleanup is asked in batches, and a notification address it cannot remove becomes a note.
	 *
	 * @return void
	 */
	public function test_the_adapter_cleans_up_and_the_address_note_follows_its_answer(): void {
		$cleaner = new class() implements Account_Data_Cleaner {
			/**
			 * Rows left.
			 *
			 * @var int
			 */
			public $rows = 3;

			/**
			 * Whether the address can be removed.
			 *
			 * @var bool
			 */
			public $removes = false;

			/**
			 * Deletes a batch.
			 *
			 * @param Account $account Account.
			 * @param int     $limit   Batch.
			 * @return int
			 */
			public function erase_account_data( Account $account, int $limit ): int {
				$done       = min( $this->rows, 2 );
				$this->rows -= $done;

				return $done;
			}

			/**
			 * Tries to remove the address.
			 *
			 * @param Account $account Account.
			 * @return bool
			 */
			public function unsubscribe_notifications( Account $account ): bool {
				return $this->removes;
			}
		};

		$this->job( [], $cleaner, true )->run( $this->account_id, 0 );

		$this->assertSame( 0, $cleaner->rows, 'asked until nothing is left' );
		$done = $this->progress->get( $this->account_id );
		$this->assertSame( Account_Deletion_Progress::DONE, $done['state'] );
		$this->assertCount( 1, $done['notes'], 'the address stays at the marketplace: the merchant is told' );
	}

	/**
	 * An address the adapter removed needs no note; an account that never registered one needs none either.
	 *
	 * @return void
	 */
	public function test_no_note_when_the_address_was_removed_or_never_registered(): void {
		$cleaner = new class() implements Account_Data_Cleaner {
			/**
			 * Deletes a batch.
			 *
			 * @param Account $account Account.
			 * @param int     $limit   Batch.
			 * @return int
			 */
			public function erase_account_data( Account $account, int $limit ): int {
				return 0;
			}

			/**
			 * Removes the address.
			 *
			 * @param Account $account Account.
			 * @return bool
			 */
			public function unsubscribe_notifications( Account $account ): bool {
				return true;
			}
		};

		$this->job( [], $cleaner, true )->run( $this->account_id, 0 );
		$this->assertSame( [], $this->progress->get( $this->account_id )['notes'] );

		$this->accounts()->save( new Account( 0, 'fake_mp', 'Второй', false, [ 'cabinet_ref' => 'c-2', 'api_token' => 't' ] ) );
		$second = $this->accounts()->find_by_identifier( 'c-2' );
		$this->job( [], null, false )->run( $second->id(), 0 );
		$this->assertSame( [], $this->progress->get( $second->id() )['notes'], 'no address registered: nothing to say' );
	}

	/**
	 * Scripted steps: each removes two rows at a time until its rows are gone, and records that it ran.
	 *
	 * @param array<string,int> $rows Rows each step has to remove.
	 * @return array<string,callable>
	 */
	private function steps( array $rows ): array {
		$this->left = $rows;
		$steps      = [];

		foreach ( array_keys( $rows ) as $name ) {
			$steps[ $name ] = function () use ( $name ): int {
				$this->calls[] = $name . ( null === $this->accounts()->get( $this->account_id ) ? ':gone' : ':row' );
				$removed       = min( $this->left[ $name ], 2 );
				$this->left[ $name ] -= $removed;

				return $removed;
			};
		}

		return $steps;
	}

	/**
	 * Builds the job over the test doubles.
	 *
	 * @param array<string,callable>    $steps       Steps.
	 * @param Account_Data_Cleaner|null $cleaner     The adapter's cleanup.
	 * @param bool                      $has_address Whether the account registered a notification address.
	 * @return Account_Deletion_Job
	 */
	private function job( array $steps, ?Account_Data_Cleaner $cleaner = null, bool $has_address = false ): Account_Deletion_Job {
		return new Account_Deletion_Job(
			$this->accounts(),
			$this->job_gate(),
			$this->progress,
			new Account_Deletion_Scheduler( $this->names() ),
			$steps,
			$cleaner,
			static function () use ( $has_address ): bool {
				return $has_address;
			},
			[ $this, 'log_line' ],
			$this->clock()
		);
	}
}

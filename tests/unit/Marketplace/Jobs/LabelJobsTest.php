<?php
/**
 * Action Scheduler jobs of the label flow.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Actions;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Flows\Label_Answer;
use Woodev\Framework\Marketplace\Flows\Label_Exception;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Flows\Label_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * Moved from the Ozon plugin's `LabelsTest` (the job half) and `LabelPollSchedulingTest`: the poll follows the error consequence of
 * spec 4.3 and the back-off ladder, ends a task visibly instead of leaving it open, and never throws out.
 */
final class LabelJobsTest extends Label_Test_Case {
	private const POLL   = 'woodev_fake_seller_poll_task';
	private const PRUNE  = 'woodev_fake_seller_prune_labels';
	private const FAILED = 'woodev_fake_seller_label_failed';

	/**
	 * The hooks are named after the plugin, and the donor's names are kept so queued actions survive the move.
	 *
	 * @return void
	 */
	public function test_the_hooks_are_named_after_the_plugin(): void {
		$this->assertSame( self::POLL, $this->jobs()->poll_hook() );
		$this->assertSame( self::PRUNE, $this->jobs()->prune_hook() );
	}

	/**
	 * `request` stores the task and queues its first poll a minute away.
	 *
	 * @return void
	 */
	public function test_request_stores_the_task_and_queues_the_first_poll(): void {
		$task_id = $this->jobs()->request( $this->account(), [ '0001-1', '0002-1' ] );

		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->labels->task( $task_id )['status'] );
		$this->assertCount( 1, $this->scheduler->of( self::POLL ) );
		$this->assertSame( [ $task_id, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );
		$this->assertEqualsWithDelta( 60, $this->scheduler->of( self::POLL )[0]['delay'], 2 );
		$this->assertSame( 'woodev-fake-seller', $this->scheduler->of( self::POLL )[0]['group'] );
	}

	/**
	 * The same poll is not queued twice.
	 *
	 * @return void
	 */
	public function test_the_same_poll_is_queued_once(): void {
		$jobs = $this->jobs();

		$jobs->queue_poll( 5 );
		$jobs->queue_poll( 5 );
		$jobs->queue_poll( 5, 60, 2 );

		$this->assertCount( 2, $this->scheduler->of( self::POLL ), 'one for [5, 0], one for [5, 2]' );
	}

	/**
	 * Action Scheduler refusing to store the poll is an error the caller must see.
	 *
	 * @return void
	 */
	public function test_a_poll_that_could_not_be_stored_is_an_exception(): void {
		$this->scheduler->zero_for[ self::POLL ] = true;

		$this->expectException( Persistence_Exception::class );
		$this->jobs()->queue_poll( 5 );
	}

	/**
	 * A pending answer queues the next poll with no failures counted.
	 *
	 * @return void
	 */
	public function test_a_pending_answer_queues_the_next_poll_with_no_failures(): void {
		$task_id = $this->task( [ '0001-1' ] );

		$this->jobs()->poll( $task_id, 3 );

		$this->assertSame( [ $task_id, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );
	}

	/**
	 * A file ends the task: no further poll.
	 *
	 * @return void
	 */
	public function test_a_file_ends_the_task_and_queues_nothing(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = $this->file_answer();

		$this->jobs()->poll( $task_id );

		$this->assertSame( Async_Task::STATUS_DONE, $this->labels->task( $task_id )['status'] );
		$this->assertSame( [], $this->scheduler->of( self::POLL ) );
	}

	/**
	 * A throttled poll is rescheduled after the wait the marketplace asked for, not failed.
	 *
	 * @return void
	 */
	public function test_a_throttled_poll_is_rescheduled_not_failed(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 45 );

		$this->jobs()->poll( $task_id, 1 );

		$queued = $this->scheduler->of( self::POLL );
		$this->assertSame( [ $task_id, 1 ], $queued[0]['args'], 'the failure count is kept' );
		$this->assertEqualsWithDelta( 45, $queued[0]['delay'], 2 );
		$this->assertNotSame( Async_Task::STATUS_FAILED, $this->labels->task( $task_id )['status'] );
	}

	/**
	 * Temporary errors walk the ladder of spec 4.3 (1, 3, 7, 15, 30 minutes); the sixth failure ends the task.
	 *
	 * @return void
	 */
	public function test_temporary_failures_follow_the_backoff_ladder_and_then_end_the_task(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Fake_Remote_Exception::of( Remote_Error::RETRY, 'bad gateway' );

		for ( $failed = 0; $failed < 5; $failed++ ) {
			$this->jobs()->poll( $task_id, $failed );
		}

		$polls = $this->scheduler->of( self::POLL );
		$this->assertEqualsWithDelta( [ 60, 180, 420, 900, 1800 ], array_column( $polls, 'delay' ), 2 );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_map( static function ( array $poll ): int {
			return $poll['args'][1];
		}, $polls ) );
		$this->assertNotSame( Async_Task::STATUS_FAILED, $this->labels->task( $task_id )['status'] );

		Actions\expectDone( self::FAILED )->once();
		$this->jobs()->poll( $task_id, 5 );
		$this->assertSame( Async_Task::STATUS_FAILED, $this->labels->task( $task_id )['status'] );
	}

	/**
	 * An error nobody classified (our own storage or code) takes the same ladder.
	 *
	 * @return void
	 */
	public function test_an_unclassified_failure_takes_the_ladder_too(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = new \RuntimeException( 'boom' );

		$this->jobs()->poll( $task_id, 0 );

		$this->assertSame( [ $task_id, 1 ], $this->scheduler->of( self::POLL )[0]['args'] );
	}

	/**
	 * A method the marketplace retired ends the task visibly, with the failed event, and nothing escapes the runner.
	 *
	 * @return void
	 */
	public function test_a_retired_method_ends_the_task_instead_of_leaving_it_open(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Fake_Remote_Exception::of( Remote_Error::API_CHANGED, 'gone' );

		Actions\expectDone( self::FAILED )->once();
		$this->jobs()->poll( $task_id, 0 );

		$this->assertSame( Async_Task::STATUS_FAILED, $this->labels->task( $task_id )['status'] );
		$this->assertSame( [], $this->scheduler->of( self::POLL ), 'a finished task is not polled again' );
	}

	/**
	 * A refused key pauses the account and ends the task with the sentence about the key.
	 *
	 * @return void
	 */
	public function test_a_refused_key_pauses_the_account_and_ends_the_task(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'bad key' );

		$this->jobs()->poll( $task_id, 0 );

		$task = $this->labels->task( $task_id );
		$this->assertSame( Async_Task::STATUS_FAILED, $task['status'] );
		$this->assertStringContainsString( 'ключ доступа', $task['result']['message'] );
		$this->assertSame( [ $this->account_id ], $this->classifier->refused, 'the account was paused through the adapter classifier' );
	}

	/**
	 * A document write the database refuses does not escape; the poll is queued again with the failure counted and no ready event.
	 *
	 * @return void
	 */
	public function test_a_failed_document_write_is_retried_and_leaves_no_ready_event(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = $this->file_answer();
		$this->db->refuse_inserts = true;

		Actions\expectDone( 'woodev_fake_seller_label_ready' )->never();
		$this->jobs()->poll( $task_id, 0 );

		$this->assertSame( [ $task_id, 1 ], $this->scheduler->of( self::POLL )[0]['args'] );
		$this->assertEqualsWithDelta( 60, $this->scheduler->of( self::POLL )[0]['delay'], 2 );
		$this->assertNotSame( Async_Task::STATUS_DONE, $this->labels->task( $task_id )['status'] );
	}

	/**
	 * A failed SELECT is an error, not «no such task»; the poll is queued again.
	 *
	 * @return void
	 */
	public function test_a_failed_task_read_is_not_taken_for_an_absent_task(): void {
		$task_id = $this->task( [ '0001-1' ] );
		$this->db->define( 'ALTER TABLE ' . Sqlite_Tables::table( 'tasks' ) . ' RENAME TO tasks_moved' );

		try {
			$this->labels->task( $task_id );
			$this->fail( 'A database failure must be an exception.' );
		} catch ( Persistence_Exception $e ) {
			$this->assertStringContainsString( 'label task', $e->getMessage() );
		}

		$this->jobs()->poll( $task_id, 0 );
		$this->assertSame( [ $task_id, 1 ], $this->scheduler->of( self::POLL )[0]['args'], 'the poll is not silently dropped' );
	}

	/**
	 * When even the next poll cannot be queued, the task is ended visibly.
	 *
	 * @return void
	 */
	public function test_a_poll_that_cannot_be_requeued_ends_the_task(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Fake_Remote_Exception::of( Remote_Error::RETRY, 'bad gateway' );
		$this->scheduler->zero_for[ self::POLL ] = true;

		$this->jobs()->poll( $task_id, 0 );

		$this->assertSame( Async_Task::STATUS_FAILED, $this->labels->task( $task_id )['status'] );
	}

	/**
	 * Without a licence the poll waits an hour and asks the marketplace nothing; a renewed licence resumes it.
	 *
	 * @return void
	 */
	public function test_a_lapsed_licence_defers_the_poll_without_a_call(): void {
		$task_id        = $this->task( [ '0001-1' ] );
		$this->licensed = false;

		$this->jobs()->poll( $task_id, 2 );

		$this->assertSame( [], $this->provider->calls );
		$this->assertSame( [ $task_id, 2 ], $this->scheduler->of( self::POLL )[0]['args'] );
		$this->assertEqualsWithDelta( Job_Gate::LICENCE_DELAY, $this->scheduler->of( self::POLL )[0]['delay'], 2 );
	}

	/**
	 * A disabled account, and a task that is not a label task or is gone, are left alone silently.
	 *
	 * @return void
	 */
	public function test_a_disabled_account_or_an_unknown_task_is_left_alone(): void {
		$task_id = $this->task( [ '0001-1' ] );
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->jobs()->poll( $task_id );
		$this->jobs()->poll( 404 );

		$this->assertSame( [], $this->provider->calls );
		$this->assertSame( [], $this->scheduler->of( self::POLL ) );
	}

	/**
	 * A provider sentence in `request` surfaces and queues nothing.
	 *
	 * @return void
	 */
	public function test_a_refused_request_queues_no_poll(): void {
		$this->provider->request_fails = new Label_Exception( 'Заказ не собран' );

		try {
			$this->jobs()->request( $this->account(), [ '0001-1' ] );
			$this->fail( 'The sentence must surface.' );
		} catch ( Label_Exception $e ) {
			$this->assertSame( 'Заказ не собран', $e->getMessage() );
		}

		$this->assertSame( [], $this->scheduler->of( self::POLL ) );
	}

	/**
	 * The daily prune is created once; the job deletes documents older than seven days.
	 *
	 * @return void
	 */
	public function test_the_prune_is_created_once_and_deletes_old_documents(): void {
		$jobs = $this->jobs();

		$jobs->ensure_prune();
		$jobs->ensure_prune();
		$this->assertCount( 1, $this->scheduler->of( self::PRUNE ) );

		$old        = $this->document( [ 'a' ] );
		$this->now += 8 * DAY_IN_SECONDS;
		$new        = $this->document( [ 'b' ] );

		$jobs->prune();

		$this->assertNull( $this->labels->document( $old ) );
		$this->assertNotNull( $this->labels->document( $new ) );
	}

	/**
	 * Registering hooks the poll with its two arguments, the prune and the Action Scheduler init.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_jobs(): void {
		$jobs = $this->jobs();
		$jobs->register();

		$this->assertNotFalse( has_action( self::POLL, [ $jobs, 'poll' ] ) );
		$this->assertNotFalse( has_action( self::PRUNE, [ $jobs, 'prune' ] ) );
		$this->assertNotFalse( has_action( 'action_scheduler_init', [ $jobs, 'ensure_prune' ] ) );
	}

	/**
	 * An answer of an unknown shape still ends the task (it is stored as JSON) and queues nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_shape_ends_the_task(): void {
		$task_id                = $this->task( [ '0001-1' ] );
		$this->provider->answer = Label_Answer::other( [ 'result' => [ 'url' => 'x' ] ] );

		$this->jobs()->poll( $task_id );

		$this->assertSame( Async_Task::STATUS_DONE, $this->labels->task( $task_id )['status'] );
	}
}

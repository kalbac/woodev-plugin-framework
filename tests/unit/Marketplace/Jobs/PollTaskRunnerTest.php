<?php
/**
 * The `poll_task` job: the import result, the card lifecycle, the deadlines, the failure ladder and the recovery sweep.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Actions;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Card_Outcome;
use Woodev\Framework\Marketplace\Flows\Import_Outcome;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner
 */
final class PollTaskRunnerTest extends Export_Test_Case {
	private const POLL   = 'woodev_fake_seller_poll_task';
	private const EXPORT = 'woodev_fake_seller_export_batch';

	/**
	 * Task of the link the test sent.
	 *
	 * @var int
	 */
	private $task_id = 0;

	/**
	 * An import item that is not reported yet keeps the task open and the poll comes back in a minute.
	 *
	 * @return void
	 */
	public function test_an_open_import_is_polled_again(): void {
		$link = $this->sent( 'NB-1' );

		$this->poll();

		$this->assertSame( Async_Task::STATUS_POLLING, $this->ledger()->get( $this->task_id )->status() );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( [ [ 'import', 'task-9001' ] ], $this->lifecycle->reads );
		$this->assertPollLater( 0, Poll_Task_Runner::FAST_INTERVAL );

		$this->now += Poll_Task_Runner::FAST_PERIOD + 1;
		$this->poll();
		$this->assertPollLater( 0, Poll_Task_Runner::SLOW_INTERVAL );
	}

	/**
	 * A final import verdict and a created card in one run: seeded, created, identifiers stored, callback fired once.
	 *
	 * @return void
	 */
	public function test_an_imported_and_created_card_is_seeded_and_marked_created(): void {
		$link = $this->sent( 'NB-1' );
		$this->import_ok( 'NB-1', 'c-1' );
		$this->lifecycle->cards = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1', 'sku_ref' => 'sku-5', 'model_ref' => 'm-1' ] ) ];

		$this->poll();

		$created = $this->link_of( $link->wc_product_id() );
		$this->assertSame( Link_Record::STATE_CREATED, $created->card_state() );
		$this->assertEqualsCanonicalizing( [ 'card_ref' => 'c-1', 'sku_ref' => 'sku-5', 'model_ref' => 'm-1' ], $created->remote_ids() );
		$this->assertSame( [ (int) $link->link_id() ], $this->seeded );
		$this->assertSame( [ [ (int) $link->link_id(), $this->account_id ] ], $this->created );
		$this->assertSame( Async_Task::STATUS_DONE, $this->ledger()->get( $this->task_id )->status() );
		$this->assertSame( [], $this->scheduler->of( self::POLL ), 'nothing left to poll' );
	}

	/**
	 * A card that does not exist yet moves along the marketplace's states and the poll comes back.
	 *
	 * @return void
	 */
	public function test_a_card_still_being_created_keeps_the_poll_going(): void {
		$link = $this->sent( 'NB-1' );
		$this->import_ok( 'NB-1', 'c-1' );
		$this->lifecycle->cards = [ 'c-1' => new Card_Outcome( Link_Record::STATE_SKU_CREATED, false, false, 'processing' ) ];

		$this->poll();

		$this->assertSame( Link_Record::STATE_SKU_CREATED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( [], $this->seeded );
		$this->assertPollLater( 0, Poll_Task_Runner::FAST_INTERVAL );
	}

	/**
	 * A card created without an identifier the marketplace gives late is read until it arrives, and the callback fires only once.
	 *
	 * @return void
	 */
	public function test_a_late_identifier_is_learned_without_firing_the_callback_again(): void {
		$link = $this->sent( 'NB-1' );
		$this->import_ok( 'NB-1', 'c-1' );
		$this->lifecycle->cards = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1' ] ) ];

		$this->poll();

		$this->assertSame( 1, count( $this->created ) );
		$this->assertNull( $this->link_of( $link->wc_product_id() )->remote_id( 'model_ref' ) );
		$this->assertPollLater( 0, Poll_Task_Runner::FAST_INTERVAL );

		$this->lifecycle->cards = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1', 'model_ref' => 'm-7' ] ) ];
		$this->poll();

		$this->assertSame( 'm-7', $this->link_of( $link->wc_product_id() )->remote_id( 'model_ref' ) );
		$this->assertSame( 1, count( $this->created ), 'the callback fired once' );

		$this->scheduler->actions = [];
		$this->poll();
		$this->assertSame( [], $this->scheduler->of( self::POLL ), 'everything known: the poll stops' );
	}

	/**
	 * A seeding that fails leaves the card for the next poll: not created, no callback.
	 *
	 * @return void
	 */
	public function test_a_failed_seeding_leaves_the_card_for_the_next_poll(): void {
		$link = $this->sent( 'NB-1' );
		$this->import_ok( 'NB-1', 'c-1' );
		$this->lifecycle->cards = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1', 'model_ref' => 'm-1' ] ) ];
		$this->seed_failure     = new \RuntimeException( 'db is down' );

		$this->poll();

		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( [], $this->created );
		$this->assertStringContainsString( 'db is down', implode( "\n", $this->log ) );

		$this->seed_failure = null;
		$this->poll();
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $link->wc_product_id() )->card_state() );
	}

	/**
	 * A reset during the request makes the answer stale: nothing is seeded.
	 *
	 * @return void
	 */
	public function test_a_reset_link_is_not_seeded(): void {
		$link = $this->sent( 'NB-1' );
		$this->import_ok( 'NB-1', 'c-1' );
		$this->lifecycle->cards   = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1', 'model_ref' => 'm-1' ] ) ];
		$this->lifecycle->on_read = function () use ( $link ): void {
			$this->links()->reset_state( $this->account_id, [ (int) $link->link_id() ] );
		};

		$this->poll();

		$this->assertSame( [], $this->seeded );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( $link->wc_product_id() )->card_state() );
	}

	/**
	 * A card the marketplace declines fails at once with its words; an item with import errors fails with the stored errors.
	 *
	 * @return void
	 */
	public function test_declined_and_erroneous_items_fail_with_the_marketplaces_words(): void {
		list( $declined, $bad ) = $this->sent_batch( [ 'NB-1', 'NB-2' ] );
		$this->lifecycle->import = [
			'NB-1' => new Import_Outcome( true, Link_Record::STATE_OFFER_VALIDATED, Task_Ledger::ITEM_IMPORTED, '', '', [], [ 'card_ref' => 'c-1' ] ),
			'NB-2' => new Import_Outcome( true, Link_Record::STATE_FAILED, Task_Ledger::ITEM_FAILED, 'bad_image', 'Image is too small', [ [ 'code' => 'bad_image', 'message' => 'Image is too small' ] ] ),
		];
		$this->lifecycle->cards  = [ 'c-1' => new Card_Outcome( Link_Record::STATE_OFFER_VALIDATED, false, true, 'declined: no brand' ) ];

		$this->poll();

		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( $declined->wc_product_id() )->card_state() );
		$this->assertSame( 'card_declined', $this->links()->last_error( (int) $declined->link_id() )['code'] );
		$this->assertStringContainsString( 'declined: no brand', $this->links()->last_error( (int) $declined->link_id() )['text'] );
		$this->assertSame( 'bad_image', $this->links()->last_error( (int) $bad->link_id() )['code'] );
		$this->assertSame( 'Image is too small', $this->links()->last_error( (int) $bad->link_id() )['text'] );
		$this->assertSame( Async_Task::STATUS_DONE_WITH_ERRORS, $this->ledger()->get( $this->task_id )->status() );
	}

	/**
	 * A card not created three hours after the submit fails with the marketplace's last status.
	 *
	 * @return void
	 */
	public function test_a_card_not_created_in_three_hours_fails(): void {
		$link = $this->sent( 'NB-1' );
		$this->import_ok( 'NB-1', 'c-1' );
		$this->lifecycle->cards = [ 'c-1' => new Card_Outcome( Link_Record::STATE_PICS_DELIVERED, false, false, 'moderation' ) ];

		$this->now += Poll_Task_Runner::CARD_DEADLINE + 1;
		$this->poll();

		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( 'card_not_created', $this->links()->last_error( (int) $link->link_id() )['code'] );
		$this->assertStringContainsString( 'moderation', $this->links()->last_error( (int) $link->link_id() )['text'] );
	}

	/**
	 * An import unfinished after a day expires: the open items and their links fail.
	 *
	 * @return void
	 */
	public function test_an_import_unfinished_after_a_day_expires(): void {
		$link = $this->sent( 'NB-1' );

		$this->now += Poll_Task_Runner::TASK_DEADLINE + 1;
		$this->poll();

		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->ledger()->get( $this->task_id )->status() );
		$this->assertSame( 'task_expired', $this->links()->last_error( (int) $link->link_id() )['code'] );
		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( [], $this->lifecycle->reads, 'no request for a task that expired' );
	}

	/**
	 * Without a licence, or while the account is paused, the poll waits and asks nothing.
	 *
	 * @return void
	 */
	public function test_the_gate_makes_the_poll_wait(): void {
		$this->sent( 'NB-1' );

		$this->licensed = false;
		$this->poll();

		$this->assertPollLater( 0, Job_Gate::LICENCE_DELAY );
		$this->assertSame( [], $this->lifecycle->reads );

		$this->licensed = true;
		$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$this->poll();
		$this->assertPollLater( 0, Job_Gate::BUSY_DELAY );
	}

	/**
	 * A refused key pauses the account; a throttle waits as long as asked without counting; a server error climbs the ladder.
	 *
	 * @return void
	 */
	public function test_remote_failures_follow_the_classifier(): void {
		$this->sent( 'NB-1' );

		$this->lifecycle->failure = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow', 30 );
		$this->poll( 2 );
		$this->assertPollLater( 2, 30 );

		$this->lifecycle->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 502' );
		$this->poll( 1 );
		$this->assertPollLater( 2, Backoff::seconds_after( 2 ) );

		$this->lifecycle->failure = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key refused' );
		$this->poll( 2 );
		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertPollLater( 2, Account_Pause::SECONDS );

		$this->lifecycle->reads = [];
		$this->poll( 2 );
		$this->assertSame( [], $this->lifecycle->reads, 'a paused account is not asked' );
	}

	/**
	 * The fifth failed poll gives up on what is open: the items and links fail, the task is `failed`.
	 *
	 * @return void
	 */
	public function test_the_fifth_failure_gives_up(): void {
		$link = $this->sent( 'NB-1' );
		$this->lifecycle->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 502' );

		$this->poll( Poll_Task_Runner::MAX_ATTEMPTS - 1 );

		$this->assertSame( Async_Task::STATUS_FAILED, $this->ledger()->get( $this->task_id )->status() );
		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertStringContainsString( 'HTTP 502', $this->links()->last_error( (int) $link->link_id() )['text'] );
	}

	/**
	 * An exception nobody classified is retried the same way and reported.
	 *
	 * @return void
	 */
	public function test_an_unexpected_exception_is_retried_and_reported(): void {
		$this->sent( 'NB-1' );
		$this->lifecycle->failure = new \LogicException( 'boom' );

		$this->poll();

		$this->assertPollLater( 1, Backoff::seconds_after( 1 ) );
		$this->assertStringContainsString( 'boom', implode( "\n", $this->log ) );
	}

	/**
	 * A run that lost the account claim mid-way writes nothing more.
	 *
	 * @return void
	 */
	public function test_a_superseded_run_stops_without_finalising(): void {
		$link = $this->sent( 'NB-1' );
		$this->import_ok( 'NB-1', 'c-1' );
		$this->lifecycle->on_read = function (): void {
			$this->now += Job_Gate::CLAIM_TTL + 5;
			$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		};

		$this->poll();

		$this->assertStringContainsString( 'taken over', implode( "\n", $this->log ) );
		$this->assertNotSame( Async_Task::STATUS_DONE, $this->ledger()->get( $this->task_id )->status() );
		$this->assertSame( [], $this->seeded );
		$this->assertNotSame( Link_Record::STATE_CREATED, $this->link_of( $link->wc_product_id() )->card_state() );
	}

	/**
	 * The sweep polls a task nobody touched for half an hour.
	 *
	 * @return void
	 */
	public function test_the_sweep_polls_a_stale_task(): void {
		$this->sent( 'NB-1' );
		$this->scheduler->actions = [];

		$this->now += Poll_Task_Runner::STALE_AFTER + 1;
		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertPollLater( 0, 0 );
	}

	/**
	 * The sweep gives the links of a task that never got the marketplace's number back to the export queue.
	 *
	 * @return void
	 */
	public function test_the_sweep_requeues_a_task_that_was_never_sent(): void {
		$link = $this->claimed_without_number( 'NB-1' );
		$this->scheduler->actions = [];

		$this->now += Poll_Task_Runner::STALE_AFTER + 1;
		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( Async_Task::STATUS_FAILED, $this->ledger()->get( $this->task_id )->status() );
		$batches = $this->scheduler->of( self::EXPORT );
		$this->assertSame( [ $this->account_id, [ (int) $link->link_id() ], 0 ], $batches[0]['args'] );
	}

	/**
	 * Links an export retry could not reschedule get a new batch from the sweep.
	 *
	 * @return void
	 */
	public function test_the_sweep_requeues_orphaned_queued_links(): void {
		$link = $this->sent( 'NB-1' );
		$this->links()->advance( (int) $link->link_id(), $this->task_id, Link_Record::STATE_QUEUED, [], null, Link_Record::STATE_SUBMITTED );
		$this->scheduler->actions = [];

		$this->now += Poll_Task_Runner::STALE_AFTER + 1;
		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertCount( 1, $this->scheduler->of( self::EXPORT ) );
		$this->assertNull( $this->task_of( $this->link_of( $link->wc_product_id() ) ), 'the task id is cleared once the batch is queued' );
	}

	/**
	 * The job hooks itself under the plugin's name; a task of another kind or a missing one is ignored.
	 *
	 * @return void
	 */
	public function test_register_and_foreign_tasks(): void {
		Actions\expectAdded( self::POLL )->once();
		$this->poll_runner()->register();

		$this->poll_runner()->run( 9999, 0 );
		$this->assertSame( [], $this->lifecycle->reads );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Sends a link through the export job, so it is `submitted` and owns a task.
	 *
	 * @param string $sku Article.
	 * @return Link_Record
	 */
	private function sent( string $sku ): Link_Record {
		return $this->sent_batch( [ $sku ] )[0];
	}

	/**
	 * Sends several links in one batch: one task owns them all.
	 *
	 * @param string[] $skus Articles.
	 * @return Link_Record[]
	 */
	private function sent_batch( array $skus ): array {
		$ids = [];
		foreach ( $skus as $sku ) {
			$product = $this->add_product( [ 'sku' => $sku ] );
			$this->service()->enqueue( $this->account_now(), [ $product->get_id() ] );
			$ids[] = (int) $this->link_of( (int) $product->get_id() )->link_id();
		}

		$this->export_runner()->run( $this->account_id, $ids, 0 );

		$links         = array_map( [ $this, 'link_by_id' ], $ids );
		$this->task_id = (int) $this->task_of( $links[0] );

		$this->lifecycle->reads   = [];
		$this->scheduler->actions = array_values(
			array_filter(
				$this->scheduler->actions,
				static function ( array $action ): bool {
					return self::POLL !== $action['hook'] || [ 0, 0 ] === $action['args'];
				}
			)
		);

		return $links;
	}

	/**
	 * Leaves a link `submitted` for a task that never reached the marketplace (the run died before the request).
	 *
	 * @param string $sku Article.
	 * @return Link_Record
	 */
	private function claimed_without_number( string $sku ): Link_Record {
		$product = $this->add_product( [ 'sku' => $sku ] );
		$this->service()->enqueue( $this->account_now(), [ $product->get_id() ] );
		$link = $this->link_of( (int) $product->get_id() );

		$this->task_id = $this->ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [] );
		$this->links()->claim_for_task( $this->task_id, [ (int) $link->link_id() ] );

		return $this->link_of( (int) $product->get_id() );
	}

	/**
	 * Loads a link by its id.
	 *
	 * @param int $link_id Link id.
	 * @return Link_Record
	 */
	private function link_by_id( int $link_id ): Link_Record {
		return $this->links()->get( $link_id );
	}

	/**
	 * Makes the marketplace report an import item as imported with its card identifier.
	 *
	 * @param string $offer_id Article.
	 * @param string $card_ref Card identifier.
	 * @return void
	 */
	private function import_ok( string $offer_id, string $card_ref ): void {
		$this->lifecycle->import[ $offer_id ] = new Import_Outcome( true, Link_Record::STATE_OFFER_VALIDATED, Task_Ledger::ITEM_IMPORTED, '', '', [], [ 'card_ref' => $card_ref ] );
	}

	/**
	 * Runs the job for the current task.
	 *
	 * @param int $attempt Failed polls in a row.
	 * @return void
	 */
	private function poll( int $attempt = 0 ): void {
		$this->scheduler->actions = array_values(
			array_filter(
				$this->scheduler->actions,
				static function ( array $action ): bool {
					return self::POLL !== $action['hook'];
				}
			)
		);

		$this->poll_runner()->run( $this->task_id, $attempt );
	}

	/**
	 * Asserts the next poll was scheduled.
	 *
	 * @param int $attempt Attempt in the new action.
	 * @param int $delay   Seconds.
	 * @return void
	 */
	private function assertPollLater( int $attempt, int $delay ): void {
		$polls = $this->scheduler->of( self::POLL );
		$this->assertNotSame( [], $polls, 'a poll is scheduled' );
		$last = end( $polls );
		$this->assertSame( [ $this->task_id, $attempt ], $last['args'] );
		$this->assertSame( $delay, $last['delay'] );
	}
}

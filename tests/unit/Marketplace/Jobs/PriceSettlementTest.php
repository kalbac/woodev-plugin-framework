<?php
/**
 * Prices the marketplace decides on later: the price task, its poll and its outcomes.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Price_Poll_Runner;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Price\Flush_Result;
use Woodev\Framework\Marketplace\Price\Price_Refusal;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Clock;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The second marketplace of the fakes answers a price upload with a task ({@see Fake_Price_Pusher}): pending, then accepted,
 * refused, held in quarantine or never answered. The flush and the poll run over the real queue, ledger and links on SQLite.
 */
final class PriceSettlementTest extends Sync_Test_Case {
	/**
	 * The marketplace double.
	 *
	 * @var Fake_Price_Pusher
	 */
	private $prices;

	/**
	 * Next marketplace card number.
	 *
	 * @var int
	 */
	private $nm_id = 5000000;

	/**
	 * Builds the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->prices = new Fake_Price_Pusher( new Fake_Clock() );
	}

	/**
	 * A pending upload opens one price task with the upload id, the links and the revisions, and schedules its first poll by the
	 * adapter's interval. Nothing is sent again while it is open.
	 *
	 * @return void
	 */
	public function test_a_pending_upload_opens_a_price_task_and_the_price_waits(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );

		$result = $this->flush_now();
		$task   = $this->only_task();

		$this->assertSame( 'Отправлено, ждём ответа маркетплейса: 1', $result->message() );
		$this->assertSame( [ Async_Task::KIND_PRICE, 'price-task-1', Async_Task::STATUS_SUBMITTED ], [ $task->kind(), $task->remote_task_id(), $task->status() ] );
		$this->assertSame( [ $this->link_id( $product ) => 1 ], $task->result()['revisions'] );
		$this->assertSame( [ 'a' ], array_column( $this->task_ledger()->items( (int) $task->task_id() ), 'offer_id' ) );
		$this->assertSame( (int) $task->task_id(), (int) $this->price_row( $product )['task_id'] );
		$this->assertNull( $this->price_row( $product )['sent_payload'] );

		$polls = $this->scheduler->of( $this->poll_hook() );
		$this->assertSame( [ (int) $task->task_id(), 0 ], $polls[0]['args'] );
		$this->assertSame( Fake_Price_Pusher::INTERVAL, $polls[0]['delay'] );

		$this->now += 4000;
		$this->flush_now();

		$this->assertSame( 1, $this->prices->created_task_count() );
		$this->assertCount( 1, $this->all_tasks() );
	}

	/**
	 * An open price task is no work the card owes: the activity column and the export lock do not see it.
	 *
	 * @return void
	 */
	public function test_an_open_price_task_does_not_lock_the_card(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();

		$this->assertSame( [], $this->task_ledger()->open_items( $this->account_id, [ $this->link_id( $product ) ] ) );
	}

	/**
	 * While the marketplace has not decided, the task is polled again by the adapter's interval and stays open.
	 *
	 * @return void
	 */
	public function test_an_undecided_task_is_polled_again(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->prices->undecided = true;
		$this->scheduler->actions = [];

		$this->now += Fake_Price_Pusher::INTERVAL;
		$this->poll();

		$this->assertSame( Async_Task::STATUS_POLLING, $this->only_task()->status() );
		$this->assertSame( Fake_Price_Pusher::INTERVAL, $this->scheduler->of( $this->poll_hook() )[0]['delay'] );
		$this->assertNotNull( $this->price_row( $product )['task_id'] );
	}

	/**
	 * Accepted: the sent price is the marketplace's price, the item is done and the task is closed.
	 *
	 * @return void
	 */
	public function test_an_accepted_price_is_settled(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->scheduler->actions = [];

		$this->poll();

		$row = $this->price_row( $product );
		$this->assertSame( '900.00', $row['sent_payload']['price'] );
		$this->assertNull( $row['task_id'] );
		$this->assertNull( $row['in_flight_rev'] );
		$this->assertNull( $row['last_error'] );
		$this->assertSame( '900.00', $this->link_price( $product ) );
		$this->assertSame( Async_Task::STATUS_DONE, $this->only_task()->status() );
		$this->assertSame( [ Task_Ledger::ITEM_IMPORTED ], $this->item_statuses() );
		$this->assertSame( [], $this->scheduler->of( $this->poll_hook() ), 'a closed task is not polled again' );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the poll released the account claim' );
	}

	/**
	 * Refused per item: the marketplace's words under the refusal code, and the same price is never sent again — not later, and not
	 * when the shop writes it again as a new revision.
	 *
	 * @return void
	 */
	public function test_a_refused_price_keeps_the_marketplace_text_and_is_final(): void {
		$product = $this->card( 'a', 100000 );
		$this->want_price( $product, [ 'price' => '50.00' ] );
		$this->flush_now();
		$this->poll();

		$row = $this->price_row( $product );
		$this->assertSame( Price_Refusal::REFUSED, $row['error_code'] );
		$this->assertSame( Fake_Price_Pusher::REFUSAL_TEXT, $row['last_error'] );
		$this->assertNull( $row['sent_payload'] );
		$this->assertNull( $row['in_flight_rev'] );
		$this->assertSame( Async_Task::STATUS_DONE_WITH_ERRORS, $this->only_task()->status() );
		$this->assertSame( [ Task_Ledger::ITEM_FAILED ], $this->item_statuses() );

		$this->now += 100000;
		$this->flush_now();

		// The same payload from another shop state is a new revision of the same price.
		$this->intents()->record( $this->account_id, $this->link_id( $product ), 0, 'price', $this->price_row( $product )['pending_payload'], [ 'regular_price' => '50.4' ] );
		$this->assertSame( 2, (int) $this->price_row( $product )['pending_rev'] );
		$this->flush_now();

		$this->assertSame( 1, $this->prices->created_task_count() );
	}

	/**
	 * A changed price is a new try after a refusal, and an accepted one clears the reason.
	 *
	 * @return void
	 */
	public function test_a_changed_price_is_sent_after_a_refusal(): void {
		$product = $this->card( 'a', 100000 );
		$this->want_price( $product, [ 'price' => '50.00' ] );
		$this->flush_now();
		$this->poll();

		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();

		$this->assertSame( 2, $this->prices->created_task_count() );
		$this->assertNull( $this->price_row( $product )['last_error'], 'the refusal belonged to the old price' );
		$this->poll( 2 );

		$row = $this->price_row( $product );
		$this->assertSame( '900.00', $row['sent_payload']['price'] );
		$this->assertNull( $row['error_code'] );
		$this->assertNull( $row['refused_payload'] );
		$this->assertNull( $row['last_error'] );
	}

	/**
	 * Quarantine has a code of its own and the framework's sentence with the marketplace's name, and is not sent again until the
	 * price changes. Going back to the price the marketplace holds is a change: it is sent, which releases the quarantine.
	 *
	 * @return void
	 */
	public function test_a_quarantined_price_waits_for_a_change(): void {
		$product = $this->card( 'a', 100000 );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->poll();

		$this->now += 400;
		$this->want_price( $product, [ 'price' => '200.00' ] );
		$this->flush_now();
		$this->poll( 2 );

		$row = $this->price_row( $product );
		$this->assertSame( Price_Refusal::QUARANTINED, $row['error_code'] );
		$this->assertSame( 'Тестовый маркетплейс держит новую цену в карантине: она намного ниже текущей', $row['last_error'] );
		$this->assertSame( '900.00', $row['sent_payload']['price'], 'the old price stays in force' );
		$this->assertSame(
			[
				[
					'code'    => Price_Refusal::QUARANTINED,
					'message' => 'Item has been moved to Price Quarantine',
				],
			],
			$this->task_ledger()->items( 2 )[ $this->link_id( $product ) ]['errors']
		);

		$this->now += 100000;
		$this->flush_now();
		$this->assertSame( 2, $this->prices->created_task_count(), 'never sent again by itself' );

		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();

		$this->assertSame( 3, $this->prices->created_task_count(), 'the shop price changed: it is sent, equal to the accepted one or not' );
		$this->poll( 3 );
		$this->assertNull( $this->price_row( $product )['error_code'] );

		$this->now += 400;
		$this->flush_now();
		$this->assertSame( 3, $this->prices->created_task_count() );
	}

	/**
	 * A task whose deadline passes expires and its price is sent once more; the second expiry is final until the price changes.
	 *
	 * @return void
	 */
	public function test_an_expired_price_is_sent_once_more_and_then_waits_for_a_change(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->prices->undecided = true;

		$this->now += Fake_Price_Pusher::DEADLINE + 1;
		$this->poll();

		$row = $this->price_row( $product );
		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->only_task()->status() );
		$this->assertSame( Price_Refusal::EXPIRED, $row['error_code'] );
		$this->assertSame( 'Маркетплейс не сообщил, принял ли он новую цену. Она будет отправлена ещё раз.', $row['last_error'] );
		$this->assertNull( $row['task_id'] );
		$this->assertNull( $row['refused_payload'] );

		$this->flush_now();
		$this->assertCount( 2, $this->all_tasks(), 'queued again once' );

		$this->now += Fake_Price_Pusher::DEADLINE + 1;
		$this->poll( 2 );

		$row = $this->price_row( $product );
		$this->assertSame( Price_Refusal::EXPIRED, $row['error_code'] );
		$this->assertSame( 'Маркетплейс не сообщил, принял ли он новую цену. Чтобы отправить её снова, измените цену.', $row['last_error'] );
		$this->assertNull( $row['in_flight_rev'] );

		$this->now += 100000;
		$this->flush_now();
		$this->assertCount( 2, $this->all_tasks() );

		$this->want_price( $product, [ 'price' => '910.00' ] );
		$this->flush_now();
		$this->assertCount( 3, $this->all_tasks() );
		$this->assertNull( $this->price_row( $product )['error_code'], 'a new price starts with a clean history' );
	}

	/**
	 * After an expiry nobody knows what the marketplace holds: a price changed back to the last accepted one is sent too.
	 *
	 * @return void
	 */
	public function test_a_price_changed_back_after_an_expiry_is_sent(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->poll();

		$this->now += 400;
		$this->want_price( $product, [ 'price' => '910.00' ] );
		$this->flush_now();
		$this->prices->undecided = true;
		$this->now             += Fake_Price_Pusher::DEADLINE + 1;
		$this->poll( 2 );

		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();

		$this->assertCount( 3, $this->all_tasks() );
		$this->assertNull( $this->price_row( $product )['last_error'], 'the sentence of the expired price is gone with it' );
	}

	/**
	 * An expiry makes an earlier refusal obsolete: the refused price is a changed one again and is sent. While the refusal stands,
	 * the queue names it for the merchant's «send»; afterwards there is nothing to name.
	 *
	 * @return void
	 */
	public function test_an_expiry_ends_an_earlier_refusal(): void {
		$product = $this->card( 'a', 100000 );
		$this->want_price( $product, [ 'price' => '50.00' ] );
		$this->flush_now();
		$this->poll();

		$refused = $this->price_row( $product )['pending_payload'];
		$link_id = $this->link_id( $product );

		$this->assertSame(
			[
				'code'    => Price_Refusal::REFUSED,
				'message' => Fake_Price_Pusher::REFUSAL_TEXT,
			],
			$this->intents()->held_price( $this->account_id, $link_id, $refused )
		);

		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->assertNull( $this->intents()->held_price( $this->account_id, $link_id, $refused ), 'while a newer upload is awaited, the old price is only queued behind it' );

		$this->prices->undecided = true;
		$this->now             += Fake_Price_Pusher::DEADLINE + 1;
		$this->poll( 2 );

		$this->assertNull( $this->intents()->held_price( $this->account_id, $link_id, $refused ) );

		$this->want_price( $product, [ 'price' => '50.00' ] );
		$this->flush_now();

		$this->assertCount( 3, $this->all_tasks() );
	}

	/**
	 * A last read past the deadline still decides: nothing the marketplace answered expires.
	 *
	 * @return void
	 */
	public function test_a_late_answer_still_counts(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();

		$this->now += Fake_Price_Pusher::DEADLINE + 1000;
		$this->poll();

		$this->assertSame( Async_Task::STATUS_DONE, $this->only_task()->status() );
		$this->assertSame( '900.00', $this->price_row( $product )['sent_payload']['price'] );
	}

	/**
	 * An answer that asks to wait sends the price again after the wait, counting no attempt.
	 *
	 * @return void
	 */
	public function test_a_wait_sends_the_price_again_later(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->prices->retry_after = 300;

		$this->poll();

		$row = $this->price_row( $product );
		$this->assertNull( $row['task_id'] );
		$this->assertNull( $row['error_code'] );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertSame( Async_Task::STATUS_DONE, $this->only_task()->status() );
		$this->assertSame( [ Task_Ledger::ITEM_SKIPPED ], $this->item_statuses() );

		$this->flush_now();
		$this->assertCount( 1, $this->all_tasks(), 'inside the wait nothing is sent' );

		$this->now += 301;
		$this->flush_now();
		$this->assertCount( 2, $this->all_tasks() );
	}

	/**
	 * A price changed while the upload is open waits for its result and goes out afterwards.
	 *
	 * @return void
	 */
	public function test_a_newer_price_waits_for_the_open_task(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();

		$this->now += 400;
		$this->want_price( $product, [ 'price' => '910.00' ] );
		$this->flush_now();
		$this->assertCount( 1, $this->all_tasks() );

		$this->poll();
		$this->assertSame( '900.00', $this->price_row( $product )['sent_payload']['price'], 'the answer settles what was sent' );

		$this->now += 400;
		$this->flush_now();
		$this->poll( 2 );
		$this->assertSame( '910.00', $this->price_row( $product )['sent_payload']['price'] );
	}

	/**
	 * An upload the marketplace recognises as one that is still open joins its task (the sender died before it marked the price).
	 *
	 * @return void
	 */
	public function test_a_duplicate_upload_joins_the_open_task(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'sync_state' ) . ' SET task_id = NULL' );

		$this->flush_now();

		$this->assertSame( 1, $this->prices->created_task_count() );
		$task = $this->only_task();
		$this->assertSame( (int) $task->task_id(), (int) $this->price_row( $product )['task_id'] );
		$this->assertCount( 1, $this->task_ledger()->items( (int) $task->task_id() ) );

		$this->poll();
		$this->assertSame( '900.00', $this->price_row( $product )['sent_payload']['price'] );
	}

	/**
	 * A price that was pulled from the marketplace while its task was open no longer waits: the task's answer changes nothing.
	 *
	 * @return void
	 */
	public function test_a_pulled_price_is_not_overwritten_by_the_task(): void {
		$product = $this->card( 'a', 100000 );
		$this->want_price( $product, [ 'price' => '200.00' ] );
		$this->flush_now();

		$token   = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$pulled  = array_merge( $this->price_row( $product )['pending_payload'], [ 'price' => '1000.00' ] );
		$settled = $this->price_queue()->settle_pulled( $this->account_id, $this->link_id( $product ), 1, $pulled, $token );
		$this->accounts()->release_claim( $this->account_id, $token );
		$this->assertTrue( $settled );

		$this->poll();

		$row = $this->price_row( $product );
		$this->assertSame( '1000.00', $row['sent_payload']['price'] );
		$this->assertNull( $row['error_code'] );
		$this->assertSame( [ Task_Ledger::ITEM_SKIPPED ], $this->item_statuses() );
		$this->assertSame( Async_Task::STATUS_DONE, $this->only_task()->status() );
	}

	/**
	 * A throttled read is tried again after the marketplace's wait; a refused key pauses the account.
	 *
	 * @return void
	 */
	public function test_a_failed_read_is_tried_again(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->scheduler->actions = [];

		$this->prices->failure = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 120 );
		$this->poll();

		$this->assertSame( 120, $this->scheduler->of( $this->poll_hook() )[0]['delay'] );
		$this->assertNotNull( $this->price_row( $product )['task_id'] );

		$this->scheduler->actions = [];
		$this->prices->failure    = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'bad key' );
		$this->poll();

		$this->assertSame( Account_Pause::SECONDS, $this->scheduler->of( $this->poll_hook() )[0]['delay'] );
		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertSame( Async_Task::STATUS_POLLING, $this->only_task()->status() );
	}

	/**
	 * A read that keeps failing past the deadline expires the task.
	 *
	 * @return void
	 */
	public function test_a_failing_read_past_the_deadline_expires_the_task(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->prices->failure = new \RuntimeException( 'boom' );

		$this->now += Fake_Price_Pusher::DEADLINE + 1;
		$this->poll();

		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->only_task()->status() );
		$this->assertNull( $this->price_row( $product )['task_id'] );
	}

	/**
	 * One of our own reads fails during a poll: what the task still owes is unknown, so it stays open, the price keeps waiting and the
	 * task is asked about again, and the next poll settles it.
	 *
	 * @dataProvider lost_reads
	 * @param string $table Table whose read fails.
	 * @param int    $nth   Which read of that table in the poll.
	 * @return void
	 */
	public function test_a_failed_database_read_leaves_the_task_open( string $table, int $nth ): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->prices->undecided  = true;
		$this->scheduler->actions = [];
		$this->lose_read( $table, $nth );

		$this->poll();

		$this->assertNull( $this->db->fail_read, 'the poll reached the read the test loses' );
		$this->assertFalse( $this->only_task()->is_final() );
		$this->assertSame( 1, (int) $this->price_row( $product )['task_id'] );
		$this->assertSame( [ [ 1, 1 ] ], array_column( $this->scheduler->of( $this->poll_hook() ), 'args' ) );
		$this->assertNotNull( $token = $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the claim was released' );
		$this->accounts()->release_claim( $this->account_id, (string) $token );

		$this->prices->undecided = false;
		$this->poll();

		$this->assertSame( Async_Task::STATUS_DONE, $this->only_task()->status() );
		$this->assertSame( '900.00', $this->price_row( $product )['sent_payload']['price'] );
	}

	/**
	 * The reads of one poll of an undecided task.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public function lost_reads(): array {
		return [
			'the waiting prices, for the decisions'   => [ 'sync_state', 1 ],
			'the waiting prices, for what is left'    => [ 'sync_state', 2 ],
			'the items, while deciding'               => [ 'task_items', 1 ],
			'the items, before the task is closed'    => [ 'task_items', 2 ],
		];
	}

	/**
	 * A read lost while a decided task is being closed does not close it with an item unread; past the deadline the next good poll
	 * ends it.
	 *
	 * @return void
	 */
	public function test_a_read_lost_past_the_deadline_expires_the_task_at_the_next_poll(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->prices->undecided = true;
		$this->now             += Fake_Price_Pusher::DEADLINE + 1;
		$this->lose_read( 'task_items', 1 );

		$this->poll();
		$this->assertFalse( $this->only_task()->is_final() );

		$this->poll();
		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->only_task()->status() );
		$this->assertNull( $this->price_row( $product )['task_id'] );
	}

	/**
	 * A price that still waits keeps its task open even when the ledger item says otherwise: nothing may wait for a closed task.
	 *
	 * @return void
	 */
	public function test_a_task_is_not_closed_while_a_price_waits_for_it(): void {
		$product = $this->card( 'a' );
		$this->want_price( $product, [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->task_ledger()->settle_item( 1, $this->link_id( $product ), Task_Ledger::ITEM_SKIPPED );
		$this->prices->undecided = true;

		$this->poll();
		$this->assertFalse( $this->only_task()->is_final() );

		$this->prices->undecided = false;
		$this->poll();

		$this->assertSame( Async_Task::STATUS_DONE, $this->only_task()->status() );
		$this->assertNull( $this->price_row( $product )['task_id'] );
		$this->assertSame( '900.00', $this->price_row( $product )['sent_payload']['price'] );
	}

	/**
	 * Another run holds the account: the poll comes back later and changes nothing.
	 *
	 * @return void
	 */
	public function test_a_busy_account_postpones_the_poll(): void {
		$this->want_price( $this->card( 'a' ), [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$this->scheduler->actions = [];

		$this->poll();

		$this->assertSame( [], $this->prices->reads );
		$this->assertSame( Job_Gate::BUSY_DELAY, $this->scheduler->of( $this->poll_hook() )[0]['delay'] );
	}

	/**
	 * The sweep polls an open task nobody has looked at, and leaves a fresh one and a card import alone.
	 *
	 * @return void
	 */
	public function test_the_sweep_picks_up_a_forgotten_price_task(): void {
		$this->want_price( $this->card( 'a' ), [ 'price' => '900.00' ] );
		$this->flush_now();
		$this->task_ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [] );
		$this->scheduler->actions = [];

		$this->runner()->run( 0 );
		$this->assertSame( [], $this->scheduler->of( $this->poll_hook() ) );

		$this->now += Price_Poll_Runner::STALE_AFTER + 1;
		$this->runner()->run( 0 );

		$this->assertSame( [ [ 1, 0 ] ], array_column( $this->scheduler->of( $this->poll_hook() ), 'args' ) );
	}

	/**
	 * The job shares its hook: a task of another kind is left alone.
	 *
	 * @return void
	 */
	public function test_a_task_of_another_kind_is_ignored(): void {
		$task_id = $this->task_ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [] );

		$this->runner()->run( $task_id );

		$this->assertSame( [], $this->prices->reads );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->task_ledger()->get( $task_id )->status() );
	}

	/**
	 * Makes one read of a table fail, as a dropped connection does.
	 *
	 * @param string $table Table, without prefix.
	 * @param int    $nth   Which read from now on, counted from 1.
	 * @return void
	 */
	private function lose_read( string $table, int $nth ): void {
		$name                = Sqlite_Tables::table( $table );
		$seen                = 0;
		$this->db->fail_read = function ( string $query ) use ( $name, $nth, &$seen ): bool {
			if ( 0 !== strpos( $query, 'SELECT' ) || false === strpos( $query, $name ) || false !== strpos( $query, 'claim' ) || ++$seen !== $nth ) {
				return false;
			}

			$this->db->fail_read = null;

			return true;
		};
	}

	/**
	 * Creates a created, price-synced card of the second marketplace.
	 *
	 * @param string   $offer   Article.
	 * @param int|null $current Price the marketplace shows now, in kopecks; null when it shows none.
	 * @return int Product id.
	 */
	private function card( string $offer, ?int $current = null ): int {
		$product = $this->product( 10 );
		$nm_id   = ++$this->nm_id;

		$this->links()->save( new Product_Link( $this->account_id, $product, 0, $offer, [ 'nmID' => $nm_id ], Product_Link::STATE_CREATED, [ 'sync_price' => true ] ) );

		if ( null !== $current ) {
			$this->prices->seed_remote_price( (string) $nm_id, $current );
		}

		return $product;
	}

	/**
	 * Plays the outer sender: takes the account claim, runs the price half under it, releases the claim.
	 *
	 * @return Flush_Result
	 */
	private function flush_now(): Flush_Result {
		$result = new Flush_Result();
		$token  = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		try {
			$this->price_runner( $this->prices )->flush_under_claim( $this->account_id, $token, 10, null, $result );
		} finally {
			$this->accounts()->release_claim( $this->account_id, $token );
		}

		return $result;
	}

	/**
	 * Builds the poll runner over the test tables.
	 *
	 * @return Price_Poll_Runner
	 */
	private function runner(): Price_Poll_Runner {
		return new Price_Poll_Runner(
			$this->job_gate(),
			$this->task_ledger(),
			$this->price_queue(),
			$this->prices,
			new Export_Scheduler( $this->names() ),
			$this->classifier,
			$this->refusal_writer(),
			new Api_Error_Ring( $this->db, Sqlite_Tables::table( 'api_errors' ), $this->clock() ),
			[ $this, 'log_line' ],
			$this->clock()
		);
	}

	/**
	 * Runs the poll job of a task.
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	private function poll( int $task_id = 1 ): void {
		$this->runner()->run( $task_id, 0 );
	}

	/**
	 * Returns the poll hook.
	 *
	 * @return string
	 */
	private function poll_hook(): string {
		return ( new Export_Scheduler( $this->names() ) )->poll_hook();
	}

	/**
	 * Lists every task of the ledger.
	 *
	 * @return Async_Task[]
	 */
	private function all_tasks(): array {
		$rows = $this->db->get_results( 'SELECT task_id FROM ' . Sqlite_Tables::table( 'tasks' ) . ' ORDER BY task_id', 'ARRAY_A' );

		return array_map(
			function ( array $row ): Async_Task {
				return $this->task_ledger()->get( (int) $row['task_id'] );
			},
			$rows
		);
	}

	/**
	 * Returns the single task of the ledger.
	 *
	 * @return Async_Task
	 */
	private function only_task(): Async_Task {
		$tasks = $this->all_tasks();

		$this->assertCount( 1, $tasks );

		return $tasks[0];
	}

	/**
	 * Lists the item statuses of the first task.
	 *
	 * @return string[]
	 */
	private function item_statuses(): array {
		return array_values( array_column( $this->task_ledger()->items( 1 ), 'status' ) );
	}

	/**
	 * Reads the last price a link was told the marketplace holds.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	private function link_price( int $product_id ): string {
		return number_format( (float) $this->db->get_var( 'SELECT last_pushed_price FROM ' . Sqlite_Tables::table( 'links' ) . ' WHERE link_id = ' . $this->link_id( $product_id ) ), 2, '.', '' );
	}
}

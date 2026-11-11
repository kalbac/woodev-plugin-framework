<?php
/**
 * The `export_batch` job: gate, claim, task, validation, request and the failure ladder.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Actions;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner
 * @covers \Woodev\Framework\Marketplace\Jobs\Export_Scheduler
 */
final class ExportBatchRunnerTest extends Export_Test_Case {
	private const EXPORT = 'woodev_fake_seller_export_batch';
	private const POLL   = 'woodev_fake_seller_poll_task';

	/**
	 * A queued link is claimed for a new task, sent, and the marketplace's task number is stored; the poll is scheduled.
	 *
	 * @return void
	 */
	public function test_a_batch_is_sent_and_its_poll_scheduled(): void {
		$link = $this->queued( 'NB-1' );

		$this->run_batch( [ $link ] );

		$moved = $this->link_of( $link->wc_product_id() );
		$task  = $this->ledger()->get( (int) $this->task_of( $moved ) );

		$this->assertSame( Link_Record::STATE_SUBMITTED, $moved->card_state() );
		$this->assertSame( Async_Task::KIND_IMPORT, $task->kind() );
		$this->assertSame( 'task-9001', $task->remote_task_id() );
		$this->assertSame( [ [ 'woodev_fake_seller_export_batch', [ (int) $link->link_id() => [ 'offer_id' => 'NB-1', 'product' => $link->wc_product_id() ] ] ] ], $this->gateway->submitted );

		$polls = $this->scheduler->of( self::POLL );
		$this->assertCount( 1, $polls );
		$this->assertSame( [ (int) $task->task_id(), 0 ], $polls[0]['args'] );
		$this->assertSame( Export_Batch_Runner::FIRST_POLL, $polls[0]['delay'] );
		$this->assertSame( 'NB-1', $this->ledger()->sent_offer_id( (int) $link->link_id() ), 'the article is frozen once the request is on its way' );
		$this->assertNull( $this->accounts()->get( $this->account_id )->setting( 'claim_token' ), 'the claim is released' );
	}

	/**
	 * The article follows the SKU until the claim: a link queued under an old SKU is sent with the current one.
	 *
	 * @return void
	 */
	public function test_the_article_is_refreshed_before_the_claim_freezes_it(): void {
		$product = $this->add_product( [ 'sku' => 'OLD' ] );
		$this->service()->enqueue( $this->account_now(), [ $product->get_id() ] );
		$this->skus[ (int) $product->get_id() ] = 'NEW';

		$this->export_runner()->run( $this->account_id, [ (int) $this->link_of( (int) $product->get_id() )->link_id() ], 0 );

		$this->assertSame( 'NEW', $this->link_of( (int) $product->get_id() )->offer_id() );
		$this->assertSame( 'NEW', array_values( $this->gateway->submitted[0][1] )[0]['offer_id'] );
	}

	/**
	 * A link that no longer validates, cannot be built or lost its product fails with the reason; the valid ones go on.
	 *
	 * @return void
	 */
	public function test_links_that_fail_after_the_claim_are_failed_with_a_reason(): void {
		$good     = $this->queued( 'GOOD' );
		$blocked  = $this->queued( 'BLOCKED' );
		$unbuilt  = $this->queued( 'UNBUILT' );
		$orphaned = $this->queued( 'GONE' );

		$this->rule->blocked        = [ $blocked->wc_product_id() ];
		$this->gateway->unbuildable = [ $unbuilt->wc_product_id() ];
		unset( $this->products[ $orphaned->wc_product_id() ] );

		$this->run_batch( [ $good, $blocked, $unbuilt, $orphaned ] );

		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $good->wc_product_id() )->card_state() );
		$this->assertSame( [ 'blocked_by_test', 'build_failed', 'product_missing' ], [
			$this->failure_code( $blocked ),
			$this->failure_code( $unbuilt ),
			$this->failure_code( $orphaned ),
		] );
		$this->assertCount( 1, $this->gateway->submitted[0][1], 'only the good card travels' );
	}

	/**
	 * When nothing is valid there is no request; the task ends `done_with_errors`.
	 *
	 * @return void
	 */
	public function test_nothing_valid_sends_nothing(): void {
		$link               = $this->queued( 'BLOCKED' );
		$this->rule->blocked = [ $link->wc_product_id() ];

		$this->run_batch( [ $link ] );

		$this->assertSame( [], $this->gateway->submitted );
		$this->assertSame( [], $this->scheduler->of( self::POLL ) );
		$task = $this->ledger()->get( 1 );
		$this->assertSame( Async_Task::STATUS_DONE_WITH_ERRORS, $task->status() );
	}

	/**
	 * A link reset while the batch was being built is dropped before the request and its item skipped.
	 *
	 * @return void
	 */
	public function test_a_link_reset_during_the_build_is_not_sent(): void {
		$kept  = $this->queued( 'KEPT' );
		$reset = $this->queued( 'RESET' );

		$this->gateway->on_build = function ( Link_Record $link ) use ( $reset ): void {
			if ( $link->wc_product_id() === $reset->wc_product_id() ) {
				$this->links()->reset_state( $this->account_id, [ (int) $reset->link_id() ] );
			}
		};

		$this->run_batch( [ $kept, $reset ] );

		$this->assertSame( [ (int) $kept->link_id() ], array_keys( $this->gateway->submitted[0][1] ) );
		$this->assertSame( Link_Record::STATE_UNLINKED, $this->link_of( $reset->wc_product_id() )->card_state() );
		$items = array_column( $this->ledger()->items( 1 ), 'status', 'link_id' );
		$this->assertSame( Task_Ledger::ITEM_SKIPPED, $items[ (int) $reset->link_id() ] );
	}

	/**
	 * Without a licence, with the write gate closed or with the account busy, the batch waits and nothing is claimed.
	 *
	 * @return void
	 */
	public function test_the_gates_make_the_batch_wait(): void {
		$link = $this->queued( 'NB-1' );

		$this->licensed = false;
		$this->run_batch( [ $link ] );
		$this->assertRetry( $link, 0, Job_Gate::LICENCE_DELAY );

		$this->licensed   = true;
		$this->write_gate = static function (): bool {
			return false;
		};
		$this->run_batch( [ $link ] );
		$this->assertRetry( $link, 0, Job_Gate::WRITE_DELAY );

		$this->write_gate = null;
		$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$this->run_batch( [ $link ] );
		$this->assertRetry( $link, 0, Job_Gate::BUSY_DELAY );

		$this->assertSame( [], $this->gateway->submitted );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $link->wc_product_id() )->card_state() );
	}

	/**
	 * A disabled or unknown account is dropped silently.
	 *
	 * @return void
	 */
	public function test_an_unknown_account_is_ignored(): void {
		$link = $this->queued( 'NB-1' );

		$this->export_runner()->run( 9999, [ (int) $link->link_id() ], 0 );

		$this->assertSame( [], $this->scheduler->of( self::EXPORT ) );
		$this->assertSame( [], $this->gateway->submitted );
	}

	/**
	 * A refused key pauses the account, puts the links back and retries after the pause without counting an attempt.
	 *
	 * @return void
	 */
	public function test_a_refused_key_pauses_the_account(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key refused' ) ];

		$this->run_batch( [ $link ] );

		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertRetry( $link, 0, Account_Pause::SECONDS );
	}

	/**
	 * A throttle waits as long as the marketplace asked and does not count as an attempt.
	 *
	 * @return void
	 */
	public function test_a_throttle_waits_without_counting_an_attempt(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 45 ) ];

		$this->run_batch( [ $link ], 2 );

		$this->assertRetry( $link, 2, 45 );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $link->wc_product_id() )->card_state() );
	}

	/**
	 * A server error requeues the links on the ladder; the fifth failure fails them.
	 *
	 * @return void
	 */
	public function test_a_server_error_retries_on_the_ladder_and_then_gives_up(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' ) ];

		$this->run_batch( [ $link ] );

		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertRetry( $link, 1, Backoff::seconds_after( 1 ) );

		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' ) ];
		$this->run_batch( [ $link ], Export_Batch_Runner::MAX_ATTEMPTS - 1 );

		$failed = $this->link_of( $link->wc_product_id() );
		$this->assertSame( Link_Record::STATE_FAILED, $failed->card_state() );
		$this->assertSame( 'export_failed', $this->links()->last_error( (int) $link->link_id() )['code'] );
		$this->assertStringContainsString( 'HTTP 503', $this->links()->last_error( (int) $link->link_id() )['text'] );
	}

	/**
	 * A refusal that retrying cannot fix fails the links at once, with the marketplace's words.
	 *
	 * @return void
	 */
	public function test_a_refusal_fails_the_links_at_once(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::FAIL, 'category not allowed' ) ];

		$this->run_batch( [ $link ] );

		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertStringContainsString( 'category not allowed', $this->links()->last_error( (int) $link->link_id() )['text'] );
		$this->assertSame( [], $this->scheduler->of( self::EXPORT ) );
	}

	/**
	 * A failure before anything left (the governor's wait) leaves the article unfrozen; a failure after the send keeps it frozen.
	 *
	 * @return void
	 */
	public function test_an_unsent_request_does_not_freeze_the_article(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'governor', 5, false ) ];

		$this->run_batch( [ $link ] );

		$this->assertNull( $this->ledger()->sent_offer_id( (int) $link->link_id() ) );

		$sent                   = $this->queued( 'NB-2' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout', null, true ) ];

		$this->run_batch( [ $sent ] );

		$this->assertSame( 'NB-2', $this->ledger()->sent_offer_id( (int) $sent->link_id() ) );
	}

	/**
	 * An answer without a task number is a transport failure, not a success.
	 *
	 * @return void
	 */
	public function test_an_answer_without_a_task_number_is_retried(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ '' ];

		$this->run_batch( [ $link ] );

		$this->assertSame( [], $this->scheduler->of( self::POLL ) );
		$this->assertRetry( $link, 1, Backoff::seconds_after( 1 ) );
	}

	/**
	 * An exception nobody classified is retried on the ladder and written to the error ring and the log.
	 *
	 * @return void
	 */
	public function test_an_unexpected_exception_is_retried_and_reported(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ new \LogicException( 'boom' ) ];

		$this->run_batch( [ $link ] );

		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertRetry( $link, 1, Backoff::seconds_after( 1 ) );
		$this->assertStringContainsString( 'boom', implode( "\n", $this->log ) );
		$this->assertSame( 1, (int) $this->db->get_var( 'SELECT COUNT(*) FROM wp_woodev_fake_api_errors' ), 'one line in the error ring' );
	}

	/**
	 * A refused retry schedule leaves the links queued with their task, for the recovery sweep.
	 *
	 * @return void
	 */
	public function test_a_refused_retry_keeps_the_task_for_the_sweep(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' ) ];
		$this->scheduler->zero_for[ self::EXPORT ] = true;

		$this->run_batch( [ $link ] );

		$queued = $this->link_of( $link->wc_product_id() );
		$this->assertSame( Link_Record::STATE_QUEUED, $queued->card_state() );
		$this->assertNotNull( $this->task_of( $queued ) );
	}

	/**
	 * The job hooks itself under the plugin's name.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_job(): void {
		Actions\expectAdded( self::EXPORT )->once();

		$this->export_runner()->register();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Ids that match no queued link claim nothing: the task is closed as failed and no request is made.
	 *
	 * @return void
	 */
	public function test_ids_without_a_queued_link_fail_the_task_and_send_nothing(): void {
		$ids = [];
		for ( $i = 0; $i < 120; $i++ ) {
			$ids[] = 5000 + $i;
		}

		$this->export_runner()->run( $this->account_id, $ids, 0 );

		$this->assertSame( [], $this->gateway->submitted );
		$this->assertSame( [], $this->scheduler->of( self::EXPORT ), 'nothing claimed: no queued link, no retry' );
		$this->assertSame( Async_Task::STATUS_FAILED, $this->ledger()->get( 1 )->status() );
	}

	/**
	 * The recovery sweep exists once, even when asked twice.
	 *
	 * @return void
	 */
	public function test_the_sweep_is_scheduled_once(): void {
		$this->export_scheduler()->ensure_sweep();
		$this->export_scheduler()->ensure_sweep();

		$this->assertCount( 1, $this->scheduler->of( self::POLL ) );
		$this->assertSame( [ 0, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );
	}

	/**
	 * Creates a valid, queued link through the request-time service.
	 *
	 * @param string $sku Article.
	 * @return Link_Record
	 */
	private function queued( string $sku ): Link_Record {
		$product = $this->add_product( [ 'sku' => $sku ] );
		$this->service()->enqueue( $this->account_now(), [ $product->get_id() ] );
		$this->scheduler->actions = array_values(
			array_filter(
				$this->scheduler->actions,
				static function ( array $action ): bool {
					return self::EXPORT !== $action['hook'];
				}
			)
		);

		return $this->link_of( (int) $product->get_id() );
	}

	/**
	 * Runs the job for links.
	 *
	 * @param Link_Record[] $links   Links.
	 * @param int           $attempt Failed attempts so far.
	 * @return void
	 */
	private function run_batch( array $links, int $attempt = 0 ): void {
		$this->scheduler->actions = array_values(
			array_filter(
				$this->scheduler->actions,
				static function ( array $action ): bool {
					return self::EXPORT !== $action['hook'];
				}
			)
		);

		$this->export_runner()->run(
			$this->account_id,
			array_map(
				static function ( Link_Record $link ): int {
					return (int) $link->link_id();
				},
				$links
			),
			$attempt
		);
	}

	/**
	 * Asserts the batch was scheduled again.
	 *
	 * @param Link_Record $link    A link of the batch.
	 * @param int         $attempt Attempt in the new action.
	 * @param int         $delay   Seconds.
	 * @return void
	 */
	private function assertRetry( Link_Record $link, int $attempt, int $delay ): void {
		$retries = $this->scheduler->of( self::EXPORT );
		$this->assertNotSame( [], $retries, 'a retry is scheduled' );
		$last = end( $retries );
		$this->assertSame( [ $this->account_id, [ (int) $link->link_id() ], $attempt ], $last['args'] );
		$this->assertSame( $delay, $last['delay'] );
		$this->scheduler->actions = array_values(
			array_filter(
				$this->scheduler->actions,
				static function ( array $action ): bool {
					return self::EXPORT !== $action['hook'];
				}
			)
		);
	}

	/**
	 * Returns the error code a link failed with.
	 *
	 * @param Link_Record $link Link.
	 * @return string
	 */
	private function failure_code( Link_Record $link ): string {
		$this->assertSame( Link_Record::STATE_FAILED, $this->link_of( $link->wc_product_id() )->card_state() );

		return (string) $this->links()->last_error( (int) $link->link_id() )['code'];
	}
}

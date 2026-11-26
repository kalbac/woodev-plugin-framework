<?php
/**
 * An export to a marketplace that gives no task number: polled by articles, never created twice, with its own deadlines.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Card_Outcome;
use Woodev\Framework\Marketplace\Flows\Import_Outcome;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Article_Lifecycle;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner
 * @covers \Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner
 * @covers \Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger
 */
final class ArticleKeyedExportTest extends Export_Test_Case {
	private const POLL   = 'woodev_fake_seller_poll_task';
	private const EXPORT = 'woodev_fake_seller_export_batch';

	/**
	 * The fake marketplace gives no task number.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->lifecycle            = new Scripted_Article_Lifecycle();
		$this->gateway->task_number = '';
	}

	/**
	 * An empty answer is an accepted import: the links stay submitted, a poll is scheduled and nothing is sent again.
	 *
	 * @return void
	 */
	public function test_an_answer_without_a_task_number_is_an_accepted_import(): void {
		$link = $this->queued( 'NB-1' );

		$this->run_batch( [ $link ] );

		$task = $this->ledger()->get( 1 );
		$this->assertTrue( $task->is_accepted_by_articles() );
		$this->assertNull( $task->remote_task_id() );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $task->status() );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->state_of( $link ) );
		$this->assertSame( [], $this->scheduler->of( self::EXPORT ), 'no retry' );
		$this->assertSame( [ 1, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );
		$this->assertSame( 'NB-1', $this->ledger()->sent_offer_id( (int) $link->link_id() ) );
	}

	/**
	 * The poll asks by the articles still open, applies what is reported and keeps asking for the rest.
	 *
	 * @return void
	 */
	public function test_the_import_is_read_by_the_articles_still_open(): void {
		$first  = $this->queued( 'NB-1' );
		$second = $this->queued( 'NB-2' );
		$this->run_batch( [ $first, $second ] );

		$this->lifecycle->import = [ 'NB-1' => $this->imported( 'c-1' ) ];
		$this->poll( 1 );

		$this->assertSame( [ 'by_articles', [ 'NB-1', 'NB-2' ] ], $this->lifecycle->reads[0] );
		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->state_of( $first ) );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->state_of( $second ) );
		$this->assertSame( Async_Task::STATUS_POLLING, $this->ledger()->get( 1 )->status() );

		$this->lifecycle->reads  = [];
		$this->lifecycle->import = [ 'NB-2' => new Import_Outcome( true, Link_Record::STATE_FAILED, Task_Ledger::ITEM_FAILED, 'wrong_brand', 'no such brand' ) ];
		$this->lifecycle->cards  = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1', 'model_ref' => 'm-1' ] ) ];
		$this->poll( 1 );

		$this->assertSame( [ 'by_articles', [ 'NB-2' ] ], $this->lifecycle->reads[0] );
		$this->assertSame( Link_Record::STATE_CREATED, $this->state_of( $first ) );
		$this->assertSame( Link_Record::STATE_FAILED, $this->state_of( $second ) );
		$this->assertSame( Async_Task::STATUS_DONE_WITH_ERRORS, $this->ledger()->get( 1 )->status() );
		$this->assertTrue( $this->ledger()->get( 1 )->is_accepted_by_articles(), 'the mark survives the final result' );
	}

	/**
	 * The sweep polls an accepted task again instead of sending its links anew.
	 *
	 * @return void
	 */
	public function test_the_sweep_polls_an_accepted_task_and_sends_nothing_again(): void {
		$link = $this->queued( 'NB-1' );
		$this->run_batch( [ $link ] );
		$this->scheduler->actions = [];
		$this->now               += Poll_Task_Runner::STALE_AFTER + 1;

		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertSame( [], $this->scheduler->of( self::EXPORT ) );
		$this->assertSame( [ 1, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->state_of( $link ) );
	}

	/**
	 * A create whose answer was lost is looked up first: the card that is there is not sent a second time.
	 *
	 * @return void
	 */
	public function test_a_retry_after_a_lost_answer_does_not_create_the_card_again(): void {
		$link = $this->lost_answer( 'NB-1' );

		$this->lifecycle->existing = [ 'NB-1' => [ 'card_ref' => 'c-1' ] ];
		$this->run_batch( [ $link ], 1 );

		$this->assertCount( 1, $this->gateway->submitted, 'only the first request' );
		$this->assertSame( [ [ 'find', [ 'NB-1' ] ] ], $this->lifecycle->reads );
		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->state_of( $link ) );
		$this->assertSame( 'c-1', $this->link_of( $link->wc_product_id() )->remote_id( 'card_ref' ) );
		$this->assertSame( Async_Task::STATUS_DONE, $this->ledger()->get( 2 )->status() );
		$this->assertSame( [ 2, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );

		$this->lifecycle->cards = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1', 'model_ref' => 'm-1' ] ) ];
		$this->poll( 2 );

		$this->assertSame( Link_Record::STATE_CREATED, $this->state_of( $link ) );
		$this->assertSame( [ (int) $link->link_id() ], $this->seeded );
	}

	/**
	 * A card that is not in the card list after a lost answer is sent again.
	 *
	 * @return void
	 */
	public function test_a_card_that_is_not_in_the_list_is_sent_again(): void {
		$link = $this->lost_answer( 'NB-1' );

		$this->run_batch( [ $link ], 1 );

		$this->assertCount( 2, $this->gateway->submitted );
		$this->assertSame( [ [ 'find', [ 'NB-1' ] ] ], $this->lifecycle->reads );
		$this->assertTrue( $this->ledger()->get( 2 )->is_accepted_by_articles() );
	}

	/**
	 * The card list lags: a re-send refused because the article is taken does not fail the link. The task waits, asks the card
	 * list only (the error list holds the echo of the refusal), and links the card once it is listed.
	 *
	 * @return void
	 */
	public function test_a_re_send_refused_as_taken_waits_for_the_card_and_links_it(): void {
		$link = $this->lost_answer( 'NB-1' );

		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::ARTICLE_TAKEN, 'article used in another card' ) ];
		$this->run_batch( [ $link ], 1 );

		$this->assertCount( 2, $this->gateway->submitted );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->state_of( $link ) );
		$this->assertSame( [], $this->scheduler->of( self::EXPORT ), 'no third create' );
		$this->assertSame( [ 2, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );

		$this->lifecycle->reads  = [];
		$this->lifecycle->import = [ 'NB-1' => new Import_Outcome( true, Link_Record::STATE_FAILED, Task_Ledger::ITEM_FAILED, 'taken', 'article used in another card' ) ];
		$this->poll( 2 );

		$this->assertSame( [ [ 'find', [ 'NB-1' ] ] ], $this->lifecycle->reads );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->state_of( $link ) );
		$this->assertSame( Async_Task::STATUS_POLLING, $this->ledger()->get( 2 )->status() );

		$this->lifecycle->existing = [ 'NB-1' => [ 'card_ref' => 'c-1' ] ];
		$this->lifecycle->cards    = [ 'c-1' => new Card_Outcome( Link_Record::STATE_CREATED, true, false, 'created', [ 'card_ref' => 'c-1', 'model_ref' => 'm-1' ] ) ];
		$this->now                += Poll_Task_Runner::STALE_AFTER + 1;
		$this->scheduler->actions  = [];
		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertSame( [], $this->scheduler->of( self::EXPORT ), 'the sweep polls, it does not send' );
		$this->assertSame( [ 2, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );

		$this->poll( 2 );

		$this->assertSame( Link_Record::STATE_CREATED, $this->state_of( $link ) );
		$this->assertSame( 'c-1', $this->link_of( $link->wc_product_id() )->remote_id( 'card_ref' ) );
		$this->assertSame( [ (int) $link->link_id() ], $this->seeded );
		$this->assertSame( [ [ (int) $link->link_id(), $this->account_id ] ], $this->created );
		$this->assertSame( Async_Task::STATUS_DONE, $this->ledger()->get( 2 )->status() );
	}

	/**
	 * The same after a run that died during its send: the sweep queues the link, the re-send is refused, the task waits.
	 *
	 * @return void
	 */
	public function test_a_re_send_after_a_dead_run_refused_as_taken_waits_for_the_card(): void {
		$link    = $this->queued( 'NB-1' );
		$task_id = $this->ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [ (int) $link->link_id() => 'NB-1' ] );
		$this->links()->claim_for_task( $task_id, [ (int) $link->link_id() ] );
		$this->ledger()->mark_sending( $task_id, 'hash', [ (int) $link->link_id() ] );
		$this->now += Poll_Task_Runner::STALE_AFTER + 1;
		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::ARTICLE_TAKEN, 'article used in another card' ) ];
		$this->run_batch( [ $link ] );
		$this->lifecycle->existing = [ 'NB-1' => [ 'card_ref' => 'c-1' ] ];
		$this->poll( 2 );

		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->state_of( $link ) );
	}

	/**
	 * A card that never shows up fails at the task deadline with the marketplace's refusal.
	 *
	 * @return void
	 */
	public function test_an_awaited_card_that_never_appears_fails_with_the_refusal(): void {
		$link                   = $this->lost_answer( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::ARTICLE_TAKEN, 'article used in another card' ) ];
		$this->run_batch( [ $link ], 1 );

		$this->now += Poll_Task_Runner::TASK_DEADLINE + 1;
		$this->poll( 2 );

		$this->assertSame( Link_Record::STATE_FAILED, $this->state_of( $link ) );
		$this->assertStringContainsString( 'article used in another card', $this->links()->last_error( (int) $link->link_id() )['text'] );
		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->ledger()->get( 2 )->status() );
	}

	/**
	 * A first send refused as taken follows no earlier request: it fails at once.
	 *
	 * @return void
	 */
	public function test_a_first_send_refused_as_taken_fails_at_once(): void {
		$link                   = $this->queued( 'NB-1' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::ARTICLE_TAKEN, 'article used in another card' ) ];

		$this->run_batch( [ $link ] );

		$this->assertSame( Link_Record::STATE_FAILED, $this->state_of( $link ) );
		$this->assertStringContainsString( 'article used in another card', $this->links()->last_error( (int) $link->link_id() )['text'] );
		$this->assertSame( [], $this->scheduler->of( self::POLL ) );
	}

	/**
	 * In a mixed batch only the card an earlier request carried is awaited; the new one is queued again.
	 *
	 * @return void
	 */
	public function test_a_card_never_sent_before_is_queued_again_when_the_batch_is_refused_as_taken(): void {
		$old                    = $this->lost_answer( 'NB-1' );
		$new                    = $this->queued( 'NB-2' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::ARTICLE_TAKEN, 'article used in another card' ) ];

		$this->run_batch( [ $old, $new ], 1 );

		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->state_of( $old ) );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->state_of( $new ) );
		$this->assertSame( [ $this->account_id, [ (int) $new->link_id() ], 2 ], $this->scheduler->of( self::EXPORT )[0]['args'] );

		$this->lifecycle->reads = [];
		$this->poll( 2 );

		$this->assertSame( [ [ 'find', [ 'NB-1' ] ] ], $this->lifecycle->reads );
	}

	/**
	 * Of a batch only the missing cards are sent; the ones found are polled even when that send fails.
	 *
	 * @return void
	 */
	public function test_only_the_missing_cards_of_a_batch_are_sent(): void {
		$first                  = $this->queued( 'NB-1' );
		$second                 = $this->queued( 'NB-2' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' ) ];
		$this->run_batch( [ $first, $second ] );

		$this->lifecycle->existing = [ 'NB-1' => [ 'card_ref' => 'c-1' ] ];
		$this->gateway->answers    = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' ) ];
		$this->run_batch( [ $first, $second ], 1 );

		$this->assertSame( [ (int) $second->link_id() ], array_keys( $this->gateway->submitted[1][1] ) );
		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->state_of( $first ) );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->state_of( $second ) );
		$this->assertSame( [ 2, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );
		$this->assertSame( [ $this->account_id, [ (int) $second->link_id() ], 2 ], $this->scheduler->of( self::EXPORT )[0]['args'] );
	}

	/**
	 * A found card and a sent one share a task: it stays open for the sent one.
	 *
	 * @return void
	 */
	public function test_a_found_card_and_a_sent_one_share_a_task(): void {
		$first                  = $this->queued( 'NB-1' );
		$second                 = $this->queued( 'NB-2' );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' ) ];
		$this->run_batch( [ $first, $second ] );

		$this->lifecycle->existing = [ 'NB-1' => [ 'card_ref' => 'c-1' ] ];
		$this->run_batch( [ $first, $second ], 1 );

		$this->lifecycle->reads  = [];
		$this->lifecycle->import = [ 'NB-2' => $this->imported( 'c-2' ) ];
		$this->poll( 2 );

		$this->assertSame( [ 'by_articles', [ 'NB-2' ] ], $this->lifecycle->reads[0] );
		$this->assertSame( [ 'cards', [ 'c-1', 'c-2' ] ], $this->lifecycle->reads[1] );
		$this->assertSame( Async_Task::STATUS_DONE, $this->ledger()->get( 2 )->status() );
	}

	/**
	 * When the card list cannot be read nothing is sent: the links wait for the next attempt.
	 *
	 * @return void
	 */
	public function test_nothing_is_sent_when_the_card_list_cannot_be_read(): void {
		$link = $this->lost_answer( 'NB-1' );

		$this->lifecycle->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' );
		$this->run_batch( [ $link ], 1 );

		$this->assertCount( 1, $this->gateway->submitted );
		$this->assertSame( Link_Record::STATE_QUEUED, $this->state_of( $link ) );
		$this->assertSame( [ $this->account_id, [ (int) $link->link_id() ], 2 ], $this->scheduler->of( self::EXPORT )[0]['args'] );
		$this->assertSame( Backoff::seconds_after( 2 ), $this->scheduler->of( self::EXPORT )[0]['delay'] );
	}

	/**
	 * A run whose account claim is taken over while the card list is read writes and sends nothing more; the sweep gives its
	 * links back.
	 *
	 * @return void
	 */
	public function test_a_run_that_lost_its_claim_during_the_lookup_leaves_the_links_to_the_sweep(): void {
		$link                      = $this->lost_answer( 'NB-1' );
		$this->lifecycle->existing = [ 'NB-1' => [ 'card_ref' => 'c-1' ] ];
		$this->lifecycle->on_read  = function (): void {
			$this->now += Job_Gate::CLAIM_TTL + 5;
			$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		};

		$this->run_batch( [ $link ], 1 );

		$this->assertCount( 1, $this->gateway->submitted );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->state_of( $link ) );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->ledger()->get( 2 )->status(), 'not finalised' );

		$this->lifecycle->on_read = null;
		$this->now               += Poll_Task_Runner::STALE_AFTER + 1;
		$this->scheduler->actions = [];
		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertSame( Link_Record::STATE_QUEUED, $this->state_of( $link ) );
		$this->assertSame( [ (int) $link->link_id() ], $this->scheduler->of( self::EXPORT )[0]['args'][1] );
	}

	/**
	 * A run that died after the send leaves a task the marketplace never confirmed: the sweep queues the link and the next run
	 * looks the card up first.
	 *
	 * @return void
	 */
	public function test_a_run_that_died_after_the_send_is_looked_up_before_the_resend(): void {
		$link    = $this->queued( 'NB-1' );
		$task_id = $this->ledger()->open( new Async_Task( $this->account_id, Async_Task::KIND_IMPORT ), [ (int) $link->link_id() => 'NB-1' ] );
		$this->links()->claim_for_task( $task_id, [ (int) $link->link_id() ] );
		$this->ledger()->mark_sending( $task_id, 'hash', [ (int) $link->link_id() ] );
		$this->now += Poll_Task_Runner::STALE_AFTER + 1;

		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );
		$this->lifecycle->existing = [ 'NB-1' => [ 'card_ref' => 'c-1' ] ];
		$this->run_batch( [ $link ] );

		$this->assertSame( [], $this->gateway->submitted );
		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->state_of( $link ) );
	}

	/**
	 * A card no request ever carried, and a card whose identifiers are known, are sent without a lookup.
	 *
	 * @return void
	 */
	public function test_a_first_send_and_an_update_ask_nothing(): void {
		$link = $this->queued( 'NB-1' );
		$this->run_batch( [ $link ] );
		$this->lifecycle->import = [ 'NB-1' => $this->imported( 'c-1' ) ];
		$this->poll( 1 );

		$this->service()->enqueue( $this->account_now(), [ $link->wc_product_id() ] );
		$this->lifecycle->reads = [];
		$this->run_batch( [ $link ] );

		$this->assertCount( 2, $this->gateway->submitted );
		$this->assertSame( [], $this->lifecycle->reads );
	}

	/**
	 * The import result is waited for as long as the adapter says, then fails with that many hours in the reason.
	 *
	 * @return void
	 */
	public function test_the_task_deadline_is_the_adapters(): void {
		$this->lifecycle->task_seconds = 259200;
		$link                          = $this->queued( 'NB-1' );
		$this->run_batch( [ $link ] );

		$this->now += Poll_Task_Runner::TASK_DEADLINE + 1;
		$this->poll( 1 );
		$this->assertSame( Async_Task::STATUS_POLLING, $this->ledger()->get( 1 )->status() );

		$this->now += 259200;
		$this->poll( 1 );

		$this->assertSame( Async_Task::STATUS_EXPIRED, $this->ledger()->get( 1 )->status() );
		$this->assertSame( Link_Record::STATE_FAILED, $this->state_of( $link ) );
		$this->assertStringContainsString( 'за 72 ч.', $this->links()->last_error( (int) $link->link_id() )['text'] );
	}

	/**
	 * An imported card is waited for as long as the adapter says.
	 *
	 * @return void
	 */
	public function test_the_card_deadline_is_the_adapters(): void {
		$this->lifecycle->card_seconds = 259200;
		$link                          = $this->queued( 'NB-1' );
		$this->run_batch( [ $link ] );
		$this->lifecycle->import = [ 'NB-1' => $this->imported( 'c-1' ) ];

		$this->now += Poll_Task_Runner::CARD_DEADLINE + 1;
		$this->poll( 1 );
		$this->assertSame( Link_Record::STATE_OFFER_VALIDATED, $this->state_of( $link ) );

		$this->now += 259200;
		$this->poll( 1 );

		$this->assertSame( Link_Record::STATE_FAILED, $this->state_of( $link ) );
		$this->assertStringContainsString( 'за 72 ч.', $this->links()->last_error( (int) $link->link_id() )['text'] );
	}

	/**
	 * The sweep still finds a task older than the default deadline when the adapter's is longer.
	 *
	 * @return void
	 */
	public function test_the_sweep_looks_as_far_back_as_the_adapters_deadline(): void {
		$this->lifecycle->task_seconds = 259200;
		$link                          = $this->queued( 'NB-1' );
		$this->run_batch( [ $link ] );
		// The link is reset, so only the age window can find the task, not the unfinished work it owns.
		$this->links()->reset_state( $this->account_id, [ (int) $link->link_id() ] );
		$this->scheduler->actions = [];
		$this->now               += 2 * Poll_Task_Runner::TASK_DEADLINE;

		$this->poll_runner()->run( Poll_Task_Runner::SWEEP_TASK, 0 );

		$this->assertSame( [ 1, 0 ], $this->scheduler->of( self::POLL )[0]['args'] );
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

		return $this->link_of( (int) $product->get_id() );
	}

	/**
	 * Sends a link whose request times out after it left: the link is queued again and nobody knows whether the card was created.
	 *
	 * @param string $sku Article.
	 * @return Link_Record
	 */
	private function lost_answer( string $sku ): Link_Record {
		$link                   = $this->queued( $sku );
		$this->gateway->answers = [ Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' ) ];
		$this->run_batch( [ $link ] );
		$this->lifecycle->reads = [];

		return $link;
	}

	/**
	 * Runs the export job for links, with the schedule cleared first.
	 *
	 * @param Link_Record[] $links   Links.
	 * @param int           $attempt Failed attempts so far.
	 * @return void
	 */
	private function run_batch( array $links, int $attempt = 0 ): void {
		$this->scheduler->actions = [];

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
	 * Runs the poll job of a task, with the schedule cleared first.
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	private function poll( int $task_id ): void {
		$this->scheduler->actions = [];

		$this->poll_runner()->run( $task_id, 0 );
	}

	/**
	 * Returns the card state of a link now.
	 *
	 * @param Link_Record $link Link.
	 * @return string
	 */
	private function state_of( Link_Record $link ): string {
		return $this->link_of( $link->wc_product_id() )->card_state();
	}

	/**
	 * Returns a successful import verdict.
	 *
	 * @param string $card_ref Card identifier.
	 * @return Import_Outcome
	 */
	private function imported( string $card_ref ): Import_Outcome {
		return new Import_Outcome( true, Link_Record::STATE_OFFER_VALIDATED, Task_Ledger::ITEM_IMPORTED, '', '', [], [ 'card_ref' => $card_ref ] );
	}
}

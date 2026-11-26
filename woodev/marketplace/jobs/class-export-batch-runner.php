<?php
/**
 * The `export_batch` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Article_Keyed_Lifecycle;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Gateway;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `woodev_{plugin id}_export_batch( account_id, link_ids, attempt )`: one import request and its task row. The scheduler sizes
 * the batch ({@see Export_Scheduler::queue_export()}); the run takes it whole.
 *
 * 1. The job gate: account enabled, licence, account pause; then the plugin's write gate — an account that may not write keeps its
 *    links queued and the batch waits.
 * 2. **One run per account**: the run takes the account row's claim; a busy account retries in 30 s.
 * 3. A task row is opened and the queued links are claimed for it (`queued` → `submitted`), fenced by the account claim.
 * 4. Each claimed link is validated again and built; a link that fails here is `failed` with the reason.
 * 5. **Right before the request** the run checks it still holds the claim and drops every link that is no longer `submitted` for
 *    this task (a «Сбросить состояние» during the build). The task then records that it is sending (body hash, links), which freezes
 *    those articles even if the outcome is uncertain.
 * 6. The marketplace's task number is stored and `poll_task` is scheduled in one minute; a refused scheduling is recovered by the
 *    poll sweep.
 *
 * A marketplace that gives no task number ({@see Article_Keyed_Lifecycle}): an empty answer is «accepted» and the task is polled by
 * its articles. A card an earlier request already carried, with no identifier known, is first looked up in the card list (step 4½): a
 * card that is there is not sent again, and when the lookup fails nothing is sent. The
 * list may lag behind: when such a card is sent and refused as {@see Remote_Error::ARTICLE_TAKEN}, it is not failed — the task stays
 * open and the poll reads the card list until the card appears or the task's deadline passes.
 *
 * Failures follow the adapter's {@see Error_Classifier}: a governor wait or a throttle reschedules with the delay it asked for
 * **without** counting an attempt; a server or transport error, or an unexpected exception before anything was sent, puts the links
 * back to `queued` and retries on the {@see Backoff} ladder, and the fifth failure marks them `failed`; an authorisation refusal
 * pauses the account; any other refusal fails the links at once. Nothing is thrown out of the job.
 *
 * @since 2.1.0
 */
final class Export_Batch_Runner {
	public const MAX_ATTEMPTS = 5;
	public const FIRST_POLL   = 60;

	private const FAILURE_CODE = 'export_batch_failed';

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Task ledger.
	 *
	 * @var Task_Ledger
	 */
	private $ledger;

	/**
	 * Export inputs.
	 *
	 * @var Export_Context_Builder
	 */
	private $contexts;

	/**
	 * Validator with the marketplace's rules.
	 *
	 * @var Export_Validator
	 */
	private $validator;

	/**
	 * The marketplace's card builder and import request.
	 *
	 * @var Export_Gateway
	 */
	private $gateway;

	/**
	 * Scheduler.
	 *
	 * @var Export_Scheduler
	 */
	private $scheduler;

	/**
	 * Classifies a failed request.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Error ring.
	 *
	 * @var Api_Error_Ring
	 */
	private $ring;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * The lifecycle of a marketplace that gives no task number, or null for one that does.
	 *
	 * @var Article_Keyed_Lifecycle|null
	 */
	private $by_article;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.0
	 *
	 * @since 2.1.21 `$by_article`.
	 *
	 * @param Job_Gate                     $gate       Job gate.
	 * @param Wpdb_Link_Store              $links      Links.
	 * @param Task_Ledger                  $ledger     Task ledger.
	 * @param Export_Context_Builder       $contexts   Export inputs.
	 * @param Export_Validator             $validator  Validator with the marketplace's rules.
	 * @param Export_Gateway               $gateway    The marketplace's card builder and import request.
	 * @param Export_Scheduler             $scheduler  Scheduler.
	 * @param Error_Classifier             $classifier Classifies a failed request.
	 * @param Api_Error_Ring               $ring       Error ring.
	 * @param callable                     $log_error  `function ( string $line ): void`.
	 * @param Article_Keyed_Lifecycle|null $by_article The lifecycle of a marketplace that gives no task number.
	 */
	public function __construct( Job_Gate $gate, Wpdb_Link_Store $links, Task_Ledger $ledger, Export_Context_Builder $contexts, Export_Validator $validator, Export_Gateway $gateway, Export_Scheduler $scheduler, Error_Classifier $classifier, Api_Error_Ring $ring, callable $log_error, ?Article_Keyed_Lifecycle $by_article = null ) {
		$this->gate       = $gate;
		$this->links      = $links;
		$this->ledger     = $ledger;
		$this->contexts   = $contexts;
		$this->validator  = $validator;
		$this->gateway    = $gateway;
		$this->scheduler  = $scheduler;
		$this->classifier = $classifier;
		$this->ring       = $ring;
		$this->log_error  = $log_error;
		$this->by_article = $by_article;
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->export_hook(), [ $this, 'run' ], 10, 3 );
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $link_ids   Link ids.
	 * @param mixed $attempt    Failed attempts so far.
	 * @return void
	 */
	public function run( $account_id = 0, $link_ids = [], $attempt = 0 ): void {
		$account_id = (int) $account_id;
		$attempt    = (int) $attempt;
		$link_ids   = array_values( array_unique( array_filter( array_map( 'intval', (array) $link_ids ) ) ) );

		try {
			$this->start( $account_id, $link_ids, $attempt );
		} catch ( \Throwable $e ) {
			$this->report( $account_id, 'export_batch failed before it claimed anything: ' . get_class( $e ) . ': ' . $e->getMessage() );
			$this->retry_or_give_up( $account_id, $link_ids, $attempt, 0, null, sprintf( /* translators: %s: reason. */ __( 'Не удалось подготовить выгрузку: %s', 'woodev-plugin-framework' ), $e->getMessage() ) );
		}
	}

	/**
	 * Applies the gate and the claim, then does the work under the claim.
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @param int   $attempt    Failed attempts so far.
	 * @return void
	 */
	private function start( int $account_id, array $link_ids, int $attempt ): void {
		list( $account, $wait ) = $this->gate->check( $account_id );

		if ( null === $account || [] === $link_ids ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->retry_later( $account_id, $link_ids, $attempt, $wait );
			return;
		}

		if ( ! $this->gate->allows_writes( $account ) ) {
			$this->retry_later( $account_id, $link_ids, $attempt, Job_Gate::WRITE_DELAY );
			return;
		}

		$fence = $this->gate->claim( $account_id );

		if ( null === $fence ) {
			$this->retry_later( $account_id, $link_ids, $attempt, Job_Gate::BUSY_DELAY );
			return;
		}

		$run = new Export_Run();

		try {
			$this->execute( $account, $link_ids, $attempt, $fence, $run );
		} catch ( \Throwable $e ) {
			$this->recover( $account, $link_ids, $attempt, $fence, $run, $e );
		} finally {
			$this->gate->release( $account_id, $fence );
		}
	}

	/**
	 * Does the work of one run.
	 *
	 * @param Account     $account  Account.
	 * @param int[]       $link_ids Link ids.
	 * @param int         $attempt  Failed attempts so far.
	 * @param Claim_Fence $fence    The run's claim.
	 * @param Export_Run  $run      What the run has done so far, for {@see self::recover()}.
	 * @return void
	 */
	private function execute( Account $account, array $link_ids, int $attempt, Claim_Fence $fence, Export_Run $run ): void {
		$this->refresh_articles( $account, $link_ids );

		$run->task_id = $this->ledger->open( new Async_Task( $account->id(), Async_Task::KIND_IMPORT ), [] );
		$claimed      = $this->links->claim_for_task( $run->task_id, $link_ids, $fence );

		if ( [] === $claimed ) {
			$this->ledger->record_poll( $run->task_id, Async_Task::STATUS_FAILED, [ 'reason' => 'nothing_queued' ] );
			return;
		}

		$run->claimed = array_map(
			static function ( Link_Record $link ): int {
				return (int) $link->link_id();
			},
			$claimed
		);

		$this->ledger->add_items( $run->task_id, $this->offer_ids( $claimed ) );

		$items = $this->build_items( $account, $run->task_id, $claimed, $fence );
		$items = $this->without_existing_cards( $account, $run, $claimed, $items, $attempt, $fence );

		if ( null === $items ) {
			return;
		}

		$items = $this->owned_items( $account->id(), $run->task_id, $fence, $items );

		if ( [] === $items ) {
			$this->close_unsent( $account, $run );
			return;
		}

		$this->submit( $account, $run, $items, $attempt, $fence );
	}

	/**
	 * Brings the article of each queued, never-sent link up to the current WooCommerce SKU before the claim freezes it: the link,
	 * the task item and the request then carry the same article. A frozen article is left as it is by the store.
	 *
	 * @param Account $account  Account.
	 * @param int[]   $link_ids Link ids.
	 * @return void
	 */
	private function refresh_articles( Account $account, array $link_ids ): void {
		foreach ( $link_ids as $link_id ) {
			$link    = $this->links->get( (int) $link_id );
			$product = null === $link || Link_Record::STATE_QUEUED !== $link->card_state() ? null : $this->contexts->product( $link->wc_product_id() );

			if ( null !== $link && null !== $product ) {
				$this->links->queue( (int) $link_id, $this->contexts->offer_id( $account, $product, $link ) );
			}
		}
	}

	/**
	 * Validates and builds each claimed link.
	 *
	 * @param Account       $account Account.
	 * @param int           $task_id Task id.
	 * @param Link_Record[] $claimed Claimed rows.
	 * @param Claim_Fence   $fence   The run's claim.
	 * @return array<int,array<string,mixed>> Link id => import item.
	 */
	private function build_items( Account $account, int $task_id, array $claimed, Claim_Fence $fence ): array {
		$items = [];

		foreach ( $claimed as $link ) {
			$link_id = (int) $link->link_id();
			$product = $this->contexts->product( $link->wc_product_id() );

			if ( null === $product ) {
				$this->fail_link( $task_id, $link_id, 'product_missing', __( 'Товар удалён с сайта или недоступен.', 'woodev-plugin-framework' ), $fence );
				continue;
			}

			$context = $this->contexts->build( $account, $product, $link );
			$report  = $this->validator->validate( $product, $context->mapping(), $context->profile(), $context->raw_context() );

			if ( ! $report->is_valid() ) {
				$first = (array) $report->first_error();
				$this->fail_link( $task_id, $link_id, (string) ( $first['code'] ?? 'invalid' ), implode( "\n", array_column( $report->errors(), 'message' ) ), $fence );
				continue;
			}

			try {
				$items[ $link_id ] = $this->gateway->build_item( $link, $product, $context->mapping(), $context->overrides(), $context->profile(), $context->context() );
			} catch ( \InvalidArgumentException $e ) {
				$this->fail_link( $task_id, $link_id, 'build_failed', sprintf( /* translators: %s: reason. */ __( 'Карточку не удалось собрать: %s', 'woodev-plugin-framework' ), $e->getMessage() ), $fence );
			}
		}

		return $items;
	}

	/**
	 * For a marketplace without task numbers: an accepted import cannot be told from a lost one, so before a card is sent again the
	 * marketplace's card list is read by article. A card that is there leaves the request, and its link goes on to the lifecycle poll.
	 *
	 * Asked only about cards an earlier request carried and that have no identifier yet: a card with identifiers is updated, which
	 * can be repeated safely.
	 *
	 * @param Account                        $account Account.
	 * @param Export_Run                     $run     The run.
	 * @param Link_Record[]                  $claimed Claimed rows.
	 * @param array<int,array<string,mixed>> $items   Link id => import item.
	 * @param int                            $attempt Failed attempts so far.
	 * @param Claim_Fence                    $fence   The run's claim.
	 * @return array<int,array<string,mixed>>|null The items still to send; null when the read failed, which is then handled.
	 * @throws \Throwable When the classifier does not know the failure.
	 */
	private function without_existing_cards( Account $account, Export_Run $run, array $claimed, array $items, int $attempt, Claim_Fence $fence ): ?array {
		if ( null === $this->by_article ) {
			return $items;
		}

		$resent = [];

		foreach ( $claimed as $link ) {
			$link_id = (int) $link->link_id();

			if ( isset( $items[ $link_id ] ) && [] === $link->remote_ids() && null !== $this->ledger->sent_offer_id( $link_id ) ) {
				$resent[ $link_id ] = (string) $link->offer_id();
			}
		}

		if ( [] === $resent ) {
			return $items;
		}

		try {
			$cards = $this->by_article->find_cards( $account, array_values( $resent ), $this->scheduler->export_hook() );
		} catch ( \Throwable $thrown ) {
			$error = $this->classifier->classify( $thrown );

			if ( null === $error ) {
				throw $thrown;
			}

			$run->settled = true;
			$this->after_failure( $account, $run->task_id, array_keys( $items ), $attempt, $error, $fence );

			return null;
		}

		if ( ! $this->gate->still_holds( $account->id(), $fence ) ) {
			throw new Claim_Lost( 'The account claim was taken over while the card list was read.' );
		}

		foreach ( $resent as $link_id => $offer_id ) {
			if ( ! isset( $cards[ $offer_id ] ) ) {
				continue;
			}

			if ( $this->links->advance( $link_id, $run->task_id, Link_Record::STATE_OFFER_VALIDATED, [ 'remote_ids' => $cards[ $offer_id ] ], $fence, Link_Record::STATE_SUBMITTED ) ) {
				$this->ledger->settle_item( $run->task_id, $link_id, Task_Ledger::ITEM_IMPORTED );
				++$run->found;
			}

			unset( $items[ $link_id ] );
		}

		$run->unconfirmed = array_keys( array_intersect_key( $resent, $items ) );

		return $items;
	}

	/**
	 * Closes the task of a run that has nothing to send: every link failed, was taken away, or its card already exists.
	 *
	 * @param Account    $account Account.
	 * @param Export_Run $run     The run.
	 * @return void
	 */
	private function close_unsent( Account $account, Export_Run $run ): void {
		if ( 0 === $run->found ) {
			$this->ledger->record_poll( $run->task_id, Async_Task::STATUS_DONE_WITH_ERRORS, [ 'reason' => 'nothing_valid' ] );
			$run->settled = true;
			return;
		}

		$status = $run->found === count( $this->ledger->items( $run->task_id ) ) ? Async_Task::STATUS_DONE : Async_Task::STATUS_DONE_WITH_ERRORS;

		$this->ledger->record_poll( $run->task_id, $status, [ 'imported' => $run->found ] );
		$run->settled = true;
		$this->schedule_first_poll( $account->id(), $run->task_id );
	}

	/**
	 * Schedules the first poll of a task; the poll sweep picks the task up when Action Scheduler refuses.
	 *
	 * @param int $account_id Account id.
	 * @param int $task_id    Task id.
	 * @return void
	 */
	private function schedule_first_poll( int $account_id, int $task_id ): void {
		try {
			$this->scheduler->poll_later( $task_id, 0, self::FIRST_POLL );
		} catch ( \Throwable $e ) {
			$this->report( $account_id, 'export_batch: the poll of task ' . $task_id . ' could not be scheduled; the poll sweep picks it up: ' . $e->getMessage() );
		}
	}

	/**
	 * Keeps the items whose link this task still owns, checked right before the request; a link reset or taken meanwhile is left out
	 * and its item marked skipped (not sent).
	 *
	 * @param int                            $account_id Account id.
	 * @param int                            $task_id    Task id.
	 * @param Claim_Fence                    $fence      The run's claim.
	 * @param array<int,array<string,mixed>> $items      Link id => import item.
	 * @return array<int,array<string,mixed>>
	 * @throws Claim_Lost When the run no longer holds the account claim.
	 */
	private function owned_items( int $account_id, int $task_id, Claim_Fence $fence, array $items ): array {
		if ( [] === $items ) {
			return [];
		}

		if ( ! $this->gate->still_holds( $account_id, $fence ) ) {
			throw new Claim_Lost( 'The account claim was taken over before the import was sent.' );
		}

		$owned = [];
		foreach ( $this->links->by_task( $task_id, [ Link_Record::STATE_SUBMITTED ] ) as $link ) {
			$owned[ (int) $link->link_id() ] = true;
		}

		foreach ( array_keys( array_diff_key( $items, $owned ) ) as $dropped ) {
			$this->ledger->settle_item( $task_id, (int) $dropped, Task_Ledger::ITEM_SKIPPED );
		}

		return array_intersect_key( $items, $owned );
	}

	/**
	 * Sends the import request and records its task id, or that it was accepted without one.
	 *
	 * @param Account                        $account Account.
	 * @param Export_Run                     $run     The run.
	 * @param array<int,array<string,mixed>> $items   Link id => import item.
	 * @param int                            $attempt Failed attempts so far.
	 * @param Claim_Fence                    $fence   The run's claim.
	 * @return void
	 */
	private function submit( Account $account, Export_Run $run, array $items, int $attempt, Claim_Fence $fence ): void {
		$hash = sha1( (string) wp_json_encode( [ 'items' => array_values( $items ) ] ) );

		$this->ledger->mark_sending( $run->task_id, $hash, array_keys( $items ) );
		$run->sending = array_keys( $items );

		try {
			$remote = $this->gateway->submit( $account, $items, $this->scheduler->export_hook() );
		} catch ( \Throwable $thrown ) {
			$error = $this->classifier->classify( $thrown );

			if ( null === $error ) {
				throw $thrown;
			}

			if ( ! $error->was_sent() ) {
				$this->ledger->unmark_sending( $run->task_id );
			}

			$run->settled = true;
			$awaited      = $error->is( Remote_Error::ARTICLE_TAKEN ) ? array_intersect( array_keys( $items ), $run->unconfirmed ) : [];

			if ( [] !== $awaited ) {
				$this->await_cards( $account, $run->task_id, array_values( array_diff( array_keys( $items ), $awaited ) ), $attempt, $error, $fence );
				return;
			}

			$this->after_failure( $account, $run->task_id, array_keys( $items ), $attempt, $error, $fence );

			if ( $run->found > 0 ) {
				$this->schedule_first_poll( $account->id(), $run->task_id ); // The cards found before the send still need their poll.
			}

			return;
		}

		$without_number = '' === $remote || '0' === $remote;

		if ( $without_number && null === $this->by_article ) {
			$run->settled = true;
			$this->after_failure( $account, $run->task_id, array_keys( $items ), $attempt, new Remote_Error( Remote_Error::RETRY, 'transport', __( 'Маркетплейс принял выгрузку, но не вернул номер задачи.', 'woodev-plugin-framework' ) ), $fence );
			return;
		}

		if ( $without_number ) {
			$this->ledger->accept_by_articles( $run->task_id, $hash );
		} else {
			$this->ledger->attach_remote( $run->task_id, $remote, $hash );
		}

		$run->settled = true;
		$this->schedule_first_poll( $account->id(), $run->task_id );
	}

	/**
	 * The re-send was refused because an article is taken, and the card list did not show the card a moment ago: most likely it is the
	 * card of the earlier, unconfirmed request, not listed yet. Failing it would leave a real card unlinked, so the task stays open
	 * and the poll waits for it. The marketplace refused the request as a whole, so the other links of it are queued again.
	 *
	 * @param Account      $account Account.
	 * @param int          $task_id Task id.
	 * @param int[]        $others  Links of the request that no earlier request may have created.
	 * @param int          $attempt Failed attempts so far.
	 * @param Remote_Error $error   The refusal.
	 * @param Claim_Fence  $fence   The run's claim.
	 * @return void
	 */
	private function await_cards( Account $account, int $task_id, array $others, int $attempt, Remote_Error $error, Claim_Fence $fence ): void {
		$this->log( 'export_batch: account ' . $account->id() . ': task ' . $task_id . ' waits for the cards of refused articles: ' . $error->describe() );
		$this->ledger->await_cards( $task_id, $error->message() );

		if ( [] !== $others ) {
			$this->retry_or_give_up( $account->id(), $others, $attempt, $task_id, $fence, $this->refused_text( $error ), $error );
		}

		$this->schedule_first_poll( $account->id(), $task_id );
	}

	/**
	 * Returns the sentence a link refused by the marketplace shows.
	 *
	 * @param Remote_Error $error The refusal.
	 * @return string
	 */
	private function refused_text( Remote_Error $error ): string {
		return sprintf( /* translators: %s: reason. */ __( 'Маркетплейс отклонил выгрузку: %s', 'woodev-plugin-framework' ), $error->message() );
	}

	/**
	 * Applies what the classifier says about a refused submit.
	 *
	 * @param Account      $account  Account.
	 * @param int          $task_id  Task id.
	 * @param int[]        $link_ids Links that were in the request.
	 * @param int          $attempt  Failed attempts so far.
	 * @param Remote_Error $error    The refusal.
	 * @param Claim_Fence  $fence    The run's claim.
	 * @return void
	 */
	private function after_failure( Account $account, int $task_id, array $link_ids, int $attempt, Remote_Error $error, Claim_Fence $fence ): void {
		$this->log( 'export_batch: account ' . $account->id() . ': ' . $error->describe() );
		$this->ledger->record_poll( $task_id, Async_Task::STATUS_FAILED, [ 'error' => $error->describe() ] );

		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->gate->pause()->apply( $account, $error );
			$this->requeue( $task_id, $link_ids, $fence, $this->retry_later( $account->id(), $link_ids, $attempt, Account_Pause::SECONDS ) );
			return;
		}

		if ( $error->is( Remote_Error::THROTTLE ) ) {
			// A wait is not a failed attempt: the same attempt count, the delay the governor or the marketplace asked for.
			$this->requeue( $task_id, $link_ids, $fence, $this->retry_later( $account->id(), $link_ids, $attempt, max( 1, (int) ( $error->retry_after() ?? Backoff::seconds_after( 1 ) ) ) ) );
			return;
		}

		if ( $error->is( Remote_Error::RETRY ) ) {
			$this->retry_or_give_up( $account->id(), $link_ids, $attempt, $task_id, $fence, sprintf( /* translators: 1: number of attempts, 2: reason. */ __( 'Маркетплейс не принял выгрузку после %1$d попыток: %2$s', 'woodev-plugin-framework' ), self::MAX_ATTEMPTS, $error->message() ), $error );
			return;
		}

		$this->fail_links( $task_id, $link_ids, $error->kind(), $this->refused_text( $error ), $fence );
	}

	/**
	 * Recovers from an unexpected exception inside the claimed run: links not yet sent go back to the queue under the ladder (the
	 * fifth failure fails them); links whose request already left are left to the poll (remote id known) or retried (unknown).
	 *
	 * @param Account     $account  Account.
	 * @param int[]       $link_ids Link ids of the job.
	 * @param int         $attempt  Failed attempts so far.
	 * @param Claim_Fence $fence    The run's claim.
	 * @param Export_Run  $run      What the run had done.
	 * @param \Throwable  $e        The exception.
	 * @return void
	 */
	private function recover( Account $account, array $link_ids, int $attempt, Claim_Fence $fence, Export_Run $run, \Throwable $e ): void {
		$this->report( $account->id(), 'export_batch failed unexpectedly: ' . get_class( $e ) . ': ' . $e->getMessage() );

		if ( $run->settled || $e instanceof Claim_Lost ) {
			return; // The outcome is already recorded, or another run owns the account now (the sweep reclaims what is left).
		}

		$claimed = [] === $run->claimed ? $link_ids : $run->claimed;

		if ( $run->task_id > 0 ) {
			$this->ledger->record_poll( $run->task_id, Async_Task::STATUS_FAILED, [ 'error' => 'unexpected: ' . $e->getMessage() ] );
		}

		$this->retry_or_give_up( $account->id(), $claimed, $attempt, $run->task_id, $fence, sprintf( /* translators: %s: reason. */ __( 'Не удалось подготовить выгрузку: %s', 'woodev-plugin-framework' ), $e->getMessage() ) );
	}

	/**
	 * Puts links back into the queue and schedules the next attempt, or fails them at the attempt limit.
	 *
	 * @param int               $account_id Account id.
	 * @param int[]             $link_ids   Link ids.
	 * @param int               $attempt    Failed attempts before this one.
	 * @param int               $task_id    Task that owns the links, 0 when none was opened.
	 * @param Claim_Fence|null  $fence      The run's claim, or null.
	 * @param string            $final_text Reason stored at the limit.
	 * @param Remote_Error|null $error      The refusal, for its retry delay.
	 * @return void
	 */
	private function retry_or_give_up( int $account_id, array $link_ids, int $attempt, int $task_id, ?Claim_Fence $fence, string $final_text, ?Remote_Error $error = null ): void {
		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			if ( $task_id > 0 ) {
				$this->fail_links( $task_id, $link_ids, 'export_failed', $final_text, $fence );
			}

			$this->report( $account_id, 'export_batch gave up after ' . self::MAX_ATTEMPTS . ' attempts: ' . $final_text );
			return;
		}

		$delay     = null === $error ? null : $error->retry_after();
		$scheduled = $this->retry_later( $account_id, $link_ids, $attempt + 1, $delay ?? Backoff::seconds_after( $attempt + 1 ) );

		if ( $task_id > 0 ) {
			$this->requeue( $task_id, $link_ids, $fence, $scheduled );
		}
	}

	/**
	 * Puts the links of a task back into the queue. They leave their task once their retry is scheduled; when Action Scheduler
	 * refused it they keep the task id, so the recovery sweep finds them and queues a new batch.
	 *
	 * @param int              $task_id   Task id.
	 * @param int[]            $link_ids  Link ids.
	 * @param Claim_Fence|null $fence     The run's claim.
	 * @param bool             $scheduled Whether their retry is scheduled.
	 * @return void
	 */
	private function requeue( int $task_id, array $link_ids, ?Claim_Fence $fence, bool $scheduled ): void {
		$columns = $scheduled ? [ 'last_task_id' => null ] : [];

		foreach ( $link_ids as $link_id ) {
			if ( $this->links->advance( (int) $link_id, $task_id, Link_Record::STATE_QUEUED, $columns, $fence, Link_Record::STATE_SUBMITTED ) ) {
				$this->ledger->settle_item( $task_id, (int) $link_id, Task_Ledger::ITEM_SKIPPED );
			}
		}
	}

	/**
	 * Fails several links with one reason.
	 *
	 * @param int              $task_id  Task id.
	 * @param int[]            $link_ids Link ids.
	 * @param string           $code     Error code.
	 * @param string           $text     Reason.
	 * @param Claim_Fence|null $fence    The run's claim.
	 * @return void
	 */
	private function fail_links( int $task_id, array $link_ids, string $code, string $text, ?Claim_Fence $fence ): void {
		foreach ( $link_ids as $link_id ) {
			$this->fail_link( $task_id, (int) $link_id, $code, $text, $fence );
		}
	}

	/**
	 * Fails one link of the task and its item.
	 *
	 * @param int              $task_id Task id.
	 * @param int              $link_id Link id.
	 * @param string           $code    Error code.
	 * @param string           $text    Reason: a sentence for the merchant or the marketplace's text verbatim.
	 * @param Claim_Fence|null $fence   The run's claim.
	 * @return void
	 */
	private function fail_link( int $task_id, int $link_id, string $code, string $text, ?Claim_Fence $fence ): void {
		$moved = $this->links->advance(
			$link_id,
			$task_id,
			Link_Record::STATE_FAILED,
			[
				'last_error_code' => $code,
				'last_error_text' => $text,
			],
			$fence,
			Link_Record::STATE_SUBMITTED
		);

		if ( ! $moved ) {
			return;
		}

		$this->ledger->settle_item(
			$task_id,
			$link_id,
			Task_Ledger::ITEM_FAILED,
			[
				[
					'code'    => $code,
					'message' => $text,
				],
			]
		);
	}

	/**
	 * Lists the articles of the claimed rows.
	 *
	 * @param Link_Record[] $claimed Rows.
	 * @return array<int,string> Link id => article.
	 */
	private function offer_ids( array $claimed ): array {
		$items = [];

		foreach ( $claimed as $link ) {
			$items[ (int) $link->link_id() ] = (string) $link->offer_id();
		}

		return $items;
	}

	/**
	 * Schedules the batch again; a refusal by Action Scheduler is recorded where support looks.
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Link ids.
	 * @param int   $attempt    Failed attempts so far.
	 * @param int   $delay      Seconds to wait.
	 * @return bool False when Action Scheduler refused it.
	 */
	private function retry_later( int $account_id, array $link_ids, int $attempt, int $delay ): bool {
		try {
			$this->scheduler->retry_export( $account_id, $link_ids, $attempt, $delay );

			return true;
		} catch ( \Throwable $e ) {
			$this->report( $account_id, 'export_batch could not be rescheduled; the links stay queued: ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * Writes a failure to the error ring and the error log.
	 *
	 * @param int    $account_id Account id.
	 * @param string $message    Failure text.
	 * @return void
	 */
	private function report( int $account_id, string $message ): void {
		try {
			$this->ring->record( $account_id > 0 ? $account_id : null, '', 0, self::FAILURE_CODE, $message, '', $this->scheduler->export_hook() );
		} catch ( \Throwable $e ) {
			unset( $e ); // The log line below still carries the failure.
		}

		$this->log( $message );
	}

	/**
	 * Writes a line to the error log.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function log( string $line ): void {
		call_user_func( $this->log_error, $line );
	}
}

<?php
/**
 * The `poll_task` job for card imports.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Card_Lifecycle;
use Woodev\Framework\Marketplace\Flows\Card_Outcome;
use Woodev\Framework\Marketplace\Flows\Import_Outcome;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `woodev_{plugin id}_poll_task( task_id, attempt )` for an import task, every minute for the first 10 minutes after the
 * submit, then every 5 minutes. Each run does what is due, in at most two requests (both made by the adapter's
 * {@see Card_Lifecycle}):
 *
 * 1. **Import** while items are open; when every item is final the task is `done` or `done_with_errors`. Unfinished 24 hours after
 *    the submit, the task is `expired` and its open items `failed`.
 * 2. **Card lifecycle** for every link of the task already imported and not yet created, **whether or not the other items are
 *    final**, until the card exists. Before a link is marked `created` its first stock and price intents are seeded; a failed
 *    seeding leaves it for the next poll. Then the card-created callback fires once. A created card that still lacks an identifier
 *    the marketplace gives later is read until it arrives. A card not created 3 hours after the submit fails; a declined one at once,
 *    with the marketplace's words.
 *
 * Ownership: a run holds the account row's claim and every link write carries it plus the state the run read, so an older answer
 * cannot move a card back. Waits from the governor or a throttle reschedule without counting an attempt; a failed call or an
 * unexpected exception is retried on the {@see Backoff} ladder and the fifth failure gives up on what is still open.
 *
 * A run re-proves its claim before every terminal ledger write and before seeding ({@see Claim_Lost} otherwise), so a superseded run
 * cannot finalise the newer holder's task, and re-reads the link right before seeding, so a reset during the request seeds nothing.
 *
 * `poll_task( 0 )` is the **recovery sweep**, recurring every 15 minutes: an import task nobody has touched for 30 minutes is polled
 * again (a refused or lost schedule), and a task that never got the marketplace's number gives its links back to the export queue.
 * Tasks that own unfinished links are found through the links whatever their age; links a refused export retry left queued get a
 * new batch.
 *
 * @since 2.1.0
 */
final class Poll_Task_Runner {
	public const FAST_PERIOD   = 600;
	public const FAST_INTERVAL = 60;
	public const SLOW_INTERVAL = 300;
	public const CARD_DEADLINE = 10800;
	public const TASK_DEADLINE = 86400;
	public const MAX_ATTEMPTS  = 5;

	public const SWEEP_TASK   = 0;
	public const STALE_AFTER  = 1800;
	public const SWEEP_LIMIT  = 50;
	public const SWEEP_MARGIN = 3600;

	/**
	 * Link states that still need a task's run: waiting for the import, for the card, or (export retry not scheduled) for a new batch.
	 *
	 * @var string[]
	 */
	public const UNFINISHED_STATES = [ Link_Record::STATE_QUEUED, Link_Record::STATE_SUBMITTED, Link_Record::STATE_OFFER_VALIDATED, Link_Record::STATE_PICS_DELIVERED, Link_Record::STATE_SKU_CREATED, Link_Record::STATE_PRICE_SENT ];

	private const OWNER_SCAN_LIMIT = 1000;
	private const FAILURE_CODE     = 'poll_task_failed';

	private const PHASE_OPEN    = 'open';
	private const PHASE_DONE    = 'done';
	private const PHASE_HANDLED = 'handled';

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
	 * Scheduler.
	 *
	 * @var Export_Scheduler
	 */
	private $scheduler;

	/**
	 * The marketplace's import result and card state.
	 *
	 * @var Card_Lifecycle
	 */
	private $lifecycle;

	/**
	 * Classifies a failed request.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Seeds the first intents of a card about to be created: `function ( Account, Link_Record ): void`; throws on failure.
	 *
	 * @var callable
	 */
	private $seed;

	/**
	 * Called once a card is `created`: `function ( int $link_id, int $account_id ): void`.
	 *
	 * @var callable
	 */
	private $on_created;

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
	 * Clock: `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Gate         $gate       Job gate.
	 * @param Wpdb_Link_Store  $links      Links.
	 * @param Task_Ledger      $ledger     Task ledger.
	 * @param Export_Scheduler $scheduler  Scheduler.
	 * @param Card_Lifecycle   $lifecycle  The marketplace's import result and card state.
	 * @param Error_Classifier $classifier Classifies a failed request.
	 * @param callable         $seed       `function ( Account, Link_Record ): void`.
	 * @param callable         $on_created `function ( int $link_id, int $account_id ): void`.
	 * @param Api_Error_Ring   $ring       Error ring.
	 * @param callable         $log_error  `function ( string $line ): void`.
	 * @param callable|null    $clock      `function (): int`; the system clock when null.
	 */
	public function __construct( Job_Gate $gate, Wpdb_Link_Store $links, Task_Ledger $ledger, Export_Scheduler $scheduler, Card_Lifecycle $lifecycle, Error_Classifier $classifier, callable $seed, callable $on_created, Api_Error_Ring $ring, callable $log_error, ?callable $clock = null ) {
		$this->gate       = $gate;
		$this->links      = $links;
		$this->ledger     = $ledger;
		$this->scheduler  = $scheduler;
		$this->lifecycle  = $lifecycle;
		$this->classifier = $classifier;
		$this->seed       = $seed;
		$this->on_created = $on_created;
		$this->ring       = $ring;
		$this->log_error  = $log_error;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->poll_hook(), [ $this, 'run' ], 10, 2 );
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $task_id Task id; 0 runs the recovery sweep.
	 * @param mixed $attempt Failed polls in a row.
	 * @return void
	 */
	public function run( $task_id = 0, $attempt = 0 ): void {
		if ( self::SWEEP_TASK === (int) $task_id ) {
			$this->sweep();
			return;
		}

		$task = $this->ledger->get( (int) $task_id );

		if ( null === $task || Async_Task::KIND_IMPORT !== $task->kind() ) {
			return;
		}

		try {
			$this->start( $task, (int) $attempt );
		} catch ( \Throwable $e ) {
			$this->report( $task->account_id(), 'poll_task ' . $task_id . ' failed unexpectedly: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Applies the gate and the claim, then polls under the claim.
	 *
	 * @param Async_Task $task    Task.
	 * @param int        $attempt Failed polls in a row.
	 * @return void
	 */
	private function start( Async_Task $task, int $attempt ): void {
		list( $account, $wait ) = $this->gate->check( $task->account_id() );

		if ( null === $account ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->poll_later( $task, $attempt, $wait );
			return;
		}

		$fence = $this->gate->claim( $account->id() );

		if ( null === $fence ) {
			$this->poll_later( $task, $attempt, Job_Gate::BUSY_DELAY );
			return;
		}

		try {
			$this->execute( $account, $task, $attempt, $fence );
		} catch ( \Throwable $e ) {
			$this->unexpected( $account, $task, $attempt, $fence, $e );
		} finally {
			$this->gate->release( $account->id(), $fence );
		}
	}

	/**
	 * Polls both phases of a task.
	 *
	 * @param Account     $account Account.
	 * @param Async_Task  $task    Task.
	 * @param int         $attempt Failed polls in a row.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 */
	private function execute( Account $account, Async_Task $task, int $attempt, Claim_Fence $fence ): void {
		$import = $task->is_final() ? self::PHASE_DONE : $this->poll_import( $account, $task, $attempt, $fence );

		if ( self::PHASE_HANDLED === $import ) {
			return;
		}

		$cards = $this->poll_lifecycle( $account, $task, $attempt, $fence );

		if ( self::PHASE_HANDLED === $cards ) {
			return;
		}

		if ( self::PHASE_OPEN === $import || self::PHASE_OPEN === $cards ) {
			$this->poll_later( $task, 0, $this->interval( $task ) );
		}
	}

	/**
	 * Phase 1: reads the import result.
	 *
	 * @param Account     $account Account.
	 * @param Async_Task  $task    Task.
	 * @param int         $attempt Failed polls in a row.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return string `open`, `done` or `handled` (a failure scheduled its own retry).
	 */
	private function poll_import( Account $account, Async_Task $task, int $attempt, Claim_Fence $fence ): string {
		$task_id = (int) $task->task_id();

		if ( $this->age( $task ) > self::TASK_DEADLINE || null === $task->remote_task_id() ) {
			$this->hold( $task->account_id(), $fence );
			$this->close_open_items( $task_id, 'task_expired', __( 'Маркетплейс не вернул результат за сутки.', 'woodev-plugin-framework' ), $fence );
			$this->ledger->record_poll( $task_id, Async_Task::STATUS_EXPIRED );

			return self::PHASE_DONE;
		}

		try {
			$outcomes = $this->lifecycle->read_import( $account, (string) $task->remote_task_id(), $this->scheduler->poll_hook() );
		} catch ( \Throwable $thrown ) {
			return $this->after_failure( $account, $task, $attempt, $this->remote_error( $thrown ), $fence );
		}

		$this->apply_import( $task_id, $outcomes, $fence );
		$this->hold( $task->account_id(), $fence );

		$items = $this->ledger->items( $task_id );
		$open  = array_filter(
			$items,
			static function ( array $item ): bool {
				return Task_Ledger::ITEM_PENDING === $item['status'];
			}
		);

		if ( [] !== $open ) {
			$this->ledger->record_poll( $task_id, Async_Task::STATUS_POLLING );

			return self::PHASE_OPEN;
		}

		$imported = array_filter(
			$items,
			static function ( array $item ): bool {
				return Task_Ledger::ITEM_IMPORTED === $item['status'];
			}
		);

		$this->ledger->record_poll( $task_id, count( $imported ) === count( $items ) ? Async_Task::STATUS_DONE : Async_Task::STATUS_DONE_WITH_ERRORS, [ 'imported' => count( $imported ) ] );

		return self::PHASE_DONE;
	}

	/**
	 * Writes each final import item to the ledger and its link.
	 *
	 * @param int                          $task_id  Task id.
	 * @param array<string,Import_Outcome> $outcomes What the marketplace reports, by article.
	 * @param Claim_Fence                  $fence    The run's claim.
	 * @return void
	 */
	private function apply_import( int $task_id, array $outcomes, Claim_Fence $fence ): void {
		$by_offer = [];

		foreach ( $this->ledger->items( $task_id ) as $item ) {
			if ( Task_Ledger::ITEM_PENDING === $item['status'] ) {
				$by_offer[ $item['offer_id'] ] = $item['link_id'];
			}
		}

		foreach ( $outcomes as $offer_id => $outcome ) {
			$link_id = $by_offer[ (string) $offer_id ] ?? null;

			if ( null === $link_id || ! $outcome->is_final() ) {
				continue;
			}

			$columns = [
				'last_error_code' => '' === $outcome->error_code() ? null : $outcome->error_code(),
				'last_error_text' => '' === $outcome->error_text() ? null : $outcome->error_text(),
			];

			if ( [] !== $outcome->remote_ids() ) {
				$columns['remote_ids'] = $outcome->remote_ids();
			}

			if ( $this->links->advance( $link_id, $task_id, $outcome->link_state(), $columns, $fence, Link_Record::STATE_SUBMITTED ) ) {
				$this->ledger->settle_item( $task_id, $link_id, $outcome->item_status(), $outcome->errors() );
			}
		}
	}

	/**
	 * Phase 2: follows the task's imported cards until they are created.
	 *
	 * @param Account     $account Account.
	 * @param Async_Task  $task    Task.
	 * @param int         $attempt Failed polls in a row.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return string `open`, `done` or `handled`.
	 */
	private function poll_lifecycle( Account $account, Async_Task $task, int $attempt, Claim_Fence $fence ): string {
		$task_id = (int) $task->task_id();
		$waiting = $this->waiting_cards( $task_id );

		if ( [] === $waiting ) {
			return self::PHASE_DONE;
		}

		try {
			$outcomes = $this->lifecycle->read_cards( $account, $waiting, $this->scheduler->poll_hook() );
		} catch ( \Throwable $thrown ) {
			return $this->after_failure( $account, $task, $attempt, $this->remote_error( $thrown ), $fence );
		}

		$this->ledger->mark_polled( $task_id );

		list( $texts, $created ) = $this->apply_cards( $account, $task_id, $waiting, $outcomes, $fence );

		if ( [] === $this->waiting_cards( $task_id ) ) {
			return self::PHASE_DONE;
		}

		if ( $this->age( $task ) <= self::CARD_DEADLINE ) {
			return self::PHASE_OPEN;
		}

		foreach ( $this->waiting_cards( $task_id, true ) as $card_id => $link ) {
			if ( ! isset( $created[ $card_id ] ) ) {
				$this->fail_link( $task_id, $link, 'card_not_created', sprintf( /* translators: %s: the marketplace's status of the card. */ __( 'Карточка не создана за 3 часа; статус маркетплейса: %s.', 'woodev-plugin-framework' ), $texts[ $card_id ] ?? $link->card_state() ), $fence );
			}
		}

		return self::PHASE_DONE;
	}

	/**
	 * Writes the marketplace's oversize flag of a card to its link. The state stays as the run read it; an answer without the
	 * flag ({@see Card_Outcome::oversize()} is null) keeps the stored value.
	 *
	 * @param int          $task_id Task id.
	 * @param Link_Record  $link    Link the card belongs to.
	 * @param Card_Outcome $outcome What the marketplace reports for the card.
	 * @param Claim_Fence  $fence   The run's claim.
	 * @return void
	 */
	private function remember_oversize( int $task_id, Link_Record $link, Card_Outcome $outcome, Claim_Fence $fence ): void {
		if ( null === $outcome->oversize() ) {
			return;
		}

		$this->links->advance(
			(int) $link->link_id(),
			$task_id,
			$link->card_state(),
			[ 'is_oversize' => $outcome->oversize() ? 1 : 0 ],
			$fence,
			$link->card_state()
		);
	}

	/**
	 * Applies each card of the answer to its link.
	 *
	 * @param Account                    $account  Account.
	 * @param int                        $task_id  Task id.
	 * @param array<string,Link_Record>  $waiting  Card identifier => link.
	 * @param array<string,Card_Outcome> $outcomes What the marketplace reports, by the same identifier.
	 * @param Claim_Fence                $fence    The run's claim.
	 * @return array{0:array<string,string>,1:array<string,bool>} The marketplace's status text, and the cards it created, by identifier.
	 */
	private function apply_cards( Account $account, int $task_id, array $waiting, array $outcomes, Claim_Fence $fence ): array {
		$texts   = [];
		$created = [];

		foreach ( $outcomes as $card_id => $outcome ) {
			$link = $waiting[ $card_id ] ?? null;

			if ( null === $link ) {
				continue;
			}

			$texts[ $card_id ] = $outcome->status_text();

			if ( $outcome->is_created() ) {
				$created[ $card_id ] = true;
			}

			$this->remember_oversize( $task_id, $link, $outcome, $fence );

			if ( Link_Record::STATE_CREATED === $link->card_state() ) {
				$this->learn_late_ids( $task_id, $link, $outcome, $fence );
			} elseif ( $outcome->is_created() ) {
				$this->created( $account, $task_id, $link, $outcome, $fence );
			} elseif ( $outcome->is_failed() ) {
				$this->fail_link( $task_id, $link, 'card_declined', sprintf( /* translators: %s: the marketplace's status of the card. */ __( 'Маркетплейс отклонил карточку: %s', 'woodev-plugin-framework' ), $outcome->status_text() ), $fence );
			} elseif ( $outcome->state() !== $link->card_state() ) {
				$this->links->advance( (int) $link->link_id(), $task_id, $outcome->state(), [], $fence, $link->card_state() );
			}
		}

		return [ $texts, $created ];
	}

	/**
	 * Seeds the first intents, marks the card created and fires the callback. A failed seeding leaves the card for the next poll.
	 *
	 * @param Account      $account Account.
	 * @param int          $task_id Task id.
	 * @param Link_Record  $link    Link.
	 * @param Card_Outcome $outcome Card outcome.
	 * @param Claim_Fence  $fence   The run's claim.
	 * @return void
	 */
	private function created( Account $account, int $task_id, Link_Record $link, Card_Outcome $outcome, Claim_Fence $fence ): void {
		$link_id = (int) $link->link_id();

		// A reset or a newer task during the request makes the answer stale: it seeds nothing.
		if ( ! $this->links->still_owns( $link_id, $task_id, $link->card_state() ) ) {
			return;
		}

		$this->hold( $account->id(), $fence );

		try {
			call_user_func( $this->seed, $account, $link );
		} catch ( \Throwable $e ) {
			$this->report( $account->id(), 'poll_task: the first stock / price intents of link ' . $link_id . ' could not be stored; retried at the next poll: ' . $e->getMessage() );

			return;
		}

		$moved = $this->links->advance(
			$link_id,
			$task_id,
			Link_Record::STATE_CREATED,
			[
				'remote_ids'      => $outcome->remote_ids(),
				'is_created'      => 1,
				'last_error_code' => null,
				'last_error_text' => null,
			],
			$fence,
			$link->card_state()
		);

		if ( ! $moved ) {
			return;
		}

		try {
			call_user_func( $this->on_created, $link_id, $account->id() );
		} catch ( \Throwable $e ) {
			$this->report( $account->id(), 'poll_task: the card-created step of link ' . $link_id . ' failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Stores the identifiers a created card did not have yet and the marketplace gives now; nothing else changes and the callback
	 * does not fire again.
	 *
	 * @param int          $task_id Task id.
	 * @param Link_Record  $link    Link.
	 * @param Card_Outcome $outcome Card outcome.
	 * @param Claim_Fence  $fence   The run's claim.
	 * @return void
	 */
	private function learn_late_ids( int $task_id, Link_Record $link, Card_Outcome $outcome, Claim_Fence $fence ): void {
		$new = Link_Record::clean_ids( array_diff_key( array_intersect_key( $outcome->remote_ids(), array_flip( $this->lifecycle->late_id_names() ) ), $link->remote_ids() ) );

		if ( [] !== $new ) {
			$this->links->advance( (int) $link->link_id(), $task_id, Link_Record::STATE_CREATED, [ 'remote_ids' => $new ], $fence, Link_Record::STATE_CREATED );
		}
	}

	/**
	 * Lists the task's links that wait for their card, and the created ones that still lack an identifier the marketplace gives
	 * late.
	 *
	 * @param int  $task_id      Task id.
	 * @param bool $only_pending Leave out the created cards that only wait for a late identifier.
	 * @return array<string,Link_Record> Card identifier => link.
	 */
	private function waiting_cards( int $task_id, bool $only_pending = false ): array {
		$states  = $only_pending ? Link_Record::LIFECYCLE_STATES : array_merge( Link_Record::LIFECYCLE_STATES, [ Link_Record::STATE_CREATED ] );
		$waiting = [];

		foreach ( $this->links->by_task( $task_id, $states ) as $link ) {
			$card_id = $link->remote_id( $this->lifecycle->card_id_name() );
			$created = Link_Record::STATE_CREATED === $link->card_state();

			if ( null !== $card_id && ( ! $created || $this->lacks_late_id( $link ) ) ) {
				$waiting[ (string) $card_id ] = $link;
			}
		}

		return $waiting;
	}

	/**
	 * Tells whether a link still lacks an identifier the marketplace gives after the card exists.
	 *
	 * @param Link_Record $link Link.
	 * @return bool
	 */
	private function lacks_late_id( Link_Record $link ): bool {
		foreach ( $this->lifecycle->late_id_names() as $name ) {
			if ( null === $link->remote_id( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads a failed request as the adapter's classifier does.
	 *
	 * @param \Throwable $thrown What the request threw.
	 * @return Remote_Error
	 * @throws \Throwable When the classifier does not know the failure: it is an unexpected exception.
	 */
	private function remote_error( \Throwable $thrown ): Remote_Error {
		$error = $this->classifier->classify( $thrown );

		if ( null === $error ) {
			throw $thrown;
		}

		return $error;
	}

	/**
	 * Applies what the classifier says about a failed poll.
	 *
	 * @param Account      $account Account.
	 * @param Async_Task   $task    Task.
	 * @param int          $attempt Failed polls in a row before this one.
	 * @param Remote_Error $error   The refusal.
	 * @param Claim_Fence  $fence   The run's claim.
	 * @return string `handled` when a retry is scheduled, `done` after giving up (the other phase may still run).
	 */
	private function after_failure( Account $account, Async_Task $task, int $attempt, Remote_Error $error, Claim_Fence $fence ): string {
		$this->log( 'poll_task ' . $task->task_id() . ': ' . $error->describe() );

		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->gate->pause()->apply( $account, $error );
			$this->poll_later( $task, $attempt, Account_Pause::SECONDS );

			return self::PHASE_HANDLED;
		}

		if ( $error->is( Remote_Error::THROTTLE ) ) {
			// A wait is not a failed poll; the 24 h / 3 h deadlines still bound the task.
			$this->poll_later( $task, $attempt, max( 1, (int) ( $error->retry_after() ?? Backoff::seconds_after( 1 ) ) ) );

			return self::PHASE_HANDLED;
		}

		if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
			$this->poll_later( $task, $attempt + 1, Backoff::seconds_after( $attempt + 1 ) );

			return self::PHASE_HANDLED;
		}

		$this->give_up( $task, sprintf( /* translators: 1: number of attempts, 2: reason. */ __( 'Не удалось узнать у маркетплейса результат выгрузки после %1$d попыток: %2$s', 'woodev-plugin-framework' ), self::MAX_ATTEMPTS, $error->message() ), $error->kind(), $fence );

		return self::PHASE_DONE;
	}

	/**
	 * Handles an unexpected exception: retried on the ladder, the fifth gives up on what is open.
	 *
	 * @param Account     $account Account.
	 * @param Async_Task  $task    Task.
	 * @param int         $attempt Failed polls in a row before this one.
	 * @param Claim_Fence $fence   The run's claim.
	 * @param \Throwable  $e       The exception.
	 * @return void
	 */
	private function unexpected( Account $account, Async_Task $task, int $attempt, Claim_Fence $fence, \Throwable $e ): void {
		$this->report( $account->id(), 'poll_task ' . $task->task_id() . ' failed unexpectedly: ' . get_class( $e ) . ': ' . $e->getMessage() );

		if ( $e instanceof Claim_Lost ) {
			return; // Another run owns the account now and polls the task itself; a superseded run writes nothing more.
		}

		if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
			$this->poll_later( $task, $attempt + 1, Backoff::seconds_after( $attempt + 1 ) );

			return;
		}

		$this->give_up( $task, sprintf( /* translators: 1: number of attempts, 2: reason. */ __( 'Не удалось обработать ответ маркетплейса после %1$d попыток: %2$s', 'woodev-plugin-framework' ), self::MAX_ATTEMPTS, $e->getMessage() ), 'poll_failed', $fence );
	}

	/**
	 * Gives up on what is still open: open import items fail (the task becomes `failed`), cards still waiting fail.
	 *
	 * @param Async_Task  $task  Task.
	 * @param string      $text  Reason.
	 * @param string      $code  Error code.
	 * @param Claim_Fence $fence The run's claim.
	 * @return void
	 */
	private function give_up( Async_Task $task, string $text, string $code, Claim_Fence $fence ): void {
		$task_id = (int) $task->task_id();

		$this->hold( $task->account_id(), $fence );
		$this->report( $task->account_id(), 'poll_task ' . $task_id . ' gave up: ' . $text );

		$current = $this->ledger->get( $task_id );

		if ( null !== $current && ! $current->is_final() ) {
			$this->close_open_items( $task_id, $code, $text, $fence );
			$this->ledger->record_poll( $task_id, Async_Task::STATUS_FAILED, [ 'error' => $text ] );

			return;
		}

		foreach ( $this->waiting_cards( $task_id, true ) as $link ) {
			$this->fail_link( $task_id, $link, $code, $text, $fence );
		}
	}

	/**
	 * Fails every item still pending, and its link.
	 *
	 * @param int         $task_id Task id.
	 * @param string      $code    Error code.
	 * @param string      $text    Reason.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 */
	private function close_open_items( int $task_id, string $code, string $text, Claim_Fence $fence ): void {
		foreach ( $this->ledger->items( $task_id ) as $item ) {
			if ( Task_Ledger::ITEM_PENDING !== $item['status'] ) {
				continue;
			}

			$this->ledger->settle_item(
				$task_id,
				$item['link_id'],
				Task_Ledger::ITEM_FAILED,
				[
					[
						'code'    => $code,
						'message' => $text,
					],
				]
			);

			$this->links->advance(
				$item['link_id'],
				$task_id,
				Link_Record::STATE_FAILED,
				[
					'last_error_code' => $code,
					'last_error_text' => $text,
				],
				$fence,
				Link_Record::STATE_SUBMITTED
			);
		}
	}

	/**
	 * Fails a link of the task from the state the run read.
	 *
	 * @param int         $task_id Task id.
	 * @param Link_Record $link    Link.
	 * @param string      $code    Error code.
	 * @param string      $text    Reason.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 */
	private function fail_link( int $task_id, Link_Record $link, string $code, string $text, Claim_Fence $fence ): void {
		$this->links->advance(
			(int) $link->link_id(),
			$task_id,
			Link_Record::STATE_FAILED,
			[
				'last_error_code' => $code,
				'last_error_text' => $text,
			],
			$fence,
			$link->card_state()
		);
	}

	/**
	 * Proves, right before a terminal ledger write or a side effect, that the run still holds the account claim, and renews it: a
	 * superseded run stops instead of finalising the newer holder's task. The window left is one statement long.
	 *
	 * @param int         $account_id Account id.
	 * @param Claim_Fence $fence      The run's claim.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function hold( int $account_id, Claim_Fence $fence ): void {
		if ( ! $this->gate->still_holds( $account_id, $fence ) ) {
			throw new Claim_Lost( 'The account claim was taken over during the poll.' );
		}
	}

	/**
	 * The recovery sweep: polls import tasks nobody touched for 30 minutes, and gives the links of a task that never got the
	 * marketplace's number back to the export queue (its run died before or while sending; the articles stay frozen if a request
	 * went out).
	 *
	 * @return void
	 */
	private function sweep(): void {
		$now      = (int) call_user_func( $this->clock );
		$stale    = $now - self::STALE_AFTER;
		$open     = $this->ledger->untouched_since( Async_Task::KIND_IMPORT, [ Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING ], $stale, $now - self::TASK_DEADLINE - self::SWEEP_MARGIN, self::SWEEP_LIMIT );
		$finished = $this->ledger->untouched_since( Async_Task::KIND_IMPORT, [ Async_Task::STATUS_DONE, Async_Task::STATUS_DONE_WITH_ERRORS, Async_Task::STATUS_FAILED, Async_Task::STATUS_EXPIRED ], $stale, $now - self::CARD_DEADLINE - self::SWEEP_MARGIN, self::SWEEP_LIMIT );

		// Unfinished work is found by its links, whatever the task's age: WP-cron on a quiet shop can be down for hours, and only
		// the 24 h / 3 h deadlines may end such a link (with a reason), never its absence from the sweep.
		$owning = $this->ledger->untouched_among( Async_Task::KIND_IMPORT, $this->links->owning_task_ids( self::UNFINISHED_STATES, self::OWNER_SCAN_LIMIT ), $stale, self::SWEEP_LIMIT );

		$tasks = [];
		foreach ( array_merge( $open, $finished, $owning ) as $task ) {
			$tasks[ (int) $task->task_id() ] = $task;
		}

		foreach ( $tasks as $task ) {
			try {
				$this->recover_task( $task );
			} catch ( \Throwable $e ) {
				$this->report( $task->account_id(), 'poll_task sweep: task ' . $task->task_id() . ' not recovered: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Recovers one stale task.
	 *
	 * @param Async_Task $task Task.
	 * @return void
	 */
	private function recover_task( Async_Task $task ): void {
		$task_id = (int) $task->task_id();

		$this->requeue_orphans( $task );

		if ( null !== $task->remote_task_id() || $task->is_final() ) {
			$this->ledger->mark_polled( $task_id );

			if ( ( ! $task->is_final() && null !== $task->remote_task_id() ) || [] !== $this->waiting_cards( $task_id ) ) {
				$this->poll_later( $task, 0, 0 );
			}

			return;
		}

		$stuck = $this->links->by_task( $task_id, [ Link_Record::STATE_SUBMITTED ] );
		$fence = [] === $stuck ? null : $this->gate->claim( $task->account_id() );

		if ( null === $fence ) {
			$this->ledger->mark_polled( $task_id );

			return; // Nothing to give back, or the account is busy: the next sweep looks again.
		}

		try {
			$ids = [];

			foreach ( $stuck as $link ) {
				if ( $this->links->advance( (int) $link->link_id(), $task_id, Link_Record::STATE_QUEUED, [ 'last_task_id' => null ], $fence, Link_Record::STATE_SUBMITTED ) ) {
					$this->ledger->settle_item( $task_id, (int) $link->link_id(), Task_Ledger::ITEM_SKIPPED );
					$ids[] = (int) $link->link_id();
				}
			}

			$this->ledger->record_poll( $task_id, Async_Task::STATUS_FAILED, [ 'reason' => 'reclaimed' ] );

			if ( [] !== $ids ) {
				$this->scheduler->queue_export( $task->account_id(), $ids );
			}
		} finally {
			$this->gate->release( $task->account_id(), $fence );
		}
	}

	/**
	 * Gives a new export batch to the links a task put back into the queue without managing to schedule their retry (they keep the
	 * task id until a retry is scheduled); the task id is cleared once the batch is queued.
	 *
	 * @param Async_Task $task Task.
	 * @return void
	 */
	private function requeue_orphans( Async_Task $task ): void {
		$task_id = (int) $task->task_id();
		$ids     = [];

		foreach ( $this->links->by_task( $task_id, [ Link_Record::STATE_QUEUED ] ) as $link ) {
			$ids[] = (int) $link->link_id();
		}

		if ( [] === $ids ) {
			return;
		}

		$this->scheduler->queue_export( $task->account_id(), $ids );

		foreach ( $ids as $link_id ) {
			$this->links->advance( $link_id, $task_id, Link_Record::STATE_QUEUED, [ 'last_task_id' => null ], null, Link_Record::STATE_QUEUED );
		}
	}

	/**
	 * Returns the seconds since the submit.
	 *
	 * @param Async_Task $task Task.
	 * @return int
	 */
	private function age( Async_Task $task ): int {
		$submitted = $task->submitted_at();

		return null === $submitted ? 0 : (int) call_user_func( $this->clock ) - $submitted->getTimestamp();
	}

	/**
	 * Returns the poll interval: every minute for the first ten minutes, then every five.
	 *
	 * @param Async_Task $task Task.
	 * @return int Seconds.
	 */
	private function interval( Async_Task $task ): int {
		return $this->age( $task ) < self::FAST_PERIOD ? self::FAST_INTERVAL : self::SLOW_INTERVAL;
	}

	/**
	 * Schedules the next poll; a refusal by Action Scheduler is recorded, and the sweep picks the task up again.
	 *
	 * @param Async_Task $task    Task.
	 * @param int        $attempt Failed polls in a row.
	 * @param int        $delay   Seconds to wait.
	 * @return void
	 */
	private function poll_later( Async_Task $task, int $attempt, int $delay ): void {
		try {
			$this->scheduler->poll_later( (int) $task->task_id(), $attempt, $delay );
		} catch ( \Throwable $e ) {
			$this->report( $task->account_id(), 'poll_task ' . $task->task_id() . ' could not be rescheduled; the sweep picks it up: ' . $e->getMessage() );
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
			$this->ring->record( $account_id > 0 ? $account_id : null, '', 0, self::FAILURE_CODE, $message, '', $this->scheduler->poll_hook() );
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

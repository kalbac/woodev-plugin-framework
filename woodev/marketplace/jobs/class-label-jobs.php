<?php
/**
 * Action Scheduler jobs of the label flow.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Flows\Label_Exception;
use Woodev\Framework\Marketplace\Flows\Label_Service;

defined( 'ABSPATH' ) || exit;

/**
 * `{plugin id}_poll_task( task_id, failed )` for label tasks (spec 4.1: single, rescheduled every minute for at most 15 minutes)
 * and a daily prune of the documents older than seven days (spec 3.1).
 *
 * The poll hook is shared by every task kind: this runner answers only for `kind = label` and returns silently for any other task,
 * which the import flow answers on the same hook. While the licence is not in force, or the account is paused, the poll waits
 * without calling the marketplace.
 *
 * @since 2.1.0
 */
final class Label_Jobs {
	public const JOB_POLL  = 'poll_task';
	public const JOB_PRUNE = 'prune_labels';

	public const POLL_SECONDS = 60;

	/**
	 * Gate of licence, account and pause.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Service.
	 *
	 * @var Label_Service
	 */
	private $service;

	/**
	 * Store.
	 *
	 * @var Wpdb_Label_Store
	 */
	private $store;

	/**
	 * Error classifier of the marketplace.
	 *
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * Hook names.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the jobs.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Gate         $gate       Gate of licence, account and pause.
	 * @param Label_Service    $service    Service.
	 * @param Wpdb_Label_Store $store      Store.
	 * @param Error_Classifier $classifier Error classifier of the marketplace.
	 * @param Job_Names        $names      Hook names of the plugin.
	 */
	public function __construct( Job_Gate $gate, Label_Service $service, Wpdb_Label_Store $store, Error_Classifier $classifier, Job_Names $names ) {
		$this->gate       = $gate;
		$this->service    = $service;
		$this->store      = $store;
		$this->classifier = $classifier;
		$this->names      = $names;
	}

	/**
	 * Hooks the jobs.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->poll_hook(), [ $this, 'poll' ], 10, 2 );
		add_action( $this->prune_hook(), [ $this, 'prune' ] );
		add_action( 'action_scheduler_init', [ $this, 'ensure_prune' ] );
	}

	/**
	 * Returns the hook of the poll job.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function poll_hook(): string {
		return $this->names->hook( self::JOB_POLL );
	}

	/**
	 * Returns the hook of the daily prune.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function prune_hook(): string {
		return $this->names->hook( self::JOB_PRUNE );
	}

	/**
	 * Queues the next poll of a task unless the same one is already pending.
	 *
	 * @since 2.1.0
	 *
	 * @param int $task_id Local task id.
	 * @param int $delay   Seconds from now.
	 * @param int $failed  Consecutive failures so far: it is the second argument of the action, so the count survives a restart.
	 * @return void
	 * @throws Persistence_Exception When Action Scheduler could not store the action.
	 */
	public function queue_poll( int $task_id, int $delay = self::POLL_SECONDS, int $failed = 0 ): void {
		$args = [ $task_id, $failed ];

		if ( $this->is_pending( $args ) ) {
			return;
		}

		$action = (int) as_schedule_single_action( time() + max( 0, $delay ), $this->poll_hook(), $args, $this->names->group() );

		// Not a unique action: Action Scheduler counts the action that is running now as a duplicate, and the successor of a poll is
		// queued from inside that poll.
		if ( 0 === $action ) {
			throw Persistence_Exception::queue( 'the poll of label task ' . $task_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Asks the marketplace for the labels of remote orders and queues the first poll. The entry point of the orders screen, after it
	 * has authorised the user for every order (spec 8.5).
	 *
	 * @since 2.1.0
	 *
	 * @param Account  $account       Account the orders belong to.
	 * @param string[] $external_keys Remote-order keys.
	 * @return int Local task id; the screen follows it through {@see Wpdb_Label_Store::task()} and
	 *             {@see Wpdb_Label_Store::document_of_task()}.
	 * @throws Label_Exception With a merchant-readable sentence.
	 * @throws \Throwable When the marketplace refused the call for another reason.
	 * @throws Persistence_Exception When the task or its poll could not be stored.
	 */
	public function request( Account $account, array $external_keys ): int {
		$task_id = $this->service->create( $account, $external_keys );

		$this->queue_poll( $task_id );

		return $task_id;
	}

	/**
	 * Runs `poll_task` for a label task. It never throws out (spec 4.1): a failure either reschedules the poll, following the error
	 * consequence of spec 4.3 and the back-off ladder, or ends the task visibly; no outcome leaves an open task without a next action.
	 *
	 * @since 2.1.0
	 *
	 * @param int|string $task_id Local task id (Action Scheduler passes the stored argument).
	 * @param int|string $failed  Consecutive failures so far.
	 * @return void
	 */
	public function poll( $task_id, $failed = 0 ): void {
		$task_id = (int) $task_id;
		$failed  = (int) $failed;

		try {
			$this->run_poll( $task_id, $failed );
		} catch ( \Throwable $e ) {
			$this->after_failure( $task_id, $failed, $this->classifier->classify( $e ) );
		}
	}

	/**
	 * One poll.
	 *
	 * @param int $task_id Local task id.
	 * @param int $failed  Consecutive failures so far.
	 * @return void
	 */
	private function run_poll( int $task_id, int $failed ): void {
		$task = $this->store->task( $task_id );

		if ( null === $task ) {
			return;
		}

		[ $account, $wait ] = $this->gate->check( $task['account_id'] );

		if ( null === $account ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->queue_poll( $task_id, $wait, $failed );
			return;
		}

		if ( Label_Service::PENDING === $this->service->collect( $account, $task_id ) ) {
			$this->queue_poll( $task_id );
		}
	}

	/**
	 * Applies spec 4.3 to a failed call.
	 *
	 * @param int               $task_id Local task id.
	 * @param int               $failed  Consecutive failures before this one.
	 * @param Remote_Error|null $error   What the failure means; null for a failure of our own (storage, code).
	 * @return void
	 */
	private function after_failure( int $task_id, int $failed, ?Remote_Error $error ): void {
		if ( null === $error ) {
			$this->retry_or_give_up( $task_id, $failed, null );
			return;
		}

		switch ( $error->consequence() ) {
			case Remote_Error::THROTTLE:
				$this->requeue_or_give_up( $task_id, max( 1, (int) $error->retry_after() ), $failed );
				return;
			case Remote_Error::RETRY:
				$this->retry_or_give_up( $task_id, $failed, $error );
				return;
			case Remote_Error::PAUSE_ACCOUNT:
				$this->pause_account( $task_id, $error );
				$this->give_up( $task_id, __( 'Маркетплейс не принял ключ доступа. Проверьте подключение на странице настроек и нажмите «Этикетка» ещё раз.', 'woodev-plugin-framework' ) );
				return;
			default:
				$this->give_up( $task_id, __( 'Маркетплейс изменил или отключил метод печати этикеток. Сообщите в поддержку плагина.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * Pauses the account of a task after a refused key. A store that fails here must not stop the task from ending visibly.
	 *
	 * @param int          $task_id Local task id.
	 * @param Remote_Error $error   The refusal.
	 * @return void
	 */
	private function pause_account( int $task_id, Remote_Error $error ): void {
		try {
			$task = $this->store->task( $task_id );

			if ( null !== $task ) {
				[ $account ] = $this->gate->check( $task['account_id'] );

				if ( null !== $account ) {
					$this->gate->pause()->apply( $account, $error );
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Schedules the next try on the back-off ladder of spec 4.3 (1, 3, 7, 15, 30 minutes), or ends the task after the fifth failure.
	 *
	 * @param int               $task_id Local task id.
	 * @param int               $failed  Consecutive failures before this one.
	 * @param Remote_Error|null $error   The error, null for a failure of our own (storage, code).
	 * @return void
	 */
	private function retry_or_give_up( int $task_id, int $failed, ?Remote_Error $error ): void {
		$count = $failed + 1;
		$delay = null === $error || $error->is( Remote_Error::RETRY ) ? self::ladder( $count ) : null;

		if ( null === $delay ) {
			$this->give_up( $task_id, __( 'Не удалось получить этикетки: маркетплейс или сайт несколько раз не ответили. Нажмите «Этикетка» ещё раз позже.', 'woodev-plugin-framework' ) );
			return;
		}

		$this->requeue_or_give_up( $task_id, $delay, $count );
	}

	/**
	 * Queues the next poll; when even that fails, tries to end the task so it is not left open silently.
	 *
	 * @param int $task_id Local task id.
	 * @param int $delay   Seconds.
	 * @param int $failed  Consecutive failures.
	 * @return void
	 */
	private function requeue_or_give_up( int $task_id, int $delay, int $failed ): void {
		try {
			$this->queue_poll( $task_id, $delay, $failed );
		} catch ( \Throwable $e ) {
			$this->give_up( $task_id, __( 'Не удалось поставить опрос маркетплейса в очередь. Нажмите «Этикетка» ещё раз.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * Ends the task as failed. If the database refuses even that, the poll is queued for the longest back-off: the task's 15-minute
	 * age ends it on the first poll that can write.
	 *
	 * @param int    $task_id Local task id.
	 * @param string $message What to tell the merchant.
	 * @return void
	 */
	private function give_up( int $task_id, string $message ): void {
		try {
			$this->service->abandon( $task_id, $message );
		} catch ( \Throwable $e ) {
			try {
				$this->queue_poll( $task_id, Backoff::seconds_after( count( Backoff::MINUTES ) ), count( Backoff::MINUTES ) - 1 );
			} catch ( \Throwable $queue_failure ) {
				return; // Nothing left that can be done from here; the 24 h sweep of spec 3.1 closes the row.
			}
		}
	}

	/**
	 * Deletes the documents older than seven days.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function prune(): void {
		$this->store->prune();
	}

	/**
	 * Creates the daily prune once.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function ensure_prune(): void {
		if ( ! as_has_scheduled_action( $this->prune_hook(), [], $this->names->group() ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, $this->prune_hook(), [], $this->names->group() );
		}
	}

	/**
	 * Returns the wait after the nth consecutive failure.
	 *
	 * @param int $failures Failures so far, this one included.
	 * @return int|null Seconds; null once the ladder is used up.
	 */
	private static function ladder( int $failures ): ?int {
		return $failures <= count( Backoff::MINUTES ) ? Backoff::seconds_after( $failures ) : null;
	}

	/**
	 * Tells whether the same poll is already waiting.
	 *
	 * @param array<int,int> $args Action arguments.
	 * @return bool
	 * @phpstan-impure
	 */
	private function is_pending( array $args ): bool {
		$pending = as_get_scheduled_actions(
			[
				'hook'     => $this->poll_hook(),
				'args'     => $args,
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		return is_array( $pending ) && [] !== $pending;
	}
}

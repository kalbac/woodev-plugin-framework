<?php
/**
 * The jobs that keep the shop's copy of the shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Flows\Shipment_Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Two hooks:
 *
 * - `{plugin id}_poll_shipments( account_id )` — recurring per enabled account, every {@see self::POLL_MINUTES} minutes;
 * - `{plugin id}_refresh_shipments( account_id, resume )` — single, queued by «refresh the list», after an action, and by a run
 *   that stopped at its budget (`resume` = 1: go on with it).
 *
 * A refresh lists every shipment ({@see self::PAGES_PER_RUN} pages a run), asks which units must be shipped again, removes the
 * shipments the marketplace no longer lists, reads the units of the shipments that need it ({@see self::READS_PER_RUN} a run: see
 * {@see Wpdb_Shipment_Store}), and marks the units to ship again. While the licence is not in force, or the account is paused after a refused key,
 * neither job calls the marketplace: the next poll tries again.
 *
 * @since 2.1.28
 */
final class Shipment_Jobs {
	public const JOB_POLL    = 'poll_shipments';
	public const JOB_REFRESH = 'refresh_shipments';

	/**
	 * Minutes between two polls of an account.
	 */
	public const POLL_MINUTES = 15;

	/**
	 * Most list pages one run reads.
	 */
	public const PAGES_PER_RUN = 10;

	/**
	 * Most shipments one run reads.
	 */
	public const READS_PER_RUN = 20;

	/**
	 * Transient that spaces the check of the recurring polls.
	 */
	private const CHECK_TRANSIENT = 'shipment_poll_check';

	/**
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * @var Shipment_Snapshot
	 */
	private $snapshot;

	/**
	 * @var Wpdb_Shipment_Store
	 */
	private $store;

	/**
	 * @var Error_Classifier
	 */
	private $classifier;

	/**
	 * @var Job_Names
	 */
	private $names;

	/**
	 * The marketplace's id: the accounts the polls are made for.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * @since 2.1.28
	 *
	 * @param Job_Gate            $gate       Gate of licence, account and pause.
	 * @param Wpdb_Account_Store  $accounts   Accounts.
	 * @param Shipment_Snapshot   $snapshot   The reads.
	 * @param Wpdb_Shipment_Store $store      The shop's copy.
	 * @param Error_Classifier    $classifier Error classifier of the marketplace.
	 * @param Job_Names           $names      Hook names of the plugin.
	 * @param string              $adapter_id The marketplace's id (the adapter's `id()`).
	 * @param callable|null       $log        `function ( string $line ): void`.
	 */
	public function __construct( Job_Gate $gate, Wpdb_Account_Store $accounts, Shipment_Snapshot $snapshot, Wpdb_Shipment_Store $store, Error_Classifier $classifier, Job_Names $names, string $adapter_id, ?callable $log = null ) {
		$this->gate       = $gate;
		$this->accounts   = $accounts;
		$this->snapshot   = $snapshot;
		$this->store      = $store;
		$this->classifier = $classifier;
		$this->names      = $names;
		$this->adapter_id = $adapter_id;
		$this->log        = $log;
	}

	/**
	 * Hooks the jobs.
	 *
	 * @since 2.1.28
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->names->hook( self::JOB_REFRESH ), [ $this, 'refresh' ], 10, 2 );
		add_action( $this->names->hook( self::JOB_POLL ), [ $this, 'poll' ] );
		add_action( 'action_scheduler_init', [ $this, 'ensure_polls' ] );
		add_action( $this->names->hook( Posting_Jobs::ACCOUNT_SAVED ), [ $this, 'ensure_poll_for' ] );
	}

	/**
	 * Queues a refresh unless the same one is already pending.
	 *
	 * @since 2.1.28
	 *
	 * @param int  $account_id Account.
	 * @param int  $delay      Seconds from now.
	 * @param bool $resume     Whether to go on with the refresh a run left unfinished.
	 * @return void
	 * @throws Persistence_Exception When Action Scheduler could not store the action.
	 */
	public function queue_refresh( int $account_id, int $delay = 0, bool $resume = false ): void {
		$hook = $this->names->hook( self::JOB_REFRESH );
		$args = [ $account_id, $resume ? 1 : 0 ];

		$this->store->mark_refreshing( $account_id );

		$pending = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		if ( is_array( $pending ) && [] !== $pending ) {
			return;
		}

		if ( 0 === (int) as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, $this->names->group() ) ) {
			throw Persistence_Exception::queue( 'the refresh of the shipments' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Runs `poll_shipments`.
	 *
	 * @since 2.1.28
	 *
	 * @param int|string $account_id Account id.
	 * @return void
	 * @throws \Throwable When the marketplace refused a call for good.
	 */
	public function poll( $account_id ): void {
		$this->refresh( $account_id, 0 );
	}

	/**
	 * Runs `refresh_shipments`.
	 *
	 * @since 2.1.28
	 *
	 * @param int|string $account_id Account id (Action Scheduler passes the stored argument).
	 * @param int|string $resume     1 to go on with an unfinished refresh.
	 * @return void
	 * @throws \Throwable When the marketplace refused a call for good (Action Scheduler records the failure).
	 */
	public function refresh( $account_id, $resume = 0 ): void {
		$account_id = (int) $account_id;
		$resume     = 1 === (int) $resume;

		list( $account, $wait ) = $this->gate->check( $account_id );

		if ( null === $account ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->store->stop_refreshing( $account_id ); // The recurring poll tries again; the screen must not say «refreshing» meanwhile.

			return;
		}

		try {
			$finished = $this->list_all( $account, $resume ) && $this->read_stale( $account );

			if ( ! $finished ) {
				$this->queue_refresh( $account_id, 0, true );

				return;
			}

			$this->snapshot->mark_reshipment( $account );
			$this->store->finish_refresh( $account_id );
		} catch ( \Throwable $e ) {
			$delay = $this->settle( $account, $e );

			if ( null === $delay ) {
				$this->store->fail_refresh( $account_id, __( 'Не удалось получить данные с маркетплейса. Показано последнее полученное, повторим позже.', 'woodev-plugin-framework' ) );

				throw $e;
			}

			$this->queue_refresh( $account_id, $delay, true );
		}
	}

	/**
	 * Creates the recurring poll of every enabled account. Checked at most once an hour.
	 *
	 * @since 2.1.28
	 *
	 * @return void
	 */
	public function ensure_polls(): void {
		$transient = $this->names->hook( self::CHECK_TRANSIENT );

		if ( false !== get_transient( $transient ) ) {
			return;
		}

		set_transient( $transient, '1', HOUR_IN_SECONDS );

		foreach ( $this->accounts->all( $this->adapter_id, true ) as $account ) {
			$this->schedule_poll( $account );
		}
	}

	/**
	 * Creates the recurring poll of one account right after it was saved.
	 *
	 * @since 2.1.28
	 *
	 * @param int|string $account_id Account id (the `account_saved` action's argument).
	 * @return void
	 */
	public function ensure_poll_for( $account_id ): void {
		$account = $this->accounts->get( (int) $account_id );

		if ( null !== $account && $account->is_enabled() && $account->adapter_id() === $this->adapter_id ) {
			$this->schedule_poll( $account );
		}
	}

	/**
	 * Lists every page of the account's shipments, within the run's budget.
	 *
	 * @param Account $account Account.
	 * @param bool    $resume  Whether to go on with the listing in progress.
	 * @return bool Whether the listing is over (or there was none to go on with).
	 * @throws \Throwable When a page cannot be read or stored.
	 */
	private function list_all( Account $account, bool $resume ): bool {
		if ( $resume ) {
			$listing = $this->store->listing( $account->id() );
			$run     = $listing['run'];
			$cursor  = $listing['cursor'];
		} else {
			$run    = $this->store->begin_refresh( $account->id() );
			$cursor = '';
		}

		if ( 0 === $run ) {
			return true;
		}

		for ( $page = 0; $page < self::PAGES_PER_RUN; ++$page ) {
			$cursor = $this->snapshot->list_page( $account, $cursor, $run );

			if ( '' === $cursor ) {
				$this->snapshot->read_reshipment( $account ); // Before the listing ends: a failure here is retried with it.
				$this->store->finish_listing( $account->id(), $run );

				return true;
			}

			$this->store->save_cursor( $account->id(), $cursor );
		}

		return false;
	}

	/**
	 * Reads the shipments marked for reading, within the run's budget. One that cannot be read is logged and left for the next
	 * refresh, so it never holds up the others; the refresh then says that it is incomplete.
	 *
	 * @param Account $account Account.
	 * @return bool Whether nothing is left for another run.
	 * @throws \Throwable When the marketplace asks to wait or refused the key: nothing more can be read now.
	 */
	private function read_stale( Account $account ): bool {
		$rows = $this->store->stale( $account->id(), self::READS_PER_RUN );

		foreach ( $rows as $row ) {
			try {
				$this->snapshot->read( $account, $row );
			} catch ( \Throwable $e ) {
				$error = $this->classifier->classify( $e );

				if ( null !== $error && ( $error->is( Remote_Error::THROTTLE ) || $error->is( Remote_Error::PAUSE_ACCOUNT ) ) ) {
					throw $e;
				}

				$this->write_log( sprintf( 'Reading the shipment %s failed: %s', $row['remote_id'], $e->getMessage() ) );
				$this->store->skip_reading( $account->id(), (string) $row['shipment_key'] );
				$this->store->flag_partial( $account->id(), __( 'Не всё удалось прочитать с маркетплейса: повторим при следующем обновлении.', 'woodev-plugin-framework' ) );
			}
		}

		return count( $rows ) < self::READS_PER_RUN || [] === $this->store->stale( $account->id(), 1 );
	}

	/**
	 * @param Account $account Account.
	 * @return void
	 */
	private function schedule_poll( Account $account ): void {
		$hook = $this->names->hook( self::JOB_POLL );

		if ( as_has_scheduled_action( $hook, [ $account->id() ], $this->names->group() ) ) {
			return;
		}

		as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::POLL_MINUTES * MINUTE_IN_SECONDS, $hook, [ $account->id() ], $this->names->group() );
	}

	/**
	 * Applies what a failure means for the account and returns how long to wait when the marketplace asked to. Any other failure
	 * ends the refresh: the recurring poll is its retry.
	 *
	 * @param Account    $account Account the call was made for.
	 * @param \Throwable $thrown  The failure.
	 * @return int|null Seconds; null for a failure that is not a request to wait.
	 */
	private function settle( Account $account, \Throwable $thrown ): ?int {
		$error = $this->classifier->classify( $thrown );

		if ( null === $error ) {
			return null;
		}

		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->gate->pause()->apply( $account, $error );
		}

		return $error->is( Remote_Error::THROTTLE ) ? max( 1, (int) $error->retry_after() ) : null;
	}

	/**
	 * @param string $line Log line.
	 * @return void
	 */
	private function write_log( string $line ): void {
		if ( null !== $this->log ) {
			call_user_func( $this->log, $line );
		}
	}
}

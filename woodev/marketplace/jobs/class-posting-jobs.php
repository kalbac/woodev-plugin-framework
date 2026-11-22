<?php
/**
 * Action Scheduler jobs of the posting flow.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Flows\History_Import;
use Woodev\Framework\Marketplace\Orders\Order_Claim_Exception;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Sync\Stock_Mode;

defined( 'ABSPATH' ) || exit;

/**
 * The two posting hooks of spec 4.1, and the only place that talks to Action Scheduler about them:
 *
 * - `{plugin id}_refresh_posting( account_id, posting_number )` — single, queued by the push events, by the poller and by the v1
 *   migration's cleanup step; at most one pending per posting. A posting another writer holds is tried again in two minutes; a
 *   temporary marketplace failure after its back-off;
 * - `{plugin id}_poll_postings( account_id )` — recurring per account, every `poll_postings_minutes` (default 10), for every
 *   switched-on account that creates orders or keeps the site's stock (spec 5.3: the registry-only accounting needs the postings
 *   even when no order is created).
 *
 * The job names are the donor plugin's, so the actions already queued in a shop keep running after the plugin moves to the
 * framework. While the licence is not in force, or the account is paused after a refused key, neither job calls the marketplace:
 * a refresh waits, a poll waits for its next run.
 *
 * @since 2.1.0
 */
final class Posting_Jobs {
	public const JOB_REFRESH = 'refresh_posting';
	public const JOB_POLL    = 'poll_postings';

	/**
	 * Name of the settings-API option of the poll interval in minutes (spec 3.3), after the settings prefix.
	 */
	public const OPTION_POLL_MINUTES = 'poll_postings_minutes';

	public const DEFAULT_POLL_MINUTES = 10;

	/**
	 * Transient that spaces the check of the recurring polls.
	 */
	private const CHECK_TRANSIENT = 'poll_check';

	/**
	 * Gate of licence, account and pause.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * The marketplace's order flow.
	 *
	 * @var Posting_Handler
	 */
	private $handler;

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
	 * The marketplace's id: the accounts the polls are made for.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Full key of the settings option that holds the poll interval.
	 *
	 * @var string
	 */
	private $poll_option;

	/**
	 * Where a poll attempt that ended without an error is recorded (a partial catch-up included); null records nothing.
	 *
	 * @var Posting_Poll_Times|null
	 */
	private $poll_times;

	/**
	 * Where each account's history ends; null keeps every write live.
	 *
	 * @var Posting_History_Cutoffs|null
	 */
	private $cutoffs;

	/**
	 * Creates the jobs.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Gate                     $gate       Gate of licence, account and pause.
	 * @param Wpdb_Account_Store           $accounts   Accounts.
	 * @param Posting_Handler              $handler    The marketplace's order flow.
	 * @param Error_Classifier             $classifier Error classifier of the marketplace.
	 * @param Job_Names                    $names      Hook names of the plugin.
	 * @param string                       $adapter_id The marketplace's id (the adapter's `id()`).
	 * @param string                       $poll_option Key of the settings option that holds the poll interval, as {@see self::option_key()} builds it.
	 * @param Posting_Poll_Times|null      $poll_times Where a poll attempt that ended without an error is recorded (since 2.1.13); null records nothing.
	 * @param Posting_History_Cutoffs|null $cutoffs Where each account's history ends (since 2.1.15); null, or no record of polls, keeps every write live.
	 */
	public function __construct( Job_Gate $gate, Wpdb_Account_Store $accounts, Posting_Handler $handler, Error_Classifier $classifier, Job_Names $names, string $adapter_id, string $poll_option, ?Posting_Poll_Times $poll_times = null, ?Posting_History_Cutoffs $cutoffs = null ) {
		$this->poll_times  = $poll_times;
		$this->cutoffs     = $cutoffs;
		$this->gate       = $gate;
		$this->accounts   = $accounts;
		$this->handler    = $handler;
		$this->classifier = $classifier;
		$this->names      = $names;
		$this->adapter_id = $adapter_id;
		$this->poll_option = $poll_option;
	}

	/**
	 * Builds the key of the poll-interval option from the plugin id as the settings API prefixes it.
	 *
	 * Not {@see Job_Names::hook()}: that one turns dashes into underscores, but a saved setting keeps the plugin id as it is
	 * (`woodev_ozon-seller_poll_postings_minutes`), and a site that saved an interval must keep it.
	 *
	 * @since 2.1.0
	 *
	 * @param string $plugin_id The plugin id, not normalised.
	 * @return string
	 */
	public static function option_key( string $plugin_id ): string {
		return 'woodev_' . $plugin_id . '_' . self::OPTION_POLL_MINUTES;
	}

	/**
	 * Hooks the jobs.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->refresh_hook(), [ $this, 'refresh' ], 10, 2 );
		add_action( $this->poll_hook(), [ $this, 'poll' ] );
		add_action( 'action_scheduler_init', [ $this, 'ensure_polls' ] );
	}

	/**
	 * Returns the hook of the refresh job.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function refresh_hook(): string {
		return $this->names->hook( self::JOB_REFRESH );
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
	 * Queues the refresh of a posting unless one is already pending.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $account_id     Account id.
	 * @param string $posting_number Posting number.
	 * @param int    $delay          Seconds from now.
	 * @return void
	 * @throws Persistence_Exception When Action Scheduler could not store the action.
	 */
	public function queue_refresh( int $account_id, string $posting_number, int $delay = 0 ): void {
		$args    = [ $account_id, $posting_number ];
		$pending = as_get_scheduled_actions(
			[
				'hook'     => $this->refresh_hook(),
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

		if ( 0 === (int) as_schedule_single_action( time() + max( 0, $delay ), $this->refresh_hook(), $args, $this->names->group() ) ) {
			// Action Scheduler returns 0 when it could not store the action: the event or poll page must not count as handled.
			throw Persistence_Exception::queue( 'the refresh of posting ' . $posting_number ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Runs `refresh_posting`.
	 *
	 * The order is written inside {@see History_Import} with the account's cutoff, so an order of the account's history sends no
	 * WooCommerce e-mail whoever queued this refresh and whenever it runs (#1925).
	 *
	 * @since 2.1.0
	 *
	 * @param int|string $account_id     Account id (Action Scheduler passes the stored argument).
	 * @param string     $posting_number Posting number.
	 * @return void
	 * @throws \Throwable When the marketplace refused the call for good (Action Scheduler records the failure).
	 */
	public function refresh( $account_id, $posting_number ): void {
		$account_id     = (int) $account_id;
		$posting_number = (string) $posting_number;

		[ $account, $wait ] = $this->gate->check( $account_id );

		if ( null === $account ) {
			return;
		}

		if ( $wait > 0 ) {
			$this->queue_refresh( $account_id, $posting_number, $wait );
			return;
		}

		try {
			History_Import::during(
				$this->stored_cutoff( $account_id ),
				function () use ( $account_id, $posting_number ): void {
					$this->handler->refresh( $account_id, $posting_number );
				}
			);
		} catch ( Order_Claim_Exception $e ) {
			$this->queue_refresh( $account_id, $posting_number, Order_Claim_Exception::RETRY_SECONDS );
		} catch ( \Throwable $e ) {
			$delay = $this->settle( $account, $e );

			if ( null === $delay ) {
				throw $e;
			}

			$this->queue_refresh( $account_id, $posting_number, $delay );
		}
	}

	/**
	 * Runs `poll_postings`.
	 *
	 * The first poll of an account fixes the account's history cutoff — the moment it began — and every poll and refresh after it
	 * writes inside {@see History_Import} with that cutoff: the orders the marketplace created before it send no WooCommerce e-mail,
	 * however many polls the import takes (#1925). The orders created after it mail as WooCommerce always does.
	 *
	 * @since 2.1.0
	 *
	 * @param int|string $account_id Account id.
	 * @return void
	 * @throws \Throwable When the marketplace refused a list call (the watermark keeps what was fully processed).
	 */
	public function poll( $account_id ): void {
		[ $account, $wait ] = $this->gate->check( (int) $account_id );

		if ( null === $account || $wait > 0 || ! self::wants_postings( $account ) ) {
			return;
		}

		try {
			History_Import::during(
				$this->poll_cutoff( $account ),
				function () use ( $account ): void {
					$this->handler->catch_up( $account );
				}
			);
		} catch ( \Throwable $e ) {
			$this->settle( $account, $e );

			throw $e;
		}

		if ( null !== $this->poll_times ) {
			$this->poll_times->record( $account->id(), time() );
		}
	}

	/**
	 * Creates the recurring poll of every account that needs one. Checked at most once an hour.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function ensure_polls(): void {
		$transient = $this->names->hook( self::CHECK_TRANSIENT );

		if ( false !== get_transient( $transient ) ) {
			return;
		}

		set_transient( $transient, '1', HOUR_IN_SECONDS );

		$interval = max( 1, (int) get_option( $this->poll_option, self::DEFAULT_POLL_MINUTES ) ) * MINUTE_IN_SECONDS;

		foreach ( $this->accounts->all( $this->adapter_id, true ) as $account ) {
			if ( self::wants_postings( $account ) && ! as_has_scheduled_action( $this->poll_hook(), [ $account->id() ], $this->names->group() ) ) {
				as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $interval, $this->poll_hook(), [ $account->id() ], $this->names->group() );
			}
		}
	}

	/**
	 * Tells whether an account needs its postings: it creates orders, or the site keeps its stock (spec 5.3, 6.4).
	 *
	 * @since 2.1.0
	 *
	 * @param Account $account Account.
	 * @return bool
	 */
	public static function wants_postings( Account $account ): bool {
		return Effective_Settings::for_adapter( $account->adapter_id() )->orders_enabled( $account ) || Stock_Mode::is_site( $account );
	}

	/**
	 * The history cutoff of an account the poll is about to read, fixed when the poll is the account's first.
	 *
	 * An account that has already ended a poll without an error when the record began has no history: its cutoff is 0 and its orders
	 * are all live, as they were. Without the record of polls nothing tells the two apart, and the cutoff is 0.
	 *
	 * @param Account $account Account.
	 * @return int Unix time; 0 for no history.
	 */
	private function poll_cutoff( Account $account ): int {
		if ( null === $this->cutoffs || null === $this->poll_times ) {
			return 0;
		}

		$stored = $this->cutoffs->get( $account->id() );

		if ( null !== $stored ) {
			return $stored;
		}

		$cutoff = null === $this->poll_times->last( $account->id() ) ? time() : 0;

		$this->cutoffs->remember( $account->id(), $cutoff );

		return $cutoff;
	}

	/**
	 * The history cutoff of an account a refresh is about to write: the one its first poll fixed, 0 when no poll has begun. A refresh
	 * never fixes it — an order pushed to the shop before the first poll is a live one.
	 *
	 * @param int $account_id Account id.
	 * @return int Unix time; 0 for no history.
	 */
	private function stored_cutoff( int $account_id ): int {
		return null === $this->cutoffs ? 0 : (int) $this->cutoffs->get( $account_id );
	}

	/**
	 * Applies what a failure means for the account and returns how long to wait before a temporary one is tried again.
	 *
	 * A refused key pauses the account, whatever the caller does with the failure next.
	 *
	 * @param Account    $account Account the call was made for.
	 * @param \Throwable $thrown  The failure.
	 * @return int|null Seconds; null for a lasting failure or one the classifier does not know.
	 */
	private function settle( Account $account, \Throwable $thrown ): ?int {
		$error = $this->classifier->classify( $thrown );

		if ( null === $error ) {
			return null;
		}

		if ( $error->is( Remote_Error::PAUSE_ACCOUNT ) ) {
			$this->gate->pause()->apply( $account, $error );
		}

		if ( $error->is( Remote_Error::THROTTLE ) ) {
			return max( 1, (int) $error->retry_after() );
		}

		return $error->is( Remote_Error::RETRY ) ? Backoff::seconds_after( 1 ) : null;
	}
}

<?php
/**
 * The stock reseed job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_reseed_stock( account_id, after_link_id )`: after an account's default or oversize warehouse changed
 * (`woodev_{plugin id}_account_warehouse_changed( account_id )`), every created card of the account has its stock recorded again
 * ({@see Stock_Intents::record_stock()}): the new warehouse gets the stock and the left one gets zero, without waiting for the
 * product's stock to change. A trashed product is left to its archive. One page per run, then the job schedules itself with the
 * cursor. Nothing is sent here: the flush sends the pairs whose payload really changed. So the job takes no job gate — like a WooCommerce stock change, it only writes
 * down what the shop wants, also for an account that is switched off at the moment.
 *
 * A page that fails is retried with the same cursor on the ladder of {@see Backoff} and given up after five attempts with a log
 * line.
 *
 * @since 2.1.25
 */
final class Reseed_Stock_Runner {
	public const JOB          = 'reseed_stock';
	public const CHANGE       = 'account_warehouse_changed';
	public const PAGE         = 100;
	public const MAX_ATTEMPTS = 5;

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Stock intents.
	 *
	 * @var Stock_Intents
	 */
	private $stock;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.25
	 *
	 * @param Job_Names       $names     Job names of the plugin.
	 * @param Wpdb_Link_Store $links     Links.
	 * @param Stock_Intents   $stock     Stock intents (not necessarily hooked).
	 * @param callable        $log_error `function ( string $line ): void`.
	 */
	public function __construct( Job_Names $names, Wpdb_Link_Store $links, Stock_Intents $stock, callable $log_error ) {
		$this->names     = $names;
		$this->links     = $links;
		$this->stock     = $stock;
		$this->log_error = $log_error;
	}

	/**
	 * Hooks the job and the change it follows.
	 *
	 * @since 2.1.25
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->names->hook( self::CHANGE ), [ $this, 'queue' ] );
		add_action( $this->names->hook( self::JOB ), [ $this, 'run' ], 10, 3 );
	}

	/**
	 * Queues the reseed of an account from its first link. A request that waits unstarted already covers this one; one that is
	 * running may be past some links already, so it does not. A refusal of Action Scheduler is logged, never thrown: the
	 * settings are being saved.
	 *
	 * @since 2.1.25
	 *
	 * @param mixed $account_id Account id.
	 * @return void
	 */
	public function queue( $account_id = 0 ): void {
		$account_id = (int) $account_id;

		try {
			if ( ! $this->is_waiting( $account_id ) ) {
				$this->schedule( $account_id, 0 );
			}
		} catch ( \Throwable $e ) {
			$this->log( 'reseed_stock: account ' . $account_id . ' was not queued: ' . $e->getMessage() );
		}
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.25
	 *
	 * @param mixed $account_id    Account id.
	 * @param mixed $after_link_id Last link id already recorded.
	 * @param mixed $attempt       Failed attempts of this page so far.
	 * @return void
	 */
	public function run( $account_id = 0, $after_link_id = 0, $attempt = 0 ): void {
		$account_id    = (int) $account_id;
		$after_link_id = (int) $after_link_id;
		$attempt       = (int) $attempt;

		try {
			$page = $this->links->page( $account_id, $after_link_id, self::PAGE );

			foreach ( $page as $link ) {
				if ( $link->is_offer() && Link_Record::STATE_CREATED === $link->card_state() && 'trash' !== get_post_status( $link->wc_product_id() ) ) {
					$this->stock->record_stock( $link );
				}
			}

			if ( count( $page ) === self::PAGE ) {
				$this->schedule( $account_id, (int) end( $page )->link_id() );
			}
		} catch ( \Throwable $e ) {
			$this->retry( $account_id, $after_link_id, $attempt, $e );
		}
	}

	/**
	 * Tells whether a reseed of the account from its first link is waiting to start.
	 *
	 * @param int $account_id Account id.
	 * @return bool
	 */
	private function is_waiting( int $account_id ): bool {
		$pending = as_get_scheduled_actions(
			[
				'hook'     => $this->names->hook( self::JOB ),
				'args'     => [ $account_id, 0 ],
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		return [] !== (array) $pending;
	}

	/**
	 * Schedules one page.
	 *
	 * @param int $account_id    Account id.
	 * @param int $after_link_id Last link id already recorded.
	 * @param int $delay         Seconds to wait; 0 runs it as soon as a queue runner is free.
	 * @param int $attempt       Failed attempts of this page so far.
	 * @return void
	 * @throws \RuntimeException When Action Scheduler refuses the action.
	 */
	private function schedule( int $account_id, int $after_link_id, int $delay = 0, int $attempt = 0 ): void {
		$hook    = $this->names->hook( self::JOB );
		$args    = $attempt > 0 ? [ $account_id, $after_link_id, $attempt ] : [ $account_id, $after_link_id ];
		$planned = $delay > 0
			? as_schedule_single_action( time() + $delay, $hook, $args, $this->names->group() )
			: as_enqueue_async_action( $hook, $args, $this->names->group() );

		if ( 0 === $planned ) {
			throw new \RuntimeException( 'Action Scheduler did not queue the stock reseed.' );
		}
	}

	/**
	 * Schedules the same page again, or gives up after the last attempt.
	 *
	 * @param int        $account_id    Account id.
	 * @param int        $after_link_id Cursor of the failed page.
	 * @param int        $attempt       Failed attempts before this one.
	 * @param \Throwable $error         What failed.
	 * @return void
	 */
	private function retry( int $account_id, int $after_link_id, int $attempt, \Throwable $error ): void {
		$this->log( 'reseed_stock: account ' . $account_id . ' after link ' . $after_link_id . ' (attempt ' . ( $attempt + 1 ) . '): ' . get_class( $error ) . ': ' . $error->getMessage() );

		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			$this->log( 'reseed_stock: account ' . $account_id . ' gave up after link ' . $after_link_id . '; use «Отправить остатки» for the rest.' );

			return;
		}

		try {
			$this->schedule( $account_id, $after_link_id, Backoff::seconds_after( $attempt + 1 ), $attempt + 1 );
		} catch ( \Throwable $e ) {
			$this->log( 'reseed_stock: the retry was not scheduled: ' . $e->getMessage() );
		}
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

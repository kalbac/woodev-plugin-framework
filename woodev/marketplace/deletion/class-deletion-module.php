<?php
/**
 * Wires the deletion of an account into a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Link_Progress_Store;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Governor\Wpdb_Throttle_Store;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\Label_Jobs;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the deletion job, hooks it, and registers the REST route of the settings screen. Nothing here names a marketplace: the
 * tables come from the adapter's schema and the one marketplace-specific part is the adapter's optional
 * {@see Account_Data_Cleaner}.
 *
 * The steps run in this order; each deletes what is left of the account and the account row goes after the last:
 *
 * `queue` (its queued jobs) · `links` (their remote ids first) · `sync_state` (after the links: a stock or price change writes a row for every link left) · `tasks` (their items first) · `postings` ·
 * `documents` · `throttle` · `api_errors` · `options` (progress and report options, snapshot transients, cached catalogue rows) ·
 * `product_meta` (pinned articles) · `events` · and the adapter's own, `plugin`.
 *
 * @since 2.1.5
 */
final class Deletion_Module {
	/**
	 * Plugin ids already wired: the registration must not hook a job twice.
	 *
	 * @var array<string,bool>
	 */
	private static $wired = [];

	/**
	 * Hooks the job and the route for a plugin.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts The plugin's account store.
	 * @param \wpdb              $db       Database.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): void {
		if ( isset( self::$wired[ $plugin->get_id() ] ) ) {
			return;
		}

		self::$wired[ $plugin->get_id() ] = true;

		$parts = self::build( $plugin, $accounts, $db );

		$parts['job']->register();

		add_action(
			'rest_api_init',
			static function () use ( $plugin, $parts ): void {
				$menu = $plugin->get_marketplace_admin_menu();

				( new Account_Deletion_Controller( $plugin->get_id(), null === $menu ? 'manage_woocommerce' : $menu->get_capability(), $parts['service'] ) )->register_routes();
			}
		);
	}

	/**
	 * Forgets the plugins wired so far (tests).
	 *
	 * @since 2.1.5
	 *
	 * @internal
	 * @return void
	 */
	public static function forget(): void {
		self::$wired = [];
	}

	/**
	 * Builds the job and the service of a plugin.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts The plugin's account store.
	 * @param \wpdb              $db       Database.
	 * @return array{job:Account_Deletion_Job,service:Account_Deletion}
	 */
	public static function build( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): array {
		$adapter   = $plugin->get_marketplace_adapter();
		$schema    = new Schema( $adapter->id() );
		$names     = new Job_Names( $plugin->get_id() );
		$options   = Uninstaller::option_prefix( $plugin->get_id() );
		$gate      = new Job_Gate( $accounts, License_Gate::for_plugin( $plugin ), Account_Pause::over( $accounts, $adapter->error_classifier() ) );
		$progress  = new Account_Deletion_Progress( $options );
		$scheduler = new Account_Deletion_Scheduler( $names );
		$stores    = self::stores( $db, $schema );

		$queue = new Account_Queue_Cleaner( $names->group(), $names->hook( '' ), [ $scheduler->hook(), $names->hook( Label_Jobs::JOB_POLL ) ] );
		$steps = self::steps(
			$queue,
			$stores,
			self::forget_options( $options, new Wpdb_Catalog_Cache( $db, $schema->table_name( 'catalog_cache', $db->prefix ) ) ),
			new Pinned_Offer_Eraser( $db, $adapter->id() ),
			new Wpdb_Event_Ledger( $db, $schema->table_name( 'events', $db->prefix ) )
		);

		$has_push = $adapter->supports( Capability::PUSH_NOTIFICATIONS );
		$cleaner  = $adapter instanceof Account_Data_Cleaner ? $adapter : null;
		$job      = new Account_Deletion_Job(
			$accounts,
			$gate,
			$progress,
			$scheduler,
			$steps,
			$cleaner,
			static function ( $account ) use ( $has_push ): bool {
				return $has_push && [] !== (array) $account->setting( 'notification_state', [] );
			},
			[ $plugin, 'log_error' ]
		);

		$preview = new Account_Deletion_Preview(
			[
				'links'     => [ $stores['links'], 'count_for_account' ],
				'tasks'     => [ $stores['tasks'], 'count_for_account' ],
				'postings'  => [ $stores['postings'], 'count_for_account' ],
				'documents' => [ $stores['documents'], 'count_for_account' ],
			],
			Effective_Settings::for_adapter( $adapter->id() ),
			$has_push
		);

		return [
			'job'     => $job,
			'service' => new Account_Deletion( $accounts, $adapter->id(), $adapter->credential_fields()->identifier(), $preview, $progress, $scheduler ),
		];
	}

	/**
	 * The table stores of an account, in the order their rows are deleted.
	 *
	 * The links go before `sync_state`: a stock or price change writes a `sync_state` row for every link it still finds, so deleting
	 * the state first lets a sale during a long deletion leave rows behind.
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb  $db     Database.
	 * @param Schema $schema Schema of the marketplace.
	 * @return array<string,object> Stores with `delete_account()`, by step name.
	 */
	public static function stores( \wpdb $db, Schema $schema ): array {
		$table = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};

		return [
			'links'      => new Wpdb_Link_Store( $db, $table( 'links' ), $table( 'link_ids' ) ),
			'sync_state' => new Sync_State_Store( $db, $table( 'sync_state' ) ),
			'tasks'      => new Wpdb_Task_Ledger( $db, $table( 'tasks' ), $table( 'task_items' ) ),
			'postings'   => new Order_Registry( $db, $table( 'postings' ) ),
			'documents'  => new Wpdb_Label_Store( $db, $table( 'tasks' ), $table( 'documents' ) ),
			'throttle'   => new Wpdb_Throttle_Store( $db, $table( 'throttle' ) ),
			'api_errors' => new Api_Error_Ring( $db, $table( 'api_errors' ) ),
		];
	}

	/**
	 * The steps, in order.
	 *
	 * @since 2.1.5
	 *
	 * @param Account_Queue_Cleaner $queue   Cancels the account's queued jobs.
	 * @param array<string,object>  $stores  Stores with `delete_account( int $account_id, int $limit ): int`, in the order they run.
	 * @param callable              $options `function ( int $account_id ): int` — deletes the options, transients and cached rows.
	 * @param Pinned_Offer_Eraser   $meta    Pinned articles.
	 * @param Wpdb_Event_Ledger     $events  Inbound notifications, deleted last among the tables.
	 * @return array<string,callable>
	 */
	public static function steps( Account_Queue_Cleaner $queue, array $stores, callable $options, Pinned_Offer_Eraser $meta, Wpdb_Event_Ledger $events ): array {
		$steps = [
			'queue' => static function ( int $account_id ) use ( $queue ): int {
				return $queue->cancel_for( $account_id );
			},
		];

		foreach ( $stores as $name => $store ) {
			$steps[ $name ] = [ $store, 'delete_account' ];
		}

		$steps['options']      = $options;
		$steps['product_meta'] = [ $meta, 'erase' ];
		$steps['events']       = [ $events, 'delete_account' ];

		return $steps;
	}

	/**
	 * Deletes the options, transients and cached rows an account owns. Runs once: it reports nothing removed.
	 *
	 * @param string             $options Option family of the plugin.
	 * @param Wpdb_Catalog_Cache $cache   Catalogue cache.
	 * @return callable `function ( int $account_id ): int`
	 */
	private static function forget_options( string $options, Wpdb_Catalog_Cache $cache ): callable {
		$progress  = new Link_Progress_Store( $options );
		$reports   = new Link_Report_Store( $options );
		$snapshots = new Cards_Snapshot_Store( $options );

		return static function ( int $account_id ) use ( $progress, $reports, $snapshots, $cache ): int {
			$progress->forget_account( $account_id );
			$reports->forget_account( $account_id );
			$snapshots->clear( $account_id );
			$cache->delete_keys( [ Warehouse_Options::key_for( $account_id ) ] );

			return 0;
		};
	}
}

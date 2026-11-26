<?php
/**
 * The marketplace flows, built from the adapter and hooked once.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Dictionary_Label_Lookup;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Data\Link_Progress_Store;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Article_Keyed_Lifecycle;
use Woodev\Framework\Marketplace\Flows\Card_Created_Seeder;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Importer;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Card_List_Source;
use Woodev\Framework\Marketplace\Flows\Deleted_Product_Releaser;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Import_Category;
use Woodev\Framework\Marketplace\Flows\Import_Mapping;
use Woodev\Framework\Marketplace\Flows\Label_Download;
use Woodev\Framework\Marketplace\Flows\Legacy_Order_Lines;
use Woodev\Framework\Marketplace\Flows\Label_Service;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renamer;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renames;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Flows\Shop_Sku_Source;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Intake;
use Woodev\Framework\Marketplace\Intake\Notifications_Controller;
use Woodev\Framework\Marketplace\Intake\Push_Rate_Limiter;
use Woodev\Framework\Marketplace\Intake\Sender_Network;
use Woodev\Framework\Marketplace\Intake\Tokenless_Seen;
use Woodev\Framework\Marketplace\Intake\Webhook_Switches;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Card_Event_Handler;
use Woodev\Framework\Marketplace\Jobs\Card_List_Event_Handler;
use Woodev\Framework\Marketplace\Jobs\Drain_Events_Runner;
use Woodev\Framework\Marketplace\Jobs\Drain_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Event_Handler_Registry;
use Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Flush_Sync_Runner;
use Woodev\Framework\Marketplace\Jobs\Import_Jobs;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\Label_Jobs;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Jobs;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;
use Woodev\Framework\Marketplace\Jobs\Posting_History_Cutoffs;
use Woodev\Framework\Marketplace\Jobs\Posting_Jobs;
use Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times;
use Woodev\Framework\Marketplace\Jobs\Price_Flush_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Recompute_Runner;
use Woodev\Framework\Marketplace\Jobs\Rename_Offer_Ids_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Refresh_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Rename_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Reconcile_Stock_Runner;
use Woodev\Framework\Marketplace\Jobs\Reconcile_Stock_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Runner;
use Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Mirror_Stock_Writer;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;
use Woodev\Framework\Marketplace\Sync\Shop_Price_Writer;
use Woodev\Framework\Marketplace\Sync\Stock_Flusher;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Readback;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\Woodev\\Framework\\Marketplace\\Flow_Wiring' ) ) :

	/**
	 * Builds every flow F9a and F9b moved out of the marketplace plugins — event intake and drain, stock and price sync, export,
	 * links, catalogue refresh, postings and labels — from the adapter's answers, and hooks each of them.
	 *
	 * **Registration is once per plugin id.** The hooks, the Action Scheduler job names and the options are all named after the
	 * plugin id ({@see Job_Names}), the intake route after the adapter id, so two marketplace plugins on one framework copy never
	 * share a hook or a route; a plugin id that is registered a second time changes nothing. Ids that differ only in dashes
	 * and underscores (`foo-bar`, `foo_bar`) are the same plugin here, as they are in {@see Job_Names}.
	 *
	 * **Nothing here talks to a marketplace.** Building the flows only reads the adapter's answers and makes objects (no query, no
	 * request); every runner is a hook callback that waits for Action Scheduler, a REST request or a WooCommerce event. The
	 * schedules are created on `action_scheduler_init`, by the runners themselves, and each runner asks the {@see Job_Gate}
	 * (licence, account switch, pause) before it calls out. A flow the adapter cannot serve is left out: no price pusher, no price
	 * flow; no push protocol, no intake route.
	 *
	 * **Extension points** (hooks named `woodev_{plugin id}_…`, snake case): `event_handlers` receives the
	 * {@see Event_Handler_Registry} to add the handler of each event type to; `card_created` fires `( int $link_id, int $account_id )`
	 * once an exported card exists on the marketplace.
	 *
	 * @internal Built by {@see Marketplace_Plugin::register_marketplace_flows()}.
	 *
	 * @since 2.1.0
	 */
	final class Flow_Wiring {
		/**
		 * Plugins whose flows are hooked, by this PHP process, keyed by {@see Job_Names::group()}.
		 *
		 * @var array<string,true>
		 */
		private static $registered = [];

		/**
		 * Plugin id, as `Woodev_Plugin::get_id()` gives it.
		 *
		 * @var string
		 */
		private $plugin_id;

		/**
		 * The adapter.
		 *
		 * @var Marketplace_Adapter
		 */
		private $adapter;

		/**
		 * Accounts.
		 *
		 * @var Wpdb_Account_Store
		 */
		private $accounts;

		/**
		 * Database.
		 *
		 * @var \wpdb
		 */
		private $db;

		/**
		 * Tables of the marketplace.
		 *
		 * @var Schema
		 */
		private $schema;

		/**
		 * The site's table prefix.
		 *
		 * @var string
		 */
		private $prefix;

		/**
		 * Licence gate.
		 *
		 * @var License_Gate
		 */
		private $license;

		/**
		 * Write gate of the plugin: `function ( Account $account ): bool|string` (only `true` allows), or null when every account may write.
		 *
		 * @var callable|null
		 */
		private $write_gate;

		/**
		 * Writes a line to the plugin's log: `function ( string $line ): void`.
		 *
		 * @var callable
		 */
		private $log_error;

		/**
		 * Writes a success line to the plugin's debug log, recorded only while logging is on: `function ( string $line ): void`.
		 *
		 * @var callable
		 */
		private $log_debug;

		/**
		 * Job and hook names of the plugin.
		 *
		 * @var Job_Names
		 */
		private $names;

		/**
		 * The shared pieces: gate, links, ring, classifier.
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
		 * Error ring.
		 *
		 * @var Api_Error_Ring
		 */
		private $ring;

		/**
		 * Export context builder, shared by export and price sync.
		 *
		 * @var Export_Context_Builder
		 */
		private $contexts;

		/**
		 * The linker every link path of the plugin writes through (the link job and the import).
		 *
		 * @var Card_Linker
		 */
		private $linker;

		/**
		 * The adapter's importer part, set when the plugin imports cards.
		 *
		 * @var Card_Importer|null
		 */
		private $importer;

		/**
		 * Scheduler of the import job, set when the plugin imports cards.
		 *
		 * @var Import_Scheduler|null
		 */
		private $imports;

		/**
		 * Creates the wiring of one plugin.
		 *
		 * @since 2.1.0
		 *
		 * @param string              $plugin_id Plugin id.
		 * @param Marketplace_Adapter $adapter   The plugin's adapter.
		 * @param Wpdb_Account_Store  $accounts  The plugin's accounts.
		 * @param \wpdb               $db        Database.
		 * @param string              $prefix    The site's table prefix (`$wpdb->prefix`).
		 * @param License_Gate        $license   Licence gate of the plugin.
		 * @param callable            $log_error `function ( string $line ): void`.
		 * @param callable            $log_debug `function ( string $line ): void`, for a success; recorded only while logging is on.
		 * @param callable|null       $write_gate `function ( Account $account ): bool|string`, the plugin's write gate (only `true` allows); null lets every account write.
		 */
		public function __construct( string $plugin_id, Marketplace_Adapter $adapter, Wpdb_Account_Store $accounts, \wpdb $db, string $prefix, License_Gate $license, callable $log_error, callable $log_debug, ?callable $write_gate = null ) {
			$this->plugin_id = $plugin_id;
			$this->adapter   = $adapter;
			$this->accounts  = $accounts;
			$this->db        = $db;
			$this->prefix    = $prefix;
			$this->license   = $license;
			$this->log_error = $log_error;
			$this->log_debug = $log_debug;
			$this->write_gate = $write_gate;
		}

		/**
		 * Builds and hooks every flow of the plugin, unless that was already done for this plugin id.
		 *
		 * @since 2.1.0
		 *
		 * @return bool True when the flows were hooked now, false when they already were.
		 */
		public function register(): bool {
			// Keyed by what Job_Names makes of the id: `foo-bar` and `foo_bar` name the same hooks and the same group, so they are one.
			$names = new Job_Names( $this->plugin_id );

			if ( isset( self::$registered[ $names->group() ] ) ) {
				return false;
			}

			self::$registered[ $names->group() ] = true;

			$this->schema     = new Schema( $this->adapter->id() );
			$this->names      = $names;
			$classifier       = $this->adapter->error_classifier();
			$this->gate       = new Job_Gate( $this->accounts, $this->license, Account_Pause::over( $this->accounts, $classifier ), $this->write_gate );
			$this->links      = new Wpdb_Link_Store( $this->db, $this->table( 'links' ), $this->table( 'link_ids' ) );
			$this->ring       = new Api_Error_Ring( $this->db, $this->table( 'api_errors' ) );
			$this->contexts   = $this->export_context_builder();
			$sync_state_store = new Sync_State_Store( $this->db, $this->table( 'sync_state' ) );
			$this->linker     = $this->card_linker( $sync_state_store );

			$this->stock_and_price( $sync_state_store );
			$this->export( $sync_state_store );
			$this->links_and_catalog();
			$this->import();
			$this->offer_id_renames();
			$this->postings();
			$this->labels();
			$this->intake();

			return true;
		}

		/**
		 * Forgets which plugin ids were hooked. For tests: a process never needs it.
		 *
		 * @internal
		 *
		 * @since 2.1.0
		 *
		 * @return void
		 */
		public static function forget(): void {
			self::$registered = [];
		}

		/**
		 * The linker of the plugin: it switches synchronisation on at linking when the account's setting says so, and records the first
		 * send through intent recorders of its own (they hook nothing; the registered ones are built in {@see self::stock_and_price()}).
		 *
		 * @param Sync_State_Store $store Pending side of `sync_state`.
		 * @return Card_Linker
		 */
		private function card_linker( Sync_State_Store $store ): Card_Linker {
			$adapter = $this->adapter;
			$queue   = new Sync_State_Queue( $this->db, $this->table( 'sync_state' ), $this->table( 'accounts' ), $adapter->stock_cadence() );
			$stock   = new Stock_Intents( [ $this->accounts, 'get' ], $this->links, $store, $queue, $adapter->stock_policy() );
			$price   = null === $adapter->price_pusher() ? null : new Price_Intents( $this->accounts, $adapter->id(), $this->links, $this->contexts, $store, $this->log_error );

			return Card_Linker::syncing( $this->links, Effective_Settings::for_adapter( $adapter->id() ), [ $this->accounts, 'get' ], $stock, $price );
		}

		/**
		 * Stock flush and the price half in one sender, the stock and price recorders, the price recompute and refresh jobs.
		 *
		 * @param Sync_State_Store $store Pending side of `sync_state`.
		 * @return void
		 */
		private function stock_and_price( Sync_State_Store $store ): void {
			$adapter     = $this->adapter;
			$classifier  = $adapter->error_classifier();
			$limits      = $adapter->sync_limits();
			$queue       = new Sync_State_Queue( $this->db, $this->table( 'sync_state' ), $this->table( 'accounts' ), $adapter->stock_cadence() );
			$releaser    = $this->deleted_product_releaser( $queue );
			$flusher     = new Stock_Flusher( $queue, $store, $this->links, $adapter->stock_pusher(), $adapter->stock_rules(), $limits, $classifier, $this->gate->pause(), [ $releaser, 'release_settled' ] );
			$pusher      = $adapter->price_pusher();
			$price_half  = null;
			$price_queue = null;
			$scheduler   = new Price_Scheduler( $this->names );

			( new Stock_Intents( [ $this->accounts, 'get' ], $this->links, $store, $queue, $adapter->stock_policy() ) )->register();

			if ( null !== $pusher ) {
				$price_queue = new Price_Sync_Queue( $this->db, $this->table( 'sync_state' ), $this->table( 'links' ), $this->table( 'accounts' ), $adapter->price_cadence() );
				$price_half  = [ new Price_Flush_Runner( $this->gate, $this->accounts, $price_queue, $this->links, $pusher, $limits, $classifier, $this->log_error ), 'flush_under_claim' ];

				$intents     = new Price_Intents( $this->accounts, $adapter->id(), $this->links, $this->contexts, $store, $this->log_error );

				$intents->register();
				( new Price_Recompute_Runner( $this->gate, $intents, $scheduler, $this->log_error ) )->register();
				$this->recompute_when_a_price_rule_changes( $scheduler );
			}

			$reader = $adapter->price_reader();

			if ( null !== $reader ) {
				// A marketplace that sends no prices has no price queue: the pull has nothing queued to settle there.
				$refresher = new Price_Refresher( $reader, $this->links, $this->contexts, $price_queue );

				( new Price_Refresh_Runner( $this->gate, $refresher, $scheduler, $classifier, $this->log_error, $this->ring ) )->register();
			}

			( new Flush_Sync_Runner( $this->names, $this->gate, $flusher, $this->accounts, $adapter->id(), $limits, $this->log_error, $price_half ) )->register();

			$this->reconcile_stock( $store, $queue );
		}

		/**
		 * The daily stock reconcile of the accounts that sync stock.
		 *
		 * **Extension point** `woodev_{plugin id}_stock_readback_applied( int $account_id, Product_Link $link, int $read_started_at, int $warehouse_id )`:
		 * fires in «marketplace is master» mode after a link's shop stock was set from the marketplace, or found equal to it.
		 * `$read_started_at` is the Unix time before the read request, so a marketplace notification older than it is not newer
		 * than what the reconcile applied; `$warehouse_id` is the warehouse the link was read in (resolved by
		 * {@see \Woodev\Framework\Marketplace\Sync\Stock_Warehouse::for_link()}, not the link's nullable override). A plugin that keeps
		 * a freshness mark for its stock notifications advances it here.
		 *
		 * @param Sync_State_Store $store Pending side of `sync_state`.
		 * @param Sync_State_Queue $queue Flusher side of `sync_state`.
		 * @return void
		 */
		private function reconcile_stock( Sync_State_Store $store, Sync_State_Queue $queue ): void {
			$adapter  = $this->adapter;
			$hook     = $this->names->hook( 'stock_readback_applied' );
			$log      = $this->log_error;
			$readback = new Stock_Readback(
				$this->links,
				$adapter->stock_pusher(),
				$store,
				$queue,
				new Mirror_Stock_Writer(),
				$adapter->stock_policy(),
				static function ( Account $account, Product_Link $link, int $started, int $warehouse ) use ( $hook, $log ): void {
					try {
						do_action( $hook, $account->id(), $link, $started, $warehouse ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Job_Names builds `woodev_{plugin id}_…` in snake case.
					} catch ( \Throwable $e ) {
						call_user_func( $log, 'stock_readback_applied listener failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // A listener's failure must not stop the reconcile.
					}
				}
			);

			( new Reconcile_Stock_Runner( $this->gate, $this->accounts, $adapter->id(), $this->links, $readback, new Reconcile_Stock_Scheduler( $this->names ), $adapter->error_classifier(), $this->ring, $this->log_error ) )->register();
		}

		/**
		 * A changed price rule recomputes prices: a profile's rule re-works every enabled account, an account's own
		 * rule (markup, price direction — saved by the settings screen) re-works that account.
		 *
		 * @param Price_Scheduler $scheduler Price scheduler.
		 * @return void
		 */
		private function recompute_when_a_price_rule_changes( Price_Scheduler $scheduler ): void {
			$adapter_id = $this->adapter->id();
			$accounts   = $this->accounts;

			add_action(
				Wpdb_Profile_Store::price_changed_action( $adapter_id ),
				static function () use ( $accounts, $scheduler, $adapter_id ): void {
					foreach ( $accounts->all( $adapter_id, true ) as $account ) {
						$scheduler->queue_recompute( $account->id() );
					}
				}
			);

			add_action(
				$this->names->hook( 'account_price_rules_changed' ),
				static function ( $account_id ) use ( $scheduler ): void {
					$scheduler->queue_recompute( (int) $account_id );
				}
			);
		}

		/**
		 * The export batch, the task poll and the sweep that finds a stranded task.
		 *
		 * @param Sync_State_Store $store Pending side of `sync_state`, which a created card is seeded into.
		 * @return void
		 */
		private function export( Sync_State_Store $store ): void {
			if ( ! $this->adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
				return;
			}

			$adapter    = $this->adapter;
			$classifier = $adapter->error_classifier();
			$ledger     = new Wpdb_Task_Ledger( $this->db, $this->table( 'tasks' ), $this->table( 'task_items' ) );
			$scheduler  = new Export_Scheduler( $this->names );
			$seeder     = new Card_Created_Seeder( $store, $this->contexts, $adapter->stock_policy() );
			$names      = $this->names;
			$lifecycle  = $adapter->card_lifecycle();
			$by_article = $lifecycle instanceof Article_Keyed_Lifecycle ? $lifecycle : null;

			( new Export_Batch_Runner( $this->gate, $this->links, $ledger, $this->contexts, new Export_Validator( $adapter->export_rules() ), $adapter->export_gateway(), $scheduler, $classifier, $this->ring, $this->log_error, $by_article ) )->register();
			( new Poll_Task_Runner(
				$this->gate,
				$this->links,
				$ledger,
				$scheduler,
				$lifecycle,
				$classifier,
				static function ( Account $account, Link_Record $link ) use ( $seeder ): void {
					$seeder->seed( $account, $link );
				},
				static function ( int $link_id, int $account_id ) use ( $names ): void {
					do_action( $names->hook( 'card_created' ), $link_id, $account_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Job_Names builds `woodev_{plugin id}_…` in snake case.
				},
				$this->ring,
				$this->log_error
			) )->register();

			add_action( 'action_scheduler_init', [ $scheduler, 'ensure_sweep' ] );
		}

		/**
		 * A product deleted for good lets go of its cards: the link rows go as «Снять связь» ends them, so the card can be imported or linked
		 * again. A created card is archived first; the stock sender hands the link back when that settles.
		 *
		 * @param Sync_State_Queue $queue Flusher side of `sync_state`.
		 * @return Deleted_Product_Releaser
		 */
		private function deleted_product_releaser( Sync_State_Queue $queue ): Deleted_Product_Releaser {
			$ledger   = new Wpdb_Task_Ledger( $this->db, $this->table( 'tasks' ), $this->table( 'task_items' ) );
			$releaser = new Deleted_Product_Releaser( new Link_Detacher( $this->links, $queue, $ledger, $this->accounts ), $this->log_error );

			$releaser->register();

			return $releaser;
		}

		/**
		 * Linking existing cards, the stored cards list (its refresh, the daily run), and the weekly refresh of the catalogue cache.
		 *
		 * @return void
		 */
		private function links_and_catalog(): void {
			if ( ! $this->adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
				return;
			}

			$adapter    = $this->adapter;
			$classifier = $adapter->error_classifier();
			$options    = Uninstaller::option_prefix( $this->plugin_id );
			$shop       = new Shop_Sku_Source( $this->db );
			$refresh    = new Refresh_Catalog_Scheduler( $this->names );
			$scheduler  = new Link_Scheduler( $this->names );
			$accounts   = $this->accounts;
			$adapter_id = $adapter->id();

			( new Link_Jobs(
				$this->gate,
				$this->links,
				$this->linker,
				[ $shop, 'products' ],
				new Link_Report_Store( $options ),
				$this->card_store(),
				$scheduler,
				$adapter->link_resolver(),
				$adapter->article_policy(),
				$classifier,
				$this->ring,
				$this->log_error,
				new Link_Progress_Store( $options ),
				null,
				static function () use ( $accounts, $adapter_id ): array {
					return array_map(
						static function ( Account $account ): int {
							return $account->id();
						},
						$accounts->all( $adapter_id, true )
					);
				}
			) )->register();

			( new Refresh_Catalog_Runner( $this->accounts, $adapter->id(), $this->gate, $adapter->catalog_refresher(), $refresh, $classifier, $this->ring, $this->log_error ) )->register();

			add_action( 'action_scheduler_init', [ $refresh, 'ensure_weekly' ] );
			add_action( 'action_scheduler_init', [ $scheduler, 'ensure_daily' ] );
		}

		/**
		 * The stored list of the cards the «Карточки» screen shows.
		 *
		 * @return Wpdb_Card_Store
		 */
		private function card_store(): Wpdb_Card_Store {
			return new Wpdb_Card_Store( $this->db, $this->table( 'cards' ), $this->table( 'links' ), Uninstaller::option_prefix( $this->plugin_id ) );
		}

		/**
		 * Wraps the handler of every card event type the adapter's list part names, so a created or changed card also refreshes its
		 * row of the stored list. Only for an adapter whose resolver reads single cards ({@see Card_List_Source}).
		 *
		 * @param Event_Handler_Registry $handlers The registry the drain reads.
		 * @return void
		 */
		private function register_card_list_handler( Event_Handler_Registry $handlers ): void {
			if ( ! $this->adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
				return;
			}

			$source = $this->adapter->link_resolver();

			if ( ! $source instanceof Card_List_Source ) {
				return;
			}

			$cards     = $this->card_store();
			$scheduler = new Link_Scheduler( $this->names );

			foreach ( $source->card_event_types() as $type ) {
				$handlers->register( (string) $type, new Card_List_Event_Handler( $source, $cards, $scheduler, $handlers->for_type( (string) $type ) ) );
			}
		}

		/**
		 * The job that imports cards as shop products. Wired only for an adapter that is a {@see Card_Importer} and reports
		 * {@see Capability::IMPORT_PRODUCTS}; any other adapter gets no job, no handler and no hook.
		 *
		 * @return void
		 */
		private function import(): void {
			$adapter = $this->adapter;

			if ( ! $adapter instanceof Card_Importer || ! $adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
				return;
			}

			$notes     = new Link_Report_Store( Uninstaller::option_prefix( $this->plugin_id ) );
			$scheduler = new Import_Scheduler( $this->names );
			$mappings   = new Wpdb_Mapping_Store( $this->db, $this->table( 'mappings' ), $this->table( 'mapping_terms' ) );
			$categories = new Import_Category( $mappings );
			$writer     = new Product_Writer(
				$categories,
				new Wpdb_Profile_Store( $this->db, $this->table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( $adapter->id() ) ),
				new Shop_Price_Writer( $this->contexts )
			);
			$mapping    = new Import_Mapping( $mappings, $categories, new Category_Finder( $adapter->catalog_schema() ), $adapter->settings_descriptor()->marketplace_name(), $adapter->export_facts() instanceof Dictionary_Label_Lookup );
			$import     = new Card_Import( $this->links, $this->linker, $notes, Effective_Settings::for_adapter( $adapter->id() ), new Group_Assembler( $this->links ), [ $writer, 'start' ], [ $writer, 'complete' ], $mapping );

			( new Import_Jobs( $this->gate, $adapter, $import, $notes, $scheduler, $adapter->error_classifier(), $this->ring, $this->log_error ) )->register();
			( new Legacy_Order_Lines( $adapter->id() ) )->register();

			$this->importer = $adapter;
			$this->imports  = $scheduler;
		}

		/**
		 * The job that sends an article the merchant changed after the export. Wired only for an adapter that is an {@see Offer_Id_Renamer} and reports
		 * {@see Capability::RENAME_SKU}; any other adapter gets no job and its articles stay frozen once sent.
		 *
		 * @return void
		 */
		private function offer_id_renames(): void {
			$adapter = $this->adapter;

			if ( ! $adapter instanceof Offer_Id_Renamer || ! $adapter->supports( Capability::RENAME_SKU ) ) {
				return;
			}

			$renames = Offer_Id_Renames::for_plugin( $this->links, $this->names, new Pinned_Offer_Id( $adapter->id(), $adapter->article_policy() ), $this->log_error, $this->log_debug );

			( new Rename_Offer_Ids_Runner( $this->gate, $renames, $adapter, new Rename_Scheduler( $this->names ), $adapter->error_classifier(), $this->ring, $this->log_error ) )->register();
		}

		/**
		 * The handler of the «card created or changed» events, registered before the plugin's own filter runs so that the plugin
		 * can still replace it.
		 *
		 * @param Event_Handler_Registry $handlers The registry the drain reads.
		 * @return void
		 */
		private function register_card_handler( Event_Handler_Registry $handlers ): void {
			if ( null === $this->importer || null === $this->imports ) {
				return;
			}

			$handler = new Card_Event_Handler(
				$this->importer,
				$this->accounts,
				$this->links,
				Effective_Settings::for_adapter( $this->adapter->id() ),
				$this->imports,
				null !== $this->adapter->price_reader() ? new Price_Scheduler( $this->names ) : null
			);

			foreach ( $this->importer->import_event_types() as $type ) {
				$handlers->register( (string) $type, $handler );
			}
		}

		/**
		 * The posting refresh and the recurring poll of postings.
		 *
		 * @return void
		 */
		private function postings(): void {
			$handler = $this->adapter->posting_handler();

			if ( null === $handler ) {
				return;
			}

			( new Posting_Jobs( $this->gate, $this->accounts, $handler, $this->adapter->error_classifier(), $this->names, $this->adapter->id(), Posting_Jobs::option_key( $this->plugin_id ), new Posting_Poll_Times( $this->plugin_id ), new Posting_History_Cutoffs( $this->plugin_id ) ) )->register();
		}

		/**
		 * The label poll, the daily prune of old documents and the download link.
		 *
		 * @return void
		 */
		private function labels(): void {
			$provider = $this->adapter->label_provider();

			if ( null === $provider || ! $this->adapter->supports( Capability::GET_LABEL ) ) {
				return;
			}

			$classifier = $this->adapter->error_classifier();
			$store      = new Wpdb_Label_Store( $this->db, $this->table( 'tasks' ), $this->table( 'documents' ) );
			$service    = new Label_Service( $provider, $store, $this->license, $classifier, $this->names );
			$orders     = new Order_Registry( $this->db, $this->table( 'postings' ) );

			( new Label_Jobs( $this->gate, $service, $store, $classifier, $this->names ) )->register();
			( new Label_Download(
				$store,
				$this->accounts,
				static function ( int $account_id, string $external_key ) use ( $orders ): ?int {
					$row = $orders->find( $account_id, $external_key );

					return null === $row ? null : $row['wc_order_id'];
				},
				$this->adapter->order_meta(),
				$this->license,
				$this->names,
				$this->adapter->id()
			) )->register();
		}

		/**
		 * The route the marketplace pushes notifications to, and the drain that processes them.
		 *
		 * @return void
		 */
		private function intake(): void {
			$decoder  = $this->adapter->inbound_decoder();
			$protocol = $this->adapter->push_protocol();

			if ( null === $decoder || null === $protocol || ! $this->adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
				return;
			}

			$events    = new Wpdb_Event_Ledger( $this->db, $this->table( 'events' ) );
			$reader    = new Notification_Account_Reader( $this->db, $this->table( 'accounts' ), Effective_Settings::for_adapter( $this->adapter->id() ) );
			$scheduler = new Drain_Scheduler( $this->names );
			$handlers  = new Event_Handler_Registry();

			$this->register_card_handler( $handlers );
			$this->register_card_list_handler( $handlers );

			do_action( $this->names->hook( 'event_handlers' ), $handlers ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Job_Names builds `woodev_{plugin id}_…` in snake case.

			( new Drain_Events_Runner( $events, $reader, $handlers, $scheduler, null, $this->license ) )->register();
			$network  = new Sender_Network( $this->adapter->id(), $protocol );
			$switches = new Webhook_Switches( $protocol->webhook_types(), Uninstaller::option_prefix( $this->plugin_id ) );

			( new Notifications_Controller( $this->adapter->id(), new Notification_Intake( $decoder, $protocol, $network, $switches, $events, $reader, $scheduler, new Push_Rate_Limiter(), $this->log_error, new Tokenless_Seen( $this->plugin_id ) ) ) )->register();
		}

		/**
		 * Builds the export context, which export and price sync both read.
		 *
		 * @return Export_Context_Builder
		 */
		private function export_context_builder(): Export_Context_Builder {
			$adapter = $this->adapter;

			return new Export_Context_Builder(
				new Link_Context_Resolver( $this->links, new Wpdb_Mapping_Store( $this->db, $this->table( 'mappings' ), $this->table( 'mapping_terms' ) ) ),
				new Wpdb_Profile_Store( $this->db, $this->table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( $adapter->id() ) ),
				$this->links,
				$adapter->export_facts(),
				$adapter->article_policy(),
				new Pinned_Offer_Id( $adapter->id(), $adapter->article_policy() ),
				$adapter->price_source(),
				null,
				null,
				new Wpdb_Task_Ledger( $this->db, $this->table( 'tasks' ), $this->table( 'task_items' ) )
			);
		}

		/**
		 * Full name of one of the marketplace's tables.
		 *
		 * @param string $table Name without any prefix, e.g. `links`.
		 * @return string
		 */
		private function table( string $table ): string {
			return $this->schema->table_name( $table, $this->prefix );
		}
	}

endif;

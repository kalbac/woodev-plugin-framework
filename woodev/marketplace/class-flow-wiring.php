<?php
/**
 * The marketplace flows, built from the adapter and hooked once.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Link_Progress_Store;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Card_Created_Seeder;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Label_Download;
use Woodev\Framework\Marketplace\Flows\Label_Service;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Shop_Sku_Source;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Intake;
use Woodev\Framework\Marketplace\Intake\Notifications_Controller;
use Woodev\Framework\Marketplace\Intake\Push_Rate_Limiter;
use Woodev\Framework\Marketplace\Intake\Sender_Network;
use Woodev\Framework\Marketplace\Intake\Tokenless_Seen;
use Woodev\Framework\Marketplace\Intake\Webhook_Switches;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Drain_Events_Runner;
use Woodev\Framework\Marketplace\Jobs\Drain_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Event_Handler_Registry;
use Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Flush_Sync_Runner;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\Label_Jobs;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Jobs;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;
use Woodev\Framework\Marketplace\Jobs\Posting_Jobs;
use Woodev\Framework\Marketplace\Jobs\Price_Flush_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Recompute_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Refresh_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Runner;
use Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;
use Woodev\Framework\Marketplace\Sync\Stock_Flusher;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;

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
		 * Write gate of the plugin: `function ( Account $account ): bool`, or null when every account may write.
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
		 * @param callable|null       $write_gate `function ( Account $account ): bool`, the plugin's write gate; null lets every account write.
		 */
		public function __construct( string $plugin_id, Marketplace_Adapter $adapter, Wpdb_Account_Store $accounts, \wpdb $db, string $prefix, License_Gate $license, callable $log_error, ?callable $write_gate = null ) {
			$this->plugin_id = $plugin_id;
			$this->adapter   = $adapter;
			$this->accounts  = $accounts;
			$this->db        = $db;
			$this->prefix    = $prefix;
			$this->license   = $license;
			$this->log_error = $log_error;
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

			$this->stock_and_price( $sync_state_store );
			$this->export( $sync_state_store );
			$this->links_and_catalog();
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
		 * Stock flush and the price half in one sender, the stock and price recorders, the price recompute and refresh jobs.
		 *
		 * @param Sync_State_Store $store Pending side of `sync_state`.
		 * @return void
		 */
		private function stock_and_price( Sync_State_Store $store ): void {
			$adapter    = $this->adapter;
			$classifier = $adapter->error_classifier();
			$limits     = $adapter->sync_limits();
			$queue      = new Sync_State_Queue( $this->db, $this->table( 'sync_state' ), $this->table( 'accounts' ), $adapter->stock_cadence() );
			$flusher    = new Stock_Flusher( $queue, $store, $this->links, $adapter->stock_pusher(), $adapter->stock_rules(), $limits, $classifier, $this->gate->pause() );
			$pusher     = $adapter->price_pusher();
			$price_half = null;
			$scheduler  = new Price_Scheduler( $this->names );

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
				$refresher = new Price_Refresher( $reader, $this->links, $this->contexts );

				( new Price_Refresh_Runner( $this->gate, $refresher, $scheduler, $classifier, $this->log_error ) )->register();
			}

			( new Flush_Sync_Runner( $this->names, $this->gate, $flusher, $this->accounts, $adapter->id(), $limits, $this->log_error, $price_half ) )->register();
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

			( new Export_Batch_Runner( $this->gate, $this->links, $ledger, $this->contexts, new Export_Validator( $adapter->export_rules() ), $adapter->export_gateway(), $scheduler, $classifier, $this->ring, $this->log_error ) )->register();
			( new Poll_Task_Runner(
				$this->gate,
				$this->links,
				$ledger,
				$scheduler,
				$adapter->card_lifecycle(),
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
		 * Linking existing cards, and the weekly refresh of the catalogue cache.
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

			( new Link_Jobs(
				$this->gate,
				$this->links,
				new Card_Linker( $this->links ),
				[ $shop, 'products' ],
				new Link_Report_Store( $options ),
				new Cards_Snapshot_Store( $options ),
				new Link_Scheduler( $this->names ),
				$adapter->link_resolver(),
				$adapter->article_policy(),
				$classifier,
				$this->ring,
				$this->log_error,
				new Link_Progress_Store( $options )
			) )->register();

			( new Refresh_Catalog_Runner( $this->accounts, $adapter->id(), $this->gate, $adapter->catalog_refresher(), $refresh, $classifier, $this->ring, $this->log_error ) )->register();

			add_action( 'action_scheduler_init', [ $refresh, 'ensure_weekly' ] );
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

			( new Posting_Jobs( $this->gate, $this->accounts, $handler, $this->adapter->error_classifier(), $this->names, $this->adapter->id(), Posting_Jobs::option_key( $this->plugin_id ) ) )->register();
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
			$reader    = new Notification_Account_Reader( $this->db, $this->table( 'accounts' ) );
			$scheduler = new Drain_Scheduler( $this->names );
			$handlers  = new Event_Handler_Registry();

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

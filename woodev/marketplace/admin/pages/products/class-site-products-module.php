<?php
/**
 * Wires the «Товары сайта» table for one marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Card_Importer;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Grouping;
use Woodev\Framework\Marketplace\Flows\Export_Service;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Flows\Shop_Sku_Source;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Bulk_Import_Feeder;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lock;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Workers;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Price_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the services of the «Товары сайта» table from the plugin's adapter and registers the provider with the records engine. Nothing
 * here names a marketplace: every marketplace fact comes from the {@see Marketplace_Adapter}.
 *
 * The providers are built when the REST API starts, not on every request, and only when the plugin still has the `products` page.
 * A plugin whose adapter has no catalogue ({@see Capability::has_catalog()}) gets the table without «Экспортировать» and
 * «Связать по артикулу», and no «Карточки на маркетплейсе» table: the page declares that one only for an adapter that can list the cards.
 *
 * @since 2.1.4
 */
final class Site_Products_Module {
	/**
	 * Slug of the page this module serves.
	 */
	public const PAGE = 'products';

	/**
	 * Hooks the registration for a plugin.
	 *
	 * @since 2.1.4
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): void {
		add_action(
			'rest_api_init',
			static function () use ( $plugin, $accounts, $db ): void {
				self::register_provider( $plugin, $accounts, $db );
				self::register_remote_cards( $plugin, $accounts, $db );
			},
			5 // Before the REST registrar walks its controllers (priority 10), so the routes of a table registered now exist.
		);

		self::register_bulk_runs( $plugin, $accounts, $db );
	}

	/**
	 * Hooks the tick of the bulk runs and their recovery sweep (#1795). Action Scheduler runs the tick outside the REST API, so this is
	 * hooked on every request; the runner and the services under it are built when a tick actually comes.
	 *
	 * @since 2.1.8
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return void
	 */
	private static function register_bulk_runs( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): void {
		$scheduler = new Bulk_Run_Scheduler( new Job_Names( $plugin->get_id() ) );

		add_action(
			$scheduler->hook(),
			static function ( $account_id, $run_id = '' ) use ( $plugin, $accounts, $db ): void {
				self::bulk_runner( $plugin, $accounts, $db )->tick( $account_id, $run_id );
			},
			10,
			2
		);
		add_action( 'action_scheduler_init', [ $scheduler, 'ensure_sweep' ] );
	}

	/**
	 * Builds the runner of the bulk runs. The named lock is taken on the same database object the tick writes through: a lock on one
	 * connection would fence nothing on another.
	 *
	 * @since 2.1.8
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return Bulk_Run_Runner
	 */
	public static function bulk_runner( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): Bulk_Run_Runner {
		$adapter = $plugin->get_marketplace_adapter();
		$schema  = new Schema( $adapter->id() );
		$table   = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};
		$links   = new Wpdb_Link_Store( $db, $table( 'links' ), $table( 'link_ids' ) );
		$ledger  = new Wpdb_Task_Ledger( $db, $table( 'tasks' ), $table( 'task_items' ) );
		$names   = new Job_Names( $plugin->get_id() );
		$runs    = new Bulk_Run_Store( $db, $table( 'bulk_runs' ) );
		$worker  = new Site_Products_Run_Worker(
			new Site_Products_Reader( $db, $table( 'links' ), $table( 'sync_state' ) ),
			self::bulk( $plugin, $adapter, $accounts, $db, $links, $ledger, $table ),
			License_Gate::for_plugin( $plugin ),
			Account_Pause::over( $accounts, $adapter->error_classifier() ),
			$adapter->export_facts() instanceof Export_Grouping
		);

		return new Bulk_Run_Runner(
			$runs,
			new Bulk_Run_Lock( $db, $table( 'bulk_runs' ) ),
			new Bulk_Run_Scheduler( $names ),
			$accounts,
			$adapter->id(),
			self::run_workers( $plugin, $adapter, $db, $runs, $worker ),
			static function ( string $line ) use ( $plugin ): void {
				$plugin->log_error( $line );
			}
		);
	}

	/**
	 * The worker of every run: the products worker, and for an adapter that can import cards the feeder that serves «Импортировать все
	 * по фильтру» (#1796).
	 *
	 * @param Marketplace_Plugin       $plugin  The marketplace plugin.
	 * @param Marketplace_Adapter      $adapter Adapter.
	 * @param \wpdb                    $db      Database.
	 * @param Bulk_Run_Store           $runs    Runs.
	 * @param Site_Products_Run_Worker $products The worker of the «Товары сайта» actions.
	 * @return \Woodev\Framework\Marketplace\Jobs\Bulk_Run_Worker
	 */
	private static function run_workers( Marketplace_Plugin $plugin, Marketplace_Adapter $adapter, \wpdb $db, Bulk_Run_Store $runs, Site_Products_Run_Worker $products ) {
		if ( ! $adapter instanceof Card_Importer || ! $adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
			return $products;
		}

		$matching = new Remote_Cards_Matching( self::card_store( $plugin, $adapter, $db ) );
		$feeder   = new Bulk_Import_Feeder(
			$runs,
			new Import_Scheduler( new Job_Names( $plugin->get_id() ) ),
			[ $matching, 'entries' ],
			Bulk_Import_Feeder::marked_in_database( $db, Product_Writer::marker_key( $adapter->id() ) )
		);

		return new Bulk_Run_Workers( $products, [ Remote_Cards_Definition::IMPORT_SELECTED => $feeder ] );
	}

	/**
	 * Registers the provider with the records engine; does nothing when the plugin has no `products` page or its id cannot be a route segment.
	 *
	 * @since 2.1.4
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return bool Whether a provider was registered.
	 */
	public static function register_provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): bool {
		$capability = self::capability_for( $plugin, null );

		return null !== $capability && Records_Registry::instance()->register( $plugin->get_id(), self::provider( $plugin, $accounts, $db ), $capability );
	}

	/**
	 * Registers the «Карточки на маркетплейсе» provider; does nothing when the plugin's `products` page does not declare that table
	 * (its adapter cannot list the cards, or the plugin dropped the table) or its id cannot be a route segment.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return bool Whether a provider was registered.
	 */
	public static function register_remote_cards( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): bool {
		$capability = self::capability_for( $plugin, Remote_Cards_Provider::TABLE );

		return null !== $capability && Records_Registry::instance()->register( $plugin->get_id(), self::remote_cards_provider( $plugin, $accounts, $db ), $capability );
	}

	/**
	 * The capability a table of the `products` page is registered with, or null when the table is not to be registered.
	 *
	 * @param Marketplace_Plugin $plugin The marketplace plugin.
	 * @param string|null        $table  A table the page must declare besides `products`; null for the products table itself.
	 * @return string|null
	 */
	private static function capability_for( Marketplace_Plugin $plugin, ?string $table ): ?string {
		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		if ( null === $menu || null === $page || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $plugin->get_id() ) ) {
			return null;
		}

		if ( null !== $table && ! in_array( $table, $page->get_tables(), true ) ) {
			return null;
		}

		return $page->get_capability( $menu->get_capability() );
	}

	/**
	 * Builds the provider.
	 *
	 * @since 2.1.4
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return Site_Products_Provider
	 */
	public static function provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): Site_Products_Provider {
		$adapter  = $plugin->get_marketplace_adapter();
		$schema   = new Schema( $adapter->id() );
		$table    = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};
		$links    = new Wpdb_Link_Store( $db, $table( 'links' ), $table( 'link_ids' ) );
		$reader   = new Site_Products_Reader( $db, $table( 'links' ), $table( 'sync_state' ) );
		$ledger   = new Wpdb_Task_Ledger( $db, $table( 'tasks' ), $table( 'task_items' ) );
		$license  = License_Gate::for_plugin( $plugin );
		$bulk     = self::bulk( $plugin, $adapter, $accounts, $db, $links, $ledger, $table );
		$withheld = self::withheld_actions( $adapter );
		$runner   = self::bulk_runner( $plugin, $accounts, $db );
		$runs     = new Bulk_Run_Store( $db, $table( 'bulk_runs' ) );

		return new Site_Products_Provider(
			$accounts,
			$reader,
			new Site_Products_Selection( $reader ),
			new Site_Products_Activity( $ledger, $db, $table( 'sync_state' ), $table( 'accounts' ), null, $runs, [ $runner, 'recover' ] ),
			$bulk,
			new Warehouse_Options( new Wpdb_Catalog_Cache( $db, $table( 'catalog_cache' ) ) ),
			$license,
			[
				'adapter_id'  => $adapter->id(),
				'marketplace' => $adapter->settings_descriptor()->marketplace_name(),
				'withheld'    => $withheld,
			],
			$runner,
			$runs
		);
	}

	/**
	 * The bulk actions an adapter cannot serve: the table withholds them from its toolbar, and the variation panel's group of the
	 * product edit screen leaves them out of its select.
	 *
	 * An adapter without a catalogue has no export and no linking; one that reads no prices has nothing to pull; one that sends
	 * none keeps no own prices to reset.
	 *
	 * @since 2.1.13
	 *
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @return string[] Action ids.
	 */
	public static function withheld_actions( Marketplace_Adapter $adapter ): array {
		$withheld = Capability::has_catalog( $adapter ) ? [] : [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::LINK_BY_ARTICLE ];
		$withheld = null === $adapter->price_reader() ? array_merge( $withheld, [ Site_Products_Bulk::PULL_PRICES ] ) : $withheld;

		return null === $adapter->price_pusher() ? array_merge( $withheld, [ Site_Products_Bulk::RESET_PRICE ] ) : $withheld;
	}

	/**
	 * Builds the bulk actions of the table for a caller that has no table of its own (the variation panel of the product edit screen).
	 * The same services, so an action there plans, queues and counts exactly as it does in the table.
	 *
	 * @since 2.1.13
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return Site_Products_Bulk
	 */
	public static function bulk_for( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): Site_Products_Bulk {
		$adapter = $plugin->get_marketplace_adapter();
		$schema  = new Schema( $adapter->id() );
		$table   = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};

		return self::bulk(
			$plugin,
			$adapter,
			$accounts,
			$db,
			new Wpdb_Link_Store( $db, $table( 'links' ), $table( 'link_ids' ) ),
			new Wpdb_Task_Ledger( $db, $table( 'tasks' ), $table( 'task_items' ) ),
			$table
		);
	}

	/**
	 * Builds the «Карточки на маркетплейсе» provider, with the same services the products table uses for «Связать по артикулу». The
	 * table offers «Импортировать» only when the adapter implements {@see Card_Importer} and reports {@see Capability::IMPORT_PRODUCTS}.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return Remote_Cards_Provider
	 */
	public static function remote_cards_provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): Remote_Cards_Provider {
		$adapter  = $plugin->get_marketplace_adapter();
		$schema   = new Schema( $adapter->id() );
		$table    = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};
		$links    = new Wpdb_Link_Store( $db, $table( 'links' ), $table( 'link_ids' ) );
		$ledger   = new Wpdb_Task_Ledger( $db, $table( 'tasks' ), $table( 'task_items' ) );
		$products = new Remote_Cards_Products( Remote_Cards_Products::woocommerce_search() );
		$importer = $adapter instanceof Card_Importer && $adapter->supports( Capability::IMPORT_PRODUCTS ) ? new Import_Scheduler( new Job_Names( $plugin->get_id() ) ) : null;
		$rows     = new Remote_Cards_Rows( [ $products, 'describe' ], Logging::for_plugin( $plugin ), null !== $importer, $adapter instanceof Remote_Card_Address ? [ $adapter, 'remote_card_url' ] : null );
		$options  = Uninstaller::option_prefix( $plugin->get_id() );
		$store    = self::card_store( $plugin, $adapter, $db );
		$reports  = new Link_Report_Store( $options );
		$plan     = null === $importer ? null : new Remote_Cards_Import_Plan( Remote_Cards_Import_Plan::woocommerce_lookup( Product_Writer::marker_key( $adapter->id() ) ), Group_Assembler::lookup( $adapter->id() ) );
		$license  = License_Gate::for_plugin( $plugin );
		$runs     = null === $importer ? null : new Remote_Cards_Run_Actions( self::bulk_runner( $plugin, $accounts, $db ), new Bulk_Run_Store( $db, $table( 'bulk_runs' ) ), new Remote_Cards_Matching( $store ), $license );
		$actions  = new Remote_Cards_Actions(
			$store,
			$links,
			$reports,
			self::linker( $plugin, $adapter, $accounts, $db, $links, $ledger, $table ),
			new Link_Scheduler( new Job_Names( $plugin->get_id() ) ),
			self::bulk( $plugin, $adapter, $accounts, $db, $links, $ledger, $table ),
			$license,
			$products,
			$rows,
			$importer,
			$plan,
			$runs
		);

		return new Remote_Cards_Provider(
			$accounts,
			$store,
			$reports,
			$rows,
			$products,
			$actions,
			[
				'adapter_id'  => $adapter->id(),
				'marketplace' => $adapter->settings_descriptor()->marketplace_name(),
			],
			$importer
		);
	}

	/**
	 * The stored list of the marketplace's cards, as the jobs that fill it build it ({@see \Woodev\Framework\Marketplace\Flow_Wiring}).
	 *
	 * @param Marketplace_Plugin  $plugin  The marketplace plugin.
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @param \wpdb               $db      Database.
	 * @return Wpdb_Card_Store
	 */
	private static function card_store( Marketplace_Plugin $plugin, Marketplace_Adapter $adapter, \wpdb $db ): Wpdb_Card_Store {
		$schema = new Schema( $adapter->id() );

		return new Wpdb_Card_Store( $db, $schema->table_name( 'cards', $db->prefix ), $schema->table_name( 'links', $db->prefix ), Uninstaller::option_prefix( $plugin->get_id() ) );
	}

	/**
	 * Builds the bulk actions over the plugin's tables, with the same services the product tab uses.
	 *
	 * @param Marketplace_Plugin  $plugin   Plugin.
	 * @param Marketplace_Adapter $adapter  Adapter.
	 * @param Wpdb_Account_Store  $accounts Accounts.
	 * @param \wpdb               $db       Database.
	 * @param Wpdb_Link_Store     $links    Links.
	 * @param Wpdb_Task_Ledger    $ledger   Task ledger.
	 * @param callable            $table    `function ( string $name ): string`, the full name of a table.
	 * @return Site_Products_Bulk
	 */
	private static function bulk( Marketplace_Plugin $plugin, Marketplace_Adapter $adapter, Wpdb_Account_Store $accounts, \wpdb $db, Wpdb_Link_Store $links, Wpdb_Task_Ledger $ledger, callable $table ): Site_Products_Bulk {
		$job_names = new Job_Names( $plugin->get_id() );
		$store     = new Sync_State_Store( $db, $table( 'sync_state' ) );
		$queue     = new Sync_State_Queue( $db, $table( 'sync_state' ), $table( 'accounts' ), $adapter->stock_cadence() );
		$contexts  = self::export_contexts( $adapter, $db, $links, $ledger, $table );
		$log       = static function ( string $line ) use ( $plugin ): void {
			$plugin->log_error( $line );
		};
		$shop      = new Shop_Sku_Source( $db );
		$linker    = self::linker( $plugin, $adapter, $accounts, $db, $links, $ledger, $table );
		$mappings_url = $plugin->get_marketplace_page_url( 'mappings' );

		return new Site_Products_Bulk(
			new Export_Service( $links, $contexts, new Export_Scheduler( $job_names ), $adapter->export_facts(), new Export_Validator( $adapter->export_rules() ) ),
			$links,
			new Stock_Intents( [ $accounts, 'get' ], $links, $store, $queue, $adapter->stock_policy() ),
			new Price_Intents( $accounts, $adapter->id(), $links, $contexts, $store, $log ),
			$contexts,
			new Link_Detacher( $links, $queue, $ledger, $accounts ),
			new Link_Scheduler( $job_names ),
			$ledger,
			[
				'link_candidates' => static function ( int $account_id, ?int $category = null ) use ( $shop, $links ): int {
					return self::count_candidates( $shop->products( $category ), $links->identity_map( $account_id ) );
				},
				'link_effect'     => [ $linker, 'remote_effect' ],
				'pull_prices'     => null === $adapter->price_reader() ? null : static function ( Account $account, array $offer_ids ) use ( $job_names ): int {
					return ( new Price_Scheduler( $job_names ) )->queue_pull( $account->id(), $offer_ids );
				},
				'can_edit'        => static function ( int $product_id ): bool {
					return current_user_can( 'edit_product', $product_id );
				},
				'describe'        => static function ( int $product_id ): string {
					$title = get_the_title( $product_id );

					return '' === $title ? '#' . $product_id : html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
				},
				'fix_urls'        => [
					Validation_Report::FIX_MAPPING    => $mappings_url,
					Validation_Report::FIX_PROFILE    => $mappings_url,
					Validation_Report::FIX_DICTIONARY => $mappings_url,
					Validation_Report::FIX_ACCOUNT    => $plugin->get_settings_url(),
				],
			]
		);
	}

	/**
	 * Builds the linker the remote-cards table links through («Связать с…» and the near-match confirmation): the same one the link job
	 * and the import use, so a link made by hand follows the account's «sync on link» setting too.
	 *
	 * @param Marketplace_Plugin  $plugin   Plugin.
	 * @param Marketplace_Adapter $adapter  Adapter.
	 * @param Wpdb_Account_Store  $accounts Accounts.
	 * @param \wpdb               $db       Database.
	 * @param Wpdb_Link_Store     $links    Links.
	 * @param Wpdb_Task_Ledger    $ledger   Task ledger.
	 * @param callable            $table    `function ( string $name ): string`, the full name of a table.
	 * @return Card_Linker
	 */
	private static function linker( Marketplace_Plugin $plugin, Marketplace_Adapter $adapter, Wpdb_Account_Store $accounts, \wpdb $db, Wpdb_Link_Store $links, Wpdb_Task_Ledger $ledger, callable $table ): Card_Linker {
		$store = new Sync_State_Store( $db, $table( 'sync_state' ) );
		$queue = new Sync_State_Queue( $db, $table( 'sync_state' ), $table( 'accounts' ), $adapter->stock_cadence() );
		$log   = static function ( string $line ) use ( $plugin ): void {
			$plugin->log_error( $line );
		};
		$price = null === $adapter->price_pusher() ? null : new Price_Intents( $accounts, $adapter->id(), $links, self::export_contexts( $adapter, $db, $links, $ledger, $table ), $store, $log );

		return Card_Linker::syncing(
			$links,
			Effective_Settings::for_adapter( $adapter->id() ),
			[ $accounts, 'get' ],
			new Stock_Intents( [ $accounts, 'get' ], $links, $store, $queue, $adapter->stock_policy() ),
			$price
		);
	}

	/**
	 * Builds the export context builder the bulk actions and the price intents share.
	 *
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @param \wpdb               $db      Database.
	 * @param Wpdb_Link_Store     $links   Links.
	 * @param Wpdb_Task_Ledger    $ledger  Task ledger.
	 * @param callable            $table   `function ( string $name ): string`, the full name of a table.
	 * @return Export_Context_Builder
	 */
	private static function export_contexts( Marketplace_Adapter $adapter, \wpdb $db, Wpdb_Link_Store $links, Wpdb_Task_Ledger $ledger, callable $table ): Export_Context_Builder {
		$mappings = new Wpdb_Mapping_Store( $db, $table( 'mappings' ), $table( 'mapping_terms' ) );

		return new Export_Context_Builder(
			new Link_Context_Resolver( $links, $mappings ),
			new Wpdb_Profile_Store( $db, $table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( $adapter->id() ) ),
			$links,
			$adapter->export_facts(),
			$adapter->article_policy(),
			new Pinned_Offer_Id( $adapter->id(), $adapter->article_policy() ),
			$adapter->price_source(),
			null,
			null,
			$ledger
		);
	}

	/**
	 * Counts the shop products with an article that have no card identity yet: what linking by article would try to match.
	 *
	 * @since 2.1.4
	 *
	 * @param array<int,array{id:int,parent_id:int,sku:string}>                        $products Shop products with an article.
	 * @param array<int,array{offer_id:?string,has_remote_ids:bool,card_state:string}> $identity Identity of the account's links by product id.
	 * @return int
	 */
	public static function count_candidates( array $products, array $identity ): int {
		$count = 0;

		foreach ( $products as $product ) {
			if ( ! ( $identity[ $product['id'] ]['has_remote_ids'] ?? false ) ) {
				++$count;
			}
		}

		return $count;
	}
}

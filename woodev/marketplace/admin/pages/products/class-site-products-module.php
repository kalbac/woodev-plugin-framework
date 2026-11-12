<?php
/**
 * Wires the «Товары сайта» table for one marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Flows\Export_Context_Builder;
use Woodev\Framework\Marketplace\Flows\Export_Service;
use Woodev\Framework\Marketplace\Flows\Link_Context_Resolver;
use Woodev\Framework\Marketplace\Flows\Link_Detacher;
use Woodev\Framework\Marketplace\Flows\Shop_Sku_Source;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
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
 * The provider is built when the REST API starts, not on every request, and only when the plugin still has the `products` page.
 * A plugin whose adapter cannot import cards (no {@see Capability::IMPORT_PRODUCTS}) gets the table without «Экспортировать» and
 * «Связать по артикулу».
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
			},
			5 // Before the REST registrar walks its controllers (priority 10), so the routes of a table registered now exist.
		);
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
		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		if ( null === $menu || null === $page || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $plugin->get_id() ) ) {
			return false;
		}

		return Records_Registry::instance()->register(
			$plugin->get_id(),
			self::provider( $plugin, $accounts, $db ),
			$page->get_capability( $menu->get_capability() )
		);
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
		$withheld = $adapter->supports( Capability::IMPORT_PRODUCTS ) ? [] : [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::LINK_BY_ARTICLE ];

		return new Site_Products_Provider(
			$accounts,
			$reader,
			new Site_Products_Selection( $reader ),
			new Site_Products_Activity( $ledger, $db, $table( 'sync_state' ), $table( 'accounts' ) ),
			$bulk,
			new Warehouse_Options( new Wpdb_Catalog_Cache( $db, $table( 'catalog_cache' ) ) ),
			$license,
			[
				'adapter_id'  => $adapter->id(),
				'marketplace' => $adapter->settings_descriptor()->marketplace_name(),
				'withheld'    => $withheld,
			]
		);
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
		$mappings  = new Wpdb_Mapping_Store( $db, $table( 'mappings' ), $table( 'mapping_terms' ) );
		$contexts  = new Export_Context_Builder(
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
		$log       = static function ( string $line ) use ( $plugin ): void {
			$plugin->log_error( $line );
		};
		$shop      = new Shop_Sku_Source( $db );
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
				'link_candidates' => static function ( int $account_id ) use ( $shop, $links ): int {
					return self::count_candidates( $shop->products(), $links->identity_map( $account_id ) );
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

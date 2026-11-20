<?php
/**
 * Wires the embedded classic screens of a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Ajax;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Module;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
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
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renamer;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renames;
use Woodev\Framework\Marketplace\Jobs\Export_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Settings\Settings_Extension;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the product tab and the order cancel AJAX of a plugin, each **only when its adapter offers the capability**:
 * the adapter implements {@see Product_Tab_Facts} to get the tab, {@see Order_Canceller} to get the cancel call (its button sits in the
 * {@see \Woodev\Framework\Marketplace\Admin\Order\Order_Details_Box}, which every marketplace order screen has). The
 * `Marketplace_Adapter` interface itself does not change, so an adapter without either keeps working and gets neither screen.
 *
 * @since 2.1.2
 */
final class Embedded_Screens {
	/**
	 * Plugin ids whose screens are hooked by this PHP process.
	 *
	 * @var array<string,true>
	 */
	private static $registered = [];

	/**
	 * Tells which screens an adapter earns.
	 *
	 * @since 2.1.2
	 *
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @return array{product_tab:bool,order_cancel:bool}
	 */
	public static function capabilities_of( Marketplace_Adapter $adapter ): array {
		return [
			'product_tab'  => $adapter instanceof Product_Tab_Facts,
			'order_cancel' => $adapter instanceof Order_Canceller,
		];
	}

	/**
	 * Forgets which plugins were hooked. For tests: a process never needs it.
	 *
	 * @internal
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$registered = [];
	}

	/**
	 * Builds and hooks the screens the adapter offers.
	 *
	 * @since 2.1.2
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin   Plugin.
	 * @param Wpdb_Account_Store                               $accounts Account store.
	 * @param \wpdb                                            $db       Database.
	 * @return void
	 */
	public static function register( $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): void {
		$adapter      = $plugin->get_marketplace_adapter();
		$capabilities = self::capabilities_of( $adapter );

		if ( ( ! $capabilities['product_tab'] && ! $capabilities['order_cancel'] ) || isset( self::$registered[ $plugin->get_id() ] ) ) {
			return;
		}

		if ( ! is_admin() ) {
			return; // Both screens live in wp-admin (admin-ajax included).
		}

		self::$registered[ $plugin->get_id() ] = true;

		$names      = new Screen_Names( $adapter->id(), $adapter->settings_descriptor()->marketplace_name() );
		$assets_url = (string) preg_replace( '#/assets/?$#', '/marketplace/assets/', $plugin->get_framework_assets_url() );
		$guard      = Admin_Guard::for_store( $accounts, $adapter->id() );

		if ( $capabilities['product_tab'] ) {
			self::product_tab( $plugin, $adapter, $accounts, $db, $guard, $names, $assets_url )->register();
			self::variation_bulk( $plugin, $adapter, $accounts, $db, $names, $assets_url )->register();
		}

		if ( $capabilities['order_cancel'] ) {
			self::order_cancel( $plugin, $adapter, $accounts, $guard, $names );
		}
	}

	/**
	 * Hooks the cancel AJAX.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin     Plugin.
	 * @param Marketplace_Adapter                              $adapter    Adapter, an {@see Order_Canceller}.
	 * @param Account_Store                                    $accounts   Account store.
	 * @param Admin_Guard                                      $guard      Guard.
	 * @param Screen_Names                                     $names      Names.
	 * @return void
	 */
	private static function order_cancel( $plugin, Marketplace_Adapter $adapter, Account_Store $accounts, Admin_Guard $guard, Screen_Names $names ): void {
		( new Cancel_Ajax(
			$guard,
			$accounts,
			$adapter->order_meta(),
			$adapter,
			$names,
			License_Gate::for_plugin( $plugin ),
			function ( string $line ) use ( $plugin ): void {
				$plugin->log_error( $line );
			}
		) )->register();
	}

	/**
	 * Builds the marketplace group of the variation panel's bulk actions. The table's own bulk actions run behind it, built only when
	 * the merchant chooses one.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin     Plugin.
	 * @param Marketplace_Adapter                              $adapter    Adapter.
	 * @param Wpdb_Account_Store                               $accounts   Account store.
	 * @param \wpdb                                            $db         Database.
	 * @param Screen_Names                                     $names      Names.
	 * @param string                                           $assets_url Assets folder URL.
	 * @return Variation_Bulk_Actions
	 */
	private static function variation_bulk( $plugin, Marketplace_Adapter $adapter, Wpdb_Account_Store $accounts, \wpdb $db, Screen_Names $names, string $assets_url ): Variation_Bulk_Actions {
		return new Variation_Bulk_Actions(
			$accounts,
			License_Gate::for_plugin( $plugin ),
			$names,
			static function ( Account $account, string $action, array $offers, array $editable ) use ( $plugin, $accounts, $db ): array {
				return Site_Products_Module::bulk_for( $plugin, $accounts, $db )->apply( $account, $action, $offers, $editable );
			},
			[
				'products' => $plugin->get_marketplace_page_url( 'products' ),
				'assets'   => $assets_url,
			],
			$plugin->get_version(),
			Site_Products_Module::withheld_actions( $adapter ),
			static function ( string $line ) use ( $plugin ): void {
				$plugin->log_error( $line );
			}
		);
	}

	/**
	 * Builds the product tab over the plugin's tables.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin     Plugin.
	 * @param Marketplace_Adapter                              $adapter    Adapter, a {@see Product_Tab_Facts}.
	 * @param Wpdb_Account_Store                               $accounts   Account store.
	 * @param \wpdb                                            $db         Database.
	 * @param Admin_Guard                                      $guard      Guard.
	 * @param Screen_Names                                     $names      Names.
	 * @param string                                           $assets_url Assets folder URL.
	 * @return Product_Tab
	 */
	private static function product_tab( $plugin, Marketplace_Adapter $adapter, Wpdb_Account_Store $accounts, \wpdb $db, Admin_Guard $guard, Screen_Names $names, string $assets_url ): Product_Tab {
		$schema     = new Schema( $adapter->id() );
		$table      = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};
		$job_names  = new Job_Names( $plugin->get_id() );
		$links      = new Wpdb_Link_Store( $db, $table( 'links' ), $table( 'link_ids' ) );
		$store      = new Sync_State_Store( $db, $table( 'sync_state' ) );
		$queue      = new Sync_State_Queue( $db, $table( 'sync_state' ), $table( 'accounts' ), $adapter->stock_cadence() );
		$ledger     = new Wpdb_Task_Ledger( $db, $table( 'tasks' ), $table( 'task_items' ) );
		$mappings   = new Wpdb_Mapping_Store( $db, $table( 'mappings' ), $table( 'mapping_terms' ) );
		$pinned     = new Pinned_Offer_Id( $adapter->id(), $adapter->article_policy() );
		$contexts   = new Export_Context_Builder(
			new Link_Context_Resolver( $links, $mappings ),
			new Wpdb_Profile_Store( $db, $table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( $adapter->id() ) ),
			$links,
			$adapter->export_facts(),
			$adapter->article_policy(),
			$pinned,
			$adapter->price_source(),
			null,
			null,
			$ledger
		);
		$warehouses = new Warehouse_Options( new Wpdb_Catalog_Cache( $db, $table( 'catalog_cache' ) ) );
		$validator  = new Export_Validator( $adapter->export_rules() );
		$license    = License_Gate::for_plugin( $plugin );
		$stock      = new Stock_Intents( [ $accounts, 'get' ], $links, $store, $queue, $adapter->stock_policy() );
		$intents    = new Price_Intents(
			$accounts,
			$adapter->id(),
			$links,
			$contexts,
			$store,
			function ( string $line ) use ( $plugin ): void {
				$plugin->log_error( $line );
			}
		);

		/** @var Product_Tab_Facts $facts */
		$facts = $adapter;

		// A card that was sent keeps its article unless the adapter can change it on the marketplace (the same test as the job's wiring).
		$renames = $adapter instanceof Offer_Id_Renamer && $adapter->supports( Capability::RENAME_SKU )
			? Offer_Id_Renames::for_plugin(
				$links,
				$job_names,
				$pinned,
				function ( string $line ) use ( $plugin ): void {
					$plugin->log_error( $line );
				},
				function ( string $line ) use ( $plugin ): void {
					$plugin->log_debug( $line );
				}
			)
			: null;

		return new Product_Tab(
			$guard,
			$accounts,
			$links,
			new Panel_Reader( $links, $contexts, $adapter->export_facts(), $validator, $mappings, $warehouses, $store, $accounts, $license, Logging::for_plugin( $plugin ), $pinned, $adapter->article_policy(), $facts, null, null !== $renames ),
			new Panel_View( $names, $facts->supports_oversize(), null !== $adapter->price_pusher(), null !== $renames ),
			new Link_Settings_Writer( $links, $intents, $mappings, $warehouses, $stock, $pinned, $renames ),
			new Link_Form_Input( $pinned ),
			new Export_Service( $links, $contexts, new Export_Scheduler( $job_names ), $adapter->export_facts(), $validator ),
			new Link_Scheduler( $job_names ),
			$license,
			new Link_Detacher( $links, $queue, $ledger, $accounts ),
			$stock,
			$names,
			[
				'settings' => $plugin->get_settings_url(),
				'mappings' => $plugin->get_marketplace_page_url( 'mappings' ),
				'assets'   => $assets_url,
			],
			$plugin->get_version(),
			$adapter->supports( Capability::IMPORT_PRODUCTS ) ? [] : [ 'export', 'link' ],
			$adapter instanceof Settings_Extension ? new Product_Extension( $adapter, $names ) : null
		);
	}
}

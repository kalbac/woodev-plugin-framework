<?php
/**
 * Wires the «Заказы» table for one marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Actions;
use Woodev\Framework\Marketplace\Admin\Order\Unit_Orders;
use Woodev\Framework\Marketplace\Admin\Shared\Order_Unit_Text;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Flows\Label_Download;
use Woodev\Framework\Marketplace\Flows\Label_Service;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\Label_Jobs;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Posting_Jobs;
use Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the services of the «Заказы» table from the plugin's adapter and registers the provider with the records engine. Nothing
 * here names a marketplace: every marketplace fact comes from the {@see Marketplace_Adapter}.
 *
 * The provider is built when the REST API starts, not on every request, and only when the plugin still has the `orders` page. An
 * adapter without label calls ({@see Capability::GET_LABEL}) gets the table without the label column and action; one that is not an
 * {@see Order_Canceller} gets it without «Отменить отправление»; one that declares {@see Seller_Actions} gets their row and bulk
 * actions.
 *
 * @since 2.1.5
 */
final class Site_Orders_Module {
	/**
	 * Slug of the page this module serves.
	 */
	public const PAGE = 'orders';

	/**
	 * Hooks the registration for a plugin.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @param callable|null      $write_gate `function ( Account $account ): bool|string`, the plugin's write gate (since 2.1.17; a string refuses and is the reason shown, since 2.1.19); null lets every account write.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate = null ): void {
		add_action(
			'rest_api_init',
			static function () use ( $plugin, $accounts, $db, $write_gate ): void {
				self::register_provider( $plugin, $accounts, $db, $write_gate );

				$analytics = Orders_Analytics_Controller::for_plugin( $plugin, $accounts, $db );

				if ( null !== $analytics ) {
					$analytics->register_routes();
				}
			},
			5 // Before the REST registrar walks its controllers (priority 10), so the routes of a table registered now exist.
		);
	}

	/**
	 * Registers the provider with the records engine; does nothing when the plugin has no `orders` page or its id cannot be a route segment.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @param callable|null      $write_gate `function ( Account $account ): bool|string`, the plugin's write gate (since 2.1.17; a string refuses and is the reason shown, since 2.1.19); null lets every account write.
	 * @return bool Whether a provider was registered.
	 */
	public static function register_provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate = null ): bool {
		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		if ( null === $menu || null === $page || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $plugin->get_id() ) ) {
			return false;
		}

		return Records_Registry::instance()->register(
			$plugin->get_id(),
			self::provider( $plugin, $accounts, $db, $write_gate ),
			$page->get_capability( $menu->get_capability() )
		);
	}

	/**
	 * Builds the provider.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @param callable|null      $write_gate `function ( Account $account ): bool|string`, the plugin's write gate (since 2.1.17; a string refuses and is the reason shown, since 2.1.19); null lets every account write.
	 * @return Site_Orders_Provider
	 */
	public static function provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate = null ): Site_Orders_Provider {
		$adapter  = $plugin->get_marketplace_adapter();
		$schema   = new Schema( $adapter->id() );
		$table    = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};
		$license  = License_Gate::for_plugin( $plugin );
		$labels   = self::labels( $plugin, $adapter, $accounts, $db, $table, $license );
		$noun     = Posting_Noun::for_descriptor( $adapter->settings_descriptor() );
		$cancel   = $adapter instanceof Order_Canceller ? new Site_Orders_Cancel( $adapter, $license, $adapter->settings_descriptor()->marketplace_name(), $noun ) : null;
		$can_edit = static function ( int $order_id ): bool {
			return current_user_can( 'edit_shop_order', $order_id );
		};
		$gate     = Posting_Action_Gate::for_adapter( $adapter, $write_gate );
		$seller   = self::seller( $plugin, $adapter, $gate, $license, new Wpdb_Seller_Action_Store( $db, $table( 'seller_actions' ) ) );
		$units    = $adapter instanceof Unit_Orders ? new Site_Orders_Units( Order_Unit_Text::for_adapter( $adapter, $db, $plugin->get_id() ), $seller ) : null;

		return new Site_Orders_Provider(
			$accounts,
			new Site_Orders_Reader( $db, $table( 'postings' ), Site_Orders_Storage::current() ),
			new Site_Orders_Rows(
				Site_Orders_Provider::offered_actions( null !== $labels, null !== $cancel ),
				$can_edit,
				static function ( \WC_Order $order ) use ( $cancel ): bool {
					return null !== $cancel && $cancel->is_finished( $order );
				},
				$adapter instanceof Posting_Status_Names ? $adapter : null,
				$gate
			),
			new Site_Orders_Activity(),
			$labels,
			$cancel,
			$adapter->order_meta(),
			$can_edit,
			$adapter->id(),
			static function ( string $line ) use ( $plugin ): void {
				$plugin->log_error( $line );
			},
			self::data_status( $plugin, $adapter ),
			null,
			$gate,
			$noun,
			$seller,
			$units
		);
	}

	/**
	 * The seller actions of an adapter that declares them.
	 *
	 * @param Marketplace_Plugin       $plugin  Plugin.
	 * @param Marketplace_Adapter      $adapter Adapter.
	 * @param Posting_Action_Gate      $gate    The write rule.
	 * @param License_Gate             $license Licence gate.
	 * @param Wpdb_Seller_Action_Store $sent    What was sent for which unit.
	 * @return Site_Orders_Seller|null
	 */
	private static function seller( Marketplace_Plugin $plugin, Marketplace_Adapter $adapter, Posting_Action_Gate $gate, License_Gate $license, Wpdb_Seller_Action_Store $sent ): ?Site_Orders_Seller {
		if ( ! $adapter instanceof Seller_Actions ) {
			return null;
		}

		$log = static function ( string $line ) use ( $plugin ): void {
			$plugin->log_error( $line );
		};

		return new Site_Orders_Seller( new Seller_Action_Runner( $adapter, $gate, $license, $adapter->settings_descriptor()->marketplace_name(), $sent, $log ) );
	}

	/**
	 * The «Статус данных» block over the plugin's order poll; none for a marketplace whose orders are not polled.
	 *
	 * @param Marketplace_Plugin  $plugin  Plugin.
	 * @param Marketplace_Adapter $adapter Adapter.
	 * @return Site_Orders_Data_Status|null
	 */
	private static function data_status( Marketplace_Plugin $plugin, Marketplace_Adapter $adapter ): ?Site_Orders_Data_Status {
		if ( null === $adapter->posting_handler() ) {
			return null;
		}

		$names = new Job_Names( $plugin->get_id() );

		return Site_Orders_Data_Status::over_scheduler( new Posting_Poll_Times( $plugin->get_id() ), $names->hook( Posting_Jobs::JOB_POLL ), $names->group() );
	}

	/**
	 * Builds the label part over the plugin's tables, with the same services the label download and the label jobs use.
	 *
	 * @param Marketplace_Plugin  $plugin   Plugin.
	 * @param Marketplace_Adapter $adapter  Adapter.
	 * @param Wpdb_Account_Store  $accounts Accounts.
	 * @param \wpdb               $db       Database.
	 * @param callable            $table    `function ( string $name ): string`, the full name of a table.
	 * @param License_Gate        $license  Licence gate.
	 * @return Site_Orders_Labels|null Null when the adapter has no label calls.
	 */
	private static function labels( Marketplace_Plugin $plugin, Marketplace_Adapter $adapter, Wpdb_Account_Store $accounts, \wpdb $db, callable $table, License_Gate $license ): ?Site_Orders_Labels {
		$provider = $adapter->label_provider();

		if ( null === $provider || ! $adapter->supports( Capability::GET_LABEL ) ) {
			return null;
		}

		$names      = new Job_Names( $plugin->get_id() );
		$classifier = $adapter->error_classifier();
		$store      = new Wpdb_Label_Store( $db, $table( 'tasks' ), $table( 'documents' ) );
		$jobs       = new Label_Jobs( new Job_Gate( $accounts, $license, Account_Pause::over( $accounts, $classifier ) ), new Label_Service( $provider, $store, $license, $classifier, $names ), $store, $classifier, $names );
		$registry   = new Order_Registry( $db, $table( 'postings' ) );
		$download   = new Label_Download(
			$store,
			$accounts,
			static function ( int $account_id, string $external_key ) use ( $registry ): ?int {
				$row = $registry->find( $account_id, $external_key );

				return null === $row ? null : $row['wc_order_id'];
			},
			$adapter->order_meta(),
			$license,
			$names,
			$adapter->id()
		);

		return new Site_Orders_Labels( $db, $table( 'tasks' ), $table( 'documents' ), [ $download, 'url' ], [ $jobs, 'request' ] );
	}
}

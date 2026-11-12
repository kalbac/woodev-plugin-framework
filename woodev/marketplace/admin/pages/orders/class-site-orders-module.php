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
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Flows\Label_Download;
use Woodev\Framework\Marketplace\Flows\Label_Service;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\Label_Jobs;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the services of the «Заказы» table from the plugin's adapter and registers the provider with the records engine. Nothing
 * here names a marketplace: every marketplace fact comes from the {@see Marketplace_Adapter}.
 *
 * The provider is built when the REST API starts, not on every request, and only when the plugin still has the `orders` page. An
 * adapter without label calls ({@see Capability::GET_LABEL}) gets the table without the label column and action; one that is not an
 * {@see Order_Canceller} gets it without «Отменить отправление».
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
	 * Registers the provider with the records engine; does nothing when the plugin has no `orders` page or its id cannot be a route segment.
	 *
	 * @since 2.1.5
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
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @return Site_Orders_Provider
	 */
	public static function provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): Site_Orders_Provider {
		$adapter  = $plugin->get_marketplace_adapter();
		$schema   = new Schema( $adapter->id() );
		$table    = static function ( string $name ) use ( $schema, $db ): string {
			return $schema->table_name( $name, $db->prefix );
		};
		$license  = License_Gate::for_plugin( $plugin );
		$labels   = self::labels( $plugin, $adapter, $accounts, $db, $table, $license );
		$cancel   = $adapter instanceof Order_Canceller ? new Site_Orders_Cancel( $adapter, $license, $adapter->settings_descriptor()->marketplace_name() ) : null;
		$can_edit = static function ( int $order_id ): bool {
			return current_user_can( 'edit_shop_order', $order_id );
		};

		return new Site_Orders_Provider(
			$accounts,
			new Site_Orders_Reader( $db, $table( 'postings' ), Site_Orders_Storage::current() ),
			new Site_Orders_Rows(
				Site_Orders_Provider::offered_actions( null !== $labels, null !== $cancel ),
				$can_edit,
				static function ( \WC_Order $order ) use ( $cancel ): bool {
					return null !== $cancel && $cancel->is_finished( $order );
				},
				static function ( int $document_id ) use ( $labels ): string {
					return null === $labels ? '' : $labels->url( $document_id );
				}
			),
			new Site_Orders_Activity(),
			$labels,
			$cancel,
			$adapter->order_meta(),
			$can_edit,
			$adapter->id(),
			static function ( string $line ) use ( $plugin ): void {
				$plugin->log_error( $line );
			}
		);
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

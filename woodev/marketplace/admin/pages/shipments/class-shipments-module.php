<?php
/**
 * The wiring of the page of shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Actions;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Seller;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Flows\Shipment_Service;
use Woodev\Framework\Marketplace\Flows\Shipment_Snapshot;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Shipment_Jobs;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Framework\Marketplace\Shipments\Shipment_Batches;

defined( 'ABSPATH' ) || exit;

/**
 * A marketplace that reports {@see Capability::CONFIRM_SHIPMENT} and implements {@see Shipment_Batches} gets the page of
 * shipments, its records provider, the download of their documents, the refresh job and «add to a shipment» on its orders; any
 * other marketplace gets none of them. Nothing here names a marketplace: every marketplace fact comes from the adapter.
 *
 * @since 2.1.28
 */
final class Shipments_Module {
	/**
	 * Slug of the page.
	 */
	public const PAGE = 'shipments';

	/**
	 * Whether a marketplace ships in shipments the screens can manage.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Adapter $adapter The marketplace's adapter.
	 * @return bool
	 */
	public static function is_offered( Marketplace_Adapter $adapter ): bool {
		return $adapter instanceof Shipment_Batches && $adapter->supports( Capability::CONFIRM_SHIPMENT );
	}

	/**
	 * The page's descriptor, named in the marketplace's words.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Adapter $adapter The marketplace's adapter.
	 * @return array<string,mixed>
	 */
	public static function page( Marketplace_Adapter $adapter ): array {
		return [
			'slug'   => self::PAGE,
			'title'  => Shipment_Noun::for_descriptor( $adapter->settings_descriptor() )->title(),
			'kind'   => Page::KIND_RECORDS,
			'tables' => [ Shipments_Provider::TABLE ],
		];
	}

	/**
	 * Hooks the page for a plugin: the document download, and the provider when the REST API starts.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Plugin $plugin     The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts   Account store.
	 * @param \wpdb              $db         Database.
	 * @param callable|null      $write_gate `function ( Account $account ): bool|string`, the plugin's write gate; null lets every account write.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate = null ): void {
		$adapter = $plugin->get_marketplace_adapter();

		if ( ! self::is_offered( $adapter ) ) {
			return;
		}

		self::download( $plugin, $accounts, $db, $write_gate )->register();

		add_action(
			'rest_api_init',
			static function () use ( $plugin, $accounts, $db, $write_gate ): void {
				self::register_provider( $plugin, $accounts, $db, $write_gate );
			},
			5 // Before the REST registrar walks its controllers (priority 10), so the routes of a table registered now exist.
		);
	}

	/**
	 * Registers the provider with the records engine; does nothing when the plugin has no page of shipments or its id cannot be a
	 * route segment.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Plugin $plugin     The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts   Account store.
	 * @param \wpdb              $db         Database.
	 * @param callable|null      $write_gate The plugin's write gate.
	 * @return bool Whether a provider was registered.
	 */
	public static function register_provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate = null ): bool {
		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		if ( null === $menu || null === $page || ! self::is_offered( $plugin->get_marketplace_adapter() ) || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $plugin->get_id() ) ) {
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
	 * @since 2.1.28
	 *
	 * @param Marketplace_Plugin $plugin     A marketplace plugin whose adapter is offered the page ({@see self::is_offered()}).
	 * @param Wpdb_Account_Store $accounts   Account store.
	 * @param \wpdb              $db         Database.
	 * @param callable|null      $write_gate The plugin's write gate.
	 * @return Shipments_Provider
	 */
	public static function provider( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate = null ): Shipments_Provider {
		$adapter  = $plugin->get_marketplace_adapter();
		$noun     = Shipment_Noun::for_descriptor( $adapter->settings_descriptor() );
		$store    = self::store( $plugin, $db );
		$service  = self::service( $plugin, $accounts, $db, $write_gate );
		$names    = new Job_Names( $plugin->get_id() );
		$runner   = self::seller( $plugin, $db, $write_gate );
		$seller   = null === $runner ? null : new Site_Orders_Seller( $runner );
		$can_edit = static function ( int $order_id ): bool {
			return current_user_can( 'edit_shop_order', $order_id );
		};

		return new Shipments_Provider(
			$accounts,
			$adapter,
			$store,
			$service,
			new Shipments_Rows( $store, $adapter, $service, $noun, $seller, $can_edit ),
			$noun,
			$adapter->id(),
			$adapter->order_meta(),
			$can_edit,
			self::queue_refresh( $plugin, $accounts, $db ),
			[ self::download( $plugin, $accounts, $db, $write_gate ), 'url' ],
			new Shipments_Data_Status(
				$store,
				static function ( int $account_id ) use ( $names ) {
					return function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( $names->hook( Shipment_Jobs::JOB_POLL ), [ $account_id ], $names->group() ) : false;
				}
			),
			$seller,
			self::log( $plugin )
		);
	}

	/**
	 * The shop's copy of the plugin's shipments.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Plugin $plugin The marketplace plugin.
	 * @param \wpdb              $db     Database.
	 * @return Wpdb_Shipment_Store
	 */
	public static function store( Marketplace_Plugin $plugin, \wpdb $db ): Wpdb_Shipment_Store {
		$schema = new Schema( $plugin->get_marketplace_adapter()->id() );

		return new Wpdb_Shipment_Store( $db, $schema->table_name( 'shipments', $db->prefix ), $schema->table_name( 'shipment_units', $db->prefix ), Uninstaller::option_prefix( $plugin->get_id() ) );
	}

	/**
	 * The actions on the plugin's shipments.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Plugin $plugin     A marketplace plugin whose adapter is offered the page.
	 * @param Wpdb_Account_Store $accounts   Account store.
	 * @param \wpdb              $db         Database.
	 * @param callable|null      $write_gate The plugin's write gate.
	 * @return Shipment_Service
	 */
	public static function service( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate = null ): Shipment_Service {
		$adapter    = $plugin->get_marketplace_adapter();
		$classifier = $adapter->error_classifier();
		$store      = self::store( $plugin, $db );

		return new Shipment_Service(
			$adapter,
			$store,
			self::snapshot( $plugin, $db ),
			Posting_Action_Gate::for_adapter( $adapter, $write_gate ),
			License_Gate::for_plugin( $plugin ),
			$classifier,
			Account_Pause::over( $accounts, $classifier ),
			Shipment_Noun::for_descriptor( $adapter->settings_descriptor() ),
			self::queue_refresh( $plugin, $accounts, $db ),
			self::log( $plugin )
		);
	}

	/**
	 * The jobs that keep the plugin's copy up to date.
	 *
	 * @since 2.1.28
	 *
	 * @param Marketplace_Plugin $plugin   A marketplace plugin whose adapter is offered the page.
	 * @param Wpdb_Account_Store $accounts Account store.
	 * @param \wpdb              $db       Database.
	 * @param Job_Gate           $gate     Gate of licence, account and pause.
	 * @return Shipment_Jobs
	 */
	public static function jobs( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, Job_Gate $gate ): Shipment_Jobs {
		$adapter = $plugin->get_marketplace_adapter();

		return new Shipment_Jobs( $gate, $accounts, self::snapshot( $plugin, $db ), self::store( $plugin, $db ), $adapter->error_classifier(), new Job_Names( $plugin->get_id() ), $adapter->id(), self::log( $plugin ) );
	}

	/**
	 * @param Marketplace_Plugin $plugin Plugin.
	 * @param \wpdb              $db     Database.
	 * @return Shipment_Snapshot The reads, tying units to orders through the order registry.
	 */
	private static function snapshot( Marketplace_Plugin $plugin, \wpdb $db ): Shipment_Snapshot {
		$adapter  = $plugin->get_marketplace_adapter();
		$postings = ( new Schema( $adapter->id() ) )->table_name( 'postings', $db->prefix );

		return Shipment_Snapshot::over( $adapter, self::store( $plugin, $db ), new Order_Registry( $db, $postings ) );
	}

	/**
	 * @param Marketplace_Plugin $plugin   Plugin.
	 * @param Wpdb_Account_Store $accounts Accounts.
	 * @param \wpdb              $db       Database.
	 * @return callable `function ( int $account_id ): void`, queues a refresh of an account's shipments.
	 */
	private static function queue_refresh( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db ): callable {
		return static function ( int $account_id ) use ( $plugin, $accounts, $db ): void {
			$classifier = $plugin->get_marketplace_adapter()->error_classifier();
			$gate       = new Job_Gate( $accounts, License_Gate::for_plugin( $plugin ), Account_Pause::over( $accounts, $classifier ) );

			self::jobs( $plugin, $accounts, $db, $gate )->queue_refresh( $account_id );
		};
	}

	/**
	 * @param Marketplace_Plugin $plugin     Plugin.
	 * @param Wpdb_Account_Store $accounts   Accounts.
	 * @param \wpdb              $db         Database.
	 * @param callable|null      $write_gate The plugin's write gate.
	 * @return Shipment_Document_Download
	 */
	private static function download( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts, \wpdb $db, ?callable $write_gate ): Shipment_Document_Download {
		return new Shipment_Document_Download( self::service( $plugin, $accounts, $db, $write_gate ), $accounts, new Job_Names( $plugin->get_id() ), $plugin->get_marketplace_adapter()->id() );
	}

	/**
	 * @param Marketplace_Plugin $plugin     Plugin.
	 * @param \wpdb              $db         Database.
	 * @param callable|null      $write_gate The plugin's write gate.
	 * @return Seller_Action_Runner|null The seller actions of an adapter that declares them.
	 */
	private static function seller( Marketplace_Plugin $plugin, \wpdb $db, ?callable $write_gate ): ?Seller_Action_Runner {
		$adapter = $plugin->get_marketplace_adapter();

		if ( ! $adapter instanceof Seller_Actions ) {
			return null;
		}

		$sent = new Wpdb_Seller_Action_Store( $db, ( new Schema( $adapter->id() ) )->table_name( 'seller_actions', $db->prefix ) );

		return new Seller_Action_Runner( $adapter, Posting_Action_Gate::for_adapter( $adapter, $write_gate ), License_Gate::for_plugin( $plugin ), $adapter->settings_descriptor()->marketplace_name(), $sent, self::log( $plugin ) );
	}

	/**
	 * @param Marketplace_Plugin $plugin Plugin.
	 * @return callable `function ( string $line ): void`, the plugin's error log.
	 */
	private static function log( Marketplace_Plugin $plugin ): callable {
		return static function ( string $line ) use ( $plugin ): void {
			$plugin->log_error( $line );
		};
	}
}

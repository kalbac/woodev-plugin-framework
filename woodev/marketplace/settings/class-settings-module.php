<?php
/**
 * Wiring factory for a marketplace plugin's settings handler, providers and diagnostics report builder.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Diagnostics_Reader;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Queue_Health;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Builder;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Site_Facts;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Scheduler;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Settings\Settings_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the default {@see Settings_Handler}, {@see Settings_Provider} and {@see Report_Builder} of a
 * {@see Marketplace_Plugin} from its adapter and tables.
 *
 * @since 2.1.2
 */
final class Settings_Module {
	/**
	 * Builds the default {@see Settings_Handler} (`accounts`, optional `notifications`, `advanced`) for `$plugin`,
	 * or null when the plugin's account store is not {@see Wpdb_Account_Store}.
	 *
	 * @since 2.1.2
	 *
	 * @param Marketplace_Plugin  $plugin        Plugin instance.
	 * @param Account_Store       $accounts      Plugin's account store.
	 * @param array<string,mixed> $provider_args Optional `Settings_Provider` metadata (`legacy_page`, `capability`).
	 * @return Settings_Handler|null
	 */
	public static function handler_for( Marketplace_Plugin $plugin, Account_Store $accounts, array $provider_args = [] ): ?Settings_Handler {
		if ( ! $accounts instanceof Wpdb_Account_Store ) {
			return null;
		}

		global $wpdb;

		$adapter    = $plugin->get_marketplace_adapter();
		$schema     = new Schema( $adapter->id() );
		$cache      = new Wpdb_Catalog_Cache( $wpdb, $schema->table_name( 'catalog_cache', $wpdb->prefix ) );
		$warehouses = new Warehouse_Options( $cache );
		$status     = new Account_Status( $accounts, $adapter );
		$plugin_id  = $plugin->get_id();
		$names      = new Job_Names( $plugin_id );

		$on_connected = null;
		if ( $adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
			$scheduler    = new Refresh_Catalog_Scheduler( $names );
			$on_connected = [ $scheduler, 'queue_now' ];
		}

		$section_providers   = [];
		$section_providers[] = new Accounts_Sections( $accounts, $adapter, $status, $warehouses, $plugin_id, $on_connected );

		if ( $adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
			$section_providers[] = new Notifications_Section(
				$adapter,
				new Notification_Account_Reader( $wpdb, $schema->table_name( 'accounts', $wpdb->prefix ) ),
				new Last_Notification_Reader( $wpdb, $schema->table_name( 'events', $wpdb->prefix ) ),
				License_Gate::for_plugin( $plugin ),
				$plugin_id
			);
		}

		$section_providers[] = new Advanced_Section();

		return new Settings_Handler(
			$plugin_id,
			$section_providers,
			$adapter->settings_descriptor()->marketplace_name(),
			$provider_args
		);
	}

	/**
	 * Builds the `Settings_Provider[]` array for `Marketplace_Plugin::get_settings_providers()`.
	 *
	 * @since 2.1.2
	 *
	 * @param Marketplace_Plugin  $plugin        Plugin instance.
	 * @param Account_Store       $accounts      Plugin's account store.
	 * @param array<string,mixed> $provider_args Optional `Settings_Provider` metadata.
	 * @return Settings_Provider[]
	 */
	public static function providers_for( Marketplace_Plugin $plugin, Account_Store $accounts, array $provider_args = [] ): array {
		$handler = self::handler_for( $plugin, $accounts, $provider_args );

		return null === $handler ? [] : [ $handler->get_settings_provider() ];
	}

	/**
	 * Builds the {@see Report_Builder} for `$plugin`.
	 *
	 * @since 2.1.2
	 *
	 * @param Marketplace_Plugin $plugin   Plugin instance.
	 * @param Account_Store      $accounts Plugin's account store.
	 * @return Report_Builder
	 */
	public static function report_builder_for( Marketplace_Plugin $plugin, Account_Store $accounts ): Report_Builder {
		global $wpdb;

		$adapter    = $plugin->get_marketplace_adapter();
		$schema     = new Schema( $adapter->id() );
		$cache      = new Wpdb_Catalog_Cache( $wpdb, $schema->table_name( 'catalog_cache', $wpdb->prefix ) );
		$warehouses = new Warehouse_Options( $cache );
		$names      = new Job_Names( $plugin->get_id() );
		$logging    = Logging::for_plugin( $plugin );
		$queue      = new Queue_Health( $wpdb, $names, $schema );
		$reader     = new Diagnostics_Reader( $wpdb, $schema );
		$site       = new Site_Facts( $adapter, $cache, (string) $plugin->get_plugin_name(), (string) $plugin->get_version() );

		$notif_accounts     = null;
		$last_notifications = null;

		if ( $adapter->supports( Capability::PUSH_NOTIFICATIONS ) ) {
			$notif_accounts     = new Notification_Account_Reader( $wpdb, $schema->table_name( 'accounts', $wpdb->prefix ) );
			$last_notifications = new Last_Notification_Reader( $wpdb, $schema->table_name( 'events', $wpdb->prefix ) );
		}

		return new Report_Builder(
			$adapter,
			$accounts,
			$warehouses,
			License_Gate::for_plugin( $plugin ),
			$logging,
			$queue,
			$reader,
			$site,
			$notif_accounts,
			$last_notifications
		);
	}
}

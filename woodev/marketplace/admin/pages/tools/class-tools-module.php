<?php
/**
 * Wires the tools page's REST routes for one marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Marketplace_Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the tools of a plugin's page and registers its controller. Nothing here names a marketplace: the tools come from
 * the `woodev_{adapter id}_marketplace_tools` filter, and the account table and claim come from the adapter's schema.
 *
 * The job gate is built here only for the claim: a tool that takes the account claim takes it and releases it through the
 * same {@see Job_Gate} the runners use, so a tool and a background run cannot both hold it.
 *
 * The page is registered by the base class; a plugin that dropped `tools` from `get_marketplace_pages()` gets no routes.
 *
 * @since 2.1.5
 */
final class Tools_Module {
	/**
	 * Slug of the page this module serves.
	 */
	public const PAGE = 'tools';

	/**
	 * Hooks the route registration for a plugin.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts The plugin's account store.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts ): void {
		add_action(
			'rest_api_init',
			static function () use ( $plugin, $accounts ): void {
				$controller = self::controller( $plugin, $accounts );

				if ( null !== $controller ) {
					$controller->register_routes();
				}
			}
		);
	}

	/**
	 * Builds the controller of a plugin.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Wpdb_Account_Store $accounts The plugin's account store.
	 * @return Tools_Controller|null Null when the plugin has no tools page or its id cannot be a route segment.
	 */
	public static function controller( Marketplace_Plugin $plugin, Wpdb_Account_Store $accounts ): ?Tools_Controller {
		global $wpdb;

		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		$owner_ok = 1 === preg_match( Mappings_Controller::OWNER_PATTERN, $plugin->get_id() );

		if ( null === $menu || null === $page || ! $owner_ok ) {
			return null;
		}

		$adapter     = $plugin->get_marketplace_adapter();
		$table       = ( new Schema( $adapter->id() ) )->table_name( 'accounts', $wpdb->prefix );
		$activity    = new Tool_Activity( $wpdb, $table );
		$gate        = new Job_Gate( $accounts, License_Gate::for_plugin( $plugin ), Account_Pause::over( $accounts, $adapter->error_classifier() ) );
		$registry    = Tool_Registry::for_adapter( $adapter->id() );
		$runner      = new Tool_Runner( $registry, [ $gate, 'claim' ], [ $gate, 'release' ] );

		return new Tools_Controller(
			$plugin->get_id(),
			$page->get_capability( $menu->get_capability() ),
			$adapter->id(),
			$accounts,
			$registry,
			$activity,
			$runner
		);
	}
}

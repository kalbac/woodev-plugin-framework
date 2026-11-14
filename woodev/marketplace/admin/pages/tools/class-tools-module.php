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
 * The job gate serves the tools twice. A tool that takes the account claim takes, renews and releases it through the same
 * {@see Job_Gate} the runners use, so a tool and a background run cannot both hold it. And a remote tool waits for the same
 * licence gate and account pause as a background run ({@see self::remote_hold()}).
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
		$license     = License_Gate::for_plugin( $plugin );
		$gate        = new Job_Gate( $accounts, $license, Account_Pause::over( $accounts, $adapter->error_classifier() ) );
		$registry    = Tool_Registry::for_adapter( $adapter->id() );
		$runner      = new Tool_Runner( $registry, [ $gate, 'claim' ], [ $gate, 'release' ], [ $gate, 'still_holds' ], [ $plugin, 'log_error' ] );
		$hold        = self::remote_hold(
			$license,
			static function ( int $account_id ) use ( $gate ): int {
				return $gate->check( $account_id )[1];
			}
		);

		return new Tools_Controller(
			$plugin->get_id(),
			$page->get_capability( $menu->get_capability() ),
			$adapter->id(),
			$accounts,
			$registry,
			$activity,
			$runner,
			new Tool_Catalogue( $registry, $activity, $hold )
		);
	}

	/**
	 * The rule that holds a remote tool: a new remote action waits while the licence is not in force, and for an account
	 * while it is paused after the marketplace refused its access. The licence sentence is the framework's, word for word.
	 *
	 * @since 2.1.5
	 *
	 * @param License_Gate $license    Licence gate.
	 * @param callable     $pause_wait `function ( int $account_id ): int` — seconds the account's jobs still wait; 0 to go.
	 * @return callable `function ( int $account_id ): ?string` — the sentence that holds the tool, or null. Account id 0 (no
	 *                  account chosen yet) has no pause to check.
	 */
	public static function remote_hold( License_Gate $license, callable $pause_wait ): callable {
		return static function ( int $account_id ) use ( $license, $pause_wait ): ?string {
			$refusal = $license->refusal_for( __( 'запустить инструмент, который обращается к маркетплейсу', 'woodev-plugin-framework' ) );

			if ( null !== $refusal ) {
				return $refusal;
			}

			if ( $account_id > 0 && call_user_func( $pause_wait, $account_id ) > 0 ) {
				return __( 'Маркетплейс отказал аккаунту в доступе, поэтому работа с ним приостановлена. Проверьте ключи в настройках плагина — инструмент станет доступен позже.', 'woodev-plugin-framework' );
			}

			return null;
		};
	}
}

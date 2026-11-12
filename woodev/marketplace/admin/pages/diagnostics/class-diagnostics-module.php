<?php
/**
 * Wires the diagnostics page's REST routes for one marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Diagnostics;

use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Controller;
use Woodev\Framework\Marketplace\Marketplace_Plugin;
use Woodev\Framework\Marketplace\Settings\Settings_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the report builder of a plugin and registers the diagnostics controller. The report sections are the ones the
 * settings screen already uses ({@see Settings_Module::report_builder_for()}), so the page and the settings never disagree.
 *
 * The page is registered by the base class; a plugin that dropped `diagnostics` from `get_marketplace_pages()` gets no routes.
 *
 * @since 2.1.5
 */
final class Diagnostics_Module {
	/**
	 * Slug of the page this module serves.
	 */
	public const PAGE = 'diagnostics';

	/**
	 * Hooks the route registration for a plugin.
	 *
	 * @since 2.1.5
	 *
	 * @param Marketplace_Plugin $plugin   The marketplace plugin.
	 * @param Account_Store      $accounts The plugin's account store.
	 * @return void
	 */
	public static function register( Marketplace_Plugin $plugin, Account_Store $accounts ): void {
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
	 * @param Account_Store      $accounts The plugin's account store.
	 * @return Diagnostics_Controller|null Null when the plugin has no diagnostics page or its id cannot be a route segment.
	 */
	public static function controller( Marketplace_Plugin $plugin, Account_Store $accounts ): ?Diagnostics_Controller {
		$menu = $plugin->get_marketplace_admin_menu();
		$page = null === $menu ? null : $menu->get_page( self::PAGE );

		$owner_ok = 1 === preg_match( Mappings_Controller::OWNER_PATTERN, $plugin->get_id() );

		if ( null === $menu || null === $page || ! $owner_ok ) {
			return null;
		}

		return new Diagnostics_Controller(
			$plugin->get_id(),
			$page->get_capability( $menu->get_capability() ),
			Settings_Module::report_builder_for( $plugin, $accounts ),
			Logging::for_plugin( $plugin )
		);
	}
}

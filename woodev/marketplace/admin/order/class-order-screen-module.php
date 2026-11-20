<?php
/**
 * Wires the read-only order screen of a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Module;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every marketplace plugin the read-only order screen ({@see Order_Screen}) with its details box ({@see Order_Details_Box}),
 * whatever else its adapter offers: the cancel button comes with an {@see Order_Canceller}, the link to labels with label calls.
 *
 * @since 2.1.13
 */
final class Order_Screen_Module {
	/**
	 * Plugin ids whose screen is hooked by this PHP process.
	 *
	 * @var array<string,true>
	 */
	private static $registered = [];

	/**
	 * Forgets which plugins were hooked. For tests: a process never needs it.
	 *
	 * @internal
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$registered = [];
	}

	/**
	 * Builds and hooks the screen.
	 *
	 * @since 2.1.13
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin Plugin.
	 * @param \wpdb                                            $db     Database.
	 * @return void
	 */
	public static function register( $plugin, \wpdb $db ): void {
		if ( ! is_admin() || isset( self::$registered[ $plugin->get_id() ] ) ) {
			return;
		}

		self::$registered[ $plugin->get_id() ] = true;

		$adapter  = $plugin->get_marketplace_adapter();
		$names    = new Screen_Names( $adapter->id(), $adapter->settings_descriptor()->marketplace_name() );
		$registry = new Order_Registry( $db, ( new Schema( $adapter->id() ) )->table_name( 'postings', $db->prefix ) );
		$box      = new Order_Details_Box( $names, $adapter, [ $registry, 'status_of_order' ], self::cancel_modal( $plugin, $adapter, $names ), self::labels_url( $plugin, $adapter ) );

		( new Order_Screen( $adapter->order_meta(), $box ) )->register();
	}

	/**
	 * Builds the cancel button of an adapter that can cancel.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin  Plugin.
	 * @param Marketplace_Adapter                              $adapter Adapter.
	 * @param Screen_Names                                     $names   Names.
	 * @return Cancel_Modal|null
	 */
	private static function cancel_modal( $plugin, Marketplace_Adapter $adapter, Screen_Names $names ): ?Cancel_Modal {
		if ( ! $adapter instanceof Order_Canceller ) {
			return null;
		}

		$assets_url = (string) preg_replace( '#/assets/?$#', '/marketplace/assets/', $plugin->get_framework_assets_url() );

		return new Cancel_Modal( $names, $adapter, $assets_url, $plugin->get_version() );
	}

	/**
	 * Returns the URL of the page that makes labels and documents.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin  Plugin.
	 * @param Marketplace_Adapter                              $adapter Adapter.
	 * @return string Empty when the adapter has no label calls.
	 */
	private static function labels_url( $plugin, Marketplace_Adapter $adapter ): string {
		if ( null === $adapter->label_provider() || ! $adapter->supports( Capability::GET_LABEL ) ) {
			return '';
		}

		return $plugin->get_marketplace_page_url( Site_Orders_Module::PAGE );
	}
}

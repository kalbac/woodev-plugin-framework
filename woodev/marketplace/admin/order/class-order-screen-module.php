<?php
/**
 * Wires the read-only order screen of a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Readiness;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every marketplace plugin the read-only order screen ({@see Order_Screen}) with its details box ({@see Order_Details_Box}),
 * whatever else its adapter offers: the cancel button comes with an {@see Order_Canceller}, the label button with label calls.
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
		$assets   = self::assets_url( $plugin );
		$box      = new Order_Details_Box( $names, $adapter, [ $registry, 'status_of_order' ], self::cancel_modal( $plugin, $adapter, $names, $assets ), self::label_button( $plugin, $adapter, $registry, $assets ), $assets, $plugin->get_version() );

		( new Order_Screen( $adapter->order_meta(), $box ) )->register();
	}

	/**
	 * Builds the cancel button of an adapter that can cancel.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin  Plugin.
	 * @param Marketplace_Adapter                              $adapter Adapter.
	 * @param Screen_Names                                     $names   Names.
	 * @param string                                           $assets  Assets folder URL.
	 * @return Cancel_Modal|null
	 */
	private static function cancel_modal( $plugin, Marketplace_Adapter $adapter, Screen_Names $names, string $assets ): ?Cancel_Modal {
		if ( ! $adapter instanceof Order_Canceller ) {
			return null;
		}

		return new Cancel_Modal( $names, $adapter, $assets, $plugin->get_version() );
	}

	/**
	 * Returns the folder URL of the module's assets, with a trailing slash.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin Plugin.
	 * @return string
	 */
	private static function assets_url( $plugin ): string {
		return (string) preg_replace( '#/assets/?$#', '/marketplace/assets/', $plugin->get_framework_assets_url() );
	}

	/**
	 * Builds the label button over the plugin's «Заказы» records table, the table that makes the labels.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin   Plugin.
	 * @param Marketplace_Adapter                              $adapter  Adapter.
	 * @param Order_Registry                                   $registry The postings registry.
	 * @param string                                           $assets   Assets folder URL.
	 * @return Label_Button|null Null when the adapter has no label calls or the plugin has no table to ask them of.
	 */
	private static function label_button( $plugin, Marketplace_Adapter $adapter, Order_Registry $registry, string $assets ): ?Label_Button {
		if ( null === $adapter->label_provider() || ! $adapter->supports( Capability::GET_LABEL ) ) {
			return null;
		}

		if ( '' === $plugin->get_marketplace_page_url( Site_Orders_Module::PAGE ) || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $plugin->get_id() ) ) {
			return null;
		}

		$table_url = rest_url( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE . '/admin/records/' . $plugin->get_id() . '/' . Site_Orders_Provider::TABLE );

		$readiness = $adapter instanceof Label_Readiness ? $adapter : null;

		return new Label_Button( $table_url, [ $registry, 'posting_of_order' ], new Label_Availability( $readiness ), $assets, $plugin->get_version() );
	}
}

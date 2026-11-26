<?php
/**
 * Wires the read-only order screen of a marketplace plugin.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Guard\Admin_Guard;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Labels;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Provider;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every marketplace plugin the read-only order screen ({@see Order_Screen}) with its details box ({@see Order_Details_Box}),
 * whatever else its adapter offers: the cancel button comes with an {@see Order_Canceller}, the label button with label calls, the
 * seller action buttons and their AJAX with {@see Seller_Actions}.
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
	 * @param Wpdb_Account_Store|null                          $accounts   Accounts, to tell whether the account of a posting can write (since 2.1.17); null judges no account.
	 * @param callable|null                                    $write_gate `function ( Account $account ): bool|string`, the plugin's write gate (since 2.1.17; a string refuses and is the reason shown, since 2.1.19); null lets every account write.
	 * @return void
	 */
	public static function register( $plugin, \wpdb $db, ?Wpdb_Account_Store $accounts = null, ?callable $write_gate = null ): void {
		if ( ! is_admin() || isset( self::$registered[ $plugin->get_id() ] ) ) {
			return;
		}

		self::$registered[ $plugin->get_id() ] = true;

		$adapter    = $plugin->get_marketplace_adapter();
		$descriptor = $adapter->settings_descriptor();
		$names      = new Screen_Names( $adapter->id(), $descriptor->marketplace_name(), Posting_Noun::for_descriptor( $descriptor ) );
		$registry   = new Order_Registry( $db, ( new Schema( $adapter->id() ) )->table_name( 'postings', $db->prefix ) );
		$assets     = self::assets_url( $plugin );
		$gate       = Posting_Action_Gate::for_adapter( $adapter, $write_gate );
		$account_of = static function ( int $account_id ) use ( $accounts ): ?Account {
			return null === $accounts ? null : $accounts->get( $account_id );
		};
		$seller     = self::seller_actions( $plugin, $adapter, $registry, $names, $assets, $gate, $account_of, $accounts );
		$box        = new Order_Details_Box( $names, $adapter, [ $registry, 'status_of_order' ], self::cancel_modal( $plugin, $adapter, $names, $assets, self::cancel_unavailable( $registry, $gate, $account_of ) ), self::label_button( $plugin, $adapter, $registry, $assets, $gate, $account_of, $db ), $assets, $plugin->get_version(), $seller );

		( new Order_Screen( $adapter->order_meta(), $box ) )->register();
	}

	/**
	 * Builds the seller action buttons of an adapter that declares actions, and hooks the AJAX they send.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin     Plugin.
	 * @param Marketplace_Adapter                              $adapter    Adapter.
	 * @param Order_Registry                                   $registry   The postings registry.
	 * @param Screen_Names                                     $names      Names.
	 * @param string                                           $assets     Assets folder URL.
	 * @param Posting_Action_Gate                              $gate       The write rule.
	 * @param callable                                         $account_of `function ( int $account_id ): ?Account`.
	 * @param Wpdb_Account_Store|null                          $accounts   Accounts; null offers no seller action.
	 * @return Seller_Action_Buttons|null
	 */
	private static function seller_actions( $plugin, Marketplace_Adapter $adapter, Order_Registry $registry, Screen_Names $names, string $assets, Posting_Action_Gate $gate, callable $account_of, ?Wpdb_Account_Store $accounts ): ?Seller_Action_Buttons {
		if ( ! $adapter instanceof Seller_Actions || null === $accounts ) {
			return null;
		}

		$log    = static function ( string $line ) use ( $plugin ): void {
			$plugin->log_error( $line );
		};
		$runner = new Seller_Action_Runner( $adapter, $gate, License_Gate::for_plugin( $plugin ), $names->name(), $log );

		( new Seller_Action_Ajax( Admin_Guard::for_store( $accounts, $adapter->id() ), $accounts, $adapter->order_meta(), $runner, $names, [ $registry, 'posting_of_order' ] ) )->register();

		$account_of_order = static function ( int $order_id ) use ( $registry, $account_of ): ?Account {
			$posting = $registry->posting_of_order( $order_id );

			return null === $posting ? null : call_user_func( $account_of, $posting['account_id'] );
		};

		return new Seller_Action_Buttons( $names, $runner, $account_of_order, $assets, $plugin->get_version() );
	}

	/**
	 * Builds the cancel button of an adapter that can cancel.
	 *
	 * @param \Woodev\Framework\Marketplace\Marketplace_Plugin $plugin  Plugin.
	 * @param Marketplace_Adapter                              $adapter Adapter.
	 * @param Screen_Names                                     $names   Names.
	 * @param string                                           $assets  Assets folder URL.
	 * @param callable                                         $unavailable `function ( \WC_Order $order ): string`, why cancelling is off for an order; empty when it is on.
	 * @return Cancel_Modal|null
	 */
	private static function cancel_modal( $plugin, Marketplace_Adapter $adapter, Screen_Names $names, string $assets, callable $unavailable ): ?Cancel_Modal {
		if ( ! $adapter instanceof Order_Canceller ) {
			return null;
		}

		return new Cancel_Modal( $names, $adapter, $assets, $plugin->get_version(), $unavailable );
	}

	/**
	 * Builds the answer to «why can this order not be cancelled now»: the account's key cannot write, or the marketplace does not allow
	 * the cancellation of the posting.
	 *
	 * @param Order_Registry      $registry   The postings registry.
	 * @param Posting_Action_Gate $gate       The rule.
	 * @param callable            $account_of `function ( int $account_id ): ?Account`.
	 * @return callable `function ( \WC_Order $order ): string`.
	 */
	private static function cancel_unavailable( Order_Registry $registry, Posting_Action_Gate $gate, callable $account_of ): callable {
		return static function ( \WC_Order $order ) use ( $registry, $gate, $account_of ): string {
			$posting = $registry->posting_of_order( $order->get_id() );
			$account = null === $posting ? null : call_user_func( $account_of, $posting['account_id'] );
			$refusal = $account instanceof Account ? $gate->write_refusal( $account ) : '';

			return '' !== $refusal ? $refusal : $gate->cancel_reason( $order );
		};
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
	 * @param Posting_Action_Gate                              $gate       Whether a label can be asked for a posting.
	 * @param callable                                         $account_of `function ( int $account_id ): ?Account`.
	 * @param \wpdb                                            $db         Database.
	 * @return Label_Button|null Null when the adapter has no label calls or the plugin has no table to ask them of.
	 */
	private static function label_button( $plugin, Marketplace_Adapter $adapter, Order_Registry $registry, string $assets, Posting_Action_Gate $gate, callable $account_of, \wpdb $db ): ?Label_Button {
		if ( null === $adapter->label_provider() || ! $adapter->supports( Capability::GET_LABEL ) ) {
			return null;
		}

		if ( '' === $plugin->get_marketplace_page_url( Site_Orders_Module::PAGE ) || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $plugin->get_id() ) ) {
			return null;
		}

		$table_url = rest_url( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE . '/admin/records/' . $plugin->get_id() . '/' . Site_Orders_Provider::TABLE );

		$schema = new Schema( $adapter->id() );
		// Only `covering()` is used: the labels part is the table's own reader of stored documents, so the button and the table agree.
		$labels = new Site_Orders_Labels(
			$db,
			$schema->table_name( 'tasks', $db->prefix ),
			$schema->table_name( 'documents', $db->prefix ),
			static function (): string {
				return '';
			},
			static function (): int {
				return 0;
			}
		);

		$label_stored = static function ( int $account_id, string $external_key ) use ( $labels ): bool {
			return $labels->covering( $account_id, [ $external_key ] ) > 0;
		};

		return new Label_Button( $table_url, [ $registry, 'posting_of_order' ], $gate, $account_of, $assets, $plugin->get_version(), $label_stored );
	}
}

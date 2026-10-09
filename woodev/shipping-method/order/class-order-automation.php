<?php
/**
 * Woodev Order Automation
 *
 * What the framework does on its own when a WooCommerce order changes status (#1007, spec §12):
 * exports the order to its carrier in the background when it enters a status the merchant picked for
 * auto-export, and cancels the carrier's shipment in the background when the order is cancelled or fully
 * refunded.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Order_Automation' ) ) :

	/**
	 * Reacts to `woocommerce_order_status_changed` — the single trigger, which `payment_complete()` and a
	 * manager's save both go through — and to the cancellation action it queues.
	 *
	 * NEVER calls a carrier inside the status change: the buyer's thank-you page and the manager's save
	 * must not wait for an API. The status change only QUEUES work on the Action Scheduler group the
	 * delayed export retry already uses ({@see Export_Retry}, {@see Carrier_Cancel}); the export runs
	 * through the same runner and the same per-order lock as a retry
	 * ({@see Orders_Registry::run_export_retry()}), the cancellation under the export lock of #945
	 * ({@see Abstract_Shipment_Handler::cancel_under_lock()}). The order of a carrier is found through the
	 * registry that already maps an order to it, and the merchant's per-carrier choice is read off that
	 * carrier's «Выгрузка» settings ({@see \Woodev\Framework\Shipping\Settings\Export_Settings}, on the plugin's own
	 * tab of the `woodev-settings` page).
	 *
	 * @since 2.0.2
	 */
	final class Order_Automation {

		/**
		 * The setting (toggle) that switches auto-export on. The key of the shipped v1 carrier plugins
		 * (they kept it in their WooCommerce integration option), kept byte-for-byte: a v1 site that had
		 * it on keeps it on — {@see \Woodev\Framework\Shipping\Settings\Export_Settings} carries the value over.
		 *
		 * @var string
		 */
		public const SETTING_AUTO_EXPORT = 'auto_export_orders';

		/**
		 * The setting (multiselect) that lists the WooCommerce statuses auto-export fires on.
		 * The key of the shipped v1 carrier plugins, kept byte-for-byte; the values are status slugs with
		 * the `wc-` prefix, as `wc_get_order_statuses()` keys them.
		 *
		 * @var string
		 */
		public const SETTING_EXPORT_STATUSES = 'export_statuses';

		/** @var string[] statuses a WooCommerce order is cancelled at the carrier in: cancelled, and a FULL refund */
		public const CANCEL_STATUSES = [ 'cancelled', 'refunded' ];

		/** @var Orders_Registry */
		private Orders_Registry $registry;

		/**
		 * @param Orders_Registry $registry the registry that maps an order to its carrier and handler.
		 */
		public function __construct( Orders_Registry $registry ) {
			$this->registry = $registry;
		}

		public function auto_export_statuses( Orders_Provider $provider ): array {

			$plugin = $this->registry->get_provider_plugin( $provider->get_id() );

			if ( null === $plugin ) {
				return [];
			}

			$settings = $plugin->get_export_settings();

			return $settings->is_auto_export_enabled() ? $settings->get_export_statuses() : [];
		}

		/**
		 * The callback of `woocommerce_order_status_changed`: queues the export or the cancellation the
		 * new status calls for. Does nothing for an order of no registered carrier.
		 *
		 * @since 2.0.2
		 *
		 * @param int|string     $order_id the order.
		 * @param string         $from     the status it left, without the `wc-` prefix.
		 * @param string         $to       the status it entered, without the `wc-` prefix.
		 * @param \WC_Order|null $order    the order, when WooCommerce passes it.
		 * @return void
		 */
		public function handle_status_change( $order_id, $from = '', $to = '', $order = null ): void {

			// The carrier itself reported the shipment cancelled and the order follows it (#1203): telling the
			// carrier to cancel what it just cancelled would loop, and a status picked by the merchant's settings
			// is no reason to export either.
			if ( Cancelled_Order_Status::is_applying() ) {
				return;
			}

			$order = $order instanceof \WC_Order ? $order : wc_get_order( absint( $order_id ) );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$provider = $this->registry->resolve_provider_for_order( $order );

			if ( null === $provider || null === $this->registry->get_shipment_handler( $provider->get_id() ) ) {
				return;
			}

			if ( in_array( (string) $to, self::CANCEL_STATUSES, true ) ) {
				$this->queue_cancellation( $order, $provider );

				return;
			}

			$this->queue_auto_export( $order, $provider, (string) $to );
		}

		/**
		 * Queues the background export of an order that entered a status the merchant picked — once.
		 *
		 * Idempotent by construction: an exported order is left alone, and
		 * {@see Export_Retry::enqueue()} does not add a second waiting action for an order that has one.
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider its carrier.
		 * @param string          $status   the status it entered.
		 * @return void
		 */
		private function queue_auto_export( \WC_Order $order, Orders_Provider $provider, string $status ): void {

			if ( ! in_array( $status, $this->auto_export_statuses( $provider ), true ) ) {
				return;
			}

			if ( Order_Actions::is_exported( $order, $provider ) ) {
				return;
			}

			Export_Retry::enqueue( $order->get_id(), 0 );
		}

		/**
		 * The order was cancelled or fully refunded: no export may still come for it, and a shipment it
		 * has at the carrier is cancelled there in the background.
		 *
		 * The cancellation is queued for an exported order, and for one whose export is RUNNING right now
		 * — that export is about to store a carrier id the status change cannot see, and the queued
		 * cancellation finds it ({@see Abstract_Shipment_Handler::cancel_under_lock()} waits its turn on
		 * the export lock). An order that was never exported costs nothing.
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider its carrier.
		 * @return void
		 */
		private function queue_cancellation( \WC_Order $order, Orders_Provider $provider ): void {

			Export_Retry::cancel_pending( $order->get_id() );

			if ( Order_Actions::is_exported( $order, $provider ) || Export_Retry::is_running( $order->get_id() ) ) {
				Carrier_Cancel::enqueue( $order->get_id(), 0 );
			}
		}

		/**
		 * The callback of the Action Scheduler action {@see Carrier_Cancel} queues: cancels the
		 * shipment of a cancelled / fully refunded order at its carrier and says what happened on the
		 * order — a private note, and the «not cancelled at the carrier» marker when the carrier refused.
		 *
		 * The WooCommerce order stays cancelled in every case. The gate is the «Отменить» button's own
		 * ({@see Order_Actions::can_cancel()}): a shipment already delivered, returned, cancelled or failed
		 * is not asked to cancel, and the merchant is told to settle it with the carrier.
		 *
		 * @since 2.0.2
		 *
		 * @param int|string $order_id the order (Action Scheduler passes the stored argument as is).
		 * @return void
		 */
		public function run_cancel( $order_id ): void {

			$order = wc_get_order( absint( $order_id ) );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			if ( ! in_array( $order->get_status(), self::CANCEL_STATUSES, true ) ) {
				// Restored since: a revived order keeps its shipment.
				Carrier_Cancel::forget_deferrals( $order );

				return;
			}

			$provider = $this->registry->resolve_provider_for_order( $order );

			if ( null === $provider ) {
				return;
			}

			$exported = Order_Actions::is_exported( $order, $provider );
			$handler  = $this->registry->get_shipment_handler( $provider->get_id() );

			if ( null === $handler ) {

				if ( $exported ) {
					$order->add_order_note( __( 'Отменить заявку у перевозчика не удалось: перевозчик не найден (плагин отключён?)', 'woodev-plugin-framework' ) );
				}

				return;
			}

			if ( $exported && ! ( new Order_Actions( $this->registry ) )->can_cancel( $order, $provider ) ) {
				Carrier_Cancel::forget_deferrals( $order );
				$order->add_order_note(
					sprintf(
						/* translators: %s: the shipment's delivery status at the carrier, e.g. "Доставлено" */
						__( 'Отменить заявку у перевозчика нельзя (статус «%s») — свяжитесь с перевозчиком', 'woodev-plugin-framework' ),
						Delivery_Status::label( Order_Actions::resolve_canonical_status( $order, $provider ) )
					)
				);

				return;
			}

			$result = $handler->cancel_under_lock( $order );

			if ( null === $result ) {
				Carrier_Cancel::forget_deferrals( $order ); // never exported: nothing is alive at the carrier.

				return;
			}

			if ( $result->is_busy() ) {
				$this->put_back( $order, $provider );

				return;
			}

			$this->report( $order, $provider, $result );
		}

		/**
		 * Books the carrier's answer to a cancellation on the order.
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider its carrier.
		 * @param Action_Result   $result   what {@see Abstract_Shipment_Handler::cancel_under_lock()} answered.
		 * @return void
		 */
		private function report( \WC_Order $order, Orders_Provider $provider, Action_Result $result ): void {

			$order = $this->reread( $order );

			Carrier_Cancel::forget_deferrals( $order );

			if ( $result->is_success() ) {
				Carrier_Cancel::clear_failed( $order );
				$order->add_order_note( __( 'Заявка отменена у перевозчика', 'woodev-plugin-framework' ) );

				return;
			}

			// The shipment is still alive at the carrier; its id is what the marker is bound to.
			Carrier_Cancel::mark_failed( $order, self::carrier_order_id( $order, $provider ) );
			$order->add_order_note(
				sprintf(
					/* translators: %s: the carrier's reason for refusing the cancellation */
					__( 'Не удалось отменить заявку у перевозчика: %s', 'woodev-plugin-framework' ),
					'' !== $result->get_message() ? $result->get_message() : __( 'перевозчик не назвал причину', 'woodev-plugin-framework' )
				)
			);
		}

		/**
		 * The order is busy (an export holds its lock): the cancellation is put back for a minute, and
		 * once it has been put back too many times the merchant is told to look at the order.
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider its carrier.
		 * @return void
		 */
		private function put_back( \WC_Order $order, Orders_Provider $provider ): void {

			$fresh = $this->reread( $order );

			if ( Carrier_Cancel::defer( $fresh ) ) {
				return;
			}

			Carrier_Cancel::forget_deferrals( $fresh );
			$fresh->add_order_note( __( 'Не удалось отменить заявку у перевозчика: заказ слишком долго выгружается. Проверьте заявку в личном кабинете перевозчика.', 'woodev-plugin-framework' ) );

			$carrier_order_id = self::carrier_order_id( $fresh, $provider );

			if ( '' !== $carrier_order_id ) {
				Carrier_Cancel::mark_failed( $fresh, $carrier_order_id );
			}
		}

		/**
		 * The carrier order id the order stores for its carrier; '' when it has none.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider its carrier.
		 * @return string
		 */
		public static function carrier_order_id( \WC_Order $order, Orders_Provider $provider ): string {

			$meta_key = $provider->get_carrier_order_id_meta_key();

			return null === $meta_key ? '' : (string) \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key );
		}

		/**
		 * The order as the datastore holds it NOW — the carrier call may have just rewritten its meta
		 * through another object ({@see Abstract_Shipment_Handler::cancel_under_lock()} works on a fresh one).
		 *
		 * @param \WC_Order $order the order.
		 * @return \WC_Order
		 */
		private function reread( \WC_Order $order ): \WC_Order {

			wp_cache_delete( $order->get_id(), 'post_meta' );
			$order->read_meta_data( true );

			return $order;
		}
	}

endif;

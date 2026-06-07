<?php
/**
 * Woodev Abstract Shipment Handler
 *
 * Base class for the order→carrier shipment lifecycle: export an order to the
 * carrier, persist the carrier-assigned order id, and cancel a shipment. It
 * drives the carrier seam {@see \Woodev\Framework\Shipping\Shipping_API::create_order()}
 * / {@see \Woodev\Framework\Shipping\Shipping_API::cancel_order()} and routes the
 * carrier-assigned id through {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler}
 * so it is stored under the plugin's own installed-site order-meta key. The
 * carrier's raw response shape never leaks past this class: each concrete carrier
 * implements only {@see self::extract_carrier_order_id()}.
 *
 * A failed export is not lost. The export is re-queued through the plugin's
 * {@see \Woodev_Background_Job_Handler} so it is retried out-of-band. The retry
 * job is enqueued in the exact shape that handler consumes — a job whose `data`
 * key is the array {@see \Woodev_Background_Job_Handler::process_job()} iterates,
 * handing each item to `process_item()`. One retry is one order id in that data
 * array; enqueuing any other shape (e.g. `['order_id' => …]`) persists a job whose
 * `data` key is unset, which `process_job()` rejects before any item runs — the
 * retry would then never fire. See docs-internal/gotchas if this regresses.
 *
 * The background-job id is built from the plugin-supplied handler's own identifier
 * (prefix = plugin id); the framework introduces no installed-site job id literal.
 * Lifecycle events are broadcast through forward-only, plugin-namespaced action
 * hooks (`woodev_shipping_{prefix}_shipment_*`); no installed-site contract string
 * — no shipping-method id, no existing hook name, no meta key — is introduced here.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.3.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Shipping_API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Abstract_Shipment_Handler' ) ) :

	/**
	 * Exports, persists, cancels, and retries a shipment against a carrier.
	 *
	 * A carrier constructs the handler with the API seam, the order-meta handler,
	 * and the plugin's background-job handler used to retry a failed export.
	 * Concrete carriers implement only the response→carrier-id mapping
	 * ({@see self::extract_carrier_order_id()}); everything else is carrier-neutral.
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Shipment_Handler {

		/** @var string logical order-meta field, resolved by the order handler to the plugin's real carrier-order-id meta key */
		protected const CARRIER_ORDER_ID_FIELD = 'carrier_order_id';

		/** @var Shipping_API carrier API seam */
		protected Shipping_API $api;

		/** @var Shipping_Order_Handler HPOS-safe order-meta accessor for the plugin's keys */
		protected Shipping_Order_Handler $order_handler;

		/** @var \Woodev_Background_Job_Handler plugin-supplied queue used to retry a failed export */
		protected \Woodev_Background_Job_Handler $retry_handler;

		/** @var string plugin-supplied token that namespaces this handler's forward hooks */
		protected string $hook_prefix;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param Shipping_API                   $api           carrier API used to create/cancel orders
		 * @param Shipping_Order_Handler         $order_handler order-meta accessor that persists the carrier id under the plugin's key
		 * @param \Woodev_Background_Job_Handler $retry_handler plugin's background-job queue used to retry a failed export
		 * @param string                         $hook_prefix   plugin-supplied token (e.g. the plugin id) that namespaces forward hooks; defaults to none
		 */
		public function __construct( Shipping_API $api, Shipping_Order_Handler $order_handler, \Woodev_Background_Job_Handler $retry_handler, string $hook_prefix = '' ) {
			$this->api           = $api;
			$this->order_handler = $order_handler;
			$this->retry_handler = $retry_handler;
			$this->hook_prefix   = $hook_prefix;
		}

		/**
		 * Exports an order to the carrier and persists the carrier-assigned id.
		 *
		 * Calls {@see Shipping_API::create_order()}, maps the response to the carrier
		 * order id via {@see self::extract_carrier_order_id()}, and stores it through
		 * the order handler under the plugin's own meta key. A carrier/network failure
		 * is not lost: the export is re-queued via {@see self::schedule_retry()} and an
		 * empty id is returned, so the caller can tell the export did not complete now.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order the order to export to the carrier
		 * @return string the carrier-assigned order id, or '' when the export failed and was queued for retry
		 */
		public function export( \WC_Order $order ): string {

			try {
				$response = $this->api->create_order( $order );
			} catch ( \Woodev_API_Exception $exception ) {

				$this->schedule_retry( $order );

				/**
				 * Fires when an order export to the carrier fails and is queued for retry.
				 *
				 * @since 1.5.0
				 *
				 * @param \WC_Order            $order     the order whose export failed
				 * @param \Woodev_API_Exception $exception the carrier/network failure
				 */
				do_action( $this->hook( 'shipment_export_failed' ), $order, $exception );

				return '';
			}

			$carrier_order_id = $this->extract_carrier_order_id( $response );

			$this->order_handler->set( $order, static::CARRIER_ORDER_ID_FIELD, $carrier_order_id );

			/**
			 * Fires after an order is successfully exported to the carrier.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order $order            the exported order
			 * @param string    $carrier_order_id the carrier-assigned order id now stored on the order
			 */
			do_action( $this->hook( 'shipment_exported' ), $order, $carrier_order_id );

			return $carrier_order_id;
		}

		/**
		 * Cancels an order's shipment with the carrier.
		 *
		 * Reads the stored carrier order id through the order handler and calls
		 * {@see Shipping_API::cancel_order()}. Returns false (without calling the
		 * carrier) when the order has no stored carrier id, and false when the carrier
		 * rejects the cancellation.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order the order whose shipment to cancel
		 * @return bool true when the carrier accepted the cancellation, false otherwise
		 */
		public function cancel( \WC_Order $order ): bool {

			$carrier_order_id = (string) $this->order_handler->get( $order, static::CARRIER_ORDER_ID_FIELD );

			if ( '' === $carrier_order_id ) {
				return false;
			}

			try {
				$this->api->cancel_order( $carrier_order_id );
			} catch ( \Woodev_API_Exception $exception ) {

				/**
				 * Fires when a shipment cancellation request to the carrier fails.
				 *
				 * @since 1.5.0
				 *
				 * @param \WC_Order            $order     the order whose cancellation failed
				 * @param \Woodev_API_Exception $exception the carrier/network failure
				 */
				do_action( $this->hook( 'shipment_cancel_failed' ), $order, $exception );

				return false;
			}

			/**
			 * Fires after a shipment is successfully cancelled with the carrier.
			 *
			 * @since 1.5.0
			 *
			 * @param \WC_Order $order            the cancelled order
			 * @param string    $carrier_order_id the carrier-assigned order id that was cancelled
			 */
			do_action( $this->hook( 'shipment_cancelled' ), $order, $carrier_order_id );

			return true;
		}

		/**
		 * Queues a failed export for out-of-band retry.
		 *
		 * The job is created in the exact shape {@see \Woodev_Background_Job_Handler}
		 * consumes: its `data` key is the array {@see \Woodev_Background_Job_Handler::process_job()}
		 * iterates, passing each entry to `process_item()`. A single retry is therefore
		 * one order id inside that `data` array — NOT a flat `['order_id' => …]`, which
		 * leaves `data` unset and makes `process_job()` throw before any retry runs.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order the order to re-export on the next queue run
		 * @return void
		 */
		protected function schedule_retry( \WC_Order $order ): void {

			$this->retry_handler->create_job( array( 'data' => array( $order->get_id() ) ) );

			$this->retry_handler->dispatch();
		}

		/**
		 * Maps a carrier create-order response to the carrier-assigned order id.
		 *
		 * Each carrier returns the id in a different place in its response, so the
		 * concrete handler extracts it; the base class only knows the result is the
		 * string id to persist on the order.
		 *
		 * @since 1.5.0
		 *
		 * @param \Woodev_API_Response $response the create-order response from the carrier
		 * @return string the carrier-assigned order id
		 */
		abstract protected function extract_carrier_order_id( \Woodev_API_Response $response ): string;

		/**
		 * Builds a namespaced forward-hook name.
		 *
		 * @since 1.5.0
		 *
		 * @param string $name bare hook suffix
		 * @return string the full hook name, e.g. `woodev_shipping_{prefix}_{name}`
		 */
		protected function hook( string $name ): string {

			$prefix = '' !== $this->hook_prefix ? $this->hook_prefix . '_' : '';

			return 'woodev_shipping_' . $prefix . $name;
		}
	}

endif;

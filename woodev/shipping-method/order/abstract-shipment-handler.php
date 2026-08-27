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

use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
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
		 * Popular-settlements store (#488) used to bump usage on a successful
		 * export.
		 *
		 * Always non-null after construction (round 3, HIGH 1): when the caller
		 * does not inject one, the constructor defaults to the framework's own
		 * shared instance — {@see Location_Provider_Registry::popular_settlement_store()}
		 * — instead of `null`. No production construction site in this repo ever
		 * supplies a store (a carrier plugin constructs both this class and
		 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order}, and the
		 * framework structurally cannot inject the store at that call site), so an
		 * optional-but-null-means-off dependency left the feature permanently
		 * unreachable. `$settlement`/`$provider` on {@see self::export()} remain
		 * independently optional and default to null, so a caller that never
		 * passes them still sees no behaviour change — only a caller that DOES
		 * resolve them (the one real framework caller,
		 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::handle_order_action()})
		 * now enrols out of the box.
		 *
		 * @var Popular_Settlement_Store
		 */
		protected Popular_Settlement_Store $popular_settlement_store;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `$popular_settlement_store` (#488 slice 2) — optional,
		 *              defaults to null so an existing call site's behaviour is unchanged.
		 * @since 2.0.2 Round 3 (HIGH 1): a null/omitted `$popular_settlement_store`
		 *              now defaults to the framework's shared instance
		 *              ({@see Location_Provider_Registry::popular_settlement_store()})
		 *              instead of leaving enrolment permanently disabled — an
		 *              explicit instance remains a genuine override (tests, or a
		 *              plugin that wants its own).
		 *
		 * @param Shipping_API                   $api                      carrier API used to create/cancel orders
		 * @param Shipping_Order_Handler         $order_handler            order-meta accessor that persists the carrier id under the plugin's key
		 * @param \Woodev_Background_Job_Handler $retry_handler            plugin's background-job queue used to retry a failed export
		 * @param string                         $hook_prefix              plugin-supplied token (e.g. the plugin id) that namespaces forward hooks; defaults to none
		 * @param Popular_Settlement_Store|null  $popular_settlement_store popular-settlements store used to bump usage on a successful export; null resolves the framework's shared instance
		 */
		public function __construct(
			Shipping_API $api,
			Shipping_Order_Handler $order_handler,
			\Woodev_Background_Job_Handler $retry_handler,
			string $hook_prefix = '',
			?Popular_Settlement_Store $popular_settlement_store = null
		) {
			$this->api                      = $api;
			$this->order_handler            = $order_handler;
			$this->retry_handler            = $retry_handler;
			$this->hook_prefix              = $hook_prefix;
			$this->popular_settlement_store = $popular_settlement_store ?? Location_Provider_Registry::instance()->popular_settlement_store();
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
		 * A successful export with a NON-EMPTY carrier order id is also "an order
		 * shipped to this settlement" (#488 popular-settlements spec D2) — the
		 * strongest available signal that the shop is genuinely committed to
		 * shipping there, stronger than merely placing the order (which can still be
		 * cancelled/refunded before ever reaching a carrier). When both `$settlement`
		 * and `$provider` are given, {@see self::enroll_popular_settlement()} bumps it
		 * (against the constructor's store — round 3, HIGH 1: a framework default
		 * now, not an opt-in) after the `shipment_exported` hook fires. Both default
		 * to null: an existing call site that does not pass them sees no behaviour
		 * change.
		 *
		 * A response that does not throw but still yields an EMPTY carrier id
		 * (round 2 critic finding, MEDIUM 3) is explicitly NOT evidence the shop
		 * shipped anywhere — an order-meta write and the `shipment_exported` hook
		 * still both fire as before (that is existing, unrelated behaviour this fix
		 * does not touch), but enrolment is skipped.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Added `$settlement` / `$provider` (#488 slice 2) to enrol the
		 *              order's settlement into the popular-settlements list.
		 * @since 2.0.2 Round 2 (MEDIUM 3): enrolment additionally requires a
		 *              non-empty `$carrier_order_id` — a non-throwing response with
		 *              no id is not evidence of a real export.
		 *
		 * @param \WC_Order              $order      the order to export to the carrier
		 * @param Location_Record|null   $settlement the settlement this order ships to, if known; null skips enrolment
		 * @param Location_Provider|null $provider the provider that produced `$settlement`, if known; null skips enrolment
		 * @return string the carrier-assigned order id, or '' when the export failed and was queued for retry
		 */
		public function export( \WC_Order $order, ?Location_Record $settlement = null, ?Location_Provider $provider = null ): string {

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

			if ( '' !== $carrier_order_id ) {
				$this->enroll_popular_settlement( $settlement, $provider );
			}

			return $carrier_order_id;
		}

		/**
		 * Bumps the popular-settlements list (#488) for a successfully exported
		 * order's settlement.
		 *
		 * A silent no-op — never throws — whenever any prerequisite is missing:
		 * the caller does not know the settlement, or the caller does not know
		 * which provider produced it. The D4/D4a gates themselves
		 * ({@see Popular_Settlement_Store::CAPABILITY_RESOLVE_KEY} capability,
		 * {@see \Woodev\Framework\Shipping\Location\Locality_Key::is_derived()})
		 * live in {@see Popular_Settlement_Store::enroll()}, not here.
		 *
		 * `enroll()` itself can still throw — most notably when `$settlement`'s
		 * own `provider_id()` disagrees with `$provider->get_id()`. This is caught
		 * and logged, never left to propagate (round 3, HIGH 2): by the time this
		 * runs the carrier order already exists (`export()` has already persisted
		 * the carrier id and fired `shipment_exported`), so enrolment — a ranking
		 * side-effect — must never undo or fail a real export over a popularity
		 * row it could not write.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 3 (HIGH 2): swallow-and-log any `enroll()` failure
		 *              instead of letting it propagate out of {@see self::export()}.
		 *
		 * @param Location_Record|null   $settlement the settlement this order ships to, if known
		 * @param Location_Provider|null $provider   the provider that produced `$settlement`, if known
		 *
		 * @return void
		 */
		protected function enroll_popular_settlement( ?Location_Record $settlement, ?Location_Provider $provider ): void {
			if ( null === $settlement || null === $provider ) {
				return;
			}

			try {
				$this->popular_settlement_store->enroll( $provider, $settlement );
			} catch ( \Throwable $throwable ) {
				error_log(
					sprintf(
						'[woodev] popular-settlements enrolment failed for provider "%s": %s',
						$provider->get_id(),
						\Woodev_API_Base::redact_secret_log_text( $throwable->getMessage() )
					)
				); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- loud-but-contained boundary; enrolment is a ranking side-effect and must never undo/fail an export whose carrier order already exists.
			}
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

<?php
/**
 * Woodev_Realistic_Shipment_Handler — the realistic fixture's Abstract_Shipment_Handler (#710, bug 6).
 *
 * Until now this fixture registered a provider and a tracking handler, but no
 * `Abstract_Shipment_Handler` — so `Order_Actions::for_row()` (whose handler-null gate is
 * deliberately shared by every action it offers, including the client-side «Редактировать»,
 * see {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_row()}) showed NO row
 * actions at all for a `realistic`-carrier order: not export/cancel/update — expected, this
 * fixture never called the carrier — but not «Редактировать» either, even though the order
 * wizard's own load/update routes never required a handler to edit an order (spec D5). An
 * order the wizard created for this carrier (e.g. on-hold, not exported) was consequently
 * missing its edit row action while a `test_shipping` order in the same state had it, because
 * that sibling fixture (`class-test-shipment-handler.php`) already wires one.
 *
 * Offline and deterministic, the same shape as the sibling fixture's own handler — no network
 * call, ever, so the rig behaves the same on every click.
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Realistic_Shipping_Api_Response' ) ) {

	/**
	 * Minimal `Woodev_API_Response`: a bag of the fake carrier's own response fields.
	 */
	class Woodev_Realistic_Shipping_Api_Response implements \Woodev_API_Response {

		/** @var array<string,mixed> */
		private array $data;

		/**
		 * @param array<string,mixed> $data fake carrier response payload.
		 */
		public function __construct( array $data ) {
			$this->data = $data;
		}

		/**
		 * @param string $key response field name.
		 * @return mixed
		 */
		public function get( string $key ) {
			return $this->data[ $key ] ?? null;
		}

		/** @inheritDoc */
		public function to_string(): string {
			return (string) wp_json_encode( $this->data );
		}

		/** @inheritDoc */
		public function to_string_safe(): string {
			return $this->to_string();
		}
	}
}

if ( ! class_exists( 'Woodev_Realistic_Shipping_Api_Request' ) ) {

	/**
	 * Minimal `Woodev_API_Request` — this fake never actually builds a wire request, so
	 * `get_request()` below has nothing real to hand back; this satisfies the interface
	 * honestly rather than faking a request that was never sent.
	 */
	class Woodev_Realistic_Shipping_Api_Request implements \Woodev_API_Request {

		/** @inheritDoc */
		public function get_method(): string {
			return 'POST';
		}

		/** @inheritDoc */
		public function get_path(): string {
			return '';
		}

		/** @inheritDoc */
		public function to_string(): string {
			return '';
		}

		/** @inheritDoc */
		public function to_string_safe(): string {
			return '';
		}
	}
}

if ( ! class_exists( 'Woodev_Realistic_Shipping_Api' ) ) {

	/**
	 * Offline fake `Shipping_API`. Only `create_order()`/`cancel_order()` do anything real —
	 * the other methods exist only to satisfy the interface, since nothing on the «Заказы
	 * доставки» page calls them.
	 */
	class Woodev_Realistic_Shipping_Api implements \Woodev\Framework\Shipping\Api\Shipping_API {

		/** @var \Woodev_API_Response|null the most recent response, for get_response(). */
		private ?\Woodev_API_Response $last_response = null;

		/**
		 * Creates a shipping order. Deterministic and always accepted — offline.
		 *
		 * @inheritDoc
		 */
		public function create_order( \WC_Order $order ): \Woodev_API_Response {
			$this->last_response = new Woodev_Realistic_Shipping_Api_Response(
				[ 'order_id' => sprintf( 'REALISTIC-EXPORT-%06d', $order->get_id() ) ]
			);

			return $this->last_response;
		}

		/**
		 * Cancels a shipping order. Always accepts — offline and deterministic.
		 *
		 * @inheritDoc
		 */
		public function cancel_order( string $order_id ): \Woodev_API_Response {
			$this->last_response = new Woodev_Realistic_Shipping_Api_Response( [ 'cancelled' => true ] );

			return $this->last_response;
		}

		/** @inheritDoc */
		public function calculate_rates( array $params ): \Woodev_API_Response {
			return new Woodev_Realistic_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_pickup_points( array $params ): \Woodev_API_Response {
			return new Woodev_Realistic_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_order( string $order_id ): \Woodev_API_Response {
			return new Woodev_Realistic_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_tracking( string $tracking_number ): \Woodev_API_Response {
			return new Woodev_Realistic_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_request(): \Woodev_API_Request {
			return new Woodev_Realistic_Shipping_Api_Request();
		}

		/** @inheritDoc */
		public function get_response(): ?\Woodev_API_Response {
			return $this->last_response;
		}
	}
}

if ( ! class_exists( 'Woodev_Realistic_Shipment_Handler' ) ) {

	/**
	 * The fixture's concrete `Abstract_Shipment_Handler` (#710, bug 6): wires «Выгрузить» /
	 * «Отменить» for real, over the offline fake API above, so this carrier's rows behave like
	 * `test_shipping`'s — and, critically, so the order wizard's «Редактировать» row action
	 * (gated on a registered handler existing at all) is no longer silently withheld for it.
	 */
	class Woodev_Realistic_Shipment_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler {

		/** @inheritDoc */
		protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
			if ( ! $response instanceof Woodev_Realistic_Shipping_Api_Response ) {
				return '';
			}

			return (string) $response->get( 'order_id' );
		}

		/**
		 * #1204: like a real carrier that deletes an order only before it moves, this fixture declares the states
		 * the parcel is «handed to delivery» in — the background cancel of such an order sends no request.
		 *
		 * @inheritDoc
		 */
		public function get_handed_over_statuses(): array {
			return [
				\Woodev\Framework\Shipping\Order\Delivery_Status::IN_TRANSIT,
				\Woodev\Framework\Shipping\Order\Delivery_Status::READY_FOR_PICKUP,
				\Woodev\Framework\Shipping\Order\Delivery_Status::RETURNING,
			];
		}

		/** @inheritDoc */
		public function supports_refusal(): bool {
			return true;
		}

		/**
		 * Offline refusal: the parcel is simply recorded as returned (raw `RETURNED`), so the rig shows the whole
		 * path — the button, the paid-return confirmation, the order note and the status change.
		 *
		 * @inheritDoc
		 */
		public function refuse( \WC_Order $order ): \Woodev\Framework\Shipping\Order\Action_Result {
			\Woodev_Order_Compatibility::update_order_meta( $order, '_woodev_realistic_status', 'RETURNED' );
			$order->save();

			return \Woodev\Framework\Shipping\Order\Action_Result::success();
		}
	}
}

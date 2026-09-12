<?php
/**
 * Woodev_Test_Shipment_Handler — the rig's fixture Abstract_Shipment_Handler (card #824).
 *
 * No fixture ever built a concrete Abstract_Shipment_Handler, which is why nothing on
 * the rig had ever exercised export()/cancel()/update() — the «Выгрузить»/«Обновить»/
 * «Отменить» row buttons on the «Заказы доставки» page had nothing behind them. This
 * wires ONE, over a fake, entirely OFFLINE `Shipping_API` — no network call, ever, so
 * the rig behaves the same on every click.
 *
 * Extracted to its own file — same reasoning as `class-test-bulk-point-source.php`'s own
 * docblock — so a PHPUnit unit test can `require_once` it directly without going through
 * the fixture plugin's full Platform v2 load path.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Shipping_Api_Response' ) ) {

	/**
	 * Minimal `Woodev_API_Response`: a bag of the fake carrier's own response fields.
	 */
	class Woodev_Test_Shipping_Api_Response implements \Woodev_API_Response {

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

if ( ! class_exists( 'Woodev_Test_Shipping_Api_Request' ) ) {

	/**
	 * Minimal `Woodev_API_Request` — this fake never actually builds a wire request,
	 * so `get_request()` below has nothing real to hand back; this satisfies the
	 * interface honestly rather than faking a request that was never sent.
	 */
	class Woodev_Test_Shipping_Api_Request implements \Woodev_API_Request {

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

if ( ! class_exists( 'Woodev_Test_Shipping_Api' ) ) {

	/**
	 * Offline fake `Shipping_API` (card #824). Only `create_order()`/`cancel_order()`
	 * do anything real — the other methods exist only to satisfy the interface, since
	 * nothing on the «Заказы доставки» page calls them.
	 */
	class Woodev_Test_Shipping_Api implements \Woodev\Framework\Shipping\Api\Shipping_API {

		/**
		 * The rig's shared "this carrier can't handle it" trigger — the SAME real town
		 * {@see Woodev_Test_Location_Adapter::UNSERVED_SETTLEMENT} already uses for the
		 * location layer's own edge case, reused here rather than inventing a second
		 * magic value. An order shipping to «Урюпинск» demonstrates a carrier that
		 * accepted the export but returned NO order id (SP-10 #860) — the merchant
		 * reaches it by editing the order's shipping city to a real, memorable place,
		 * not by typing a string no real carrier response would ever contain.
		 *
		 * @var string
		 */
		public const NO_ID_TRIGGER_CITY = 'Урюпинск';

		/** @var \Woodev_API_Response|null the most recent response, for get_response(). */
		private ?\Woodev_API_Response $last_response = null;

		/**
		 * Creates a shipping order. Deterministic: a plausible carrier id on every
		 * order except one shipping to {@see self::NO_ID_TRIGGER_CITY}, which comes
		 * back with none — see that constant's own docblock.
		 *
		 * @inheritDoc
		 */
		public function create_order( \WC_Order $order ): \Woodev_API_Response {
			$has_id = self::NO_ID_TRIGGER_CITY !== $order->get_shipping_city();

			$this->last_response = new Woodev_Test_Shipping_Api_Response(
				$has_id ? [ 'order_id' => sprintf( 'TESTCARRIER-EXPORT-%06d', $order->get_id() ) ] : []
			);

			return $this->last_response;
		}

		/**
		 * Cancels a shipping order. Always accepts — offline and deterministic.
		 *
		 * @inheritDoc
		 */
		public function cancel_order( string $order_id ): \Woodev_API_Response {
			$this->last_response = new Woodev_Test_Shipping_Api_Response( [ 'cancelled' => true ] );

			return $this->last_response;
		}

		/** @inheritDoc */
		public function calculate_rates( array $params ): \Woodev_API_Response {
			return new Woodev_Test_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_pickup_points( array $params ): \Woodev_API_Response {
			return new Woodev_Test_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_order( string $order_id ): \Woodev_API_Response {
			return new Woodev_Test_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_tracking( string $tracking_number ): \Woodev_API_Response {
			return new Woodev_Test_Shipping_Api_Response( [] );
		}

		/** @inheritDoc */
		public function get_request(): \Woodev_API_Request {
			return new Woodev_Test_Shipping_Api_Request();
		}

		/** @inheritDoc */
		public function get_response(): ?\Woodev_API_Response {
			return $this->last_response;
		}
	}
}

if ( ! class_exists( 'Woodev_Test_Shipment_Retry_Job_Handler' ) ) {

	/**
	 * Background-job handler {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler}
	 * requires for a failed export's retry queue. Never actually dispatched by this
	 * fixture's own `Woodev_Test_Shipping_Api::create_order()`, which never throws —
	 * only present because the base class's constructor requires one.
	 */
	class Woodev_Test_Shipment_Retry_Job_Handler extends \Woodev_Background_Job_Handler {

		/** @var string */
		protected $prefix = 'woodev_test_shipping';

		/** @var string */
		protected $action = 'shipment_retry';

		/**
		 * @inheritDoc
		 */
		protected function process_item( $item, $job ) {
			return null;
		}
	}
}

if ( ! class_exists( 'Woodev_Test_Shipment_Handler' ) ) {

	/**
	 * The fixture's concrete `Abstract_Shipment_Handler` (card #824).
	 *
	 * `update()` cycles the order's own raw status forward through a fixed sequence —
	 * the same vocabulary {@see Woodev_Test_Shipping_Method_Plugin::init_test_shipping_orders_page()}
	 * declares in its `status_map` — so a merchant clicking «Обновить» repeatedly SEES
	 * the row's delivery-status badge advance, and eventually sees «Отменить» disappear
	 * once the canonical status reaches `delivered` (Order_Actions::for_order()'s own
	 * cancel gate) — the whole action-set gate observable from one fixture order.
	 */
	class Woodev_Test_Shipment_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler {

		/**
		 * The raw status sequence `update()` advances through — must agree with
		 * `init_test_shipping_orders_page()`'s own `status_map` keys.
		 *
		 * @var string[]
		 */
		private const STATUS_SEQUENCE = [ 'CREATED', 'PICKED_UP', 'ON_THE_WAY', 'ARRIVED_PVZ', 'HANDED_TO_CLIENT' ];

		/** @inheritDoc */
		protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
			if ( ! $response instanceof Woodev_Test_Shipping_Api_Response ) {
				return '';
			}

			return (string) $response->get( 'order_id' );
		}

		/** @inheritDoc */
		public function supports_update(): bool {
			return true;
		}

		/**
		 * Pulls the carrier's current state — see class docblock.
		 *
		 * @inheritDoc
		 */
		public function update( \WC_Order $order ): bool {
			$current = (string) $this->order_handler->get( $order, 'status' );

			$this->order_handler->set( $order, 'status', self::next_status( $current ) );

			return true;
		}

		/**
		 * The next raw status after `$current` in {@see self::STATUS_SEQUENCE} — the
		 * first entry when `$current` is unrecognised (never synced yet), the LAST
		 * entry (a terminal state) once already there.
		 *
		 * @param string $current current raw status, or '' when never synced.
		 * @return string
		 */
		private static function next_status( string $current ): string {
			$index = array_search( $current, self::STATUS_SEQUENCE, true );

			if ( false === $index ) {
				return self::STATUS_SEQUENCE[0];
			}

			return self::STATUS_SEQUENCE[ min( $index + 1, count( self::STATUS_SEQUENCE ) - 1 ) ];
		}
	}
}

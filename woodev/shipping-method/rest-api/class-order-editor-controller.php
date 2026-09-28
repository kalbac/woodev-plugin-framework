<?php
/**
 * Woodev Shipping Orders — order editor REST controller
 *
 * `POST woodev/v1/shipping/orders` (create), `PUT woodev/v1/shipping/orders/{id}` (update) and
 * `GET woodev/v1/shipping/orders/{id}/edit` (the wizard's prefill): the transport of the admin
 * order wizard (#710 spec D4 / D5, card #968). Registered through {@see \Woodev_REST_V1_Registrar}
 * by {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::register_rest()}, next to
 * {@see Orders_Controller}, and gated like that controller's write routes.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Order_Editor_Controller' ) ) :

	/**
	 * The admin order wizard's routes — a thin transport over {@see Order_Editor}.
	 *
	 * **Transport contract** (spec D4):
	 *
	 *  - **Auth** — the cookie session plus the `wp_rest` nonce the orders page bootstrap already
	 *    injects (`window.woodevShippingOrders.nonce`, sent as `X-WP-Nonce` by the page's `apiFetch`).
	 *  - **401** the request carries no logged-in user; **403** the user lacks `edit_shop_orders` —
	 *    both answered by WordPress itself from a failing `permission_callback`.
	 *  - **404** the order does not exist or is not a row of the orders page; **409** the order can
	 *    no longer be edited (exported, or in a final status — {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::is_editable()});
	 *    **422** the payload is invalid — `data.errors` is a list of `{ field, code, message }`,
	 *    `field` being the dotted path of the offending request field.
	 *  - **Bodies** — create → `201 { id, number, message }`, plus `export: { success, message }` when the
	 *    request carried `export_now` (D6: the export runs after the order is saved, the order stays
	 *    whatever the carrier answers, and `message` then reads «Заказ №N создан, но не выгружен. СДЭК: …»
	 *    — the carrier's text is for the merchant only); update → `200 { id, number, message }`
	 *    (`export_now` is ignored — an edit never exports, O4);
	 *    load → `200` the prefill in the same shape the create / update body takes, plus an `order`
	 *    block (`id`, `number`, `status`, `is_paid`, `total`, `currency`) the O14 warning reads.
	 *
	 * The payload's own shape is validated by the service ({@see \Woodev\Framework\Shipping\Admin\Orders\Order_Payload_Validator}),
	 * not by REST argument schemas: a schema mismatch would answer 400 and leave the contract above
	 * with two error formats.
	 *
	 * @since 2.0.2
	 */
	class Order_Editor_Controller extends \WP_REST_Controller {

		/**
		 * The request keys that make up an order payload — anything else in the body is dropped.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		private const PAYLOAD_KEYS = [
			'customer',
			'billing',
			'shipping',
			'items',
			'shipping_line',
			'pickup_point',
			'fields',
			'carrier_fields',
			'payment_method',
			'status',
		];

		/**
		 * The service.
		 *
		 * @since 2.0.2
		 *
		 * @var Order_Editor
		 */
		private $editor;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry   $registry orders registry, the providers' source.
		 * @param Order_Editor|null $editor   service; defaults to one built from `$registry`.
		 */
		public function __construct( Orders_Registry $registry, ?Order_Editor $editor = null ) {
			$this->editor = $editor ?? new Order_Editor( $registry );
		}

		/**
		 * Registers the three routes.
		 *
		 * `/shipping/orders` shares its path with {@see Orders_Controller}'s list route — WordPress
		 * merges the handlers of one route registered twice, keyed by HTTP method.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders',
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_order' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				]
			);

			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/(?P<id>\d+)',
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_order' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'id' => [ 'type' => 'integer' ],
					],
				]
			);

			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/(?P<id>\d+)/edit',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'load_order' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'id' => [ 'type' => 'integer' ],
					],
				]
			);
		}

		/**
		 * Permission gate: `edit_shop_orders`, the WooCommerce order-edit capability every
		 * shipping-orders write route requires ({@see Orders_Controller::perform_action_permissions_check()}).
		 * A missing login is answered 401 and a lacking capability 403 by WordPress itself.
		 *
		 * The gate is per ROUTE only: the object-level checks (is this order a row of the page, may it
		 * still be edited) live in the service and run on every `{id}` call.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function permissions_check( $request ): bool {
			return current_user_can( 'edit_shop_orders' );
		}

		public function create_order( $request ) {
			$order = $this->editor->create( self::payload( $request ) );

			if ( $order instanceof \WP_Error ) {
				return $order;
			}

			$body = [
				'id'      => (int) $order->get_id(),
				'number'  => (string) $order->get_order_number(),
				'message' => sprintf(
					/* translators: %s: order number. */
					__( 'Заказ №%s создан.', 'woodev-plugin-framework' ),
					$order->get_order_number()
				),
			];

			if ( self::wants_export( $request ) ) {
				// D6: the order is saved and stays whatever the carrier answers — the outcome
				// rides beside it, it never turns the response into an error.
				$export = $this->editor->export_created( $order );

				$body['export']  = $export;
				$body['message'] = $export['success']
					? sprintf(
						/* translators: 1: order number, 2: the carrier's note or the framework's own «Заказ выгружен перевозчику.». */
						__( 'Заказ №%1$s создан. %2$s', 'woodev-plugin-framework' ),
						$order->get_order_number(),
						$export['message']
					)
					: sprintf(
						/* translators: 1: order number, 2: why the export did not happen — the carrier's own text, prefixed with its name. */
						__( 'Заказ №%1$s создан, но не выгружен. %2$s', 'woodev-plugin-framework' ),
						$order->get_order_number(),
						$export['message']
					);
			}

			$response = rest_ensure_response( $body );

			$response->set_status( 201 );

			return $response;
		}

		/**
		 * `PUT /shipping/orders/{id}` — updates an order in place.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `id` comes from the route.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function update_order( $request ) {
			$order = $this->editor->update( absint( $request->get_param( 'id' ) ), self::payload( $request ) );

			if ( $order instanceof \WP_Error ) {
				return $order;
			}

			return rest_ensure_response(
				[
					'id'      => (int) $order->get_id(),
					'number'  => (string) $order->get_order_number(),
					'message' => sprintf(
						/* translators: %s: order number. */
						__( 'Заказ №%s сохранён.', 'woodev-plugin-framework' ),
						$order->get_order_number()
					),
				]
			);
		}

		/**
		 * `GET /shipping/orders/{id}/edit` — the wizard's prefill.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `id` comes from the route.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function load_order( $request ) {
			$prefill = $this->editor->load( absint( $request->get_param( 'id' ) ) );

			if ( $prefill instanceof \WP_Error ) {
				return $prefill;
			}

			return rest_ensure_response( $prefill );
		}

		/**
		 * The order payload of a request — the known keys of its JSON (or form) body, and nothing
		 * else: the route's own `id` must never be read as part of an order.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return array<string, mixed>
		 */
		private static function payload( $request ): array {
			$body = $request->get_json_params();

			if ( ! is_array( $body ) ) {
				$body = $request->get_body_params();
			}

			return array_intersect_key( is_array( $body ) ? $body : [], array_flip( self::PAYLOAD_KEYS ) );
		}

		/**
		 * Whether the create request asks for the immediate export (`export_now`, #710 D6).
		 *
		 * Read on its own, not through {@see self::PAYLOAD_KEYS}: it is not part of the order, and
		 * the payload validator must not see it. The update route never reads it — an edit does
		 * not export (O4).
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		private static function wants_export( $request ): bool {
			$body = $request->get_json_params();

			if ( ! is_array( $body ) ) {
				$body = $request->get_body_params();
			}

			return is_array( $body ) && isset( $body['export_now'] ) && rest_sanitize_boolean( $body['export_now'] );
		}
	}

endif;

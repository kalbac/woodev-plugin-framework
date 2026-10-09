<?php
/**
 * Woodev Shipping Orders — toolbar actions REST controller
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Admin\Orders\Toolbar_Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Toolbar_Controller' ) ) :

	/**
	 * The routes behind the carrier's page-level buttons above the orders table (s164) — see
	 * {@see Toolbar_Actions} for what a carrier declares and what each call does.
	 *
	 * | route                                                        | method | gate               | answers                                  |
	 * |--------------------------------------------------------------|--------|--------------------|------------------------------------------|
	 * | `/shipping/orders/toolbar-actions`                           | GET    | page capability    | `{ actions }` the buttons to draw        |
	 * | `/shipping/orders/toolbar-actions/{id}`                      | GET    | page capability    | the dialog (tabs)                        |
	 * | `/shipping/orders/toolbar-actions/{id}`                      | POST   | `edit_shop_orders` | per-order results; 422 on a bad payload  |
	 * | `/shipping/orders/toolbar-actions/{id}/rows`                 | POST   | `edit_shop_orders` | `{ message, dialog }` after a row button |
	 *
	 * The writes carry the `wp_rest` nonce like every route of the page, and are gated exactly like the
	 * single-order action route: they change orders at a carrier.
	 *
	 * @since 2.0.2
	 */
	class Toolbar_Controller extends \WP_REST_Controller {

		/**
		 * Registry to read the page capability from.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * The declaration reader/performer.
		 *
		 * @since 2.0.2
		 *
		 * @var Toolbar_Actions
		 */
		private $actions;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry      $registry orders registry.
		 * @param Toolbar_Actions|null $actions  toolbar actions; defaults to one built from $registry.
		 */
		public function __construct( Orders_Registry $registry, ?Toolbar_Actions $actions = null ) {
			$this->registry = $registry;
			$this->actions  = $actions ?? new Toolbar_Actions( $registry );
		}

		/**
		 * Registers the routes.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/toolbar-actions',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_actions' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
				]
			);

			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/toolbar-actions/(?P<id>[a-z0-9_-]+)',
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => [ $this, 'get_dialog' ],
						'permission_callback' => [ $this, 'read_permissions_check' ],
						'args'                => [
							'id' => [ 'type' => 'string' ],
						],
					],
					[
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => [ $this, 'submit' ],
						'permission_callback' => [ $this, 'write_permissions_check' ],
						'args'                => [
							'id' => [ 'type' => 'string' ],
						],
					],
				]
			);

			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/toolbar-actions/(?P<id>[a-z0-9_-]+)/rows',
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'perform_row_action' ],
					'permission_callback' => [ $this, 'write_permissions_check' ],
					'args'                => [
						'id'     => [ 'type' => 'string' ],
						'tab'    => [
							'type'     => 'string',
							'required' => true,
						],
						'row'    => [
							'type'     => 'string',
							'required' => true,
						],
						'action' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				]
			);
		}

		/**
		 * Read gate: the page-level capability, like the row list.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function read_permissions_check( $request ): bool {
			return current_user_can( $this->registry->get_page_capability() );
		}

		/**
		 * Write gate: `edit_shop_orders`, like the single-order action route — these routes change orders at a carrier.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function write_permissions_check( $request ): bool {
			return current_user_can( 'edit_shop_orders' );
		}

		/**
		 * The buttons to draw above the table.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response
		 */
		public function get_actions( $request ) {
			return rest_ensure_response( [ 'actions' => $this->actions->for_page() ] );
		}

		/**
		 * The dialog of one action, as the carrier declares it now.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `id` comes from the route.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_dialog( $request ) {
			$id     = (string) $request->get_param( 'id' );
			$dialog = $this->actions->dialog( $id );

			if ( null === $dialog ) {
				return self::unavailable_error();
			}

			return rest_ensure_response(
				[
					'id'     => $id,
					'dialog' => $dialog,
				]
			);
		}

		/**
		 * Submits the dialog's form: one run per chosen order, one result per order.
		 *
		 * A payload that does not fit the declaration answers 422 with `data.errors` (`[ { field, code, message } ]`)
		 * before the carrier is called, like the single-order route. Otherwise ALWAYS a 200 — an order that failed is
		 * not a failed request — carrying the aggregate `messages`, one `results` line per order and the refreshed
		 * `dialog`, so the list tab shows what the run just changed.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `id` comes from the route, `payload` from the body.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function submit( $request ) {
			$id     = (string) $request->get_param( 'id' );
			$result = $this->actions->submit( $id, $request->get_param( 'payload' ) );

			if ( null === $result ) {
				return self::unavailable_error();
			}

			if ( isset( $result['errors'] ) ) {
				return new \WP_Error(
					'woodev_shipping_orders_invalid_payload',
					__( 'Проверьте заполнение полей.', 'woodev-plugin-framework' ),
					[
						'status' => 422,
						'errors' => $result['errors'],
					]
				);
			}

			return rest_ensure_response(
				[
					'action' => $id,
					'dialog' => $this->actions->dialog( $id ),
				] + $result
			);
		}

		/**
		 * Runs a button of a row of the dialog's list tab, and answers with the refreshed dialog.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `id` comes from the route, `tab`, `row` and `action` from the body.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function perform_row_action( $request ) {
			$id     = (string) $request->get_param( 'id' );
			$result = $this->actions->perform_row_action(
				$id,
				(string) $request->get_param( 'tab' ),
				(string) $request->get_param( 'row' ),
				(string) $request->get_param( 'action' )
			);

			if ( ! $result['available'] ) {
				return new \WP_Error(
					'woodev_shipping_orders_toolbar_row_action_not_available',
					__( 'Это действие сейчас недоступно для выбранной строки.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			if ( empty( $result['ok'] ) ) {
				return new \WP_Error(
					'woodev_shipping_orders_toolbar_row_action_failed',
					(string) ( $result['message'] ?? '' ),
					[ 'status' => 502 ]
				);
			}

			return rest_ensure_response(
				[
					'message' => (string) ( $result['message'] ?? '' ),
					'dialog'  => $this->actions->dialog( $id ),
				]
			);
		}

		/**
		 * The answer for an action nobody declares, or whose carrier supplied no usable dialog.
		 *
		 * @since 2.0.2
		 *
		 * @return \WP_Error
		 */
		private static function unavailable_error(): \WP_Error {
			return new \WP_Error(
				'woodev_shipping_orders_toolbar_unavailable',
				__( 'Это действие сейчас недоступно.', 'woodev-plugin-framework' ),
				[ 'status' => 404 ]
			);
		}
	}

endif;

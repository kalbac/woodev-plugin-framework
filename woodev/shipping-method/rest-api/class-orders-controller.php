<?php
/**
 * Woodev Shipping Orders REST Controller
 *
 * Serves the row contract for the framework-owned «Заказы доставки» page (SP-10 spec,
 * increment 1). This is deliberately the MINIMAL row shape — id, order number, edit
 * URL, date created, WC status, and the carrier that matched — the full per-carrier
 * column payload lands in increment 2. Registered through
 * {@see \Woodev_REST_V1_Registrar} by {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::register_rest()},
 * mirroring {@see \Woodev\Framework\Settings\Settings_Page_Registry::register_rest()}.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Orders_Controller' ) ) :

	/**
	 * `GET woodev/v1/shipping/orders` dispatch controller.
	 *
	 * @since 2.0.2
	 */
	class Orders_Controller extends \WP_REST_Controller {

		/**
		 * Registry to read providers/capability from.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * Scope-query builder.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Query
		 */
		private $query;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry   $registry orders registry.
		 * @param Orders_Query|null $query    scope-query builder; defaults to one built from $registry.
		 */
		public function __construct( Orders_Registry $registry, ?Orders_Query $query = null ) {
			$this->registry = $registry;
			$this->query    = $query ?? new Orders_Query( $registry );
		}

		/**
		 * Registers the `/shipping/orders` route.
		 *
		 * @internal
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
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'carrier'  => [
							'type'    => 'string',
							'default' => 'all',
						],
						'page'     => [
							'type'    => 'integer',
							'default' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => Orders_Query::DEFAULT_PER_PAGE,
						],
						'orderby'  => [
							'type'    => 'string',
							'default' => 'date',
						],
						'order'    => [
							'type'    => 'string',
							'default' => 'DESC',
						],
						'search'   => [
							'type'    => 'string',
							'default' => '',
						],
					],
				]
			);
		}

		/**
		 * Permission gate: the page-level capability. A user without it must get a 403,
		 * never a silently empty list.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function get_items_permissions_check( $request ): bool {
			return current_user_can( $this->registry->get_page_capability() );
		}

		/**
		 * Returns the paginated row list.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_items( $request ) {
			$carrier = (string) $request->get_param( 'carrier' );
			if ( '' === $carrier ) {
				$carrier = 'all';
			}

			if ( 'all' !== $carrier && null === $this->registry->get_provider( $carrier ) ) {
				return new \WP_Error(
					'woodev_shipping_orders_unknown_carrier',
					__( 'Неизвестный перевозчик.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$result = $this->query->get_results(
				[
					'carrier'  => $carrier,
					'page'     => $request->get_param( 'page' ),
					'per_page' => $request->get_param( 'per_page' ),
					'orderby'  => $request->get_param( 'orderby' ),
					'order'    => $request->get_param( 'order' ),
					'search'   => $request->get_param( 'search' ),
				]
			);

			$matched_provider = 'all' !== $carrier ? $this->registry->get_provider( $carrier ) : null;

			$rows = [];
			foreach ( (array) $result->orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$rows[] = $this->build_row( $order, $matched_provider );
			}

			return rest_ensure_response(
				[
					'rows'        => $rows,
					'total'       => (int) $result->total,
					'total_pages' => (int) $result->max_num_pages,
				]
			);
		}

		/**
		 * Builds one increment-1 row.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order            matched order.
		 * @param Orders_Provider|null $matched_provider the requested single carrier, or null
		 *                                                for the aggregate (resolved per row).
		 * @return array<string,mixed>
		 */
		private function build_row( \WC_Order $order, ?Orders_Provider $matched_provider ): array {
			$provider = $matched_provider ?? $this->resolve_matched_provider( $order );
			$status   = $order->get_status();
			$created  = $order->get_date_created();

			return [
				'id'           => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'edit_url'     => $order->get_edit_order_url(),
				'date_created' => $created ? $created->date( \DATE_ATOM ) : null,
				'status'       => [
					'slug'  => $status,
					'label' => wc_get_order_status_name( $status ),
				],
				'carrier'      => null !== $provider
					? [
						'id'    => $provider->get_id(),
						'label' => $provider->get_label(),
					]
					: null,
			];
		}

		/**
		 * Resolves which registered carrier an aggregate row belongs to, by checking each
		 * provider's marker meta key in turn (first match wins). Only needed for the
		 * aggregate ('all') view — a single-carrier request already knows its provider.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order order to inspect.
		 * @return Orders_Provider|null
		 */
		private function resolve_matched_provider( \WC_Order $order ): ?Orders_Provider {
			foreach ( $this->registry->get_providers() as $provider ) {
				if ( '' !== (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_marker_meta_key() ) ) {
					return $provider;
				}
			}

			return null;
		}
	}

endif;

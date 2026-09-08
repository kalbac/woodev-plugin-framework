<?php
/**
 * Woodev Shipping Orders REST Controller
 *
 * Serves the row contract for the framework-owned «Заказы доставки» page (SP-10 spec).
 * The row shape and its `Order_Row_Builder` come from increment 2 (M1, D3, D4); this
 * controller only dispatches the request and resolves which carrier matched each
 * aggregate row. Registered through {@see \Woodev_REST_V1_Registrar} by
 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::register_rest()},
 * mirroring {@see \Woodev\Framework\Settings\Settings_Page_Registry::register_rest()}.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Delivery_Sync_Status;

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
		 * Row builder.
		 *
		 * @since 2.0.2
		 *
		 * @var Order_Row_Builder
		 */
		private $row_builder;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry        $registry    orders registry.
		 * @param Orders_Query|null      $query       scope-query builder; defaults to one built from $registry.
		 * @param Order_Row_Builder|null $row_builder row builder; defaults to a new instance.
		 */
		public function __construct( Orders_Registry $registry, ?Orders_Query $query = null, ?Order_Row_Builder $row_builder = null ) {
			$this->registry    = $registry;
			$this->query       = $query ?? new Orders_Query( $registry );
			$this->row_builder = $row_builder ?? new Order_Row_Builder();
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
						'carrier'         => [
							'type'    => 'string',
							'default' => 'all',
						],
						'page'            => [
							'type'    => 'integer',
							'default' => 1,
						],
						'per_page'        => [
							'type'    => 'integer',
							'default' => Orders_Query::DEFAULT_PER_PAGE,
						],
						'orderby'         => [
							'type'    => 'string',
							'default' => 'date',
						],
						'order'           => [
							'type'    => 'string',
							'default' => 'DESC',
						],
						'search'          => [
							'type'    => 'string',
							'default' => '',
						],
						'after'           => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => [ __CLASS__, 'validate_iso_date' ],
						],
						'before'          => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => [ __CLASS__, 'validate_iso_date' ],
						],
						'status'          => [
							'type'    => 'array',
							'items'   => [ 'type' => 'string' ],
							'default' => [],
						],
						'delivery_status' => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => [ __CLASS__, 'validate_delivery_status' ],
						],
						'has_tracking'    => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				]
			);

			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/sync-status',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_sync_status' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
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
		 * REST `validate_callback` for `after`/`before` (SP-10 spec D10/D11): an ISO 8601
		 * `YYYY-MM-DD` date, including calendar validity (rejects e.g. `2026-02-30`). An
		 * empty string is valid — it means "this bound is not set", not "empty date".
		 *
		 * @since 2.0.2
		 *
		 * @param mixed            $value   the request value.
		 * @param \WP_REST_Request $request request.
		 * @param string           $param   parameter name.
		 * @return bool|\WP_Error
		 */
		public static function validate_iso_date( $value, $request, string $param ) {
			if ( '' === $value ) {
				return true;
			}

			if ( is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches )
				&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
				return true;
			}

			return new \WP_Error(
				'rest_invalid_param',
				/* translators: %s: parameter name. */
				sprintf( __( '%s must be an ISO 8601 date (YYYY-MM-DD).', 'woodev-plugin-framework' ), $param ),
				[ 'status' => 400 ]
			);
		}

		/**
		 * REST `validate_callback` for `delivery_status` (SP-10 spec D10): one of
		 * {@see Delivery_Status::canonical_states()} or {@see Delivery_Status::UNKNOWN}.
		 * An empty string is valid — it means "no delivery-status filter".
		 *
		 * @since 2.0.2
		 *
		 * @param mixed            $value   the request value.
		 * @param \WP_REST_Request $request request.
		 * @param string           $param   parameter name.
		 * @return bool|\WP_Error
		 */
		public static function validate_delivery_status( $value, $request, string $param ) {
			if ( '' === $value || in_array( $value, array_merge( Delivery_Status::canonical_states(), [ Delivery_Status::UNKNOWN ] ), true ) ) {
				return true;
			}

			return new \WP_Error(
				'rest_invalid_param',
				/* translators: %s: parameter name. */
				sprintf( __( '%s is not a recognized delivery status.', 'woodev-plugin-framework' ), $param ),
				[ 'status' => 400 ]
			);
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

			$params = [
				'carrier'         => $carrier,
				'page'            => $request->get_param( 'page' ),
				'per_page'        => $request->get_param( 'per_page' ),
				'orderby'         => $request->get_param( 'orderby' ),
				'order'           => $request->get_param( 'order' ),
				'search'          => $request->get_param( 'search' ),
				'after'           => $request->get_param( 'after' ),
				'before'          => $request->get_param( 'before' ),
				'status'          => $request->get_param( 'status' ),
				'delivery_status' => $request->get_param( 'delivery_status' ),
			];

			// `has_tracking` carries no default (SP-10 spec D10): an explicit `false` must
			// still filter, so its PRESENCE — not its truthiness — decides whether
			// Orders_Query::build_args() applies the filter at all.
			if ( $request->has_param( 'has_tracking' ) && null !== $request->get_param( 'has_tracking' ) ) {
				$params['has_tracking'] = $request->get_param( 'has_tracking' );
			}

			$result = $this->query->get_results( $params );

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
		 * Returns the delivery-status sync freshness (SP-10 spec D9, #828): every registered
		 * carrier's `last_updated`/`next_update`, plus an aggregate `last_updated` for the
		 * (later) "Data status" panel.
		 *
		 * **The aggregate is `null` the moment ANY registered carrier has never synced —
		 * not the oldest of only the carriers that HAVE.** The aggregate exists to make one
		 * honest statement about the whole table: if СДЭК synced ten minutes ago and Яндекс
		 * has never synced at all, "обновлено 10 минут назад" is FALSE for every Яндекс row
		 * on screen. Overstating freshness is exactly the failure this panel exists to
		 * prevent (§D9's premise is that the delivery status is the one thing on this page
		 * that CAN be stale); understating it merely sends the merchant to look, and it
		 * self-corrects the moment that carrier syncs once. A carrier that has never synced
		 * is not "missing from the calculation" — it is infinitely stale, and the oldest
		 * (here, effectively infinite) wins. When every carrier HAS synced at least once,
		 * the aggregate is the oldest of their timestamps, same as before.
		 *
		 * The per-carrier breakdown this response carries alongside still reports `null`
		 * for a never-synced carrier exactly as before — that is what tells the merchant
		 * WHICH carrier is the reason the aggregate reads `null`.
		 *
		 * ⚠ Known gap, accepted for now (#828, flagged for the operator): a carrier that is
		 * registered but has placed no orders at all yet is indistinguishable here from one
		 * that has orders and simply never synced — both read `null` and both hold the
		 * aggregate at `null`. That is honest (this store cannot tell the two apart) if
		 * unhelpful. Deliberately NOT fixed by coupling this panel to the row/order query —
		 * that is different scope.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response
		 */
		public function get_sync_status( $request ) {
			$carriers         = [];
			$oldest           = null;
			$any_never_synced = false;

			foreach ( $this->registry->get_providers() as $provider ) {
				$last_updated = Delivery_Sync_Status::get_last_updated( $provider->get_id() );
				$next_update  = Delivery_Sync_Status::get_next_update( $provider->get_cron_hook() );

				if ( null === $last_updated ) {
					$any_never_synced = true;
				} elseif ( null === $oldest || $last_updated < $oldest ) {
					$oldest = $last_updated;
				}

				$carriers[] = [
					'id'           => $provider->get_id(),
					'label'        => $provider->get_label(),
					'last_updated' => $last_updated,
					'next_update'  => $next_update,
				];
			}

			return rest_ensure_response(
				[
					'last_updated' => $any_never_synced ? null : $oldest,
					'carriers'     => $carriers,
				]
			);
		}

		/**
		 * Builds one row via {@see Order_Row_Builder}.
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

			return $this->row_builder->build( $order, $provider );
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

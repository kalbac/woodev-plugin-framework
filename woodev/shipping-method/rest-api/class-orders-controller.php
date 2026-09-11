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
						'carrier'             => [
							'type'    => 'string',
							'default' => 'all',
						],
						'page'                => [
							'type'    => 'integer',
							'default' => 1,
						],
						'per_page'            => [
							'type'    => 'integer',
							'default' => Orders_Query::DEFAULT_PER_PAGE,
						],
						'orderby'             => [
							'type'    => 'string',
							'default' => 'date',
						],
						'order'               => [
							'type'    => 'string',
							'default' => 'DESC',
						],
						'search'              => [
							'type'    => 'string',
							'default' => '',
						],
						'after'               => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => [ __CLASS__, 'validate_iso_date' ],
						],
						'before'              => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => [ __CLASS__, 'validate_iso_date' ],
						],
						'status'              => [
							'type'    => 'array',
							'items'   => [ 'type' => 'string' ],
							'default' => [],
						],
						// 'is not' rule for `status` (#836) — every valid WC status except these.
						'status_not'          => [
							'type'    => 'array',
							'items'   => [ 'type' => 'string' ],
							'default' => [],
						],
						'delivery_status'     => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => [ __CLASS__, 'validate_delivery_status' ],
						],
						// 'is not' rule for `delivery_status` (#836) — same value set.
						'delivery_status_not' => [
							'type'              => 'string',
							'default'           => '',
							'validate_callback' => [ __CLASS__, 'validate_delivery_status' ],
						],
						'has_tracking'        => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						// present (any value) => filters on pickup-point meta presence (#836);
						// absent => not filtered — same presence rule as `has_tracking`.
						'has_pickup_point'    => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						// present (any value) => filters on whether the order was ever
						// exported to the carrier (SP-10 #841); absent => not filtered —
						// same presence rule as `has_tracking`.
						'is_exported'         => [
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
		 * Returns the paginated row list, plus the two scope counts the «Все / Новые»
		 * links above the table render (`scope_counts`, SP-10 #841 — see
		 * {@see self::build_scope_counts()} for why they travel in THIS response and not
		 * in one of their own).
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
				'carrier'             => $carrier,
				'page'                => $request->get_param( 'page' ),
				'per_page'            => $request->get_param( 'per_page' ),
				'orderby'             => $request->get_param( 'orderby' ),
				'order'               => $request->get_param( 'order' ),
				'search'              => $request->get_param( 'search' ),
				'after'               => $request->get_param( 'after' ),
				'before'              => $request->get_param( 'before' ),
				'status'              => $request->get_param( 'status' ),
				'status_not'          => $request->get_param( 'status_not' ),
				'delivery_status'     => $request->get_param( 'delivery_status' ),
				'delivery_status_not' => $request->get_param( 'delivery_status_not' ),
			];

			// `has_tracking`/`has_pickup_point`/`is_exported` carry no default (SP-10 spec
			// D10; pickup point added #836, is_exported added #841): an explicit `false`
			// must still filter, so PRESENCE — not truthiness — decides whether
			// Orders_Query::build_args() applies the filter at all.
			if ( $request->has_param( 'has_tracking' ) && null !== $request->get_param( 'has_tracking' ) ) {
				$params['has_tracking'] = $request->get_param( 'has_tracking' );
			}

			if ( $request->has_param( 'has_pickup_point' ) && null !== $request->get_param( 'has_pickup_point' ) ) {
				$params['has_pickup_point'] = $request->get_param( 'has_pickup_point' );
			}

			if ( $request->has_param( 'is_exported' ) && null !== $request->get_param( 'is_exported' ) ) {
				$params['is_exported'] = $request->get_param( 'is_exported' );
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
					'rows'         => $rows,
					'total'        => (int) $result->total,
					'total_pages'  => (int) $result->max_num_pages,
					'scope_counts' => $this->build_scope_counts( $params, (int) $result->total ),
				]
			);
		}

		/**
		 * Both numbers the «Все / Новые» scope links above the table show (SP-10 #841),
		 * in the SAME response as the rows they describe.
		 *
		 * **One response, not two, is the whole point of computing this here.** The
		 * control exists so the merchant can check «Новые (7)» against the badge in the
		 * admin menu with their own eyes (operator, 11.09.2026) — two round trips can
		 * answer from two different states of the table and disagree, and a control whose
		 * two numbers can contradict each other is worse than no control.
		 *
		 * **Both counts respect every OTHER filter of the request.** With «Реалистичная
		 * доставка» picked, «Новые» is the new orders among THAT carrier's, because the
		 * numbers describe what the table would show if the merchant followed the link.
		 * Only the scope's own arg — `is_exported` — is overridden: that is the axis the
		 * two links select between, so it cannot be inherited from the current view or
		 * both links would report the same number.
		 *
		 * **«New» is `is_exported = false`**, i.e. the carrier has no order id for it yet
		 * — settled by measurement: an order stops being new when
		 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()}
		 * writes its `carrier_order_id`.
		 *
		 * ⚠ Only ONE extra query ever runs, and not as an optimisation for its own sake:
		 * the current view's own `total` is BY DEFINITION one of the two counts (the
		 * aggregate view's total IS «Все», the «Новые» view's total IS «Новые»), so
		 * reusing it is what makes that link's number provably the same number the table
		 * was built from rather than a second query's opinion of it.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $params request params as handed to {@see Orders_Query::get_results()}.
		 * @param int                 $total  the total this request's own query already reported.
		 * @return array{all:int,new:int}
		 */
		private function build_scope_counts( array $params, int $total ): array {
			$all_params = $params;
			unset( $all_params['is_exported'] );

			$new_params                = $all_params;
			$new_params['is_exported'] = false;

			// `wc_string_to_bool()` and not a cast, because that is what
			// `Orders_Query::build_args()` reads the very same value with — a different
			// reader here could call a request «Новые» that the query then filtered as
			// «экспортированные», and the count would describe another table.
			$is_new_scope = array_key_exists( 'is_exported', $params ) && ! wc_string_to_bool( $params['is_exported'] );
			$is_all_scope = ! array_key_exists( 'is_exported', $params );

			return [
				'all' => $is_all_scope ? $total : $this->count_matching( $all_params ),
				'new' => $is_new_scope ? $total : $this->count_matching( $new_params ),
			];
		}

		/**
		 * Counts the orders one set of request params matches, through the SAME
		 * {@see Orders_Query} the rows come from.
		 *
		 * ⚠ Never `wc_get_orders()` or a `$wpdb` count of its own. On the legacy CPT
		 * datastore `wc_get_orders()` silently DROPS `meta_query` (gotcha
		 * `wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore`), so a bespoke
		 * count would report the WHOLE table there while the rows beside it were
		 * correctly scoped — the two datastores would disagree and only one of them
		 * would look wrong. Going through `Orders_Query` is what keeps both honest,
		 * because it is the object that owns the datastore branch.
		 *
		 * Asks for the smallest page there is: only the paginated result's `total` is
		 * read, so the page size is pure cost.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $params request params to count under.
		 * @return int
		 */
		private function count_matching( array $params ): int {
			$params['page']     = 1;
			$params['per_page'] = 1;

			return (int) $this->query->get_results( $params )->total;
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

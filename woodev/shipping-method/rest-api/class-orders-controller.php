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

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
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
		 * Action-set builder — also used by {@see self::perform_action()} to refuse
		 * an action the client's button is stale about (card #824).
		 *
		 * @since 2.0.2
		 *
		 * @var Order_Actions
		 */
		private $order_actions;

		/**
		 * Maximum number of order ids one bulk request accepts (card #874). Rejected outright
		 * with a clear `WP_Error` rather than silently truncated — a truncated batch would
		 * perform the action on fewer orders than the merchant selected without saying so.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		private const BULK_MAX_IDS = 100;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added `$order_actions` (card #824).
		 *
		 * @param Orders_Registry        $registry      orders registry.
		 * @param Orders_Query|null      $query         scope-query builder; defaults to one built from $registry.
		 * @param Order_Row_Builder|null $row_builder   row builder; defaults to one built from `$order_actions`.
		 * @param Order_Actions|null     $order_actions action-set builder; defaults to one built from $registry.
		 */
		public function __construct( Orders_Registry $registry, ?Orders_Query $query = null, ?Order_Row_Builder $row_builder = null, ?Order_Actions $order_actions = null ) {
			$this->registry      = $registry;
			$this->query         = $query ?? new Orders_Query( $registry );
			$this->order_actions = $order_actions ?? new Order_Actions( $registry );
			$this->row_builder   = $row_builder ?? new Order_Row_Builder( $this->order_actions );
		}

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

			// Performs one row action — export/update/cancel (card #824). A write, so
			// gated on `edit_shop_orders` rather than the (possibly weaker) page
			// capability {@see self::get_items_permissions_check()} reuses.
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/(?P<id>\d+)/actions/(?P<action>[a-z_]+)',
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'perform_action' ],
					'permission_callback' => [ $this, 'perform_action_permissions_check' ],
					'args'                => [
						'id'     => [
							'type' => 'integer',
						],
						'action' => [
							'type' => 'string',
						],
					],
				]
			);

			// Bulk action + preview routes (SP-10 increment 4, cards #874/#875).
			$this->register_bulk_action_route();
		}

		/**
		 * Registers the `/shipping/orders/bulk/{action}` route.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_bulk_action_route(): void {
			// Performs one action across many orders in a single request (card #874) — one
			// request rather than N so the client can show ONE aggregate result instead of N
			// races toasts. Gated the same as the single-order write route.
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/bulk/(?P<action>[a-z_]+)',
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'perform_bulk_action' ],
					'permission_callback' => [ $this, 'perform_action_permissions_check' ],
					'args'                => [
						'action' => [
							'type' => 'string',
						],
						'ids'    => [
							'type'              => 'array',
							'required'          => true,
							'items'             => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'validate_callback' => [ __CLASS__, 'validate_bulk_ids' ],
						],
					],
				]
			);

			// A read-only preview of one order for the panel a merchant opens without leaving
			// the table (card #875) — same capability as the row list itself.
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/shipping/orders/(?P<id>\d+)/preview',
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_preview' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'id' => [
							'type' => 'integer',
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
		 * Permission gate for {@see self::perform_action()}: `edit_shop_orders`, the
		 * WooCommerce order-edit capability — STRICTER than
		 * {@see self::get_items_permissions_check()}'s page capability
		 * ({@see Orders_Registry::get_page_capability()} resolves to
		 * `manage_woocommerce`), because this route mutates an order at a carrier
		 * rather than merely reading the page. Mirrors
		 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::handle_order_action()}'s
		 * own capability check.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function perform_action_permissions_check( $request ): bool {
			return current_user_can( 'edit_shop_orders' );
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
		 * REST `validate_callback` for `ids` on the bulk action route (card #874): rejects a
		 * list longer than {@see self::BULK_MAX_IDS} with a clear error rather than silently
		 * truncating it — a truncated batch would act on fewer orders than the merchant
		 * selected without saying so. Per-item type/positivity is left to the `items` schema
		 * declared alongside this callback.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed            $value   the request value.
		 * @param \WP_REST_Request $request request.
		 * @param string           $param   parameter name.
		 * @return bool|\WP_Error
		 */
		public static function validate_bulk_ids( $value, $request, string $param ) {
			if ( is_array( $value ) && count( $value ) > self::BULK_MAX_IDS ) {
				return new \WP_Error(
					'woodev_shipping_orders_bulk_too_many_ids',
					sprintf(
						/* translators: %d: maximum number of order ids accepted per bulk request. */
						__( 'За один раз можно обработать не более %d заказов.', 'woodev-plugin-framework' ),
						self::BULK_MAX_IDS
					),
					[ 'status' => 400 ]
				);
			}

			return true;
		}

		/**
		 * Returns the paginated row list, plus the two scope counts the «Все / Новые»
		 * links above the table render (`scope_counts`, SP-10 #841 — see
		 * {@see self::build_scope_counts()} for why they travel in THIS response and not
		 * in one of their own) and one count per carrier for the picker above them
		 * (`carrier_counts`, SP-10 #855, {@see self::build_carrier_counts()}).
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
					'rows'           => $rows,
					'total'          => (int) $result->total,
					'total_pages'    => (int) $result->max_num_pages,
					'scope_counts'   => $this->build_scope_counts( $params, (int) $result->total ),
					'carrier_counts' => $this->build_carrier_counts( $params, (int) $result->total ),
				]
			);
		}

		/**
		 * One number per carrier for the «Перевозчик» picker above the table (SP-10 #855),
		 * in the SAME response as the rows — built on {@see self::build_scope_counts()}'s
		 * pattern, for the same reasons, and differing from it only in which axis is
		 * overridden.
		 *
		 * **They used to be computed in the page bootstrap**
		 * ({@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::build_bootstrap_providers()}),
		 * once per page load, with no filters at all. So the picker read «СДЭК (71)» beside
		 * a table of four the moment any filter was on, and the number that disagreed with
		 * the table was the one the merchant would carry away. Computing them here is what
		 * makes «Все перевозчики (12) | СДЭК (9) | Яндекс (3)» a description of the period
		 * the merchant is actually looking at.
		 *
		 * **Every OTHER filter of the request is inherited**, `carrier` alone is
		 * overridden: that is the axis the picker selects between, so inheriting it would
		 * make every option report the current carrier's number.
		 *
		 * ⚠ The scope (`is_exported`) is inherited on purpose, unlike in
		 * {@see self::build_scope_counts()} where it is the overridden axis. Standing in
		 * «Новые», «СДЭК (9)» has to mean nine NEW СДЭК orders — the count describes what
		 * the table would show if the merchant followed that option, and following it does
		 * not leave «Новые».
		 *
		 * The current carrier's own count is the row query's `total`, reused rather than
		 * re-asked for exactly as the current scope's is: it is then provably the same
		 * number the table was built from rather than a second query's opinion of it.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $params request params as handed to {@see Orders_Query::get_results()}.
		 * @param int                 $total  the total this request's own query already reported.
		 * @return array<string,int> carrier id (including `all`) => count.
		 */
		private function build_carrier_counts( array $params, int $total ): array {
			$current = isset( $params['carrier'] ) ? (string) $params['carrier'] : 'all';

			$ids = [ 'all' ];
			foreach ( $this->registry->get_providers() as $provider ) {
				$ids[] = $provider->get_id();
			}

			$counts = [];
			foreach ( $ids as $id ) {
				if ( $id === $current ) {
					$counts[ $id ] = $total;
					continue;
				}

				$counts[ $id ] = $this->count_matching( array_merge( $params, [ 'carrier' => $id ] ) );
			}

			return $counts;
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

		/**
		 * Performs one order-row action — export/update/cancel (card #824).
		 *
		 * Never trusts the client's button: recomputes {@see Order_Actions::for_order()}
		 * for THIS order right now and refuses an action outside that list — the
		 * client's copy of the row can be stale the moment two requests race, or a
		 * carrier's own gate (e.g. `supports_update()`) changed underneath it.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `id` and `action` come from the route.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function perform_action( $request ) {
			$order_id = absint( $request->get_param( 'id' ) );
			$action   = (string) $request->get_param( 'action' );
			$order    = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				return new \WP_Error(
					'woodev_shipping_orders_unknown_order',
					__( 'Заказ не найден.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$provider = $this->resolve_matched_provider( $order );

			if ( null === $provider ) {
				return new \WP_Error(
					'woodev_shipping_orders_unknown_carrier',
					__( 'Не удалось определить перевозчика для этого заказа.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$available = array_column( $this->order_actions->for_order( $order, $provider ), 'action' );

			if ( ! in_array( $action, $available, true ) ) {
				// ⚠ Say WHY, not just "no". The gate that refused was computed one line
				// above out of framework-owned state, so the reason is in hand — reporting
				// the bare fact sends the merchant to support asking what it means
				// (operator, s134). {@see Order_Actions::unavailable_reason()} answers for
				// the framework's own gate only; a CARRIER-side refusal is #819's boundary
				// and cannot be described here at all today.
				return new \WP_Error(
					'woodev_shipping_orders_action_not_available',
					$this->order_actions->unavailable_reason( $order, $provider, $action ),
					[ 'status' => 400 ]
				);
			}

			$handler = $this->registry->get_shipment_handler( $provider->get_id() );

			if ( null === $handler ) {
				// Cannot happen given `$available` is non-empty only when
				// Order_Actions::for_order() itself resolved a handler — guarded
				// anyway rather than trusting that invariant across the call above.
				return new \WP_Error(
					'woodev_shipping_orders_no_handler',
					__( 'Для этого перевозчика не настроен обработчик отправлений.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			try {
				$succeeded = $this->dispatch_action( $handler, $order, $action, $provider );
			} catch ( \Throwable $exception ) {
				$this->log_action_failure( $provider->get_id(), $action, $exception );

				return $this->action_upstream_error();
			}

			if ( ! $succeeded ) {
				return new \WP_Error(
					'woodev_shipping_orders_action_failed',
					self::action_failure_message( $action ),
					[ 'status' => 502 ]
				);
			}

			return rest_ensure_response(
				[
					'row'     => $this->build_row( self::reread_order( $order ), $provider ),
					'message' => self::action_success_message( $action ),
				]
			);
		}

		/**
		 * Performs one action across many orders in a single request (card #874).
		 *
		 * **One request, not N** — N requests would produce N toasts, race the table's own
		 * re-render, and cannot produce the aggregate sentence this route returns at all.
		 *
		 * For EACH id, in the order given: resolves the order and its provider, recomputes
		 * {@see Order_Actions::for_order()} and checks the requested action is in it — never
		 * trusting the client's selection, same as {@see self::perform_action()}. An order the
		 * action does not apply to (unknown id, unresolvable carrier, action outside the
		 * recomputed gate, no registered handler) is SKIPPED, not failed — the operator's own
		 * rule: an inapplicable order is simply ignored for that action. Every ELIGIBLE order is
		 * then dispatched through the same {@see self::dispatch_action()} path the single-order
		 * route uses, so `export()` returning `''` (#860) counts as a failure there too, and an
		 * exception is caught PER ORDER and counted as a failure rather than aborting the batch.
		 *
		 * Always a 200, even when every eligible order failed — a partial (or total) failure
		 * among many orders is not a failed REQUEST.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `action` comes from the route, `ids` from
		 *                                  the body.
		 * @return \WP_REST_Response
		 */
		public function perform_bulk_action( $request ) {
			$action = (string) $request->get_param( 'action' );
			$ids    = (array) $request->get_param( 'ids' );

			$eligible  = 0;
			$succeeded = 0;
			$failed    = 0;
			$rows      = [];

			foreach ( $ids as $id ) {
				$order = wc_get_order( absint( $id ) );

				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$provider = $this->resolve_matched_provider( $order );

				if ( null === $provider ) {
					continue;
				}

				$available = array_column( $this->order_actions->for_order( $order, $provider ), 'action' );

				if ( ! in_array( $action, $available, true ) ) {
					continue;
				}

				$handler = $this->registry->get_shipment_handler( $provider->get_id() );

				if ( null === $handler ) {
					// Cannot happen given `$available` is non-empty only when
					// Order_Actions::for_order() itself resolved a handler — guarded anyway
					// rather than trusting that invariant across the call above.
					continue;
				}

				++$eligible;

				try {
					$succeeded_this_order = $this->dispatch_action( $handler, $order, $action, $provider );
				} catch ( \Throwable $exception ) {
					$this->log_action_failure( $provider->get_id(), $action, $exception );
					$succeeded_this_order = false;
				}

				if ( $succeeded_this_order ) {
					++$succeeded;
					$rows[] = $this->build_row( self::reread_order( $order ), $provider );
					continue;
				}

				++$failed;
			}

			return rest_ensure_response(
				[
					'action'    => $action,
					'requested' => count( $ids ),
					'eligible'  => $eligible,
					'skipped'   => count( $ids ) - $eligible,
					'succeeded' => $succeeded,
					'failed'    => $failed,
					'rows'      => $rows,
					'messages'  => self::build_bulk_messages( $action, $eligible, $succeeded, $failed ),
				]
			);
		}

		/**
		 * Builds the `messages` group of the bulk response.
		 *
		 * `success` is present only when `succeeded > 0`, `error` only when `failed > 0` — either
		 * may be absent. When NOTHING among the requested ids was eligible, that general rule
		 * would leave `messages` an empty object («Экспортировано 0 из 0» is never built), which
		 * is honest but tells the merchant nothing about why; a single explanatory message is
		 * returned instead.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action    the requested action id.
		 * @param int    $eligible  orders that were actually attempted.
		 * @param int    $succeeded of those, how many succeeded.
		 * @param int    $failed    of those, how many failed.
		 * @return array{success?:string,error?:string}
		 */
		private static function build_bulk_messages( string $action, int $eligible, int $succeeded, int $failed ): array {
			if ( 0 === $eligible ) {
				return [
					'error' => __( 'Ни один из выбранных заказов не поддерживает это действие.', 'woodev-plugin-framework' ),
				];
			}

			$messages = [];

			if ( $succeeded > 0 ) {
				$messages['success'] = self::bulk_success_message( $action, $succeeded, $eligible );
			}

			if ( $failed > 0 ) {
				$messages['error'] = self::bulk_failure_message( $action, $failed, $eligible );
			}

			return $messages;
		}

		/**
		 * The Russian aggregate success sentence for one bulk action — the operator's own
		 * wording, near-verbatim: «Экспортировано 3 из 5». **"из N" is the ELIGIBLE count**, not
		 * the requested one — the sentence describes what was actually attempted.
		 *
		 * No countable noun appears here (unlike {@see self::bulk_failure_message()}), matching
		 * the operator's own example exactly — so no `_n()` call is needed for this half.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action    the requested action id.
		 * @param int    $succeeded orders successfully processed.
		 * @param int    $eligible  orders actually attempted.
		 * @return string
		 */
		private static function bulk_success_message( string $action, int $succeeded, int $eligible ): string {
			switch ( $action ) {
				case Order_Actions::EXPORT:
					$template = __( 'Экспортировано %1$d из %2$d', 'woodev-plugin-framework' );
					break;

				case Order_Actions::CANCEL:
					$template = __( 'Отменено %1$d из %2$d', 'woodev-plugin-framework' );
					break;

				case Order_Actions::UPDATE:
					$template = __( 'Обновлено %1$d из %2$d', 'woodev-plugin-framework' );
					break;

				default:
					$template = __( 'Выполнено %1$d из %2$d', 'woodev-plugin-framework' );
			}

			return sprintf( $template, $succeeded, $eligible );
		}

		/**
		 * The Russian aggregate failure sentence for one bulk action — the operator's own
		 * wording, near-verbatim: «Не удалось экспортировать 2 заказа из 5».
		 *
		 * ⚠ Russian has three plural forms («1 заказ» / «2 заказа» / «5 заказов»), so the count
		 * is threaded through `_n()` rather than hand-built — a hand-built string is wrong at 1,
		 * 2 and 5 alike. `_n()`'s own untranslated fallback is binary (singular vs one plural),
		 * exactly like every other `_n()` call already in this framework (e.g. `_n( 'Every %d
		 * Minute', 'Every %d Minutes', … )`); the THIRD Russian form only renders once the
		 * shipped `woodev-plugin-framework-ru_RU` catalogue carries an entry for this exact
		 * singular/plural pair with all three `msgstr` forms — a translation-catalogue update
		 * this task's scope (`languages/**` is not among the touched paths) does not cover.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action   the requested action id.
		 * @param int    $failed   orders that failed.
		 * @param int    $eligible orders actually attempted.
		 * @return string
		 */
		private static function bulk_failure_message( string $action, int $failed, int $eligible ): string {
			switch ( $action ) {
				case Order_Actions::EXPORT:
					$template = _n(
						'Не удалось экспортировать %1$d заказ из %2$d',
						'Не удалось экспортировать %1$d заказов из %2$d',
						$failed,
						'woodev-plugin-framework'
					);
					break;

				case Order_Actions::CANCEL:
					$template = _n(
						'Не удалось отменить %1$d заказ из %2$d',
						'Не удалось отменить %1$d заказов из %2$d',
						$failed,
						'woodev-plugin-framework'
					);
					break;

				case Order_Actions::UPDATE:
					$template = _n(
						'Не удалось обновить %1$d заказ из %2$d',
						'Не удалось обновить %1$d заказов из %2$d',
						$failed,
						'woodev-plugin-framework'
					);
					break;

				default:
					$template = __( 'Не удалось выполнить действие для %1$d из %2$d', 'woodev-plugin-framework' );
			}

			return sprintf( $template, $failed, $eligible );
		}

		/**
		 * Returns what a shop owner needs to see about one order without opening it (card #875).
		 *
		 * Same capability as the row list ({@see self::get_items_permissions_check()}) — this is
		 * a read, not a write.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request; `id` comes from the route.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_preview( $request ) {
			$order_id = absint( $request->get_param( 'id' ) );
			$order    = wc_get_order( $order_id );

			if ( ! $order instanceof \WC_Order ) {
				return new \WP_Error(
					'woodev_shipping_orders_unknown_order',
					__( 'Заказ не найден.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$provider = $this->resolve_matched_provider( $order );

			return rest_ensure_response( $this->row_builder->build_preview( $order, $provider ) );
		}

		/**
		 * Dispatches one action to the carrier's shipment handler.
		 *
		 * ⚠ `export()` returns `''` on failure AND on a carrier response with no id
		 * (card #860) — a `''` return is NOT success. `cancel()`/`update()` already
		 * return bool.
		 *
		 * The framework itself performs only its own three verbs — export/cancel/
		 * update. Anything else is a carrier extra declared via the
		 * `woodev_shipping_order_actions` filter ({@see Order_Actions::for_order()});
		 * the `default:` branch below is the matching PERFORMING-side extension
		 * point, so such an action is not merely advertised but actually executed by
		 * the carrier plugin that declared it. An action nothing hooks still fails
		 * honestly (`false`) rather than reporting a fake success.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 2 (MEDIUM 3): the `default:` branch applies the
		 *              `woodev_shipping_perform_order_action` filter instead of
		 *              unconditionally returning false, so a carrier's own declared
		 *              action can actually be performed.
		 *
		 * @param Abstract_Shipment_Handler $handler handler resolved for the order's carrier.
		 * @param \WC_Order                 $order   the order.
		 * @param string                    $action  one of {@see Order_Actions}' action ids.
		 * @param Orders_Provider           $provider the matched carrier descriptor.
		 * @return bool
		 */
		private function dispatch_action( Abstract_Shipment_Handler $handler, \WC_Order $order, string $action, Orders_Provider $provider ): bool {
			switch ( $action ) {
				case Order_Actions::EXPORT:
					[ $settlement, $settlement_provider ] = $this->resolve_popular_settlement_context( $order );

					return '' !== $handler->export( $order, $settlement, $settlement_provider );

				case Order_Actions::CANCEL:
					return $handler->cancel( $order );

				case Order_Actions::UPDATE:
					return $handler->update( $order );

				default:
					/**
					 * Performs a carrier's own extra order action (one declared via the
					 * `woodev_shipping_order_actions` filter, not one of the framework's
					 * own export/update/cancel verbs).
					 *
					 * The carrier plugin that declared the action is the only one that
					 * knows how to perform it, so it hooks this filter, checks `$action`
					 * (and, if it serves more than one carrier, `$provider`) is its own,
					 * performs the action against its own API, and returns whether it
					 * succeeded. Defaults to `false`, so an action nothing hooks still
					 * fails honestly instead of reporting success it never earned.
					 *
					 * @since 2.0.2
					 *
					 * @param bool            $performed whether the action was performed successfully; default false.
					 * @param string          $action    the action id, as declared by the carrier's filter.
					 * @param \WC_Order       $order     the order the action was requested for.
					 * @param Orders_Provider $provider  the matched carrier descriptor.
					 */
					return (bool) apply_filters( 'woodev_shipping_perform_order_action', false, $action, $order, $provider );
			}
		}

		/**
		 * Re-reads an order's meta after a carrier action wrote to it.
		 *
		 * ⚠ Not a defensive nicety — without it the response is WRONG on a legacy-CPT
		 * shop, and right on an HPOS one, which is exactly why no unit test and no pass
		 * on the (HPOS) rig can catch it. {@see \Woodev_Order_Compatibility::update_order_meta()}
		 * branches on the datastore: under HPOS it calls `$order->update_meta_data()` +
		 * `save_meta_data()`, so the in-memory object this method was handed is already
		 * current; on the legacy CPT store it calls `update_post_meta()` straight against
		 * the row, BYPASSING that object's meta cache. So after a successful export the
		 * same `$order` instance still reports the OLD `carrier_order_id` — and the row
		 * built from it would come back with `is_exported` false and the pre-action
		 * button set, leaving «Выгрузить» on screen for an order that was just exported
		 * and inviting the merchant to export it a second time.
		 *
		 * `read_meta_data( true )` forces a re-read past the cache, which is correct on
		 * both stores: under HPOS it re-reads what was just saved, on the CPT store it
		 * picks up the write that went around the object.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order a carrier action just wrote to.
		 * @return \WC_Order the same instance, with its meta re-read from the store.
		 */
		private static function reread_order( \WC_Order $order ): \WC_Order {
			$order->read_meta_data( true );

			return $order;
		}

		/**
		 * Resolves the popular-settlements enrolment context for an order about to
		 * be exported through this route — the settlement the customer picked at
		 * checkout and the SAME provider that produced it (#488 slice 2).
		 *
		 * Mirrors {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::resolve_popular_settlement_context()}
		 * exactly, but reads the framework's own shared singleton
		 * ({@see Location_Provider_Registry::instance()}) directly rather than
		 * depending on `Shipping_Admin_Order` — that class is PLUGIN-constructed
		 * (each carrier plugin builds its own instance) and this REST controller has
		 * no guaranteed access to one. `Location_Provider_Registry` and its
		 * `Popular_Settlement_Store` are already framework-level singletons reused
		 * this same way elsewhere (e.g. {@see Abstract_Shipment_Handler}'s own
		 * constructor default), so this introduces no new coupling.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order about to be exported.
		 * @return array{0: Location_Record|null, 1: Location_Provider|null}
		 */
		private function resolve_popular_settlement_context( \WC_Order $order ): array {
			$settlement = Location_Provider_Registry::instance()->popular_settlement_store()->recall_candidate( $order );

			if ( null === $settlement ) {
				return [ null, null ];
			}

			$provider = Location_Provider_Registry::instance()->get_providers()[ $settlement->provider_id() ] ?? null;

			return [ $settlement, $provider ];
		}

		/**
		 * The Russian success sentence for one action.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action one of {@see Order_Actions}' action ids.
		 * @return string
		 */
		private static function action_success_message( string $action ): string {
			switch ( $action ) {
				case Order_Actions::EXPORT:
					return __( 'Заказ выгружен перевозчику.', 'woodev-plugin-framework' );

				case Order_Actions::CANCEL:
					return __( 'Отправление отменено.', 'woodev-plugin-framework' );

				case Order_Actions::UPDATE:
					return __( 'Информация по заказу обновлена.', 'woodev-plugin-framework' );

				default:
					return __( 'Готово.', 'woodev-plugin-framework' );
			}
		}

		/**
		 * The Russian failure sentence for one action.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action one of {@see Order_Actions}' action ids.
		 * @return string
		 */
		private static function action_failure_message( string $action ): string {
			switch ( $action ) {
				case Order_Actions::EXPORT:
					return __( 'Не удалось выгрузить заказ перевозчику.', 'woodev-plugin-framework' );

				case Order_Actions::CANCEL:
					return __( 'Не удалось отменить отправление.', 'woodev-plugin-framework' );

				case Order_Actions::UPDATE:
					return __( 'Не удалось обновить информацию по заказу.', 'woodev-plugin-framework' );

				default:
					return __( 'Действие не выполнено.', 'woodev-plugin-framework' );
			}
		}

		/**
		 * A generic 502 for an action that threw rather than returning false.
		 *
		 * @since 2.0.2
		 *
		 * @return \WP_Error
		 */
		private static function action_upstream_error(): \WP_Error {
			return new \WP_Error(
				'woodev_shipping_orders_action_error',
				__( 'Сервис перевозчика временно недоступен. Попробуйте повторить действие позже.', 'woodev-plugin-framework' ),
				[ 'status' => 502 ]
			);
		}

		/**
		 * Logs an action failure. The browser only ever sees a generic 502.
		 *
		 * @since 2.0.2
		 *
		 * @param string     $provider_id carrier/tab id.
		 * @param string     $action      one of {@see Order_Actions}' action ids.
		 * @param \Throwable $exception   the caught failure.
		 * @return void
		 */
		private static function log_action_failure( string $provider_id, string $action, \Throwable $exception ): void {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a carrier failure; the browser only ever sees a generic 502.
				sprintf(
					'[woodev] shipping order action "%s" (%s) failed: %s',
					$action,
					$provider_id,
					\Woodev_API_Base::redact_secret_log_text( $exception->getMessage() )
				)
			);
		}
	}

endif;

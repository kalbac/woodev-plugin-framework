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
				return new \WP_Error(
					'woodev_shipping_orders_action_not_available',
					__( 'Это действие недоступно для данного заказа.', 'woodev-plugin-framework' ),
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
				$succeeded = $this->dispatch_action( $handler, $order, $action );
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
					'row'     => $this->build_row( $order, $provider ),
					'message' => self::action_success_message( $action ),
				]
			);
		}

		/**
		 * Dispatches one action to the carrier's shipment handler.
		 *
		 * ⚠ `export()` returns `''` on failure AND on a carrier response with no id
		 * (card #860) — a `''` return is NOT success. `cancel()`/`update()` already
		 * return bool.
		 *
		 * @since 2.0.2
		 *
		 * @param Abstract_Shipment_Handler $handler handler resolved for the order's carrier.
		 * @param \WC_Order                 $order   the order.
		 * @param string                    $action  one of {@see Order_Actions}' action ids.
		 * @return bool
		 */
		private function dispatch_action( Abstract_Shipment_Handler $handler, \WC_Order $order, string $action ): bool {
			switch ( $action ) {
				case Order_Actions::EXPORT:
					[ $settlement, $settlement_provider ] = $this->resolve_popular_settlement_context( $order );

					return '' !== $handler->export( $order, $settlement, $settlement_provider );

				case Order_Actions::CANCEL:
					return $handler->cancel( $order );

				case Order_Actions::UPDATE:
					return $handler->update( $order );

				default:
					return false;
			}
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

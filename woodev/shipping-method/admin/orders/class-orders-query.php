<?php
/**
 * Shipping orders — scope query
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Query' ) ) :

	/**
	 * Builds `wc_get_orders()` arguments from the {@see Orders_Registry} and runs them
	 * (SP-10 spec M2).
	 *
	 * All three shipped carrier plugins select their rows the same way — one
	 * `wc_get_orders()` call scoped to their marker key, `type` restricted to
	 * `wc_get_order_types( 'view-orders' )`, and `wc-cancelled`/`wc-failed` excluded
	 * from the status list. The aggregate (all carriers) view is the SAME single query
	 * matching ANY registered provider's marker key — never N queries stitched
	 * together.
	 *
	 * **The scope mechanism itself branches on the order datastore** (round 2 of this
	 * increment; round 1 assumed one mechanism covered both and was wrong):
	 *
	 * - HPOS: a real `meta_query` — measured correct against a real HPOS install on the
	 *   rig, 07.09.2026 (SP-10 spec M2).
	 * - Legacy CPT: `WC_Order_Data_Store_CPT` does not support `meta_query` at all — it
	 *   fires `_doing_it_wrong` (WC ≥9.2) and silently returns UNFILTERED results, so a
	 *   `meta_query` arg must never reach it. This class instead emits the
	 *   {@see self::QUERY_VAR_MARKER_KEYS} custom query var, which
	 *   {@see Orders_Registry::translate_marker_keys_query_var()} turns into a real
	 *   `meta_query` on WooCommerce's own `woocommerce_order_data_store_cpt_get_orders_query`
	 *   filter — the same technique all three shipped carrier plugins already use.
	 *
	 * @since 2.0.2
	 */
	class Orders_Query {

		/** @var int default page size, absent an explicit `per_page`. */
		const DEFAULT_PER_PAGE = 20;

		/**
		 * Custom query var carrying the marker meta keys in scope, for the legacy CPT
		 * datastore path. One var covers both the single-carrier and aggregate cases —
		 * it always carries an array, one entry for a single carrier, N for the
		 * aggregate, zero for "matches nothing".
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const QUERY_VAR_MARKER_KEYS = 'woodev_shipping_marker_keys';

		/**
		 * A `meta_query` clause that can never match a real order — used both for the
		 * zero-provider case and for a carrier id the registry does not recognize, so
		 * neither silently falls back to "matches everything". Shared by both datastore
		 * paths through {@see self::meta_query_for_keys()}, so they cannot silently
		 * diverge on what "matches nothing" means.
		 *
		 * @since 2.0.2
		 *
		 * @var array<int, array<string, string>>
		 */
		const NO_MATCH_META_QUERY = [
			[
				'key'     => '_woodev_shipping_orders_none',
				'value'   => '__woodev_shipping_orders_query_never_matches__',
				'compare' => '=',
			],
		];

		/**
		 * Registry to read providers from.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry|null $registry registry; defaults to the singleton.
		 */
		public function __construct( ?Orders_Registry $registry = null ) {
			$this->registry = $registry ?? Orders_Registry::instance();
		}

		/**
		 * Builds the `wc_get_orders()` args for a request (pure; injectable for tests).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request {
		 *     Optional. Request-shaped params.
		 *
		 *     @type string $carrier  provider id, or 'all'/''/omitted for the aggregate.
		 *     @type int    $page     1-based page number. Default 1.
		 *     @type int    $per_page page size. Default {@see self::DEFAULT_PER_PAGE}.
		 *     @type string $orderby  wc_get_orders() orderby. Default 'date'.
		 *     @type string $order    'ASC' or 'DESC'. Default 'DESC'.
		 *     @type string $search   free-text search term.
		 * }
		 * @return array<string,mixed>
		 */
		public function build_args( array $request = [] ): array {
			$carrier  = isset( $request['carrier'] ) ? (string) $request['carrier'] : '';
			$page     = isset( $request['page'] ) ? max( 1, (int) $request['page'] ) : 1;
			$per_page = isset( $request['per_page'] ) ? max( 1, (int) $request['per_page'] ) : self::DEFAULT_PER_PAGE;
			$orderby  = isset( $request['orderby'] ) && '' !== $request['orderby'] ? (string) $request['orderby'] : 'date';
			$order    = isset( $request['order'] ) ? strtoupper( (string) $request['order'] ) : 'DESC';
			$order    = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
			$search   = isset( $request['search'] ) ? trim( (string) $request['search'] ) : '';

			$args = [
				'type'     => wc_get_order_types( 'view-orders' ),
				'status'   => array_keys( array_diff_key( wc_get_order_statuses(), array_flip( [ 'wc-cancelled', 'wc-failed' ] ) ) ),
				'limit'    => $per_page,
				'paged'    => $page,
				'orderby'  => $orderby,
				'order'    => $order,
				'paginate' => true,
			];

			$keys = $this->resolve_marker_keys( $carrier );

			if ( $this->is_hpos_enabled() ) {
				$args['meta_query'] = self::meta_query_for_keys( $keys ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the framework's supported HPOS row-scope mechanism (SP-10 spec M2); never reached on the legacy CPT datastore (see class docblock).
			} else {
				// `meta_query` is unsupported on the legacy CPT datastore (fires
				// _doing_it_wrong and is silently ignored) — pass the custom query var
				// instead; Orders_Registry::translate_marker_keys_query_var() turns it
				// into the real meta_query on WooCommerce's own filter.
				$args[ self::QUERY_VAR_MARKER_KEYS ] = $keys;
			}

			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			/**
			 * Filters the built `wc_get_orders()` args before the query runs.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed> $args    built args.
			 * @param string              $carrier requested carrier id, or '' for the aggregate.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_query_args', $args, $carrier );

			return is_array( $filtered ) ? $filtered : $args;
		}

		/**
		 * Builds the args and runs the query.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request see {@see self::build_args()}.
		 * @return object wc_get_orders() paginated result (orders/total/max_num_pages).
		 */
		public function get_results( array $request = [] ) {
			return wc_get_orders( $this->build_args( $request ) );
		}

		/**
		 * Builds the `meta_query` shape for a set of marker keys.
		 *
		 * Shared by the HPOS path ({@see self::build_args()}) and the legacy-CPT
		 * translation ({@see Orders_Registry::translate_marker_keys_query_var()}), so
		 * the two paths cannot silently diverge on what "one carrier", "several
		 * carriers" or "matches nothing" means.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $keys marker meta keys; empty means "matches nothing".
		 * @return array<int|string, mixed>
		 */
		public static function meta_query_for_keys( array $keys ): array {
			if ( [] === $keys ) {
				return self::NO_MATCH_META_QUERY;
			}

			if ( 1 === count( $keys ) ) {
				return [
					[
						'key'     => reset( $keys ),
						'compare' => 'EXISTS',
					],
				];
			}

			$meta_query = [ 'relation' => 'OR' ];

			foreach ( $keys as $key ) {
				$meta_query[] = [
					'key'     => $key,
					'compare' => 'EXISTS',
				];
			}

			return $meta_query;
		}

		/**
		 * Resolves the marker meta keys in scope for one carrier or the aggregate.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier provider id, or 'all'/'' for the aggregate.
		 * @return string[] empty means "matches nothing" (unknown carrier, or zero providers).
		 */
		private function resolve_marker_keys( string $carrier ): array {
			if ( '' !== $carrier && 'all' !== $carrier ) {
				$provider = $this->registry->get_provider( $carrier );

				// An unrecognized carrier must never fall back to the aggregate.
				return null !== $provider ? [ $provider->get_marker_meta_key() ] : [];
			}

			return array_values(
				array_map(
					static function ( Orders_Provider $provider ): string {
						return $provider->get_marker_meta_key();
					},
					$this->registry->get_providers()
				)
			);
		}

		/**
		 * Whether the store uses HPOS. Thin wrapper over
		 * {@see \Woodev_Plugin_Compatibility::is_hpos_enabled()}, `protected` so a unit
		 * test can flip it deterministically — the real static call always reports
		 * `false` under Brain Monkey (no `OrderUtil` class is loaded there; see
		 * `PluginCompatibilityTest::is_hpos_enabled_returns_false_when_order_util_not_available()`),
		 * which is not a substitute for exercising the HPOS branch.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected function is_hpos_enabled(): bool {
			return \Woodev_Plugin_Compatibility::is_hpos_enabled();
		}
	}

endif;

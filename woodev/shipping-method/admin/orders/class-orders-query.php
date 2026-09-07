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
	 * `wc_get_orders()` call with a `meta_query` clause asserting their marker key
	 * `EXISTS`, `type` restricted to `wc_get_order_types( 'view-orders' )`, and
	 * `wc-cancelled`/`wc-failed` excluded from the status list. The aggregate (all
	 * carriers) view is the SAME single query with `relation => 'OR'` across every
	 * registered provider's marker key — measured on the rig against HPOS on
	 * 07.09.2026 (SP-10 spec M2) — never N queries stitched together.
	 *
	 * @since 2.0.2
	 */
	final class Orders_Query {

		/** @var int default page size, absent an explicit `per_page`. */
		const DEFAULT_PER_PAGE = 20;

		/**
		 * A `meta_query` clause that can never match a real order — used both for the
		 * zero-provider case and for a carrier id the registry does not recognize, so
		 * neither silently falls back to "matches everything".
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
				'type'       => wc_get_order_types( 'view-orders' ),
				'status'     => array_keys( array_diff_key( wc_get_order_statuses(), array_flip( [ 'wc-cancelled', 'wc-failed' ] ) ) ),
				'limit'      => $per_page,
				'paged'      => $page,
				'orderby'    => $orderby,
				'order'      => $order,
				'paginate'   => true,
				'meta_query' => $this->build_meta_query( $carrier ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the framework's supported HPOS-safe row-scope mechanism (SP-10 spec M2).
			];

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
		 * Builds the `meta_query` clause for one carrier or the aggregate.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier provider id, or 'all'/'' for the aggregate.
		 * @return array<int|string, mixed>
		 */
		private function build_meta_query( string $carrier ): array {
			if ( '' !== $carrier && 'all' !== $carrier ) {
				$provider = $this->registry->get_provider( $carrier );

				// An unrecognized carrier must never fall back to the aggregate.
				if ( null === $provider ) {
					return self::NO_MATCH_META_QUERY;
				}

				return [
					[
						'key'     => $provider->get_marker_meta_key(),
						'compare' => 'EXISTS',
					],
				];
			}

			$providers = $this->registry->get_providers();

			if ( [] === $providers ) {
				return self::NO_MATCH_META_QUERY;
			}

			$meta_query = [ 'relation' => 'OR' ];

			foreach ( $providers as $provider ) {
				$meta_query[] = [
					'key'     => $provider->get_marker_meta_key(),
					'compare' => 'EXISTS',
				];
			}

			return $meta_query;
		}
	}

endif;

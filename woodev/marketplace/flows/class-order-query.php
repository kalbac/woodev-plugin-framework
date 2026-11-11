<?php
/**
 * The one way the marketplace flows query orders by meta, on both order stores.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * `wc_get_orders()` honours `meta_query` on the HPOS store only; the posts (CPT) store drops the argument silently, so a lookup by
 * remote order number would return unrelated orders (spec 3.2). This helper passes the clauses through on HPOS and, on the CPT
 * store, carries them in a private query var and re-injects them into the `WP_Query` arguments through
 * `woocommerce_order_data_store_cpt_get_orders_query` (removing `meta_query` from the unsupported list for the duration of the call).
 *
 * Nothing else in a marketplace plugin should call `wc_get_orders()` with a `meta_query`.
 *
 * @since 2.1.0
 */
final class Order_Query {
	public const CPT_FILTER         = 'woocommerce_order_data_store_cpt_get_orders_query';
	public const UNSUPPORTED_FILTER = 'woocommerce_order_data_store_cpt_query_unsupported_args';

	/**
	 * Private query var that carries the token the CPT filter looks for.
	 *
	 * @var string
	 */
	private const QUERY_VAR = 'woodev_mp_meta_query_token';

	/**
	 * Returns the ids of the orders whose meta match the clauses, oldest id first.
	 *
	 * @since 2.1.0
	 *
	 * @param array<int|string,mixed> $clauses  `meta_query` clauses (`key`, `value`, `compare`).
	 * @param int                     $limit    Orders per page; -1 for all.
	 * @param int                     $page     1-based page.
	 * @param string[]                $statuses Order statuses to include; `any` for every status.
	 * @return int[]
	 */
	public static function ids_by_meta( array $clauses, int $limit = -1, int $page = 1, array $statuses = [ 'any' ] ): array {
		$args = [
			'return'     => 'ids',
			'type'       => 'shop_order',
			'limit'      => $limit,
			'paged'      => max( 1, $page ),
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'meta_query' => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The caller's own narrow order query.
		];

		// «Every status» is the absence of the argument: WooCommerce then queries all of its order statuses on both stores. The
		// literal `[ 'any' ]` is understood by the posts store and by the newest HPOS query only; WooCommerce 8.5 and 9.3 look for an
		// order whose status is the word "any" and find nothing.
		if ( [ 'any' ] !== $statuses ) {
			$args['status'] = $statuses;
		}

		$token     = wp_generate_uuid4();
		$filter    = static function ( $wp_query_args, $query_vars ) use ( $clauses, $token ) {
			if ( is_array( $wp_query_args ) && is_array( $query_vars ) && ( $query_vars[ self::QUERY_VAR ] ?? null ) === $token ) {
				$wp_query_args['meta_query'] = $clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The caller's own narrow order query.
			}

			return $wp_query_args;
		};
		$supported = static function ( $unsupported ) {
			return is_array( $unsupported ) ? array_values( array_diff( $unsupported, [ 'meta_query' ] ) ) : $unsupported;
		};

		$args[ self::QUERY_VAR ] = $token;

		add_filter( self::CPT_FILTER, $filter, 10, 2 );
		add_filter( self::UNSUPPORTED_FILTER, $supported );

		try {
			$found = wc_get_orders( $args );
		} finally {
			remove_filter( self::CPT_FILTER, $filter, 10 );
			remove_filter( self::UNSUPPORTED_FILTER, $supported );
		}

		return array_map(
			static function ( $id ): int {
				return (int) $id;
			},
			(array) $found
		);
	}

	/**
	 * Returns the orders whose meta match the clauses, oldest first, trashed orders left out (spec 3.2).
	 *
	 * @since 2.1.0
	 *
	 * @param array<int|string,mixed> $clauses `meta_query` clauses (`key`, `value`, `compare`).
	 * @param int                     $limit   Orders to return; -1 for all.
	 * @return \WC_Order[]
	 */
	public static function by_meta( array $clauses, int $limit = -1 ): array {
		$orders = [];

		foreach ( self::ids_by_meta( $clauses, $limit ) as $id ) {
			$order = wc_get_order( $id );

			if ( $order instanceof \WC_Order && 'trash' !== $order->get_status() ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}
}

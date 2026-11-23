<?php
/**
 * The orders list query of an «Источник» choice.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an {@see Order_Source_Choice} into query arguments for each order storage.
 *
 * An order came from a marketplace when its `created_via` is one the registered marketplaces' plugins create orders with. HPOS keeps
 * it in a column of the operational data table (`wc_get_orders()`: `created_via`, `field_query`), the posts table in the
 * `_created_via` post meta (`meta_query`). The shop's own orders are every order a marketplace did not create, an order with no
 * `created_via` at all among them.
 *
 * @since 2.1.13
 */
final class Order_Source_Query {
	/**
	 * Post meta key of the posts table's `created_via`.
	 */
	private const META_KEY = '_created_via';

	/**
	 * First WooCommerce version whose HPOS orders query understands `field_query`.
	 */
	private const ORDERS_TABLE_MIN_WC = '8.2';

	/**
	 * First WooCommerce version that lets the HPOS list skip its blank-state count.
	 */
	private const ORDERS_TABLE_COUNTS_MIN_WC = '8.6';

	/**
	 * Operational data column of HPOS's `created_via`.
	 */
	private const FIELD = 'created_via';

	/**
	 * The `created_via` values of the registered marketplaces.
	 *
	 * @var string[]
	 */
	private $created_vias;

	/**
	 * Returns the orders of an account: `function ( string $created_via, int $account_id ): int[]`.
	 *
	 * @var callable|null
	 */
	private $account_orders;

	/**
	 * Creates the query builder.
	 *
	 * @since 2.1.13
	 *
	 * @param string[]      $created_vias   The `created_via` values of the registered marketplaces.
	 * @param callable|null $account_orders `function ( string $created_via, int $account_id ): int[]`, the WooCommerce orders of an account; without it a choice of one account keeps nothing apart (since 2.1.16).
	 */
	public function __construct( array $created_vias, ?callable $account_orders = null ) {
		$this->created_vias   = array_values( $created_vias );
		$this->account_orders = $account_orders;
	}

	/**
	 * Adds the choice to the arguments of the HPOS list's `wc_get_orders()` call.
	 *
	 * An argument WooCommerce's own channel filter (`_created_via`) already set is left alone: the manager asked for that channel
	 * by name.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $args   Query arguments.
	 * @param string              $choice An {@see Order_Source_Choice} value.
	 * @return array<string,mixed>
	 */
	public function for_orders_table( array $args, string $choice ): array {
		if ( isset( $args['created_via'] ) ) {
			return $args;
		}

		$only = $this->only( $choice );

		if ( null !== $only ) {
			$args['created_via'] = $only;
			$ids                 = $this->post_ids( $choice, isset( $args['post__in'] ) ? (array) $args['post__in'] : [] );

			if ( null !== $ids ) {
				$args['post__in'] = $ids;
			}

			return $args;
		}

		if ( Order_Source_Choice::SHOP === $choice ) {
			$field_query         = $this->combine( 'OR', [ $this->field_clause( 'NOT IN' ), $this->field_clause( 'NOT EXISTS' ) ] );
			$args['field_query'] = empty( $args['field_query'] ) ? $field_query : $this->combine( 'AND', [ $args['field_query'], $field_query ] );
		}

		return $args;
	}

	/**
	 * Adds the choice to the `meta_query` of the posts list's main query.
	 *
	 * @since 2.1.13
	 *
	 * @param array<int|string,mixed> $meta_query The query's own meta query.
	 * @param string                  $choice     An {@see Order_Source_Choice} value.
	 * @return array<int|string,mixed>
	 */
	public function for_posts( array $meta_query, string $choice ): array {
		$only = $this->only( $choice );

		if ( null !== $only ) {
			$clause = $this->meta_clause( 'IN', $only );
		} elseif ( Order_Source_Choice::SHOP === $choice ) {
			$clause = $this->combine(
				'OR',
				[
					$this->meta_clause( 'NOT IN' ),
					[
						'key'     => self::META_KEY,
						'compare' => 'NOT EXISTS',
					],
				]
			);
		} else {
			return $meta_query;
		}

		// A list of clauses, never a bare one: WP_Meta_Query skips the `key`/`value` of a clause given at the top level.
		return empty( $meta_query ) ? [ $clause ] : $this->combine( 'AND', [ $meta_query, $clause ] );
	}

	/**
	 * Returns the `wc_get_orders()` arguments that select the orders a choice keeps: the list's own constraint, to count them.
	 *
	 * @since 2.1.13
	 *
	 * @param bool   $hpos   Whether orders live in the HPOS tables.
	 * @param string $choice An {@see Order_Source_Choice} value.
	 * @return array<string,mixed> Only the source constraint: the caller adds `limit`, `paginate` and the rest.
	 */
	public function matching( bool $hpos, string $choice ): array {
		if ( $hpos ) {
			return $this->for_orders_table( [], $choice );
		}

		$meta_query = $this->for_posts( [], $choice );
		$args       = [] === $meta_query ? [] : [ 'meta_query' => $meta_query ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one key, a count for a screen.
		$ids        = $this->post_ids( $choice, [] );

		if ( null !== $ids ) {
			$args['post__in'] = $ids;
		}

		return $args;
	}

	/**
	 * Returns the orders a choice of one account keeps, as the `post__in` of a list query: WooCommerce's own `post__in` of both
	 * storages, so the posts list and the HPOS list are narrowed the same way.
	 *
	 * @since 2.1.16
	 *
	 * @param string           $choice   An {@see Order_Source_Choice} value.
	 * @param array<int,mixed> $existing The `post__in` the query already carries (the posts list sets one for a search).
	 * @return int[]|null Null when the choice is not one account's; `[ 0 ]` when it keeps no order, because an empty `post__in` selects every order.
	 */
	public function post_ids( string $choice, array $existing ): ?array {
		$account = $this->account_of( $choice );

		if ( null === $account ) {
			return null;
		}

		$ids = array_map( 'intval', (array) call_user_func( $this->account_orders, $account[0], $account[1] ) );

		if ( [] !== $existing ) {
			$ids = array_intersect( $ids, array_map( 'intval', $existing ) );
		}

		return [] === $ids ? [ 0 ] : array_values( array_unique( $ids ) );
	}

	/**
	 * Tells whether the HPOS orders list can be filtered by source: the `field_query` that excludes the marketplaces' orders (the
	 * shop's own) exists since WooCommerce 8.2. The posts-table list needs no such version.
	 *
	 * @since 2.1.13
	 *
	 * @param bool        $hpos       Whether orders live in the HPOS tables.
	 * @param string|null $wc_version The installed WooCommerce version; null when unknown.
	 * @return bool
	 */
	public static function supports_storage( bool $hpos, ?string $wc_version ): bool {
		return ! $hpos || ( null !== $wc_version && version_compare( $wc_version, self::ORDERS_TABLE_MIN_WC, '>=' ) );
	}

	/**
	 * Tells whether the status counts of the HPOS orders list can follow the source: it asks for them through
	 * `woocommerce_shop_order_list_table_order_count` and may skip its blank-state count through
	 * `woocommerce_shop_order_list_table_should_render_blank_state`, a hook of WooCommerce 8.6.
	 *
	 * @since 2.1.13
	 *
	 * @param string|null $wc_version The installed WooCommerce version; null when unknown.
	 * @return bool
	 */
	public static function supports_orders_table_status_counts( ?string $wc_version ): bool {
		return null !== $wc_version && version_compare( $wc_version, self::ORDERS_TABLE_COUNTS_MIN_WC, '>=' );
	}

	/**
	 * The `created_via` values a choice keeps, when it keeps only some.
	 *
	 * @param string $choice An {@see Order_Source_Choice} value.
	 * @return string[]|null Null when the choice does not select by inclusion.
	 */
	private function only( string $choice ): ?array {
		if ( Order_Source_Choice::MARKETPLACES === $choice ) {
			return $this->created_vias;
		}

		if ( in_array( $choice, $this->created_vias, true ) ) {
			return [ $choice ];
		}

		$account = $this->account_of( $choice );

		return null === $account ? null : [ $account[0] ];
	}

	/**
	 * Splits a choice of one account of a registered marketplace.
	 *
	 * @param string $choice An {@see Order_Source_Choice} value.
	 * @return array{0:string,1:int}|null The `created_via` and the account id; null for any other choice, or without the orders lookup.
	 */
	private function account_of( string $choice ): ?array {
		$account = Order_Source_Choice::parse_account( $choice );

		return null !== $account && null !== $this->account_orders && in_array( $account[0], $this->created_vias, true ) ? $account : null;
	}

	/**
	 * Joins clauses into one `field_query` / `meta_query` group.
	 *
	 * @param string                             $relation `AND` or `OR`.
	 * @param array<int,array<int|string,mixed>> $clauses  Clauses.
	 * @return array<int|string,mixed>
	 */
	private function combine( string $relation, array $clauses ): array {
		return array_merge( [ 'relation' => $relation ], $clauses );
	}

	/**
	 * One HPOS `field_query` clause over the marketplaces' `created_via` values.
	 *
	 * @param string $compare `NOT IN`, or `NOT EXISTS` (a NULL column).
	 * @return array<string,mixed>
	 */
	private function field_clause( string $compare ): array {
		$clause = [
			'field'   => self::FIELD,
			'compare' => $compare,
		];

		if ( 'NOT IN' === $compare ) {
			$clause['value'] = $this->created_vias;
		}

		return $clause;
	}

	/**
	 * One post meta clause over the marketplaces' `created_via` values.
	 *
	 * @param string        $compare `IN` or `NOT IN`.
	 * @param string[]|null $values  Values; the registered marketplaces' when null.
	 * @return array<string,mixed>
	 */
	private function meta_clause( string $compare, ?array $values = null ): array {
		return [
			'key'     => self::META_KEY,
			'value'   => $values ?? $this->created_vias,
			'compare' => $compare,
		];
	}
}

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
	 * Creates the query builder.
	 *
	 * @since 2.1.13
	 *
	 * @param string[] $created_vias The `created_via` values of the registered marketplaces.
	 */
	public function __construct( array $created_vias ) {
		$this->created_vias = array_values( $created_vias );
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

		return empty( $meta_query ) ? $clause : $this->combine( 'AND', [ $meta_query, $clause ] );
	}

	/**
	 * Returns the `wc_get_orders()` arguments that count the orders of every registered marketplace.
	 *
	 * @since 2.1.13
	 *
	 * @param bool $hpos Whether orders live in the HPOS tables.
	 * @return array<string,mixed> Only the source constraint: the caller adds `limit`, `paginate` and the rest.
	 */
	public function marketplace_orders( bool $hpos ): array {
		if ( $hpos ) {
			return [ 'created_via' => $this->created_vias ];
		}

		return [
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key, a count for a screen.
				$this->meta_clause( 'IN', $this->created_vias ),
			],
		];
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

		return null;
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

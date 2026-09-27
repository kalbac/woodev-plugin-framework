<?php
/**
 * Shipping orders — id resolution for the scope query
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Id_Resolver' ) ) :

	/**
	 * Resolves the `meta_query` tree {@see Orders_Query} builds to the ids of the orders
	 * that satisfy it, with ONE flat statement against the order-meta table (#928).
	 *
	 * **Why the tree is not handed to the datastore as `meta_query`.** `WP_Meta_Query`
	 * and HPOS `OrdersTableMetaQuery` give every OR-ed `EXISTS` clause its own
	 * un-predicated `JOIN` on the meta table (`ON order = id`; the `meta_key` test sits
	 * in `WHERE`), so an order with `d` meta rows costs `~d^N` row combinations for `N`
	 * registered carriers. Measured (`docs-internal/research/2026-09-26-928-form-measurement/`):
	 * the UNFILTERED orders page at four carriers took 11.7 s per 10 000 orders at the
	 * dev rig's own meta density, and sites with four or more carrier plugins exist.
	 *
	 * **The form.** One driver row per (order, registered marker) — `mk.meta_key IN
	 * (<marker keys>)`, an index range on `meta_key` — and every leaf of the tree as a
	 * correlated `EXISTS` / `NOT EXISTS` subquery on the same table, predicated on the
	 * driver's order id AND the leaf's key AND (where the leaf has one) its value. Nothing
	 * is joined: each subquery is one index lookup per driver row, so the cost is
	 * `leaves × orders-in-scope`, linear in `N`. The ids come back through `post__in`,
	 * which both datastores support (CPT: `WP_Query` `ID IN (…)`; HPOS:
	 * `OrdersTableQuery::maybe_remap_args()` maps it to `id`), so `paginate` / `total` /
	 * `max_num_pages` follow the list by construction.
	 *
	 * **Row semantics are the tree's, not a cheaper reading of it.** `EXISTS` ⇔ a row
	 * with that key exists; `NOT EXISTS` ⇔ none does; `IN` / `NOT IN` / `=` / `!=` ⇔ a row
	 * with that key exists AND its value compares so — exactly how `WP_Meta_Query` joins
	 * them (a `NOT IN` does NOT match an order with no such row at all, gotcha
	 * `a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all`). Every
	 * negative clause the builder emits stays bound to its own provider's marker inside
	 * the tree, so this form does NOT lean on the one-marker-per-order rule (#919 Q4) —
	 * it is equivalent to the `meta_query` on every order, multi-marker ones included.
	 * The unit gate `ShippingOrdersQueryRowSemanticsTest` walks the same tree against an
	 * independent oracle; `ShippingOrdersIdResolverTest` pins this SQL's shape.
	 *
	 * **One leaf is not a meta clause** (#843, «Любое»): {@see self::ORDER_STATUS_LEAF},
	 * the order's own status, compiled against the order table's status column of the
	 * active datastore. It exists so `match=any` can OR the order-status filter with the
	 * meta-based ones inside THIS statement — an OR of them in the main `wc_get_orders`
	 * query is exactly the un-predicated join per key this class avoids.
	 *
	 * ⚠ The id list is the size of the RESULT, not of the page — ~6 bytes per id in the
	 * main query's SQL, linear in the store; ~3 M carrier orders would reach MariaDB's
	 * 16 MB default `max_allowed_packet`. And an EMPTY list must never reach `post__in`:
	 * both datastores read `[]` as "argument not set" and return every order.
	 * {@see Orders_Query::build_args()} routes an empty result through
	 * {@see Orders_Query::NO_MATCH_META_QUERY} instead.
	 *
	 * @since 2.0.2
	 */
	class Orders_Id_Resolver {

		/**
		 * Alias of the driver row (one per order and registered marker).
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const DRIVER_ALIAS = 'mk';

		/**
		 * Alias of the row each leaf's correlated subquery looks for.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const LEAF_ALIAS = 'm';

		/**
		 * Alias of the order row a status leaf's correlated subquery looks at.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const ORDER_ALIAS = 'o';

		/**
		 * The marker of the ONE leaf kind that is not a meta clause (#843): «the order's
		 * own status is one of these». A leaf of the tree is
		 * `[ self::ORDER_STATUS_LEAF => [ 'wc-processing', … ] ]` — no `key`, no `compare`,
		 * so it can never be mistaken for a meta clause (or read as a nested group) by
		 * anything that walks the tree, and it never reaches `WP_Meta_Query`: the tree only
		 * ever goes through this class.
		 *
		 * Order status lives on the order itself, not in the meta table, so the leaf is
		 * compiled against the COLUMN of the active datastore — HPOS `{wc_orders}.status`,
		 * legacy CPT `{posts}.post_status` (both hold the `wc-`-prefixed slug) — as a
		 * correlated `EXISTS` on the driver's order id, the same shape every meta leaf has:
		 * one primary-key lookup per driver row, no join. {@see Orders_Query} emits it only
		 * under `match=any` (under `all` the status stays a native `status` arg), and always
		 * with a NON-EMPTY list: a status request nothing in which is real is the
		 * sentinel, not an empty leaf.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const ORDER_STATUS_LEAF = 'order_status';

		/**
		 * Database connection — `$wpdb`, or a stand-in exposing `prepare()`, `get_col()`,
		 * `last_error`, `prefix`, `posts` and `postmeta` (unit tests).
		 *
		 * @since 2.0.2
		 *
		 * @var \wpdb
		 */
		private $wpdb;

		/**
		 * Whether the HPOS order tables are the active datastore.
		 *
		 * @since 2.0.2
		 *
		 * @var bool
		 */
		private $hpos;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param \wpdb $wpdb database connection.
		 * @param bool  $hpos true => HPOS `wc_orders_meta`; false => legacy `postmeta`.
		 */
		public function __construct( $wpdb, bool $hpos ) {
			$this->wpdb = $wpdb;
			$this->hpos = $hpos;
		}

		/**
		 * Runs the id query and returns the matching order ids.
		 *
		 * @since 2.0.2
		 *
		 * @param string[]                $marker_keys registered marker keys in scope — the driver;
		 *                                             empty means "matches nothing" and runs no query.
		 * @param array<int|string,mixed> $meta_query  the tree the ids must satisfy.
		 * @return int[] distinct positive ids, in no particular order; empty when nothing matches.
		 */
		public function resolve( array $marker_keys, array $meta_query ): array {
			if ( [] === $marker_keys ) {
				return [];
			}

			$sql = $this->compile( $marker_keys, $meta_query );

			$rows = $this->wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the one flat id query of the orders page (#928); every literal in $sql went through $wpdb->prepare() in compile(); caching is decided by the caller (the badge already caches its counts).

			$error = (string) $this->wpdb->last_error;

			if ( '' !== $error ) {
				// Fail CLOSED, as before: nothing came back, so the caller gets `[]` → the
				// «matches nothing» sentinel, never every order. The only change is that a
				// broken statement no longer looks like an honest empty result (#936).
				$this->log_query_failure( $error );

				return [];
			}

			if ( ! is_array( $rows ) ) {
				return [];
			}

			$ids = [];

			foreach ( $rows as $row ) {
				$id = (int) $row;

				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}

			return array_values( $ids );
		}

		/**
		 * Writes a failed id query to the PHP error log (#936) — the same `[woodev]`
		 * `error_log()` diagnostic the rest of the shipping subsystem uses. The text never
		 * reaches a screen, so it is neither translated nor escaped.
		 *
		 * @since 2.0.2
		 *
		 * @param string $error `$wpdb->last_error` after the failed statement.
		 * @return void
		 */
		private function log_query_failure( string $error ): void {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a failed id query; the merchant only ever sees an empty orders page.
				sprintf( '[woodev] shipping orders id query failed, the page shows no orders: %s', $error )
			);
		}

		/**
		 * Builds the id query's SQL for the active datastore. Public so its shape can be
		 * pinned without a database (`ShippingOrdersIdResolverTest`).
		 *
		 * A top-level part of the tree that IS the marker-key scope
		 * ({@see Orders_Query::meta_query_for_keys()} over `$marker_keys`), byte for byte,
		 * is not emitted again: the driver predicate already states it. That covers the
		 * scope part itself and, when every carrier in scope lacks a status concept, a
		 * `unknown` / `is not <canonical>` filter part that happens to be exactly the same
		 * OR of marker `EXISTS` clauses. The short cut is taken only under an `AND` root
		 * (or when the whole tree is the scope) — under an `OR` root dropping a disjunct
		 * would change the meaning, so there it is compiled like any other node.
		 *
		 * @since 2.0.2
		 *
		 * @param string[]                $marker_keys registered marker keys in scope; must not be empty.
		 * @param array<int|string,mixed> $meta_query  the tree the ids must satisfy.
		 * @return string
		 *
		 * @throws \InvalidArgumentException when `$marker_keys` is empty, or the tree carries a
		 *                                   compare this class does not implement.
		 */
		public function compile( array $marker_keys, array $meta_query ): string {
			if ( [] === $marker_keys ) {
				throw new \InvalidArgumentException( 'Orders_Id_Resolver: an empty marker-key scope matches nothing and must not be compiled.' );
			}

			$table     = $this->meta_table();
			$id_column = $this->id_column();
			$scope     = Orders_Query::meta_query_for_keys( $marker_keys );

			$where = [
				sprintf( '%s.meta_key IN (%s)', self::DRIVER_ALIAS, $this->quote_list( $marker_keys ) ),
			];

			// The whole tree being the scope, the driver says it all. An AND root's parts
			// join the driver's own AND list, the scope part left out; an OR root is one term.
			if ( $meta_query !== $scope ) {
				$where = 'AND' === self::relation_of( $meta_query )
					? array_merge( $where, $this->compile_terms( $meta_query, $table, $id_column, $scope ) )
					: array_merge( $where, [ $this->compile_group( $meta_query, $table, $id_column ) ] );
			}

			return sprintf(
				'SELECT DISTINCT %1$s.%2$s FROM %3$s AS %1$s WHERE %4$s',
				self::DRIVER_ALIAS,
				$id_column,
				$table,
				implode( ' AND ', $where )
			);
		}

		/**
		 * The `relation` of a group, normalised: anything but `OR` is `AND`, as in
		 * `WP_Meta_Query`.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int|string,mixed> $group the group.
		 * @return string 'AND' or 'OR'.
		 */
		private static function relation_of( array $group ): string {
			return 'OR' === strtoupper( (string) ( $group['relation'] ?? 'AND' ) ) ? 'OR' : 'AND';
		}

		/**
		 * Compiles the children of one `relation` group, one boolean expression each.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int|string,mixed> $group     the group.
		 * @param string                  $table     meta table name.
		 * @param string                  $id_column order-id column of that table.
		 * @param array<int|string,mixed> $skip      a child to leave out (the scope part, under an
		 *                                           `AND` group only); `[]` to skip nothing.
		 * @return string[]
		 */
		private function compile_terms( array $group, string $table, string $id_column, array $skip = [] ): array {
			$skips = 'AND' === self::relation_of( $group ) && [] !== $skip;
			$terms = [];

			foreach ( $group as $key => $child ) {
				if ( 'relation' === $key || ! is_array( $child ) ) {
					continue;
				}

				if ( $skips && $child === $skip ) {
					continue;
				}

				if ( isset( $child['key'] ) ) {
					$terms[] = $this->compile_leaf( $child, $table, $id_column );
				} elseif ( isset( $child[ self::ORDER_STATUS_LEAF ] ) ) {
					$terms[] = $this->compile_order_status_leaf( (array) $child[ self::ORDER_STATUS_LEAF ], $id_column );
				} else {
					$terms[] = $this->compile_group( $child, $table, $id_column );
				}
			}

			return $terms;
		}

		/**
		 * Compiles one `relation` group of the tree to a single boolean expression.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int|string,mixed> $group     the group.
		 * @param string                  $table     meta table name.
		 * @param string                  $id_column order-id column of that table.
		 * @return string
		 */
		private function compile_group( array $group, string $table, string $id_column ): string {
			$terms = $this->compile_terms( $group, $table, $id_column );

			if ( [] === $terms ) {
				// `WP_Meta_Query` treats an empty group as no condition; so does the oracle.
				return '1=1';
			}

			if ( 1 === count( $terms ) ) {
				// A one-child group (a single-clause part, or a part wrapping one AND
				// group) adds no meaning — and no parentheses.
				return $terms[0];
			}

			return '(' . implode( ' ' . self::relation_of( $group ) . ' ', $terms ) . ')';
		}

		/**
		 * Compiles one leaf clause to a correlated subquery predicated on the driver's
		 * order id, the leaf's key and — where the compare has one — its value.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $leaf      a clause carrying `key`, `compare` and maybe `value`.
		 * @param string              $table     meta table name.
		 * @param string              $id_column order-id column of that table.
		 * @return string
		 *
		 * @throws \InvalidArgumentException on a compare this class does not implement, or an
		 *                                   `IN` / `NOT IN` with no values — the builder never
		 *                                   emits either, and a new shape must be added here on
		 *                                   purpose, not compiled by accident.
		 */
		private function compile_leaf( array $leaf, string $table, string $id_column ): string {
			$compare = strtoupper( trim( (string) ( $leaf['compare'] ?? '=' ) ) );
			$inner   = sprintf(
				'SELECT 1 FROM %1$s AS %2$s WHERE %2$s.%3$s = %4$s.%3$s AND %2$s.meta_key = %5$s',
				$table,
				self::LEAF_ALIAS,
				$id_column,
				self::DRIVER_ALIAS,
				$this->quote( (string) $leaf['key'] )
			);

			switch ( $compare ) {
				case 'EXISTS':
					return "EXISTS ({$inner})";

				case 'NOT EXISTS':
					return "NOT EXISTS ({$inner})";

				case 'IN':
				case 'NOT IN':
					$values = array_map( 'strval', (array) ( $leaf['value'] ?? [] ) );

					if ( [] === $values ) {
						throw new \InvalidArgumentException( "Orders_Id_Resolver: '{$compare}' with no values for key '{$leaf['key']}'." );
					}

					return sprintf( 'EXISTS (%s AND %s.meta_value %s (%s))', $inner, self::LEAF_ALIAS, $compare, $this->quote_list( $values ) );

				case '=':
				case '!=':
					return sprintf( 'EXISTS (%s AND %s.meta_value %s %s)', $inner, self::LEAF_ALIAS, $compare, $this->quote( (string) ( $leaf['value'] ?? '' ) ) );
			}

			throw new \InvalidArgumentException( "Orders_Id_Resolver: unhandled compare '{$compare}' for key '{$leaf['key']}'." );
		}

		/**
		 * Compiles the order-status leaf ({@see self::ORDER_STATUS_LEAF}) to a correlated
		 * subquery on the active datastore's ORDER table, keyed on the driver's order id.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $statuses  `wc-`-prefixed order statuses; must not be empty.
		 * @param string   $id_column order-id column of the META table (the driver's).
		 * @return string
		 *
		 * @throws \InvalidArgumentException on an empty list — the builder turns "no real
		 *                                   status" into the sentinel, and an empty `IN ()`
		 *                                   is not SQL.
		 */
		private function compile_order_status_leaf( array $statuses, string $id_column ): string {
			$statuses = array_map( 'strval', array_values( $statuses ) );

			if ( [] === $statuses ) {
				throw new \InvalidArgumentException( 'Orders_Id_Resolver: an order-status leaf with no statuses.' );
			}

			return sprintf(
				'EXISTS (SELECT 1 FROM %1$s AS %2$s WHERE %2$s.%3$s = %4$s.%5$s AND %2$s.%6$s IN (%7$s))',
				$this->hpos ? $this->wpdb->prefix . 'wc_orders' : $this->wpdb->posts,
				self::ORDER_ALIAS,
				$this->hpos ? 'id' : 'ID',
				self::DRIVER_ALIAS,
				$id_column,
				$this->hpos ? 'status' : 'post_status',
				$this->quote_list( $statuses )
			);
		}

		/**
		 * The order-meta table of the active datastore.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function meta_table(): string {
			return $this->hpos ? $this->wpdb->prefix . 'wc_orders_meta' : $this->wpdb->postmeta;
		}

		/**
		 * The order-id column of that table.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function id_column(): string {
			return $this->hpos ? 'order_id' : 'post_id';
		}

		/**
		 * One value, quoted and escaped by `$wpdb->prepare()`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $value raw value.
		 * @return string
		 */
		private function quote( string $value ): string {
			return (string) $this->wpdb->prepare( '%s', $value );
		}

		/**
		 * A comma-separated list of quoted values.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $values raw values.
		 * @return string
		 */
		private function quote_list( array $values ): string {
			return implode( ',', array_map( [ $this, 'quote' ], $values ) );
		}
	}

endif;

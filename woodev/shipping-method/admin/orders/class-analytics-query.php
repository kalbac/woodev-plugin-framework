<?php
/**
 * Delivery analytics — the aggregate SQL
 *
 * @since 2.0.24
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\{Delivery_Status, Shipment_Cancellation};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Analytics_Query' ) ) :

	/**
	 * Reads the delivery aggregates of the «Аналитика» tab (#711) with a handful of `GROUP BY` statements —
	 * never by loading orders.
	 *
	 * **The shape is {@see Orders_Id_Resolver}'s** (#928): one DRIVER per provider — the distinct orders that
	 * carry its marker meta — and everything else a correlated one-row subquery, so the cost is linear in the
	 * orders that carry a marker. Each provider contributes one `LEG` (a `SELECT` of one row per order); the legs are
	 * `UNION ALL`-ed into a derived table and three outer statements group it by carrier, by carrier and day, and
	 * by shipping method.
	 *
	 * **Who owns an order.** The first REGISTERED provider with a non-empty marker, exactly as
	 * {@see Orders_Registry::resolve_provider_for_order()} decides; a later leg skips an order an earlier marker
	 * already claimed, so a multi-marker order (CDEK writes one per package) is counted once.
	 *
	 * **Both datastores.** The `$hpos` flag picks the table pair and the column expressions ({@see self::source()}):
	 * HPOS `wc_orders` + `wc_order_operational_data` + `wc_orders_meta`, or the legacy `posts` + `postmeta`. The
	 * rows come back identical, which is what the unit tests pin.
	 *
	 * **Binding.** Every value reaches the statement through a NUMBERED placeholder (`%1$s`, `%2$d`, …) registered by
	 * {@see self::bind()}, so a fragment can be composed in any order and each statement is one `prepare()` call. Table
	 * names come from `$wpdb`; the literals inside the SQL are class constants.
	 *
	 * @since 2.0.24
	 */
	class Analytics_Query {

		/**
		 * The longest order-to-delivery time the average accepts, in seconds (180 days): a manually completed outlier
		 * must not poison the mean.
		 *
		 * @var int
		 */
		public const MAX_DELIVERY_SECONDS = 15552000;

		/**
		 * Raw canonical states of each outcome bucket ({@see Analytics_Aggregator::BUCKETS}); `cancelled` and `unknown`
		 * are decided before and after these.
		 *
		 * @var array<string,string[]>
		 */
		private const BUCKET_STATES = [
			'delivered'   => [ Delivery_Status::DELIVERED ],
			'returned'    => [ Delivery_Status::RETURNING, Delivery_Status::RETURNED ],
			'failed'      => [ Delivery_Status::FAILED ],
			'in_progress' => [ Delivery_Status::PENDING, Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT, Delivery_Status::READY_FOR_PICKUP ],
			'cancelled'   => [ Delivery_Status::CANCELLED ],
		];

		/**
		 * Database connection.
		 *
		 * @var \wpdb
		 */
		private $wpdb;

		/**
		 * Whether the HPOS order tables are the active datastore.
		 *
		 * @var bool
		 */
		private $hpos;

		/**
		 * Constructor.
		 *
		 * @since 2.0.24
		 *
		 * @param \wpdb $wpdb database connection.
		 * @param bool  $hpos true => HPOS tables; false => legacy `posts` / `postmeta`.
		 */
		public function __construct( $wpdb, bool $hpos ) {
			$this->wpdb = $wpdb;
			$this->hpos = $hpos;
		}

		/**
		 * One row per carrier and day — the ONE pass over the orders that feeds the carrier table, the totals and the chart: orders,
		 * money, outcome counts and the order-to-delivery time sums. The carrier rows are these rows added up per carrier
		 * ({@see Analytics_Aggregator::carrier_rows()}), a few hundred aggregate rows, never orders.
		 *
		 * @since 2.0.24
		 *
		 * @param Orders_Provider[]   $providers registered providers, in registry order.
		 * @param array<string,mixed> $scope     {@see self::day_sql()}.
		 * @param string|null         $only      one provider id to restrict to, or null for all.
		 * @return array<int,array<string,mixed>> raw rows.
		 */
		public function day_rows( array $providers, array $scope, ?string $only = null ): array {
			return $this->run( $this->day_sql( $providers, $scope, $only ) );
		}

		/**
		 * One row per carrier and shipping method.
		 *
		 * @since 2.0.24
		 *
		 * @param Orders_Provider[]   $providers registered providers, in registry order.
		 * @param array<string,mixed> $scope     {@see self::day_sql()}.
		 * @param string|null         $only      one provider id to restrict to, or null for all.
		 * @return array<int,array<string,mixed>> raw rows.
		 */
		public function method_rows( array $providers, array $scope, ?string $only = null ): array {
			return $this->run( $this->method_sql( $providers, $scope, $only ) );
		}

		/**
		 * The condition that an order counts for the average time: delivered, with a completion after the order, within the bound.
		 *
		 * @param array<int,mixed> $args statement arguments so far; appended to.
		 * @return string
		 */
		private function timed_sql( array &$args ): string {
			return "x.bucket = 'delivered' AND x.diff > 0 AND x.diff <= " . $this->bind( $args, self::MAX_DELIVERY_SECONDS, 'd' );
		}

		/**
		 * The statement behind {@see self::day_rows()}.
		 *
		 * @since 2.0.24
		 *
		 * @param Orders_Provider[]   $providers registered providers, in registry order.
		 * @param array<string,mixed> $scope     {
		 *     The window and the population.
		 *
		 *     @type string   $from     inclusive lower bound, GMT `Y-m-d H:i:s`.
		 *     @type string   $to       EXCLUSIVE upper bound, GMT `Y-m-d H:i:s`.
		 *     @type int      $offset   site UTC offset in seconds, used only to bucket days.
		 *     @type string[] $excluded WooCommerce order statuses that are not part of the population.
		 * }
		 * @param string|null         $only      one provider id to restrict to, or null for all.
		 * @return array{0:string,1:array<int,mixed>}|null `[ sql, args ]`, or null when no provider is in scope.
		 */
		public function day_sql( array $providers, array $scope, ?string $only = null ): ?array {
			$args = [];
			$legs = $this->legs( $providers, $scope, $only, $args, false );

			if ( '' === $legs ) {
				return null;
			}

			$buckets = [];
			foreach ( Analytics_Aggregator::BUCKETS as $bucket ) {
				$buckets[] = "SUM( x.bucket = '" . $bucket . "' ) AS " . $bucket;
			}

			// Each use of the bound is its OWN argument: `wpdb::prepare()` counts placeholder occurrences against arguments and
			// raises a `_doing_it_wrong` notice (HTML in the REST body under WP_DEBUG) when one numbered placeholder is used twice.
			$select = 'SELECT x.carrier, x.day, COUNT(*) AS orders, SUM( x.total ) AS revenue, SUM( x.ship ) AS shipping_paid, '
				. implode( ', ', $buckets )
				. ', SUM( CASE WHEN ' . $this->timed_sql( $args ) . ' THEN 1 ELSE 0 END ) AS time_sample'
				. ', SUM( CASE WHEN ' . $this->timed_sql( $args ) . ' THEN x.diff ELSE 0 END ) AS time_seconds';

			return [ $select . ' FROM ( ' . $legs . ' ) x GROUP BY x.carrier, x.day', $args ];
		}

		/**
		 * The statement behind {@see self::method_rows()}: the population's shipping LINES grouped by method.
		 *
		 * The line's price is itemmeta `cost` plus `total_tax` (measured on the rig: a shipping item stores `method_id`,
		 * `instance_id`, `cost`, `total_tax`, `taxes`).
		 *
		 * **The population drives, the items are probed.** The legs are the SLIM ones — carrier, order id and total only, with no status, day
		 * or completion time, which this table does not need and which cost two thirds of a full leg — and the statement joins them to
		 * `woocommerce_order_items` by its `order_id` index (a core table: no index of ours is added to it). `STRAIGHT_JOIN` pins that
		 * order: left to itself, the optimizer drives from a scan of the itemmeta table (154 000 rows at 50 000 orders) for a year or a
		 * quarter and from the population for a month — the same statement, a 40 % swing in the time it takes.
		 *
		 * **Revenue is summed once per distinct (carrier, method, order).** The inner statement collapses an order's lines of one
		 * method into a single row — carrying the order total once, the lines counted and their prices summed — and only then does
		 * the outer statement add the orders up, so an order with two lines of the same method adds its total once. An order with two
		 * DIFFERENT methods still counts in both (the method rows are not additive, which the tab's footnote says).
		 *
		 * @since 2.0.24
		 *
		 * @param Orders_Provider[]   $providers registered providers, in registry order.
		 * @param array<string,mixed> $scope     {@see self::day_sql()}.
		 * @param string|null         $only      one provider id to restrict to, or null for all.
		 * @return array{0:string,1:array<int,mixed>}|null `[ sql, args ]`, or null when no provider is in scope.
		 */
		public function method_sql( array $providers, array $scope, ?string $only = null ): ?array {
			$args = [];
			$legs = $this->legs( $providers, $scope, $only, $args, true );

			if ( '' === $legs ) {
				return null;
			}

			$prefix = $this->wpdb->prefix;
			$price  = 'COALESCE( CAST( cost.meta_value AS DECIMAL(18,4) ), 0 ) + COALESCE( CAST( tax.meta_value AS DECIMAL(18,4) ), 0 )';

			$per_order = 'SELECT STRAIGHT_JOIN x.carrier, method.meta_value AS method_id, x.order_id, MAX( x.total ) AS total, COUNT(*) AS line_count, SUM( ' . $price . ' ) AS shipping_paid'
				. ' FROM ( ' . $legs . ' ) x'
				. ' JOIN ' . $prefix . "woocommerce_order_items line ON line.order_id = x.order_id AND line.order_item_type = 'shipping'"
				. ' JOIN ' . $prefix . "woocommerce_order_itemmeta method ON method.order_item_id = line.order_item_id AND method.meta_key = 'method_id'"
				. ' LEFT JOIN ' . $prefix . "woocommerce_order_itemmeta cost ON cost.order_item_id = line.order_item_id AND cost.meta_key = 'cost'"
				. ' LEFT JOIN ' . $prefix . "woocommerce_order_itemmeta tax ON tax.order_item_id = line.order_item_id AND tax.meta_key = 'total_tax'"
				. ' GROUP BY x.carrier, method.meta_value, x.order_id';

			$sql = 'SELECT m.carrier, m.method_id, COUNT(*) AS orders, SUM( m.line_count ) AS line_count, SUM( m.shipping_paid ) AS shipping_paid, SUM( m.total ) AS revenue'
				. ' FROM ( ' . $per_order . ' ) m GROUP BY m.carrier, m.method_id';

			return [ $sql, $args ];
		}

		/**
		 * The `UNION ALL` of the legs in scope, with their arguments appended to `$args`.
		 *
		 * @param Orders_Provider[]   $providers registered providers, in registry order.
		 * @param array<string,mixed> $scope     the window.
		 * @param string|null         $only      one provider id to restrict to, or null for all.
		 * @param array<int,mixed>    $args      statement arguments so far; appended to.
		 * @param bool                $slim      true => each leg is just `carrier`, `order_id`, `total` (the shipping-method statement).
		 * @return string empty when no provider is in scope.
		 */
		private function legs( array $providers, array $scope, ?string $only, array &$args, bool $slim ): string {
			$legs    = [];
			$earlier = [];

			foreach ( $providers as $provider ) {
				if ( null === $only || $only === $provider->get_id() ) {
					$legs[] = $slim ? $this->slim_leg( $provider, $earlier, $scope, $args ) : $this->leg( $provider, $earlier, $scope, $args );
				}

				$earlier[] = $provider->get_marker_meta_key();
			}

			return implode( ' UNION ALL ', $legs );
		}

		/**
		 * One provider's leg: a row per order with its money, day, completion time and outcome bucket.
		 *
		 * @param Orders_Provider     $provider provider.
		 * @param string[]            $earlier  marker keys of the providers registered before this one.
		 * @param array<string,mixed> $scope    the window.
		 * @param array<int,mixed>    $args     statement arguments so far; appended to.
		 * @return string
		 */
		private function leg( Orders_Provider $provider, array $earlier, array $scope, array &$args ): string {
			return 'SELECT t.carrier, t.order_id, t.total, t.ship, t.day, t.created_gmt,'
				. ' TIMESTAMPDIFF( SECOND, t.created_gmt, t.completed_gmt ) AS diff, '
				. $this->bucket_sql( $provider, $args ) . ' AS bucket'
				. ' FROM ( ' . $this->inner_select( $provider, $earlier, $scope, $args ) . ' ) t';
		}

		/**
		 * One provider's SLIM leg: the orders of the window with only their carrier, id and total.
		 *
		 * @param Orders_Provider     $provider provider.
		 * @param string[]            $earlier  marker keys of the providers registered before this one.
		 * @param array<string,mixed> $scope    the window.
		 * @param array<int,mixed>    $args     statement arguments so far; appended to.
		 * @return string
		 */
		private function slim_leg( Orders_Provider $provider, array $earlier, array $scope, array &$args ): string {
			$src = $this->source();

			$select = 'SELECT ' . $this->bind( $args, $provider->get_id(), 's' ) . ' AS carrier, ' . $src['id'] . ' AS order_id, ' . $src['total'] . ' AS total';

			return $select . $this->population_sql( $provider, $earlier, $scope, $args, '' );
		}

		/**
		 * The inner select of a leg: the provider's orders in the window, one row each, raw status and cancellation flag included.
		 *
		 * @param Orders_Provider     $provider provider.
		 * @param string[]            $earlier  marker keys of the providers registered before this one.
		 * @param array<string,mixed> $scope    the window.
		 * @param array<int,mixed>    $args     statement arguments so far; appended to.
		 * @return string
		 */
		private function inner_select( Orders_Provider $provider, array $earlier, array $scope, array &$args ): string {
			$src     = $this->source();
			$created = $src['created'];

			$select = 'SELECT ' . $this->bind( $args, $provider->get_id(), 's' ) . ' AS carrier, ' . $src['id'] . ' AS order_id,'
				. ' ' . $src['total'] . ' AS total, ' . $src['ship'] . ' AS ship,'
				. ' DATE( DATE_ADD( ' . $created . ', INTERVAL ' . $this->bind( $args, (int) $scope['offset'], 'd' ) . ' SECOND ) ) AS day,'
				. ' ' . $created . ' AS created_gmt, ' . $src['completed'] . ' AS completed_gmt,'
				. ' ' . $this->meta_exists_sql( Shipment_Cancellation::CANCELLED_AT_META, $args ) . ' AS cancelled,'
				. ' ' . $this->raw_status_sql( $provider, $args ) . ' AS raw_status';

			return $select . $this->population_sql( $provider, $earlier, $scope, $args, $src['join'] );
		}

		/**
		 * The FROM and WHERE of a leg: the provider's marked orders, in the window, outside the excluded statuses, not claimed by an
		 * earlier provider.
		 *
		 * @param Orders_Provider     $provider provider.
		 * @param string[]            $earlier  marker keys of the providers registered before this one.
		 * @param array<string,mixed> $scope    the window.
		 * @param array<int,mixed>    $args     statement arguments so far; appended to.
		 * @param string              $join     extra JOIN clause the select list needs (HPOS operational data), or an empty string.
		 * @return string
		 */
		private function population_sql( Orders_Provider $provider, array $earlier, array $scope, array &$args, string $join ): string {
			$src     = $this->source();
			$created = $src['created'];

			$driver = ' FROM ( SELECT DISTINCT ' . $src['meta_fk'] . ' AS oid FROM ' . $src['meta']
				. ' WHERE meta_key = ' . $this->bind( $args, $provider->get_marker_meta_key(), 's' ) . " AND meta_value <> '' ) mk"
				. ' JOIN ' . $src['orders'] . ' o ON ' . $src['id'] . ' = mk.oid ' . $join;

			$where = ' WHERE ' . $src['type']
				. $this->excluded_sql( $src['status'], (array) $scope['excluded'], $args )
				. ' AND ' . $created . ' >= ' . $this->bind( $args, (string) $scope['from'], 's' )
				. ' AND ' . $created . ' < ' . $this->bind( $args, (string) $scope['to'], 's' )
				. $this->earlier_markers_sql( $earlier, $args );

			return $driver . $where;
		}

		/**
		 * `AND status NOT IN (…)` — or nothing when no status is excluded.
		 *
		 * @param string           $column   status column expression.
		 * @param string[]         $excluded excluded statuses.
		 * @param array<int,mixed> $args     statement arguments so far; appended to.
		 * @return string
		 */
		private function excluded_sql( string $column, array $excluded, array &$args ): string {
			return [] === $excluded ? '' : ' AND ' . $column . ' NOT IN ( ' . $this->bind_list( $args, $excluded ) . ' )';
		}

		/**
		 * `AND NOT EXISTS` an earlier provider's marker on the order — the «first provider wins» rule.
		 *
		 * @param string[]         $earlier marker keys of the providers registered before this one.
		 * @param array<int,mixed> $args    statement arguments so far; appended to.
		 * @return string
		 */
		private function earlier_markers_sql( array $earlier, array &$args ): string {
			if ( [] === $earlier ) {
				return '';
			}

			$src = $this->source();

			return ' AND NOT EXISTS ( SELECT 1 FROM ' . $src['meta'] . ' e WHERE e.' . $src['meta_fk'] . ' = ' . $src['id']
				. ' AND e.meta_key IN ( ' . $this->bind_list( $args, $earlier ) . " ) AND e.meta_value <> '' )";
		}

		/**
		 * `0` / `1` — whether the order carries a non-empty meta key.
		 *
		 * @param string           $key  meta key.
		 * @param array<int,mixed> $args statement arguments so far; appended to.
		 * @return string
		 */
		private function meta_exists_sql( string $key, array &$args ): string {
			$src = $this->source();

			return '( EXISTS ( SELECT 1 FROM ' . $src['meta'] . ' c WHERE c.' . $src['meta_fk'] . ' = ' . $src['id']
				. ' AND c.meta_key = ' . $this->bind( $args, $key, 's' ) . " AND c.meta_value <> '' ) )";
		}

		/**
		 * The order's raw carrier status: the newest meta row under the provider's status key, `NULL` for a provider without one.
		 *
		 * @param Orders_Provider  $provider provider.
		 * @param array<int,mixed> $args     statement arguments so far; appended to.
		 * @return string
		 */
		private function raw_status_sql( Orders_Provider $provider, array &$args ): string {
			$key = $provider->get_status_meta_key();

			if ( null === $key ) {
				return 'NULL';
			}

			$src = $this->source();

			return '( SELECT s.meta_value FROM ' . $src['meta'] . ' s WHERE s.' . $src['meta_fk'] . ' = ' . $src['id']
				. ' AND s.meta_key = ' . $this->bind( $args, $key, 's' ) . ' ORDER BY s.' . $src['meta_pk'] . ' DESC LIMIT 1 )';
		}

		/**
		 * The `CASE` that maps the leg's `cancelled` flag and `raw_status` to an outcome bucket.
		 *
		 * Built from the provider's OWN `status_map` through {@see Delivery_Status::invert_status_map()} — the inversion
		 * the orders list's delivery-status filter uses ({@see Orders_Query}) — so the tab and the list agree on what
		 * «delivered» means. An unmapped raw value, or none, is `unknown`.
		 *
		 * @param Orders_Provider  $provider provider.
		 * @param array<int,mixed> $args     statement arguments so far; appended to.
		 * @return string
		 */
		private function bucket_sql( Orders_Provider $provider, array &$args ): string {
			$whens = [ "WHEN t.cancelled = 1 THEN 'cancelled'" ];

			if ( null !== $provider->get_status_meta_key() ) {
				$inverted = Delivery_Status::invert_status_map( $provider->get_status_map() );

				foreach ( self::BUCKET_STATES as $bucket => $states ) {
					$raw = $this->raw_values( $inverted, $states );

					if ( [] !== $raw ) {
						$whens[] = 'WHEN t.raw_status IN ( ' . $this->bind_list( $args, $raw ) . " ) THEN '" . $bucket . "'";
					}
				}
			}

			return 'CASE ' . implode( ' ', $whens ) . " ELSE 'unknown' END";
		}

		/**
		 * The raw statuses a provider maps to any of the given canonical states.
		 *
		 * @param array<string,string[]> $inverted canonical state => raw values.
		 * @param string[]               $states   canonical states.
		 * @return string[]
		 */
		private function raw_values( array $inverted, array $states ): array {
			$raw = [];

			foreach ( $states as $state ) {
				$raw = array_merge( $raw, $inverted[ $state ] ?? [] );
			}

			return array_values( array_unique( $raw ) );
		}

		/**
		 * The table and column expressions of the active datastore.
		 *
		 * @return array<string,string>
		 */
		private function source(): array {
			$prefix = $this->wpdb->prefix;

			if ( $this->hpos ) {
				return [
					'orders'    => $prefix . 'wc_orders',
					'id'        => 'o.id',
					'type'      => "o.type = 'shop_order'",
					'status'    => 'o.status',
					'created'   => 'o.date_created_gmt',
					'total'     => 'o.total_amount',
					'ship'      => 'COALESCE( op.shipping_total_amount, 0 ) + COALESCE( op.shipping_tax_amount, 0 )',
					'completed' => 'op.date_completed_gmt',
					'join'      => 'LEFT JOIN ' . $prefix . 'wc_order_operational_data op ON op.order_id = o.id',
					'meta'      => $prefix . 'wc_orders_meta',
					'meta_fk'   => 'order_id',
					'meta_pk'   => 'id',
				];
			}

			return [
				'orders'    => $this->wpdb->posts,
				'id'        => 'o.ID',
				'type'      => "o.post_type = 'shop_order'",
				'status'    => 'o.post_status',
				'created'   => 'o.post_date_gmt',
				'total'     => $this->legacy_decimal( '_order_total' ),
				'ship'      => $this->legacy_decimal( '_order_shipping' ) . ' + ' . $this->legacy_decimal( '_order_shipping_tax' ),
				'completed' => "DATE_ADD( '1970-01-01 00:00:00', INTERVAL CAST( " . $this->legacy_meta( '_date_completed' ) . ' AS UNSIGNED ) SECOND )',
				'join'      => '',
				'meta'      => $this->wpdb->postmeta,
				'meta_fk'   => 'post_id',
				'meta_pk'   => 'meta_id',
			];
		}

		/**
		 * A legacy order meta value read as a decimal, `0` when absent.
		 *
		 * @param string $key `postmeta` key (a class-level literal).
		 * @return string
		 */
		private function legacy_decimal( string $key ): string {
			return 'COALESCE( CAST( ' . $this->legacy_meta( $key ) . ' AS DECIMAL(18,4) ), 0 )';
		}

		/**
		 * The newest `postmeta` value of an order under a literal key.
		 *
		 * @param string $key `postmeta` key (a class-level literal, never user input).
		 * @return string
		 */
		private function legacy_meta( string $key ): string {
			return '( SELECT pm.meta_value FROM ' . $this->wpdb->postmeta . " pm WHERE pm.post_id = o.ID AND pm.meta_key = '" . $key . "' ORDER BY pm.meta_id DESC LIMIT 1 )";
		}

		/**
		 * Registers one statement argument and returns its numbered placeholder.
		 *
		 * ⚠ A string placeholder is returned QUOTED (`'%3$s'`). `wpdb::prepare()` adds quotes only around the plain `%s`; a
		 * numbered `%3$s` is inserted RAW, "for backward compatibility" — so a bare numbered placeholder turns a date into
		 * `< 2026-10-31 21:00:00`, a syntax error on every uncached request (found on the rig by the coordinator's smoke). With the quotes
		 * in the template, `prepare()` still escapes the value; the quotes are only the delimiters. An integer (`%d`) needs none.
		 *
		 * @param array<int,mixed> $args  statement arguments so far; appended to.
		 * @param string|int       $value the value.
		 * @param string           $type  `s` or `d`.
		 * @return string e.g. `'%3$s'` or `%4$d`.
		 */
		private function bind( array &$args, $value, string $type ): string {
			$args[] = $value;

			$placeholder = '%' . count( $args ) . '$' . $type;

			return 's' === $type ? "'" . $placeholder . "'" : $placeholder;
		}

		/**
		 * Registers a list of string arguments and returns their comma-separated placeholders.
		 *
		 * @param array<int,mixed> $args   statement arguments so far; appended to.
		 * @param string[]         $values the values.
		 * @return string
		 */
		private function bind_list( array &$args, array $values ): string {
			$placeholders = [];

			foreach ( $values as $value ) {
				$placeholders[] = $this->bind( $args, (string) $value, 's' );
			}

			return implode( ', ', $placeholders );
		}

		/**
		 * Prepares and runs a statement.
		 *
		 * A failed statement is an EXCEPTION, never an empty result: an empty array would read as «no orders», be cached and be drawn as
		 * zeros. `wpdb` errors are suppressed around the call so the database's HTML never lands in the REST body, then reported
		 * through `last_error`; the message carries the database's text for the server log, never the SQL.
		 *
		 * @param array{0:string,1:array<int,mixed>}|null $statement `[ sql, args ]`, or null for «no provider in scope».
		 * @return array<int,array<string,mixed>>
		 * @throws \RuntimeException When the database reports an error or the statement could not be prepared.
		 */
		private function run( ?array $statement ): array {
			if ( null === $statement ) {
				return [];
			}

			$previous                 = $this->wpdb->suppress_errors( true );
			$this->wpdb->last_error   = '';
			$rows                     = $this->wpdb->get_results( $this->wpdb->prepare( $statement[0], $statement[1] ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line; the statement is composed of class literals and numbered placeholders only.
			$error                    = (string) $this->wpdb->last_error;
			$this->wpdb->suppress_errors( $previous );

			if ( '' !== $error || ! is_array( $rows ) ) {
				throw new \RuntimeException( '' !== $error ? $error : 'The analytics statement could not be prepared.' );
			}

			return $rows;
		}
	}

endif;

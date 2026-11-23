<?php
/**
 * The SQL behind the «Аналитика» tab of «Заказы маркетплейсов».
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads AGGREGATES of the marketplace's orders — the postings registry joined to the order store on either storage
 * ({@see Site_Orders_Storage}) — and never orders one by one: every method is one grouped statement, and what comes back is a row
 * per account and day, per account, per product or per status. The only row-level read is the few oldest stuck postings.
 *
 * The population is the one the «Заказы» table lists (a live order: not a refund, a trashed order or a draft) without failed
 * orders and checkout drafts. `wc-pending` stays in: a merchant may choose it as the status of a new marketplace order. A
 * cancellation is an order in `wc-cancelled` OR a posting in one of the adapter's rejected statuses (`not_accepted` and the like,
 * whose order may still be «В обработке»); cancelled, rejected and refunded orders bring no revenue, no units and no products.
 *
 * Every period read takes a scope `{ from, to, offset, accounts }`: `from` inclusive and `to` EXCLUSIVE GMT bounds of the order's
 * creation date, the site's UTC offset in seconds (used only to put an order on its site day) and the account ids to read.
 *
 * @since 2.1.13
 */
final class Orders_Analytics_Query {
	/**
	 * Statuses that are not part of the population.
	 */
	public const EXCLUDED_STATUSES = [ 'wc-failed', 'wc-checkout-draft' ];

	/**
	 * Statuses of a shop order nobody paid for yet: out of the shop's revenue unless the marketplace made the order.
	 */
	public const UNPAID_STATUSES = [ 'wc-pending' ];

	/**
	 * Statuses that bring no revenue.
	 */
	public const UNSOLD_STATUSES = [ 'wc-cancelled', 'wc-refunded' ];

	/**
	 * The status of a cancellation.
	 */
	public const CANCELLED_STATUS = 'wc-cancelled';

	/**
	 * Most stuck postings listed.
	 */
	public const STUCK_LIMIT = 20;

	/**
	 * The moment a posting started waiting: the marketplace's own date when the adapter supplies it, else when the shop first saw it.
	 */
	private const WAITING_SINCE = 'COALESCE(pg.processing_since, pg.first_seen_at)';

	/**
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the postings table.
	 *
	 * @var string
	 */
	private $postings;

	/**
	 * @var Site_Orders_Storage
	 */
	private $storage;

	/**
	 * The marketplace's statuses of a posting it cancelled or did not accept.
	 *
	 * @var string[]
	 */
	private $rejected;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.13
	 *
	 * @param \wpdb               $db       Database.
	 * @param string              $postings Full name of the postings table.
	 * @param Site_Orders_Storage $storage  Where the orders live.
	 * @param string[]            $rejected The marketplace's statuses of a rejected posting ({@see \Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Sets::rejected()}).
	 */
	public function __construct( \wpdb $db, string $postings, Site_Orders_Storage $storage, array $rejected = [] ) {
		$this->db       = $db;
		$this->postings = $postings;
		$this->storage  = $storage;
		$this->rejected = array_values( $rejected );
	}

	/**
	 * One row per account and site day: orders, cancellations, sold orders and revenue.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $scope The period scope.
	 * @return array<int,array{account:int,day:string,orders:int,cancelled:int,sold:int,revenue:float}>
	 */
	public function day_rows( array $scope ): array {
		if ( [] === $scope['accounts'] ) {
			return [];
		}

		$rows = $this->run(
			'SELECT pg.account_id AS account, ' . $this->day_sql( $scope ) . ' AS site_day, COUNT(*) AS orders,'
			. ' SUM(CASE WHEN ' . $this->cancelled_sql() . ' THEN 1 ELSE 0 END) AS cancelled,'
			. ' SUM(CASE WHEN ' . $this->sold_sql() . ' THEN 1 ELSE 0 END) AS sold,'
			. ' SUM(CASE WHEN ' . $this->sold_sql() . ' THEN ' . $this->storage->total_expression( $this->db ) . ' ELSE 0 END) AS revenue'
			. ' FROM ' . $this->from_sql() . ' WHERE ' . $this->period_where( $scope )
			. ' GROUP BY pg.account_id, site_day'
		);

		return array_map(
			static function ( array $row ): array {
				return [
					'account'   => (int) $row['account'],
					'day'       => (string) $row['site_day'],
					'orders'    => (int) $row['orders'],
					'cancelled' => (int) $row['cancelled'],
					'sold'      => (int) $row['sold'],
					'revenue'   => (float) $row['revenue'],
				];
			},
			$rows
		);
	}

	/**
	 * Units on the sold orders of the period, per account.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $scope The period scope.
	 * @return array<int,float> Units by account id.
	 */
	public function unit_rows( array $scope ): array {
		if ( [] === $scope['accounts'] ) {
			return [];
		}

		$rows = $this->run(
			'SELECT pg.account_id AS account, SUM(CAST(qm.meta_value AS DECIMAL(20,6))) AS units FROM ' . $this->items_from_sql()
			. ' WHERE ' . $this->period_where( $scope ) . ' AND ' . $this->sold_sql() . ' GROUP BY pg.account_id'
		);
		$out  = [];

		foreach ( $rows as $row ) {
			$out[ (int) $row['account'] ] = (float) $row['units'];
		}

		return $out;
	}

	/**
	 * The products of the sold orders of the period: one row per product and variation, and one per item name for a line that
	 * is not linked to a shop product (the marketplace's name of the item).
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,mixed> $scope The period scope.
	 * @return array<int,array{product_id:int,variation_id:int,name:string,quantity:float,revenue:float}> A row with `product_id` 0 is unlinked.
	 */
	public function product_rows( array $scope ): array {
		if ( [] === $scope['accounts'] ) {
			return [];
		}

		$product = "COALESCE(pm.meta_value, '0')";
		$rows    = $this->run(
			'SELECT ' . $product . ' AS product_id, COALESCE(vm.meta_value, \'0\') AS variation_id,'
			. ' CASE WHEN ' . $product . " IN ('0', '') THEN oi.order_item_name ELSE '' END AS unlinked_name,"
			. ' MAX(oi.order_item_name) AS name, SUM(CAST(qm.meta_value AS DECIMAL(20,6))) AS quantity,'
			. ' SUM(CAST(COALESCE(tm.meta_value, \'0\') AS DECIMAL(20,6))) AS revenue FROM ' . $this->items_from_sql()
			. $this->item_meta_join( 'pm', '_product_id' ) . $this->item_meta_join( 'vm', '_variation_id' ) . $this->item_meta_join( 'tm', '_line_total' )
			. ' WHERE ' . $this->period_where( $scope ) . ' AND ' . $this->sold_sql()
			. ' GROUP BY product_id, variation_id, unlinked_name'
		);

		return array_map(
			static function ( array $row ): array {
				return [
					'product_id'   => (int) $row['product_id'],
					'variation_id' => (int) $row['variation_id'],
					'name'         => (string) $row['name'],
					'quantity'     => (float) $row['quantity'],
					'revenue'      => (float) $row['revenue'],
				];
			},
			$rows
		);
	}

	/**
	 * The shop's revenue in the period and the part of it made by orders of the marketplace (`created_via`). Every shop order,
	 * not only the registry's: the denominator is the whole shop. A pending order counts only when the marketplace made it (a
	 * new marketplace order may be pending by the merchant's choice; a pending checkout order is not paid yet), and an order whose
	 * posting the marketplace rejected counts nowhere, as in the period figures.
	 *
	 * @since 2.1.13
	 *
	 * With an account, the marketplace part is that account's alone (its orders are the ones with a posting of the account in the
	 * registry), so the share it gives is the account's share of the shop, not the marketplace's; the shop's total stays the whole shop.
	 *
	 * @param array<string,mixed> $scope       The period scope (its accounts are not used).
	 * @param string              $created_via The marketplace's `created_via`; empty gives a zero marketplace part.
	 * @param int|null            $account     One account id, or null for the marketplace's orders of every account.
	 * @return array{shop:float,marketplace:float}
	 */
	public function shop_revenue( array $scope, string $created_via, ?int $account = null ): array {
		$total  = $this->storage->total_expression( $this->db );
		$date   = $this->storage->date_column();
		$status = $this->storage->status_column();
		$unpaid = $status . ' NOT IN (' . $this->list_sql( self::UNPAID_STATUSES ) . ')';
		$ours   = '' === $created_via ? '' : $this->db->prepare( $this->storage->created_via_expression( $this->db ) . ' = %s', $created_via ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- A prepared fragment.
		$mine   = '' === $ours || null === $account ? $ours : $ours . $this->db->prepare( ' AND EXISTS (SELECT 1 FROM %i AS ac WHERE ac.wc_order_id = ', $this->postings ) . $this->storage->id_column() . $this->db->prepare( ' AND ac.account_id = %d)', $account ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared fragments.
		$part   = '' === $mine ? '0' : 'SUM(CASE WHEN ' . $mine . ' THEN ' . $total . ' ELSE 0 END)';
		$rows   = $this->run(
			'SELECT SUM(' . $total . ') AS shop, ' . $part . ' AS marketplace'
			. ' FROM ' . $this->storage->orders_from( $this->db ) . ' WHERE ' . $this->storage->live_condition()
			. ' AND ' . $status . ' NOT IN (' . $this->list_sql( array_merge( self::EXCLUDED_STATUSES, self::UNSOLD_STATUSES ) ) . ')'
			. ' AND ' . ( '' === $ours ? $unpaid : '(' . $unpaid . ' OR ' . $ours . ')' )
			. $this->not_rejected_sql()
			. $this->db->prepare( ' AND ' . $date . ' >= %s AND ' . $date . ' < %s', $scope['from'], $scope['to'] ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column is one of two fixed names.
		);
		$row   = $rows[0] ?? [];

		return [
			'shop'        => (float) ( $row['shop'] ?? 0 ),
			'marketplace' => (float) ( $row['marketplace'] ?? 0 ),
		];
	}

	/**
	 * How many postings each marketplace status holds now, per account, finished ones included — the rows the «Заказы» table lists
	 * under the same account and that status (with «Состояние» left at «Все»).
	 *
	 * @since 2.1.13
	 *
	 * @param int[] $accounts Account ids.
	 * @return array<int,array{account:int,code:string,count:int}>
	 */
	public function status_rows( array $accounts ): array {
		if ( [] === $accounts ) {
			return [];
		}

		$rows = $this->run(
			'SELECT pg.account_id AS account, pg.status_raw AS code, COUNT(*) AS total FROM ' . $this->from_sql()
			. ' WHERE ' . $this->live_where( $accounts ) . " AND pg.status_raw <> '' GROUP BY pg.account_id, pg.status_raw"
		);

		return array_map(
			static function ( array $row ): array {
				return [
					'account' => (int) $row['account'],
					'code'    => (string) $row['code'],
					'count'   => (int) $row['total'],
				];
			},
			$rows
		);
	}

	/**
	 * The open postings that have been waiting on the shop since before a moment: how many, and the oldest ones.
	 *
	 * A posting waits since the moment the marketplace says it entered processing (`processing_since`); when the adapter did not say, since the
	 * shop first saw it (`first_seen_at`), which a history import moves to the import day.
	 *
	 * @since 2.1.13
	 *
	 * @param int[]    $accounts Account ids.
	 * @param string[] $statuses The marketplace statuses that wait on the shop (packing, shipping).
	 * @param string   $before   GMT `Y-m-d H:i:s`: a posting that has waited since before it is stuck.
	 * @return array{count:int,items:array<int,array{account:int,posting:string,order_id:int,status:string,waiting_since:string}>}
	 */
	public function stuck( array $accounts, array $statuses, string $before ): array {
		if ( [] === $accounts || [] === $statuses ) {
			return [
				'count' => 0,
				'items' => [],
			];
		}

		$where = $this->open_where( $accounts ) . ' AND pg.status_raw IN (' . $this->list_sql( $statuses ) . ')'
			. $this->db->prepare( ' AND ' . self::WAITING_SINCE . ' < %s', $before ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- A fixed expression.
		$count = (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->from_sql() . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared or fixed.

		if ( 0 === $count ) {
			return [
				'count' => 0,
				'items' => [],
			];
		}

		$rows = $this->run(
			'SELECT pg.account_id AS account, pg.external_key AS posting, pg.wc_order_id AS order_id, pg.status_raw AS status, ' . self::WAITING_SINCE . ' AS waiting_since'
			. ' FROM ' . $this->from_sql() . ' WHERE ' . $where . ' ORDER BY waiting_since ASC, pg.external_key ASC'
			. $this->db->prepare( ' LIMIT %d', self::STUCK_LIMIT )
		);

		return [
			'count' => $count,
			'items' => array_map(
				static function ( array $row ): array {
					return [
						'account'       => (int) $row['account'],
						'posting'       => (string) $row['posting'],
						'order_id'      => (int) $row['order_id'],
						'status'        => (string) $row['status'],
						'waiting_since' => (string) $row['waiting_since'],
					];
				},
				$rows
			),
		];
	}

	/**
	 * `postings AS pg` joined to the order.
	 *
	 * @return string
	 */
	private function from_sql(): string {
		return $this->db->prepare( '%i AS pg ', $this->postings ) . $this->storage->join( $this->db );
	}

	/**
	 * The registry, the order and its product lines with their quantity.
	 *
	 * @return string
	 */
	private function items_from_sql(): string {
		return $this->from_sql()
			. $this->db->prepare( ' INNER JOIN %i AS oi ON oi.order_id = pg.wc_order_id AND oi.order_item_type = %s', $this->db->prefix . 'woocommerce_order_items', 'line_item' )
			. $this->db->prepare( ' INNER JOIN %i AS qm ON qm.order_item_id = oi.order_item_id AND qm.meta_key = %s', $this->db->prefix . 'woocommerce_order_itemmeta', '_qty' );
	}

	/**
	 * A line's meta value under an alias, absent as NULL.
	 *
	 * @param string $alias Alias, a fixed name.
	 * @param string $key   Meta key.
	 * @return string
	 */
	private function item_meta_join( string $alias, string $key ): string {
		return $this->db->prepare( ' LEFT JOIN %i AS ' . $alias . ' ON ' . $alias . '.order_item_id = oi.order_item_id AND ' . $alias . '.meta_key = %s', $this->db->prefix . 'woocommerce_order_itemmeta', $key ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The alias is one of our fixed names.
	}

	/**
	 * The population of a period.
	 *
	 * @param array<string,mixed> $scope The period scope.
	 * @return string
	 */
	private function period_where( array $scope ): string {
		$date = $this->storage->date_column();

		return 'pg.account_id IN (' . $this->ids_sql( $scope['accounts'] ) . ') AND pg.wc_order_id IS NOT NULL AND ' . $this->storage->live_condition()
			. ' AND ' . $this->storage->status_column() . ' NOT IN (' . $this->list_sql( self::EXCLUDED_STATUSES ) . ')'
			. $this->db->prepare( ' AND ' . $date . ' >= %s AND ' . $date . ' < %s', $scope['from'], $scope['to'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column is one of two fixed names.
	}

	/**
	 * The postings of the accounts with a live order: the rows of the «Заказы» table.
	 *
	 * @param int[] $accounts Account ids.
	 * @return string
	 */
	private function live_where( array $accounts ): string {
		return 'pg.account_id IN (' . $this->ids_sql( $accounts ) . ') AND pg.wc_order_id IS NOT NULL AND ' . $this->storage->live_condition();
	}

	/**
	 * The open ones of those: the «В работе» rows.
	 *
	 * @param int[] $accounts Account ids.
	 * @return string
	 */
	private function open_where( array $accounts ): string {
		return $this->live_where( $accounts ) . ' AND pg.is_terminal = 0';
	}

	/**
	 * @return string The condition of a cancellation: the order is cancelled, or the marketplace rejected the posting.
	 */
	private function cancelled_sql(): string {
		$order = $this->db->prepare( $this->storage->status_column() . ' = %s', self::CANCELLED_STATUS ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column is one of two fixed names.

		return [] === $this->rejected ? $order : '(' . $order . ' OR pg.status_raw IN (' . $this->list_sql( $this->rejected ) . '))';
	}

	/**
	 * @return string `AND` no rejected posting is registered for the order `o`; nothing without rejected statuses.
	 */
	private function not_rejected_sql(): string {
		if ( [] === $this->rejected ) {
			return '';
		}

		return $this->db->prepare( ' AND NOT EXISTS (SELECT 1 FROM %i AS rj WHERE rj.wc_order_id = ', $this->postings )
			. $this->storage->id_column() . ' AND rj.status_raw IN (' . $this->list_sql( $this->rejected ) . '))';
	}

	/**
	 * @return string The condition of an order that was sold: neither cancelled, rejected nor refunded.
	 */
	private function sold_sql(): string {
		return 'NOT (' . $this->storage->status_column() . ' IN (' . $this->list_sql( self::UNSOLD_STATUSES ) . ')'
			. ( [] === $this->rejected ? '' : ' OR pg.status_raw IN (' . $this->list_sql( $this->rejected ) . ')' ) . ')';
	}

	/**
	 * The site day of the order's creation.
	 *
	 * @param array<string,mixed> $scope The period scope.
	 * @return string
	 */
	private function day_sql( array $scope ): string {
		return 'DATE(DATE_ADD(' . $this->storage->date_column() . $this->db->prepare( ', INTERVAL %d SECOND))', (int) $scope['offset'] );
	}

	/**
	 * @param int[] $ids Ids.
	 * @return string A prepared list.
	 */
	private function ids_sql( array $ids ): string {
		$ids = array_values( array_map( 'intval', $ids ) );

		return $this->db->prepare( implode( ', ', array_fill( 0, count( $ids ), '%d' ) ), $ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder per id.
	}

	/**
	 * @param string[] $values Strings.
	 * @return string A prepared list.
	 */
	private function list_sql( array $values ): string {
		$values = array_values( $values );

		return $this->db->prepare( implode( ', ', array_fill( 0, count( $values ), '%s' ) ), $values ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder per value.
	}

	/**
	 * Runs a read.
	 *
	 * @param string $sql Statement, every fragment prepared or fixed.
	 * @return array<int,array<string,mixed>>
	 * @throws \RuntimeException When the database refused the statement.
	 */
	private function run( string $sql ): array {
		$rows = $this->db->get_results( $sql, 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared or fixed; the answer is cached by the controller.

		if ( ! is_array( $rows ) || '' !== (string) $this->db->last_error ) {
			throw new \RuntimeException( 'The marketplace analytics read failed: ' . $this->db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $rows;
	}
}

<?php
/**
 * Where WooCommerce keeps its orders, as far as the «Заказы» table's SQL needs to know.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * The order store is either the HPOS table (`wc_orders`: `id`, `type`, `status`, `date_created_gmt`, `total_amount`) or the posts table
 * (`ID`, `post_type`, `post_status`, `post_date_gmt`, and the total in the `_order_total` meta). The reader joins its registry rows to
 * `o` — the order — and asks this class for the column or fragment each filter and sort uses, so there is one query with two storage
 * paths and no `wc_get_orders()` call that would have to load every order to sort them.
 *
 * Both paths leave out trashed orders and drafts.
 *
 * @since 2.1.5
 */
final class Site_Orders_Storage {
	/**
	 * The sort keys the table offers. `date` and `total` order by the storage's columns; the others by columns of the registry row.
	 *
	 * @var string[]
	 */
	public const SORT_KEYS = [ 'date', 'order', 'posting', 'status', 'total' ];

	/**
	 * Whether the orders live in the HPOS table.
	 *
	 * @var bool
	 */
	private $hpos;

	/**
	 * Full name of the table that holds the orders.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Creates the descriptor.
	 *
	 * @since 2.1.5
	 *
	 * @param bool   $hpos  True for the HPOS table, false for the posts table.
	 * @param string $table Full name of that table.
	 */
	public function __construct( bool $hpos, string $table ) {
		$this->hpos  = $hpos;
		$this->table = $table;
	}

	/**
	 * The storage WooCommerce uses on this site now.
	 *
	 * @since 2.1.5
	 *
	 * @return self
	 */
	public static function current(): self {
		return new self( \Woodev_Plugin_Compatibility::is_hpos_enabled(), \Woodev_Order_Compatibility::get_orders_table() );
	}

	/**
	 * @since 2.1.5
	 *
	 * @return bool
	 */
	public function is_hpos(): bool {
		return $this->hpos;
	}

	/**
	 * Joins the order to a registry row (`pg`).
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb $db Database.
	 * @return string A prepared fragment.
	 */
	public function join( \wpdb $db ): string {
		return $db->prepare( 'INNER JOIN %i AS o ON o.' . ( $this->hpos ? 'id' : 'ID' ) . ' = pg.wc_order_id', $this->table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column is one of two fixed names; the table is a placeholder.
	}

	/**
	 * The condition that keeps real shop orders: not a refund, a trashed order or a draft.
	 *
	 * @since 2.1.5
	 *
	 * @return string A fixed fragment.
	 */
	public function live_condition(): string {
		return $this->hpos
			? "o.type = 'shop_order' AND o.status NOT IN ('trash', 'auto-draft')"
			: "o.post_type = 'shop_order' AND o.post_status NOT IN ('trash', 'auto-draft')";
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string The column that holds the order's WooCommerce status (with the `wc-` prefix on both stores).
	 */
	public function status_column(): string {
		return $this->hpos ? 'o.status' : 'o.post_status';
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string The column that holds the date the order was created, in UTC.
	 */
	public function date_column(): string {
		return $this->hpos ? 'o.date_created_gmt' : 'o.post_date_gmt';
	}

	/**
	 * What a sort key orders by.
	 *
	 * @since 2.1.5
	 *
	 * @param string $key Key of {@see self::SORT_KEYS}; the date for anything else.
	 * @param \wpdb  $db  Database.
	 * @return string A fixed or prepared fragment; nothing the request sent is in it.
	 */
	public function sort_expression( string $key, \wpdb $db ): string {
		switch ( $key ) {
			case 'order':
				return 'pg.wc_order_id';
			case 'posting':
				return 'pg.external_key';
			case 'status':
				return 'pg.status_raw';
			case 'total':
				return $this->total_expression( $db );
			default:
				return $this->date_column();
		}
	}

	/**
	 * The order total: a column on HPOS, a meta value (as a number) on the posts store.
	 *
	 * @param \wpdb $db Database.
	 * @return string
	 */
	private function total_expression( \wpdb $db ): string {
		if ( $this->hpos ) {
			return 'o.total_amount';
		}

		return $db->prepare( '(SELECT CAST(tm.meta_value AS DECIMAL(20,6)) FROM %i AS tm WHERE tm.post_id = o.ID AND tm.meta_key = %s LIMIT 1)', $db->postmeta, '_order_total' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both values are placeholders.
	}
}

<?php
/**
 * The SQL behind the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the postings registry (one row per remote order group of an account, with the WooCommerce order it became) joined to the
 * order store on whichever storage the shop uses ({@see Site_Orders_Storage}). Filtering, sorting and paging are done in SQL over the
 * whole table, never over one page, and a registry row whose WooCommerce order is gone, trashed or still being created is not listed.
 *
 * An item is `order_id`, `external_key` (the marketplace's posting number), `status_raw` (the marketplace's own status),
 * `is_terminal` (the marketplace will not change the posting any more) and `claim_until` (UTC time until which a writer holds the
 * row; empty for none).
 *
 * @since 2.1.5
 */
final class Site_Orders_Reader {
	/**
	 * Columns every read selects.
	 */
	private const COLUMNS = 'pg.wc_order_id, pg.external_key, pg.status_raw, pg.is_terminal, pg.claim_until';

	/**
	 * Most posting statuses the status filter offers.
	 */
	public const MAX_POSTING_STATUSES = 50;

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
	 * Creates the reader.
	 *
	 * @since 2.1.5
	 *
	 * @param \wpdb               $db       Database.
	 * @param string              $postings Full name of the postings table.
	 * @param Site_Orders_Storage $storage  Where the orders live.
	 */
	public function __construct( \wpdb $db, string $postings, Site_Orders_Storage $storage ) {
		$this->db       = $db;
		$this->postings = $postings;
		$this->storage  = $storage;
	}

	/**
	 * Reads one page, sorted and filtered over the whole table.
	 *
	 * @since 2.1.5
	 *
	 * @param Site_Orders_Filter $filter   Filters and sort.
	 * @param int                $page     Page number, from 1.
	 * @param int                $per_page Rows per page.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function page( Site_Orders_Filter $filter, int $page, int $per_page ): array {
		$from  = $this->from_sql();
		$where = $this->where( $filter );
		$total = (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared or fixed.

		if ( 0 === $total ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		$rows = $this->db->get_results(
			'SELECT ' . self::COLUMNS . ' FROM ' . $from . ' WHERE ' . $where // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared or fixed.
			. ' ORDER BY ' . $filter->order_by( $this->storage, $this->db ) . $this->db->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, $page - 1 ) * $per_page ),
			'ARRAY_A'
		);

		return [
			'items' => array_map( [ $this, 'item' ], is_array( $rows ) ? $rows : [] ),
			'total' => $total,
		];
	}

	/**
	 * How many orders each posting state holds under every other filter of the read (the state itself is not applied): the counts
	 * of the view row. One query.
	 *
	 * @since 2.1.6
	 *
	 * @param Site_Orders_Filter $filter Filters; the state is ignored.
	 * @return array{open:int,finished:int}
	 */
	public function state_counts( Site_Orders_Filter $filter ): array {
		$rows = $this->db->get_results(
			'SELECT pg.is_terminal AS finished, COUNT(*) AS total FROM ' . $this->from_sql() . ' WHERE ' . $this->where( $filter->without_state() ) . ' GROUP BY pg.is_terminal', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared or fixed.
			'ARRAY_A'
		);
		$out  = [
			Site_Orders_Filter::STATE_OPEN     => 0,
			Site_Orders_Filter::STATE_FINISHED => 0,
		];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ 1 === (int) $row['finished'] ? Site_Orders_Filter::STATE_FINISHED : Site_Orders_Filter::STATE_OPEN ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Reads the registry rows of orders named by id. An order that is not an order of the account, or is gone, is absent.
	 *
	 * @since 2.1.5
	 *
	 * @param int   $account_id Account.
	 * @param int[] $order_ids  WooCommerce order ids.
	 * @return array<int,array<string,mixed>> Items by order id.
	 */
	public function find( int $account_id, array $order_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$rows = $this->db->get_results(
			'SELECT ' . self::COLUMNS . ' FROM ' . $this->from_sql() . ' WHERE ' . $this->db->prepare( 'pg.account_id = %d', $account_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared or fixed.
			. ' AND pg.wc_order_id IN (' . $this->db->prepare( implode( ', ', array_fill( 0, count( $ids ), '%d' ) ), $ids ) . ') AND ' . $this->storage->live_condition(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder per id.
			'ARRAY_A'
		);

		$items = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$item                        = $this->item( $row );
			$items[ $item['order_id'] ] = $item;
		}

		return $items;
	}

	/**
	 * How many orders each of the marketplace's posting statuses holds under every other filter of the read (the posting status
	 * itself is not applied): the options of the «Статус на маркетплейсе» filter, with their counts. Only stored codes, so the
	 * filter never offers a status no order is in. One query.
	 *
	 * @since 2.1.13
	 *
	 * @param Site_Orders_Filter $filter Filters; the posting status is ignored.
	 * @return array<string,int> Count by code, at most {@see self::MAX_POSTING_STATUSES} codes, sorted by code.
	 */
	public function posting_status_counts( Site_Orders_Filter $filter ): array {
		$rows = $this->db->get_results(
			'SELECT pg.status_raw AS code, COUNT(*) AS total FROM ' . $this->from_sql() . ' WHERE ' . $this->where( $filter->without_posting_status() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Every fragment is prepared or fixed.
			. " AND pg.status_raw <> '' GROUP BY pg.status_raw ORDER BY pg.status_raw" . $this->db->prepare( ' LIMIT %d', self::MAX_POSTING_STATUSES ),
			'ARRAY_A'
		);
		$out  = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row['code'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * `postings AS pg` joined to the order.
	 *
	 * @return string A prepared fragment.
	 */
	private function from_sql(): string {
		return $this->db->prepare( '%i AS pg ', $this->postings ) . $this->storage->join( $this->db );
	}

	/**
	 * The conditions of a filter. Everything the request sent is a placeholder value.
	 *
	 * @param Site_Orders_Filter $filter Filter.
	 * @return string
	 */
	private function where( Site_Orders_Filter $filter ): string {
		$clauses = [
			$this->db->prepare( 'pg.account_id = %d', $filter->account_id() ),
			'pg.wc_order_id IS NOT NULL',
			$this->storage->live_condition(),
		];

		if ( '' !== $filter->state() ) {
			$clauses[] = Site_Orders_Filter::STATE_FINISHED === $filter->state() ? 'pg.is_terminal = 1' : 'pg.is_terminal = 0';
		}

		if ( '' !== $filter->wc_status() ) {
			$clauses[] = $this->db->prepare( $this->storage->status_column() . ' = %s', $filter->wc_status() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column is one of two fixed names.
		}

		if ( '' !== $filter->posting_status() ) {
			$clauses[] = $this->db->prepare( 'pg.status_raw = %s', $filter->posting_status() );
		}

		if ( '' !== $filter->from() ) {
			$clauses[] = $this->db->prepare( $this->storage->date_column() . ' >= %s', $filter->from() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column is one of two fixed names.
		}

		if ( '' !== $filter->to() ) {
			$clauses[] = $this->db->prepare( $this->storage->date_column() . ' <= %s', $filter->to() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The column is one of two fixed names.
		}

		if ( '' !== $filter->search() ) {
			$clauses[] = $this->search_clause( $filter->search() );
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * The posting number contains the text, or the text is the order id.
	 *
	 * @param string $search Search text.
	 * @return string
	 */
	private function search_clause( string $search ): string {
		return $this->db->prepare(
			'(pg.external_key LIKE %s OR pg.wc_order_id = %d)',
			'%' . $this->db->esc_like( $search ) . '%',
			ctype_digit( $search ) ? (int) $search : 0
		);
	}

	/**
	 * @param array<string,mixed> $row Database row.
	 * @return array{order_id:int,external_key:string,status_raw:string,is_terminal:bool,claim_until:string}
	 */
	private function item( array $row ): array {
		return [
			'order_id'     => (int) $row['wc_order_id'],
			'external_key' => (string) $row['external_key'],
			'status_raw'   => (string) $row['status_raw'],
			'is_terminal'  => 1 === (int) $row['is_terminal'],
			'claim_until'  => (string) ( $row['claim_until'] ?? '' ),
		];
	}
}

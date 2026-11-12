<?php
/**
 * The filters and sort of one read of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Account, the posting state, the WooCommerce status, the order date range, the search text and the sort. Built from the engine's
 * validated query, so a value is already typed; the sort is still checked against the whitelist and the status against its
 * shape, because the reader puts them in SQL.
 *
 * @since 2.1.5
 */
final class Site_Orders_Filter {
	/**
	 * Posting state: the marketplace still works on the posting.
	 */
	public const STATE_OPEN = 'open';

	/**
	 * Posting state: the marketplace will not change the posting any more (delivered or cancelled).
	 */
	public const STATE_FINISHED = 'finished';

	/**
	 * @var int
	 */
	private $account_id;

	/**
	 * @var string
	 */
	private $state;

	/**
	 * @var string
	 */
	private $wc_status;

	/**
	 * @var string
	 */
	private $from;

	/**
	 * @var string
	 */
	private $to;

	/**
	 * @var string
	 */
	private $search;

	/**
	 * @var string
	 */
	private $sort;

	/**
	 * @var string
	 */
	private $direction;

	/**
	 * Creates the filter.
	 *
	 * @since 2.1.5
	 *
	 * @param int    $account_id Account.
	 * @param string $state      `open`, `finished`; anything else is any.
	 * @param string $wc_status  WooCommerce status with its `wc-` prefix; anything of another shape is any.
	 * @param string $from       Earliest creation time, UTC `Y-m-d H:i:s`; empty for none.
	 * @param string $to         Latest creation time, UTC `Y-m-d H:i:s`; empty for none.
	 * @param string $search     Search text.
	 * @param string $sort       Key of {@see Site_Orders_Storage::SORT_KEYS}; the date for anything else.
	 * @param string $direction  `asc` or `desc`.
	 */
	public function __construct( int $account_id, string $state = '', string $wc_status = '', string $from = '', string $to = '', string $search = '', string $sort = 'date', string $direction = 'desc' ) {
		$this->account_id = $account_id;
		$this->state      = in_array( $state, [ self::STATE_OPEN, self::STATE_FINISHED ], true ) ? $state : '';
		$this->wc_status  = 1 === preg_match( '/^[a-z0-9-]{1,40}$/D', $wc_status ) ? $wc_status : '';
		$this->from       = $from;
		$this->to         = $to;
		$this->search     = trim( $search );
		$this->sort       = in_array( $sort, Site_Orders_Storage::SORT_KEYS, true ) ? $sort : 'date';
		$this->direction  = 'asc' === $direction ? 'asc' : 'desc';
	}

	/**
	 * Builds the filter from the engine's validated query.
	 *
	 * @since 2.1.5
	 *
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $query      `search`, `sort`, `direction` and `filters`.
	 * @return self
	 */
	public static function from_query( int $account_id, array $query ): self {
		$filters = (array) ( $query['filters'] ?? [] );
		$dates   = (array) ( $filters['date'] ?? [] );

		return new self(
			$account_id,
			(string) ( $filters['state'] ?? '' ),
			(string) ( $filters['wc_status'] ?? '' ),
			isset( $dates['from'] ) ? self::utc( (string) $dates['from'], '00:00:00' ) : '',
			isset( $dates['to'] ) ? self::utc( (string) $dates['to'], '23:59:59' ) : '',
			(string) ( $filters['q'] ?? $query['search'] ?? '' ),
			(string) ( $query['sort'] ?? 'date' ),
			(string) ( $query['direction'] ?? 'desc' )
		);
	}

	/**
	 * A day of the shop's calendar as the UTC time the orders table stores: the merchant's «с 1 по 3 ноября» is three local days.
	 *
	 * @param string $date `Y-m-d`.
	 * @param string $time `H:i:s` within that day.
	 * @return string UTC `Y-m-d H:i:s`.
	 */
	private static function utc( string $date, string $time ): string {
		$local = new \DateTimeImmutable( $date . ' ' . $time, wp_timezone() );

		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @since 2.1.5
	 *
	 * @return int
	 */
	public function account_id(): int {
		return $this->account_id;
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string `open`, `finished`, or empty for any.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string WooCommerce status, empty for any.
	 */
	public function wc_status(): string {
		return $this->wc_status;
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string UTC time or empty.
	 */
	public function from(): string {
		return $this->from;
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string UTC time or empty.
	 */
	public function to(): string {
		return $this->to;
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function search(): string {
		return $this->search;
	}

	/**
	 * The `ORDER BY` list: the chosen key, then the order id, so two reads of one page agree.
	 *
	 * @since 2.1.5
	 *
	 * @param Site_Orders_Storage $storage Where the orders live.
	 * @param \wpdb               $db      Database.
	 * @return string A fixed or prepared fragment; nothing the request sent is in it.
	 */
	public function order_by( Site_Orders_Storage $storage, \wpdb $db ): string {
		$direction = strtoupper( $this->direction );

		return $storage->sort_expression( $this->sort, $db ) . ' ' . $direction . ', pg.wc_order_id ' . $direction;
	}
}

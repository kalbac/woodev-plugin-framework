<?php
/**
 * The filters and sort of one read of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Account, state group, category, «только с ошибками», the search text and the sort. Built from the engine's validated query, so a
 * value is already typed; the state is still checked against the groups and the sort against the whitelist, because the reader
 * puts them in SQL.
 *
 * @since 2.1.3
 */
final class Site_Products_Filter {
	/**
	 * Sort key => SQL expression over the aliases `p` (the product) and `lk` (its lookup row).
	 *
	 * @var array<string,string>
	 */
	public const SORTS = [
		'name'  => 'p.post_title',
		'sku'   => 'lk.sku',
		'stock' => 'lk.stock_quantity',
		'price' => 'lk.min_price',
	];

	/**
	 * @var int
	 */
	private $account_id;

	/**
	 * @var string
	 */
	private $state;

	/**
	 * @var int
	 */
	private $category;

	/**
	 * @var bool
	 */
	private $only_errors;

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
	 * @since 2.1.3
	 *
	 * @param int    $account_id  Account.
	 * @param string $state       State group; anything but a group is ignored.
	 * @param int    $category    Category term id, 0 for any.
	 * @param bool   $only_errors Only products with an error.
	 * @param string $search      Search text.
	 * @param string $sort        Key of {@see self::SORTS}; the title for anything else.
	 * @param string $direction   `asc` or `desc`.
	 */
	public function __construct( int $account_id, string $state = '', int $category = 0, bool $only_errors = false, string $search = '', string $sort = 'name', string $direction = 'asc' ) {
		$this->account_id  = $account_id;
		$this->state       = State_Groups::is_group( $state ) ? $state : '';
		$this->category    = max( 0, $category );
		$this->only_errors = $only_errors;
		$this->search      = trim( $search );
		$this->sort        = isset( self::SORTS[ $sort ] ) ? $sort : 'name';
		$this->direction   = 'desc' === $direction ? 'desc' : 'asc';
	}

	/**
	 * Builds the filter from the engine's validated query.
	 *
	 * @since 2.1.3
	 *
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $query      `search`, `sort`, `direction` and `filters`.
	 * @return self
	 */
	public static function from_query( int $account_id, array $query ): self {
		$filters = (array) ( $query['filters'] ?? [] );

		return new self(
			$account_id,
			(string) ( $filters['state'] ?? '' ),
			(int) ( $filters['category'] ?? 0 ),
			true === ( $filters['only_errors'] ?? false ),
			(string) ( $filters['q'] ?? $query['search'] ?? '' ),
			(string) ( $query['sort'] ?? 'name' ),
			(string) ( $query['direction'] ?? 'asc' )
		);
	}

	/**
	 * @since 2.1.3
	 *
	 * @return int
	 */
	public function account_id(): int {
		return $this->account_id;
	}

	/**
	 * @since 2.1.3
	 *
	 * @return string State group, empty for any.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * @since 2.1.3
	 *
	 * @return int Category term id, 0 for any.
	 */
	public function category(): int {
		return $this->category;
	}

	/**
	 * @since 2.1.3
	 *
	 * @return bool
	 */
	public function only_errors(): bool {
		return $this->only_errors;
	}

	/**
	 * @since 2.1.3
	 *
	 * @return string
	 */
	public function search(): string {
		return $this->search;
	}

	/**
	 * The `ORDER BY` list: the chosen key, then the product id, so two reads of one page agree.
	 *
	 * @since 2.1.3
	 *
	 * @param string $stock_sql What the stock sort orders by when the reader knows better than the lookup column (a variable product's
	 *                          stock is the sum over its variations); the whitelisted column when empty.
	 * @return string A fixed fragment; nothing the request sent is in it.
	 */
	public function order_by( string $stock_sql = '' ): string {
		$direction = strtoupper( $this->direction );
		$column    = 'stock' === $this->sort && '' !== $stock_sql ? $stock_sql : self::SORTS[ $this->sort ];

		return $column . ' ' . $direction . ', p.ID ' . $direction;
	}
}

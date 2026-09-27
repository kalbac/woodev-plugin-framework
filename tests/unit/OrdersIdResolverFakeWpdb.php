<?php
/**
 * A `$wpdb` stand-in for the tests around {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Id_Resolver}
 * (#928): enough of `wpdb` to compile the id query without a database — `prepare()`
 * with `%s` / `%d`, the table-name properties the resolver reads, and a `get_col()`
 * that records every statement it was handed and answers with a canned list.
 *
 * Not a Mockery mock on purpose: the same object is shared by three test files (the
 * SQL-shape test, the join-growth gate and the registry's badge tests), and the
 * quoting it does is part of what those files pin.
 *
 * @package Woodev\Tests\Unit
 * @since 2.0.2
 */

namespace Woodev\Tests\Unit;

/**
 * @since 2.0.2
 */
class OrdersIdResolverFakeWpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $postmeta = 'wp_postmeta';

	/** @var string the legacy CPT order table (an order-status leaf reads `post_status` from it, #843). */
	public $posts = 'wp_posts';

	/** @var string[] every statement `get_col()` received, in order. */
	public $queries = [];

	/** @var callable(string):array<int,string|int> answers `get_col()` from the statement. */
	private $answer;

	/**
	 * @param callable|array<int,string|int>|null $answer a canned id list, or a callable
	 *                                                    handed the SQL that returns one.
	 */
	public function __construct( $answer = [] ) {
		$this->answer = is_callable( $answer )
			? $answer
			: static function () use ( $answer ): array {
				return (array) $answer;
			};
	}

	/**
	 * The subset of `wpdb::prepare()` the resolver uses: each `%s` becomes a single-quoted,
	 * backslash-escaped literal; each `%d` an integer. Anything else is unsupported here.
	 *
	 * @param string $query
	 * @param mixed  ...$args
	 */
	public function prepare( string $query, ...$args ): string {
		$index = 0;

		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $match ) use ( $args, &$index ): string {
				$value = $args[ $index++ ] ?? '';

				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}

				return "'" . addcslashes( (string) $value, "'\\" ) . "'";
			},
			$query
		);
	}

	/**
	 * @return array<int,string|int>
	 */
	public function get_col( string $sql ): array {
		$this->queries[] = $sql;

		return ( $this->answer )( $sql );
	}
}

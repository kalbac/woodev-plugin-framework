<?php
/**
 * Test stand-in for the WordPress term class (the unit suite loads no WordPress).
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

if ( ! class_exists( 'WP_Term', false ) ) {
	/**
	 * A product category.
	 */
	class WP_Term {
		/**
		 * Term id.
		 *
		 * @var int
		 */
		public $term_id = 0;

		/**
		 * Parent term id.
		 *
		 * @var int
		 */
		public $parent = 0;

		/**
		 * Name.
		 *
		 * @var string
		 */
		public $name = '';
	}
}

if ( ! class_exists( 'WP_Query', false ) ) {
	/**
	 * A product query that answers from a list the test sets and remembers its arguments.
	 */
	class WP_Query {
		/**
		 * Ids every query returns.
		 *
		 * @var int[]
		 */
		public static $ids = [];

		/**
		 * Arguments of every query made.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public static $queries = [];

		/**
		 * Ids found (the page of the query).
		 *
		 * @var int[]
		 */
		public $posts = [];

		/**
		 * Total found.
		 *
		 * @var int
		 */
		public $found_posts = 0;

		/**
		 * Runs the query.
		 *
		 * @param array<string,mixed> $args Arguments.
		 */
		public function __construct( array $args = [] ) {
			self::$queries[]   = $args;
			$offset            = (int) ( $args['offset'] ?? 0 );
			$this->posts       = array_slice( self::$ids, $offset, (int) $args['posts_per_page'] );
			$this->found_posts = count( self::$ids );
		}
	}
}

<?php
/**
 * Unit fixtures for the order editor's per-order edit lock (#981 round 4).
 *
 * A stand-in for `$wpdb` that answers `GET_LOCK` / `RELEASE_LOCK` the way MySQL does — granted,
 * timed out or errored — and records every statement, so a unit test can pin the lock's name, its
 * timeout and its release without a database.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

if ( ! class_exists( __NAMESPACE__ . '\Order_Editor_Fake_Wpdb', false ) ) {

	/**
	 * The `$wpdb` the editor's lock talks to.
	 */
	final class Order_Editor_Fake_Wpdb {

		/** @var string the table prefix, part of the lock name. */
		public $prefix = 'wp_';

		/** @var string the database name, part of the lock name. */
		public $dbname = 'shop';

		/** @var string|null what `GET_LOCK` answers: '1' granted, '0' timed out, null a database error. */
		public $grant = '1';

		/** @var string[] every statement issued, prepared, in order. */
		public $queries = [];

		/**
		 * `$wpdb->prepare()`, for the two placeholders the lock uses.
		 *
		 * @param string $query   SQL with `%s` / `%d` placeholders.
		 * @param mixed  ...$args placeholder values.
		 * @return string
		 */
		public function prepare( $query, ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace_callback(
					'/%[sd]/',
					static function ( array $match ) use ( $arg ): string {
						return '%d' === $match[0] ? (string) (int) $arg : "'" . (string) $arg . "'";
					},
					(string) $query,
					1
				);
			}

			return (string) $query;
		}

		/**
		 * `$wpdb->get_var()` — the `GET_LOCK` answer.
		 *
		 * @param string $query prepared SQL.
		 * @return string|null
		 */
		public function get_var( $query ) {
			$this->queries[] = (string) $query;

			return $this->grant;
		}

		/**
		 * `$wpdb->query()` — the `RELEASE_LOCK`.
		 *
		 * @param string $query prepared SQL.
		 * @return int
		 */
		public function query( $query ): int {
			$this->queries[] = (string) $query;

			return 1;
		}

		/**
		 * The statements that took or released a lock, in order.
		 *
		 * @return string[]
		 */
		public function lock_statements(): array {
			return array_values(
				array_filter(
					$this->queries,
					static function ( string $sql ): bool {
						return false !== strpos( $sql, '_LOCK(' );
					}
				)
			);
		}
	}
}

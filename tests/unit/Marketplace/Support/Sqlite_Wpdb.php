<?php
/**
 * A `wpdb` over SQLite, so that the SQL of the throttle store and the error ring really runs.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

require_once __DIR__ . '/wpdb-stub.php';

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- The point of this double is to run the stores' SQL on an in-memory SQLite database.

/**
 * Implements the few `wpdb` calls the plugin's stores use. `prepare()` fills `%i`, `%d` and `%s` the way WordPress does;
 * `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE` and `GREATEST()` are run in their SQLite forms (`INSERT OR IGNORE`, `ON CONFLICT … DO UPDATE SET`, `MAX()`). Every statement is kept in {@see self::$statements}.
 */
#[\AllowDynamicProperties] // The WordPress table-name properties (`posts`, `term_taxonomy`, …) that a test needs are set on it by name.
final class Sqlite_Wpdb extends \wpdb {
	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Every statement run, oldest first.
	 *
	 * @var string[]
	 */
	public $statements = array();

	/**
	 * Id of the last inserted row, like `wpdb::$insert_id`.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Error of the last statement, '' when it succeeded, like `wpdb::$last_error`.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * When true every read fails the way a dropped connection does (error set, null or empty result) while writes still
	 * work, so a test can model a transient read failure.
	 *
	 * @var bool
	 */
	public $fail_reads = false;

	/**
	 * When true `insert()` fails without storing anything, like a denied INSERT.
	 *
	 * @var bool
	 */
	public $refuse_inserts = false;

	/**
	 * Called once with the statement, just before the first `query()` whose text matches `$before_query_pattern`; it clears itself
	 * first. A test uses it to let a competing write land between a request's read and its write.
	 *
	 * @var callable|null
	 */
	public $before_query;

	/**
	 * Called once at the start of the next `update()`; it clears itself first. A test uses it to let a competing write land between
	 * a store's read of a revision and its revision-keyed UPDATE.
	 *
	 * @var callable|null
	 */
	public $before_update;

	/**
	 * Pattern of the statement `$before_query` waits for.
	 *
	 * @var string
	 */
	public $before_query_pattern = '';

	/**
	 * Connection.
	 *
	 * @var \PDO
	 */
	private $pdo;

	/**
	 * Opens an empty in-memory database. The parent constructor would connect to MySQL, so it is not called.
	 */
	public function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod,Squiz.Commenting.FunctionComment.Missing -- No parent call by design.
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
	}

	/**
	 * Creates a table from a statement written in SQLite's dialect.
	 *
	 * @param string $sql Statement.
	 * @return void
	 */
	public function define( string $sql ): void {
		$this->pdo->exec( $sql );
	}

	/**
	 * Fills the placeholders of a statement.
	 *
	 * @param string $query   Statement.
	 * @param mixed  ...$args Values, or one array of them.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$args = array_values( $args );

		return (string) preg_replace_callback(
			'/%([disf])/',
			function ( array $placeholder ) use ( &$args ): string {
				$value = array_shift( $args );
				switch ( $placeholder[1] ) {
					case 'i':
						return '"' . str_replace( '"', '""', (string) $value ) . '"';
					case 'd':
						return (string) (int) $value;
					case 'f':
						return (string) (float) $value;
				}

				return $this->pdo->quote( (string) $value );
			},
			(string) $query
		);
	}

	/**
	 * Runs a statement.
	 *
	 * @param string $query Statement.
	 * @return int|bool Rows changed.
	 */
	public function query( $query ) {
		if ( null !== $this->before_query && 1 === preg_match( $this->before_query_pattern, (string) $query ) ) {
			$hook               = $this->before_query;
			$this->before_query = null;
			$hook( (string) $query );
		}

		$this->statements[] = (string) $query;
		$this->last_error   = '';

		try {
			$changed         = $this->pdo->exec( self::to_sqlite( (string) $query ) );
			$this->insert_id = (int) $this->pdo->lastInsertId();
		} catch ( \PDOException $e ) {
			// The real `wpdb` never throws: it records the error and returns false.
			$this->last_error = $e->getMessage();

			return false;
		}

		return $changed;
	}

	/**
	 * Runs a read like `wpdb` does: a failure sets `last_error` and yields nothing instead of throwing.
	 *
	 * @param string|null $query Statement.
	 * @return \PDOStatement|null
	 */
	private function read( $query ): ?\PDOStatement {
		$this->statements[] = (string) $query;
		$this->last_error   = '';

		if ( $this->fail_reads ) {
			$this->last_error = 'MySQL server has gone away';

			return null;
		}

		try {
			$statement = $this->pdo->query( (string) $query );
		} catch ( \PDOException $e ) {
			$this->last_error = $e->getMessage();

			return null;
		}

		return false === $statement ? null : $statement;
	}

	/**
	 * Rewrites the MySQL forms the stores use into SQLite's: `INSERT IGNORE`, the upsert, `GREATEST()` and `DELETE … LIMIT`.
	 *
	 * @param string $sql MySQL statement.
	 * @return string
	 */
	private static function to_sqlite( string $sql ): string {
		$sql = (string) preg_replace( '/^INSERT IGNORE/', 'INSERT OR IGNORE', $sql );
		$sql = 'START TRANSACTION' === $sql ? 'BEGIN' : $sql;
		$sql = str_replace( 'ON DUPLICATE KEY UPDATE', 'ON CONFLICT(account_id, bucket) DO UPDATE SET', $sql );
		// SQLite has no `DELETE … LIMIT` unless it was compiled with it: bound the delete by the rowids it would have taken.
		$sql = (string) preg_replace( '/^DELETE FROM (\S+) WHERE (.+) LIMIT (\d+)$/s', 'DELETE FROM $1 WHERE rowid IN (SELECT rowid FROM $1 WHERE $2 LIMIT $3)', $sql );

		return str_replace( 'GREATEST(', 'MAX(', $sql );
	}

	/**
	 * Reads one row.
	 *
	 * @param string|null $query  Statement.
	 * @param string      $output Output type; only `ARRAY_A` is supported.
	 * @param int         $y      Unused.
	 * @return array<string,mixed>|null
	 */
	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		$statement = $this->read( $query );

		$row = null === $statement ? false : $statement->fetch( \PDO::FETCH_ASSOC );

		return false === $row ? null : $row;
	}

	/**
	 * Reads every row of a query.
	 *
	 * @param string|null $query  Statement.
	 * @param string      $output Output type; only `ARRAY_A` is supported.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( $query = null, $output = 'OBJECT' ) {
		$statement = $this->read( $query );

		return null === $statement ? array() : $statement->fetchAll( \PDO::FETCH_ASSOC );
	}

	/**
	 * Reads one column of every row.
	 *
	 * @param string|null $query Statement.
	 * @param int         $x     Column offset.
	 * @return array<int,mixed>
	 */
	public function get_col( $query = null, $x = 0 ) {
		$statement = $this->read( $query );

		return null === $statement ? array() : $statement->fetchAll( \PDO::FETCH_COLUMN, (int) $x );
	}

	/**
	 * Stands in for `wpdb::esc_like()`. SQLite's `LIKE` has no default escape character, so the text is returned as it is: a test
	 * that searches with this database uses text without `%` or `_`, and the one pattern the reader builds from a literal prefix
	 * (`attribute_`) is the only `_` it meets.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return (string) $text;
	}

	/**
	 * Reads one value.
	 *
	 * @param string|null $query Statement.
	 * @param int         $x     Unused.
	 * @param int         $y     Unused.
	 * @return string|null
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$statement = $this->read( $query );
		$value     = null === $statement ? false : $statement->fetchColumn();

		return false === $value ? null : (string) $value;
	}

	/**
	 * Inserts a row; a null value is stored as NULL, like WordPress does.
	 *
	 * @param string              $table  Table.
	 * @param array<string,mixed> $data   Column => value.
	 * @param string[]|string[]   $format Ignored.
	 * @return int|false False on a duplicate key.
	 * @throws \PDOException On any other database error.
	 */
	public function insert( $table, $data, $format = null ) {
		if ( $this->refuse_inserts ) {
			return false;
		}

		$columns = array_keys( $data );
		$sql     = 'INSERT INTO "' . $table . '" ("' . implode( '","', $columns ) . '") VALUES (' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')';

		$this->statements[] = $sql;
		try {
			$this->bound( $sql, array_values( $data ) );
		} catch ( \PDOException $e ) {
			if ( '23000' !== (string) $e->getCode() ) {
				throw $e;
			}

			// A duplicate key is a failed insert, as in wpdb.
			$this->last_error = $e->getMessage();

			return false;
		}

		$this->insert_id = (int) $this->pdo->lastInsertId();

		return 1;
	}

	/**
	 * Inserts a row or replaces the one with the same key.
	 *
	 * @param string              $table  Table.
	 * @param array<string,mixed> $data   Column => value.
	 * @param string[]|string[]   $format Ignored.
	 * @return int|false Rows affected.
	 */
	public function replace( $table, $data, $format = null ) {
		$columns = array_keys( $data );
		$sql     = 'INSERT OR REPLACE INTO "' . $table . '" ("' . implode( '","', $columns ) . '") VALUES (' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')';

		$this->statements[] = $sql;
		$this->bound( $sql, array_values( $data ) );

		return 1;
	}

	/**
	 * Updates rows; a null value is stored as NULL, like WordPress does.
	 *
	 * @param string              $table        Table.
	 * @param array<string,mixed> $data         Column => new value.
	 * @param array<string,mixed> $where        Column => value, joined with AND.
	 * @param string[]|string[]   $format       Ignored.
	 * @param string[]|string[]   $where_format Ignored.
	 * @return int|false Rows changed.
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( null !== $this->before_update ) {
			$hook                = $this->before_update;
			$this->before_update = null;
			$hook( (string) $table );
		}

		$assign = array();
		foreach ( array_keys( $data ) as $column ) {
			$assign[] = '"' . $column . '" = ?';
		}

		$conditions = array();
		foreach ( array_keys( $where ) as $column ) {
			$conditions[] = '"' . $column . '" = ?';
		}

		$sql                = 'UPDATE "' . $table . '" SET ' . implode( ', ', $assign ) . ' WHERE ' . implode( ' AND ', $conditions );
		$this->statements[] = $sql;

		return $this->bound( $sql, array_merge( array_values( $data ), array_values( $where ) ) );
	}

	/**
	 * Accepts the switch; this double prints nothing either way.
	 *
	 * @param bool $suppress Whether to suppress errors.
	 * @return bool Previous setting.
	 */
	public function suppress_errors( $suppress = true ) {
		return false;
	}

	/**
	 * Runs a statement with bound string parameters; a bound value keeps the NUL bytes of a gzip payload that `quote()` refuses, and keys still compare as text.
	 *
	 * @param string           $sql    Statement with `?` placeholders.
	 * @param array<int,mixed> $values Values; null is bound as NULL.
	 * @return int Rows changed.
	 * @throws \PDOException On a database error, a constraint violation included.
	 */
	private function bound( string $sql, array $values ): int {
		$statement = $this->pdo->prepare( $sql );
		foreach ( $values as $index => $value ) {
			$statement->bindValue( $index + 1, null === $value ? null : (string) $value, null === $value ? \PDO::PARAM_NULL : \PDO::PARAM_STR );
		}
		$statement->execute();

		return $statement->rowCount();
	}

	/**
	 * Runs a SELECT and returns every row.
	 *
	 * @param string $sql Statement.
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( string $sql ): array {
		return $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
	}
}

<?php
/**
 * The persistence tables of a fake marketplace, on SQLite.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data\Support;

use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Creates every table of {@see Schema} in an in-memory SQLite database by **translating the real `CREATE TABLE` statements**, not
 * by writing a second column list: a column added to, renamed in or removed from the schema is in the test database at once, so a
 * store that drifts from the schema fails its test instead of passing against a stale copy.
 *
 * The translation keeps what the stores' SQL depends on: columns with their nullability and defaults, the primary key
 * (`AUTO_INCREMENT` becomes `INTEGER PRIMARY KEY AUTOINCREMENT`) and the unique keys. Plain `KEY` lines are indexes, which no
 * test needs.
 */
final class Sqlite_Tables {
	/**
	 * Marketplace id of the fake marketplace: the stores never see another.
	 *
	 * @var string
	 */
	public const MARKETPLACE_ID = 'fake';

	/**
	 * Site prefix of the test database.
	 *
	 * @var string
	 */
	public const PREFIX = 'wp_';

	/**
	 * The schema of the fake marketplace.
	 *
	 * @return Schema
	 */
	public static function schema(): Schema {
		return new Schema( self::MARKETPLACE_ID );
	}

	/**
	 * Full name of a table of the fake marketplace.
	 *
	 * @param string $table Table name without any prefix, e.g. `links`.
	 * @return string
	 */
	public static function table( string $table ): string {
		return self::schema()->table_name( $table, self::PREFIX );
	}

	/**
	 * Creates every table in a database.
	 *
	 * @param Sqlite_Wpdb $db Database.
	 * @return void
	 */
	public static function install( Sqlite_Wpdb $db ): void {
		foreach ( self::schema()->statements( self::PREFIX, '' ) as $statement ) {
			$db->define( self::to_sqlite( $statement ) );
		}
	}

	/**
	 * Translates one MySQL `CREATE TABLE` statement of the schema into SQLite's dialect.
	 *
	 * @param string $mysql Statement from {@see Schema::statements()}.
	 * @return string
	 */
	public static function to_sqlite( string $mysql ): string {
		$lines    = array_map( 'trim', explode( "\n", $mysql ) );
		$name     = (string) preg_replace( '/^CREATE TABLE (\S+) \(.*$/', '$1', (string) array_shift( $lines ) );
		$has_auto = false !== strpos( $mysql, 'AUTO_INCREMENT' );
		$parts    = [];

		foreach ( $lines as $line ) {
			$line = rtrim( $line, ',' );

			if ( 0 === strpos( $line, ')' ) || '' === $line || 0 === strpos( $line, 'KEY ' ) ) {
				continue;
			}

			if ( 0 === strpos( $line, 'PRIMARY KEY' ) ) {
				$parts[] = $has_auto ? null : (string) preg_replace( '/^PRIMARY KEY\s+/', 'PRIMARY KEY ', $line );
				continue;
			}

			if ( 0 === strpos( $line, 'UNIQUE KEY' ) ) {
				$parts[] = (string) preg_replace( '/^UNIQUE KEY \w+ /', 'UNIQUE ', $line );
				continue;
			}

			$parts[] = self::column( $line );
		}

		return 'CREATE TABLE ' . $name . ' (' . implode( ', ', array_filter( $parts ) ) . ')';
	}

	/**
	 * Translates a column definition.
	 *
	 * @param string $line MySQL column definition, e.g. `link_id bigint(20) unsigned NOT NULL AUTO_INCREMENT`.
	 * @return string
	 */
	private static function column( string $line ): string {
		preg_match( '/^(`?\w+`?) (\w+)(?:\([^)]*\))?(?: unsigned)?(.*)$/', $line, $match );

		list( , $name, $type, $rest ) = $match;

		if ( false !== strpos( $rest, 'AUTO_INCREMENT' ) ) {
			return $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
		}

		if ( in_array( $type, [ 'bigint', 'int', 'tinyint', 'smallint' ], true ) ) {
			$sqlite = 'INTEGER';
		} elseif ( 'decimal' === $type ) {
			$sqlite = 'REAL';
		} elseif ( 'longblob' === $type ) {
			$sqlite = 'BLOB';
		} else {
			$sqlite = 'TEXT';
		}

		// MySQL's per-column charset and binary collation: SQLite compares text byte for byte already.
		$rest = (string) preg_replace( '/ CHARACTER SET \w+| COLLATE \w+/', '', $rest );

		return $name . ' ' . $sqlite . $rest;
	}
}

<?php
/**
 * The one claim mechanism for rows that more than one runner may touch.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Spec 3.5. A table that uses the trait has the columns `claim_token char(32) null` and `claim_until datetime null`
 * (UTC). Three rules turn a claim into a fence rather than a lock, and they are the reason every owner of a claimed
 * row goes through this trait:
 *
 * 1. every durable write by the holder carries the token in its `WHERE` ({@see self::update_if_claimed()}): a runner
 *    whose claim expired and was taken over changes 0 rows and must treat that as «lost the claim»;
 * 2. a side effect outside our tables is performed only right after {@see self::holds()} returned true;
 * 3. a claim is released only by its holder ({@see self::release()}), in `finally`.
 *
 * The using class supplies the database and the clock; keys are `column => value` maps whose columns come from code,
 * never from a request.
 *
 * @since 2.0.29
 */
trait Claimable {
	/**
	 * Returns the database.
	 *
	 * @since 2.0.29
	 *
	 * @return \wpdb
	 */
	abstract protected function claim_db(): \wpdb;

	/**
	 * Returns the current Unix time.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	abstract protected function claim_now(): int;

	/**
	 * Claims one row if nobody holds it or the holder's claim has expired.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $table Full table name.
	 * @param array<string,mixed> $key   Column => value pairs that identify the row.
	 * @param int                 $ttl   Seconds the claim lasts.
	 * @return string|null The new token, or null when another holder owns the row (or no row matches).
	 */
	protected function acquire( string $table, array $key, int $ttl ): ?string {
		$db    = $this->claim_db();
		$token = $this->new_claim_token();
		$now   = $this->claim_clock( 0 );

		list( $where, $args ) = $this->claim_where( $key );

		$changed = $db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement.
			$db->prepare(
				'UPDATE %i SET claim_token = %s, claim_until = %s WHERE ' . $where . ' AND (claim_token IS NULL OR claim_until < %s)',
				array_merge( [ $table, $token, $this->claim_clock( $ttl ) ], $args, [ $now ] )
			)
		);

		return 1 === $changed ? $token : null;
	}

	/**
	 * Tells whether a token still owns a row.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $table Full table name.
	 * @param array<string,mixed> $key   Column => value pairs that identify the row.
	 * @param string              $token Claim token.
	 * @return bool
	 */
	protected function holds( string $table, array $key, string $token ): bool {
		$db = $this->claim_db();

		list( $where, $args ) = $this->claim_where( $key );

		$found = $db->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement.
			$db->prepare(
				'SELECT 1 FROM %i WHERE ' . $where . ' AND claim_token = %s AND claim_until > %s',
				array_merge( [ $table ], $args, [ $token, $this->claim_clock( 0 ) ] )
			)
		);

		return null !== $found && '' !== $found;
	}

	/**
	 * Extends a claim the caller holds.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $table Full table name.
	 * @param array<string,mixed> $key   Column => value pairs that identify the row.
	 * @param string              $token Claim token.
	 * @param int                 $ttl   Seconds from now.
	 * MySQL counts a row whose new value equals the old one as unchanged, so a renewal in the same second as the acquire changes 0
	 * rows while the claim is still held; that case falls back to {@see self::holds()}.
	 *
	 * @return bool False when the token no longer owns the row.
	 */
	protected function renew( string $table, array $key, string $token, int $ttl ): bool {
		return $this->update_if_claimed( $table, $key, $token, 'claim_until = %s', [ $this->claim_clock( $ttl ) ], false )
			|| $this->holds( $table, $key, $token );
	}

	/**
	 * Releases a claim; a caller that is not the holder changes nothing.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $table Full table name.
	 * @param array<string,mixed> $key   Column => value pairs that identify the row.
	 * @param string              $token Claim token.
	 * @return void
	 */
	protected function release( string $table, array $key, string $token ): void {
		$this->update_if_claimed( $table, $key, $token, 'claim_token = NULL, claim_until = NULL', [], false );
	}

	/**
	 * Runs an accounting write that only the holder of the claim may make (rule 1).
	 *
	 * @since 2.0.29
	 *
	 * @param string              $table        Full table name.
	 * @param array<string,mixed> $key          Column => value pairs that identify the row.
	 * @param string              $token        Claim token.
	 * @param string              $set          `SET` fragment of fixed text and placeholders.
	 * @param array<int,mixed>    $set_args     Values for the placeholders of `$set`.
	 * @param bool                $release_also True to clear the claim in the same statement.
	 * @return bool False when the token no longer owns the row.
	 */
	protected function update_if_claimed( string $table, array $key, string $token, string $set, array $set_args, bool $release_also ): bool {
		$db = $this->claim_db();

		list( $where, $args ) = $this->claim_where( $key );

		$changed = $db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement.
			$db->prepare(
				'UPDATE %i SET ' . $set . ( $release_also ? ', claim_token = NULL, claim_until = NULL' : '' ) . ' WHERE ' . $where . ' AND claim_token = %s',
				array_merge( [ $table ], $set_args, $args, [ $token ] )
			)
		);

		return 1 === $changed;
	}

	/**
	 * Runs an accounting write of the holder that must not pass silently: an SQL error throws instead of reading as «claim lost»,
	 * and a write that changed nothing counts as done only while the token still owns the row. MySQL reports a row whose new values
	 * equal the old ones as unchanged, so with the claim held that means the row already holds exactly these values.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $table    Full table name.
	 * @param array<string,mixed> $key      Column => value pairs that identify the row.
	 * @param string              $token    Claim token.
	 * @param string              $set      `SET` fragment of fixed text and placeholders.
	 * @param array<int,mixed>    $set_args Values for the placeholders of `$set`.
	 * @return bool False when the token no longer owns the row.
	 * @throws \RuntimeException When the database refused the statement.
	 */
	protected function write_if_claimed( string $table, array $key, string $token, string $set, array $set_args ): bool {
		$db = $this->claim_db();

		list( $where, $args ) = $this->claim_where( $key );

		$changed = $db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only fixed fragments and placeholders are concatenated into the statement.
			$db->prepare(
				'UPDATE %i SET ' . $set . ' WHERE ' . $where . ' AND claim_token = %s',
				array_merge( [ $table ], $set_args, $args, [ $token ] )
			)
		);

		if ( false === $changed ) {
			throw new \RuntimeException( 'A claimed write was refused by the database: ' . $db->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return 1 === $changed || $this->holds( $table, $key, $token );
	}

	/**
	 * Makes a fresh claim token: 32 hex characters.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	protected function new_claim_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Formats the current time plus an offset as the UTC datetime the columns hold.
	 *
	 * @since 2.0.29
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	protected function claim_clock( int $offset ): string {
		return gmdate( 'Y-m-d H:i:s', $this->claim_now() + $offset );
	}

	/**
	 * Turns a key map into a `WHERE` fragment and its values.
	 *
	 * @param array<string,mixed> $key Column => value pairs.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function claim_where( array $key ): array {
		$parts = [];
		$args  = [];

		foreach ( $key as $column => $value ) {
			$parts[] = is_int( $value ) ? '%i = %d' : '%i = %s';
			$args[]  = $column;
			$args[]  = $value;
		}

		return [ implode( ' AND ', $parts ), $args ];
	}
}

<?php
/**
 * The schema statements carry every table and column of one marketplace's persistence.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Reads the `CREATE TABLE` text the way `dbDelta` does and compares it with the column lists the stores depend on.
 *
 * The statements are not executed on MySQL here (the suite has no MySQL); {@see self::test_every_statement_runs_on_the_test_database()}
 * runs their SQLite translation, and the idempotence of `dbDelta` on them is for the integration run to show.
 */
final class SchemaTest extends TestCase {
	private const PREFIX = 'wp_';
	private const FAKE   = 'wp_woodev_fake_';

	/**
	 * Columns of each table, keyed by table name without the family prefix.
	 *
	 * @return array<string,string[]>
	 */
	private function expected_columns(): array {
		return [
			'accounts'      => [ 'account_id', 'name', 'external_id', 'secret_enc', 'secret_fingerprint', 'is_enabled', 'health', 'health_message', 'health_checked_at', 'key_expires_at', 'seller_id', 'currency', 'warehouse_id', 'stock_mode', 'price_direction', 'orders_enabled', 'import_enabled', 'notification_token', 'notification_state', 'settings', 'claim_token', 'claim_until' ],
			'links'         => [ 'link_id', 'account_id', 'wc_product_id', 'wc_parent_id', 'kind', 'offer_id', 'remote_product_id', 'remote_sku', 'remote_model_id', 'card_state', 'is_created', 'sync_stock', 'sync_price', 'warehouse_id', 'is_oversize', 'price_rule_override', 'attribute_overrides', 'mapping_id', 'last_task_id', 'last_error_code', 'last_error_text', 'last_pushed_stock', 'last_pushed_stock_at', 'last_pushed_price', 'last_pushed_price_at', 'updated_at' ],
			'tasks'         => [ 'task_id', 'account_id', 'kind', 'remote_task_id', 'status', 'submitted_at', 'last_polled_at', 'finished_at', 'poll_count', 'item_count', 'request_hash', 'result' ],
			'task_items'    => [ 'task_id', 'link_id', 'offer_id', 'status', 'errors' ],
			'events'        => [ 'event_id', 'account_id', 'type', 'dedup_key', 'received_at', 'processed_at', 'status', 'attempts', 'claim_token', 'claim_until', 'next_attempt_at', 'payload', 'result', 'source_ip', 'ip_trusted' ],
			'sync_state'    => [ 'account_id', 'link_id', 'warehouse_id', 'kind', 'pending_payload', 'pending_rev', 'pending_since', 'in_flight_rev', 'in_flight_payload', 'sent_payload', 'sent_rev', 'sent_at', 'attempts', 'next_attempt_at', 'last_error', 'remote_present', 'remote_reserved', 'remote_read_at' ],
			'postings'      => [ 'account_id', 'external_key', 'wc_order_id', 'members', 'status_raw', 'is_terminal', 'claim_token', 'claim_until', 'lines', 'baseline', 'first_seen_at', 'refreshed_at' ],
			'documents'     => [ 'document_id', 'account_id', 'task_id', 'external_keys', 'content_type', 'body', 'created_at' ],
			'throttle'      => [ 'account_id', 'bucket', 'state', 'version', 'disabled_until', 'disabled_reason' ],
			'api_errors'    => [ 'error_id', 'account_id', 'path', 'http_status', 'code', 'message', 'trace_id', 'occurred_at', 'context' ],
			'mappings'      => [ 'mapping_id', 'name', 'wc_product_id', 'category', 'profile_id', 'attributes', 'updated_at' ],
			'mapping_terms' => [ 'term_id', 'mapping_id' ],
			'profiles'      => [ 'profile_id', 'name', 'settings' ],
			'catalog_cache' => [ 'cache_key', 'payload', 'fetched_at' ],
		];
	}

	/**
	 * Every table has a statement named `{prefix}woodev_{marketplace id}_{table}`, and no other table is created.
	 *
	 * @return void
	 */
	public function test_statements_cover_exactly_the_tables(): void {
		$schema   = new Schema( 'fake' );
		$expected = [];
		foreach ( array_keys( $this->expected_columns() ) as $table ) {
			$expected[] = self::FAKE . $table;
		}

		$this->assertSame( $expected, array_keys( $schema->statements( self::PREFIX, '' ) ) );
		$this->assertSame( $expected, $schema->table_names( self::PREFIX ) );
	}

	/**
	 * Each statement has exactly the columns of its table: none missing, none invented.
	 *
	 * @return void
	 */
	public function test_every_table_has_exactly_the_expected_columns(): void {
		$statements = ( new Schema( 'fake' ) )->statements( self::PREFIX, '' );

		foreach ( $this->expected_columns() as $table => $columns ) {
			$parsed = $this->parse( $statements[ self::FAKE . $table ] );

			$this->assertSame( $columns, array_keys( $parsed['columns'] ), "columns of {$table}" );
		}
	}

	/**
	 * Acceptance «per-plugin table prefix»: two marketplaces never share a table, and the prefix is the only difference.
	 *
	 * @return void
	 */
	public function test_each_marketplace_has_its_own_table_set(): void {
		$first  = ( new Schema( 'ozon' ) )->table_names( self::PREFIX );
		$second = ( new Schema( 'wildberries' ) )->table_names( self::PREFIX );

		$this->assertSame( [], array_intersect( $first, $second ) );
		$this->assertSame( 'wp_woodev_ozon_accounts', $first[0] );
		$this->assertSame( 'wp_woodev_wildberries_accounts', $second[0] );
		$this->assertSame( 'woodev_wildberries_', ( new Schema( 'wildberries' ) )->table_prefix() );
	}

	/**
	 * The site prefix (a multisite blog's `wp_2_`) goes in front of the marketplace prefix.
	 *
	 * @return void
	 */
	public function test_the_site_prefix_goes_first(): void {
		$this->assertSame( 'wp_2_woodev_fake_links', ( new Schema( 'fake' ) )->table_name( 'links', 'wp_2_' ) );
	}

	/**
	 * A marketplace id is part of a table name, so only a safe one is accepted.
	 *
	 * @dataProvider invalid_ids
	 *
	 * @param string $id Marketplace id.
	 * @return void
	 */
	public function test_an_id_that_cannot_be_part_of_a_table_name_is_refused( string $id ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Schema( $id );
	}

	/**
	 * Ids that must not reach SQL.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function invalid_ids(): array {
		return [
			'empty'              => [ '' ],
			'dash'               => [ 'ozon-seller' ],
			'upper case'         => [ 'Ozon' ],
			'starts with digit'  => [ '1ozon' ],
			'quote'              => [ "o'zon" ],
			'space'              => [ 'oz on' ],
			'longer than a name' => [ str_repeat( 'a', Schema::MAX_ID_LENGTH + 1 ) ],
		];
	}

	/**
	 * The longest id still gives table names MySQL accepts (64 characters) for a site prefix of 20.
	 *
	 * @return void
	 */
	public function test_the_longest_table_name_fits_mysql(): void {
		$names = ( new Schema( str_repeat( 'a', Schema::MAX_ID_LENGTH ) ) )->table_names( str_repeat( 'p', 20 ) );

		foreach ( $names as $name ) {
			$this->assertLessThanOrEqual( 64, strlen( $name ), $name );
		}
	}

	/**
	 * No statement names a marketplace: the schema is the same text for every one, apart from the table prefix.
	 *
	 * @return void
	 */
	public function test_the_statements_are_neutral(): void {
		$schema = new Schema( 'fake' );
		$one    = $schema->statements( self::PREFIX, '' );
		$other  = ( new Schema( 'other' ) )->statements( self::PREFIX, '' );

		$this->assertSame(
			str_replace( 'woodev_other_', 'woodev_fake_', implode( "\n", $other ) ),
			implode( "\n", $one )
		);
		$this->assertDoesNotMatchRegularExpression( '/ozon|wildberries|yandex/i', implode( "\n", $one ) );
	}

	/**
	 * The claim columns sit on the three rows that more than one runner touches.
	 *
	 * @return void
	 */
	public function test_claim_columns_are_on_events_postings_and_accounts(): void {
		$statements = ( new Schema( 'fake' ) )->statements( self::PREFIX, '' );

		foreach ( [ 'events', 'postings', 'accounts' ] as $table ) {
			$columns = $this->parse( $statements[ self::FAKE . $table ] )['columns'];

			$this->assertStringStartsWith( 'char(32) NULL', $columns['claim_token'], "{$table}.claim_token" );
			$this->assertStringStartsWith( 'datetime NULL', $columns['claim_until'], "{$table}.claim_until" );
		}
	}

	/**
	 * The keys the writers rely on: composite primary keys and the unique indexes.
	 *
	 * @return void
	 */
	public function test_keys_the_stores_rely_on_exist(): void {
		$keys = [
			'accounts'      => [ 'PRIMARY KEY  (account_id)', 'UNIQUE KEY external_id (external_id)' ],
			'links'         => [ 'UNIQUE KEY account_product (account_id,wc_product_id)', 'UNIQUE KEY account_offer (account_id,offer_id)', 'KEY account_state (account_id,card_state)', 'KEY account_sku (account_id,remote_sku)', 'KEY account_remote_product (account_id,remote_product_id)', 'KEY wc_parent (wc_parent_id)' ],
			'task_items'    => [ 'PRIMARY KEY  (task_id,link_id)' ],
			'events'        => [ 'UNIQUE KEY account_dedup (account_id,dedup_key)' ],
			'sync_state'    => [ 'PRIMARY KEY  (account_id,link_id,warehouse_id,kind)' ],
			'postings'      => [ 'PRIMARY KEY  (account_id,external_key)' ],
			'throttle'      => [ 'PRIMARY KEY  (account_id,bucket)' ],
			'mapping_terms' => [ 'PRIMARY KEY  (term_id)' ],
			'catalog_cache' => [ 'PRIMARY KEY  (cache_key)' ],
		];

		$statements = ( new Schema( 'fake' ) )->statements( self::PREFIX, '' );

		foreach ( $keys as $table => $expected ) {
			$parsed = $this->parse( $statements[ self::FAKE . $table ] );

			foreach ( $expected as $key ) {
				$this->assertContains( $key, $parsed['keys'], "{$table}: {$key}" );
			}
		}
	}

	/**
	 * An offer id may be null on a parent row, which the unique `(account_id, offer_id)` key then allows many times; and it is wide
	 * enough for any article policy an adapter declares.
	 *
	 * @return void
	 */
	public function test_parent_links_may_have_no_offer_id(): void {
		$columns = $this->parse( ( new Schema( 'fake' ) )->statements( self::PREFIX, '' )[ self::FAKE . 'links' ] )['columns'];

		$this->assertStringStartsWith( 'varchar(191) NULL', $columns['offer_id'] );
		$this->assertStringNotContainsString( 'NOT NULL', $columns['offer_id'] );
	}

	/**
	 * The enum defaults are neutral words, not a marketplace's.
	 *
	 * @return void
	 */
	public function test_enum_defaults_are_neutral(): void {
		$columns = $this->parse( ( new Schema( 'fake' ) )->statements( self::PREFIX, '' )[ self::FAKE . 'accounts' ] )['columns'];

		$this->assertStringContainsString( "DEFAULT 'site'", $columns['stock_mode'] );
		$this->assertStringContainsString( "DEFAULT 'to_marketplace'", $columns['price_direction'] );
	}

	/**
	 * Rules that make a second `dbDelta` run a no-op (each is explained in the `Schema` docblock).
	 *
	 * @return void
	 */
	public function test_statements_follow_the_dbdelta_rules(): void {
		foreach ( ( new Schema( 'fake' ) )->statements( self::PREFIX, 'DEFAULT CHARACTER SET utf8mb4' ) as $name => $sql ) {
			$this->assertSame( 1, preg_match( '/^CREATE TABLE ' . preg_quote( $name, '/' ) . ' \(\n/', $sql ), "{$name}: one CREATE TABLE, one space before the name" );
			$this->assertSame( 1, preg_match( '/^\t\t\tPRIMARY KEY  \(/m', $sql ), "{$name}: PRIMARY KEY followed by two spaces" );
			$this->assertStringContainsString( ') DEFAULT CHARACTER SET utf8mb4;', $sql, "{$name}: charset clause" );
			$this->assertDoesNotMatchRegularExpression( '/\bIF NOT EXISTS\b|\bINDEX\b|\bjson\b|CURRENT_TIMESTAMP/i', $sql, "{$name}: forbidden construct" );
			$this->assertDoesNotMatchRegularExpression( '/\b(bigint|int|tinyint|smallint)\b(?!\()/', $sql, "{$name}: integer type without the display width" );
		}
	}

	/**
	 * The reserved word is quoted in the statement that uses it.
	 *
	 * @return void
	 */
	public function test_reserved_word_lines_is_quoted(): void {
		$this->assertStringContainsString( '`lines` longtext', ( new Schema( 'fake' ) )->statements( self::PREFIX, '' )[ self::FAKE . 'postings' ] );
	}

	/**
	 * The statements are translatable and the translation runs: every table of the schema exists on the test database, so the
	 * store tests exercise the real columns.
	 *
	 * @return void
	 */
	public function test_every_statement_runs_on_the_test_database(): void {
		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );

		foreach ( array_keys( $this->expected_columns() ) as $table ) {
			$this->assertSame( [], $db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( $table ) ), $table );
		}
	}

	/**
	 * Splits a statement into columns (name => definition) and key lines.
	 *
	 * @param string $sql One `CREATE TABLE` statement.
	 * @return array{columns:array<string,string>,keys:string[]}
	 */
	private function parse( string $sql ): array {
		$result = [
			'columns' => [],
			'keys'    => [],
		];

		foreach ( explode( "\n", $sql ) as $line ) {
			$line = rtrim( trim( $line ), ',' );

			if ( ! preg_match( '/^\t*(`?)(\w+)\1 (.+)$/', "\t" . $line, $matches ) || 0 === strpos( $line, 'CREATE TABLE' ) || 0 === strpos( $line, ')' ) ) {
				continue;
			}

			if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\b/', $line ) ) {
				$result['keys'][] = $line;
				continue;
			}

			$result['columns'][ $matches[2] ] = $matches[3];
		}

		return $result;
	}
}

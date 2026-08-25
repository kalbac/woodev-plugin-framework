<?php
/**
 * Integration: the popular-settlements table against a LIVE MySQL (#498, #488
 * slice 2).
 *
 * Slice 2 introduced a real database table — `dbDelta()`, a
 * `(provider_id, locality_key)` UNIQUE key and an atomic
 * `INSERT … ON DUPLICATE KEY UPDATE` — and covered it only with unit tests
 * driving a FAKE `$wpdb`. Exactly the four things a fake cannot prove were
 * therefore unproven:
 *
 * 1. that `dbDelta()` actually creates the table with this schema. `dbDelta()`
 *    parses the DDL with its own rules (whitespace, keyword case, `KEY`
 *    formatting) and silently skips what it does not understand, so a schema
 *    that reads correctly to a human can still produce a table that is missing
 *    a column or an index;
 * 2. that the UNIQUE key is genuinely unique on a live engine;
 * 3. that the upsert increments `order_count` and moves `last_ordered_at`
 *    while leaving `record`, `created_at` and `last_verified_at` alone — the
 *    unit test asserts this against a fake SQL parser of our own, i.e. it
 *    tests OUR reading of the statement, not the engine's;
 * 4. that the deferred install (`init` priority 20, gated on an option-stored
 *    schema version) runs in a real WordPress lifecycle and runs AGAIN after a
 *    version bump.
 *
 * WHY THIS FILE REMOVES TWO WORDPRESS TEST FILTERS BEFORE IT DOES ANYTHING —
 * and it is the exact class of thing #498 was filed to catch.
 * `WP_UnitTestCase_Base::start_transaction()` adds two `query` filters,
 * `_create_temporary_tables()` and `_drop_temporary_tables()`, which rewrite a
 * leading `CREATE TABLE` to `CREATE TEMPORARY TABLE` and a leading `DROP TABLE`
 * to `DROP TEMPORARY TABLE` (`wordpress-phpunit/includes/abstract-testcase.php`
 * lines 478-479, 498, 511). Left in place, `Popular_Settlement_Store::install()`
 * would create a TEMPORARY table — while `SHOW TABLES` does not list temporary
 * tables at all and would answer about a leftover REAL one instead. Measured
 * here on the first run: a `DROP TABLE IF EXISTS` reported success and
 * `SHOW TABLES LIKE` still returned the table, because the drop had hit the
 * temporary shadow and the read had seen the real one. A schema test that does
 * not remove these filters is measuring two different tables at once.
 *
 * With them removed the table is real, so the DDL also commits the surrounding
 * transaction implicitly and nothing this file writes is rolled back. Both ends
 * are therefore explicit — dropped and recreated in `setUp()`, dropped again in
 * `tearDown()` — which is also what makes each test's row expectations exact
 * rather than "whatever the previous test left".
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
use Woodev\Tests\Integration\TestCase;

class PopularSettlementTableTest extends TestCase {

	/**
	 * The option `Location_Provider_Registry::maybe_install_popular_settlements_table()`
	 * gates on. Duplicated as a literal on purpose: the constant is private, and
	 * a test that reached into it with reflection would stop noticing a rename
	 * that breaks every installed site's upgrade path.
	 *
	 * @var string
	 */
	private const SCHEMA_VERSION_OPTION = 'woodev_popular_settlements_schema_version';

	/** @var Popular_Settlement_Store */
	private $store;

	/** @var string */
	private $table;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		// See the file docblock: without this the DDL below becomes TEMPORARY and
		// every `SHOW TABLES` assertion in this file would silently answer about a
		// different table.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		$this->store = new Popular_Settlement_Store();
		$this->table = $wpdb->prefix . 'woodev_popular_settlements';

		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" ); // phpcs:ignore WordPress.DB -- test fixture DDL.

		$this->store->install();
	}

	protected function tearDown(): void {
		global $wpdb;

		// Drop AND reinstall, never merely drop. The suite's own bootstrap fires
		// `init`, which runs `maybe_install_popular_settlements_table()` OUTSIDE any
		// test's transaction and therefore leaves a REAL, empty table behind — the
		// state every later test in the run inherits. Leaving it dropped made two
		// unrelated tests in `ProviderSelectionScopeAgreementTest` go RISKY with
		// «Table 'wp_woodev_popular_settlements' doesn't exist» printed mid-test.
		// This restores exactly what was found: present, empty, current schema.
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" ); // phpcs:ignore WordPress.DB -- test fixture DDL.

		$this->store->install();

		delete_option( self::SCHEMA_VERSION_OPTION );

		parent::tearDown();
	}

	/**
	 * A settlement record for the fixture CDEK provider, which is the one
	 * bundled provider that declares `CAPABILITY_RESOLVE_KEY` — `enroll()`'s D4
	 * gate silently returns for anything else, so a test built on a
	 * capability-less provider would pass while writing nothing.
	 *
	 * @param string $native_id Provider-native settlement id.
	 * @param string $name      Settlement name.
	 * @return Location_Record
	 */
	private function record( string $native_id, string $name = 'Москва' ): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => $this->provider()->get_id() . ':' . $native_id,
				'provider_id' => $this->provider()->get_id(),
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'settlement'  => [ 'name' => $name, 'type' => 'г' ],
			]
		);
	}

	/**
	 * The fixture provider instance.
	 *
	 * @return Location_Provider
	 */
	private function provider(): Location_Provider {
		return new \Woodev_Test_Cdek_Location_Provider();
	}

	/**
	 * Reads one raw row straight out of the database — deliberately NOT through
	 * `all_for_provider()`, because half of what this file exists to check is
	 * the columns the reader does not expose (`created_at`, `record`).
	 *
	 * @param string $locality_key The row's key.
	 * @return array<string,mixed>|null
	 */
	private function row( string $locality_key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB -- test assertion read.
			$wpdb->prepare( "SELECT * FROM `{$this->table}` WHERE locality_key = %s", $locality_key ),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	public function test_db_delta_creates_the_table_with_every_declared_column(): void {
		global $wpdb;

		$this->assertSame(
			$this->table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ), // phpcs:ignore WordPress.DB
			'dbDelta() did not create the popular-settlements table at all.'
		);

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$this->table}`" ); // phpcs:ignore WordPress.DB

		// Named one by one rather than asserted as a set: a MISSING column is the
		// failure mode dbDelta actually produces, and naming them makes the
		// diff say which one.
		foreach ( [ 'id', 'provider_id', 'locality_key', 'country', 'record', 'order_count', 'last_ordered_at', 'last_verified_at', 'created_at' ] as $column ) {
			$this->assertContains( $column, $columns, sprintf( 'dbDelta() did not create the `%s` column.', $column ) );
		}
	}

	public function test_db_delta_creates_the_provider_locality_unique_key(): void {
		global $wpdb;

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$this->table}`", ARRAY_A ); // phpcs:ignore WordPress.DB
		$unique  = [];

		foreach ( $indexes as $index ) {
			if ( 'provider_locality' === $index['Key_name'] ) {
				$this->assertSame( '0', (string) $index['Non_unique'], 'provider_locality exists but is not UNIQUE.' );
				$unique[ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			}
		}

		ksort( $unique );

		$this->assertSame(
			[ 'provider_id', 'locality_key' ],
			array_values( $unique ),
			'The (provider_id, locality_key) unique key is missing or has the wrong columns.'
		);
	}

	public function test_the_unique_key_is_enforced_by_the_engine(): void {
		global $wpdb;

		$record = $this->record( '44' );

		$this->store->enroll( $this->provider(), $record );

		$suppressed = $wpdb->suppress_errors( true );

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO `{$this->table}` (`provider_id`, `locality_key`, `country`, `record`, `order_count`, `created_at`)
				 VALUES (%s, %s, %s, %s, 1, %s)",
				$record->provider_id(),
				$record->key(),
				'RU',
				'{}',
				'2026-08-25 00:00:00'
			)
		);

		$wpdb->suppress_errors( $suppressed );

		$this->assertFalse( $inserted, 'A duplicate (provider_id, locality_key) was accepted — the unique key is not enforced.' );
	}

	public function test_enrolling_the_same_settlement_twice_bumps_one_row_and_leaves_the_record_alone(): void {
		$record = $this->record( '44' );

		$this->store->enroll( $this->provider(), $record );

		$first = $this->row( $record->key() );

		$this->assertNotNull( $first, 'The first enrolment wrote no row.' );
		$this->assertSame( '1', (string) $first['order_count'] );
		$this->assertNull( $first['last_verified_at'], 'Enrolment must not touch the verification clock (spec D2 — two clocks).' );

		// A SECOND enrolment of the same settlement, carrying a DIFFERENT record
		// payload: the upsert must bump the counters and leave the stored record
		// untouched — only verification (D5/D6) ever overwrites it in place.
		$this->store->enroll( $this->provider(), $this->record( '44', 'Москва (переименована)' ) );

		$second = $this->row( $record->key() );

		$this->assertNotNull( $second );
		$this->assertSame( $first['id'], $second['id'], 'The bump created a second row instead of updating the first.' );
		$this->assertSame( '2', (string) $second['order_count'], 'order_count was not incremented by the upsert.' );
		$this->assertSame( $first['record'], $second['record'], 'A bump overwrote the stored record; only verification may do that.' );
		$this->assertSame( $first['created_at'], $second['created_at'], 'A bump moved created_at.' );
		$this->assertNull( $second['last_verified_at'], 'A bump touched the verification clock.' );
		$this->assertNotNull( $second['last_ordered_at'], 'A bump left the ordering clock unset.' );
	}

	public function test_exactly_one_row_exists_after_two_enrolments_of_the_same_settlement(): void {
		global $wpdb;

		$this->store->enroll( $this->provider(), $this->record( '44' ) );
		$this->store->enroll( $this->provider(), $this->record( '44' ) );

		$this->assertSame(
			'1',
			(string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" ), // phpcs:ignore WordPress.DB
			'Two enrolments of one settlement produced more than one row on a live engine.'
		);
	}

	public function test_a_foreign_providers_row_is_never_read_back(): void {
		global $wpdb;

		$this->store->enroll( $this->provider(), $this->record( '44' ) );

		// Written directly: there is no second capability-declaring provider in
		// the fixture set, and what is under test is the READ filter, not another
		// provider's enrolment path.
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$this->table,
			[
				'provider_id'  => 'some-other-provider',
				'locality_key' => 'some-other-provider:44',
				'country'      => 'RU',
				'record'       => '{}',
				'order_count'  => 99,
				'created_at'   => '2026-08-25 00:00:00',
			]
		);

		$entries = $this->store->all_for_provider( $this->provider()->get_id() );

		$this->assertCount( 1, $entries, 'all_for_provider() returned a row belonging to another provider.' );
		$this->assertSame( $this->provider()->get_id(), $entries[0]->provider_id() );
	}

	public function test_the_deferred_install_runs_once_per_schema_version(): void {
		global $wpdb;

		$registry = Location_Provider_Registry::instance();

		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" ); // phpcs:ignore WordPress.DB
		delete_option( self::SCHEMA_VERSION_OPTION );

		$registry->maybe_install_popular_settlements_table();

		$this->assertSame(
			$this->table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ), // phpcs:ignore WordPress.DB
			'The deferred install did not create the table.'
		);
		$this->assertNotFalse( get_option( self::SCHEMA_VERSION_OPTION ), 'The deferred install did not stamp the schema version.' );

		// Same version again: the gate must short-circuit BEFORE touching the
		// database, so a table dropped behind its back stays dropped.
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" ); // phpcs:ignore WordPress.DB

		$registry->maybe_install_popular_settlements_table();

		$this->assertNull(
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ), // phpcs:ignore WordPress.DB
			'The install ran again for a schema version already stamped.'
		);

		// A version BUMP must make it run again — this is the upgrade path every
		// installed site takes, and the one the option gate exists to allow.
		update_option( self::SCHEMA_VERSION_OPTION, 'not-the-current-version' );

		$registry->maybe_install_popular_settlements_table();

		$this->assertSame(
			$this->table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ), // phpcs:ignore WordPress.DB
			'The install did not run again after the stored schema version changed.'
		);
	}
}

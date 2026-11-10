<?php
/**
 * The database throttle store: creation, reading and the compare-and-swap.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Governor;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Governor\Wpdb_Throttle_Store;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Runs the store's real SQL on SQLite. What this proves is the swap discipline (the throttle table): the
 * version, not a timestamp, decides who wins.
 */
final class WpdbThrottleStoreTest extends TestCase {
	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Store under test.
	 *
	 * @var Wpdb_Throttle_Store
	 */
	private $store;

	/**
	 * Opens a database with the tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );
		$this->store = new Wpdb_Throttle_Store( $this->db, Sqlite_Schema::THROTTLE );
	}

	/**
	 * A bucket nobody stored reads as null.
	 *
	 * @return void
	 */
	public function test_a_missing_bucket_reads_as_null(): void {
		$this->assertNull( $this->store->read( '7', 'default' ) );
	}

	/**
	 * Creation is an `INSERT IGNORE`: the second creator does not overwrite the first one's state.
	 *
	 * @return void
	 */
	public function test_creating_twice_keeps_the_first_row(): void {
		$this->store->create( '7', 'default', array( 'tokens' => 50.0 ) );
		$this->store->create( '7', 'default', array( 'tokens' => 1.0 ) );

		$row = $this->store->read( '7', 'default' );
		$this->assertEquals( array( 'tokens' => 50.0 ), $row->state() );
		$this->assertSame( 0, $row->version() );
		$this->assertNull( $row->disabled_until() );
		$this->assertSame( '', $row->disabled_reason() );
		$this->assertStringContainsString( 'INSERT IGNORE', $this->db->statements[0] );
	}

	/**
	 * Only the caller that read the current version wins; the version moves on with every win.
	 *
	 * @return void
	 */
	public function test_only_the_holder_of_the_current_version_wins_the_swap(): void {
		$this->store->create( '7', 'stocks', array( 'tokens' => 1.0 ) );

		$this->assertTrue( $this->store->swap( '7', 'stocks', 0, array( 'tokens' => 0.0 ), null, '' ), 'the first writer wins' );
		$this->assertFalse( $this->store->swap( '7', 'stocks', 0, array( 'tokens' => 0.0 ), null, '' ), 'the second writer read version 0 too and loses' );

		$row = $this->store->read( '7', 'stocks' );
		$this->assertSame( 1, $row->version() );
		$this->assertEquals( array( 'tokens' => 0.0 ), $row->state() );
		$this->assertTrue( $this->store->swap( '7', 'stocks', 1, array( 'tokens' => 0.5 ), null, '' ), 'a writer that re-read version 1 wins' );
	}

	/**
	 * An identical state still moves the version, so two writers that compute the same state cannot both win.
	 *
	 * @return void
	 */
	public function test_an_identical_state_still_moves_the_version(): void {
		$this->store->create( '7', 'default', array( 'tokens' => 5.0 ) );

		$this->assertTrue( $this->store->swap( '7', 'default', 0, array( 'tokens' => 5.0 ), null, '' ) );
		$this->assertFalse( $this->store->swap( '7', 'default', 0, array( 'tokens' => 5.0 ), null, '' ) );
	}

	/**
	 * The switch-off time is stored in UTC, read back as a Unix time and cleared with null.
	 *
	 * @return void
	 */
	public function test_the_switch_off_time_round_trips_and_clears(): void {
		$this->store->create( '7', 'm:abc', array() );
		$until = 1794182400; // 2026-11-09 00:00:00 UTC.

		$this->store->swap( '7', 'm:abc', 0, array(), $until, 'retired:/v2/x' );
		$row = $this->store->read( '7', 'm:abc' );
		$this->assertSame( $until, $row->disabled_until() );
		$this->assertSame( 'retired:/v2/x', $row->disabled_reason() );
		$this->assertSame( '2026-11-09 00:00:00', $this->db->rows( 'SELECT disabled_until FROM ' . Sqlite_Schema::THROTTLE )[0]['disabled_until'] );

		$this->store->swap( '7', 'm:abc', 1, array(), null, '' );
		$this->assertNull( $this->store->read( '7', 'm:abc' )->disabled_until() );
	}

	/**
	 * The swap compares the version in its `WHERE`, which is what makes it a compare-and-swap.
	 *
	 * @return void
	 */
	public function test_the_swap_statement_compares_the_version(): void {
		$this->store->create( '7', 'default', array() );
		$this->store->swap( '7', 'default', 0, array(), null, '' );

		$update = end( $this->db->statements );
		$this->assertStringContainsString( 'version = version + 1', $update );
		$this->assertMatchesRegularExpression( '/WHERE account_id = 7 AND bucket = \'default\' AND version = 0$/', $update );
	}

	/**
	 * Accounts and buckets are separate rows.
	 *
	 * @return void
	 */
	public function test_rows_are_keyed_by_account_and_bucket(): void {
		$this->store->create( '1', 'default', array( 'tokens' => 1.0 ) );
		$this->store->create( '2', 'default', array( 'tokens' => 2.0 ) );
		$this->store->create( '1', 'stocks', array( 'tokens' => 3.0 ) );

		$this->assertEquals( array( 'tokens' => 2.0 ), $this->store->read( '2', 'default' )->state() );
		$this->assertEquals( array( 'tokens' => 3.0 ), $this->store->read( '1', 'stocks' )->state() );
	}

	/**
	 * The table is keyed by a numeric account id; anything else is a programming error, not a row for account 0.
	 *
	 * @return void
	 */
	public function test_a_non_numeric_account_key_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store->read( 'abc', 'default' );
	}
}

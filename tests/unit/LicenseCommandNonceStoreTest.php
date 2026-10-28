<?php
/**
 * Atomic nonce-claim store tests (§9.1 / §9.2).
 *
 * The store is the anti-replay primitive for signed license commands. The claim is
 * atomic via a raw `INSERT IGNORE` (the UNIQUE option_name index makes every concurrent
 * loser's insert affect 0 rows → 'replayed'; add_option() is NOT used — its
 * ON DUPLICATE KEY UPDATE overwrites a racer's row, #1485). State machine:
 * processing → consumed; a stuck `processing` record older than STUCK_TAKEOVER_AFTER
 * is taken over by a conditional UPDATE (crash recovery, §9.1 — single winner). Retention
 * is capped at MAX_TTL so a huge signed expires_at cannot pin entries; the live-entry
 * cap (MAX_NONCE_ENTRIES) returns 'store_full' (the dispatcher maps it → rate_limited).
 *
 * Time flows through one overridable now() seam (a probe subclass) so the skew / stuck
 * / expiry assertions never sleep.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-nonce-store.php';

/**
 * A nonce store with an injectable clock for deterministic time-based assertions.
 */
class Clockable_Nonce_Store extends \Woodev_License_Command_Nonce_Store {

	/**
	 * The fixed "current" time the store reports.
	 *
	 * @var int
	 */
	public $fixed_now = 1_700_000_000;

	/**
	 * Returns the injected fixed time instead of the wall clock.
	 *
	 * @return int
	 */
	protected function now(): int {
		return $this->fixed_now;
	}
}

/**
 * An in-memory `wp_options` table behind the wpdb API the nonce store uses.
 *
 * Models the two atomic primitives: `INSERT IGNORE` (one row affected only when the name is new) and
 * the conditional `UPDATE … WHERE option_value = <raw>` (one row affected only when the raw value still
 * matches). The hooks run a «concurrent request» between the store's read and its write.
 */
class Options_Table_Wpdb {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	public $options = 'wp_options';

	/**
	 * Rows affected by the last write.
	 *
	 * @var int
	 */
	public $rows_affected = 0;

	/**
	 * Raw option values by option_name.
	 *
	 * @var array<string, string>
	 */
	public $table = array();

	/**
	 * Autoload flag written by INSERT IGNORE, by option_name.
	 *
	 * @var array<string, string>
	 */
	public $autoload = array();

	/**
	 * Runs just before an INSERT IGNORE is applied (a racer inserts first).
	 *
	 * @var callable|null
	 */
	public $before_insert;

	/**
	 * Runs just before a conditional UPDATE is applied (a racer rewrites the row first).
	 *
	 * @var callable|null
	 */
	public $before_update;

	/**
	 * Passthrough LIKE escape.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return $text;
	}

	/**
	 * Packs the query and its args so query() / get_var() can interpret them.
	 *
	 * @param string $query   Query.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return serialize( array( $query, $args ) );
	}

	/**
	 * The prune / count scan: every row (the tests only seed nonce rows).
	 *
	 * @return array<int, object>
	 */
	public function get_results() {
		$rows = array();
		foreach ( $this->table as $name => $value ) {
			$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
		}

		return $rows;
	}

	/**
	 * The raw stored value.
	 *
	 * @param string $sql Packed query.
	 * @return string|null
	 */
	public function get_var( $sql ) {
		list( , $args ) = unserialize( $sql );

		return $this->table[ $args[0] ] ?? null;
	}

	/**
	 * INSERT IGNORE or conditional UPDATE, with MySQL's rows-affected semantics.
	 *
	 * @param string $sql Packed query.
	 * @return int Rows affected.
	 */
	public function query( $sql ) {
		list( $query, $args ) = unserialize( $sql );

		if ( 0 === strpos( $query, 'INSERT IGNORE' ) ) {
			if ( $this->before_insert ) {
				( $this->before_insert )( $this );
			}

			if ( isset( $this->table[ $args[0] ] ) ) {
				return $this->rows_affected = 0;
			}

			$this->table[ $args[0] ]    = $args[1];
			$this->autoload[ $args[0] ] = $args[2];

			return $this->rows_affected = 1;
		}

		if ( $this->before_update ) {
			( $this->before_update )( $this );
		}

		if ( ( $this->table[ $args[1] ] ?? null ) !== $args[2] ) {
			return $this->rows_affected = 0;
		}

		$this->table[ $args[1] ] = $args[0];

		return $this->rows_affected = 1;
	}
}

/**
 * Class LicenseCommandNonceStoreTest.
 */
class LicenseCommandNonceStoreTest extends TestCase {

	/**
	 * Isolates $GLOBALS['wpdb'] from cross-file pollution (a sibling test leaves a
	 * wpdb double without esc_like()/get_results()). Tests that need a real store set
	 * their own; the rest get a benign empty one (gotcha
	 * testing/brain-monkey-function-pollution applied to the wpdb global).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$wpdb          = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$GLOBALS['wpdb'] = $wpdb;

		// The raw claim writes serialise like add_option() does and read back with maybe_unserialize().
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ) => is_scalar( $value ) ? $value : serialize( $value ) );
		Functions\when( 'maybe_unserialize' )->alias( static fn( $value ) => is_string( $value ) && false !== @unserialize( $value ) ? unserialize( $value ) : $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	/**
	 * Arms an in-memory options table as the wpdb global; get_option() reads the same table.
	 *
	 * @return Options_Table_Wpdb
	 */
	private function arm_options_table(): Options_Table_Wpdb {
		$wpdb            = new Options_Table_Wpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( $wpdb ) {
				return isset( $wpdb->table[ $name ] ) ? unserialize( $wpdb->table[ $name ] ) : false;
			}
		);

		return $wpdb;
	}

	/**
	 * Clears the wpdb global so it never leaks into a later test file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A canonical 32-lowercase-hex nonce.
	 *
	 * @var string
	 */
	private const NONCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * The fixed clock value used across the time-sensitive tests.
	 *
	 * @var int
	 */
	private const NOW = 1_700_000_000;

	/**
	 * Builds the option name the store derives for a nonce.
	 *
	 * @param string $nonce The nonce.
	 * @return string
	 */
	private function option_name_for( string $nonce ): string {
		return \Woodev_License_Command_Nonce_Store::OPTION_PREFIX . substr( hash( 'sha256', $nonce ), 0, 32 );
	}

	/**
	 * Constants are pinned to the frozen §9.1/§9.2 values.
	 *
	 * @return void
	 */
	public function test_constants_are_frozen(): void {
		$this->assertSame( 14 * DAY_IN_SECONDS, \Woodev_License_Command_Nonce_Store::MAX_TTL );
		$this->assertSame( 300, \Woodev_License_Command_Nonce_Store::STUCK_TAKEOVER_AFTER );
		$this->assertSame( 100, \Woodev_License_Command_Nonce_Store::MAX_NONCE_ENTRIES );
		$this->assertSame( 'woodev_license_command_nonces_', \Woodev_License_Command_Nonce_Store::OPTION_PREFIX );
	}

	/**
	 * The option name is the prefix + first-32-hex of sha256(nonce).
	 *
	 * @return void
	 */
	public function test_option_name_derivation(): void {
		$store    = new Clockable_Nonce_Store();
		$expected = $this->option_name_for( self::NONCE );

		$method = new \ReflectionMethod( $store, 'option_name' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( $expected, $method->invoke( $store, self::NONCE ) );
		$this->assertSame( 32, strlen( substr( $expected, strlen( \Woodev_License_Command_Nonce_Store::OPTION_PREFIX ) ) ) );
	}

	/**
	 * claim() on a fresh nonce writes the processing record via INSERT IGNORE (autoload
	 * 'no'), caps retention at MAX_TTL, clears the option cache entry and returns
	 * 'claimed' — and never touches add_option() (ON DUPLICATE KEY UPDATE, #1485).
	 *
	 * @return void
	 */
	public function test_claim_fresh_nonce_writes_processing_record(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;
		$wpdb             = $this->arm_options_table();

		// Below cap a claim performs NO maintenance writes (lazy prune, §9.2 amended).
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();

		$cleared = array();
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key, $group ) use ( &$cleared ) {
				$cleared[] = array( $key, $group );
				return true;
			}
		);

		// A small expires_at (well under MAX_TTL) is kept verbatim.
		$result = $store->claim( self::NONCE, self::NOW + 100 );
		$name   = $this->option_name_for( self::NONCE );

		$this->assertSame( 'claimed', $result );
		$this->assertSame( array( $name ), array_keys( $wpdb->table ) );
		$this->assertSame( 'no', $wpdb->autoload[ $name ] );
		$this->assertSame(
			array( 's' => 'processing', 'c' => self::NOW, 'e' => self::NOW + 100 ),
			unserialize( $wpdb->table[ $name ] )
		);
		$this->assertContains( array( $name, 'options' ), $cleared, 'The option cache entry is dropped after the raw insert.' );
		$this->assertContains( array( 'notoptions', 'options' ), $cleared );
	}

	/**
	 * claim() caps the retention expiry at now + MAX_TTL when the signed expires_at is
	 * larger (a huge expires_at cannot pin an entry — §9.2).
	 *
	 * @return void
	 */
	public function test_claim_caps_retention_at_max_ttl(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;
		$wpdb             = $this->arm_options_table();

		Functions\expect( 'delete_option' )->never();

		$store->claim( self::NONCE, self::NOW + ( 100 * YEAR_IN_SECONDS ) );

		$record = unserialize( $wpdb->table[ $this->option_name_for( self::NONCE ) ] );

		$this->assertSame( self::NOW + \Woodev_License_Command_Nonce_Store::MAX_TTL, $record['e'] );
	}

	/**
	 * The same key claimed twice: 'claimed' once, then 'replayed' (the first claim's
	 * processing record is in flight).
	 *
	 * @return void
	 */
	public function test_second_claim_of_the_same_key_is_replayed(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;
		$wpdb             = $this->arm_options_table();

		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();

		$this->assertSame( 'claimed', $store->claim( self::NONCE, self::NOW + 100 ) );
		$this->assertSame( 'replayed', $store->claim( self::NONCE, self::NOW + 100 ) );
		$this->assertCount( 1, $wpdb->table );
	}

	/**
	 * The concurrent loser: this request read «no record», but another process inserted the
	 * row before our INSERT IGNORE ran → 0 rows affected → 'replayed', and the winner's row is
	 * left untouched (add_option()'s ON DUPLICATE KEY UPDATE would have overwritten it, #1485).
	 *
	 * @return void
	 */
	public function test_claim_loser_when_row_inserted_between_read_and_insert_is_replayed(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;
		$wpdb             = $this->arm_options_table();
		$name             = $this->option_name_for( self::NONCE );
		$winner           = serialize( array( 's' => 'processing', 'c' => self::NOW - 1, 'e' => self::NOW + 50 ) );

		$wpdb->before_insert = static function ( Options_Table_Wpdb $db ) use ( $name, $winner ): void {
			$db->table[ $name ] = $winner;
		};

		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		$this->assertSame( 'replayed', $store->claim( self::NONCE, self::NOW + 100 ) );
		$this->assertSame( $winner, $wpdb->table[ $name ], 'The winner\'s row is not overwritten by the loser.' );
	}

	/**
	 * A consumed record always rejects with 'replayed', regardless of status (§3.1).
	 *
	 * @return void
	 */
	public function test_claim_on_consumed_record_is_replayed(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn(
			array( 's' => 'consumed', 'r' => 'executed', 'c' => self::NOW, 'e' => self::NOW + 100 )
		);
		// No write may happen on a consumed record.
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		$this->assertSame( 'replayed', $store->claim( self::NONCE, self::NOW + 100 ) );
	}

	/**
	 * A processing record younger than STUCK_TAKEOVER_AFTER is in-flight → 'replayed',
	 * no takeover write.
	 *
	 * @return void
	 */
	public function test_claim_on_young_processing_record_is_replayed(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn(
			array( 's' => 'processing', 'c' => self::NOW - 10, 'e' => self::NOW + 100 )
		);
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		$this->assertSame( 'replayed', $store->claim( self::NONCE, self::NOW + 100 ) );
	}

	/**
	 * A processing record at exactly STUCK_TAKEOVER_AFTER seconds old is still
	 * in-flight (boundary is inclusive of "young") → 'replayed'.
	 *
	 * @return void
	 */
	public function test_claim_on_processing_record_at_boundary_is_replayed(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn(
			array( 's' => 'processing', 'c' => self::NOW - \Woodev_License_Command_Nonce_Store::STUCK_TAKEOVER_AFTER, 'e' => self::NOW + 100 )
		);
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		$this->assertSame( 'replayed', $store->claim( self::NONCE, self::NOW + 100 ) );
	}

	/**
	 * A processing record older than STUCK_TAKEOVER_AFTER is taken over by a conditional
	 * UPDATE: c refreshed, returns 'claimed' (§9.9 crash-between-claim-and-action).
	 *
	 * @return void
	 */
	public function test_claim_takes_over_stale_processing_record(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;
		$wpdb             = $this->arm_options_table();
		$name             = $this->option_name_for( self::NONCE );

		$wpdb->table[ $name ] = serialize( array( 's' => 'processing', 'c' => self::NOW - 301, 'e' => self::NOW + 100 ) );

		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();

		$this->assertSame( 'claimed', $store->claim( self::NONCE, self::NOW + 100 ) );

		$record = unserialize( $wpdb->table[ $name ] );

		$this->assertSame( 'processing', $record['s'] );
		$this->assertSame( self::NOW, $record['c'], 'Takeover refreshes the claimed-at timestamp.' );
		$this->assertSame( self::NOW + 100, $record['e'] );
	}

	/**
	 * Two contenders read the same stale record (a persistent object cache can serve it to both):
	 * exactly one wins the takeover; the other re-reads the rewritten row and is 'replayed'.
	 *
	 * @return void
	 */
	public function test_stale_takeover_is_won_by_exactly_one_of_two_contenders(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;
		$wpdb             = $this->arm_options_table();
		$name             = $this->option_name_for( self::NONCE );
		$stale            = array( 's' => 'processing', 'c' => self::NOW - 301, 'e' => self::NOW + 100 );

		$wpdb->table[ $name ] = serialize( $stale );

		// Both contenders' get_option() read is the cached stale copy.
		Functions\when( 'get_option' )->justReturn( $stale );
		Functions\expect( 'update_option' )->never();

		$results = array(
			$store->claim( self::NONCE, self::NOW + 100 ),
			$store->claim( self::NONCE, self::NOW + 100 ),
		);

		$this->assertSame( array( 'claimed', 'replayed' ), $results );
	}

	/**
	 * The compare-and-set itself: a racer rewrites the stale row AFTER this request read it but
	 * BEFORE its UPDATE → the `WHERE option_value = <raw read>` matches nothing → 'replayed'.
	 *
	 * @return void
	 */
	public function test_takeover_loses_when_the_row_is_rewritten_before_the_update(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;
		$wpdb             = $this->arm_options_table();
		$name             = $this->option_name_for( self::NONCE );
		$racer            = serialize( array( 's' => 'processing', 'c' => self::NOW, 'e' => self::NOW + 100 ) );

		$wpdb->table[ $name ] = serialize( array( 's' => 'processing', 'c' => self::NOW - 301, 'e' => self::NOW + 100 ) );
		$wpdb->before_update  = static function ( Options_Table_Wpdb $db ) use ( $name, $racer ): void {
			$db->table[ $name ] = $racer;
		};

		Functions\expect( 'update_option' )->never();

		$this->assertSame( 'replayed', $store->claim( self::NONCE, self::NOW + 100 ) );
		$this->assertSame( $racer, $wpdb->table[ $name ], 'The racer\'s row is not overwritten by the loser.' );
	}

	/**
	 * mark_consumed() rewrites the record to consumed with the terminal status, and
	 * PRESERVES the existing row's retention expiry `e` — consumption never extends
	 * (or shortens) the retention set at claim time (§9.2 amended).
	 *
	 * @return void
	 */
	public function test_mark_consumed_updates_status_and_preserves_retention(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn(
			array( 's' => 'processing', 'c' => self::NOW, 'e' => self::NOW + 100 )
		);

		$captured = null;
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = array( $name, $value );
				return true;
			}
		);

		// A signed expires_at far past the stored e MUST NOT extend it.
		$store->mark_consumed( self::NONCE, 'executed', self::NOW + 999999 );

		$this->assertSame( $this->option_name_for( self::NONCE ), $captured[0] );
		$this->assertSame( 'consumed', $captured[1]['s'] );
		$this->assertSame( 'executed', $captured[1]['r'] );
		$this->assertSame( self::NOW + 100, $captured[1]['e'], 'The existing retention expiry is preserved, never clobbered.' );
	}

	/**
	 * mark_consumed() on a row pruned mid-action re-creates it with the claim()
	 * retention rule: e = min( signed expires_at, now + MAX_TTL ) — the small
	 * signed expiry wins.
	 *
	 * @return void
	 */
	public function test_mark_consumed_recreates_pruned_row_capped_by_signed_expiry(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn( false ); // row pruned mid-action.

		$captured = null;
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$store->mark_consumed( self::NONCE, 'executed', self::NOW + 50 );

		$this->assertSame( 'consumed', $captured['s'] );
		$this->assertSame( self::NOW + 50, $captured['e'], 'Retention never exceeds the original signed expiry.' );
	}

	/**
	 * mark_consumed() re-creation also honors the MAX_TTL cap when the signed expiry
	 * is huge (same rule as claim()).
	 *
	 * @return void
	 */
	public function test_mark_consumed_recreated_row_capped_by_max_ttl(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn( false );

		$captured = null;
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$store->mark_consumed( self::NONCE, 'executed', self::NOW + ( 100 * YEAR_IN_SECONDS ) );

		$this->assertSame( self::NOW + \Woodev_License_Command_Nonce_Store::MAX_TTL, $captured['e'] );
	}

	/**
	 * prune() deletes only rows whose decoded retention expiry is in the past, using a
	 * prepared LIKE query (wpdb spy).
	 *
	 * @return void
	 */
	public function test_prune_deletes_only_expired_rows(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		$prefix  = \Woodev_License_Command_Nonce_Store::OPTION_PREFIX;
		$expired = $prefix . str_repeat( 'a', 32 );
		$live    = $prefix . str_repeat( 'b', 32 );

		$wpdb = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $query, ...$args ) {
				return $query . '|' . implode( ',', $args );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				(object) array( 'option_name' => $expired, 'option_value' => serialize( array( 's' => 'consumed', 'e' => self::NOW - 1 ) ) ),
				(object) array( 'option_name' => $live, 'option_value' => serialize( array( 's' => 'processing', 'e' => self::NOW + 100 ) ) ),
			)
		);

		$GLOBALS['wpdb'] = $wpdb;

		$deleted = array();
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? unserialize( $value ) : $value;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		$store->prune();

		unset( $GLOBALS['wpdb'] );

		$this->assertSame( array( $expired ), $deleted, 'Only the expired row is deleted; the live row is kept.' );
	}

	/**
	 * At MAX_NONCE_ENTRIES live entries, a fresh claim prunes (the recorded §9.2
	 * exception: EXPIRED rows may be deleted on this path), recounts, and returns
	 * 'store_full' — with NO live row created or mutated (no add/update_option).
	 *
	 * @return void
	 */
	public function test_claim_at_capacity_prunes_expired_then_returns_store_full(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		// The fresh nonce itself has no record...
		Functions\when( 'get_option' )->justReturn( false );

		$prefix = \Woodev_License_Command_Nonce_Store::OPTION_PREFIX;
		$rows   = array();
		for ( $i = 0; $i < \Woodev_License_Command_Nonce_Store::MAX_NONCE_ENTRIES; $i++ ) {
			$rows[] = (object) array(
				'option_name'  => $prefix . str_pad( (string) $i, 32, '0', STR_PAD_LEFT ),
				'option_value' => serialize( array( 's' => 'processing', 'e' => self::NOW + 1000 ) ),
			);
		}

		// One expired row rides along: the at-cap prune deletes it (and ONLY it).
		$expired_name = $prefix . str_repeat( 'e', 32 );
		$rows[]       = (object) array(
			'option_name'  => $expired_name,
			'option_value' => serialize( array( 's' => 'consumed', 'e' => self::NOW - 1 ) ),
		);

		$wpdb = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? unserialize( $value ) : $value;
			}
		);

		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);
		// NO live row is created or mutated at capacity.
		Functions\expect( 'add_option' )->never();
		Functions\expect( 'update_option' )->never();

		$result = $store->claim( self::NONCE, self::NOW + 100 );

		unset( $GLOBALS['wpdb'] );

		$this->assertSame( 'store_full', $result );
		$this->assertSame( array( $expired_name ), $deleted, 'The at-cap prune deleted ONLY the expired row.' );
	}

	/**
	 * LAZY prune (§9.2 amended): BELOW cap, a fresh claim performs NO maintenance
	 * writes — even when expired rows exist, delete_option is never called and
	 * the INSERT IGNORE claim is the first (and only) write.
	 *
	 * @return void
	 */
	public function test_claim_below_cap_never_prunes(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		$wpdb   = $this->arm_options_table();
		$prefix = \Woodev_License_Command_Nonce_Store::OPTION_PREFIX;

		$wpdb->table = array(
			$prefix . str_repeat( '1', 32 ) => serialize( array( 's' => 'processing', 'e' => self::NOW + 1000 ) ), // live
			$prefix . str_repeat( '2', 32 ) => serialize( array( 's' => 'consumed', 'e' => self::NOW - 1 ) ), // expired
		);

		// The maintenance-write ban below cap: the stale row is left alone.
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->never();

		$result = $store->claim( self::NONCE, self::NOW + 100 );

		$this->assertSame( 'claimed', $result );
		$this->assertArrayHasKey( $prefix . str_repeat( '2', 32 ), $wpdb->table, 'The expired row survives a below-cap claim.' );
	}
}

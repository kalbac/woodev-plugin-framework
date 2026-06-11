<?php
/**
 * Atomic nonce-claim store tests (§9.1 / §9.2).
 *
 * The store is the anti-replay primitive for signed license commands. The claim is
 * atomic via add_option() (the UNIQUE option_name index makes the concurrent loser's
 * INSERT fail → add_option() returns false → 'replayed'). State machine:
 * processing → consumed; a stuck `processing` record older than STUCK_TAKEOVER_AFTER
 * is taken over (crash recovery, §9.1 — the only v1 command is idempotent). Retention
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
	 * claim() on a fresh nonce writes the processing record via add_option (autoload
	 * 'no'), caps retention at MAX_TTL, and returns 'claimed'.
	 *
	 * @return void
	 */
	public function test_claim_fresh_nonce_writes_processing_record(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		$captured = null;
		Functions\when( 'get_option' )->justReturn( false );
		// Below cap a claim performs NO maintenance writes (lazy prune, §9.2 amended).
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'add_option' )->once()->andReturnUsing(
			static function ( $name, $value, $deprecated, $autoload ) use ( &$captured ) {
				$captured = array( $name, $value, $deprecated, $autoload );
				return true;
			}
		);

		// A small expires_at (well under MAX_TTL) is kept verbatim.
		$result = $store->claim( self::NONCE, self::NOW + 100 );

		$this->assertSame( 'claimed', $result );
		$this->assertSame( $this->option_name_for( self::NONCE ), $captured[0] );
		$this->assertSame( 'no', $captured[3] );
		$this->assertSame( 'processing', $captured[1]['s'] );
		$this->assertSame( self::NOW, $captured[1]['c'] );
		$this->assertSame( self::NOW + 100, $captured[1]['e'] );
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

		$captured = null;
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'delete_option' )->never();
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$store->claim( self::NONCE, self::NOW + ( 100 * YEAR_IN_SECONDS ) );

		$this->assertSame( self::NOW + \Woodev_License_Command_Nonce_Store::MAX_TTL, $captured['e'] );
	}

	/**
	 * The concurrent loser: add_option() returns false (UNIQUE index conflict) and
	 * there is no readable record yet → 'replayed' (§9.9 concurrency double-execution).
	 *
	 * @return void
	 */
	public function test_claim_loser_when_add_option_false_is_replayed(): void {
		$store = new Clockable_Nonce_Store();

		// First read sees nothing; the winner already inserted between our read and write,
		// so add_option fails; the re-read then sees the winner's processing record.
		Functions\when( 'get_option' )->alias(
			static function () {
				static $calls = 0;
				$calls++;
				if ( 1 === $calls ) {
					return false;
				}
				return array( 's' => 'processing', 'c' => self::NOW, 'e' => self::NOW + 100 );
			}
		);
		Functions\when( 'add_option' )->justReturn( false );
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'update_option' )->never();

		$this->assertSame( 'replayed', $store->claim( self::NONCE, self::NOW + 100 ) );
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
	 * A processing record older than STUCK_TAKEOVER_AFTER is taken over: c refreshed
	 * via update_option, returns 'claimed' (§9.9 crash-between-claim-and-action).
	 *
	 * @return void
	 */
	public function test_claim_takes_over_stale_processing_record(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn(
			array( 's' => 'processing', 'c' => self::NOW - 301, 'e' => self::NOW + 100 )
		);
		Functions\expect( 'add_option' )->never();

		$captured = null;
		Functions\expect( 'update_option' )->once()->andReturnUsing(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = array( $name, $value );
				return true;
			}
		);

		$this->assertSame( 'claimed', $store->claim( self::NONCE, self::NOW + 100 ) );
		$this->assertSame( $this->option_name_for( self::NONCE ), $captured[0] );
		$this->assertSame( 'processing', $captured[1]['s'] );
		$this->assertSame( self::NOW, $captured[1]['c'], 'Takeover refreshes the claimed-at timestamp.' );
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
	 * add_option is the first (and only) write.
	 *
	 * @return void
	 */
	public function test_claim_below_cap_never_prunes(): void {
		$store            = new Clockable_Nonce_Store();
		$store->fixed_now = self::NOW;

		Functions\when( 'get_option' )->justReturn( false );

		$prefix = \Woodev_License_Command_Nonce_Store::OPTION_PREFIX;
		$rows   = array(
			(object) array(
				'option_name'  => $prefix . str_repeat( '1', 32 ),
				'option_value' => serialize( array( 's' => 'processing', 'e' => self::NOW + 1000 ) ), // live
			),
			(object) array(
				'option_name'  => $prefix . str_repeat( '2', 32 ),
				'option_value' => serialize( array( 's' => 'consumed', 'e' => self::NOW - 1 ) ), // expired
			),
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

		// The maintenance-write ban below cap: the stale row is left alone.
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_option' )->once()->andReturn( true );

		$result = $store->claim( self::NONCE, self::NOW + 100 );

		unset( $GLOBALS['wpdb'] );

		$this->assertSame( 'claimed', $result );
	}
}

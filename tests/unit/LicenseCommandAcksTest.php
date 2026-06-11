<?php
/**
 * Tests for Woodev_License_Command_Acks (§9.5/§9.6/§9.7 client-side ack store).
 *
 * Covers:
 * - §9.6 schema: every record entry has {nonce, status, terminal, protocol:1, ts};
 *   terminal is false only when status === 'failed'.
 * - FIFO cap at MAX_PENDING_ACKS = 50 (oldest dropped when exceeded).
 * - 30-day retention: expired entries dropped on every write and get_pending().
 * - get_pending() returns entries for attaching to outgoing requests.
 * - confirm_received() deletes EXACTLY the named entries; unconfirmed entries survive
 *   (§9.9 lost-ack redelivery).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-acks.php';

/**
 * Class LicenseCommandAcksTest.
 */
class LicenseCommandAcksTest extends TestCase {

	/**
	 * Fixed "now" for deterministic retention / ts tests.
	 *
	 * @var int
	 */
	private const NOW = 1_700_000_000;

	/**
	 * Option name used by the store.
	 *
	 * @var string
	 */
	private const OPTION = 'woodev_license_command_acks';

	/**
	 * Sets up WP function stubs and a fixed-time store for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Builds a store instance whose clock returns the given timestamp.
	 *
	 * @param int $now The fixed "now" timestamp.
	 * @return \Woodev_License_Command_Acks
	 */
	private function make_store( int $now = self::NOW ): \Woodev_License_Command_Acks {
		return new \Woodev_License_Command_Acks(
			static function () use ( $now ): int {
				return $now;
			}
		);
	}

	/**
	 * Builds a nonce-shaped string (32 hex chars).
	 *
	 * @param string $seed A single char to repeat.
	 * @return string
	 */
	private function nonce( string $seed = 'a' ): string {
		return str_repeat( $seed, 32 );
	}

	// -----------------------------------------------------------------------
	// §9.6 Schema shape
	// -----------------------------------------------------------------------

	/**
	 * record() appends the full §9.6 schema entry with all required keys.
	 *
	 * @return void
	 */
	public function test_record_appends_schema_entry(): void {
		$stored = null;
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$store = $this->make_store( self::NOW );
		$store->record( $this->nonce( 'a' ), 'executed' );

		$this->assertIsArray( $stored );
		$this->assertCount( 1, $stored );

		$entry = $stored[0];
		$this->assertArrayHasKey( 'nonce', $entry );
		$this->assertArrayHasKey( 'status', $entry );
		$this->assertArrayHasKey( 'terminal', $entry );
		$this->assertArrayHasKey( 'protocol', $entry );
		$this->assertArrayHasKey( 'ts', $entry );

		$this->assertSame( $this->nonce( 'a' ), $entry['nonce'] );
		$this->assertSame( 'executed', $entry['status'] );
		$this->assertTrue( $entry['terminal'] );
		$this->assertSame( 1, $entry['protocol'] );
		$this->assertSame( self::NOW, $entry['ts'] );
	}

	/**
	 * terminal flag is false only when status === 'failed'.
	 *
	 * @dataProvider terminal_flag_provider
	 *
	 * @param string $status   The ack status.
	 * @param bool   $expected Expected terminal flag value.
	 * @return void
	 */
	public function test_terminal_flag_per_status( string $status, bool $expected ): void {
		$stored = null;
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$store = $this->make_store( self::NOW );
		$store->record( $this->nonce( 'b' ), $status );

		$this->assertIsArray( $stored );
		$this->assertCount( 1, $stored );
		$this->assertSame( $expected, $stored[0]['terminal'] );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function terminal_flag_provider(): array {
		return array(
			'executed is terminal'                       => array( 'executed', true ),
			'already is terminal'                        => array( 'already', true ),
			'unsupported_command is terminal'            => array( 'unsupported_command', true ),
			'network_active_unsupported is terminal'     => array( 'network_active_unsupported', true ),
			'failed is NOT terminal (retryable)'         => array( 'failed', false ),
		);
	}

	// -----------------------------------------------------------------------
	// FIFO cap (MAX_PENDING_ACKS = 50)
	// -----------------------------------------------------------------------

	/**
	 * When the store exceeds MAX_PENDING_ACKS, the OLDEST entry is dropped (FIFO).
	 *
	 * @return void
	 */
	public function test_fifo_cap_drops_oldest(): void {
		$stored = null;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored ) {
				return $stored ?? false;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$store = $this->make_store( self::NOW );
		$cap   = \Woodev_License_Command_Acks::MAX_PENDING_ACKS;

		// Fill exactly up to the cap.
		for ( $i = 0; $i < $cap; $i++ ) {
			$store->record( str_pad( dechex( $i ), 32, '0', STR_PAD_LEFT ), 'executed' );
		}

		// All cap entries survive.
		$this->assertCount( $cap, (array) $stored );

		$first_nonce = str_pad( '0', 32, '0', STR_PAD_LEFT );
		$this->assertSame( $first_nonce, $stored[0]['nonce'], 'Oldest entry is at index 0 before overflow.' );

		// Add one more → the oldest (index 0) must be dropped.
		$new_nonce = str_pad( dechex( $cap ), 32, '0', STR_PAD_LEFT );
		$store->record( $new_nonce, 'executed' );

		$this->assertCount( $cap, (array) $stored, 'Count stays at cap after overflow.' );
		$this->assertSame( $new_nonce, end( $stored )['nonce'], 'Newest is appended at the end.' );

		// The oldest entry must have been removed.
		$nonces = array_column( $stored, 'nonce' );
		$this->assertNotContains( $first_nonce, $nonces, 'The oldest entry is dropped on overflow.' );
	}

	// -----------------------------------------------------------------------
	// 30-day retention
	// -----------------------------------------------------------------------

	/**
	 * Entries older than 30 * DAY_IN_SECONDS are dropped on record() (every write).
	 *
	 * @return void
	 */
	public function test_retention_drops_expired_on_write(): void {
		$retention = \Woodev_License_Command_Acks::RETENTION_SECONDS;

		// An entry recorded exactly at the retention boundary: ts = NOW - retention.
		$old_entry = array(
			'nonce'    => $this->nonce( 'z' ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => self::NOW - $retention,
		);

		$stored = null;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored, $old_entry ) {
				return null !== $stored ? $stored : array( $old_entry );
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$store = $this->make_store( self::NOW );
		$store->record( $this->nonce( 'a' ), 'executed' );

		$nonces = array_column( (array) $stored, 'nonce' );
		$this->assertNotContains( $this->nonce( 'z' ), $nonces, 'Expired entry (exactly at boundary) is dropped on write.' );
		$this->assertContains( $this->nonce( 'a' ), $nonces, 'New entry is present.' );
	}

	/**
	 * Entries older than 30 * DAY_IN_SECONDS are dropped on get_pending() (every drain pass).
	 *
	 * @return void
	 */
	public function test_retention_drops_expired_on_get_pending(): void {
		$retention = \Woodev_License_Command_Acks::RETENTION_SECONDS;

		$expired_entry = array(
			'nonce'    => $this->nonce( 'e' ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => self::NOW - $retention,
		);
		$fresh_entry   = array(
			'nonce'    => $this->nonce( 'f' ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => self::NOW - 100,
		);

		Functions\when( 'get_option' )->justReturn( array( $expired_entry, $fresh_entry ) );
		Functions\when( 'update_option' )->justReturn( true );

		$store   = $this->make_store( self::NOW );
		$pending = $store->get_pending();

		$nonces = array_column( $pending, 'nonce' );
		$this->assertNotContains( $this->nonce( 'e' ), $nonces, 'Expired entry dropped from get_pending().' );
		$this->assertContains( $this->nonce( 'f' ), $nonces, 'Fresh entry returned by get_pending().' );
	}

	// -----------------------------------------------------------------------
	// get_pending()
	// -----------------------------------------------------------------------

	/**
	 * get_pending() returns the current non-expired entries.
	 *
	 * @return void
	 */
	public function test_get_pending_returns_current_entries(): void {
		$entry = array(
			'nonce'    => $this->nonce( 'a' ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => self::NOW,
		);

		Functions\when( 'get_option' )->justReturn( array( $entry ) );
		Functions\when( 'update_option' )->justReturn( true );

		$store   = $this->make_store( self::NOW );
		$pending = $store->get_pending();

		$this->assertCount( 1, $pending );
		$this->assertSame( $this->nonce( 'a' ), $pending[0]['nonce'] );
	}

	/**
	 * get_pending() returns an empty array when the store is empty.
	 *
	 * @return void
	 */
	public function test_get_pending_empty_store(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$store   = $this->make_store( self::NOW );
		$pending = $store->get_pending();

		$this->assertSame( array(), $pending );
	}

	// -----------------------------------------------------------------------
	// confirm_received() — §9.9 lost-ack + redelivery
	// -----------------------------------------------------------------------

	/**
	 * confirm_received() removes EXACTLY the named nonces; unconfirmed entries survive.
	 *
	 * §9.9 lost-ack + redelivery: the server's acks_received is an incomplete list —
	 * only those nonces are deleted; the rest remain for the next request.
	 *
	 * @return void
	 */
	public function test_confirm_received_removes_only_named_nonces(): void {
		$nonce_a = $this->nonce( 'a' );
		$nonce_b = $this->nonce( 'b' );
		$nonce_c = $this->nonce( 'c' );

		$initial = array(
			array( 'nonce' => $nonce_a, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => self::NOW ),
			array( 'nonce' => $nonce_b, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => self::NOW ),
			array( 'nonce' => $nonce_c, 'status' => 'executed', 'terminal' => true, 'protocol' => 1, 'ts' => self::NOW ),
		);

		$stored = $initial;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$store = $this->make_store( self::NOW );
		// Confirm only nonce_a and nonce_c; nonce_b is NOT confirmed (lost ack).
		$store->confirm_received( array( $nonce_a, $nonce_c ) );

		$remaining_nonces = array_column( $stored, 'nonce' );
		$this->assertNotContains( $nonce_a, $remaining_nonces, 'Confirmed nonce_a is removed.' );
		$this->assertNotContains( $nonce_c, $remaining_nonces, 'Confirmed nonce_c is removed.' );
		$this->assertContains( $nonce_b, $remaining_nonces, 'Unconfirmed nonce_b SURVIVES for next request.' );
		$this->assertCount( 1, $stored, 'Exactly one entry remains.' );
	}

	/**
	 * confirm_received() with an empty list is a no-op (all entries survive).
	 *
	 * @return void
	 */
	public function test_confirm_received_empty_list_no_op(): void {
		$entry = array(
			'nonce'    => $this->nonce( 'a' ),
			'status'   => 'executed',
			'terminal' => true,
			'protocol' => 1,
			'ts'       => self::NOW,
		);

		$stored = array( $entry );
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = $value;
				return true;
			}
		);

		$store = $this->make_store( self::NOW );
		$store->confirm_received( array() );

		$this->assertCount( 1, $stored, 'All entries survive an empty confirm.' );
	}

	// -----------------------------------------------------------------------
	// Option name + autoload
	// -----------------------------------------------------------------------

	/**
	 * update_option() is called with the correct option name and autoload 'no'.
	 *
	 * @return void
	 */
	public function test_option_name_and_autoload(): void {
		$calls = array();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing(
				static function ( $name, $value, $autoload ) use ( &$calls ) {
					$calls[] = array( $name, $autoload );
					return true;
				}
			);

		$store = $this->make_store( self::NOW );
		$store->record( $this->nonce( 'a' ), 'executed' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'woodev_license_command_acks', $calls[0][0] );
		$this->assertSame( 'no', $calls[0][1] );
	}
}

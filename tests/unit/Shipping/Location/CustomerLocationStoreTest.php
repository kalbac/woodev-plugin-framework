<?php
/**
 * Tests for Customer_Location_Store — the dual customer-location store (spec D10,
 * Task 4): guest -> WC()->session under one framework key; logged-in -> user meta
 * (authoritative) + session (fast path); the `implicit` flag precedence (spec D11);
 * wp_login migration; the empty-key discipline; and every degradation path (no
 * WooCommerce, no session, corrupt stored blob).
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';

	/**
	 * Minimal `\WC_Session` stand-in — array-backed get()/set(), plus a raw()
	 * escape hatch to inspect the stored shape directly (same shape as
	 * `Pickup_Selection_Fake_Session` in `PickupSelectionTest.php`).
	 */
	final class Customer_Location_Store_Fake_Session {

		/** @var array<string, mixed> */
		private array $store = [];

		/**
		 * @param string $key     Session key.
		 * @param mixed  $default Fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   Session key.
		 * @param mixed  $value Value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}

		/**
		 * @param string $key Session key.
		 *
		 * @return void
		 */
		public function clear( string $key ): void {
			unset( $this->store[ $key ] );
		}

		/**
		 * @param string $key Session key.
		 *
		 * @return mixed
		 */
		public function raw( string $key ) {
			return $this->store[ $key ] ?? null;
		}
	}

	/**
	 * A session that also answers `has_session()`, the way the real
	 * `WC_Session_Handler` does — i.e. whether anything written to it will actually
	 * survive the request (issue #324 review finding H1).
	 *
	 * The plain fake above deliberately does NOT declare the method: a custom handler
	 * installed through `woocommerce_session_handler` need not either, and the store
	 * must not report a failure it cannot actually observe.
	 */
	final class Customer_Location_Store_Fake_Durable_Session {

		/** @var array<string, mixed> */
		private array $store = [];

		/** @var bool */
		private bool $durable;

		public function __construct( bool $durable ) {
			$this->durable = $durable;
		}

		/**
		 * @param string $key     Session key.
		 * @param mixed  $default Fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   Session key.
		 * @param mixed  $value Value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}

		/**
		 * Mirrors `WC_Session_Handler::has_session()` — for a guest this is the cart
		 * cookie's presence, and `save_data()` writes nothing without it.
		 *
		 * @return bool
		 */
		public function has_session(): bool {
			return $this->durable;
		}

		/**
		 * @param string $key Session key.
		 *
		 * @return mixed
		 */
		public function raw( string $key ) {
			return $this->store[ $key ] ?? null;
		}
	}

	/**
	 * {@see Customer_Location_Store_Probe}'s counterpart for the durable-session fake.
	 */
	final class Customer_Location_Store_Durable_Probe extends Customer_Location_Store {

		/** @var Customer_Location_Store_Fake_Durable_Session */
		private Customer_Location_Store_Fake_Durable_Session $fake_session;

		public function __construct( Customer_Location_Store_Fake_Durable_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * Probe substituting a {@see Customer_Location_Store_Fake_Session} (or `null`,
	 * simulating WooCommerce/the session being unavailable) for the real
	 * `WC()->session` global — mirrors `Pickup_Selection_Probe`'s "override the
	 * protected seam, never mock WC() itself" discipline (Brain Monkey's
	 * Patchwork-based `Functions\when( 'WC' )` would leak `function_exists( 'WC' )
	 * === true` to the rest of the PHPUnit process).
	 */
	final class Customer_Location_Store_Probe extends Customer_Location_Store {

		/** @var Customer_Location_Store_Fake_Session|null */
		private ?Customer_Location_Store_Fake_Session $fake_session;

		public function __construct( ?Customer_Location_Store_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Customer_Location_Store
	 */
	final class CustomerLocationStoreTest extends TestCase {

		private const SESSION_KEY = 'woodev_customer_location';
		private const META_KEY    = 'woodev_customer_location';

		/**
		 * Simple in-memory user-meta fake, keyed [user_id][meta_key] => value —
		 * enough to stand in for `get_user_meta()`/`update_user_meta()` without a
		 * real WordPress install.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function fake_user_meta_store(): array {
			return [];
		}

		/**
		 * Wires `get_user_meta()`/`update_user_meta()` to a shared in-memory array
		 * (passed by reference so the test can assert on it directly).
		 *
		 * @param array<int, array<string, mixed>> $store Backing store, by reference.
		 *
		 * @return void
		 */
		private function stub_user_meta( array &$store ): void {
			Functions\when( 'get_user_meta' )->alias(
				static function ( $user_id, $key, $single = false ) use ( &$store ) {
					return $store[ $user_id ][ $key ] ?? '';
				}
			);

			Functions\when( 'update_user_meta' )->alias(
				static function ( $user_id, $key, $value ) use ( &$store ) {
					$store[ $user_id ][ $key ] = $value;

					return true;
				}
			);
		}

		private function stub_guest(): void {
			Functions\when( 'is_user_logged_in' )->justReturn( false );
		}

		private function stub_logged_in( int $user_id ): void {
			Functions\when( 'is_user_logged_in' )->justReturn( true );
			Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		}

		private function record( string $key = 'dadata:fias-1', string $level = Location_Record::LEVEL_SETTLEMENT ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => $level,
					'country'     => 'RU',
					'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				]
			);
		}

		// -------------------------------------------------------------------
		// Guest: session round trip, no meta touched
		// -------------------------------------------------------------------

		public function test_guest_write_then_read_round_trips_through_the_session(): void {
			Functions\expect( 'get_user_meta' )->never();
			Functions\expect( 'update_user_meta' )->never();

			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$this->assertTrue( $store->set( $this->record() ) );

			$read = $store->get();

			$this->assertNotNull( $read );
			$this->assertSame( 'dadata:fias-1', $read['record']->key() );
			$this->assertNotNull( $session->raw( self::SESSION_KEY ), 'the record must land under the framework session key' );
		}

		public function test_guest_write_without_an_initialized_session_is_a_noop_and_returns_false(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( null );

			// Must not throw.
			$result = $store->set( $this->record() );

			$this->assertFalse( $result );
			$this->assertNull( $store->get() );
		}

		/**
		 * Issue #324 review finding H1. `set()`'s boolean is not "did I put it in the
		 * session object" — it is what `/location/select` reports to the browser as
		 * `persisted`, and what `location-cascade.js` shows the customer a «не удалось
		 * сохранить» notice for. `WC_Session_Handler::save_data()` writes nothing
		 * unless `has_session()`, which for a GUEST means the cart cookie exists
		 * (`class-wc-session-handler.php:380,555`). A session object that will discard
		 * the write at shutdown must therefore report `false`, not `true`.
		 *
		 * This became reachable the moment #324 started bridging the session on
		 * `/select`: before, a guest with no session got `null` here and honestly
		 * reported `false`; after, the object always exists, so without this check the
		 * flag would be a constant `true` and the whole notice feature dead code.
		 */
		public function test_guest_write_into_a_session_that_will_not_survive_reports_false(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Durable_Session( false );
			$store   = new Customer_Location_Store_Durable_Probe( $session );

			$this->assertFalse( $store->set( $this->record() ) );

			// Still WRITTEN: the cookie may yet be set later in the same request, and a
			// write that costs nothing must not be withheld — only the REPORT is honest.
			$this->assertNotNull( $session->raw( self::SESSION_KEY ) );
		}

		public function test_guest_write_into_a_durable_session_reports_true(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Durable_Session( true );
			$store   = new Customer_Location_Store_Durable_Probe( $session );

			$this->assertTrue( $store->set( $this->record() ) );
		}

		/**
		 * A session handler that does not declare `has_session()` at all — the abstract
		 * `WC_Session` does not, and a custom handler installed through
		 * `woocommerce_session_handler` need not — must not be reported as a failure.
		 * Unknown is not false; crying wolf here would put a «не удалось сохранить»
		 * notice in front of customers on every such store.
		 */
		public function test_guest_write_reports_true_when_durability_is_unknowable(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$this->assertTrue( $store->set( $this->record() ) );
		}

		// -------------------------------------------------------------------
		// Logged-in: dual write, session-preferred read, meta fallback + repopulate
		// -------------------------------------------------------------------

		public function test_logged_in_write_goes_to_both_meta_and_session(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );
			$this->stub_logged_in( 42 );

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$this->assertTrue( $store->set( $this->record() ) );

			$this->assertNotNull( $session->raw( self::SESSION_KEY ), 'session fast path must be written' );
			$this->assertNotNull( $meta_store[42][ self::META_KEY ] ?? null, 'user meta must be written' );
		}

		public function test_logged_in_read_prefers_the_session_over_meta(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );
			$this->stub_logged_in( 42 );

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			// Seed DIFFERENT records into meta vs session directly, bypassing set(),
			// so the read path's preference is unambiguous.
			$meta_store[42][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:meta-record' )->to_array(),
				'implicit' => false,
				'saved_at' => 100,
			];
			$session->set(
				self::SESSION_KEY,
				[
					'record'   => $this->record( 'dadata:session-record' )->to_array(),
					'implicit' => false,
					'saved_at' => 200,
				]
			);

			$read = $store->get();

			$this->assertSame( 'dadata:session-record', $read['record']->key() );
		}

		public function test_logged_in_read_falls_back_to_meta_and_repopulates_the_session(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );
			$this->stub_logged_in( 42 );

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$meta_store[42][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:meta-record' )->to_array(),
				'implicit' => false,
				'saved_at' => 100,
			];

			$this->assertNull( $session->raw( self::SESSION_KEY ), 'sanity: session starts empty' );

			$read = $store->get();

			$this->assertSame( 'dadata:meta-record', $read['record']->key() );

			// The whole point of repopulation: a page rendering BEFORE any explicit
			// session write (spec) still gets the fast path from the next read on.
			$this->assertNotNull(
				$session->raw( self::SESSION_KEY ),
				'a meta-sourced read must repopulate the session fast path'
			);
			$this->assertSame(
				'dadata:meta-record',
				$session->raw( self::SESSION_KEY )['record']['key'],
				'the repopulated session entry must carry the SAME record read from meta'
			);
		}

		public function test_logged_in_write_survives_the_session_being_cleared_because_meta_is_authoritative(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );
			$this->stub_logged_in( 42 );

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$store->set( $this->record( 'dadata:persisted' ) );

			// Simulate a fresh session (new cookie / cache purge) — meta must still answer.
			$session->clear( self::SESSION_KEY );

			$read = $store->get();

			$this->assertNotNull( $read );
			$this->assertSame( 'dadata:persisted', $read['record']->key() );
		}

		// -------------------------------------------------------------------
		// implicit/explicit precedence (spec D11)
		// -------------------------------------------------------------------

		public function test_explicit_set_overwrites_an_implicit_record_and_drops_the_flag(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( new Customer_Location_Store_Fake_Session() );

			$store->set( $this->record( 'dadata:guess' ), true );
			$store->set( $this->record( 'dadata:real-choice' ), false );

			$read = $store->get();

			$this->assertSame( 'dadata:real-choice', $read['record']->key() );
			$this->assertFalse( $read['implicit'] );
		}

		public function test_implicit_set_does_not_overwrite_an_explicit_record(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( new Customer_Location_Store_Fake_Session() );

			$store->set( $this->record( 'dadata:real-choice' ), false );
			$result = $store->set( $this->record( 'dadata:guess' ), true );

			$this->assertFalse( $result, 'an implicit set() over an explicit record must report refusal' );

			$read = $store->get();

			$this->assertSame( 'dadata:real-choice', $read['record']->key() );
			$this->assertFalse( $read['implicit'] );
		}

		public function test_implicit_set_may_overwrite_another_implicit_record(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( new Customer_Location_Store_Fake_Session() );

			$store->set( $this->record( 'dadata:old-guess' ), true );
			$result = $store->set( $this->record( 'dadata:new-guess' ), true );

			$this->assertTrue( $result );

			$read = $store->get();

			$this->assertSame( 'dadata:new-guess', $read['record']->key() );
			$this->assertTrue( $read['implicit'] );
		}

		public function test_implicit_set_writes_when_nothing_is_stored_yet(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( new Customer_Location_Store_Fake_Session() );

			$this->assertTrue( $store->set( $this->record(), true ) );
			$this->assertTrue( $store->get()['implicit'] );
		}

		// -------------------------------------------------------------------
		// wp_login migration
		// -------------------------------------------------------------------

		public function test_login_migration_copies_the_session_record_to_meta_when_no_meta_record_exists(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$session->set(
				self::SESSION_KEY,
				[
					'record'   => $this->record( 'dadata:guest-choice' )->to_array(),
					'implicit' => false,
					'saved_at' => 111,
				]
			);

			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$this->assertSame( 'dadata:guest-choice', $meta_store[7][ self::META_KEY ]['record']['key'] ?? null );
		}

		public function test_login_migration_implicit_guest_guess_does_not_overwrite_an_explicit_account_record(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );

			$meta_store[7][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:account-choice' )->to_array(),
				'implicit' => false,
				'saved_at' => 50,
			];

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$session->set(
				self::SESSION_KEY,
				[
					'record'   => $this->record( 'dadata:guest-guess' )->to_array(),
					'implicit' => true,
					'saved_at' => 200,
				]
			);

			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$this->assertSame(
				'dadata:account-choice',
				$meta_store[7][ self::META_KEY ]['record']['key'],
				'the account explicit choice must survive an implicit guest guess'
			);
			// The session (now the logged-in user's fast path) must be resynced to
			// the winning (meta) record too — otherwise a subsequent session-preferred
			// read would still surface the losing guest guess.
			$this->assertSame( 'dadata:account-choice', $session->raw( self::SESSION_KEY )['record']['key'] );
		}

		public function test_login_migration_explicit_guest_choice_overwrites_an_existing_explicit_account_record(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );

			$meta_store[7][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:old-account-choice' )->to_array(),
				'implicit' => false,
				'saved_at' => 50,
			];

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$session->set(
				self::SESSION_KEY,
				[
					'record'   => $this->record( 'dadata:fresh-guest-choice' )->to_array(),
					'implicit' => false,
					'saved_at' => 200,
				]
			);

			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$this->assertSame(
				'dadata:fresh-guest-choice',
				$meta_store[7][ self::META_KEY ]['record']['key'],
				'a fresh explicit choice made while logged out is the customer\'s real answer and must win'
			);
		}

		public function test_login_migration_implicit_guest_guess_overwrites_an_implicit_account_record(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );

			$meta_store[7][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:old-guess' )->to_array(),
				'implicit' => true,
				'saved_at' => 50,
			];

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$session->set(
				self::SESSION_KEY,
				[
					'record'   => $this->record( 'dadata:fresh-guess' )->to_array(),
					'implicit' => true,
					'saved_at' => 200,
				]
			);

			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$this->assertSame( 'dadata:fresh-guess', $meta_store[7][ self::META_KEY ]['record']['key'] );
		}

		public function test_login_migration_is_a_noop_when_the_session_has_no_record(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );

			$meta_store[7][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:account-choice' )->to_array(),
				'implicit' => false,
				'saved_at' => 50,
			];

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$this->assertSame( 'dadata:account-choice', $meta_store[7][ self::META_KEY ]['record']['key'] );
		}

		public function test_login_migration_without_a_session_does_not_crash(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );

			$store = new Customer_Location_Store_Probe( null );

			// Must not throw.
			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$this->assertArrayNotHasKey( 7, $meta_store );
		}

		/**
		 * A hook this important (P1 finding) should not depend SOLELY on
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry}'s
		 * own once-per-fleet guard against double-registration — handle_wp_login()
		 * itself must be safe to run twice (e.g. `wp_login` somehow firing more than
		 * once for the same login). The second run sees exactly what the first run
		 * left behind (both stores already resynced to the winner), so it must
		 * reconfirm the SAME winner rather than flip it or duplicate anything.
		 */
		public function test_login_migration_run_twice_is_idempotent(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );

			$meta_store[7][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:account-choice' )->to_array(),
				'implicit' => false,
				'saved_at' => 50,
			];

			$session = new Customer_Location_Store_Fake_Session();
			$store   = new Customer_Location_Store_Probe( $session );

			$session->set(
				self::SESSION_KEY,
				[
					'record'   => $this->record( 'dadata:guest-guess' )->to_array(),
					'implicit' => true,
					'saved_at' => 200,
				]
			);

			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$first_meta    = $meta_store[7][ self::META_KEY ];
			$first_session = $session->raw( self::SESSION_KEY );

			// Simulate wp_login firing a second time for the same login.
			$store->handle_wp_login( 'jdoe', $this->wp_user( 7 ) );

			$this->assertSame( $first_meta, $meta_store[7][ self::META_KEY ], 'a second migration run must not change the winner' );
			$this->assertSame( $first_session, $session->raw( self::SESSION_KEY ), 'a second migration run must not change the resynced session' );
			$this->assertSame(
				'dadata:account-choice',
				$meta_store[7][ self::META_KEY ]['record']['key'],
				'the account explicit choice must still have won after a second run'
			);
		}

		// -------------------------------------------------------------------
		// Empty-key discipline
		// -------------------------------------------------------------------

		public function test_a_record_with_an_empty_key_cannot_be_constructed_and_so_cannot_reach_set(): void {
			$this->expectException( \InvalidArgumentException::class );

			// Customer_Location_Store::set() only accepts a Location_Record instance;
			// Location_Record::from_array() already refuses an empty key at
			// construction time, so an empty-key record can never reach the store.
			Location_Record::from_array(
				[
					'key'         => '',
					'provider_id' => 'dadata',
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);
		}

		public function test_a_stored_blob_with_an_empty_key_reads_back_as_null(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$session->set(
				self::SESSION_KEY,
				[
					'record'   => [ 'key' => '', 'provider_id' => 'dadata', 'level' => 'settlement', 'country' => 'RU' ],
					'implicit' => false,
					'saved_at' => 1,
				]
			);

			$store = new Customer_Location_Store_Probe( $session );

			$this->assertNull( $store->get() );
		}

		public function test_a_stored_blob_with_a_missing_key_field_reads_back_as_null(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$session->set(
				self::SESSION_KEY,
				[
					'record'   => [ 'provider_id' => 'dadata', 'level' => 'settlement', 'country' => 'RU' ],
					'implicit' => false,
					'saved_at' => 1,
				]
			);

			$store = new Customer_Location_Store_Probe( $session );

			$this->assertNull( $store->get() );
		}

		// -------------------------------------------------------------------
		// Corrupt/legacy stored blob — must degrade to null, never throw
		// -------------------------------------------------------------------

		public function test_a_non_array_stored_blob_reads_back_as_null(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$session->set( self::SESSION_KEY, 'not-an-array' );

			$store = new Customer_Location_Store_Probe( $session );

			$this->assertNull( $store->get() );
		}

		public function test_a_stored_blob_missing_the_record_key_reads_back_as_null(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$session->set( self::SESSION_KEY, [ 'implicit' => false, 'saved_at' => 1 ] );

			$store = new Customer_Location_Store_Probe( $session );

			$this->assertNull( $store->get() );
		}

		public function test_a_stored_blob_with_a_bogus_level_reads_back_as_null(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$session->set(
				self::SESSION_KEY,
				[
					'record'   => [ 'key' => 'dadata:1', 'provider_id' => 'dadata', 'level' => 'galaxy', 'country' => 'RU' ],
					'implicit' => false,
					'saved_at' => 1,
				]
			);

			$store = new Customer_Location_Store_Probe( $session );

			$this->assertNull( $store->get() );
		}

		public function test_a_stored_record_that_is_not_an_array_reads_back_as_null(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$session->set( self::SESSION_KEY, [ 'record' => 'nope', 'implicit' => false, 'saved_at' => 1 ] );

			$store = new Customer_Location_Store_Probe( $session );

			$this->assertNull( $store->get() );
		}

		// -------------------------------------------------------------------
		// saved_at
		// -------------------------------------------------------------------

		public function test_saved_at_is_written_and_reading_does_not_mutate_it(): void {
			$this->stub_guest();

			$session = new Customer_Location_Store_Fake_Session();
			$session->set(
				self::SESSION_KEY,
				[
					'record'   => $this->record()->to_array(),
					'implicit' => false,
					'saved_at' => 12345,
				]
			);

			$store = new Customer_Location_Store_Probe( $session );

			$first  = $store->get();
			$second = $store->get();

			$this->assertSame( 12345, $first['saved_at'] );
			$this->assertSame( 12345, $second['saved_at'], 'reading twice must not change saved_at' );
		}

		public function test_set_stamps_saved_at(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( new Customer_Location_Store_Fake_Session() );

			$store->set( $this->record() );

			$this->assertIsInt( $store->get()['saved_at'] );
			$this->assertGreaterThan( 0, $store->get()['saved_at'] );
		}

		// -------------------------------------------------------------------
		// No WooCommerce at all
		// -------------------------------------------------------------------

		public function test_no_session_guest_get_returns_null(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( null );

			$this->assertNull( $store->get() );
		}

		public function test_no_session_logged_in_still_reads_meta(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );
			$this->stub_logged_in( 42 );

			$meta_store[42][ self::META_KEY ] = [
				'record'   => $this->record( 'dadata:meta-only' )->to_array(),
				'implicit' => false,
				'saved_at' => 1,
			];

			$store = new Customer_Location_Store_Probe( null );

			$read = $store->get();

			$this->assertNotNull( $read, 'meta is WP-core, independent of WooCommerce/session availability' );
			$this->assertSame( 'dadata:meta-only', $read['record']->key() );
		}

		public function test_no_session_logged_in_set_still_writes_meta_and_returns_true(): void {
			$meta_store = $this->fake_user_meta_store();
			$this->stub_user_meta( $meta_store );
			$this->stub_logged_in( 42 );

			$store = new Customer_Location_Store_Probe( null );

			$this->assertTrue( $store->set( $this->record( 'dadata:meta-only-write' ) ) );
			$this->assertSame( 'dadata:meta-only-write', $meta_store[42][ self::META_KEY ]['record']['key'] );
		}

		// -------------------------------------------------------------------
		// Extension hook
		// -------------------------------------------------------------------

		public function test_a_successful_set_fires_the_saved_action(): void {
			$this->stub_guest();

			Actions\expectDone( 'woodev_customer_location_saved' )->once();

			$store = new Customer_Location_Store_Probe( new Customer_Location_Store_Fake_Session() );

			$store->set( $this->record() );
		}

		public function test_a_refused_implicit_set_does_not_fire_the_saved_action(): void {
			$this->stub_guest();

			$store = new Customer_Location_Store_Probe( new Customer_Location_Store_Fake_Session() );

			$store->set( $this->record( 'dadata:real-choice' ), false );

			Actions\expectDone( 'woodev_customer_location_saved' )->never();

			$store->set( $this->record( 'dadata:guess' ), true );
		}

		/**
		 * Minimal `WP_User`-shaped stand-in — only `->ID` is read by
		 * {@see Customer_Location_Store::handle_wp_login()}. `handle_wp_login()`
		 * leaves its second parameter untyped (see that method's own docblock for
		 * why — same precedent as
		 * `Woodev_Payment_Gateway_Admin_User_Handler::add_profile_section( $user )`),
		 * so a duck-typed object with a public `$ID` satisfies it without needing a
		 * real `\WP_User` class (or `eval()`) in the unit-test process.
		 *
		 * @param int $id User id.
		 *
		 * @return object{ID: int}
		 */
		private function wp_user( int $id ): object {
			return (object) [ 'ID' => $id ];
		}
	}
}

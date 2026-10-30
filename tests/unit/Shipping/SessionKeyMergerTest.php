<?php
/**
 * Tests for Session_Key_Merger (#1381) — the per-key merge of framework-owned `WC()->session`
 * keys run on `shutdown` right before WooCommerce < 11.4 writes the whole session row back:
 * a concurrent write of a key this request only READ is adopted, a key this request WROTE is
 * kept, a key another request removed is removed; and every gate under which the merge must
 * do nothing — WooCommerce merging per key itself (11.4+), a custom session handler, no
 * session, no cart cookie, a session WooCommerce is not going to save, no stored row yet.
 * Plus the two writers' bookkeeping: the location store and the pickup selection map report
 * their reads and writes.
 *
 * Most tests run in their own process: they need the exact class name `WC_Session_Handler`
 * (and one needs `SessionDataWriter`) declared, and a class declared in the shared PHPUnit
 * process cannot be undeclared (gotcha `a-mockery-alias-mock-declares-the-class-for-the-whole-process`;
 * PHPUnit 9 honours the annotation per METHOD only).
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping {

	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
	use Woodev\Framework\Shipping\Pickup\Selection_Scope;
	use Woodev\Framework\Shipping\Session_Key_Merger;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-session-key-merger.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/pickup/interface-selection-scope.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/pickup/class-pickup-selection.php';

	/**
	 * The `$wpdb` the merge reads the sessions table through: `prefix`, `prepare()` with `%s`,
	 * a `get_var()` that records the statement and answers with the canned row, `last_error`.
	 */
	final class Session_Key_Merger_Fake_Wpdb {

		/** @var string */
		public $prefix = 'wp_';

		/** @var string */
		public $last_error = '';

		/** @var string[] every statement `get_var()` received. */
		public $queries = [];

		/** @var string|null the stored `session_value`, or `null` for «no row». */
		private ?string $row;

		/** @var string the error every `get_var()` reports, `''` for none. */
		private string $failure;

		/**
		 * @param array<string, mixed>|null $row     The stored session as PLAIN values (serialized here the
		 *                                           way WooCommerce stores it), or `null` for no row.
		 * @param string                    $failure `wpdb::$last_error` after the read.
		 */
		public function __construct( ?array $row, string $failure = '' ) {
			$this->row     = null === $row ? null : serialize( array_map( 'maybe_serialize', $row ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			$this->failure = $failure;
		}

		/**
		 * @param string $query SQL with `%s` placeholders.
		 * @param mixed  ...$args Values.
		 */
		public function prepare( string $query, ...$args ): string {
			$index = 0;

			return (string) preg_replace_callback(
				'/%s/',
				static function () use ( $args, &$index ): string {
					return "'" . addcslashes( (string) ( $args[ $index++ ] ?? '' ), "'\\" ) . "'";
				},
				$query
			);
		}

		/**
		 * @param string $query The prepared statement.
		 *
		 * @return string|null
		 */
		public function get_var( string $query ): ?string {
			$this->queries[]  = $query;
			$this->last_error = $this->failure;

			return $this->row;
		}
	}

	/**
	 * Probe substituting an injected handler for the real `WC()->session`. Everything else —
	 * the `SessionDataWriter` check, the class check, the reflection on `$_dirty`, the table
	 * read through `$wpdb` — is the real code.
	 */
	final class Session_Key_Merger_Probe extends Session_Key_Merger {

		/** @var object|null */
		private $handler;

		/**
		 * @param object|null $handler The session handler to merge into, or `null` for «no session».
		 */
		public function __construct( $handler ) {
			$this->handler = $handler;
		}

		protected function session() {
			return $this->handler;
		}
	}

	/**
	 * Minimal `\WC_Session` stand-in for the writer-bookkeeping tests: array-backed get()/set().
	 */
	final class Session_Key_Merger_Fake_Session {

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
	}

	/**
	 * {@see Customer_Location_Store} on the fake session above.
	 */
	final class Session_Key_Merger_Store_Probe extends Customer_Location_Store {

		private Session_Key_Merger_Fake_Session $fake_session;

		public function __construct( Session_Key_Merger_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * {@see Pickup_Selection} on the fake session above.
	 */
	final class Session_Key_Merger_Selection_Probe extends Pickup_Selection {

		private Session_Key_Merger_Fake_Session $fake_session;

		public function __construct( Selection_Scope $scope, Session_Key_Merger_Fake_Session $fake_session ) {
			parent::__construct( $scope );
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * A {@see Selection_Scope} that only ever has to answer its session key.
	 */
	final class Session_Key_Merger_Test_Scope implements Selection_Scope {

		public function session_key(): string {
			return 'woodev_merger_test_pickup_selection';
		}

		public function locality_for_point( Pickup_Point $point ): string {
			return $point->get_locality();
		}

		public function current_locality(): string {
			return '';
		}

		public function type_for_method( string $method_id ): ?string {
			return null;
		}
	}

	class SessionKeyMergerTest extends TestCase {

		private const LOCATION_KEY = 'woodev_customer_location';

		private const PICKUP_KEY = 'woodev_edostavka_pickup_selection';

		private const MOSCOW = [
			'records'  => [ 'settlement' => [ 'key' => 'cdek:44', 'name' => 'Москва' ] ],
			'current'  => 'settlement',
			'implicit' => false,
			'saved_at' => 1000,
		];

		private const KAZAN = [
			'records'  => [ 'settlement' => [ 'key' => 'cdek:424', 'name' => 'Казань' ] ],
			'current'  => 'settlement',
			'implicit' => false,
			'saved_at' => 1003,
		];

		protected function setUp(): void {
			parent::setUp();

			Session_Key_Merger::reset_for_tests();

			// WordPress' own (de)serialization helpers, as WooCommerce's session uses them.
			Functions\when( 'maybe_serialize' )->alias(
				static function ( $value ) {
					return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				}
			);
			Functions\when( 'maybe_unserialize' )->alias(
				static function ( $value ) {
					if ( ! is_string( $value ) ) {
						return $value;
					}

					$data = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

					return false === $data && 'b:0;' !== $value ? $value : $data;
				}
			);
		}

		protected function tearDown(): void {
			Session_Key_Merger::reset_for_tests();
			unset( $GLOBALS['wpdb'] );
			parent::tearDown();
		}

		// ------------------------------------------------------------------
		// The merge itself — a real `WC_Session_Handler` (stub by that exact
		// name), a dirty session with a cart cookie, a stored row to read.
		// ------------------------------------------------------------------

		/**
		 * The request as the stale `update_order_review` of the ticket sees it: it loaded Moscow,
		 * rated, and changed WooCommerce's own keys (so the session is dirty and WILL be saved).
		 *
		 * @param array<string, mixed> $loaded The session as this request loaded it.
		 */
		private function dirty_handler( array $loaded, bool $has_session = true ): \WC_Session_Handler {
			$handler = new \WC_Session_Handler( 't_guest_1', $loaded, $has_session );
			$handler->set( 'chosen_shipping_methods', [ 'edostavka_pickup:47' ] );

			return $handler;
		}

		/**
		 * @param array<string, mixed>|null $row The row as stored RIGHT NOW (plain values), or `null`.
		 */
		private function stored_row( ?array $row, string $failure = '' ): Session_Key_Merger_Fake_Wpdb {
			$GLOBALS['wpdb'] = new Session_Key_Merger_Fake_Wpdb( $row, $failure );

			return $GLOBALS['wpdb'];
		}

		/**
		 * The measured race (report §1, pair mode, 5/5 lost on 11.2.1): this request loaded Moscow and
		 * only READ it; `/location/select` saved Kazan meanwhile. The merge adopts Kazan, so
		 * WooCommerce's whole-row save a moment later writes Kazan back, not Moscow.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_adopts_a_concurrent_write_of_a_key_this_request_only_read(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ] );
			$wpdb    = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN, 'cart' => [ 'x' => 1 ] ] );
			$merger  = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::KAZAN, $handler->get( self::LOCATION_KEY ), 'the concurrent write wins over the stale copy' );
			$this->assertCount( 1, $wpdb->queries, 'one SELECT, straight from the sessions table' );
			$this->assertStringContainsString( "FROM wp_woocommerce_sessions WHERE session_key = 't_guest_1'", $wpdb->queries[0] );
			$this->assertArrayNotHasKey( 'cart', $handler->raw_data(), 'only the framework key is touched — WooCommerce keys stay whole-row' );
		}

		/**
		 * The `/location/select` request itself: it WROTE Kazan, and the row still holds Moscow (or a
		 * slower request's stale save). This request's value is the one to keep — same key, two
		 * writers, last writer wins, as WooCommerce 11.4 does.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_leaves_a_key_this_request_wrote(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ] );
			$handler->set( self::LOCATION_KEY, self::KAZAN );
			$wpdb   = $this->stored_row( [ self::LOCATION_KEY => self::MOSCOW ] );
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			Session_Key_Merger::note_write( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::KAZAN, $handler->get( self::LOCATION_KEY ) );
			$this->assertSame( [], $wpdb->queries, 'nothing pending, nothing read' );
		}

		/**
		 * A pickup map this request read while `/location/forget` (or order processing in another tab)
		 * removed it: the key is removed here too, so the save does not resurrect it.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_removes_a_key_another_request_deleted(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler(
				[
					self::LOCATION_KEY => self::MOSCOW,
					self::PICKUP_KEY   => [ 'cdek:44' => [ 'pvz' => [ 'id' => 'MSK2', 'seq' => 1 ] ] ],
				]
			);
			$this->stored_row( [ self::LOCATION_KEY => self::MOSCOW ] );
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			Session_Key_Merger::note_read( self::PICKUP_KEY );
			$merger->merge_before_save();

			$this->assertNull( $handler->get( self::PICKUP_KEY ) );
			$this->assertArrayNotHasKey( self::PICKUP_KEY, $handler->raw_data(), 'removed, not stored as null' );
			$this->assertSame( self::MOSCOW, $handler->get( self::LOCATION_KEY ), 'an unchanged key is left as it is' );
		}

		/**
		 * One pending key written by both requests with different values and one only the other wrote:
		 * each key is judged on its own.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_judges_each_key_on_its_own(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ] );
			$handler->set( self::PICKUP_KEY, [ 'cdek:424' => [ 'pvz' => [ 'id' => 'KZN1', 'seq' => 1 ] ] ] );
			$this->stored_row(
				[
					self::LOCATION_KEY => self::KAZAN,
					self::PICKUP_KEY   => [ 'cdek:44' => [ 'pvz' => [ 'id' => 'MSK2', 'seq' => 1 ] ] ],
				]
			);
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			Session_Key_Merger::note_read( self::PICKUP_KEY );
			Session_Key_Merger::note_write( self::PICKUP_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::KAZAN, $handler->get( self::LOCATION_KEY ), 'read-only key: adopted' );
			$this->assertSame( 'KZN1', $handler->get( self::PICKUP_KEY )['cdek:424']['pvz']['id'], 'written key: kept' );
		}

		/**
		 * kalbac/woocommerce-yandex-delivery#150: a request that saves the session without ever reading the
		 * record (here an `apply_coupon` that only left a notice) loaded Moscow; `/location/select` saved
		 * Kazan meanwhile. Nothing in the request reports the record, yet the merge adopts Kazan.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_adopts_the_location_record_in_a_request_that_never_read_it(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ] );
			$wpdb    = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger  = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::protect_location_record();
			$merger->merge_before_save();

			$this->assertSame( self::KAZAN, $handler->get( self::LOCATION_KEY ), 'the concurrent write wins over the stale copy' );
			$this->assertCount( 1, $wpdb->queries );
		}

		/**
		 * The same request when the shopper had NO record when it loaded and `/location/select` saved one
		 * meanwhile: the record is adopted, not dropped by the whole-row save.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_adopts_a_record_saved_while_a_request_without_one_ran(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [] );
			$this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::protect_location_record();
			$merger->merge_before_save();

			$this->assertSame( self::KAZAN, $handler->get( self::LOCATION_KEY ) );
		}

		/**
		 * The protection is not a second path for the key: a request that writes the record after the
		 * protection was set keeps its own value.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_a_request_that_writes_the_protected_record_keeps_its_value(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ] );
			$handler->set( self::LOCATION_KEY, self::KAZAN );
			$wpdb   = $this->stored_row( [ self::LOCATION_KEY => self::MOSCOW ] );
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::protect_location_record();
			Session_Key_Merger::note_write( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::KAZAN, $handler->get( self::LOCATION_KEY ) );
			$this->assertSame( [], $wpdb->queries, 'nothing pending, nothing read' );
		}

		/**
		 * A request that never saves the session pays no query for the protection: the gates run before
		 * the read, and a clean session is left clean.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_the_protection_costs_no_query_in_a_request_that_does_not_save_the_session(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = new \WC_Session_Handler( 't_guest_1', [ self::LOCATION_KEY => self::MOSCOW ] );
			$wpdb    = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger  = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::protect_location_record();
			$merger->merge_before_save();

			$this->assertFalse( $handler->is_dirty() );
			$this->assertSame( [], $wpdb->queries );
		}

		/**
		 * WooCommerce 11.4+ merges per key itself: the protection registers nothing, so a shop on a current
		 * WooCommerce carries no `shutdown` callback for it.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_the_protection_hooks_nothing_when_woocommerce_merges_per_key_itself(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-data-writer.php';

			Actions\expectAdded( 'shutdown' )->never();

			Session_Key_Merger::protect_location_record();

			$this->assertSame( [], Session_Key_Merger::instance()->pending_keys() );
		}

		// ------------------------------------------------------------------
		// The gates — every one of them must leave the session untouched AND
		// issue no query.
		// ------------------------------------------------------------------

		/**
		 * WooCommerce 11.4 (woocommerce#69559) merges per key itself; its `SessionDataWriter` present
		 * means this class steps aside, whatever the row holds.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_is_a_no_op_when_woocommerce_merges_per_key_itself(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-data-writer.php';

			$this->assertTrue( class_exists( Session_Key_Merger::CORE_MERGE_CLASS ), 'precondition: the 11.4 class is declared' );

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ] );
			$wpdb    = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger  = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::MOSCOW, $handler->get( self::LOCATION_KEY ) );
			$this->assertSame( [], $wpdb->queries );
		}

		/**
		 * A handler installed through `woocommerce_session_handler` — here a subclass, as Redis handlers
		 * and the Store API cart-token handler are — may not keep its rows in the sessions table at all.
		 * Only the exact core class is merged.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_is_a_no_op_for_a_custom_session_handler(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = new class( 't_guest_1', [ self::LOCATION_KEY => self::MOSCOW ] ) extends \WC_Session_Handler {};
			$handler->set( 'chosen_shipping_methods', [ 'x' ] );
			$wpdb   = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::MOSCOW, $handler->get( self::LOCATION_KEY ) );
			$this->assertSame( [], $wpdb->queries );
		}

		/**
		 * `has_session()` false — a guest without the cart cookie: `save_data()` writes nothing, so
		 * there is nothing to protect.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_is_a_no_op_when_the_session_will_not_survive(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ], false );
			$wpdb    = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger  = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::MOSCOW, $handler->get( self::LOCATION_KEY ) );
			$this->assertSame( [], $wpdb->queries );
		}

		/**
		 * A CLEAN session: WooCommerce is not going to save it, so the row's newer value already stands.
		 * Adopting it through `set()` would dirty the session and make WooCommerce write the whole
		 * stale row — undoing every concurrent change instead of just ours. The flag is WooCommerce's
		 * own `$_dirty`, read by reflection.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_merge_leaves_a_clean_session_clean(): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = new \WC_Session_Handler( 't_guest_1', [ self::LOCATION_KEY => self::MOSCOW ] );
			$this->assertFalse( $handler->is_dirty(), 'precondition' );
			$wpdb   = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertFalse( $handler->is_dirty(), 'still clean — WooCommerce must not be made to save' );
			$this->assertSame( self::MOSCOW, $handler->get( self::LOCATION_KEY ) );
			$this->assertSame( [], $wpdb->queries );
		}

		/**
		 * No stored row (a session saved for the first time, a guest just migrated onto an account whose
		 * row does not exist yet) or a failed read: nothing another request could have written,
		 * nothing adopted — in particular our key is NOT removed.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @dataProvider unreadable_rows
		 */
		public function test_merge_is_a_no_op_without_a_stored_row( ?array $row, string $failure ): void {
			require_once dirname( __DIR__, 2 ) . '/_stubs/wc-session-handler.php';

			$handler = $this->dirty_handler( [ self::LOCATION_KEY => self::MOSCOW ] );
			$this->stored_row( $row, $failure );
			$merger = new Session_Key_Merger_Probe( $handler );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( self::MOSCOW, $handler->get( self::LOCATION_KEY ) );
		}

		/**
		 * @return array<string, array{0: array<string, mixed>|null, 1: string}>
		 */
		public function unreadable_rows(): array {
			return [
				'no row'      => [ null, '' ],
				'read failed' => [ [ self::LOCATION_KEY => self::KAZAN ], 'MySQL server has gone away' ],
			];
		}

		public function test_merge_is_a_no_op_without_a_session(): void {
			$wpdb   = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger = new Session_Key_Merger_Probe( null );
			Session_Key_Merger::reset_for_tests( $merger );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			$merger->merge_before_save();

			$this->assertSame( [], $wpdb->queries );
		}

		public function test_merge_is_a_no_op_when_nothing_was_touched(): void {
			// Even an object of the wrong kind is never looked at when there is no pending key.
			$wpdb   = $this->stored_row( [ self::LOCATION_KEY => self::KAZAN ] );
			$merger = new Session_Key_Merger_Probe( new \stdClass() );
			Session_Key_Merger::reset_for_tests( $merger );

			$merger->merge_before_save();

			$this->assertSame( [], $wpdb->queries );
		}

		// ------------------------------------------------------------------
		// Bookkeeping: what is pending, and when the hook is registered.
		// ------------------------------------------------------------------

		public function test_pending_keys_are_the_touched_keys_this_request_did_not_write(): void {
			Session_Key_Merger::note_read( self::LOCATION_KEY );
			Session_Key_Merger::note_read( self::PICKUP_KEY );
			Session_Key_Merger::note_write( self::PICKUP_KEY );
			Session_Key_Merger::note_write( 'woodev_edostavka_pickup_selection_placed' );
			Session_Key_Merger::note_read( '' );

			$merger = Session_Key_Merger::instance();

			$this->assertSame( [ self::LOCATION_KEY ], $merger->pending_keys() );
			$this->assertSame( [ self::PICKUP_KEY, 'woodev_edostavka_pickup_selection_placed' ], $merger->written_keys() );
		}

		public function test_the_shutdown_hook_is_registered_once_on_the_first_touch_and_before_woocommerce_saves(): void {
			Actions\expectAdded( 'shutdown' )->once()->with( \Mockery::type( 'array' ), Session_Key_Merger::PRIORITY );

			Session_Key_Merger::note_read( self::LOCATION_KEY );
			Session_Key_Merger::note_write( self::LOCATION_KEY );
			Session_Key_Merger::note_read( self::PICKUP_KEY );

			$this->assertSame( 19, Session_Key_Merger::PRIORITY, 'WooCommerce saves at 20 (WC_Session_Handler::init_hooks())' );
		}

		public function test_the_location_record_protection_hooks_the_merge_once_and_pends_the_record_key(): void {
			Actions\expectAdded( 'shutdown' )->once()->with( \Mockery::type( 'array' ), Session_Key_Merger::PRIORITY );

			Session_Key_Merger::protect_location_record();
			Session_Key_Merger::protect_location_record();

			$this->assertSame( [ self::LOCATION_KEY ], Session_Key_Merger::instance()->pending_keys() );
			$this->assertSame( self::LOCATION_KEY, Session_Key_Merger::LOCATION_RECORD_KEY, 'the store stores the record under this very key' );
		}

		public function test_nothing_is_hooked_in_a_request_that_never_touches_a_framework_key(): void {
			Actions\expectAdded( 'shutdown' )->never();

			Session_Key_Merger::instance();
			Session_Key_Merger::note_read( '' );

			$this->assertSame( [], Session_Key_Merger::instance()->pending_keys() );
		}

		// ------------------------------------------------------------------
		// The writers report what they read and write.
		// ------------------------------------------------------------------

		public function test_the_location_store_reports_its_reads_and_writes(): void {
			Functions\when( 'is_user_logged_in' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wc_parse_relative_date_option' )->justReturn( [ 'number' => '', 'unit' => 'days' ] );

			$store = new Session_Key_Merger_Store_Probe( new Session_Key_Merger_Fake_Session() );

			$this->assertNull( $store->get() );
			$this->assertSame( [ self::LOCATION_KEY ], Session_Key_Merger::instance()->pending_keys(), 'a read puts the key under protection' );

			$store->set(
				Location_Record::from_array(
					[
						'key'         => 'svc:kazan',
						'provider_id' => 'svc',
						'level'       => Location_Record::LEVEL_SETTLEMENT,
						'country'     => 'RU',
						'settlement'  => [ 'name' => 'Казань', 'type' => 'г' ],
					]
				),
				false
			);

			$this->assertSame( [], Session_Key_Merger::instance()->pending_keys(), 'a write takes the key out of the merge' );
			$this->assertSame( [ self::LOCATION_KEY ], Session_Key_Merger::instance()->written_keys() );
		}

		public function test_the_pickup_selection_reports_its_reads_and_writes(): void {
			$scope     = new Session_Key_Merger_Test_Scope();
			$selection = new Session_Key_Merger_Selection_Probe( $scope, new Session_Key_Merger_Fake_Session() );

			$this->assertNull( $selection->recall( 'cdek:44', 'pvz' ) );
			$this->assertNull( $selection->recall_placed( 7 ) );
			$this->assertSame( [ $scope->session_key(), $scope->session_key() . '_placed' ], Session_Key_Merger::instance()->pending_keys() );

			Session_Key_Merger::reset_for_tests();
			$selection->remember( 'cdek:44', 'pvz', 'MSK2', 'Москва, Тверская 1' );
			$selection->remember_placed( 7, [ 'rate' => 'edostavka_pickup:47' ] );

			$this->assertSame( [], Session_Key_Merger::instance()->pending_keys() );
			$this->assertSame( [ $scope->session_key(), $scope->session_key() . '_placed' ], Session_Key_Merger::instance()->written_keys() );

			Session_Key_Merger::reset_for_tests();
			$selection->forget_all();

			$this->assertSame( [ $scope->session_key() ], Session_Key_Merger::instance()->written_keys(), 'clearing the map is a write too' );
		}
	}
}

<?php
/**
 * Tests for Location_Resolution_Cache — the mandatory per-plugin adapter contract's
 * lazy, session-cached resolution (Task 5; spec D9, §4.3): first resolve_for() call
 * per (locality_key, plugin_id) runs the adapter; a subsequent call for the same pair
 * is a cache hit, including when the adapter resolved to `null` ("this carrier does
 * not serve this locality" — a legitimate, cached answer); a throwing adapter is
 * logged and treated as transient (never cached); the adapter obligation
 * (`needs_location_provider() === true` but `get_location_adapter() === null`) is
 * reported via `_doing_it_wrong()`; and every degradation path (no session) resolves
 * without caching rather than fatal.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Location_Adapter;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Resolution_Cache;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';

	/**
	 * Minimal `\WC_Session` stand-in — same shape as
	 * `Customer_Location_Store_Fake_Session` (Task 4's own test file).
	 */
	final class Location_Resolution_Cache_Fake_Session {

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
		 * @return mixed
		 */
		public function raw( string $key ) {
			return $this->store[ $key ] ?? null;
		}
	}

	/**
	 * Probe substituting a {@see Location_Resolution_Cache_Fake_Session} (or
	 * `null`, simulating no WooCommerce session) for the real `WC()->session`
	 * global — mirrors `Customer_Location_Store_Probe`'s discipline.
	 */
	final class Location_Resolution_Cache_Probe extends Location_Resolution_Cache {

		/** @var Location_Resolution_Cache_Fake_Session|null */
		private ?Location_Resolution_Cache_Fake_Session $fake_session;

		public function __construct( ?Location_Resolution_Cache_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * Bare fixture implementing only what PHP requires to instantiate
	 * Shipping_Plugin at all — built via `newInstanceWithoutConstructor()`
	 * (same discipline as `ShippingPluginNeedsLocationProviderTest`'s own
	 * fixture: a real Shipping_Plugin constructor touches a long chain of
	 * WP/WC calls this test has no business stubbing).
	 *
	 * Mutable public properties stand in for constructor arguments so each
	 * test configures only what it needs.
	 */
	class Location_Resolution_Cache_Fixture_Plugin extends Shipping_Plugin {

		public string $fake_id = 'test_plugin';
		public bool $fake_needs_location_provider = true;
		public ?Location_Adapter $fake_adapter = null;

		protected function get_shipping_method_classes(): array {
			return [];
		}

		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Stub';
		}

		public function get_download_id() {
			return 0;
		}

		public function get_id() {
			return $this->fake_id;
		}

		public function needs_location_provider(): bool {
			return $this->fake_needs_location_provider;
		}

		public function get_location_adapter(): ?Location_Adapter {
			return $this->fake_adapter;
		}
	}

	/**
	 * A spy {@see Location_Adapter}: counts calls, and either returns a
	 * configured value or throws a configured exception.
	 */
	final class Location_Resolution_Cache_Spy_Adapter implements Location_Adapter {

		public int $calls = 0;

		/** @var mixed */
		private $return_value;

		private ?\Throwable $throw;

		/**
		 * @param mixed          $return_value Value to return (ignored while `$throw` is set).
		 * @param \Throwable|null $throw        Exception to throw instead of returning.
		 */
		public function __construct( $return_value = null, ?\Throwable $throw = null ) {
			$this->return_value = $return_value;
			$this->throw        = $throw;
		}

		public function resolve( Location_Record $record ) {
			++$this->calls;

			if ( null !== $this->throw ) {
				throw $this->throw;
			}

			return $this->return_value;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Location_Resolution_Cache
	 */
	final class LocationResolutionCacheTest extends TestCase {

		private const STORAGE_KEY = 'woodev_location_resolution_cache';

		/**
		 * The secret a foreign adapter's exception message embeds — #594.
		 *
		 * @var string
		 */
		private const SECRET = 'LIVESECRET';

		private function record( string $key = 'dadata:fias-1' ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);
		}

		private function plugin( ?Location_Adapter $adapter, bool $needs = true, string $id = 'test_plugin' ): Location_Resolution_Cache_Fixture_Plugin {
			$instance = ( new \ReflectionClass( Location_Resolution_Cache_Fixture_Plugin::class ) )->newInstanceWithoutConstructor();

			$instance->fake_id                     = $id;
			$instance->fake_needs_location_provider = $needs;
			$instance->fake_adapter                 = $adapter;

			return $instance;
		}

		protected function setUp(): void {
			parent::setUp();

			// No filter callback hooked by default: apply_filters() must return
			// the passed-through default value unchanged.
			Functions\when( 'apply_filters' )->returnArg( 2 );
		}

		// -------------------------------------------------------------------
		// First call runs the adapter; a second call for the same pair is a hit
		// -------------------------------------------------------------------

		public function test_first_resolve_calls_the_adapter_once(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'city-42' );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$result = $cache->resolve_for( $plugin, $this->record() );

			$this->assertSame( 'city-42', $result );
			$this->assertSame( 1, $adapter->calls );
		}

		public function test_a_second_call_for_the_same_pair_is_a_hit_the_adapter_is_called_exactly_once(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'city-42' );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			$first  = $cache->resolve_for( $plugin, $record );
			$second = $cache->resolve_for( $plugin, $record );
			$third  = $cache->resolve_for( $plugin, $record );

			$this->assertSame( 'city-42', $first );
			$this->assertSame( 'city-42', $second );
			$this->assertSame( 'city-42', $third );
			$this->assertSame( 1, $adapter->calls, 'the adapter must be called EXACTLY once across N reads' );
		}

		// -------------------------------------------------------------------
		// A null resolve ("does not serve") is cached as a distinct failure marker
		// -------------------------------------------------------------------

		public function test_a_null_resolve_is_cached_and_not_confusable_with_never_asked(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( null );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			$this->assertFalse( $cache->has( $plugin, $record ), 'sanity: nothing cached yet' );

			$first = $cache->resolve_for( $plugin, $record );

			$this->assertNull( $first );
			$this->assertTrue( $cache->has( $plugin, $record ), 'a null resolve must be recorded as a cache entry' );

			$second = $cache->resolve_for( $plugin, $record );

			$this->assertNull( $second );
			$this->assertSame( 1, $adapter->calls, 'a cached null answer must not re-call the adapter' );
		}

		// -------------------------------------------------------------------
		// Separate cache slots per locality key and per plugin id
		// -------------------------------------------------------------------

		public function test_different_locality_keys_get_separate_cache_slots(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'resolved' );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$cache->resolve_for( $plugin, $this->record( 'dadata:city-a' ) );
			$cache->resolve_for( $plugin, $this->record( 'dadata:city-b' ) );

			$this->assertSame( 2, $adapter->calls, 'two different localities must not share a cache slot' );
		}

		public function test_different_plugin_ids_get_separate_cache_slots(): void {
			$adapter_a = new Location_Resolution_Cache_Spy_Adapter( 'a-identity' );
			$adapter_b = new Location_Resolution_Cache_Spy_Adapter( 'b-identity' );
			$plugin_a  = $this->plugin( $adapter_a, true, 'plugin_a' );
			$plugin_b  = $this->plugin( $adapter_b, true, 'plugin_b' );

			$session = new Location_Resolution_Cache_Fake_Session();
			$cache   = new Location_Resolution_Cache_Probe( $session );

			$record = $this->record();

			$result_a = $cache->resolve_for( $plugin_a, $record );
			$result_b = $cache->resolve_for( $plugin_b, $record );

			$this->assertSame( 'a-identity', $result_a );
			$this->assertSame( 'b-identity', $result_b );
			$this->assertSame( 1, $adapter_a->calls );
			$this->assertSame( 1, $adapter_b->calls );
		}

		public function test_a_provider_switch_produces_a_different_locality_key_and_therefore_misses(): void {
			// spec D5: the locality key is namespaced by provider id, so a
			// switch from one provider to another produces a DIFFERENT key even
			// for "the same" real-world locality — a stale cache entry under
			// the old provider's key can never be misread as the new one's
			// answer.
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'resolved' );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$cache->resolve_for( $plugin, $this->record( 'dadata:fias-1' ) );
			$cache->resolve_for( $plugin, $this->record( 'cdek-dict:fias-1' ) );

			$this->assertSame( 2, $adapter->calls, 'a provider switch must miss the cache by construction' );
		}

		// -------------------------------------------------------------------
		// A throwing adapter: logged, not cached, retried on the next call
		// -------------------------------------------------------------------

		public function test_a_throwing_adapter_is_logged_not_cached_and_retried(): void {
			$exception = new \RuntimeException( 'carrier API timeout' );
			$adapter   = new Location_Resolution_Cache_Spy_Adapter( null, $exception );
			$plugin    = $this->plugin( $adapter );
			$cache     = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			$caught = null;
			try {
				$cache->resolve_for( $plugin, $record );
			} catch ( \RuntimeException $e ) {
				$caught = $e;
			}

			$this->assertSame( $exception, $caught, 'the adapter exception must propagate to the caller' );
			$this->assertSame( 1, $adapter->calls );
			$this->assertFalse( $cache->has( $plugin, $record ), 'a thrown resolution must never be cached' );

			// Retried: a second call reaches the adapter again (spy count 2) —
			// still failing here (the spy is configured to always throw), which
			// is itself the proof that nothing short-circuited the second call
			// via a bogus cache entry.
			$caught_again = null;
			try {
				$cache->resolve_for( $plugin, $record );
			} catch ( \RuntimeException $e ) {
				$caught_again = $e;
			}

			$this->assertSame( $exception, $caught_again );
			$this->assertSame( 2, $adapter->calls, 'a subsequent call must retry the adapter, not skip it' );
			$this->assertFalse( $cache->has( $plugin, $record ) );
		}

		// -------------------------------------------------------------------
		// #594: the rethrown \Throwable's message is a FOREIGN adapter's own
		// message (spec: Location_Adapter is supplied by the participating
		// plugin) — it must be redacted through Woodev_API_Base's own
		// redaction before it reaches error_log(), since it never passed
		// through that class's redaction itself.
		// -------------------------------------------------------------------

		public function test_a_throwing_adapter_redacts_a_secret_in_the_logged_message(): void {
			$exception = new \RuntimeException( 'carrier rejected api_key=' . self::SECRET );
			$adapter   = new Location_Resolution_Cache_Spy_Adapter( null, $exception );
			$plugin    = $this->plugin( $adapter, true, 'test_plugin' );
			$cache     = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );
			$record    = $this->record( 'dadata:fias-1' );

			$captured = null;
			Functions\expect( 'error_log' )
				->once()
				->with(
					\Mockery::on(
						static function ( $message ) use ( &$captured ) {
							$captured = $message;
							return true;
						}
					)
				);

			try {
				$cache->resolve_for( $plugin, $record );
				$this->fail( 'expected the adapter exception to propagate' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( $exception, $e );
			}

			$this->assertSame(
				sprintf(
					'[woodev] location adapter "%s" (plugin "%s") resolve() failed for locality "%s": %s',
					get_class( $adapter ),
					'test_plugin',
					$record->key(),
					'carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK
				),
				$captured
			);
		}

		/**
		 * Control: a thrown message carrying NO secret must reach the rendered
		 * error_log() line byte-for-byte — asserted on the COMPLETE rendered
		 * line, not merely a substring, so a redactor that mangled anything
		 * else in the line could not pass silently.
		 */
		public function test_a_throwing_adapter_without_a_secret_logs_the_message_untouched(): void {
			$exception = new \RuntimeException( 'carrier API timeout' );
			$adapter   = new Location_Resolution_Cache_Spy_Adapter( null, $exception );
			$plugin    = $this->plugin( $adapter, true, 'test_plugin' );
			$cache     = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );
			$record    = $this->record( 'dadata:fias-1' );

			$captured = null;
			Functions\expect( 'error_log' )
				->once()
				->with(
					\Mockery::on(
						static function ( $message ) use ( &$captured ) {
							$captured = $message;
							return true;
						}
					)
				);

			try {
				$cache->resolve_for( $plugin, $record );
				$this->fail( 'expected the adapter exception to propagate' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( $exception, $e );
			}

			$this->assertSame(
				sprintf(
					'[woodev] location adapter "%s" (plugin "%s") resolve() failed for locality "%s": %s',
					get_class( $adapter ),
					'test_plugin',
					$record->key(),
					'carrier API timeout'
				),
				$captured
			);
		}

		public function test_a_throwing_adapter_recovers_on_retry_once_the_underlying_condition_clears(): void {
			// A more realistic retry scenario: the SAME plugin/locality pair,
			// but the adapter's transient condition (e.g. an API timeout) is
			// gone by the second attempt. Modeled with two adapter instances
			// sharing one plugin fixture, since Location_Resolution_Cache_Fixture_Plugin
			// exposes its adapter through a simple mutable property.
			$plugin = $this->plugin( new Location_Resolution_Cache_Spy_Adapter( null, new \RuntimeException( 'timeout' ) ) );
			$cache  = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			try {
				$cache->resolve_for( $plugin, $record );
				$this->fail( 'expected the first call to throw' );
			} catch ( \RuntimeException $e ) {
				// expected.
			}

			$recovered_adapter        = new Location_Resolution_Cache_Spy_Adapter( 'recovered-identity' );
			$plugin->fake_adapter     = $recovered_adapter;

			$result = $cache->resolve_for( $plugin, $record );

			$this->assertSame( 'recovered-identity', $result );
			$this->assertSame( 1, $recovered_adapter->calls );
			$this->assertTrue( $cache->has( $plugin, $record ) );
		}

		// -------------------------------------------------------------------
		// Falsy-but-valid identities must be cached and returned as HITS
		// -------------------------------------------------------------------

		/**
		 * @dataProvider falsy_but_valid_identities
		 *
		 * @param mixed $identity A falsy value that is nonetheless a real,
		 *                        resolved carrier identity.
		 */
		public function test_falsy_but_valid_identities_are_cached_as_hits( $identity ): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( $identity );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			$first  = $cache->resolve_for( $plugin, $record );
			$second = $cache->resolve_for( $plugin, $record );

			$this->assertSame( $identity, $first );
			$this->assertSame( $identity, $second );
			$this->assertSame( 1, $adapter->calls, 'a falsy-but-valid identity must not be mistaken for "never asked" on the next read' );
			$this->assertTrue( $cache->has( $plugin, $record ) );
		}

		/**
		 * @return array<string, array{0: mixed}>
		 */
		public function falsy_but_valid_identities(): array {
			return [
				'int zero'     => [ 0 ],
				'empty string' => [ '' ],
				'string zero'  => [ '0' ],
				'bool false'   => [ false ],
				'empty array'  => [ [] ],
			];
		}

		// -------------------------------------------------------------------
		// Empty-key discipline
		// -------------------------------------------------------------------

		public function test_an_empty_plugin_id_never_produces_a_stored_entry(): void {
			// Location_Record::key() is already guaranteed non-empty by
			// Location_Record::from_array()'s own construction-time validation
			// (Task 1) — this class can never observe an empty locality key. A
			// plugin misreporting an empty id has no such guarantee, so this
			// class guards that dimension itself: nothing is ever stored under
			// it, and every call re-resolves.
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'resolved' );
			$plugin  = $this->plugin( $adapter, true, '' );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			$cache->resolve_for( $plugin, $record );
			$cache->resolve_for( $plugin, $record );

			$this->assertFalse( $cache->has( $plugin, $record ) );
			$this->assertSame( 2, $adapter->calls, 'an unusable plugin-id dimension must never be cached, so every call re-resolves' );
		}

		public function test_an_empty_plugin_id_seeded_directly_into_the_session_is_never_read_back(): void {
			// Belt-and-suspenders on the read side too, mirroring the gotcha's
			// own testing note: refuse on BOTH the write and the read.
			$session = new Location_Resolution_Cache_Fake_Session();
			$session->set(
				self::STORAGE_KEY,
				[
					'dadata:fias-1' => [ '' => [ 'v' => 'someone-elses-answer', 'ok' => true, 't' => time() ] ],
				]
			);

			$plugin = $this->plugin( new Location_Resolution_Cache_Spy_Adapter( 'someone-elses-answer' ), true, '' );
			$cache  = new Location_Resolution_Cache_Probe( $session );

			$this->assertFalse( $cache->has( $plugin, $this->record( 'dadata:fias-1' ) ) );
		}

		// -------------------------------------------------------------------
		// Adapter obligation (spec §4.3)
		// -------------------------------------------------------------------

		public function test_needs_location_provider_true_but_no_adapter_triggers_doing_it_wrong(): void {
			Functions\expect( '_doing_it_wrong' )
				->once()
				->with( \Mockery::type( 'string' ), \Mockery::pattern( '/test_plugin/' ), '2.0.2' );

			$plugin = $this->plugin( null, true, 'test_plugin' );
			$cache  = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$result = $cache->resolve_for( $plugin, $this->record() );

			$this->assertNull( $result );
		}

		public function test_no_adapter_and_needs_location_provider_false_does_not_warn(): void {
			Functions\expect( '_doing_it_wrong' )->never();

			$plugin = $this->plugin( null, false, 'no_op_plugin' );
			$cache  = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$this->assertNull( $cache->resolve_for( $plugin, $this->record() ) );
		}

		public function test_the_obligation_violation_is_not_cached(): void {
			Functions\when( '_doing_it_wrong' )->justReturn( null );

			$plugin = $this->plugin( null, true );
			$cache  = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			$cache->resolve_for( $plugin, $record );

			$this->assertFalse( $cache->has( $plugin, $record ), 'a misconfiguration must not be cached — a later fix must take effect on the next call' );
		}

		// -------------------------------------------------------------------
		// Degradation: no session
		// -------------------------------------------------------------------

		public function test_no_session_still_resolves_but_does_not_cache(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'resolved-without-session' );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( null );

			$record = $this->record();

			$first  = $cache->resolve_for( $plugin, $record );
			$second = $cache->resolve_for( $plugin, $record );

			$this->assertSame( 'resolved-without-session', $first );
			$this->assertSame( 'resolved-without-session', $second );
			$this->assertSame( 2, $adapter->calls, 'without a session there is nothing to cache into, so every call must re-resolve' );
			$this->assertFalse( $cache->has( $plugin, $record ) );
		}

		public function test_no_session_and_a_null_resolve_does_not_crash(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( null );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( null );

			$this->assertNull( $cache->resolve_for( $plugin, $this->record() ) );
		}

		// -------------------------------------------------------------------
		// Stored shape sanity — the raw session blob
		// -------------------------------------------------------------------

		public function test_the_stored_entry_shape_matches_the_documented_contract(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'city-77' );
			$plugin  = $this->plugin( $adapter, true, 'test_plugin' );
			$session = new Location_Resolution_Cache_Fake_Session();
			$cache   = new Location_Resolution_Cache_Probe( $session );

			$cache->resolve_for( $plugin, $this->record( 'dadata:fias-9' ) );

			$raw = $session->raw( self::STORAGE_KEY );

			$this->assertIsArray( $raw );
			$this->assertArrayHasKey( 'dadata:fias-9', $raw );
			$this->assertArrayHasKey( 'test_plugin', $raw['dadata:fias-9'] );

			$entry = $raw['dadata:fias-9']['test_plugin'];

			$this->assertSame( 'city-77', $entry['v'] );
			$this->assertTrue( $entry['ok'] );
			$this->assertIsInt( $entry['t'] );
		}

		public function test_a_null_resolve_stores_ok_false(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( null );
			$plugin  = $this->plugin( $adapter, true, 'test_plugin' );
			$session = new Location_Resolution_Cache_Fake_Session();
			$cache   = new Location_Resolution_Cache_Probe( $session );

			$cache->resolve_for( $plugin, $this->record( 'dadata:fias-9' ) );

			$entry = $session->raw( self::STORAGE_KEY )['dadata:fias-9']['test_plugin'];

			$this->assertNull( $entry['v'] );
			$this->assertFalse( $entry['ok'] );
		}

		// -------------------------------------------------------------------
		// TTL filter (FILTER_TTL) — honestly implemented: 0/default = no expiry
		// -------------------------------------------------------------------

		public function test_default_ttl_never_expires_an_entry(): void {
			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'resolved' );
			$plugin  = $this->plugin( $adapter );
			$session = new Location_Resolution_Cache_Fake_Session();
			$cache   = new Location_Resolution_Cache_Probe( $session );

			$record = $this->record();

			$cache->resolve_for( $plugin, $record );

			// Backdate the stamp far into the past directly in storage — with no
			// TTL filter hooked, this must still be served as a hit.
			$raw                                                    = $session->raw( self::STORAGE_KEY );
			$raw[ $record->key() ][ $plugin->get_id() ]['t'] = time() - ( 365 * DAY_IN_SECONDS );
			$session->set( self::STORAGE_KEY, $raw );

			$cache->resolve_for( $plugin, $record );

			$this->assertSame( 1, $adapter->calls, 'with no TTL filter hooked, an old entry must still be a hit' );
		}

		public function test_a_positive_ttl_filter_expires_a_stale_entry(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $default ) {
					return 'woodev_location_resolution_cache_ttl' === $tag ? 60 : $default;
				}
			);

			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'resolved' );
			$plugin  = $this->plugin( $adapter );
			$session = new Location_Resolution_Cache_Fake_Session();
			$cache   = new Location_Resolution_Cache_Probe( $session );

			$record = $this->record();

			$cache->resolve_for( $plugin, $record );

			$raw                                                    = $session->raw( self::STORAGE_KEY );
			$raw[ $record->key() ][ $plugin->get_id() ]['t'] = time() - 120; // older than the 60s TTL.
			$session->set( self::STORAGE_KEY, $raw );

			$cache->resolve_for( $plugin, $record );

			$this->assertSame( 2, $adapter->calls, 'an entry older than a positive TTL must be treated as expired and re-resolved' );
		}

		public function test_a_positive_ttl_filter_still_serves_a_fresh_entry(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $default ) {
					return 'woodev_location_resolution_cache_ttl' === $tag ? 3600 : $default;
				}
			);

			$adapter = new Location_Resolution_Cache_Spy_Adapter( 'resolved' );
			$plugin  = $this->plugin( $adapter );
			$cache   = new Location_Resolution_Cache_Probe( new Location_Resolution_Cache_Fake_Session() );

			$record = $this->record();

			$cache->resolve_for( $plugin, $record );
			$cache->resolve_for( $plugin, $record );

			$this->assertSame( 1, $adapter->calls, 'an entry within a positive TTL must still be a hit' );
		}
	}
}

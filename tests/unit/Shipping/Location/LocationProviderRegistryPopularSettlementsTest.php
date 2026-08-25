<?php
/**
 * Unit tests for the popular-settlements install gate on Location_Provider_Registry
 * (#488 slice 2, round 2, HIGH 1: "the store is never provisioned"): install() gets
 * called exactly once per schema-version bump, and the option write happens after a
 * real install — not merely as a side effect of the (deferred, harmless) hook
 * registration itself.
 *
 * `add_hooks()` registers `maybe_install_popular_settlements_table()` onto `init` at
 * priority 20 rather than calling it synchronously — this is the round-2 fix for the
 * ~150 unrelated unit tests round 1 broke by touching a possibly-polluted global
 * `$wpdb` mid-`add_hooks()`. This file exercises the GATE LOGIC directly (reflection,
 * bypassing the singleton's private constructor) rather than through a real `init`
 * firing, which is consistent with how dozens of existing tests already call
 * `collect()` directly instead of simulating `do_action('init')`.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';

	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;

	/**
	 * Declares CAPABILITY_RESOLVE_KEY — a D4-eligible provider (round 3, MEDIUM 3
	 * checkout-listener gate tests).
	 */
	class Checkout_Listener_Resolving_Fixture_Provider extends Abstract_Location_Provider {
		public function get_id(): string {
			return 'listener-resolving';
		}

		public function get_name(): string {
			return 'Resolving Fixture';
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_SETTLEMENT ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function resolve_key( string $key ): ?Location_Record {
			return null;
		}
	}

	/**
	 * Does NOT override resolve_key() — D4-ineligible (round 3, MEDIUM 3).
	 */
	class Checkout_Listener_Non_Resolving_Fixture_Provider extends Abstract_Location_Provider {
		public function get_id(): string {
			return 'listener-non-resolving';
		}

		public function get_name(): string {
			return 'Non-Resolving Fixture';
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_SETTLEMENT ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Location\Locality_Key;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Settings;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry::maybe_install_popular_settlements_table
	 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry::handle_checkout_order_processed_for_popular_settlements
	 */
	final class LocationProviderRegistryPopularSettlementsTest extends TestCase {

		/**
		 * Builds a registry instance WITHOUT going through the singleton
		 * (private constructor) — isolates each test from any state another test
		 * file's `declare_needed()`/`collect()` may have left on the real singleton.
		 *
		 * @param Popular_Settlement_Store|null $store injected so a version-mismatch
		 *                                              branch never touches a real
		 *                                              `\wpdb` via `install()`.
		 * @return Location_Provider_Registry
		 */
		private function registry( ?Popular_Settlement_Store $store ): Location_Provider_Registry {
			$reflection = new \ReflectionClass( Location_Provider_Registry::class );
			$registry   = $reflection->newInstanceWithoutConstructor();

			$property = $reflection->getProperty( 'popular_settlement_store' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $registry, $store );

			return $registry;
		}

		private function invoke_maybe_install( Location_Provider_Registry $registry ): void {
			$method = ( new \ReflectionClass( $registry ) )->getMethod( 'maybe_install_popular_settlements_table' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			$method->invoke( $registry );
		}

		/**
		 * When the stored schema-version option already matches the current
		 * version, install() must NOT run again — the whole point of the gate.
		 */
		public function test_does_not_reinstall_when_the_version_already_matches(): void {
			Functions\when( 'get_option' )->justReturn( '2' ); // current POPULAR_SETTLEMENTS_SCHEMA_VERSION
			Functions\expect( 'update_option' )->never();

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'install' );

			$this->invoke_maybe_install( $this->registry( $store ) );
		}

		/**
		 * When the stored version differs (including "never installed", i.e.
		 * `get_option()` returning false), install() runs exactly once and the
		 * option is updated to the current version afterwards.
		 */
		public function test_installs_once_and_records_the_new_version_when_the_version_differs(): void {
			Functions\when( 'get_option' )->justReturn( false );
			Functions\expect( 'update_option' )->once()->with(
				'woodev_popular_settlements_schema_version',
				'2'
			);

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'install' )->once();

			$this->invoke_maybe_install( $this->registry( $store ) );
		}

		/**
		 * HIGH 1/HIGH 2 reachability proof at the registration level:
		 * `add_hooks()` actually registers both new callbacks — deferred (via
		 * `add_action()`, never called synchronously), which is what keeps this safe
		 * for the ~150 pre-existing tests that call `declare_needed()`/`add_hooks()`
		 * directly without ever firing a real `init`/`woocommerce_checkout_order_processed`.
		 */
		public function test_add_hooks_registers_the_install_gate_and_the_checkout_listener(): void {
			$added = [];

			Functions\when( 'add_action' )->alias(
				static function ( $hook, $callback = null, $priority = 10, $accepted_args = 1 ) use ( &$added ) {
					$added[] = [
						'hook'   => $hook,
						'method' => is_array( $callback ) ? $callback[1] : null,
					];
				}
			);
			Functions\when( 'add_filter' )->justReturn( true );

			$reflection = new \ReflectionClass( Location_Provider_Registry::class );
			$registry   = $reflection->newInstanceWithoutConstructor();

			$registry->add_hooks();

			$this->assertContains(
				[ 'hook' => 'init', 'method' => 'maybe_install_popular_settlements_table' ],
				$added,
				'add_hooks() must register the install gate on init (deferred — never called synchronously).'
			);
			$this->assertContains(
				[ 'hook' => 'woocommerce_checkout_order_processed', 'method' => 'handle_checkout_order_processed_for_popular_settlements' ],
				$added,
				'add_hooks() must register the checkout-candidate listener.'
			);
			// The pre-existing 'init' registration for collect() must still be there too —
			// this fix must not have replaced it.
			$this->assertContains( [ 'hook' => 'init', 'method' => 'collect' ], $added );
		}

		// -------------------------------------------------------------------------
		// Round 3, MEDIUM 3: handle_checkout_order_processed_for_popular_settlements()
		// gated by the SAME D4/D4a rules Popular_Settlement_Store::enroll() enforces.
		// -------------------------------------------------------------------------

		/**
		 * Builds a registry instance WITHOUT going through the singleton, with the
		 * activation gate open, the given providers registered, and an active
		 * provider id resolvable — everything {@see Location_Provider_Registry::get_active_provider()}
		 * needs, without running the real settings-registration machinery
		 * ({@see Location_Settings} is stubbed via Mockery: {@see Location_Provider_Registry::collect()}
		 * is never called, so no real `Woodev_Abstract_Settings` behaviour is
		 * needed, only `get_value()`'s return value).
		 *
		 * @param array<int, \Woodev\Framework\Shipping\Location\Location_Provider> $providers
		 * @param string                                                            $active_provider_id
		 * @return Location_Provider_Registry
		 */
		private function registry_with( array $providers, string $active_provider_id ): Location_Provider_Registry {
			$reflection = new \ReflectionClass( Location_Provider_Registry::class );
			$registry   = $reflection->newInstanceWithoutConstructor();

			$this->set_property( $reflection, $registry, 'needed', true );

			$indexed = [];
			foreach ( $providers as $provider ) {
				$indexed[ $provider->get_id() ] = $provider;
			}
			$this->set_property( $reflection, $registry, 'providers', $indexed );

			$settings = Mockery::mock( Location_Settings::class );
			$settings->shouldReceive( 'get_value' )->with( Location_Provider_Registry::SETTING_ACTIVE_PROVIDER )->andReturn( $active_provider_id );
			$this->set_property( $reflection, $registry, 'settings_handler', $settings );

			return $registry;
		}

		private function set_property( \ReflectionClass $reflection, object $object, string $name, $value ): void {
			$property = $reflection->getProperty( $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $object, $value );
		}

		/**
		 * Stubs the logged-in path of {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store::get_chain()}
		 * with `$raw_chain` via `get_user_meta()` — a plain WordPress function, safe
		 * to stub (unlike `WC()`, which this codebase deliberately never mocks
		 * globally — see CustomerLocationStoreTest.php's own docblock — because
		 * Brain Monkey/Patchwork would leak `function_exists('WC') === true` to the
		 * rest of the PHPUnit process). `Customer_Location_Store::session()` itself
		 * safely returns null here since `WC()` genuinely does not exist anywhere in
		 * this test process, so the logged-in path falls straight through to
		 * `get_user_meta()`.
		 *
		 * @param array<string, mixed> $raw_chain
		 * @return void
		 */
		private function stub_logged_in_chain( array $raw_chain ): void {
			Functions\when( 'is_user_logged_in' )->justReturn( true );
			Functions\when( 'get_current_user_id' )->justReturn( 42 );
			Functions\when( 'get_user_meta' )->justReturn( $raw_chain );
		}

		/**
		 * D4: a provider without CAPABILITY_RESOLVE_KEY gets no popular list at all
		 * (the same rule {@see Popular_Settlement_Store::enroll()} enforces).
		 *
		 * The gate is keyed on the RECORD's own provider, so the record has to be
		 * read before the capability can even be asked about — that ordering is the
		 * point of the round-4 fix, not an accident.
		 */
		public function test_a_record_whose_own_provider_cannot_resolve_never_gets_a_candidate_stamped(): void {
			$provider = new \Checkout_Listener_Non_Resolving_Fixture_Provider();
			$registry = $this->registry_with( [ $provider ], $provider->get_id() );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'remember_candidate' );
			$this->set_property( new \ReflectionClass( Location_Provider_Registry::class ), $registry, 'popular_settlement_store', $store );

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$this->stub_logged_in_chain(
				[
					'records' => [
						Location_Record::from_array(
							[
								'key'         => Locality_Key::compose( $provider->get_id(), 'town-1' ),
								'provider_id' => $provider->get_id(),
								'level'       => Location_Record::LEVEL_SETTLEMENT,
								'country'     => 'RU',
							]
						)->to_array(),
					],
					'current' => Location_Record::LEVEL_SETTLEMENT,
				]
			);

			$registry->handle_checkout_order_processed_for_popular_settlements( 1, [], Mockery::mock( '\WC_Order' ) );
		}

		/**
		 * A level is resolved PER LEVEL with a bundled fallback, so with a
		 * non-resolving provider ACTIVE the settlement record can legitimately
		 * belong to another, resolving provider (gotcha
		 * `a-level-served-can-come-from-the-fallback-not-the-active-provider`).
		 *
		 * Gating on the active provider dropped that perfectly enrollable record.
		 * The gate belongs on the record's OWN provider — which is what
		 * {@see Popular_Settlement_Store::enroll()} does, and the two must agree.
		 */
		public function test_a_fallback_owned_record_is_stamped_even_when_the_active_provider_cannot_resolve(): void {
			$active   = new \Checkout_Listener_Non_Resolving_Fixture_Provider();
			$fallback = new \Checkout_Listener_Resolving_Fixture_Provider();
			$registry = $this->registry_with( [ $active, $fallback ], $active->get_id() );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'remember_candidate' )->once();
			$this->set_property( new \ReflectionClass( Location_Provider_Registry::class ), $registry, 'popular_settlement_store', $store );

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$this->stub_logged_in_chain(
				[
					'records' => [
						Location_Record::from_array(
							[
								'key'         => Locality_Key::compose( $fallback->get_id(), 'town-2' ),
								'provider_id' => $fallback->get_id(),
								'level'       => Location_Record::LEVEL_SETTLEMENT,
								'country'     => 'RU',
							]
						)->to_array(),
					],
					'current' => Location_Record::LEVEL_SETTLEMENT,
				]
			);

			$registry->handle_checkout_order_processed_for_popular_settlements( 1, [], Mockery::mock( '\WC_Order' ) );
		}

		/**
		 * The mirror of the case above: the ACTIVE provider can resolve, but the
		 * record belongs to one that cannot. Stamping it would leave meta that
		 * {@see Popular_Settlement_Store::enroll()} refuses — a candidate that can
		 * never become a row.
		 */
		public function test_a_record_from_a_non_resolving_provider_is_not_stamped_even_when_the_active_one_can(): void {
			$active        = new \Checkout_Listener_Resolving_Fixture_Provider();
			$non_resolving = new \Checkout_Listener_Non_Resolving_Fixture_Provider();
			$registry      = $this->registry_with( [ $active, $non_resolving ], $active->get_id() );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'remember_candidate' );
			$this->set_property( new \ReflectionClass( Location_Provider_Registry::class ), $registry, 'popular_settlement_store', $store );

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$this->stub_logged_in_chain(
				[
					'records' => [
						Location_Record::from_array(
							[
								'key'         => Locality_Key::compose( $non_resolving->get_id(), 'town-3' ),
								'provider_id' => $non_resolving->get_id(),
								'level'       => Location_Record::LEVEL_SETTLEMENT,
								'country'     => 'RU',
							]
						)->to_array(),
					],
					'current' => Location_Record::LEVEL_SETTLEMENT,
				]
			);

			$registry->handle_checkout_order_processed_for_popular_settlements( 1, [], Mockery::mock( '\WC_Order' ) );
		}

		/**
		 * D4a: a derived key (never issued by the provider) is never enrolled — and,
		 * as of round 3, never even STAMPED as a candidate, since the meta could
		 * never lead to a row.
		 */
		public function test_a_derived_key_never_gets_a_candidate_stamped(): void {
			$provider = new \Checkout_Listener_Resolving_Fixture_Provider();
			$registry = $this->registry_with( [ $provider ], $provider->get_id() );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldNotReceive( 'remember_candidate' );
			$this->set_property( new \ReflectionClass( Location_Provider_Registry::class ), $registry, 'popular_settlement_store', $store );

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$derived_key = Locality_Key::derive( $provider->get_id(), [ 'name' => 'Some Town' ] );
			$record      = Location_Record::from_array(
				[
					'key'         => $derived_key,
					'provider_id' => $provider->get_id(),
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);

			$this->stub_logged_in_chain(
				[
					'records' => [ $record->to_array() ],
					'current' => Location_Record::LEVEL_SETTLEMENT,
				]
			);

			$order = Mockery::mock( '\WC_Order' );

			$registry->handle_checkout_order_processed_for_popular_settlements( 1, [], $order );
		}

		/**
		 * The eligible path: a D4-capable provider and a genuine (non-derived) key
		 * DOES get its candidate stamped — proving the round-3 gate does not
		 * over-block the case it must still let through.
		 */
		public function test_an_eligible_settlement_still_gets_its_candidate_stamped(): void {
			$provider = new \Checkout_Listener_Resolving_Fixture_Provider();
			$registry = $this->registry_with( [ $provider ], $provider->get_id() );

			$record = Location_Record::from_array(
				[
					'key'         => $provider->get_id() . ':1',
					'provider_id' => $provider->get_id(),
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);

			$order = Mockery::mock( '\WC_Order' );

			$store = Mockery::mock( Popular_Settlement_Store::class );
			$store->shouldReceive( 'remember_candidate' )->once()->with(
				$order,
				\Mockery::on( static fn( Location_Record $candidate ): bool => $candidate->key() === $record->key() )
			);
			$this->set_property( new \ReflectionClass( Location_Provider_Registry::class ), $registry, 'popular_settlement_store', $store );

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$this->stub_logged_in_chain(
				[
					'records' => [ $record->to_array() ],
					'current' => Location_Record::LEVEL_SETTLEMENT,
				]
			);

			$registry->handle_checkout_order_processed_for_popular_settlements( 1, [], $order );
		}
	}
}

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

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
}

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry::maybe_install_popular_settlements_table
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
	}
}

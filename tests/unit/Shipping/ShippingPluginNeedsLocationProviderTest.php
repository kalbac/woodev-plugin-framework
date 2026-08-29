<?php
/**
 * Unit test for Shipping_Plugin::needs_location_provider() — the Task 3
 * opt-in a host plugin overrides to declare it needs the framework's Location
 * Provider layer.
 *
 * Constructing a real Shipping_Plugin touches a long chain of WP/WC calls
 * inside __construct()/add_hooks() (see that method's own wiring of
 * Location_Provider_Registry::instance()->declare_needed(), exercised in full
 * by {@see \Woodev\Tests\Unit\Shipping\Location\LocationProviderRegistryTest}
 * instead), so this test builds a bare stub via
 * `ReflectionClass::newInstanceWithoutConstructor()` and calls the pure
 * accessor directly — no WordPress functions involved.
 *
 * Both fixtures below are NAMED (not anonymous) classes deliberately: a `new
 * class extends ... {}` expression instantiates immediately — running straight
 * into Shipping_Plugin's real, argument-heavy constructor — which is exactly
 * what `newInstanceWithoutConstructor()` exists to avoid. A named class can be
 * reflected on without ever being `new`'d.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

/**
 * Bare fixture implementing only what PHP requires to instantiate
 * Shipping_Plugin at all — never actually `new`'d, only reflected on.
 */
class Bare_Shipping_Plugin_Fixture extends Shipping_Plugin {

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
}

/**
 * Same bare fixture, but opting IN to the location-provider layer — proves a
 * subclass override is actually consulted, not just the base default.
 */
class Opted_In_Shipping_Plugin_Fixture extends Bare_Shipping_Plugin_Fixture {

	public function needs_location_provider(): bool {
		return true;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::needs_location_provider
 */
final class ShippingPluginNeedsLocationProviderTest extends TestCase {

	public function test_default_is_false(): void {
		$instance = ( new \ReflectionClass( Bare_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		$this->assertFalse( $instance->needs_location_provider() );
	}

	public function test_a_subclass_can_opt_in(): void {
		$instance = ( new \ReflectionClass( Opted_In_Shipping_Plugin_Fixture::class ) )->newInstanceWithoutConstructor();

		$this->assertTrue( $instance->needs_location_provider() );
	}
}

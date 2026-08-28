<?php
/**
 * Unit tests for the two `?Location_Provider` filter-return guards (#613, from
 * the #599 audit).
 *
 * Both were unfixed siblings of the s100 blocker: a `?Location_Provider`-typed
 * method returning `apply_filters()` with nothing in between, so a plugin
 * returning the wrong type is a fatal `TypeError` — one while the checkout is
 * being rendered, the other on the `/location/suggest` REST endpoint the
 * checkout calls.
 *
 * `null` is a LEGITIMATE return at both sites and must survive the guard: it is
 * the documented answer for "nothing is registered" / "the chain found nobody".
 * A guard shaped `$filtered instanceof Location_Provider ? $filtered : $pre`
 * would quietly turn a deliberate `null` back into a provider, which is why the
 * audit's proposed fix for the registry was not taken verbatim.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace Woodev\Tests\Unit\Shipping\Location;

use Brain\Monkey\Functions;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Tests\Unit\TestCase;

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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';

/**
 * Declared here rather than reused from `LocationProviderRegistryTest`'s own
 * fake: a test file that depends on another file having been loaded first fails
 * in isolation for a reason that has nothing to do with what it tests.
 */
class Guard_Probe_Location_Provider extends Abstract_Location_Provider {

	private string $probe_id;

	public function __construct( string $id ) {
		$this->probe_id = $id;
	}

	public function get_id(): string {
		return $this->probe_id;
	}

	public function get_name(): string {
		return 'Guard probe ' . $this->probe_id;
	}

	public function is_configured(): bool {
		return true;
	}

	public function get_countries(): array {
		return [ 'RU' ];
	}

	protected function declare_suggest_levels(): array {
		return Location_Record::LEVELS;
	}

	public function suggest( string $query, Location_Scope $scope ): array {
		return [];
	}

	public function resolve_key( string $key ): ?Location_Record {
		return null;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Location\Location_Provider_Registry
 * @covers \Woodev\Framework\Shipping\Location\Location_Service
 */
final class LocationProviderFilterReturnGuardsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'RU', 'state' => '' ] );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
		Shipping_Settings_Tab::reset_for_tests();
	}

	protected function tearDown(): void {
		Location_Provider_Registry::instance()->reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
		Shipping_Settings_Tab::reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Stubs `apply_filters` so the provider-collection filter returns
	 * `$providers`, ONE further tag returns `$value`, and every other tag passes
	 * its own default through — which is what real WordPress does with nothing
	 * hooked.
	 *
	 * @param Abstract_Location_Provider[] $providers Providers to collect.
	 * @param string                       $tag       The tag to hijack.
	 * @param mixed                        $value     What that tag returns.
	 *
	 * @return void
	 */
	private function stub_filters( array $providers, string $tag, $value ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $fired, $default = null ) use ( $providers, $tag, $value ) {
				if ( Location_Provider_Registry::FILTER_PROVIDERS === $fired ) {
					return $providers;
				}

				return $fired === $tag ? $value : $default;
			}
		);
	}

	/**
	 * Brings the registry up the way every other test in this directory does:
	 * through the public `declare_needed()` + `collect()` path.
	 *
	 * @return Location_Provider_Registry
	 */
	private function collected_registry(): Location_Provider_Registry {
		$registry = Location_Provider_Registry::instance();
		$registry->declare_needed();
		$registry->collect();

		return $registry;
	}

	/* ------------------------------------------------------------------ *
	 * Location_Provider_Registry::resolve_active_provider_for_id(), reached
	 * through the public get_active_provider(). Checkout-render path.
	 * ------------------------------------------------------------------ */

	/**
	 * @return void
	 */
	public function test_registry_discards_a_wrongly_typed_active_provider_return(): void {
		$this->stub_filters( [], Location_Provider_Registry::FILTER_ACTIVE_PROVIDER, 'not-a-provider' );

		$active = $this->collected_registry()->get_active_provider();

		$this->assertNotSame( 'not-a-provider', $active );
		$this->assertSame( Location_Provider_Registry::DEFAULT_PROVIDER_ID, $active->get_id() );
	}

	/**
	 * The control. Without it a "guard" that simply ignored the hook would pass
	 * the test above and silently break the extension seam.
	 *
	 * @return void
	 */
	public function test_registry_honours_a_legitimate_active_provider_return(): void {
		$swapped = new Guard_Probe_Location_Provider( 'swapped-in-by-a-plugin' );

		$this->stub_filters( [], Location_Provider_Registry::FILTER_ACTIVE_PROVIDER, $swapped );

		$this->assertSame( $swapped, $this->collected_registry()->get_active_provider() );
	}

	/**
	 * `null` is a documented answer here, not a malformed one.
	 *
	 * @return void
	 */
	public function test_registry_honours_a_deliberate_null_active_provider_return(): void {
		$this->stub_filters( [], Location_Provider_Registry::FILTER_ACTIVE_PROVIDER, null );

		$this->assertNull( $this->collected_registry()->get_active_provider() );
	}

	/* ------------------------------------------------------------------ *
	 * Location_Service::provider_for_level(). REST /location/suggest path.
	 * ------------------------------------------------------------------ */

	/**
	 * @return void
	 */
	public function test_service_discards_a_wrongly_typed_provider_for_level_return(): void {
		$this->stub_filters( [], Location_Service::FILTER_PROVIDER_FOR_LEVEL, 42 );

		$resolved = ( new Location_Service( $this->collected_registry() ) )
			->provider_for_level( Location_Record::LEVEL_REGION, 'RU' );

		$this->assertNotSame( 42, $resolved );
		$this->assertTrue(
			null === $resolved || $resolved instanceof Abstract_Location_Provider,
			'the guard must yield the chain answer, never the filter garbage'
		);
	}

	/**
	 * The control.
	 *
	 * @return void
	 */
	public function test_service_honours_a_legitimate_provider_for_level_return(): void {
		$swapped = new Guard_Probe_Location_Provider( 'chosen-by-a-plugin' );

		$this->stub_filters( [], Location_Service::FILTER_PROVIDER_FOR_LEVEL, $swapped );

		$this->assertSame(
			$swapped,
			( new Location_Service( $this->collected_registry() ) )
				->provider_for_level( Location_Record::LEVEL_REGION, 'RU' )
		);
	}

	/**
	 * `null` is what the chain itself answers when nobody serves the level.
	 *
	 * @return void
	 */
	public function test_service_honours_a_deliberate_null_provider_for_level_return(): void {
		$this->stub_filters( [], Location_Service::FILTER_PROVIDER_FOR_LEVEL, null );

		$this->assertNull(
			( new Location_Service( $this->collected_registry() ) )
				->provider_for_level( Location_Record::LEVEL_REGION, 'RU' )
		);
	}
}

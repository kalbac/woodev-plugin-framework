<?php
/**
 * Yandex-shaped pilot fixture tests.
 *
 * The S1 analog of {@see EdostavkaPilotFixtureTest} (spec 7): the validation gate
 * that proves the new shipping abstraction fits the #1 reference plugin. It loads a
 * yandex-shaped pilot through the Platform v2 path and asserts every yandex
 * installed-site contract string is preserved.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Shipping_Method_Pickup;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Tests\Unit\Support\Pilot_Fixture_WP_Stubs;
use Woodev\Tests\Unit\Support\Pilot_Testable_Framework_Resolver;

/**
 * Class YandexPilotFixtureTest
 */
class YandexPilotFixtureTest extends TestCase {

	use Pilot_Fixture_WP_Stubs;

	/**
	 * The yandex-shaped pilot fixture should load through the Platform v2 path and
	 * preserve every yandex installed-site contract string: the two shipping method
	 * ids, the settings option key, the REST namespace, the warehouse table name and
	 * the order-meta prefix + chosen-pickup-point session key.
	 */
	public function test_yandex_pilot_fixture_loads_and_preserves_contract_strings(): void {
		$this->install_woocommerce_class_stubs();
		$this->mock_wordpress_runtime_functions();

		$fixture = dirname( __DIR__ ) . '/_fixtures/woodev-yandex-pilot-plugin/woodev-yandex-pilot-plugin.php';

		require_once $fixture;

		$this->assertFalse(
			class_exists( 'Woodev_Yandex_Pilot_Shipping_Plugin', false ),
			'Fixture plugin class must not be loaded before resolver invokes the callback.'
		);
		$this->assertFalse(
			class_exists( 'Woodev_Yandex_Pilot_Express_Method', false ),
			'Fixture express method class must not be loaded before the new load path runs.'
		);
		$this->assertFalse(
			class_exists( 'Woodev_Yandex_Pilot_Other_Day_Method', false ),
			'Fixture other-day method class must not be loaded before the new load path runs.'
		);

		$resolver             = new Pilot_Testable_Framework_Resolver();
		$resolver->wc_version = '7.0.0';

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\expect( 'add_filter' )
			->once()
			->with( 'woocommerce_shipping_methods', \Mockery::type( 'array' ) )
			->andReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return $value;
			}
		);

		$accepted = $resolver->register_loader_definition( \woodev_yandex_pilot_plugin_loader_definition() );

		$resolver->load_plugins();

		$plugin = \woodev_yandex_pilot_plugin();

		$this->assertTrue( $accepted, 'yandex-shaped definition accepted by resolver' );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertInstanceOf( \Woodev\Framework\Woocommerce_Plugin::class, $plugin );
		$this->assertInstanceOf( \Woodev\Framework\Shipping\Shipping_Plugin::class, $plugin );

		// The new pickup abstraction must back both yandex methods.
		$this->assertTrue( class_exists( 'Woodev_Yandex_Pilot_Express_Method', false ) );
		$this->assertTrue( class_exists( 'Woodev_Yandex_Pilot_Other_Day_Method', false ) );
		$this->assertTrue(
			is_subclass_of( 'Woodev_Yandex_Pilot_Express_Method', Shipping_Method_Pickup::class ),
			'Express method must extend the framework pickup method abstraction.'
		);
		$this->assertTrue(
			is_subclass_of( 'Woodev_Yandex_Pilot_Other_Day_Method', Shipping_Method_Pickup::class ),
			'Other-day method must extend the framework pickup method abstraction.'
		);

		// installed-site contract: the two yandex shipping method ids must be exact.
		$expected_methods = [
			'yandex_delivery_express'   => 'Woodev_Yandex_Pilot_Express_Method',
			'yandex_delivery_other_day' => 'Woodev_Yandex_Pilot_Other_Day_Method',
		];
		$this->assertSame( $expected_methods, $plugin->get_fixture_shipping_method_classes() );
		$this->assertSame(
			$expected_methods,
			$plugin->register_shipping_methods( [] ),
			'Real Shipping_Plugin registration must preserve both yandex method IDs.'
		);
		$this->assertSame( 'yandex_delivery_express', \Woodev_Yandex_Pilot_Express_Method::get_method_id() );
		$this->assertSame( 'yandex_delivery_other_day', \Woodev_Yandex_Pilot_Other_Day_Method::get_method_id() );

		// installed-site contract: settings option key preserved byte-for-byte.
		$this->assertSame( 'woocommerce_yandex_delivery_settings', $plugin->get_fixture_settings_option_name() );

		// installed-site contract: REST namespace = id-dasherized of 'yandex_delivery'.
		$this->assertSame( 'yandex-delivery', $plugin->get_id_dasherized() );

		// installed-site contract: warehouse table NAME (schema stays human-only).
		$this->assertSame( 'wc_yandex_delivery_warehouses', \Woodev_Yandex_Pilot_Warehouse_Store::TABLE_NAME );

		// installed-site contract: order-meta prefix + chosen-pickup-point session key.
		$this->assertSame( '_yandex_delivery_', \Woodev_Yandex_Pilot_Pickup_Method::META_PREFIX );
		$this->assertSame( 'chosen_yandex_pickup_point', \Woodev_Yandex_Pilot_Pickup_Method::SESSION_KEY );

		// The map-provider and pickup-source seams resolve to yandex collaborators.
		$this->assertSame( 'yandex', ( new \Woodev_Yandex_Pilot_Map_Provider() )->get_id() );

		$points = ( new \Woodev_Yandex_Pilot_Point_Source() )->search( [ 'city' => 'Moscow' ] );
		$this->assertNotEmpty( $points, 'Yandex pickup source must normalize carrier results into points.' );
		$this->assertContainsOnlyInstancesOf( Pickup_Point::class, $points );
	}
}

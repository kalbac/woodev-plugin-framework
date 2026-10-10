<?php
/**
 * Realistic shipping fixture tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\Support\Pilot_Fixture_WP_Stubs;
use Woodev\Tests\Unit\Support\Pilot_Testable_Framework_Resolver;

/**
 * Class RealisticShippingFixtureTest
 */
class RealisticShippingFixtureTest extends TestCase {

	use Pilot_Fixture_WP_Stubs;

	/**
	 * Realistic file-based shipping fixtures should load through the Platform v2 path.
	 */
	public function test_realistic_shipping_fixture_loads_through_explicit_loader_definition(): void {
		$this->install_woocommerce_class_stubs();
		$this->mock_wordpress_runtime_functions( true );

		$fixture = dirname( __DIR__ ) . '/_fixtures/woodev-realistic-shipping-plugin/woodev-realistic-shipping-plugin.php';

		require_once $fixture;

		$resolver             = new Pilot_Testable_Framework_Resolver();
		$resolver->wc_version = '7.0.0';

		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\expect( 'do_action' )->once()->with( 'woodev_plugins_loaded' );

		$accepted = $resolver->register_loader_definition( \woodev_realistic_shipping_plugin_loader_definition() );

		$resolver->load_plugins();

		$plugin = \woodev_realistic_shipping_plugin();

		$this->assertTrue( $accepted );
		$this->assertCount( 1, $resolver->get_active_plugins() );
		$this->assertInstanceOf( \Woodev\Framework\Woocommerce_Plugin::class, $plugin );
		$this->assertInstanceOf( \Woodev\Framework\Shipping\Shipping_Plugin::class, $plugin );
		$this->assertTrue( class_exists( 'Woodev_Realistic_Shipping_Method', false ) );
		$this->assertTrue( class_exists( 'Woodev_Realistic_Pickup_Shipping_Method', false ) );
		$this->assertSame(
			[
				'woodev_realistic_shipping'        => 'Woodev_Realistic_Shipping_Method',
				'woodev_realistic_pickup_shipping' => 'Woodev_Realistic_Pickup_Shipping_Method',
			],
			$plugin->get_fixture_shipping_method_classes()
		);

		// SP-10 #820 round 2, defect 2: nothing registered a provider, so the
		// framework-owned «Заказы доставки» page did not exist on the rig at all —
		// the submenu is correctly absent without one, which read as "the page was
		// never built" rather than "no carrier declared itself yet". Asserted here,
		// in the SAME test as construction, rather than a separate test method:
		// `Woodev_Realistic_Shipping_Plugin` is a process-wide singleton that
		// registers itself at most once, so a later test method could observe a
		// stale registration (or none, if an intervening test elsewhere reset the
		// registry) depending on suite order — this assertion is only meaningful
		// right after the construction that is supposed to cause it.
		if ( class_exists( '\Woodev\Framework\Shipping\Admin\Orders\Orders_Registry' ) ) {
			$provider = \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance()->get_provider( 'realistic' );

			$this->assertNotNull(
				$provider,
				'the realistic fixture must register an Orders_Provider so the orders page is reachable on the rig'
			);
			$this->assertSame( 'realistic', $provider->get_id() );
			$this->assertSame(
				[ 'woodev_realistic_shipping', 'woodev_realistic_pickup_shipping' ],
				$provider->get_method_ids(),
				'both fixture shipping methods must be declared, or type resolves to unknown for whichever one is missing'
			);

			$tracking_handler = \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance()->get_tracking_handler( 'realistic' );
			$this->assertInstanceOf( \Woodev_Realistic_Tracking_Handler::class, $tracking_handler );
			$this->assertCount( 4, $tracking_handler->get_history( 'RL200100004' ) );
			$this->assertSame( 'Отправление вручено получателю', $tracking_handler->get_history( 'RL200100004' )[3]['description'] );
			$this->assertSame( [], $tracking_handler->get_history( '' ) );

			// #1180: the fixture's «Вызвать курьера» is the rig's and the tests' action WITH input fields — it must
			// survive the framework's own re-validation whole (all four types), and only an exported order is offered it.
			Functions\when( 'wp_date' )->alias( static fn( string $format, ?int $timestamp = null ): string => gmdate( $format, $timestamp ?? time() ) );

			$exported = \Mockery::mock( '\WC_Order' );
			$exported->shouldReceive( 'get_id' )->andReturn( 5 );
			$fresh = \Mockery::mock( '\WC_Order' );
			$fresh->shouldReceive( 'get_id' )->andReturn( 6 );
			Functions\when( 'get_post_meta' )->alias( static fn( int $id ): string => 5 === $id ? 'RL-1' : '' );

			$offered = $plugin->declare_courier_call_action( [], $exported, $provider );

			$this->assertSame( 'call_courier', $offered[0]['action'] );
			$this->assertSame(
				[ 'date', 'time_range', 'select', 'textarea' ],
				array_column( \Woodev\Framework\Shipping\Admin\Orders\Order_Action_Fields::sanitize( $offered[0]['fields'] ), 'type' ),
				'nothing the fixture declares is dropped by the sanitiser'
			);
			$this->assertSame( [], $plugin->declare_courier_call_action( [], $fresh, $provider ), 'not offered before the export' );

			// #1205: the fixture carrier hands the framework's shipment-facts seam something to chew on, step by step —
			// a baseline that states «no courier, no issues», then a moved cost and date and a courier, then an issue.
			Functions\when( 'sanitize_text_field' )->returnArg();
			$baseline = $plugin->get_fixture_shipment_facts( $exported, 0 );
			$moved    = $plugin->get_fixture_shipment_facts( $exported, 1 );
			$troubled = $plugin->get_fixture_shipment_facts( $exported, 2 );

			$this->assertTrue( $baseline->reports_courier() && null === $baseline->get_courier() && [] === $baseline->get_issues() );
			$this->assertNotNull( $baseline->get_cost() );
			$this->assertGreaterThan( $baseline->get_cost()['amount'], $moved->get_cost()['amount'] );
			$this->assertNotSame( $baseline->get_delivery_date()['date'], $moved->get_delivery_date()['date'] );
			$this->assertNotNull( $moved->get_courier() );
			$this->assertSame( [], $moved->get_issues() );
			$this->assertSame( '13', $troubled->get_issues()[0]['code'] );

			// Reset so this process-wide registration does not leak into any other
			// unit test that asserts on a CLEAN Orders_Registry singleton.
			// remove_action()/remove_filter() are not among mock_wordpress_runtime_functions()'s
			// stubs (nothing else here removes a hook), so they are stubbed just for this.
			Functions\when( 'remove_action' )->justReturn( true );
			Functions\when( 'remove_filter' )->justReturn( true );
			\Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance()->reset_for_tests();
		}
	}
}

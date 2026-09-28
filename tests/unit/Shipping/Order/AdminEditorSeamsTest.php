<?php
/**
 * Unit: the seams the admin order editor reaches the framework's own writes through
 * (#710 spec D4, card #968) — on the checkout handler, the registry and the plugin base.
 *
 * Pinned: the editor's `refresh` switch re-runs the marker writers without leaking into the
 * writer's context; the persistence core fires NO checkout hook (they stay checkout-only) while
 * the admin path has its own, built with the same prefix rule; a managed field reads back the way
 * it was written; and the registry / plugin accessors the editor uses.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/order-persistence-fixtures.php';
require_once __DIR__ . '/order-marker-fakes.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::persist_values
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::pickup_field_ids
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::read_values
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::announce_admin_order_saved
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::get_provider_plugin
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::get_pickup_handler
 */
final class AdminEditorSeamsTest extends TestCase {

	private const KEY = '_test_marker';

	/** @var array<int,array{0:string,1:array<int,mixed>}> every `do_action()` call. */
	private $actions = [];

	/** @var string[] the `method_id` each marker-writer run saw. */
	private $writer_runs = [];

	/** @var array<string,mixed> keys the last writer context carried. */
	private $writer_context = [];

	protected function setUp(): void {
		parent::setUp();

		$this->actions        = [];
		$this->writer_runs    = [];
		$this->writer_context = [];

		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ) {
				$this->actions[] = [ $hook, $args ];
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) {
				Order_Marker_Fakes::$db[ (int) $id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias( [ Order_Marker_Fakes::class, 'get_post_meta' ] );

		Order_Marker_Fakes::reset();
		Location_Provider_Registry::instance()->reset_for_tests();
		Shipping_Settings_Tab::reset_for_tests();
		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Checkout_Handler::reset_native_field_registry();
		Shipping_Settings_Tab::reset_for_tests();
		Orders_Registry::instance()->reset_for_tests();
		Order_Marker_Fakes::reset();

		parent::tearDown();
	}

	private function handler( string $prefix = 'carrier' ): Checkout_Handler {
		return new Checkout_Handler(
			Checkout_Fields::from_array(
				[
					Field::create( 'carrier_pickup_point' )->mark_pickup_slot()->to_array(),
					Field::create( 'carrier_comment' )->to_array(),
					Field::create( 'billing_address_2' )->to_array(),
				]
			),
			$prefix
		);
	}

	private function register_provider(): void {
		Orders_Registry::instance()->register_provider(
			Orders_Provider::create(
				'carrier',
				'Перевозчик',
				self::KEY,
				[ 'carrier_courier' ],
				[
					'marker_writer' => function ( \WC_Order $order, array $context ): void {
						$this->writer_runs[]  = $context['method_id'];
						$this->writer_context = $context;
						$order->update_meta_data( self::KEY, '1' );
					},
				]
			)
		);
	}

	public function test_the_pickup_slot_ids_are_exposed(): void {
		$this->assertSame( [ 'carrier_pickup_point' ], $this->handler()->pickup_field_ids() );
	}

	public function test_a_saved_marker_is_left_alone_by_default_and_rewritten_on_refresh(): void {
		$this->register_provider();
		$order   = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'carrier_courier', 4 ) ] );
		$handler = $this->handler();

		$handler->persist_values( $order, [], 'carrier_courier' );
		$handler->persist_values( $order, [], 'carrier_courier' );

		$this->assertCount( 1, $this->writer_runs, 'a valid marker is not re-written by the second plugin handler of a checkout' );

		$handler->persist_values( $order, [], 'carrier_courier', null, [ 'refresh' => true ] );

		$this->assertCount( 2, $this->writer_runs, 'an edit refreshes the marker: it may be derived from the rate' );
	}

	public function test_refresh_is_the_markers_switch_not_part_of_the_writers_context(): void {
		$this->register_provider();
		$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'carrier_courier', 4 ) ] );

		$this->handler()->persist_values( $order, [], 'carrier_courier', null, [ 'refresh' => true, 'carrier_fields' => [ 'a' => 1 ] ] );

		$this->assertArrayNotHasKey( 'refresh', $this->writer_context );
		$this->assertSame( [ 'a' => 1 ], $this->writer_context['carrier_fields'] );
	}

	public function test_the_persistence_core_fires_no_checkout_hook(): void {
		$this->register_provider();
		$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'carrier_courier', 4 ) ] );

		$this->handler()->persist_values( $order, [ 'carrier_comment' => 'позвонить' ], 'carrier_courier', null, [ 'refresh' => true ] );

		$this->assertSame( [], $this->actions, 'checkout_field_saved / data_saved / processed stay CHECKOUT-ONLY' );
	}

	public function test_the_admin_hook_uses_the_same_prefix_rule_as_the_checkout_hooks(): void {
		$order = Order_Marker_Fakes::order( [] );

		$this->handler( 'carrier' )->announce_admin_order_saved( $order, [ 'carrier_comment' => 'x' ], [ 'is_update' => true ] );
		$this->handler( '' )->announce_admin_order_saved( $order, [], [ 'is_update' => false ] );

		$this->assertSame( 'woodev_shipping_carrier_admin_order_saved', $this->actions[0][0] );
		$this->assertSame( [ $order, [ 'carrier_comment' => 'x' ], [ 'is_update' => true ] ], $this->actions[0][1] );
		$this->assertSame( 'woodev_shipping_admin_order_saved', $this->actions[1][0], 'an anonymous handler falls back like hook() does' );
	}

	public function test_read_values_returns_what_persist_values_wrote_and_skips_native_fields(): void {
		$order = Order_Marker_Fakes::order( [] );

		Order_Marker_Fakes::$db[42] = [
			'carrier_pickup_point' => 'PVZ-1',
			'carrier_comment'      => '',
			'billing_address_2'    => 'native, never read as meta',
		];

		$this->assertSame( [ 'carrier_pickup_point' => 'PVZ-1' ], $this->handler()->read_values( $order ) );
	}

	public function test_the_registry_returns_the_plugin_a_provider_was_registered_with(): void {
		$plugin = Mockery::mock( Shipping_Plugin::class );

		Orders_Registry::instance()->register_provider( Orders_Provider::create( 'a', 'A', '_a', [ 'a_m' ] ), $plugin );
		Orders_Registry::instance()->register_provider( Orders_Provider::create( 'b', 'B', '_b', [ 'b_m' ] ) );

		$this->assertSame( $plugin, Orders_Registry::instance()->get_provider_plugin( 'a' ) );
		$this->assertNull( Orders_Registry::instance()->get_provider_plugin( 'b' ), 'registered without a plugin' );
		$this->assertNull( Orders_Registry::instance()->get_provider_plugin( 'ghost' ) );
	}

	public function test_a_replaced_provider_forgets_the_previous_plugin(): void {
		$plugin = Mockery::mock( Shipping_Plugin::class );

		Orders_Registry::instance()->register_provider( Orders_Provider::create( 'a', 'A', '_a', [ 'a_m' ] ), $plugin );
		Orders_Registry::instance()->register_provider( Orders_Provider::create( 'a', 'A2', '_a', [ 'a_m' ] ) );

		$this->assertNull( Orders_Registry::instance()->get_provider_plugin( 'a' ) );
	}

	public function test_a_shipping_plugin_offers_no_pickup_handler_by_default(): void {
		$plugin = Mockery::mock( Shipping_Plugin::class )->makePartial();

		$this->assertNull( $plugin->get_pickup_handler() );
	}
}

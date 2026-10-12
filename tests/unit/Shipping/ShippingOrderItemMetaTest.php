<?php
/**
 * Unit: shipping methods declare order-item meta keys hidden in WooCommerce displays.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		class WC_Shipping_Method {
			public $id;
			public array $supports = [];
			public $instance_form_fields = [];
			public $settings = [];
			public $title = '';

			public function supports( $feature ) {
				return in_array( $feature, $this->supports, true );
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping {

use Brain\Monkey\Functions;
use ReflectionMethod;
use ReflectionProperty;
use Woodev\Framework\Shipping\{Shipping_Method, Shipping_Plugin};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-method.php';

/** Carrier method declaration used by the filter tests. */
class Shipping_Order_Item_Meta_Method_Fixture extends Shipping_Method {

	public static function get_method_id(): string {
		return 'carrier_method';
	}

	public function get_delivery_type(): string {
		return 'carrier';
	}

	protected function get_method_form_fields(): array {
		return [];
	}

	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
		return null;
	}

	protected function get_plugin(): Shipping_Plugin {
		throw new \RuntimeException( 'Not used in this test.' );
	}

	public static function get_hidden_order_item_meta_keys(): array {
		return [ 'carrier_internal_meta' ];
	}
}

/** Minimal WooCommerce shipping item shape accepted by the formatter filter. */
class Shipping_Order_Item_Meta_Item_Fixture {

	public function get_method_id(): string {
		return 'carrier_method';
	}
}

/** @covers \Woodev\Framework\Shipping\Shipping_Plugin::hide_order_item_meta_from_admin */
/** @covers \Woodev\Framework\Shipping\Shipping_Plugin::hide_order_item_meta_from_formatted_display */
/** @covers \Woodev\Framework\Shipping\Shipping_Plugin::register_order_item_meta_filters */
final class ShippingOrderItemMetaTest extends TestCase {

	/** @var array<int,array{0:string,1:mixed,2:int}> */
	private array $registered_filters = [];

	protected function setUp(): void {
		parent::setUp();
		$this->registered_filters = [];
		$this->reset_filter_state();
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) {
				$this->registered_filters[] = [ $hook, $callback, $accepted_args ];
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->reset_filter_state();
		parent::tearDown();
	}

	public function test_admin_filter_hides_framework_and_carrier_keys_and_leaves_other_keys_alone(): void {
		$this->register_fixture_method();

		$hidden = Shipping_Plugin::hide_order_item_meta_from_admin( [ 'woocommerce_internal' ] );

		$this->assertSame(
			[ 'woocommerce_internal', '_woodev_quoted_services', 'carrier_internal_meta' ],
			$hidden
		);
		$this->assertNotContains( 'carrier_visible_meta', $hidden );
	}

	public function test_formatted_shipping_item_meta_hides_declared_keys_only(): void {
		$this->register_fixture_method();
		$meta = [
			1 => (object) [ 'key' => '_woodev_quoted_services', 'value' => '{}' ],
			2 => (object) [ 'key' => 'carrier_internal_meta', 'value' => 'private' ],
			3 => (object) [ 'key' => 'carrier_visible_meta', 'value' => 'shown' ],
		];

		$filtered = Shipping_Plugin::hide_order_item_meta_from_formatted_display( $meta, new Shipping_Order_Item_Meta_Item_Fixture() );

		$this->assertSame( [ 3 ], array_keys( $filtered ) );
	}

	public function test_filter_registration_is_once_for_the_process(): void {
		$register = new ReflectionMethod( Shipping_Plugin::class, 'register_order_item_meta_filters' );
		$register->setAccessible( true );
		$register->invoke( null, [ Shipping_Order_Item_Meta_Method_Fixture::class ] );
		$register->invoke( null, [] );

		$this->assertSame(
			[ 'woocommerce_hidden_order_itemmeta', 'woocommerce_order_item_get_formatted_meta_data' ],
			array_column( $this->registered_filters, 0 )
		);
	}

	private function register_fixture_method(): void {
		$register = new ReflectionMethod( Shipping_Plugin::class, 'register_order_item_meta_filters' );
		$register->setAccessible( true );
		$register->invoke( null, [ Shipping_Order_Item_Meta_Method_Fixture::class ] );
	}

	private function reset_filter_state(): void {
		foreach ( [ 'order_item_meta_filters_registered' => false, 'hidden_order_item_meta_keys' => [], 'hidden_order_item_meta_keys_by_method' => [] ] as $name => $value ) {
			$property = new ReflectionProperty( Shipping_Plugin::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}
}
}

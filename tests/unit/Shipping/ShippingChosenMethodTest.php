<?php
/**
 * Unit: a framework carrier's chosen shipping method survives a change of the rate set (#1266).
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

/** First carrier plugin's method. */
class Chosen_Method_Carrier_A_Fixture extends Shipping_Method {

	public static function get_method_id(): string {
		return 'carrier_a_pickup';
	}

	public function get_delivery_type(): string {
		return 'pickup';
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
}

/** Second carrier plugin's method (another plugin of the same process). */
class Chosen_Method_Carrier_B_Fixture extends Chosen_Method_Carrier_A_Fixture {

	public static function get_method_id(): string {
		return 'carrier_b_courier';
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::keep_chosen_carrier_method
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::register_chosen_method_filter
 */
final class ShippingChosenMethodTest extends TestCase {

	/** @var array<int,array{0:string,1:mixed,2:int,3:int}> */
	private array $registered_filters = [];

	protected function setUp(): void {
		parent::setUp();
		$this->registered_filters = [];
		$this->reset_state();
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) {
				$this->registered_filters[] = [ $hook, $callback, $priority, $accepted_args ];
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->reset_state();
		parent::tearDown();
	}

	public function test_chosen_carrier_method_is_kept_when_still_offered(): void {
		$this->register( [ Chosen_Method_Carrier_A_Fixture::class ] );

		$rates = [ 'free_shipping:1' => 'x', 'carrier_a_pickup:7' => 'x', 'carrier_a_pickup:8' => 'x' ];

		$this->assertSame( 'carrier_a_pickup:7', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, 'carrier_a_pickup:7' ) );
	}

	public function test_default_is_returned_when_the_chosen_rate_is_gone(): void {
		$this->register( [ Chosen_Method_Carrier_A_Fixture::class ] );

		$rates = [ 'free_shipping:1' => 'x', 'carrier_a_pickup:8' => 'x' ];

		$this->assertSame( 'free_shipping:1', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, 'carrier_a_pickup:7' ) );
	}

	public function test_a_non_framework_chosen_method_leaves_the_default_alone(): void {
		$this->register( [ Chosen_Method_Carrier_A_Fixture::class ] );

		$rates = [ 'free_shipping:1' => 'x', 'flat_rate:2' => 'x', 'carrier_a_pickup:7' => 'x' ];

		// e.g. a coupon made free shipping the default; the buyer's flat rate is not ours to protect.
		$this->assertSame( 'free_shipping:1', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, 'flat_rate:2' ) );
	}

	public function test_an_empty_or_unusable_chosen_method_returns_the_default(): void {
		$this->register( [ Chosen_Method_Carrier_A_Fixture::class ] );

		$rates = [ 'free_shipping:1' => 'x', 'carrier_a_pickup:7' => 'x' ];

		$this->assertSame( 'free_shipping:1', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, '' ) );
		$this->assertSame( 'free_shipping:1', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, false ) );
		$this->assertSame( 'free_shipping:1', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', 'not-an-array', 'carrier_a_pickup:7' ) );
	}

	public function test_a_method_id_that_is_only_a_prefix_of_a_carrier_id_is_not_ours(): void {
		$this->register( [ Chosen_Method_Carrier_A_Fixture::class ] );

		$rates = [ 'free_shipping:1' => 'x', 'carrier_a_pickup_extra:7' => 'x' ];

		$this->assertSame( 'free_shipping:1', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, 'carrier_a_pickup_extra:7' ) );
	}

	public function test_two_carrier_plugins_share_one_hook_and_both_are_protected(): void {
		$this->register( [ Chosen_Method_Carrier_A_Fixture::class ] );
		$this->register( [ Chosen_Method_Carrier_B_Fixture::class ] );

		$this->assertSame(
			[ [ 'woocommerce_shipping_chosen_method', [ Shipping_Plugin::class, 'keep_chosen_carrier_method' ], 10, 3 ] ],
			$this->registered_filters
		);

		$rates = [ 'free_shipping:1' => 'x', 'carrier_a_pickup:7' => 'x', 'carrier_b_courier:9' => 'x' ];

		$this->assertSame( 'carrier_a_pickup:7', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, 'carrier_a_pickup:7' ) );
		$this->assertSame( 'carrier_b_courier:9', Shipping_Plugin::keep_chosen_carrier_method( 'free_shipping:1', $rates, 'carrier_b_courier:9' ) );
	}

	/** @param string[] $classes */
	private function register( array $classes ): void {
		$register = new ReflectionMethod( Shipping_Plugin::class, 'register_chosen_method_filter' );
		if ( PHP_VERSION_ID < 80100 ) {
			$register->setAccessible( true );
		}
		$register->invoke( null, $classes );
	}

	private function reset_state(): void {
		foreach ( [ 'chosen_method_filter_registered' => false, 'carrier_method_ids' => [] ] as $name => $value ) {
			$property = new ReflectionProperty( Shipping_Plugin::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, $value );
		}
	}
}
}

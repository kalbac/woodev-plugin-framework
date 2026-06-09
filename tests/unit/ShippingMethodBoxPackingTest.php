<?php
/**
 * Behavioral tests for the box-packing seam on Shipping_Method.
 *
 * Exercises the two pure-ish protected methods added in 2.0.0 without a full
 * WooCommerce environment: WC_Shipping_Method is stubbed as an empty class and
 * a concrete fixture subclass overrides get_option(). The method instance is
 * built with ReflectionClass::newInstanceWithoutConstructor() (the real
 * constructor needs WC plugin wiring), then the protected methods are invoked
 * via reflection.
 *
 * - get_packing_algorithm() returns a valid stored algorithm and falls back to
 *   ALGORITHM_VIRTUAL for an unknown value.
 * - pack_package( [ 'contents' => [] ] ) returns null (nothing physical to pack).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		class ShippingMethodBoxPackingTest_WC_Shipping_Method_Stub {}
		class_alias( ShippingMethodBoxPackingTest_WC_Shipping_Method_Stub::class, 'WC_Shipping_Method' );
	}

	if ( ! class_exists( '\WC_Product', false ) ) {
		/**
		 * Minimal WC_Product double exposing the dimension/weight accessors that
		 * Woodev_WC_Packer_Dispatcher::from_cart_items() reads.
		 */
		class ShippingMethodBoxPackingTest_WC_Product_Stub {

			/** @var bool */
			public bool $virtual = false;

			/** @var float */
			public float $length = 0.0;

			/** @var float */
			public float $width = 0.0;

			/** @var float */
			public float $height = 0.0;

			/** @var float */
			public float $weight = 0.0;

			public function is_virtual(): bool {
				return $this->virtual;
			}

			public function get_length(): float {
				return $this->length;
			}

			public function get_width(): float {
				return $this->width;
			}

			public function get_height(): float {
				return $this->height;
			}

			public function get_weight(): float {
				return $this->weight;
			}
		}
		class_alias( ShippingMethodBoxPackingTest_WC_Product_Stub::class, 'WC_Product' );
	}

	// Box-packer interfaces + classes required by the dispatcher.
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-item-with-product.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/interfaces/interface-packer-packable-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-item-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-box-implementation.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packed-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/abstract-class-packer.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-single-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-virtual-box.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-input-item.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-package-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-result.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-packer-dispatcher.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/box-packer/class-wc-packer-dispatcher.php';

	// Shipping method under test.
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-method.php';

	/**
	 * Concrete fixture method that overrides get_option() so the box-packing
	 * seam can be exercised without WC settings storage.
	 */
	class ShippingMethodBoxPackingTest_Method extends \Woodev\Framework\Shipping\Shipping_Method {

		/** @var array<string,mixed> stored option values keyed by option name. */
		public array $stored_options = [];

		public static function get_method_id(): string {
			return 'box_packing_test_method';
		}

		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		protected function get_method_form_fields(): array {
			return [];
		}

		protected function calculate_rate( array $package ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			return null;
		}

		protected function get_plugin(): \Woodev\Framework\Shipping\Shipping_Plugin {
			throw new \LogicException( 'not needed for box-packing seam tests' );
		}

		/**
		 * Returns a stored option, or the supplied default.
		 *
		 * @param string $key           option name.
		 * @param mixed  $empty_value   default when the option is absent.
		 * @return mixed
		 */
		public function get_option( $key, $empty_value = null ) {
			return $this->stored_options[ $key ] ?? $empty_value;
		}
	}

}

namespace Woodev\Tests\Unit {

	class ShippingMethodBoxPackingTest extends TestCase {

		/**
		 * Builds a method instance without running the WC-dependent constructor.
		 *
		 * @param array<string,mixed> $options stored option values.
		 * @return \ShippingMethodBoxPackingTest_Method
		 */
		private function make_method( array $options = [] ): \ShippingMethodBoxPackingTest_Method {
			$reflection = new \ReflectionClass( \ShippingMethodBoxPackingTest_Method::class );
			/** @var \ShippingMethodBoxPackingTest_Method $method */
			$method                 = $reflection->newInstanceWithoutConstructor();
			$method->stored_options = $options;

			return $method;
		}

		/**
		 * Invokes a protected method via reflection.
		 *
		 * @param object  $object instance to invoke on.
		 * @param string  $name   protected method name.
		 * @param mixed   ...$args arguments.
		 * @return mixed
		 */
		private function invoke( object $object, string $name, ...$args ) {
			$ref = new \ReflectionMethod( $object, $name );

			// setAccessible() is required to invoke a protected method on PHP < 8.1
			// and is deprecated on 8.5+; guard so both ends of the range pass.
			if ( PHP_VERSION_ID < 80100 ) {
				$ref->setAccessible( true );
			}

			return $ref->invokeArgs( $object, $args );
		}

		public function test_get_packing_algorithm_returns_valid_stored_value(): void {
			$method = $this->make_method( [ 'packing_algorithm' => \Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY ] );

			$this->assertSame(
				\Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY,
				$this->invoke( $method, 'get_packing_algorithm' )
			);
		}

		public function test_get_packing_algorithm_falls_back_to_virtual_for_unknown_value(): void {
			$method = $this->make_method( [ 'packing_algorithm' => 'nonexistent_algo_xyz' ] );

			$this->assertSame(
				\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
				$this->invoke( $method, 'get_packing_algorithm' )
			);
		}

		public function test_get_packing_algorithm_defaults_to_virtual_when_unset(): void {
			$method = $this->make_method();

			$this->assertSame(
				\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
				$this->invoke( $method, 'get_packing_algorithm' )
			);
		}

		public function test_pack_package_returns_null_for_empty_contents(): void {
			$method = $this->make_method();

			$this->assertNull( $this->invoke( $method, 'pack_package', [ 'contents' => [] ] ) );
		}

		public function test_pack_package_returns_null_for_missing_contents(): void {
			$method = $this->make_method();

			$this->assertNull( $this->invoke( $method, 'pack_package', [] ) );
		}

		/**
		 * Runs in a separate process so the conditionally-defined WC_Product
		 * stub is declared and aliased in a clean class table — another test in
		 * the shared process may already have defined \WC_Product as an empty
		 * class, which would skip this file's stub block (Brain Monkey class
		 * pollution).
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_pack_package_packs_non_virtual_contents(): void {
			$product         = new \ShippingMethodBoxPackingTest_WC_Product_Stub();
			$product->length = 10.0;
			$product->width  = 5.0;
			$product->height = 3.0;
			$product->weight = 1.5;

			$method = $this->make_method( [ 'packing_algorithm' => \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL ] );

			$package = [
				'contents' => [
					'item_key' => [ 'data' => $product, 'quantity' => 2 ],
				],
			];

			$result = $this->invoke( $method, 'pack_package', $package );

			$this->assertInstanceOf( \Woodev_Packer_Result::class, $result );

			$data = $result->to_array();

			$this->assertSame( \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL, $data['algorithm'] );
			$this->assertNotEmpty( $data['packages'] );
			$this->assertSame( 3.0, $data['total_weight'] );
		}
	}

}

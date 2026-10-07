<?php
/**
 * A shipping method class that leaves `$method_title` empty renders as a blank card in WooCommerce's
 * «Create shipping method» modal. {@see \Woodev\Framework\Shipping\Shipping_Method::ensure_method_title()}
 * falls back to a non-empty title and reports `_doing_it_wrong()` once per class.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base (same shape as the sibling tests' stub).
		 */
		class WC_Shipping_Method {

			/** @var string */
			public $id;

			/** @var array */
			public array $supports = [];

			/**
			 * @param string $feature feature flag.
			 * @return bool
			 */
			public function supports( $feature ) {
				return in_array( $feature, $this->supports, true );
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Concrete method whose constructor is bypassed; `ensure_method_title()` is run through a public wrapper.
	 */
	class Title_Fallback_Courier_Method extends Shipping_Method {

		/** @var string */
		public $id = 'acme_courier';

		/** @var string */
		public $method_title = '';

		public function __construct() {}

		public static function get_method_id(): string {
			return 'acme_courier';
		}

		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		protected function get_method_form_fields(): array {
			return [];
		}

		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			return null;
		}

		protected function get_plugin(): \Woodev\Framework\Shipping\Shipping_Plugin {
			throw new \LogicException( 'not needed' );
		}

		public function run_ensure(): void {
			$this->ensure_method_title();
		}
	}

	/**
	 * Same, with a name the carrier chose.
	 */
	class Title_Fallback_Named_Method extends Title_Fallback_Courier_Method {

		/** @var string */
		public $method_title = 'СДЭК — курьер';
	}

	/**
	 * A method of no known delivery type: the default title is empty too.
	 */
	class Title_Fallback_Typeless_Method extends Title_Fallback_Courier_Method {

		/** @var string */
		public $id = 'acme_typeless';

		public function get_delivery_type(): string {
			return 'other';
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Shipping_Method::ensure_method_title
	 */
	final class ShippingMethodTitleFallbackTest extends TestCase {

		/** @var array<int, string> */
		private array $reported = [];

		protected function setUp(): void {
			parent::setUp();

			$this->reported = [];

			Functions\when( '_doing_it_wrong' )->alias(
				function ( $function, $message ) {
					$this->reported[] = (string) $function . ' | ' . (string) $message;
				}
			);
		}

		public function test_an_empty_method_title_falls_back_to_the_default_title_and_is_reported(): void {
			$method = new Title_Fallback_Courier_Method();

			$method->run_ensure();

			$this->assertNotSame( '', $method->method_title );
			$this->assertSame( 'Courier delivery', $method->method_title );
			$this->assertCount( 1, $this->reported );
			$this->assertStringContainsString( 'acme_courier', $this->reported[0] );
		}

		public function test_the_report_is_made_once_per_class(): void {
			( new Title_Fallback_Typeless_Method() )->run_ensure();
			( new Title_Fallback_Typeless_Method() )->run_ensure();

			$this->assertCount( 1, $this->reported );
		}

		public function test_a_method_of_unknown_type_falls_back_to_its_id(): void {
			$method = new Title_Fallback_Typeless_Method();

			$method->run_ensure();

			$this->assertSame( 'Acme_typeless', $method->method_title );
		}

		public function test_a_title_the_carrier_set_is_left_alone_and_not_reported(): void {
			$method = new Title_Fallback_Named_Method();

			$method->run_ensure();

			$this->assertSame( 'СДЭК — курьер', $method->method_title );
			$this->assertSame( [], $this->reported );
		}
	}
}

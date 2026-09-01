<?php
/**
 * Minimal WC()->shipping()->get_shipping_methods() double for CheckoutConfigTest's
 * issue #709 tests (Checkout_Config::pickup_method_ids()/resolve_required() deriving
 * real ids from is_pickup_shipping()).
 *
 * Split into its own file, bracketed-namespace style, because it needs to declare
 * `WC_Shipping_Method` in the GLOBAL namespace (the real `Shipping_Method` extends it
 * directly) — CheckoutConfigTest.php itself uses unbracketed `namespace X;` for its
 * whole file, and PHP does not allow mixing bracketed and unbracketed namespace
 * declarations in one file. Mirrors the same split
 * `ShippingMethodFilterReturnGuardsTest.php` already uses for the identical reason.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Bare-minimum WooCommerce shipping method base — only what
		 * `Shipping_Method::get_id()` (`return $this->id;`) needs.
		 */
		class WC_Shipping_Method {
			/** @var string */
			public $id;
		}
	}

	// Must run AFTER the WC_Shipping_Method stub above and BEFORE the
	// Shipping_Method-extending double below — class-shipping-method.php declares
	// `abstract class Shipping_Method extends \WC_Shipping_Method`, resolved at
	// declaration time, and the double below extends Shipping_Method itself.
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-rate.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-method.php';
}

namespace Woodev\Tests\Unit\Shipping\Checkout {

	/**
	 * A `Shipping_Method` double whose constructor bypasses the real one entirely
	 * (which needs `get_plugin()`, `init_form_fields()`, `init_settings()`, `is_admin()` —
	 * none of it relevant to `is_pickup_shipping()`/`get_id()`), mirroring
	 * `Woodev_Test_Shipping_Method_For_Guards` in `ShippingMethodFilterReturnGuardsTest.php`.
	 * `get_plugin()` is never called by `Checkout_Config::pickup_method_ids()`, so it
	 * throws rather than building an unused `Shipping_Plugin` double.
	 */
	final class Checkout_Config_Fake_Shipping_Method extends \Woodev\Framework\Shipping\Shipping_Method {

		private bool $pickup;

		public function __construct( string $id, bool $pickup ) {
			$this->id     = $id;
			$this->pickup = $pickup;
		}

		public static function get_method_id(): string {
			return 'checkout_config_fake_shipping_method';
		}

		public function get_delivery_type(): string {
			return $this->pickup ? self::TYPE_PICKUP : self::TYPE_COURIER;
		}

		protected function get_method_form_fields(): array {
			return [];
		}

		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			return null;
		}

		protected function get_plugin(): \Woodev\Framework\Shipping\Shipping_Plugin {
			throw new \RuntimeException( 'not needed by these tests' );
		}
	}
}

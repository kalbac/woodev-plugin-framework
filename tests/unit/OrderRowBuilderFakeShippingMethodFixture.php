<?php
/**
 * Minimal `WC_Shipping_Method` double for OrderRowBuilderTest's `type` resolution tests
 * (Order_Row_Builder::resolve_type() reading a real Shipping_Method's
 * is_courier_shipping()/is_pickup_shipping()/is_postal_shipping()).
 *
 * Split into its own file, bracketed-namespace style, because it needs to declare
 * `WC_Shipping_Method` in the GLOBAL namespace (the real `Shipping_Method` extends it
 * directly) — OrderRowBuilderTest.php itself uses unbracketed `namespace X;` for its
 * whole file, and PHP does not allow mixing bracketed and unbracketed namespace
 * declarations in one file. Mirrors the same split
 * `CheckoutConfigPickupMethodFixture.php` already uses for the identical reason.
 *
 * @package Woodev\Tests\Unit
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

	// Must run AFTER the WC_Shipping_Method stub above — class-shipping-method.php
	// declares `abstract class Shipping_Method extends \WC_Shipping_Method`, resolved
	// at declaration time, and the double below extends Shipping_Method itself.
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-rate.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-method.php';
}

namespace Woodev\Tests\Unit {

	/**
	 * A `Shipping_Method` double reporting a fixed delivery type. Its constructor
	 * bypasses the real one entirely (which needs `get_plugin()`, `init_form_fields()`,
	 * `init_settings()`, `is_admin()` — none of it relevant to
	 * `is_courier_shipping()`/`is_pickup_shipping()`/`is_postal_shipping()`), mirroring
	 * `Checkout_Config_Fake_Shipping_Method`.
	 */
	final class Order_Row_Builder_Fake_Shipping_Method extends \Woodev\Framework\Shipping\Shipping_Method {

		/** @var string */
		private $delivery_type;

		public function __construct( string $delivery_type ) {
			$this->delivery_type = $delivery_type;
		}

		public static function get_method_id(): string {
			return 'order_row_builder_fake_shipping_method';
		}

		public function get_delivery_type(): string {
			return $this->delivery_type;
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

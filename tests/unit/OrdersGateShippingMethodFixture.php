<?php
/**
 * Test doubles for `ShippingOrdersRegistryTest`'s `check_method_ids_contract()`
 * gate tests (card #842, round 2, critic MAJOR).
 *
 * The round-1 gate tests mocked `Shipping_Plugin::get_shipping_method_ids()`
 * directly — the very method under test, once the fix moved to
 * `get_declared_shipping_method_ids()`. This fixture gives those tests a REAL
 * `Shipping_Plugin` double whose `get_shipping_method_classes()` returns real
 * shipping-method classes, so `get_declared_shipping_method_ids()` runs its real
 * (side-effect-free, static) resolution — and a constructor that THROWS on each
 * method double, so a passing test proves nothing was constructed.
 *
 * Split into its own file, bracketed-namespace style, because it needs to declare
 * `WC_Shipping_Method` in the GLOBAL namespace (the real `Shipping_Method` extends
 * it directly) — ShippingOrdersRegistryTest.php itself uses unbracketed
 * `namespace X;` for its whole file, and PHP does not allow mixing bracketed and
 * unbracketed namespace declarations in one file. Mirrors the same split
 * `OrderRowBuilderFakeShippingMethodFixture.php` already uses for the identical
 * reason.
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
	// at declaration time, and the doubles below extend Shipping_Plugin/Shipping_Method.
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-rate.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-method.php';
}

namespace Woodev\Tests\Unit {

	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Framework\Shipping\Shipping_Plugin;

	/**
	 * Minimal Shipping_Plugin double whose declared class list is supplied by the
	 * test, so `check_method_ids_contract()` tests exercise the REAL
	 * `get_declared_shipping_method_ids()` path.
	 */
	class Woodev_Test_Shipping_Plugin_For_Orders_Gate extends Shipping_Plugin {

		/** @var class-string[] */
		private array $classes;

		/** @param class-string[] $classes shipping method classes to declare. */
		public function __construct( array $classes ) {
			$this->classes = $classes;
		}

		/** @return class-string[] */
		protected function get_shipping_method_classes(): array {
			return $this->classes;
		}

		/** @return string */
		protected function get_file() {
			return __FILE__;
		}

		/** @return string */
		public function get_plugin_name() {
			return 'Orders Gate Shipping Plugin';
		}

		/** @return int */
		public function get_download_id() {
			return 0;
		}

		/** @return string */
		public function get_id() {
			return 'orders-gate-shipping';
		}

		/** @return string */
		public function get_id_underscored() {
			return 'orders_gate_shipping';
		}

		/** @return null */
		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}
	}

	/**
	 * A shipping method whose CONSTRUCTOR THROWS — proves the gate never
	 * constructs a shipping method to read its id: `get_declared_shipping_method_ids()`
	 * derives it via `$class::get_method_id()` off the class name alone.
	 */
	abstract class Woodev_Test_Throwing_Shipping_Method_For_Orders_Gate extends Shipping_Method {

		public function __construct() {
			throw new \RuntimeException( 'must not be constructed — the gate reads get_method_id() statically' );
		}

		/** @return string */
		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return Shipping_Plugin */
		protected function get_plugin(): Shipping_Plugin {
			throw new \RuntimeException( 'not needed — this double is never constructed' );
		}

		/**
		 * @param array                      $package unused.
		 * @param \Woodev_Packer_Result|null $packed  unused.
		 * @return \Woodev\Framework\Shipping\Shipping_Rate|null
		 */
		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			throw new \RuntimeException( 'not needed — this double is never constructed' );
		}
	}

	/** Declares id `cdek_courier`, matching ShippingOrdersRegistryTest's provider fixtures. */
	class Woodev_Test_Cdek_Courier_Method extends Woodev_Test_Throwing_Shipping_Method_For_Orders_Gate {

		/** @return string */
		public static function get_method_id(): string {
			return 'cdek_courier';
		}
	}

	/** Declares id `cdek_pickup`, matching ShippingOrdersRegistryTest's provider fixtures. */
	class Woodev_Test_Cdek_Pickup_Method extends Woodev_Test_Throwing_Shipping_Method_For_Orders_Gate {

		/** @return string */
		public static function get_method_id(): string {
			return 'cdek_pickup';
		}
	}
}

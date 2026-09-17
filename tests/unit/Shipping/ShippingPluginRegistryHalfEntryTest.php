<?php
/**
 * `set_shipping_method()` used to write a half-entry for an id it had not registered yet
 * (only `shipping_method`, no `class_name`) — #818, surfaced in s124 while working #813.
 *
 * `Shipping_Method::__construct()` calls `$this->get_plugin()->set_shipping_method( $id, $this )`
 * UNCONDITIONALLY, so any code that constructs a method before its id has gone through
 * `Shipping_Plugin::add_shipping_method()` (a test, or a caller outside the normal
 * `register_shipping_methods()` flow) left the registry with an entry missing `class_name`.
 * `get_shipping_method_class_names()` then read that key with no guard at all — a warning
 * plus a `null` in the result for every such entry.
 *
 * Fixed at the writer: `set_shipping_method()` now backfills `class_name` from
 * `get_class( $shipping_method )` for an id with no entry yet, so the record it creates is
 * always complete — it never again matters whether `add_shipping_method()` ran first.
 *
 * UNIT, not integration: this exercises `Shipping_Plugin`'s own registry contract directly —
 * `add_shipping_method()`/`set_shipping_method()`/the three readers — with no real
 * `WC_Shipping_Method` construction involved (that half, the ACTUAL `Shipping_Method`
 * constructor calling into this contract, is covered by the integration test next to this
 * file's sibling `AddSupportRebuildsFormFieldsTest.php` — see
 * `ShippingPluginEarlyConstructionRegistryTest` in `tests/integration/Shipping/`).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base — same shape as the sibling stub in
		 * `ShippingMethodFilterReturnGuardsTest.php` (whichever file's guard runs first wins,
		 * so declaring it identically here keeps both correct regardless of suite order).
		 */
		class WC_Shipping_Method {

			/** @var string */
			public $id;

			/** @var array */
			public array $supports = [];

			/** @var array */
			public $instance_form_fields = [];

			/** @var array */
			public $settings = [];

			/** @var string */
			public $title = '';
		}
	}
}

namespace Woodev\Tests\Unit\Shipping {

	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Minimal Shipping_Plugin double. Bypasses the real constructor entirely, same as
	 * `Woodev_Test_Shipping_Plugin_For_Guards` in `ShippingMethodFilterReturnGuardsTest.php` —
	 * this test asserts the registry contract directly and needs none of
	 * `Woocommerce_Plugin::__construct()`'s wiring.
	 */
	class Woodev_Test_Shipping_Plugin_For_Registry extends Shipping_Plugin {

		public function __construct() {}

		/** @return array */
		protected function get_shipping_method_classes(): array {
			return [];
		}

		/** @return string */
		protected function get_file() {
			return __FILE__;
		}

		/** @return string */
		public function get_plugin_name() {
			return 'Registry Shipping Plugin';
		}

		/** @return int */
		public function get_download_id() {
			return 0;
		}

		/** @return string */
		public function get_id() {
			return 'registry-shipping';
		}

		/** @return string */
		public function get_id_underscored() {
			return 'registry_shipping';
		}

		/** @return null */
		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}
	}

	/**
	 * Minimal Shipping_Method double with a BYPASSED constructor: this test drives
	 * `Shipping_Plugin::set_shipping_method()` directly, exactly as
	 * `Shipping_Method::__construct()` would, without needing a real `WC_Shipping_Method`
	 * construction (settings API, form fields, ...) to succeed under Brain Monkey.
	 */
	class Woodev_Test_Shipping_Method_For_Registry extends Shipping_Method {

		/** @var string */
		public $id = 'registry-method';

		public function __construct() {}

		/** @return string */
		public static function get_method_id(): string {
			return 'registry-method';
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
			throw new \RuntimeException( 'not needed — this double never calls the real constructor' );
		}

		/**
		 * @param array                      $package unused.
		 * @param \Woodev_Packer_Result|null $packed  unused.
		 * @return \Woodev\Framework\Shipping\Shipping_Rate|null
		 */
		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			throw new \RuntimeException( 'not needed — this double never calculates a rate' );
		}
	}

	/**
	 * A second method class, distinct from {@see Woodev_Test_Shipping_Method_For_Registry},
	 * used only as the officially-registered class name in the "must not clobber" test — its
	 * own identity is irrelevant, only that it differs from `get_class( $method )`.
	 */
	class Woodev_Test_Other_Shipping_Method_For_Registry extends Woodev_Test_Shipping_Method_For_Registry {}

	/**
	 * @coversNothing
	 */
	final class ShippingPluginRegistryHalfEntryTest extends TestCase {

		/**
		 * The defect itself: constructing a method before its id is registered must not
		 * leave `get_shipping_method_class_names()` with a warning and a `null` element.
		 *
		 * Without the fix, `set_shipping_method()` writes only `['shipping_method' => ...]`
		 * for the unregistered id, and `get_shipping_method_class_names()`'s unguarded
		 * `$method['class_name']` read raises "Undefined array key" — which PHPUnit's error
		 * handler turns into a test ERROR, not merely a failed assertion.
		 *
		 * @return void
		 */
		public function test_set_shipping_method_backfills_class_name_for_an_unregistered_id(): void {

			$plugin = new Woodev_Test_Shipping_Plugin_For_Registry();
			$method = new Woodev_Test_Shipping_Method_For_Registry();

			// What Shipping_Method::__construct() does, unconditionally, before this id has
			// ever gone through add_shipping_method().
			$plugin->set_shipping_method( 'registry-method', $method );

			$this->assertSame(
				[ Woodev_Test_Shipping_Method_For_Registry::class ],
				$plugin->get_shipping_method_class_names(),
				'the class name must be backfilled from the instance, not left missing'
			);
		}

		/**
		 * The id must actually appear in the registry — not merely fail to warn.
		 *
		 * @return void
		 */
		public function test_an_early_constructed_method_is_a_real_registry_entry(): void {

			$plugin = new Woodev_Test_Shipping_Plugin_For_Registry();
			$method = new Woodev_Test_Shipping_Method_For_Registry();

			$plugin->set_shipping_method( 'registry-method', $method );

			$this->assertTrue( $plugin->has_shipping_method( 'registry-method' ) );
			$this->assertSame( [ 'registry-method' ], $plugin->get_shipping_method_ids() );

			$this->assertSame(
				Woodev_Test_Shipping_Method_For_Registry::class,
				$plugin->get_shipping_method_class_name( 'registry-method' ),
				'the singular reader must resolve the same backfilled class name'
			);
		}

		/**
		 * The already-constructed instance must come back unchanged — get_shipping_method()
		 * must not construct a SECOND, different instance for an id whose only entry came
		 * from an early set_shipping_method() call.
		 *
		 * @return void
		 */
		public function test_get_shipping_method_returns_the_same_early_constructed_instance(): void {

			$plugin = new Woodev_Test_Shipping_Plugin_For_Registry();
			$method = new Woodev_Test_Shipping_Method_For_Registry();

			$plugin->set_shipping_method( 'registry-method', $method );

			$this->assertSame( $method, $plugin->get_shipping_method( 'registry-method' ) );
		}

		/**
		 * The control: an id `add_shipping_method()` already registered keeps ITS class
		 * name — set_shipping_method() must only cache the instance, never overwrite an
		 * already-known class_name with `get_class()` of whatever instance happens to be
		 * passed in.
		 *
		 * @return void
		 */
		public function test_set_shipping_method_does_not_overwrite_an_already_registered_class_name(): void {

			$plugin = new Woodev_Test_Shipping_Plugin_For_Registry();
			$method = new Woodev_Test_Shipping_Method_For_Registry();

			$plugin->add_shipping_method( 'registry-method', Woodev_Test_Other_Shipping_Method_For_Registry::class );

			$plugin->set_shipping_method( 'registry-method', $method );

			$this->assertSame(
				Woodev_Test_Other_Shipping_Method_For_Registry::class,
				$plugin->get_shipping_method_class_name( 'registry-method' ),
				'a registered class_name must survive a later set_shipping_method() call'
			);
			$this->assertSame( $method, $plugin->get_shipping_method( 'registry-method' ) );
		}

		/**
		 * The positive control for the fix's own read path: a plugin with only properly
		 * registered methods (the ordinary `add_shipping_method()` flow, no early
		 * construction at all) must keep working exactly as before.
		 *
		 * @return void
		 */
		public function test_a_normally_registered_method_reports_its_class_name(): void {

			$plugin = new Woodev_Test_Shipping_Plugin_For_Registry();

			$plugin->add_shipping_method( 'registry-method', Woodev_Test_Shipping_Method_For_Registry::class );

			$this->assertSame(
				[ Woodev_Test_Shipping_Method_For_Registry::class ],
				$plugin->get_shipping_method_class_names()
			);
			$this->assertSame( [ 'registry-method' ], $plugin->get_shipping_method_ids() );
		}
	}
}

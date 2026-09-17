<?php
/**
 * `add_support()` called before `parent::__construct()` reports the wrong order — #815.
 *
 * `Shipping_Method::__construct()` assigns `$this->id` itself, so a subclass calling
 * `$this->add_support( ... )` from its OWN constructor before chaining to
 * `parent::__construct()` runs `add_support()` with `get_id()` still `''`. The action
 * `add_support()` fires is then named `woodev_shipping_method__supports_<feature>` — no id
 * segment, shared by every shipping method that declares a feature in this order (measured
 * on the rig, #813/#815).
 *
 * The fix does not change WHEN or WHETHER that action fires (queuing it was rejected on the
 * card as a contract change nobody asked for); it only reports the call via
 * `_doing_it_wrong()` under `WP_DEBUG`, naming the correct order — the same enforcement
 * shape `SubsystemHandlerContractTest.php` / `AdminNoticeHandlerContractTest.php` already use
 * for #758/#759.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base. `Shipping_Method` extends this
		 * directly (`extends \WC_Shipping_Method`). Shaped identically to the stub in
		 * `ShippingMethodCapabilityFlagsTest.php` so this file behaves the same whichever
		 * test file's `class_exists( 'WC_Shipping_Method', false )` guard wins PHPUnit's
		 * suite-collection race.
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
	use Mockery;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Minimal concrete `Shipping_Method` whose constructor takes the id to assign
	 * directly, instead of running the real `Shipping_Method::__construct()` chain — so a
	 * test can put it in EITHER state add_support() can observe: `$this->id` still `''`
	 * (the defect's precondition) or already set (the documented order).
	 */
	class Woodev_Test_Shipping_Method_For_Add_Support_Notice extends Shipping_Method {

		/** @var string */
		public $id;

		/** @param string $id shipping method id, or '' to reproduce the pre-construction state. */
		public function __construct( string $id ) {
			$this->id = $id;
		}

		/** @return string */
		public static function get_method_id(): string {
			return 'add-support-notice-method';
		}

		/** @return string */
		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/**
		 * @param array                      $package unused.
		 * @param \Woodev_Packer_Result|null $packed  unused.
		 * @return \Woodev\Framework\Shipping\Shipping_Rate|null
		 */
		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			return null;
		}

		/** @return \Woodev\Framework\Shipping\Shipping_Plugin */
		protected function get_plugin(): \Woodev\Framework\Shipping\Shipping_Plugin {
			throw new \RuntimeException( 'not needed for add_support() empty-id notice tests' );
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Shipping_Method::add_support
	 */
	final class AddSupportEmptyIdNoticeTest extends TestCase {

		/**
		 * The defect: add_support() called while get_id() is still '' must be reported,
		 * naming the exact function string the implementation passes.
		 *
		 * WP_DEBUG cannot be un-defined once set, so this runs isolated (same discipline as
		 * SubsystemHandlerContractTest.php's own WP_DEBUG-dependent tests).
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_add_support_reports_incorrect_usage_when_id_is_empty(): void {
			define( 'WP_DEBUG', true );

			Functions\expect( '_doing_it_wrong' )
				->once()
				->with(
					'Woodev\Framework\Shipping\Shipping_Method::add_support',
					Mockery::type( 'string' ),
					'2.0.2'
				);

			// The behaviour itself is UNCHANGED: the action still fires, under the same
			// empty-id name the card measured on the rig.
			Functions\expect( 'do_action' )
				->once()
				->with(
					'woodev_shipping_method__supports_cod',
					Mockery::type( Woodev_Test_Shipping_Method_For_Add_Support_Notice::class ),
					Shipping_Method::FEATURE_COD
				);

			$method = new Woodev_Test_Shipping_Method_For_Add_Support_Notice( '' );
			$method->add_support( Shipping_Method::FEATURE_COD );

			$this->assertTrue(
				$method->supports_cod(),
				'the notice reports the order, it does not change what add_support() declares'
			);
		}

		/**
		 * The documented order — add_support() called with the id already set, as it is
		 * for every call made after parent::__construct() has run — must never trip the
		 * notice, even under WP_DEBUG.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_add_support_does_not_report_when_id_is_already_set(): void {
			define( 'WP_DEBUG', true );

			Functions\expect( '_doing_it_wrong' )->never();
			Functions\when( 'do_action' )->justReturn( null );

			$method = new Woodev_Test_Shipping_Method_For_Add_Support_Notice( 'add-support-notice-method' );
			$method->add_support( Shipping_Method::FEATURE_COD );

			$this->assertTrue( $method->supports_cod() );
		}

		/**
		 * The everyday case: no WP_DEBUG at all. Kept separate from the WP_DEBUG-true
		 * control above so a regression that drops the `defined( 'WP_DEBUG' )` guard
		 * cannot hide behind that test alone.
		 */
		public function test_add_support_does_not_report_without_wp_debug(): void {
			Functions\expect( '_doing_it_wrong' )->never();
			Functions\when( 'do_action' )->justReturn( null );

			$method = new Woodev_Test_Shipping_Method_For_Add_Support_Notice( '' );
			$method->add_support( Shipping_Method::FEATURE_COD );

			$this->assertTrue( $method->supports_cod() );
		}
	}
}

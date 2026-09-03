<?php
/**
 * Tests for the three capability flags added by issue #713 —
 * `Shipping_Method::FEATURE_COD` / `FEATURE_INSURANCE` / `FEATURE_DECLARED_VALUE` and
 * their named predicates `supports_cod()` / `supports_insurance()` / `supports_declared_value()`.
 *
 * These flags follow the EXACT existing pattern of `FEATURE_BOX_PACKING` /
 * `supports_box_packing()`: a constant, a one-line predicate wrapping
 * `$this->supports( self::FEATURE_* )`, and an action fired by `add_support()`
 * ( `woodev_shipping_method_{id}_supports_{name}` ) when a method opts in.
 *
 * Deliberately does NOT cover any domain calculation (commission, insurance sum,
 * declared value) — issue #713 explicitly keeps that out of the framework.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base. `Shipping_Method` extends this
		 * directly (`extends \WC_Shipping_Method`). Shaped identically to the stub in
		 * `ShippingMethodFilterReturnGuardsTest.php` (`$supports` as `public array`,
		 * `supports()` doing a strict `in_array()`) so this file behaves the same
		 * whichever test file's `class_exists( 'WC_Shipping_Method', false )` guard
		 * wins PHPUnit's suite-collection race.
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
	 * Minimal concrete `Shipping_Method`. The real constructor pulls in a
	 * `Shipping_Plugin`, `init_form_fields()`, `init_settings()` and admin hooks that
	 * are irrelevant to a capability-flag test, so — mirroring
	 * `Woodev_Test_Shipping_Method_For_Guards` in `ShippingMethodFilterReturnGuardsTest.php`
	 * — it is bypassed entirely.
	 */
	class Woodev_Test_Shipping_Method_For_Capability_Flags extends Shipping_Method {

		/** @var string */
		public $id = 'capability-flags-method';

		public function __construct() {}

		/** @return string */
		public static function get_method_id(): string {
			return 'capability-flags-method';
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
			throw new \RuntimeException( 'not needed for capability-flag tests' );
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Shipping_Method::supports_cod
	 * @covers \Woodev\Framework\Shipping\Shipping_Method::supports_insurance
	 * @covers \Woodev\Framework\Shipping\Shipping_Method::supports_declared_value
	 * @covers \Woodev\Framework\Shipping\Shipping_Method::add_support
	 */
	final class ShippingMethodCapabilityFlagsTest extends TestCase {

		// -------------------------------------------------------------------------
		// False by default
		// -------------------------------------------------------------------------

		public function test_supports_cod_is_false_by_default(): void {
			$this->assertFalse( ( new Woodev_Test_Shipping_Method_For_Capability_Flags() )->supports_cod() );
		}

		public function test_supports_insurance_is_false_by_default(): void {
			$this->assertFalse( ( new Woodev_Test_Shipping_Method_For_Capability_Flags() )->supports_insurance() );
		}

		public function test_supports_declared_value_is_false_by_default(): void {
			$this->assertFalse( ( new Woodev_Test_Shipping_Method_For_Capability_Flags() )->supports_declared_value() );
		}

		// -------------------------------------------------------------------------
		// True after add_support()
		// -------------------------------------------------------------------------

		public function test_supports_cod_is_true_after_add_support(): void {
			Functions\when( 'do_action' )->justReturn( null );

			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();
			$method->add_support( Shipping_Method::FEATURE_COD );

			$this->assertTrue( $method->supports_cod() );
			// Declaring one flag must not implicitly declare the other two.
			$this->assertFalse( $method->supports_insurance() );
			$this->assertFalse( $method->supports_declared_value() );
		}

		public function test_supports_insurance_is_true_after_add_support(): void {
			Functions\when( 'do_action' )->justReturn( null );

			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();
			$method->add_support( Shipping_Method::FEATURE_INSURANCE );

			$this->assertTrue( $method->supports_insurance() );
			$this->assertFalse( $method->supports_cod() );
			$this->assertFalse( $method->supports_declared_value() );
		}

		public function test_supports_declared_value_is_true_after_add_support(): void {
			Functions\when( 'do_action' )->justReturn( null );

			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();
			$method->add_support( Shipping_Method::FEATURE_DECLARED_VALUE );

			$this->assertTrue( $method->supports_declared_value() );
			$this->assertFalse( $method->supports_cod() );
			$this->assertFalse( $method->supports_insurance() );
		}

		public function test_add_support_accepts_all_three_flags_at_once(): void {
			Functions\when( 'do_action' )->justReturn( null );

			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();
			$method->add_support(
				[
					Shipping_Method::FEATURE_COD,
					Shipping_Method::FEATURE_INSURANCE,
					Shipping_Method::FEATURE_DECLARED_VALUE,
				]
			);

			$this->assertTrue( $method->supports_cod() );
			$this->assertTrue( $method->supports_insurance() );
			$this->assertTrue( $method->supports_declared_value() );
		}

		// -------------------------------------------------------------------------
		// add_support() fires `woodev_shipping_method_{id}_supports_{name}`
		// -------------------------------------------------------------------------

		/**
		 * `FEATURE_COD` has no hyphen — the plain case.
		 */
		public function test_add_support_fires_the_supports_action_for_cod(): void {
			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();

			Functions\expect( 'do_action' )
				->once()
				->with( 'woodev_shipping_method_capability-flags-method_supports_cod', $method, Shipping_Method::FEATURE_COD );

			$method->add_support( Shipping_Method::FEATURE_COD );
		}

		/**
		 * `FEATURE_DECLARED_VALUE` ('declared-value') is the case that actually
		 * exercises `add_support()`'s `str_replace( '-', '_', $name )` — without it the
		 * fired action name would read `..._supports_declared-value`, a hyphen inside a
		 * WordPress hook name, which is legal but reads wrong and breaks the "same
		 * convention as every other supports_* action" claim this predicate's docblock
		 * makes.
		 */
		public function test_add_support_fires_the_supports_action_with_hyphen_normalized_to_underscore(): void {
			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();

			Functions\expect( 'do_action' )
				->once()
				->with(
					'woodev_shipping_method_capability-flags-method_supports_declared_value',
					$method,
					Shipping_Method::FEATURE_DECLARED_VALUE
				);

			$method->add_support( Shipping_Method::FEATURE_DECLARED_VALUE );
		}

		/**
		 * Calling add_support() a second time for an already-declared feature must not
		 * refire the action — `add_support()`'s own `in_array()` guard.
		 */
		public function test_add_support_does_not_refire_the_action_for_an_already_declared_feature(): void {
			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();

			// Exactly one do_action() call is expected across BOTH add_support() calls
			// below — if the guard failed and it fired twice, this expectation itself
			// fails.
			Functions\expect( 'do_action' )->once()->withAnyArgs();

			$method->add_support( Shipping_Method::FEATURE_INSURANCE );
			$method->add_support( Shipping_Method::FEATURE_INSURANCE );

			$this->assertTrue( $method->supports_insurance() );
		}

		// -------------------------------------------------------------------------
		// Backward safety: an undeclared method's behaviour is unchanged
		// -------------------------------------------------------------------------

		/**
		 * The important one (per the #713 brief): a method that never calls
		 * add_support() for any of the three new flags must behave EXACTLY as it did
		 * before this change — the predicates only ever READ `$this->supports`, they
		 * never mutate it, never throw, and never fire anything on their own.
		 */
		public function test_an_undeclared_methods_behaviour_is_completely_unchanged(): void {
			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();

			// A predicate call must not itself trigger the add_support() action —
			// checking support is not the same as declaring it.
			Functions\expect( 'do_action' )->never();

			$this->assertFalse( $method->supports_cod() );
			$this->assertFalse( $method->supports_insurance() );
			$this->assertFalse( $method->supports_declared_value() );

			// Repeated calls stay stable (no hidden state mutation from reading them).
			$this->assertFalse( $method->supports_cod() );
			$this->assertFalse( $method->supports_insurance() );
			$this->assertFalse( $method->supports_declared_value() );

			$this->assertSame( [], $method->supports );
		}

		/**
		 * A method that only ever declared the PRE-EXISTING flags (box-packing,
		 * shipping-classes) must still answer `false` for all three NEW predicates —
		 * the new flags are not implied by, or entangled with, any existing one.
		 */
		public function test_declaring_pre_existing_flags_does_not_imply_any_new_flag(): void {
			Functions\when( 'do_action' )->justReturn( null );

			$method = new Woodev_Test_Shipping_Method_For_Capability_Flags();
			$method->add_support( [ Shipping_Method::FEATURE_BOX_PACKING, Shipping_Method::FEATURE_SHIPPING_CLASSES ] );

			$this->assertTrue( $method->supports_box_packing() );
			$this->assertTrue( $method->supports_shipping_classes() );
			$this->assertFalse( $method->supports_cod() );
			$this->assertFalse( $method->supports_insurance() );
			$this->assertFalse( $method->supports_declared_value() );
		}
	}
}

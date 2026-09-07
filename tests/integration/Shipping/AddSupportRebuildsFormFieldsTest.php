<?php
/**
 * `add_support()` after construction reaches the settings screen — #813.
 *
 * The second half of #811. That one stopped the base constructor from discarding a
 * `$this->supports` the subclass had just set; this one is about the OTHER way to declare a
 * feature — the way `docs/shipping-method.md` recommends in those words:
 *
 * > `Shipping_Method::FEATURE_SHIPPING_CLASSES` -- opt-in via `add_support()`
 *
 * `Shipping_Method::init_form_fields()` runs INSIDE the constructor, so a feature declared
 * after `parent::__construct()` arrived too late to be seen by it. Measured on the rig with
 * four subclasses differing ONLY in where the declaration sits (#813, WooCommerce 11.1.0):
 *
 *   A  `$this->supports = [ … ]` before parent::__construct()   flag TRUE   control YES
 *   B  add_support( BOX_PACKING ) after parent::__construct()    flag TRUE   control NO
 *   C  add_support( BOX_PACKING ) before parent::__construct()   flag TRUE   control YES
 *   D  add_support( SHIPPING_CLASSES ) after the constructor     flag TRUE   control NO
 *
 * B and D are the documented path, and D is the feature the documentation names by hand. The
 * flag read back true while the merchant had no control to set it with, and the author of the
 * next carrier plugin (#786) would have walked into it by following the documentation.
 *
 * WHY THIS ASSERTS ON THE EXISTING FIXTURE rather than on purpose-built probe subclasses.
 * The first draft of this file declared three probe methods of its own. They reproduced the
 * defect faithfully and were the wrong tool: `Shipping_Method::__construct()` calls
 * `$this->get_plugin()->set_shipping_method( $id, $this )`, so every probe left an entry in
 * the SINGLETON test plugin's private `$methods` map — under an id that
 * `Shipping_Plugin::add_shipping_method()` had never registered, and therefore with no
 * `'class_name'` key. `get_shipping_method_class_names()` reads that key unguarded and
 * `get_shipping_method_ids()` is consumed by production code
 * (`admin/class-shipping-admin-order.php:486`). Nothing in the suite touches those paths
 * TODAY, which is exactly the kind of green that stops being green later.
 *
 * `Woodev_Test_Shipping_Method` needs no probe: it declares `FEATURE_BOX_PACKING` and does
 * NOT declare `FEATURE_SHIPPING_CLASSES`, so calling `add_support()` on a constructed
 * instance exercises the fixed path exactly — the same call, on the same kind of object, at
 * the same point in its life as case B/D above, since a subclass making that call from its
 * own constructor makes it after `parent::__construct()` has already built the form.
 *
 * INTEGRATION AND NOT UNIT, for the reason `BoxPackingSettingReachabilityTest` gives: what is
 * asserted here is the product of a real `WC_Shipping_Method` — `instance_form_fields`,
 * `get_instance_form_fields()` and the `set_defaults()` pass it runs. The unit suite's
 * WooCommerce stand-ins are hand-written stubs, so asserting there would prove the stub
 * (gotcha `a-mocked-provider-proves-the-mock-not-the-contract`).
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Tests\Integration\TestCase;

/**
 * @since 2.0.2
 */
class AddSupportRebuildsFormFieldsTest extends TestCase {

	/**
	 * Any non-zero instance id works — it is what makes WooCommerce treat the method as an
	 * instance and build `instance_form_fields` at all.
	 *
	 * @var int
	 */
	private const INSTANCE_ID = 4243;

	/**
	 * The starting position every test here depends on, asserted rather than assumed: the
	 * fixture must NOT already declare the feature, or none of this measures anything.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_fixture_does_not_declare_shipping_classes_to_begin_with(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$this->assertFalse(
			$method->supports_shipping_classes(),
			'if the fixture ever starts declaring this feature, every test below silently stops testing the rebuild'
		);

		$this->assertArrayNotHasKey( 'shipping_class_id', $method->instance_form_fields );
	}

	/**
	 * The defect itself: the documented opt-in, for the feature the documentation names.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_a_feature_declared_after_construction_builds_its_control(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$method->add_support( Shipping_Method::FEATURE_SHIPPING_CLASSES );

		$this->assertTrue(
			$method->supports_shipping_classes(),
			'the flag is the easy half — it was already true before #813'
		);

		$this->assertArrayHasKey(
			'shipping_class_id',
			$method->instance_form_fields,
			'a feature declared through add_support() must reach the settings screen, or the documented opt-in is a dead end'
		);
	}

	/**
	 * What WooCommerce itself hands the settings screen — the rebuilt array has to survive the
	 * `set_defaults()` pass, not merely exist on the property.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_rebuilt_control_is_what_woocommerce_renders(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$method->add_support( Shipping_Method::FEATURE_SHIPPING_CLASSES );

		$fields = $method->get_instance_form_fields();

		$this->assertArrayHasKey( 'shipping_class_id', $fields );
		$this->assertSame( 'select', $fields['shipping_class_id']['type'] );
		$this->assertNotSame( '', (string) $fields['shipping_class_id']['title'] );

		$this->assertArrayHasKey(
			Shipping_Method::SHIPPING_CLASS_ANY,
			$fields['shipping_class_id']['options'],
			'the default must be one of the offered options, or the select renders with nothing selected'
		);
	}

	/**
	 * The rebuild must not cost the controls that were already there.
	 *
	 * `init_form_fields()` builds from scratch, so a second run replaces the whole array. The
	 * feature the fixture declared the ordinary way has to survive that.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_rebuild_keeps_the_controls_that_were_already_built(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$method->add_support( Shipping_Method::FEATURE_SHIPPING_CLASSES );

		foreach ( [ 'title', 'description', 'packing_algorithm', 'shipping_class_id' ] as $control ) {
			$this->assertArrayHasKey(
				$control,
				$method->instance_form_fields,
				'the rebuild replaces the whole array, so everything the constructor built must come back'
			);
		}
	}

	/**
	 * Reading the setting must work without anyone having touched `instance_settings`.
	 *
	 * The rebuild happens AFTER the constructor has run `init_settings()`, so the new key is
	 * not in the saved array. `WC_Shipping_Method::get_instance_option()` falls back to the
	 * field's own default for exactly that case — verified against WooCommerce 11.1.0, and
	 * pinned here because it is the assumption the fix rests on. Note that WooCommerce reads
	 * `$form_fields[ $key ]` there with no `isset()` guard, so a flag whose control was never
	 * built is not merely invisible, it warns on read.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_rebuilt_setting_reads_back_its_default(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$method->add_support( Shipping_Method::FEATURE_SHIPPING_CLASSES );

		$this->assertSame(
			Shipping_Method::SHIPPING_CLASS_ANY,
			$method->get_instance_option( 'shipping_class_id' ),
			'a control the merchant has never saved must still read as its default'
		);
	}

	/**
	 * A feature that shapes no form must not trigger a rebuild.
	 *
	 * This is the half that keeps the fix narrow: `FEATURE_COD`, `FEATURE_INSURANCE` and
	 * `FEATURE_DECLARED_VALUE` declare intent for the host plugin and gate no control, so
	 * paying for an `init_form_fields()` run — and a second application of the
	 * `woodev_shipping_method_{id}_form_fields` filter — on their account would be waste.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_a_feature_that_shapes_no_form_does_not_rebuild_it(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$runs = 0;

		add_filter(
			'woodev_shipping_method_' . $method->get_id() . '_form_fields',
			static function ( $fields ) use ( &$runs ) {
				++$runs;

				return $fields;
			}
		);

		$method->add_support( Shipping_Method::FEATURE_COD );

		$this->assertTrue( $method->supports_cod(), 'the flag is still declared' );
		$this->assertSame( 0, $runs, 'no form-shaping feature was added, so the form must not be rebuilt' );

		$method->add_support( Shipping_Method::FEATURE_SHIPPING_CLASSES );

		$this->assertSame( 1, $runs, 'a form-shaping feature must rebuild the form exactly once' );
	}

	/**
	 * One array call naming several features rebuilds once, after ALL of them are appended.
	 *
	 * Rebuilding inside the loop would build the form against a half-declared `supports`.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_several_features_in_one_call_rebuild_once_and_at_the_end(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$runs = 0;

		add_filter(
			'woodev_shipping_method_' . $method->get_id() . '_form_fields',
			static function ( $fields ) use ( &$runs ) {
				++$runs;

				return $fields;
			}
		);

		$method->add_support( [ Shipping_Method::FEATURE_COD, Shipping_Method::FEATURE_SHIPPING_CLASSES ] );

		$this->assertSame( 1, $runs, 'one call, one rebuild — not one per feature' );
		$this->assertArrayHasKey( 'shipping_class_id', $method->instance_form_fields );
		$this->assertTrue( $method->supports_cod() );
	}

	/**
	 * Declaring a feature that is already declared changes nothing and rebuilds nothing.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_a_redundant_declaration_is_a_no_op(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$before = $method->instance_form_fields;

		$runs = 0;

		add_filter(
			'woodev_shipping_method_' . $method->get_id() . '_form_fields',
			static function ( $fields ) use ( &$runs ) {
				++$runs;

				return $fields;
			}
		);

		$method->add_support( Shipping_Method::FEATURE_BOX_PACKING );

		$this->assertSame( 0, $runs, 'the fixture already declares this one, so there is nothing to rebuild' );
		$this->assertSame( $before, $method->instance_form_fields );
	}

	/**
	 * Declaring a form-shaping feature from INSIDE the form-fields filter still lands.
	 *
	 * The nasty ordering, and the one a plugin reaches by declaring box packing conditionally
	 * on a setting it reads while filtering its own form. `add_support()` re-enters
	 * `init_form_fields()`; the inner pass builds the control and assigns it — and then the
	 * OUTER pass returns from `apply_filters()` holding the array as it was BEFORE the feature
	 * existed and assigns that over the top. The feature ends up declared with no control:
	 * exactly the defect #813 exists to remove, arrived at from the other side.
	 *
	 * Not a regression — before the rebuild existed, this path produced no control either. It
	 * is the case the fix has to also cover, or the fix is only true for callers that declare
	 * features outside a filter.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_a_feature_declared_from_inside_the_form_fields_filter_still_builds_its_control(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$declared = false;

		add_filter(
			'woodev_shipping_method_' . $method->get_id() . '_form_fields',
			static function ( $fields, $instance ) use ( &$declared ) {

				if ( ! $declared ) {
					$declared = true;
					$instance->add_support( Shipping_Method::FEATURE_SHIPPING_CLASSES );
				}

				return $fields;
			},
			10,
			2
		);

		$method->init_form_fields();

		$this->assertTrue( $method->supports_shipping_classes() );

		$this->assertArrayHasKey(
			'shipping_class_id',
			$method->instance_form_fields,
			'the outer pass must not assign its pre-feature array over the rebuild the filter triggered'
		);
	}

	/**
	 * The registry must look exactly as it did before this test class ran.
	 *
	 * The first draft of this file left three unregistered ids in the singleton plugin's
	 * `$methods` map (see the class docblock). This asserts the shape that draft broke, so the
	 * mistake cannot come back quietly.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_this_test_class_leaves_the_plugin_registry_alone(): void {

		$plugin = \Woodev_Test_Shipping_Method_Plugin::instance();

		$before = $plugin->get_shipping_method_ids();

		new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );
		new \Woodev_Test_Shipping_Method( self::INSTANCE_ID + 1 );

		$this->assertSame(
			$before,
			$plugin->get_shipping_method_ids(),
			'constructing a shipping method must not add an id the plugin never registered'
		);

		$this->assertContains(
			\Woodev_Test_Shipping_Method::METHOD_ID,
			$before,
			'the fixture id must be there to begin with, or the comparison above proves nothing'
		);
	}
}

<?php
/**
 * The realistic shipping fixture, asserted on BEHAVIOUR rather than on declaration — #814.
 *
 * `woodev-realistic-shipping-plugin` exists to be closer in shape to a production carrier than
 * `woodev-test-shipping-method` is: an abstract base with two concrete subclasses, one courier
 * and one pickup. That is exactly the shape the next carrier plugin will take (#786).
 *
 * Until #814 it was unreachable from this suite. `tests/bootstrap.php` loaded three fixtures and
 * not this one, so the only test that touched it — `RealisticShippingFixtureTest`, in the UNIT
 * suite — could `require_once` the files and assert that the classes exist and that the id → class
 * map is what was expected. It could not assert one thing about how the methods BEHAVE, because
 * the unit suite never executes a real `WC_Shipping_Method`.
 *
 * TWO THINGS WERE NEEDED, and the card only named one of them. Loading the fixture is a
 * `require_once` in `tests/bootstrap.php`; the mappings the card proposed
 * (`wp-content/plugins/…`) are what the DEV rig needs and are irrelevant here, since the suite
 * requires fixtures straight out of the repo mount. What the container additionally needed was the
 * `woodev-framework/tests/_fixtures/woodev-realistic-shipping-plugin/woodev` mirror that the other
 * three fixtures already have: `Framework_Resolver::load_plugins()` requires
 * `<plugin_path>/woodev/class-plugin.php` from whichever registered plugin it reaches first, and
 * without the mirror the bootstrap died there before a single test ran.
 *
 * Measured: the full suite is 156/567 with the fixture loaded, unchanged from without it. The
 * card's worry — that existing tests count registered methods and would break — did not
 * materialise.
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Tests\Integration\TestCase;

/**
 * @since 2.0.2
 */
class RealisticShippingFixtureBehaviourTest extends TestCase {

	/**
	 * Any non-zero instance id works — it is what makes WooCommerce treat the method as an
	 * instance and build `instance_form_fields` at all.
	 *
	 * @var int
	 */
	private const INSTANCE_ID = 5150;

	/**
	 * The fixture has to be loaded at all, asserted first so a bootstrap regression reads as
	 * itself rather than as a pile of undefined-class errors further down.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_fixture_plugin_is_loaded_in_the_integration_environment(): void {

		$this->assertTrue(
			function_exists( 'woodev_realistic_shipping_plugin' ),
			'the fixture must be required by tests/bootstrap.php, or nothing below can run'
		);

		$this->assertTrue( class_exists( '\Woodev_Realistic_Shipping_Method' ) );
		$this->assertTrue( class_exists( '\Woodev_Realistic_Pickup_Shipping_Method' ) );
	}

	/**
	 * An abstract base with two concrete subclasses survives a real `WC_Shipping_Method`.
	 *
	 * This is the whole point of the fixture and the thing the unit suite cannot say. Two levels
	 * of inheritance above `WC_Shipping_Method`, with `get_method_id()` static and overridden per
	 * subclass, is the arrangement a production carrier uses and the one where a mistake collapses
	 * both methods onto a single id.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_each_subclass_keeps_its_own_identity_through_the_shared_base(): void {

		$courier = new \Woodev_Realistic_Shipping_Method( self::INSTANCE_ID );
		$pickup  = new \Woodev_Realistic_Pickup_Shipping_Method( self::INSTANCE_ID );

		$this->assertSame( 'woodev_realistic_shipping', $courier->get_method_id() );
		$this->assertSame( 'woodev_realistic_pickup_shipping', $pickup->get_method_id() );

		$this->assertNotSame(
			$courier->get_method_id(),
			$pickup->get_method_id(),
			'a shared abstract base must not collapse two methods onto one id'
		);

		$this->assertSame( $courier->get_method_id(), $courier->id );
		$this->assertSame( $pickup->get_method_id(), $pickup->id );
	}

	/**
	 * Courier and pickup disagree, and the framework's own predicate agrees with each.
	 *
	 * `is_pickup_shipping()` is the single source of truth the other three pickup declarations
	 * are resolved from (#709). Pinning it against `get_delivery_type()` on a fixture that has
	 * BOTH shapes is what makes a future divergence visible.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_two_methods_disagree_on_delivery_type_and_the_predicate_follows(): void {

		$courier = new \Woodev_Realistic_Shipping_Method( self::INSTANCE_ID );
		$pickup  = new \Woodev_Realistic_Pickup_Shipping_Method( self::INSTANCE_ID );

		$this->assertSame( Shipping_Method::TYPE_COURIER, $courier->get_delivery_type() );
		$this->assertSame( Shipping_Method::TYPE_PICKUP, $pickup->get_delivery_type() );

		$this->assertFalse( $courier->is_pickup_shipping() );
		$this->assertTrue( $pickup->is_pickup_shipping() );
	}

	/**
	 * Both methods build a real settings form through the real WooCommerce base.
	 *
	 * `get_method_form_fields()` returns `[]` for both, so everything here comes from the
	 * framework's own `init_form_fields()` running inside a genuine `WC_Shipping_Method` — which
	 * is precisely what the unit suite's hand-written stand-ins cannot produce (gotcha
	 * `a-mocked-provider-proves-the-mock-not-the-contract`).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_both_methods_build_a_real_settings_form(): void {

		foreach ( [ new \Woodev_Realistic_Shipping_Method( self::INSTANCE_ID ), new \Woodev_Realistic_Pickup_Shipping_Method( self::INSTANCE_ID ) ] as $method ) {

			$fields = $method->get_instance_form_fields();

			$this->assertArrayHasKey( 'title', $fields, $method->get_method_id() . ' must offer a Title control' );
			$this->assertArrayHasKey( 'description', $fields );

			$this->assertNotSame(
				'',
				(string) $method->get_title(),
				'a method with no title is nameless in the zone table and on the checkout (#768)'
			);
		}
	}

	/**
	 * Neither method declares a form-shaping feature, so neither gets those controls.
	 *
	 * The negative half of #811/#813, on the fixture that models the production shape: the
	 * controls appear when a method asks for them and not otherwise. If this ever starts
	 * failing, a feature was declared somewhere without anyone deciding to.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_neither_method_declares_a_form_shaping_feature(): void {

		foreach ( [ new \Woodev_Realistic_Shipping_Method( self::INSTANCE_ID ), new \Woodev_Realistic_Pickup_Shipping_Method( self::INSTANCE_ID ) ] as $method ) {

			$this->assertFalse( $method->supports_box_packing(), $method->get_method_id() );
			$this->assertFalse( $method->supports_shipping_classes(), $method->get_method_id() );

			$this->assertArrayNotHasKey( 'packing_algorithm', $method->instance_form_fields );
			$this->assertArrayNotHasKey( 'shipping_class_id', $method->instance_form_fields );
		}
	}

	/**
	 * And #813's fix reaches this shape too — the one #786 will actually be written in.
	 *
	 * The fix was pinned on `Woodev_Test_Shipping_Method`, a direct subclass. This asserts it
	 * survives the extra inheritance level, which is the arrangement the real plugin will use.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_add_support_reaches_the_form_through_the_extra_inheritance_level(): void {

		$method = new \Woodev_Realistic_Shipping_Method( self::INSTANCE_ID );

		$this->assertArrayNotHasKey( 'shipping_class_id', $method->instance_form_fields );

		$method->add_support( Shipping_Method::FEATURE_SHIPPING_CLASSES );

		$this->assertArrayHasKey(
			'shipping_class_id',
			$method->instance_form_fields,
			'#813 must hold for a method two levels below Shipping_Method, not only one'
		);
	}

	/**
	 * The plugin registers both methods and nothing else.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_plugin_registers_exactly_its_two_methods(): void {

		$ids = woodev_realistic_shipping_plugin()->get_shipping_method_ids();

		sort( $ids );

		$this->assertSame(
			[ 'woodev_realistic_pickup_shipping', 'woodev_realistic_shipping' ],
			$ids
		);
	}
}

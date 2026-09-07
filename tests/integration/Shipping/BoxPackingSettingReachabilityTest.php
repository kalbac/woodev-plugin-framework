<?php
/**
 * The box-packing setting is reachable — #567 (rolled in from #771).
 *
 * This lives in the INTEGRATION suite deliberately. What it asserts is the product of a real
 * `WC_Shipping_Method::init_form_fields()` run — the base class merges
 * `$this->instance_form_fields` and only then does `Shipping_Method::init_form_fields()` add
 * `packing_algorithm` behind `supports_box_packing()`. The unit suite's WooCommerce stand-ins
 * are hand-written stubs, so asserting there would prove the stub rather than the contract
 * (gotcha `a-mocked-provider-proves-the-mock-not-the-contract`).
 *
 * WHY IT EXISTS AT ALL. #771 translated 114 admin strings and gated them, but the gates prove
 * a translation EXISTS, never that its wording reads well on screen; that visual pass moved to
 * #567. The example in #771's own headline — the «Алгоритм упаковки» select — turned out to be
 * unreachable on the rig, because not one fixture declared `FEATURE_BOX_PACKING`. So five
 * merchant-facing strings could not be read on screen by the pass that exists to read them.
 * `Woodev_Test_Shipping_Method` now declares the feature; this test is what keeps it declared,
 * because nothing else would notice its removal — the fixture's rates are identical either way.
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Tests\Integration\TestCase;

/**
 * @since 2.0.2
 */
class BoxPackingSettingReachabilityTest extends TestCase {

	/**
	 * Any non-zero instance id works — it is what makes WooCommerce treat the method as an
	 * instance and build `instance_form_fields` at all.
	 *
	 * @var int
	 */
	private const INSTANCE_ID = 4243;

	/**
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_test_shipping_method_declares_box_packing(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$this->assertTrue(
			$method->supports_box_packing(),
			'a fixture must declare FEATURE_BOX_PACKING or the packing settings cannot be reached on the rig'
		);
	}

	/**
	 * The framework half of the same defect, pinned separately from the fixture half.
	 *
	 * `Shipping_Method::__construct()` used to ASSIGN `$this->supports`, throwing away
	 * whatever the subclass had declared moments earlier — the WooCommerce-native idiom, and
	 * the one all four fixtures in this repo already used. It went unnoticed because every
	 * one of them declared exactly the two features the assignment put back.
	 *
	 * Measured on the rig before the fix, all three ways a plugin could try to declare it:
	 * a pre-set `$this->supports` read back FALSE; `add_support()` after construction read
	 * TRUE but built no control, because `init_form_fields()` had already run; only
	 * `add_support()` plus a manual `init_form_fields()` re-run produced one. Neither
	 * documented path worked, which made the `supports_box_packing()` and
	 * `supports_shipping_classes()` branches dead code for every plugin.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_a_subclass_declaration_survives_the_framework_constructor(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$this->assertContains(
			\Woodev\Framework\Shipping\Shipping_Method::FEATURE_BOX_PACKING,
			$method->supports,
			'a feature the subclass declared before parent::__construct() must not be discarded'
		);

		foreach ( [ 'shipping-zones', 'instance-settings' ] as $mandatory ) {
			$this->assertContains(
				$mandatory,
				$method->supports,
				'the two framework features must still be present after the merge'
			);
		}

		$this->assertSame(
			array_values( array_unique( $method->supports ) ),
			$method->supports,
			'the merge must not duplicate a feature the subclass also named'
		);

		$this->assertNotContains(
			'settings',
			$method->supports,
			"WooCommerce's bare `['settings']` default is dropped, so has_settings() is unchanged for a method with no instance id"
		);
	}

	/**
	 * The control itself, not merely the flag: the flag is what the framework reads, but the
	 * SELECT is what the translation pass has to look at.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_the_packing_algorithm_select_is_built_with_every_algorithm_label(): void {

		$method = new \Woodev_Test_Shipping_Method( self::INSTANCE_ID );

		$this->assertArrayHasKey(
			'packing_algorithm',
			$method->instance_form_fields,
			'the packing algorithm control must be part of the instance settings screen'
		);

		$field = $method->instance_form_fields['packing_algorithm'];

		$this->assertSame( 'select', $field['type'] );
		$this->assertNotSame( '', (string) $field['title'], 'the label is one of the strings the pass reads' );
		$this->assertNotSame( '', (string) $field['desc_tip'], 'so is the tooltip' );

		$this->assertSame(
			\Woodev_Packer_Dispatcher::get_algorithms(),
			$field['options'],
			'every algorithm label must reach the screen — they are three more strings of the same pass'
		);

		$this->assertArrayHasKey(
			\Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
			$field['options'],
			'the default must be one of the offered options, or the select renders with nothing selected'
		);
	}
}

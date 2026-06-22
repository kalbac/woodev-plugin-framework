<?php
/**
 * Platform-neutral setup wizard tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/**
 * Minimal neutral probe wizard (bypasses parent construction).
 */
class Neutral_Probe_Wizard extends Setup_Wizard {

	/**
	 * Skip parent wiring for isolated tests.
	 */
	public function __construct() {}

	/**
	 * Satisfies the abstract contract.
	 *
	 * @return void
	 */
	protected function register_steps(): void {}
}

/**
 * Class PlatformNeutralSetupWizardTest.
 */
class PlatformNeutralSetupWizardTest extends TestCase {

	/**
	 * The neutral base wizard defaults to a non-WooCommerce capability.
	 *
	 * @return void
	 */
	public function test_base_wizard_default_capability_is_not_wc(): void {
		$wizard = new Neutral_Probe_Wizard();

		$this->assertSame( 'manage_options', $wizard->get_required_capability() );
	}

	/**
	 * The neutral base wizard declares no WooCommerce/HPOS-named methods.
	 *
	 * @return void
	 */
	public function test_base_wizard_declares_no_woocommerce_methods(): void {
		foreach ( get_class_methods( Setup_Wizard::class ) as $method ) {
			$this->assertStringNotContainsStringIgnoringCase( 'woocommerce', $method );
			$this->assertStringNotContainsStringIgnoringCase( 'hpos', $method );
		}
	}
}

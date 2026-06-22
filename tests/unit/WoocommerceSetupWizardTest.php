<?php
/**
 * WooCommerce setup wizard tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Setup\Woocommerce_Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-woocommerce-setup-wizard.php';

/**
 * Minimal concrete WC wizard double (bypasses parent construction).
 */
class WC_Test_Wizard extends Woocommerce_Setup_Wizard {

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
 * Class WoocommerceSetupWizardTest.
 */
class WoocommerceSetupWizardTest extends TestCase {

	/**
	 * The WC wizard raises the required capability to manage_woocommerce.
	 *
	 * @return void
	 */
	public function test_capability_is_manage_woocommerce(): void {
		$wizard = new WC_Test_Wizard();

		$this->assertSame( 'manage_woocommerce', $wizard->get_required_capability() );
	}
}

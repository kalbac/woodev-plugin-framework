<?php
/**
 * Platform-neutral setup wizard tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/admin/abstract-plugin-admin-setup-wizard.php';

/**
 * Minimal setup wizard test double.
 */
class Testable_Platform_Neutral_Setup_Wizard extends \Woodev_Plugin_Setup_Wizard {

	/**
	 * Avoid parent construction for isolated helper tests.
	 */
	public function __construct() {}

	/**
	 * Satisfies the abstract setup wizard contract for isolated tests.
	 *
	 * @return void
	 */
	protected function register_steps() {}
}

/**
 * Class PlatformNeutralSetupWizardTest.
 */
class PlatformNeutralSetupWizardTest extends TestCase {

	/**
	 * Invalid step registration should use WordPress doing_it_wrong() without WooCommerce.
	 *
	 * @return void
	 */
	public function test_register_step_error_path_uses_wordpress_doing_it_wrong(): void {
		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				'Woodev_Plugin_Setup_Wizard::register_step',
				'Invalid step ID',
				'1.8.0'
			);

		$wizard = new Testable_Platform_Neutral_Setup_Wizard();

		$this->assertFalse(
			$wizard->register_step(
				'',
				'General',
				static function (): void {}
			)
		);
	}
}

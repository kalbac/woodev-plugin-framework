<?php
/**
 * Setup_Wizard completion-state tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/**
 * Minimal concrete wizard used only in this test file.
 */
class State_Test_Wizard extends Setup_Wizard {
	public function __construct() {}
	protected function register_steps(): void {}
	public function get_id(): string { return 'acme'; }
}

/**
 * Tests for Setup_Wizard completion-state tracking.
 *
 * @covers \Woodev\Framework\Setup\Setup_Wizard
 */
class SetupWizardStateTest extends TestCase {

	public function test_is_complete_reads_option(): void {
		Functions\expect( 'get_option' )
			->once()->with( 'woodev_acme_setup_wizard_complete', '' )
			->andReturn( 'completed' );

		$wizard = new State_Test_Wizard();
		$this->assertTrue( $wizard->is_complete() );
		$this->assertFalse( $wizard->is_skipped() );
	}

	public function test_complete_setup_writes_option(): void {
		Functions\expect( 'update_option' )
			->once()->with( 'woodev_acme_setup_wizard_complete', 'skipped' );

		$wizard = new State_Test_Wizard();
		$wizard->complete_setup( 'skipped' );
	}
}

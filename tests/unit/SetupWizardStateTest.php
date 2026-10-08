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
		Functions\expect( 'get_option' )->once()->with( 'woodev_acme_setup_wizard_complete', '' )->andReturn( '' );
		Functions\expect( 'update_option' )
			->once()->with( 'woodev_acme_setup_wizard_complete', 'skipped' );

		$wizard = new State_Test_Wizard();
		$this->assertSame( 'skipped', $wizard->complete_setup( 'skipped' ) );
	}

	public function test_complete_setup_updates_cache_without_a_second_read(): void {
		// Writing `completed` is always allowed: no stored-value read, and the cache is set from the write.
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->once();

		$wizard = new State_Test_Wizard();
		$wizard->complete_setup( 'completed' );

		$this->assertTrue( $wizard->is_complete() );
	}

	// D1 — the state only moves forward.

	public function test_completed_is_never_overwritten_by_skipped(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'completed' );
		Functions\expect( 'update_option' )->never();

		$wizard = new State_Test_Wizard();

		$this->assertSame( 'completed', $wizard->complete_setup( 'skipped' ) );
		$this->assertTrue( $wizard->is_complete() );
		$this->assertFalse( $wizard->is_skipped() );
	}

	public function test_skipped_may_later_become_completed(): void {
		// The skipped state is what the option holds; `completed` replaces it.
		Functions\expect( 'get_option' )->never(); // the guard reads the stored value only when `skipped` is requested.
		Functions\expect( 'update_option' )->once()->with( 'woodev_acme_setup_wizard_complete', 'completed' );

		$wizard = new State_Test_Wizard();

		$this->assertSame( 'completed', $wizard->complete_setup( 'completed' ) );
		$this->assertTrue( $wizard->is_complete() );
	}

	public function test_completed_stays_completed_on_a_repeated_completed(): void {
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->once()->with( 'woodev_acme_setup_wizard_complete', 'completed' );

		$wizard = new State_Test_Wizard();

		$this->assertSame( 'completed', $wizard->complete_setup( 'completed' ) );
	}

	// D1 — «Настрою позже» keeps the wizard reachable from the notice / plugin-row link.

	public function test_a_skipped_wizard_still_offers_its_action_link(): void {
		Functions\when( 'get_option' )->justReturn( 'skipped' );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );

		$wizard = new State_Test_Wizard();

		$this->assertCount( 1, $wizard->add_action_link( [] ) );
	}

	public function test_a_completed_wizard_drops_its_action_link(): void {
		Functions\when( 'get_option' )->justReturn( 'completed' );

		$wizard = new State_Test_Wizard();

		$this->assertSame( [], $wizard->add_action_link( [] ) );
	}
}

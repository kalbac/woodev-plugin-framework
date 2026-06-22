<?php
/**
 * Setup_Wizard first-install trigger / redirect guard tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/**
 * Minimal concrete wizard that bypasses the real constructor and exposes
 * the protected guard method for isolated unit testing.
 */
class Trigger_Test_Wizard extends Setup_Wizard {
	public bool $complete = false;
	public function __construct() {}
	protected function register_steps(): void {}
	public function get_id(): string { return 'acme'; }
	public function is_finished(): bool { return $this->complete; }
	// expose the guard
	public function should() : bool { return $this->should_redirect_on_admin_init(); }
}

/**
 * Tests for Setup_Wizard first-install redirect guard.
 *
 * @covers \Woodev\Framework\Setup\Setup_Wizard
 */
class SetupWizardTriggerTest extends TestCase {

	private function base_env(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( 1 );
	}

	public function test_redirects_for_single_fresh_install(): void {
		$this->base_env();
		$_GET = [];
		$wizard = new Trigger_Test_Wizard();
		$this->assertTrue( $wizard->should() );
	}

	public function test_no_redirect_on_bulk_activation(): void {
		$get_backup = $_GET;
		$this->base_env();
		$_GET = [ 'activate-multi' => '1' ];
		$wizard = new Trigger_Test_Wizard();
		$this->assertFalse( $wizard->should() );
		$_GET = $get_backup;
	}

	public function test_no_redirect_when_already_finished(): void {
		$this->base_env();
		$_GET = [];
		$wizard = new Trigger_Test_Wizard();
		$wizard->complete = true;
		$this->assertFalse( $wizard->should() );
	}

	public function test_no_redirect_without_transient(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		$_GET = [];
		$wizard = new Trigger_Test_Wizard();
		$this->assertFalse( $wizard->should() );
	}

	public function test_no_redirect_without_capability(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( 1 );
		$_GET = [];
		$wizard = new Trigger_Test_Wizard();
		$this->assertFalse( $wizard->should() );
	}
}

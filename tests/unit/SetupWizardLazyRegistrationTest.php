<?php
/**
 * Setup wizard lazy step registration tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

class Lazy_Registration_Test_Wizard extends Setup_Wizard {
	public int $register_calls = 0;

	protected function register_steps(): void {
		$this->register_calls++;
		$this->register_content_step( 'welcome', 'Welcome', static function (): string { return ''; } );
	}

	public function get_id(): string {
		return 'lazy-registration';
	}
}

/** @covers \Woodev\Framework\Setup\Setup_Wizard */
class SetupWizardLazyRegistrationTest extends TestCase {

	public function test_constructor_defers_steps_until_first_access_and_registers_only_once(): void {
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugin_basename' )->justReturn( 'acme/plugin.php' );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'lazy-registration' );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( '/plugins/acme/plugin.php' );

		$wizard = new Lazy_Registration_Test_Wizard( $plugin );

		$this->assertSame( 0, $wizard->register_calls );
		$this->assertTrue( $wizard->has_steps() );
		$this->assertSame( 1, $wizard->register_calls );

		$wizard->initialize();
		$this->assertSame( 1, $wizard->register_calls );
	}
}

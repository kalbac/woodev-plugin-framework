<?php
/**
 * Setup wizard lazy step registration tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
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

	public function test_constructor_registers_hooks_without_building_steps_until_first_access(): void {
		Actions\expectAdded( 'woodev_lazy-registration_installed' )->once();
		Actions\expectAdded( 'admin_init' )->twice();
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'rest_api_init' )->once();
		Filters\expectAdded( 'plugin_action_links_acme/plugin.php' )->once();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugin_basename' )->justReturn( 'acme/plugin.php' );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'lazy-registration' );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( '/plugins/acme/plugin.php' );

		$wizard = new Lazy_Registration_Test_Wizard( $plugin );

		$this->assertSame( 0, $wizard->register_calls );

		$this->assertTrue( $wizard->has_steps() );
		$this->assertSame( 1, $wizard->register_calls );
		$this->assertTrue( $wizard->has_steps() );
		$this->assertSame( 1, $wizard->register_calls );
	}
}

<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Setup\Setup_Wizard;
use Woodev\Framework\Setup\Step;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/** Minimal concrete wizard for registry tests (no plugin construction). */
class Registry_Test_Wizard extends Setup_Wizard {
	public array $declared = [];
	public function __construct() {}                       // bypass parent wiring
	public function get_id(): string { return 'reg'; }     // no plugin in this double
	protected function register_steps(): void {
		foreach ( $this->declared as $id => $kind ) {
			if ( 'content' === $kind ) {
				$this->register_content_step( $id, $id, static function (): string { return ''; } );
			} else {
				$this->register_step( $id, $id, [ $id . '_field' ] );
			}
		}
	}
	public function expose_build_steps(): void { $this->build_steps(); } // test hook
}

class SetupWizardRegistryTest extends TestCase {

	public function test_registers_and_orders_steps(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 ); // return $steps unchanged
		$wizard = new Registry_Test_Wizard();
		$wizard->declared = [ 'welcome' => 'content', 'connection' => 'settings' ];
		$wizard->expose_build_steps();

		$this->assertTrue( $wizard->has_steps() );
		$this->assertSame( [ 'welcome', 'connection' ], array_keys( $wizard->get_steps() ) );
		$this->assertInstanceOf( Step::class, $wizard->get_steps()['connection'] );
	}

	public function test_capability_default_is_neutral(): void {
		$wizard = new Registry_Test_Wizard();
		$this->assertSame( 'manage_options', $wizard->get_required_capability() );
	}
}

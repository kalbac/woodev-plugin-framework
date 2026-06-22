<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Step;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-setup.php';

class SetupWizardRestControllerTest extends TestCase {

	public function test_permission_check_uses_wizard_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'get_required_capability' )->andReturn( 'manage_options' );

		$controller = new \Woodev_REST_API_Setup( $wizard );
		$this->assertFalse( $controller->permissions_check() );
	}

	public function test_complete_sets_state_and_returns_ok(): void {
		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'complete_setup' )->once()->with( 'completed' );

		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'state' )->andReturn( 'completed' );

		$controller = new \Woodev_REST_API_Setup( $wizard );
		$response   = $controller->complete( $request );

		$this->assertSame( [ 'complete' => true, 'state' => 'completed' ], $response );
	}

	public function test_save_step_persists_values_and_returns_ok(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'K' );

		$plugin = Mockery::mock( '\Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'get_steps' )->andReturn(
			[ 'connection' => Step::settings( 'connection', 'C', [ 'api_key' ] ) ]
		);
		$wizard->shouldReceive( 'get_plugin' )->andReturn( $plugin );

		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'step_id' )->andReturn( 'connection' );
		$request->shouldReceive( 'get_param' )->with( 'values' )->andReturn( [ 'api_key' => 'K' ] );

		$controller = new \Woodev_REST_API_Setup( $wizard );
		$response   = $controller->save_step( $request );

		$this->assertSame( [ 'saved' => true, 'step' => 'connection' ], $response );
	}
}

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
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
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

	public function test_save_step_passes_only_step_fields_to_on_save(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$captured = null;
		$on_save  = static function ( $values ) use ( &$captured ): void {
			$captured = $values;
		};

		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
		// Only the declared field is ever persisted.
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'K' );

		$plugin = Mockery::mock( '\Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'get_steps' )->andReturn(
			[ 'connection' => Step::settings( 'connection', 'C', [ 'api_key' ], $on_save ) ]
		);
		$wizard->shouldReceive( 'get_plugin' )->andReturn( $plugin );

		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'step_id' )->andReturn( 'connection' );
		// A crafted request smuggles an extra, undeclared key.
		$request->shouldReceive( 'get_param' )->with( 'values' )->andReturn(
			[
				'api_key' => 'K',
				'evil'    => 'X',
			]
		);

		$controller = new \Woodev_REST_API_Setup( $wizard );
		$controller->save_step( $request );

		// on_save must receive ONLY the step's declared field, never 'evil'.
		$this->assertSame( [ 'api_key' => 'K' ], $captured );
	}
	// -----------------------------------------------------------------------
	// Issue #397 — the wizard's per-field server errors never reached a field.
	//
	// `save_step()` returned `[ 'field' => $sid ]` while `src/setup-wizard/app.js`'s
	// `goNext()` reads `err.data.errors` — a MAP of id => message. The map was `null` on
	// every response, so `setFieldErrors()` was never called from the server side at all,
	// even though everything below it was already wired: `app.js` passes `serverErrors` to
	// `StepView`, which puts each message on `schema.serverError` for its field.
	//
	// `errors` is also what this framework's OTHER settings surface returns
	// (`Woodev_REST_API_Settings_Page::save()`'s `woodev_settings_invalid`), so the wizard
	// was the outlier, not the client.
	// -----------------------------------------------------------------------

	/**
	 * Builds a wizard whose handler rejects `$failing_id`.
	 *
	 * @param string $failing_id setting id that fails validation.
	 * @param string $message    the validation message.
	 * @return \Woodev_REST_API_Setup
	 */
	private function make_rejecting_controller( string $failing_id, string $message ): \Woodev_REST_API_Setup {
		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
		$handler->shouldReceive( 'update_value' )->andReturnUsing(
			static function ( $id ) use ( $failing_id, $message ): void {
				if ( $id === $failing_id ) {
					throw new \Woodev_Plugin_Exception( $message, 400 );
				}
			}
		);

		$plugin = Mockery::mock( '\Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'get_steps' )->andReturn(
			[ 'connection' => Step::settings( 'connection', 'C', [ 'api_key', 'token' ] ) ]
		);
		$wizard->shouldReceive( 'get_plugin' )->andReturn( $plugin );

		return new \Woodev_REST_API_Setup( $wizard );
	}

	/**
	 * @return \WP_REST_Request&\Mockery\MockInterface
	 */
	private function make_request( array $values ) {
		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'step_id' )->andReturn( 'connection' );
		$request->shouldReceive( 'get_param' )->with( 'values' )->andReturn( $values );

		return $request;
	}

	public function test_save_step_reports_a_rejected_setting_under_the_errors_key(): void {
		$controller = $this->make_rejecting_controller( 'token', 'Неверный токен.' );
		$result     = $controller->save_step( $this->make_request( [ 'api_key' => 'K', 'token' => 'bad' ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_setup_invalid', $result->get_error_code() );

		$data = $result->get_error_data();

		// The whole issue: a MAP the client can index by setting id.
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertSame( [ 'token' => 'Неверный токен.' ], $data['errors'] );
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * The key the client reads is `errors` and nothing else. `field` carried the same fact
	 * under a name no reader knew; two keys for one fact is how the two sides drifted apart
	 * in the first place.
	 */
	public function test_save_step_does_not_also_report_the_legacy_field_key(): void {
		$controller = $this->make_rejecting_controller( 'token', 'Неверный токен.' );
		$result     = $controller->save_step( $this->make_request( [ 'token' => 'bad' ] ) );

		$this->assertArrayNotHasKey( 'field', $result->get_error_data() );
	}

	/**
	 * The message on the map is the SAME string as the error message itself, so a client that
	 * shows only the banner and one that highlights the field never disagree about the wording.
	 */
	public function test_the_mapped_message_matches_the_error_message(): void {
		$controller = $this->make_rejecting_controller( 'token', 'Неверный токен.' );
		$result     = $controller->save_step( $this->make_request( [ 'token' => 'bad' ] ) );

		$this->assertSame( $result->get_error_message(), $result->get_error_data()['errors']['token'] );
	}

	/**
	 * The control: a step whose settings all validate still returns the success payload, with
	 * no error data at all. Without it, the assertions above would pass for a controller that
	 * had started refusing everything.
	 */
	public function test_control_a_valid_step_still_saves(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$controller = $this->make_rejecting_controller( 'nothing-fails', 'unused' );
		$response   = $controller->save_step( $this->make_request( [ 'api_key' => 'K', 'token' => 'good' ] ) );

		$this->assertSame( [ 'saved' => true, 'step' => 'connection' ], $response );
	}
}

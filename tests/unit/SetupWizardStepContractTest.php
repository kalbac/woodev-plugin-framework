<?php
/**
 * Setup wizard step contract (D2): skippable, validation before persistence, actions, Throwable.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Action_Outcome;
use Woodev\Framework\Setup\Setup_Wizard;
use Woodev\Framework\Setup\Step;
use Woodev\Framework\Setup\Step_Action;

require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step-action.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-action-outcome.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-callback-failure.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-setup.php';

/**
 * Wizard whose steps are injected by the test, exposing the bootstrap payload.
 */
class Contract_Test_Wizard extends Setup_Wizard {
	/** @var Step[] */
	public array $injected = [];

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	protected function register_steps(): void {
		foreach ( $this->injected as $step ) {
			$this->steps[ $step->get_id() ] = $step;
		}
	}

	public function get_id(): string {
		return 'acme';
	}

	public function build(): void {
		$this->build_steps();
	}

	public function data(): array {
		return $this->get_bootstrap_data();
	}

	protected function get_field_schema(): array {
		return [ 'api_key' => [ 'type' => 'string', 'value' => 'k' ] ];
	}

	public function get_state(): string {
		return '';
	}
}

/**
 * @covers \Woodev\Framework\Setup\Step
 * @covers \Woodev\Framework\Setup\Step_Action
 * @covers \Woodev\Framework\Setup\Action_Outcome
 * @covers \Woodev_REST_API_Setup
 */
class SetupWizardStepContractTest extends TestCase {

	// -----------------------------------------------------------------------
	// Descriptor
	// -----------------------------------------------------------------------

	public function test_a_step_is_skippable_by_default_and_can_be_made_mandatory(): void {
		$step = Step::settings( 'connection', 'C', [ 'api_key' ] );

		$this->assertTrue( $step->is_skippable() );
		$this->assertFalse( $step->set_skippable( false )->is_skippable() );
		$this->assertTrue( $step->set_skippable()->is_skippable() );
	}

	public function test_a_step_holds_actions_by_id(): void {
		$action = Step_Action::create( 'check-key', 'Проверить ключ', static fn() => Action_Outcome::success() );
		$step   = Step::settings( 'connection', 'C', [ 'api_key' ] )->add_action( $action );

		$this->assertSame( $action, $step->get_action( 'check-key' ) );
		$this->assertSame( [ 'check-key' => $action ], $step->get_actions() );
		$this->assertNull( $step->get_action( 'missing' ) );
	}

	public function test_an_action_id_must_be_a_url_segment(): void {
		$this->expectException( \InvalidArgumentException::class );

		Step_Action::create( 'bad id/..', 'X', static fn() => Action_Outcome::success() );
	}

	public function test_a_throwing_visibility_callback_hides_the_step_and_logs(): void {
		$captured = null;
		Functions\expect( 'error_log' )->once()->with(
			Mockery::on(
				static function ( $message ) use ( &$captured ) {
					$captured = $message;
					return true;
				}
			)
		);

		$step = Step::settings( 'delivery', 'D', [ 'tariff' ] )
			->set_visibility_callback( static function (): bool { throw new \TypeError( 'api_key=LIVESECRET' ); } );

		$this->assertFalse( $step->is_visible() );
		$this->assertSame(
			'[woodev] setup wizard visibility check failed for step "delivery": api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$captured
		);
	}

	// -----------------------------------------------------------------------
	// Bootstrap (what reaches the client)
	// -----------------------------------------------------------------------

	private function bootstrap_for( array $steps ): array {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.org/wp-admin/' );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Acme' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard           = new Contract_Test_Wizard( $plugin );
		$wizard->injected = $steps;
		$wizard->build();

		return $wizard->data();
	}

	public function test_the_bootstrap_emits_skippable_for_every_step(): void {
		$data = $this->bootstrap_for(
			[
				Step::content( 'welcome', 'W', '<p>hi</p>' ),
				Step::settings( 'connection', 'C', [ 'api_key' ] )->set_skippable( false ),
				Step::settings( 'extras', 'E', [] ),
			]
		);

		$by_id = array_column( $data['steps'], null, 'id' );

		$this->assertTrue( $by_id['welcome']['skippable'] );
		$this->assertFalse( $by_id['connection']['skippable'] );
		$this->assertTrue( $by_id['extras']['skippable'] );
		$this->assertArrayHasKey( 'skippable', $by_id['finish'] );
	}

	public function test_the_bootstrap_emits_actions_with_their_destructive_flag_and_no_callback(): void {
		$data = $this->bootstrap_for(
			[
				Step::settings( 'connection', 'C', [ 'api_key' ] )
					->add_action( Step_Action::create( 'check-key', 'Проверить ключ', static fn() => Action_Outcome::success() ) )
					->add_action( Step_Action::create( 'wipe', 'Очистить', static fn() => Action_Outcome::success(), true, 'Удалить старые данные?' ) ),
			]
		);

		$this->assertSame(
			[
				[ 'id' => 'check-key', 'label' => 'Проверить ключ', 'destructive' => false, 'confirm' => '' ],
				[ 'id' => 'wipe', 'label' => 'Очистить', 'destructive' => true, 'confirm' => 'Удалить старые данные?' ],
			],
			$data['steps'][0]['actions']
		);
	}

	public function test_a_throwing_content_callback_blanks_that_step_and_logs(): void {
		Functions\expect( 'error_log' )->once();

		$data = $this->bootstrap_for(
			[
				Step::content( 'welcome', 'W', static function (): string { throw new \Error( 'boom' ); } ),
				Step::content( 'second', 'S', '<p>ok</p>' ),
			]
		);

		$by_id = array_column( $data['steps'], null, 'id' );
		$this->assertSame( '', $by_id['welcome']['content'] );
		$this->assertSame( '<p>ok</p>', $by_id['second']['content'] );
	}

	// -----------------------------------------------------------------------
	// REST — validation before persistence
	// -----------------------------------------------------------------------

	/**
	 * @param Step     $step    the step under test.
	 * @param Mockery\MockInterface|null $handler optional settings handler.
	 * @return \Woodev_REST_API_Setup
	 */
	private function controller_for( Step $step, $handler = null ): \Woodev_REST_API_Setup {
		$handler = $handler ?? Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );

		$plugin = Mockery::mock( '\Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );

		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'get_steps' )->andReturn( [ $step->get_id() => $step ] );
		$wizard->shouldReceive( 'get_plugin' )->andReturn( $plugin );

		return new \Woodev_REST_API_Setup( $wizard );
	}

	private function request( string $step_id, array $values = [], array $extra = [] ) {
		$request = Mockery::mock( '\WP_REST_Request' );
		$params  = array_merge( [ 'step_id' => $step_id, 'values' => $values ], $extra );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static fn( $key ) => $params[ $key ] ?? null );

		return $request;
	}

	public function test_validation_errors_block_persistence_and_on_save_and_come_back_per_field(): void {
		$on_save_ran = false;
		$step        = Step::settings(
			'connection',
			'C',
			[ 'api_key', 'token' ],
			static function () use ( &$on_save_ran ): void {
				$on_save_ran = true;
			}
		)->set_validation_callback(
			static fn( array $values ) => '' === $values['api_key'] ? [ 'api_key' => 'Укажите ключ.' ] : null
		);

		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'update_value' )->never();

		$result = $this->controller_for( $step, $handler )->save_step( $this->request( 'connection', [ 'api_key' => '', 'token' => 't' ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_setup_invalid', $result->get_error_code() );
		$this->assertSame( [ 'api_key' => 'Укажите ключ.' ], $result->get_error_data()['errors'] );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertFalse( $on_save_ran );
	}

	public function test_a_valid_step_passes_validation_and_persists(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$step    = Step::settings( 'connection', 'C', [ 'api_key' ] )->set_validation_callback( static fn() => null );
		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'K' );

		$result = $this->controller_for( $step, $handler )->save_step( $this->request( 'connection', [ 'api_key' => 'K' ] ) );

		$this->assertSame( [ 'saved' => true, 'step' => 'connection' ], $result );
	}

	public function test_validation_receives_only_declared_fields(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$seen    = null;
		$step    = Step::settings( 'connection', 'C', [ 'api_key' ] )->set_validation_callback(
			static function ( array $values ) use ( &$seen ) {
				$seen = $values;
				return [];
			}
		);
		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'update_value' )->once();

		$this->controller_for( $step, $handler )->save_step( $this->request( 'connection', [ 'api_key' => 'K', 'evil' => 'X' ] ) );

		$this->assertSame( [ 'api_key' => 'K' ], $seen );
	}

	public function test_a_validator_returning_false_refuses_with_a_generic_message(): void {
		$step    = Step::settings( 'connection', 'C', [ 'api_key' ] )->set_validation_callback( static fn() => false );
		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'update_value' )->never();

		$result = $this->controller_for( $step, $handler )->save_step( $this->request( 'connection', [ 'api_key' => 'K' ] ) );

		$this->assertSame( 'woodev_setup_invalid', $result->get_error_code() );
		$this->assertSame( [], $result->get_error_data()['errors'] );
	}

	public function test_a_validator_throwing_an_error_is_redacted_logged_and_persists_nothing(): void {
		$captured = null;
		Functions\expect( 'error_log' )->once()->with(
			Mockery::on(
				static function ( $message ) use ( &$captured ) {
					$captured = $message;
					return true;
				}
			)
		);

		$step    = Step::settings( 'connection', 'C', [ 'api_key' ] )->set_validation_callback(
			static function (): array { throw new \TypeError( 'bad call, api_key=LIVESECRET' ); }
		);
		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'update_value' )->never();

		$result = $this->controller_for( $step, $handler )->save_step( $this->request( 'connection', [ 'api_key' => 'K' ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_setup_step_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'LIVESECRET', $result->get_error_message() );
		$this->assertStringNotContainsString( 'bad call', $result->get_error_message() );
		$this->assertSame(
			'[woodev] setup wizard validation failed for step "connection": bad call, api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$captured
		);
	}

	// -----------------------------------------------------------------------
	// REST — actions
	// -----------------------------------------------------------------------

	private function action_step( callable $callback, bool $destructive = false ): Step {
		return Step::settings( 'connection', 'C', [ 'api_key' ] )
			->add_action( Step_Action::create( 'act', 'Do', $callback, $destructive ) );
	}

	public function test_an_action_returns_its_structured_success(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$seen   = null;
		$step   = $this->action_step(
			static function ( array $values ) use ( &$seen ): Action_Outcome {
				$seen = $values;
				return Action_Outcome::success( 'Ключ подходит.', [ 'account' => 'ACME' ] );
			}
		);
		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'update_value' )->never(); // an action never persists step values by itself.

		$result = $this->controller_for( $step, $handler )->run_action(
			$this->request( 'connection', [ 'api_key' => 'K', 'evil' => 'X' ], [ 'action_id' => 'act' ] )
		);

		$this->assertSame(
			[ 'status' => 'success', 'message' => 'Ключ подходит.', 'data' => [ 'account' => 'ACME' ] ],
			$result
		);
		$this->assertSame( [ 'api_key' => 'K' ], $seen );
	}

	public function test_an_action_returns_its_structured_error_as_a_normal_answer(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$step = $this->action_step( static fn() => Action_Outcome::error( 'Ключ не подходит.' ) );

		$result = $this->controller_for( $step )->run_action( $this->request( 'connection', [], [ 'action_id' => 'act' ] ) );

		$this->assertSame( [ 'status' => 'error', 'message' => 'Ключ не подходит.', 'data' => [] ], $result );
	}

	public function test_an_unknown_action_and_an_unknown_step_are_404(): void {
		$controller = $this->controller_for( $this->action_step( static fn() => Action_Outcome::success() ) );

		$missing_action = $controller->run_action( $this->request( 'connection', [], [ 'action_id' => 'nope' ] ) );
		$missing_step   = $controller->run_action( $this->request( 'ghost', [], [ 'action_id' => 'act' ] ) );

		$this->assertSame( 'woodev_setup_unknown_action', $missing_action->get_error_code() );
		$this->assertSame( 404, $missing_action->get_error_data()['status'] );
		$this->assertSame( 'woodev_setup_unknown_step', $missing_step->get_error_code() );
	}

	public function test_a_destructive_action_is_refused_without_confirmation_and_runs_with_it(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$runs = 0;
		$step = $this->action_step(
			static function () use ( &$runs ): Action_Outcome {
				++$runs;
				return Action_Outcome::success( 'Готово.' );
			},
			true
		);

		$controller = $this->controller_for( $step );

		$refused = $controller->run_action( $this->request( 'connection', [], [ 'action_id' => 'act' ] ) );
		$this->assertInstanceOf( 'WP_Error', $refused );
		$this->assertSame( 'woodev_setup_confirmation_required', $refused->get_error_code() );
		$this->assertSame( 400, $refused->get_error_data()['status'] );
		$this->assertSame( 0, $runs );

		$ran = $controller->run_action( $this->request( 'connection', [], [ 'action_id' => 'act', 'confirmed' => true ] ) );
		$this->assertSame( 'success', $ran['status'] );
		$this->assertSame( 1, $runs );
	}

	public function test_a_non_destructive_action_needs_no_confirmation(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$step   = $this->action_step( static fn() => Action_Outcome::success() );
		$result = $this->controller_for( $step )->run_action( $this->request( 'connection', [], [ 'action_id' => 'act' ] ) );

		$this->assertSame( 'success', $result['status'] );
	}

	public function test_an_action_throwing_an_error_not_an_exception_is_redacted_and_logged(): void {
		$captured = null;
		Functions\expect( 'error_log' )->once()->with(
			Mockery::on(
				static function ( $message ) use ( &$captured ) {
					$captured = $message;
					return true;
				}
			)
		);

		$step   = $this->action_step( static function (): Action_Outcome { throw new \TypeError( 'Argument #1 must be Carrier, api_key=LIVESECRET' ); } );
		$result = $this->controller_for( $step )->run_action( $this->request( 'connection', [], [ 'action_id' => 'act' ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_setup_action_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'Carrier', $result->get_error_message() );
		$this->assertStringNotContainsString( 'LIVESECRET', $result->get_error_message() );
		$this->assertSame(
			'[woodev] setup wizard action "act" failed for step "connection": Argument #1 must be Carrier, api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$captured
		);
	}

	public function test_an_action_returning_something_else_is_an_unexpected_failure(): void {
		Functions\expect( 'error_log' )->once();

		$step   = $this->action_step( static fn() => true );
		$result = $this->controller_for( $step )->run_action( $this->request( 'connection', [], [ 'action_id' => 'act' ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_setup_action_failed', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// REST — complete() reports the state in force (D1)
	// -----------------------------------------------------------------------

	public function test_complete_reports_the_state_in_force_when_a_downgrade_is_refused(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$wizard = Mockery::mock( '\Woodev\Framework\Setup\Setup_Wizard' );
		$wizard->shouldReceive( 'complete_setup' )->once()->with( 'skipped' )->andReturn( 'completed' );

		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'state' )->andReturn( 'skipped' );

		$response = ( new \Woodev_REST_API_Setup( $wizard ) )->complete( $request );

		$this->assertSame( [ 'complete' => true, 'state' => 'completed' ], $response );
	}
}

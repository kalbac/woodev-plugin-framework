<?php
/**
 * Setup wizard step graph (D3) and custom step components (D4).
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
require_once __DIR__ . '/Shipping/Rest_Api/wp-rest-controller-stub.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/class-rest-v1-registrar.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-setup.php';

/**
 * A real wizard (no hooks) over an injected plugin, with the steps the test registers.
 */
class Graph_Test_Wizard extends Setup_Wizard {

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

	public function build(): self {
		$this->build_steps();

		return $this;
	}

	public function data(): array {
		return $this->get_bootstrap_data();
	}

	public function handles(): array {
		return $this->get_component_handles();
	}

	protected function get_field_schema(): array {
		return [
			'mode' => [ 'type' => 'string', 'value' => 'keep' ],
			'map'  => [ 'type' => 'string', 'value' => '' ],
			'ctrl' => [ 'type' => 'string', 'value' => 'live' ],
			'dep'  => [ 'type' => 'string', 'value' => '', 'show_if' => [ 'setting' => 'ctrl', 'value' => 'live' ] ],
		];
	}

	public function get_state(): string {
		return '';
	}
}

/**
 * @covers \Woodev\Framework\Setup\Setup_Wizard
 * @covers \Woodev\Framework\Setup\Step
 * @covers \Woodev_REST_API_Setup
 */
class SetupWizardStepGraphTest extends TestCase {

	/** @var array<string,mixed> the "saved" settings the handler mock reads and writes. */
	private array $saved = [ 'mode' => 'keep' ];

	/** @var Graph_Test_Wizard */
	private $wizard;

	/** @var \Woodev_REST_API_Setup */
	private $controller;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'admin_url' )->justReturn( 'http://example.org/wp-admin/' );

		$this->saved = [ 'mode' => 'keep' ];
	}

	/**
	 * Builds the wizard + controller over a handler that persists into $this->saved.
	 *
	 * @param Step[] $steps the registered steps.
	 */
	private function boot( array $steps ): void {
		$saved = &$this->saved;

		$handler = Mockery::mock( '\Woodev_Abstract_Settings' );
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
		$handler->shouldReceive( 'get_value' )->andReturnUsing( static fn( $id ) => $saved[ $id ] ?? '' );
		$handler->shouldReceive( 'update_value' )->andReturnUsing(
			static function ( $id, $value ) use ( &$saved ): void {
				$saved[ $id ] = $value;
			}
		);

		$plugin = Mockery::mock( '\Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Acme' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard           = new Graph_Test_Wizard( $plugin );
		$wizard->injected = $steps;

		$this->wizard     = $wizard->build();
		$this->controller = new \Woodev_REST_API_Setup( $this->wizard );
	}

	/**
	 * The migration-wizard shape: a first step whose saved choice hides the mapping steps.
	 *
	 * @return Step[]
	 */
	private function branching_steps( ?callable $mapping_action = null ): array {
		$saved = &$this->saved;

		$mapping = Step::settings( 'mapping', 'Сопоставление', [ 'map' ] )
			->set_visibility_callback( static function () use ( &$saved ): bool { return 'scratch' !== ( $saved['mode'] ?? '' ); } );

		if ( null !== $mapping_action ) {
			$mapping->add_action( Step_Action::create( 'preview', 'Предпросмотр', $mapping_action ) );
		}

		return [
			Step::settings( 'start', 'Начало', [ 'mode' ] ),
			$mapping,
			Step::content( 'done', 'Итог', '<p>ok</p>' ),
		];
	}

	private function request( string $step_id, array $values = [], array $extra = [] ) {
		$request = Mockery::mock( '\WP_REST_Request' );
		$params  = array_merge( [ 'step_id' => $step_id, 'values' => $values ], $extra );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static fn( $key ) => $params[ $key ] ?? null );

		return $request;
	}

	private function visibility( array $graph ): array {
		$out = [];
		foreach ( $graph as $entry ) {
			$out[ $entry['id'] ] = $entry['visible'];
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Graph recompute
	// -----------------------------------------------------------------------

	public function test_the_bootstrap_emits_the_initial_graph_with_every_step_in_order(): void {
		$this->boot( $this->branching_steps() );

		$graph = $this->wizard->data()['steps'];

		$this->assertSame( [ 'start', 'mapping', 'done', 'finish' ], array_column( $graph, 'id' ) );
		$this->assertSame( [ 'start' => true, 'mapping' => true, 'done' => true, 'finish' => true ], $this->visibility( $graph ) );
		$this->assertTrue( $graph[1]['skippable'] );
		$this->assertArrayHasKey( 'fields', $graph[1] ); // a visible step carries its full descriptor.
	}

	public function test_a_hidden_step_is_in_the_graph_as_a_bare_hidden_entry(): void {
		$this->saved['mode'] = 'scratch';
		$this->boot( $this->branching_steps() );

		$graph = $this->wizard->data()['steps'];

		$this->assertSame( [ 'id' => 'mapping', 'visible' => false ], $graph[1] );
		$this->assertSame( [ 'start', 'done' ], array_keys( $this->wizard->get_steps() ) );
	}

	public function test_saving_the_first_step_recomputes_the_graph_and_hides_the_mapping_step(): void {
		$this->boot( $this->branching_steps() );

		$before = $this->wizard->get_step_graph();
		$this->assertTrue( $this->visibility( $before )['mapping'] );

		$response = $this->controller->save_step( $this->request( 'start', [ 'mode' => 'scratch' ] ) );

		$this->assertTrue( $response['saved'] );
		$this->assertSame(
			[ 'start' => true, 'mapping' => false, 'done' => true, 'finish' => true ],
			$this->visibility( $response['graph'] )
		);
	}

	public function test_changing_the_choice_back_shows_the_step_again(): void {
		$this->saved['mode'] = 'scratch';
		$this->boot( $this->branching_steps() );

		$response = $this->controller->save_step( $this->request( 'start', [ 'mode' => 'keep' ] ) );

		$this->assertTrue( $this->visibility( $response['graph'] )['mapping'] );
	}

	public function test_an_action_answer_carries_the_graph_too(): void {
		$saved = &$this->saved;
		$steps = [
			Step::settings( 'start', 'Начало', [ 'mode' ] )->add_action(
				Step_Action::create(
					'fresh',
					'Начать с чистого листа',
					static function () use ( &$saved ): Action_Outcome {
						$saved['mode'] = 'scratch'; // the action persists a choice itself.
						return Action_Outcome::success( 'Готово.' );
					}
				)
			),
			Step::settings( 'mapping', 'Сопоставление', [ 'map' ] )
				->set_visibility_callback( static function () use ( &$saved ): bool { return 'scratch' !== ( $saved['mode'] ?? '' ); } ),
		];
		$this->boot( $steps );

		$response = $this->controller->run_action( $this->request( 'start', [], [ 'action_id' => 'fresh' ] ) );

		$this->assertSame( 'success', $response['status'] );
		$this->assertFalse( $this->visibility( $response['graph'] )['mapping'] );
	}

	// -----------------------------------------------------------------------
	// show_if across steps follows the context the client can see
	// -----------------------------------------------------------------------

	/**
	 * Boots the wizard over a REAL settings handler: `ctrl` (owned by the `gate` step) controls the
	 * visibility of `dep` (owned by the `detail` step). `ctrl` is STORED as `live`.
	 *
	 * @param bool $gate_visible whether the `gate` step is visible.
	 * @param array<string,mixed> $written receives what the handler was asked to persist.
	 */
	private function boot_cross_step( bool $gate_visible, array &$written ): void {
		require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/register-settings/class-register-settings.php';
		require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';

		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		$handler = new class( $written ) extends \Woodev_Abstract_Settings {
			/** @var array */
			public $written;
			public function __construct( array &$written ) {
				$this->written = &$written;
				parent::__construct( 'cross_step' );
			}
			protected function register_settings() {
				$this->register_setting( 'ctrl', \Woodev_Setting::TYPE_STRING, [ 'default' => 'test' ] );
				$this->register_setting( 'dep', \Woodev_Setting::TYPE_STRING, [ 'default' => '', 'show_if' => [ 'setting' => 'ctrl', 'value' => 'live' ] ] );
			}
			public function get_value( $setting_id, $with_default = true ) {
				return 'ctrl' === $setting_id ? 'live' : '';
			}
			public function update_value( $setting_id, $value ) {
				$this->written[ $setting_id ] = $value;
			}
			public function save( $setting_id = '' ) {}
		};

		$plugin = Mockery::mock( '\Woodev_Plugin' );
		$plugin->shouldReceive( 'get_settings_handler' )->andReturn( $handler );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Acme' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard           = new Graph_Test_Wizard( $plugin );
		$wizard->injected = [
			Step::settings( 'gate', 'Режим', [ 'ctrl' ] )->set_visibility_callback( static fn(): bool => $gate_visible ),
			Step::settings( 'detail', 'Детали', [ 'dep' ] )->set_validation_callback(
				// A plugin rule on the values the merchant sees: a PRESENT, empty `dep` is refused.
				static fn( array $values ): array => array_key_exists( 'dep', $values ) && '' === $values['dep'] ? [ 'dep' => 'Заполните поле.' ] : []
			),
		];

		$this->wizard     = $wizard->build();
		$this->controller = new \Woodev_REST_API_Setup( $this->wizard );
	}

	public function test_a_controller_owned_by_a_hidden_step_counts_as_absent_so_the_dependent_field_is_hidden(): void {
		$written = [];
		$this->boot_cross_step( false, $written );

		// The graph does not carry the hidden step's value (nor any field of it).
		$this->assertSame( [ 'id' => 'gate', 'visible' => false ], $this->wizard->get_step_graph()[0] );

		// `ctrl` is stored as `live`, but the client cannot see it: `dep` is hidden there, so the
		// server must not validate it (no error the merchant cannot fix) and must not persist it.
		$response = $this->controller->save_step( $this->request( 'detail', [ 'dep' => 'typed' ] ) );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['saved'] );
		$this->assertSame( [], $written );
	}

	public function test_an_unsent_dependent_field_is_not_validated_when_its_controller_step_is_hidden(): void {
		$written = [];
		$this->boot_cross_step( false, $written );

		// Nothing submitted: the stored `dep` ('') would be validated if it counted as visible.
		$response = $this->controller->save_step( $this->request( 'detail', [] ) );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['saved'] );
	}

	public function test_a_controller_owned_by_a_visible_step_still_resolves_from_its_stored_value(): void {
		$written = [];
		$this->boot_cross_step( true, $written );

		// Control: the gate step is visible, `ctrl` = live is stored → `dep` is visible, so an
		// empty one is validated, and a typed one persisted.
		$refused = $this->controller->save_step( $this->request( 'detail', [ 'dep' => '' ] ) );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'woodev_setup_invalid', $refused->get_error_code() );

		$saved = $this->controller->save_step( $this->request( 'detail', [ 'dep' => 'typed' ] ) );
		$this->assertTrue( $saved['saved'] );
		$this->assertSame( [ 'dep' => 'typed' ], $written );
	}

	// -----------------------------------------------------------------------
	// A step that is not visible is refused
	// -----------------------------------------------------------------------

	public function test_a_save_for_a_hidden_step_is_refused_and_nothing_is_persisted_or_run(): void {
		$this->saved['mode'] = 'scratch';
		$on_save_ran         = false;
		$validator_ran       = false;
		$saved               = &$this->saved;

		$mapping = Step::settings(
			'mapping',
			'Сопоставление',
			[ 'map' ],
			static function () use ( &$on_save_ran ): void {
				$on_save_ran = true;
			}
		)->set_visibility_callback( static function () use ( &$saved ): bool { return 'scratch' !== ( $saved['mode'] ?? '' ); } )
			->set_validation_callback(
				static function () use ( &$validator_ran ) {
					$validator_ran = true;
					return null;
				}
			);
		$this->boot( [ Step::settings( 'start', 'Начало', [ 'mode' ] ), $mapping ] );

		$result = $this->controller->save_step( $this->request( 'mapping', [ 'map' => 'x' ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_setup_step_hidden', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'map', $this->saved );
		$this->assertFalse( $on_save_ran );
		$this->assertFalse( $validator_ran );
	}

	public function test_an_action_for_a_hidden_step_is_refused_and_does_not_run(): void {
		$this->saved['mode'] = 'scratch';
		$ran                 = false;
		$this->boot(
			$this->branching_steps(
				static function () use ( &$ran ): Action_Outcome {
					$ran = true;
					return Action_Outcome::success();
				}
			)
		);

		$result = $this->controller->run_action( $this->request( 'mapping', [], [ 'action_id' => 'preview' ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_setup_step_hidden', $result->get_error_code() );
		$this->assertFalse( $ran );
	}

	public function test_a_step_nobody_registered_is_still_a_404_not_a_409(): void {
		$this->boot( $this->branching_steps() );

		$result = $this->controller->save_step( $this->request( 'ghost' ) );

		$this->assertSame( 'woodev_setup_unknown_step', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_a_visible_step_is_not_refused(): void {
		$this->boot( $this->branching_steps() );

		$response = $this->controller->save_step( $this->request( 'mapping', [ 'map' => 'x' ] ) );

		$this->assertTrue( $response['saved'] );
	}

	public function test_deciding_whether_to_wire_the_wizard_does_not_run_the_visibility_predicates(): void {
		$runs = 0;
		$this->boot(
			[
				Step::settings( 'start', 'Начало', [ 'mode' ] )
					->set_visibility_callback(
						static function () use ( &$runs ): bool {
							++$runs;
							return true;
						}
					),
			]
		);

		$this->assertTrue( $this->wizard->has_steps() );
		$this->assertSame( 0, $runs ); // evaluated when a graph is built, never while the plugin boots.
	}

	// -----------------------------------------------------------------------
	// A throwing predicate
	// -----------------------------------------------------------------------

	public function test_a_throwing_predicate_hides_the_step_and_logs_instead_of_failing_the_request(): void {
		Functions\expect( 'error_log' )->atLeast()->once();

		$this->boot(
			[
				Step::settings( 'start', 'Начало', [ 'mode' ] ),
				Step::settings( 'mapping', 'Сопоставление', [ 'map' ] )
					->set_visibility_callback( static function (): bool { throw new \TypeError( 'api_key=LIVESECRET' ); } ),
			]
		);

		$saved = $this->controller->save_step( $this->request( 'start', [ 'mode' => 'keep' ] ) );

		$this->assertTrue( $saved['saved'] ); // not a 500.
		$this->assertFalse( $this->visibility( $saved['graph'] )['mapping'] );

		$refused = $this->controller->save_step( $this->request( 'mapping' ) );
		$this->assertSame( 'woodev_setup_step_hidden', $refused->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// D4 — custom component
	// -----------------------------------------------------------------------

	public function test_the_bootstrap_emits_the_component_descriptor_and_null_for_built_in_steps(): void {
		$this->boot(
			[
				Step::content( 'welcome', 'W', '<p>x</p>' ),
				Step::content( 'review', 'R', '' )->set_component( 'acme-wizard-review', 'ReviewStep' ),
			]
		);

		$by_id = array_column( $this->wizard->data()['steps'], null, 'id' );

		$this->assertNull( $by_id['welcome']['component'] );
		$this->assertSame( [ 'handle' => 'acme-wizard-review', 'export' => 'ReviewStep' ], $by_id['review']['component'] );
	}

	/**
	 * @dataProvider provide_bad_components
	 */
	public function test_a_malformed_component_declaration_is_refused( string $handle, string $export ): void {
		$this->expectException( \InvalidArgumentException::class );

		Step::content( 'review', 'R', '' )->set_component( $handle, $export );
	}

	public function provide_bad_components(): array {
		return [
			'empty handle'  => [ '', 'ReviewStep' ],
			'spaced handle' => [ 'my script', 'ReviewStep' ],
			'empty export'  => [ 'acme-review', '' ],
			'dotted export' => [ 'acme-review', 'a.b' ],
		];
	}

	public function test_registered_component_handles_become_dependencies_and_unregistered_ones_are_logged(): void {
		Functions\when( 'wp_script_is' )->alias( static fn( $handle ) => 'acme-ok' === $handle );
		$logged = [];
		Functions\when( 'error_log' )->alias(
			static function ( $message ) use ( &$logged ): void {
				$logged[] = $message;
			}
		);

		$this->boot(
			[
				Step::content( 'a', 'A', '' )->set_component( 'acme-ok', 'A' ),
				Step::content( 'b', 'B', '' )->set_component( 'acme-missing', 'B' ),
				Step::content( 'c', 'C', '' ),
			]
		);

		$this->assertSame( [ 'acme-ok' ], $this->wizard->handles() );
		$this->assertSame( [ '[woodev] setup wizard step "b" names the script "acme-missing", which is not registered' ], $logged );
	}

	public function test_a_hidden_steps_component_script_is_still_collected(): void {
		Functions\when( 'wp_script_is' )->justReturn( true );
		$saved = &$this->saved;
		$saved['mode'] = 'scratch';

		$this->boot(
			[
				Step::content( 'b', 'B', '' )->set_component( 'acme-ok', 'B' )
					->set_visibility_callback( static function () use ( &$saved ): bool { return 'scratch' !== $saved['mode']; } ),
			]
		);

		$this->assertSame( [ 'acme-ok' ], $this->wizard->handles() );
	}
}

<?php
/**
 * Step description and terminal finish step tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Setup_Wizard;
use Woodev\Framework\Setup\Step;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

/**
 * Concrete wizard for description + finish-step testing.
 */
class Desc_Test_Wizard extends Setup_Wizard {

	/** @var string description to pass to the step. */
	public string $step_description = '';

	/**
	 * Inject mock, skip parent wiring.
	 *
	 * @param \Woodev_Plugin $plugin plugin mock.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers one settings step (description driven by public property).
	 *
	 * @return void
	 */
	protected function register_steps(): void {
		$this->register_step( 'connection', 'Подключение', [ 'api_key' ], null, $this->step_description );
	}

	/** @return string */
	public function get_id(): string {
		return 'desc-test';
	}

	/**
	 * Proxy to build_steps so tests can rebuild after changing step_description.
	 *
	 * @return void
	 */
	public function rebuild(): void {
		$this->build_steps();
	}

	/**
	 * Proxy to get_bootstrap_data.
	 *
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->get_bootstrap_data();
	}

	/**
	 * Override field schema to avoid needing a real settings handler.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_field_schema(): array {
		return [ 'api_key' => [ 'type' => 'string', 'value' => '' ] ];
	}

	/** @return string */
	public function get_state(): string {
		return '';
	}
}

/**
 * Tests for B2: step descriptions + auto-appended terminal finish step.
 */
class SetupWizardStepDescriptionTest extends TestCase {

	/**
	 * Step::settings() and Step::content() accept and expose a description.
	 *
	 * @return void
	 */
	public function test_step_factories_accept_and_return_description(): void {
		$s_step = Step::settings( 'delivery', 'Доставка', [ 'tariff' ], null, 'Describe delivery' );
		$this->assertSame( 'Describe delivery', $s_step->get_description() );

		$c_step = Step::content( 'welcome', 'Добро пожаловать', '<p>hi</p>', 'Welcome desc' );
		$this->assertSame( 'Welcome desc', $c_step->get_description() );
	}

	/**
	 * Default description is empty string.
	 *
	 * @return void
	 */
	public function test_step_description_defaults_to_empty(): void {
		$step = Step::settings( 'basic', 'Базовые', [ 'x' ] );
		$this->assertSame( '', $step->get_description() );
	}

	/**
	 * The wizard emits a 'description' key in each step of bootstrap data.
	 *
	 * @return void
	 */
	public function test_bootstrap_step_includes_description(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'N' );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard                  = new Desc_Test_Wizard( $plugin );
		$wizard->step_description = 'Step description text';
		$wizard->rebuild();

		$data  = $wizard->data();
		$steps = $data['steps'];

		// Find the 'connection' step entry (not the synthetic finish one).
		$conn = null;
		foreach ( $steps as $s ) {
			if ( 'connection' === $s['id'] ) {
				$conn = $s;
				break;
			}
		}

		$this->assertNotNull( $conn, 'connection step not found in bootstrap data' );
		$this->assertArrayHasKey( 'description', $conn );
		$this->assertSame( 'Step description text', $conn['description'] );
	}

	/**
	 * Bootstrap data's last step is always the synthetic terminal finish step.
	 *
	 * @return void
	 */
	public function test_bootstrap_last_step_is_terminal_finish(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'N' );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_settings_url' )->andReturn( '' );
		$plugin->shouldReceive( 'get_reviews_url' )->andReturn( '' );

		$wizard = new Desc_Test_Wizard( $plugin );
		$wizard->rebuild();
		$data = $wizard->data();

		$last = end( $data['steps'] );

		$this->assertSame( 'finish', $last['id'] );
		$this->assertSame( 'finish', $last['type'] );
		$this->assertSame( [], $last['fields'] );
		$this->assertSame( '', $last['content'] );
		$this->assertArrayHasKey( 'description', $last );
	}
}

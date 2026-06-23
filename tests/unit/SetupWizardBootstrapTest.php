<?php
/**
 * Setup Wizard bootstrap data tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Setup\Setup_Wizard;

require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

class Bootstrap_Test_Wizard extends Setup_Wizard {
	public function __construct( $plugin ) { $this->plugin = $plugin; } // inject mock, skip parent wiring
	protected function register_steps(): void {
		$this->register_content_step( 'welcome', 'Привет', static function (): string { return '<p>hi</p>'; } );
		$this->register_step( 'connection', 'Подключение', [ 'api_key' ] );
	}
	public function get_id(): string { return 'acme'; }
	public function build(): void { $this->build_steps(); }
	public function data(): array { return $this->get_bootstrap_data(); }
	protected function get_field_schema(): array { return [ 'api_key' => [ 'type' => 'string', 'value' => 'k' ] ]; }
	public function get_state(): string { return ''; }
}

class SetupWizardBootstrapTest extends TestCase {

	public function test_bootstrap_data_shape(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'rest_url' )->returnArg( 1 );      // return the path arg so restRoot contains it
		Functions\when( 'esc_url_raw' )->returnArg( 1 );

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Acme' );
		$plugin->shouldReceive( 'get_documentation_url' )->andReturn( '' );

		$wizard = new Bootstrap_Test_Wizard( $plugin );
		$wizard->build();
		$data = $wizard->data();

		$this->assertSame( 'acme', $data['pluginId'] );
		$this->assertSame( 'NONCE', $data['nonce'] );
		$step_ids = array_column( $data['steps'], 'id' );
		$this->assertContains( 'welcome', $step_ids );
		$this->assertContains( 'connection', $step_ids );
		// Last step is always the synthetic terminal finish descriptor.
		$this->assertSame( 'finish', end( $step_ids ) );
		// content step carries no fields; settings step carries its schema slice.
		$this->assertSame( [], $data['steps'][0]['fields'] );
		$this->assertSame( '<p>hi</p>', $data['steps'][0]['content'] );
		$this->assertArrayHasKey( 'api_key', $data['steps'][1]['fields'] );
		$this->assertStringContainsString( 'woodev/v1/acme/setup', $data['restRoot'] );
	}
}

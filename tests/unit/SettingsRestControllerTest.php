<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry;
use Woodev\Framework\Shipping\Settings\Tool_Result;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-settings-page.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/settings/class-shipping-tool.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/settings/class-tool-result.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/settings/class-shipping-tools-registry.php';

class SettingsRestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Shipping_Tools_Registry::reset_for_tests();
	}

	protected function tearDown(): void {
		Shipping_Tools_Registry::reset_for_tests();
		parent::tearDown();
	}

	private function tools_section( array $tools ) {
		$section = Mockery::mock();
		$section->shouldReceive( 'is_tools' )->andReturn( true );
		$section->shouldReceive( 'get_tools' )->andReturn( $tools );

		return $section;
	}

	private function request( array $params ) {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);

		return $request;
	}

	private function section( array $setting_ids ) {
		$section = Mockery::mock();
		$section->shouldReceive( 'get_setting_ids' )->andReturn( $setting_ids );

		return $section;
	}

	public function test_get_schema_returns_registry_tabs(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_tabs' )->andReturn(
			[ [ 'id' => 'cdek', 'label' => 'СДЭК', 'capability' => 'manage_woocommerce', 'sections' => [] ] ]
		);

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->get_schema( $this->request( [] ) );

		$this->assertSame( 'cdek', $response['tabs'][0]['id'] );
	}

	public function test_save_unknown_provider_is_404(): void {
		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'ghost' )->andReturn( null );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->save( $this->request( [ 'provider_id' => 'ghost', 'values' => [] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_unknown_provider', $result->get_error_code() );
	}

	public function test_save_persists_each_known_value(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
		$handler->shouldReceive( 'validate_values' )->once()->andReturn( [] );
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'secret' );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'api_key' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'api_key' => 'secret' ] ] ) );

		$this->assertTrue( $response['saved'] );
		$this->assertSame( 'cdek', $response['provider'] );
	}

	public function test_save_drops_undeclared_keys(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
		$handler->shouldReceive( 'validate_values' )->once()->andReturn( [] );
		// Undeclared key must never reach the handler — not even to be 404-rejected.
		$handler->shouldNotReceive( 'update_value' );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'api_key' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'ghost' => 'x' ] ] ) );

		$this->assertTrue( $response['saved'] );
	}

	public function test_save_returns_error_map_when_validation_fails(): void {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
		$handler->shouldReceive( 'validate_values' )
			->once()
			->andReturn( [ 'mode' => 'invalid mode' ] );
		$handler->shouldNotReceive( 'update_value' );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'api_key', 'mode' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'api_key' => 'good', 'mode' => 'bad' ] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_invalid', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( [ 'mode' => 'invalid mode' ], $data['errors'] );
	}

	public function test_save_persists_all_when_valid(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
		$handler->shouldReceive( 'validate_values' )->once()->andReturn( [] );
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'good' );
		$handler->shouldReceive( 'update_value' )->once()->with( 'mode', 'live' );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'api_key', 'mode' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'api_key' => 'good', 'mode' => 'live' ] ] ) );

		$this->assertTrue( $response['saved'] );
		$this->assertSame( 'cdek', $response['provider'] );
	}

	// ----- run_tool() (#505) -----

	public function test_run_tool_unknown_provider_is_404(): void {
		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'ghost' )->andReturn( null );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->run_tool( $this->request( [ 'provider_id' => 'ghost', 'tool_id' => 'sweep', 'args' => [] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_unknown_provider', $result->get_error_code() );
	}

	public function test_run_tool_unknown_tool_is_404(): void {
		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->tools_section( [] ) ] );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'shipping' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->run_tool( $this->request( [ 'provider_id' => 'shipping', 'tool_id' => 'ghost', 'args' => [] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_unknown_tool', $result->get_error_code() );
	}

	public function test_run_tool_scopes_args_and_returns_the_result_payload(): void {
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );

		$received = null;
		$tool     = Shipping_Tool::create(
			'sweep',
			'Проверить',
			'',
			'Проверить',
			static function ( array $args ) use ( &$received ): Tool_Result {
				$received = $args;

				return Tool_Result::success( 'Готово' );
			},
			false,
			'',
			[ 'name' => 'provider_id', 'options' => [] ]
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( $tool ) {
				if ( Shipping_Tools_Registry::FILTER_TOOLS === $tag ) {
					return [ $tool ];
				}

				return $default;
			}
		);

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->tools_section( [ $tool ] ) ] );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'shipping' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$response   = $controller->run_tool(
			$this->request(
				[
					'provider_id' => 'shipping',
					'tool_id'     => 'sweep',
					'args'        => [ 'provider_id' => 'dadata', 'extra' => 'must-not-reach-the-callback' ],
				]
			)
		);

		$this->assertSame( [ 'provider_id' => 'dadata' ], $received );
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Готово', $response['message'] );
	}

	public function test_run_tool_callback_throwing_is_a_logged_500(): void {
		$tool = Shipping_Tool::create(
			'sweep',
			'Проверить',
			'',
			'Проверить',
			static function ( array $args ): Tool_Result {
				throw new \RuntimeException( 'boom' );
			}
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) use ( $tool ) {
				if ( Shipping_Tools_Registry::FILTER_TOOLS === $tag ) {
					return [ $tool ];
				}

				return $default;
			}
		);

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->tools_section( [ $tool ] ) ] );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'shipping' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->run_tool( $this->request( [ 'provider_id' => 'shipping', 'tool_id' => 'sweep', 'args' => [] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_tool_error', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}

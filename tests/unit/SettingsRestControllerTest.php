<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-settings-page.php';

class SettingsRestControllerTest extends TestCase {

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

	public function test_save_is_non_atomic_persisting_valid_keys_before_a_later_failure(): void {
		// Documented behavior (mirrors the wizard): each key persists as it
		// validates; a mid-tab failure leaves earlier keys saved and names the
		// failing field. Re-submitting the tab is idempotent.
		$handler = Mockery::mock();
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'good' );
		$handler->shouldReceive( 'update_value' )->once()->with( 'mode', 'bad' )->andThrow( new \Woodev_Plugin_Exception( 'invalid mode', 400 ) );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'api_key', 'mode' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'api_key' => 'good', 'mode' => 'bad' ] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'mode', $result->get_error_data()['field'] );
	}

	public function test_save_reports_validation_error_with_field(): void {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'update_value' )->andThrow( new \Woodev_Plugin_Exception( 'bad value', 400 ) );

		$provider = Mockery::mock();
		$provider->shouldReceive( 'get_sections' )->andReturn( [ $this->section( [ 'mode' ] ) ] );
		$provider->shouldReceive( 'get_handler' )->andReturn( $handler );

		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'cdek' )->andReturn( $provider );

		$controller = new \Woodev_REST_API_Settings_Page( $registry );
		$result     = $controller->save( $this->request( [ 'provider_id' => 'cdek', 'values' => [ 'mode' => 'x' ] ] ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'woodev_settings_invalid', $result->get_error_code() );
		$this->assertSame( 'mode', $result->get_error_data()['field'] );
	}
}

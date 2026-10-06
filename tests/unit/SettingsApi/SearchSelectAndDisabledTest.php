<?php
/** Tests for scalar AJAX selection and live conditional read-only settings. */
namespace Woodev\Tests\Unit\SettingsApi;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Composite_Settings_Handler;
use Woodev\Framework\Settings\Field_Schema;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/rest-api/controllers/class-rest-api-settings-page.php';

final class SearchSelectAndDisabledTest extends TestCase {
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [ 'woodev_search_test_mode' => 'manual', 'woodev_search_test_city' => 42 ];
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( $defaults, $args ) );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) { $this->options[ $key ] = $value; return true; } );
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://example.test/wp-json/' . $path );
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ) => strip_tags( $value ) );
	}

	private function handler(): \Woodev_Abstract_Settings {
		return new class() extends \Woodev_Abstract_Settings {
			public array $terms = [];
			public function __construct() { parent::__construct( 'search_test' ); }
			protected function register_settings() {
				$this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'default' => 'manual' ] );
				$this->register_setting( 'city', \Woodev_Setting::TYPE_INTEGER, [
					'default' => 0,
					'disabled_if' => static fn( string $id ): array => 'city' === $id ? [ 'setting' => 'mode', 'value' => 'auto' ] : [],
				] );
				$this->register_control( 'city', \Woodev_Control::TYPE_SEARCH_SELECT, [
					'disabled_reason' => 'Выбран автоматический режим.',
					'search_callback' => function ( string $term ): array {
						$this->terms[] = $term;
						return [ [ 'value' => 42, 'label' => 'Москва' ], [ 'value' => 'code', 'label' => 'Казань' ] ];
					},
					'label_callback' => static fn( $value ): string => 42 === $value ? 'Москва' : '',
				] );
				$this->register_setting( 'hidden_city', \Woodev_Setting::TYPE_INTEGER, [ 'default' => 5 ] );
				$this->register_control( 'hidden_city', \Woodev_Control::TYPE_SEARCH_SELECT, [
					'search_callback' => static fn( string $term ): array => [],
					'label_callback' => static fn( $value ): string => '',
				] );
			}
		};
	}

	private function controller( \Woodev_Abstract_Settings $handler ): \Woodev_REST_API_Settings_Page {
		$provider = Settings_Provider::create_with_sections( 'search_test', 'Test', $handler, [], Settings_Section::create( 'main', 'Main', [ 'mode', 'city' ] ) );
		$registry = Mockery::mock();
		$registry->shouldReceive( 'get_provider' )->with( 'search_test' )->andReturn( $provider );
		$registry->shouldReceive( 'get_provider_capability' )->with( 'search_test' )->andReturn( 'manage_woocommerce' );
		return new \Woodev_REST_API_Settings_Page( $registry );
	}

	private function request( array $params, string $nonce = 'good' ): \WP_REST_Request {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing( static fn( $key ) => $params[ $key ] ?? null );
		$request->shouldReceive( 'get_header' )->with( 'X-WP-Nonce' )->andReturn( $nonce );
		return $request;
	}

	public function test_schema_hydrates_saved_label_and_keeps_callbacks_off_the_wire(): void {
		$handler = $this->handler();
		$schema = Field_Schema::from_handler( $handler )['city'];
		$this->assertSame( 'search-select', $schema['controlType'] );
		$this->assertSame( 42, $schema['value'] );
		$this->assertSame( 'Москва', $schema['value_label'] );
		$this->assertSame( 'https://example.test/wp-json/woodev/v1/settings/search_test/control/city/search', $schema['search_url'] );
		$this->assertSame( [ 'setting' => 'mode', 'value' => 'auto' ], $schema['disabled_if'] );
		$this->assertFalse( $schema['disabled'] );
		$this->assertSame( 'Выбран автоматический режим.', $schema['disabled_reason'] );
		$this->assertArrayNotHasKey( 'search_callback', $schema );
		$this->assertSame( [], $handler->terms, 'label hydration does not search' );
		$this->assertSame( '', $handler->get_setting( 'city' )->get_control()->get_value_label( 99 ) );
	}

	public function test_search_is_scoped_to_the_declared_control_and_preserves_id_types(): void {
		$handler = $this->handler();
		$result = $this->controller( $handler )->search_control( $this->request( [ 'provider_id' => 'search_test', 'setting_id' => 'city', 'term' => ' Мос ' ] ) );
		$this->assertSame( [ 'Мос' ], $handler->terms );
		$this->assertSame( [ [ 'value' => 42, 'label' => 'Москва' ], [ 'value' => 'code', 'label' => 'Казань' ] ], $result['options'] );
	}

	public function test_a_single_cyrillic_character_does_not_call_the_carrier(): void {
		$handler = $this->handler();
		$result = $this->controller( $handler )->search_control( $this->request( [ 'provider_id' => 'search_test', 'setting_id' => 'city', 'term' => 'Я' ] ) );
		$this->assertSame( [ 'options' => [] ], $result );
		$this->assertSame( [], $handler->terms );
	}

	public function test_a_registered_but_unexposed_control_cannot_be_searched(): void {
		$handler = $this->handler();
		$result = $this->controller( $handler )->search_control( $this->request( [ 'provider_id' => 'search_test', 'setting_id' => 'hidden_city', 'term' => 'Мос' ] ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( [], $handler->terms );
	}

	public function test_search_requires_manager_capability_and_a_valid_nonce(): void {
		$controller = $this->controller( $this->handler() );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'good' === $nonce && 'wp_rest' === $action );
		$this->assertTrue( $controller->search_permissions_check( $this->request( [ 'provider_id' => 'search_test' ] ) ) );
		$this->assertFalse( $controller->search_permissions_check( $this->request( [ 'provider_id' => 'search_test' ], '' ) ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $controller->search_permissions_check( $this->request( [ 'provider_id' => 'search_test' ] ) ) );
	}

	public function test_disabled_field_is_not_validated_or_saved_and_keeps_the_saved_scalar(): void {
		$handler = $this->handler();
		$result = $this->controller( $handler )->save( $this->request( [ 'provider_id' => 'search_test', 'values' => [ 'mode' => 'auto', 'city' => [ 'invalid' ] ] ] ) );
		$this->assertTrue( $result['saved'] );
		$this->assertSame( 'auto', $this->options['woodev_search_test_mode'] );
		$this->assertSame( 42, $this->options['woodev_search_test_city'] );
		$this->assertSame( 42, $handler->get_value( 'city' ) );
	}

	public function test_enabled_field_still_saves_a_scalar_in_its_original_namespace(): void {
		$handler = $this->handler();
		$this->controller( $handler )->save( $this->request( [ 'provider_id' => 'search_test', 'values' => [ 'mode' => 'manual', 'city' => '99' ] ] ) );
		$this->assertSame( 99, $this->options['woodev_search_test_city'] );
	}

	public function test_a_sibling_handler_controls_disablement_in_a_composite_tab(): void {
		$city = $this->handler();
		$city->unregister_setting( 'mode' );
		$mode = new class() extends \Woodev_Abstract_Settings {
			public function __construct() { parent::__construct( 'other' ); }
			protected function register_settings() { $this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'default' => 'manual' ] ); }
		};
		$composite = new Composite_Settings_Handler( 'search_test', [ $city, $mode ] );
		$this->assertSame( [ 'mode' => 'auto' ], $composite->filter_visible_values( [ 'city' => 99, 'mode' => 'auto' ] ) );
		$this->assertSame( [ 'city' => 99, 'mode' => 'manual' ], $composite->filter_visible_values( [ 'city' => 99, 'mode' => 'manual' ] ) );
	}

	public function test_search_select_requires_callbacks_and_a_scalar_setting(): void {
		$handler = $this->handler();
		Functions\when( '_doing_it_wrong' )->justReturn( null );
		$this->assertFalse( $handler->register_control( 'city', 'search-select', [] ) );
		$handler->register_setting( 'multi', \Woodev_Setting::TYPE_STRING, [ 'is_multi' => true ] );
		$this->assertFalse( $handler->register_control( 'multi', 'search-select', [ 'search_callback' => static fn( $term ) => [], 'label_callback' => static fn( $value ) => '' ] ) );
	}
}

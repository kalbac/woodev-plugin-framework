<?php
namespace Woodev\Tests\Unit;

use Mockery;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-page-registry.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-connection-result.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/interface-connection-test.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/interface-connection-status.php';

class SettingsPageRegistryTest extends TestCase {

	// ----- capability resolution (4 rules) -----

	public function test_capability_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_capability( null, false ) );
	}

	public function test_capability_flips_to_manage_woocommerce_for_wc_plugin(): void {
		$this->assertSame( 'manage_woocommerce', Settings_Page_Registry::resolve_capability( null, true ) );
	}

	public function test_explicit_capability_overrides_both(): void {
		$this->assertSame( 'edit_shop_orders', Settings_Page_Registry::resolve_capability( 'edit_shop_orders', true ) );
		$this->assertSame( 'edit_shop_orders', Settings_Page_Registry::resolve_capability( 'edit_shop_orders', false ) );
	}

	// ----- page capability = broadest reach -----

	public function test_page_capability_prefers_manage_woocommerce_reach(): void {
		$this->assertSame( 'manage_woocommerce', Settings_Page_Registry::resolve_page_capability( [ 'manage_options', 'manage_woocommerce' ] ) );
	}

	public function test_page_capability_all_neutral_is_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_page_capability( [ 'manage_options', 'manage_options' ] ) );
	}

	public function test_page_capability_empty_defaults_to_manage_options(): void {
		$this->assertSame( 'manage_options', Settings_Page_Registry::resolve_page_capability( [] ) );
	}

	// ----- tab aggregation -----

	private function provider( string $id, string $label, ?string $cap = null ): Settings_Provider {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( 'api_key' );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Ключ' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( '' );
		$setting->shouldReceive( 'get_control' )->andReturn( null );
		$setting->shouldReceive( 'is_sensitive' )->andReturn( false );
		$setting->shouldReceive( 'get_constant_name' )->andReturn( null );
		$setting->shouldReceive( 'is_required' )->andReturn( false );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( $id );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ 'api_key' => $setting ] );
		$handler->shouldReceive( 'get_value' )->andReturn( 'v' );

		return Settings_Provider::create(
			$id,
			$label,
			$handler,
			[ Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ) ],
			null === $cap ? [] : [ 'capability' => $cap ]
		);
	}

	public function test_build_tabs_dedupes_by_id_keeping_first_and_preserves_order(): void {
		$registry = Settings_Page_Registry::instance();

		$tabs = $registry->build_tabs(
			[
				[ 'provider' => $this->provider( 'b', 'B' ), 'is_woocommerce' => false ],
				[ 'provider' => $this->provider( 'a', 'A' ), 'is_woocommerce' => true ],
				[ 'provider' => $this->provider( 'b', 'B-dup' ), 'is_woocommerce' => false ],
			],
			static function (): bool {
				return true; // current_user_can stub: sees everything.
			}
		);

		$ids = array_column( $tabs, 'id' );
		$this->assertSame( [ 'b', 'a' ], $ids );
		$this->assertSame( 'B', $tabs[0]['label'] );
		$this->assertSame( 'manage_woocommerce', $tabs[1]['capability'] );
	}

	public function test_build_tabs_preserves_multiple_sections_in_order(): void {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( 'api_key' );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Ключ' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( '' );
		$setting->shouldReceive( 'get_control' )->andReturn( null );
		$setting->shouldReceive( 'is_sensitive' )->andReturn( false );
		$setting->shouldReceive( 'get_constant_name' )->andReturn( null );
		$setting->shouldReceive( 'is_required' )->andReturn( false );

		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_id' )->andReturn( 'cdek' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ 'api_key' => $setting ] );
		$handler->shouldReceive( 'get_value' )->andReturn( 'v' );

		$provider = Settings_Provider::create(
			'cdek',
			'СДЭК',
			$handler,
			[
				Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ),
				Settings_Section::create( 'advanced', 'Дополнительно', [ 'api_key' ] ),
			]
		);

		$tabs = Settings_Page_Registry::instance()->build_tabs(
			[ [ 'provider' => $provider, 'is_woocommerce' => false ] ],
			static function (): bool {
				return true;
			}
		);

		$this->assertCount( 2, $tabs[0]['sections'] );
		$this->assertSame( [ 'general', 'advanced' ], array_column( $tabs[0]['sections'], 'id' ) );
	}

	public function test_build_tabs_omits_tabs_the_user_cannot_access(): void {
		$registry = Settings_Page_Registry::instance();

		$tabs = $registry->build_tabs(
			[
				[ 'provider' => $this->provider( 'wc', 'WC', 'manage_woocommerce' ), 'is_woocommerce' => true ],
				[ 'provider' => $this->provider( 'admin', 'Admin', 'manage_options' ), 'is_woocommerce' => false ],
			],
			static function ( string $cap ): bool {
				return 'manage_woocommerce' === $cap; // shop manager.
			}
		);

		$this->assertSame( [ 'wc' ], array_column( $tabs, 'id' ) );
		$this->assertArrayHasKey( 'sections', $tabs[0] );
		$this->assertSame( 'general', $tabs[0]['sections'][0]['id'] );
		$this->assertArrayHasKey( 'api_key', $tabs[0]['sections'][0]['fields'] );
	}

	// ----- connection metadata + status (build_sections) -----

	public function test_build_sections_marks_connection_and_action_label(): void {
		$handler  = $this->make_connection_handler();
		$provider = Settings_Provider::create(
			'carrier',
			'Перевозчик',
			$handler,
			[ Settings_Section::create( 'api', 'Подключение', [ 'token' ], '', true, 'Проверить' ) ]
		);

		$registry = Settings_Page_Registry::instance();
		$sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

		$this->assertTrue( $sections[0]['is_connection'] );
		$this->assertSame( 'Проверить', $sections[0]['action_label'] );
		$this->assertTrue( $sections[0]['supports_test'] );
	}

	public function test_build_sections_includes_status_when_handler_provides_one(): void {
		$handler  = $this->make_connection_handler_with_status();
		$provider = Settings_Provider::create(
			'carrier',
			'Перевозчик',
			$handler,
			[ Settings_Section::create( 'api', 'Подключение', [ 'token' ], '', true, 'Проверить' ) ]
		);

		$registry = Settings_Page_Registry::instance();
		$sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

		$this->assertSame( [ 'success' => true, 'message' => 'Подключено' ], $sections[0]['status'] );
	}

	/**
	 * Builds a minimal `token` setting mock that Field_Schema::from_handler accepts.
	 */
	private function token_setting() {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( 'token' );
		$setting->shouldReceive( 'get_type' )->andReturn( 'string' );
		$setting->shouldReceive( 'get_name' )->andReturn( 'Токен' );
		$setting->shouldReceive( 'get_options' )->andReturn( [] );
		$setting->shouldReceive( 'is_is_multi' )->andReturn( false );
		$setting->shouldReceive( 'get_description' )->andReturn( '' );
		$setting->shouldReceive( 'get_control' )->andReturn( null );
		$setting->shouldReceive( 'is_sensitive' )->andReturn( false );
		$setting->shouldReceive( 'get_constant_name' )->andReturn( null );
		$setting->shouldReceive( 'is_required' )->andReturn( false );

		return $setting;
	}

	/**
	 * Handler that implements only the connection-test seam.
	 */
	private function make_connection_handler() {
		$handler = Mockery::mock( '\Woodev_Settings_Connection_Test' );
		$handler->shouldReceive( 'get_id' )->andReturn( 'carrier' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ 'token' => $this->token_setting() ] );
		$handler->shouldReceive( 'get_value' )->andReturn( '' );

		return $handler;
	}

	/**
	 * Handler that also implements the optional connection-status seam.
	 */
	private function make_connection_handler_with_status() {
		$handler = Mockery::mock( '\Woodev_Settings_Connection_Test, \Woodev_Settings_Connection_Status' );
		$handler->shouldReceive( 'get_id' )->andReturn( 'carrier' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ 'token' => $this->token_setting() ] );
		$handler->shouldReceive( 'get_value' )->andReturn( '' );
		$handler->shouldReceive( 'get_connection_status' )->andReturn( \Woodev_Connection_Result::success( 'Подключено' ) );

		return $handler;
	}

	/**
	 * Invokes a private/protected method via reflection.
	 *
	 * @param object  $object target instance.
	 * @param string  $method method name.
	 * @param mixed[] $args   positional arguments.
	 * @return mixed
	 */
	private function call_private( object $object, string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}

		return $ref->invokeArgs( $object, $args );
	}
}

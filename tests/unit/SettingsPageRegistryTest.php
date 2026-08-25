<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Settings\Tool_Result;

require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-field-schema.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-settings-page-registry.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-connection-result.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/interface-connection-test.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/interface-connection-status.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/settings/class-tool-result.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/settings/class-shipping-tool.php';

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
		$setting->shouldReceive( 'get_validate' )->andReturn( null );
		$setting->shouldReceive( 'get_show_if_conditions' )->andReturn( [] );

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
		$setting->shouldReceive( 'get_validate' )->andReturn( null );
		$setting->shouldReceive( 'get_show_if_conditions' )->andReturn( [] );

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

	public function test_build_sections_marks_tools_and_serializes_descriptors_without_callback(): void {
		$handler  = $this->make_connection_handler();
		$tool     = Shipping_Tool::create(
			'sweep',
			'Проверить',
			'',
			'Проверить',
			static fn( array $args ): Tool_Result => Tool_Result::success()
		);
		$provider = Settings_Provider::create(
			'shipping',
			'Доставка',
			$handler,
			[ Settings_Section::create( 'tools', 'Инструменты', [], '', false, '', true, [ $tool ] ) ]
		);

		$registry = Settings_Page_Registry::instance();
		$sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

		$this->assertTrue( $sections[0]['is_tools'] );
		$this->assertSame( [ [
			'id'          => 'sweep',
			'name'        => 'Проверить',
			'desc'        => '',
			'button'      => 'Проверить',
			'disabled'    => false,
			'status_text' => '',
		] ], $sections[0]['tools'] );
		$this->assertArrayNotHasKey( 'callback', $sections[0]['tools'][0] );
	}

	public function test_build_sections_non_tools_section_omits_tools_key(): void {
		$handler  = $this->make_connection_handler();
		$provider = Settings_Provider::create(
			'shipping',
			'Доставка',
			$handler,
			[ Settings_Section::create( 'general', 'Общие', [ 'token' ] ) ]
		);

		$registry = Settings_Page_Registry::instance();
		$sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

		$this->assertArrayNotHasKey( 'is_tools', $sections[0] );
		$this->assertArrayNotHasKey( 'tools', $sections[0] );
	}

	/**
	 * m3: the direct-construction door guards the same way the FILTER_TOOLS
	 * filter door does (Shipping_Tools_Registry::collect()) — a non-conforming
	 * entry is rejected and logged, the rest of the list still registers,
	 * rather than a page-wide fatal on `$tool->to_array()`.
	 */
	public function test_build_sections_rejects_a_non_conforming_tool_entry(): void {
		$handler   = $this->make_connection_handler();
		$conforming = Shipping_Tool::create(
			'sweep',
			'Проверить',
			'',
			'Проверить',
			static fn( array $args ): Tool_Result => Tool_Result::success()
		);
		$provider = Settings_Provider::create(
			'shipping',
			'Доставка',
			$handler,
			[ Settings_Section::create( 'tools', 'Инструменты', [], '', false, '', true, [ $conforming, 'not-a-tool' ] ) ]
		);

		Functions\expect( '_doing_it_wrong' )->once();

		$registry = Settings_Page_Registry::instance();
		$sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

		$this->assertCount( 1, $sections[0]['tools'] );
		$this->assertSame( 'sweep', $sections[0]['tools'][0]['id'] );
	}

	/**
	 * A section that declares NO setting ids (a deliberate empty stub, or a
	 * connection-only block) must render no fields at all. get_settings( [] )
	 * means "all settings" for a caller that wants the whole handler — but a
	 * section's own declared id list is never that caller; an empty
	 * declaration means zero fields, not every field of the handler.
	 */
	public function test_build_sections_empty_declared_ids_yields_no_fields(): void {
		$handler  = $this->make_connection_handler();
		$provider = Settings_Provider::create(
			'carrier',
			'Перевозчик',
			$handler,
			[ Settings_Section::create( 'widget', 'Виджет ЛК', [], '', true, 'Подключить' ) ]
		);

		$registry = Settings_Page_Registry::instance();
		$sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

		$this->assertSame( [], $sections[0]['fields'], 'a section declaring no setting ids must render no fields' );
	}

	/**
	 * A section that declares a subset of the handler's setting ids must
	 * render exactly that subset — no more, no less.
	 */
	public function test_build_sections_declared_subset_yields_exact_subset(): void {
		$handler = Mockery::mock( '\Woodev_Settings_Connection_Test' );
		$handler->shouldReceive( 'get_id' )->andReturn( 'carrier' );
		$handler->shouldReceive( 'get_settings' )->with( [ 'token' ] )->andReturn( [ 'token' => $this->token_setting() ] );
		$handler->shouldReceive( 'get_value' )->andReturn( '' );

		$provider = Settings_Provider::create(
			'carrier',
			'Перевозчик',
			$handler,
			[ Settings_Section::create( 'api', 'Подключение', [ 'token' ], '', true, 'Проверить' ) ]
		);

		$registry = Settings_Page_Registry::instance();
		$sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

		$this->assertSame( [ 'token' ], array_keys( $sections[0]['fields'] ), 'a section declaring a subset must render exactly that subset' );
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
		$setting->shouldReceive( 'get_validate' )->andReturn( null );
		$setting->shouldReceive( 'get_show_if_conditions' )->andReturn( [] );

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

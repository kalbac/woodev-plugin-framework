<?php
/**
 * Unit tests for a carrier plugin's ONE composite tab on `woodev-settings` (#1014).
 *
 * `Shipping_Plugin::get_settings_providers()` composes the carrier's own contribution
 * (`get_tab_settings_providers()`) and the framework's «Выгрузка» section into a single provider whose
 * id is the plugin id, and leaves «Выгрузка» out for a carrier that does not export orders.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Composite_Settings_Handler;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Settings\Export_Settings;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-export-settings.php';

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::get_settings_providers
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::plugin_exports_orders
 * @covers \Woodev\Framework\Settings\Settings_Page_Registry::build_tabs
 */
final class CarrierSettingsTabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'remove_action', 'add_filter', 'remove_filter', 'add_action' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias( static fn( string $name, $default = false ) => $default );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $status ) => $status );
		Functions\when( 'wc_string_to_bool' )->alias( static fn( $value ) => in_array( strtolower( (string) $value ), [ 'yes', 'true', '1' ], true ) );

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * A real `Shipping_Plugin` with only the identity and the carrier's contribution faked, so
	 * `get_settings_providers()` and the base class run for real.
	 *
	 * @param Settings_Provider[]|array $contribution what `get_tab_settings_providers()` returns.
	 * @return Shipping_Plugin&\Mockery\MockInterface
	 */
	private function carrier( string $id = 'cdek', array $contribution = [] ) {
		$plugin = Mockery::mock( Shipping_Plugin::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$plugin->shouldReceive( 'get_id' )->andReturn( $id );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'СДЭК' );
		$plugin->shouldReceive( 'get_export_settings' )->andReturnUsing( static fn() => new Export_Settings( $id ) );
		$plugin->shouldReceive( 'get_tab_settings_providers' )->andReturn( $contribution );

		return $plugin;
	}

	/** Registers the carrier the way an exporting one does: a provider owned by the plugin, plus a shipment handler. */
	private function make_it_export( Shipping_Plugin $plugin, bool $with_handler = true ): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( Orders_Provider::create( $plugin->get_id(), 'СДЭК', '_cdek_marker', [ $plugin->get_id() ] ), $plugin );

		if ( $with_handler ) {
			$registry->register_shipment_handler( $plugin->get_id(), Mockery::mock( Abstract_Shipment_Handler::class ) );
		}
	}

	/** The carrier's own credentials: a handler owning `api_key` and one section over it. */
	private function credentials( array $args = [], string $setting_id = 'api_key', string $section_id = 'credentials' ): Settings_Provider {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( $setting_id );

		$handler = Mockery::mock( \Woodev_Abstract_Settings::class );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ $setting_id => $setting ] );
		$handler->shouldReceive( 'get_id' )->andReturn( 'cdek' );

		return Settings_Provider::create_with_sections(
			'cdek',
			'Подключение',
			$handler,
			$args,
			Settings_Section::create( $section_id, 'Доступ', [ $setting_id ] )
		);
	}

	/**
	 * A contribution whose only section is a `create_connection()` block over one setting.
	 *
	 * @param bool $can_test whether its handler implements the connection-test interface.
	 */
	private function connection( string $section_id, string $setting_id, bool $can_test ): Settings_Provider {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( $setting_id );

		$handler = Mockery::mock( \Woodev_Abstract_Settings::class . ( $can_test ? ', \Woodev_Settings_Connection_Test' : '' ) );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ $setting_id => $setting ] );

		return Settings_Provider::create_with_sections(
			'cdek',
			'Подключение',
			$handler,
			[],
			Settings_Section::create_connection( $section_id, 'Доступ', [ $setting_id ], 'Проверить' )
		);
	}

	/** @return string[] */
	private function section_ids( Settings_Provider $provider ): array {
		return array_map( static fn( Settings_Section $section ) => $section->get_id(), $provider->get_sections() );
	}

	// ----- one tab per carrier -----

	public function test_an_exporting_carrier_gets_one_tab_with_the_export_section(): void {
		$plugin = $this->carrier();
		$this->make_it_export( $plugin );

		$providers = $plugin->get_settings_providers();

		$this->assertCount( 1, $providers );
		$this->assertSame( 'cdek', $providers[0]->get_id(), 'the tab id is the plugin id' );
		$this->assertSame( 'СДЭК', $providers[0]->get_label() );
		$this->assertSame( [ Export_Settings::SECTION_ID ], $this->section_ids( $providers[0] ) );
		$this->assertInstanceOf( Composite_Settings_Handler::class, $providers[0]->get_handler() );
		$this->assertSame(
			( new Export_Settings( 'cdek' ) )->get_owned_setting_ids(),
			$providers[0]->get_sections()[0]->get_setting_ids()
		);
	}

	public function test_the_carriers_own_section_lands_in_the_same_tab_ahead_of_the_export_section(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials() ] );
		$this->make_it_export( $plugin );

		$providers = $plugin->get_settings_providers();

		$this->assertCount( 1, $providers, 'still ONE provider: no second tab under the plugin id' );
		$this->assertSame( [ 'credentials', Export_Settings::SECTION_ID ], $this->section_ids( $providers[0] ) );

		$owned = array_keys( $providers[0]->get_handler()->get_settings() );
		$this->assertContains( 'api_key', $owned, 'the carrier handler is one of the composite children' );
		foreach ( ( new Export_Settings( 'cdek' ) )->get_owned_setting_ids() as $export_id ) {
			$this->assertContains( $export_id, $owned );
		}
	}

	public function test_the_tab_goes_through_build_tabs_as_a_single_tab(): void {
		$plugin = $this->carrier();
		$this->make_it_export( $plugin );

		$tabs = Settings_Page_Registry::instance()->build_tabs(
			array_map( static fn( $provider ) => [ 'provider' => $provider, 'is_woocommerce' => true ], $plugin->get_settings_providers() ),
			static fn( string $capability ): bool => true
		);

		$this->assertCount( 1, $tabs );
		$this->assertSame( 'cdek', $tabs[0]['id'] );
		$this->assertSame( [ 'export' ], array_column( $tabs[0]['sections'], 'id' ) );
		$this->assertSame( [ 'auto_export_orders', 'export_statuses' ], array_keys( $tabs[0]['sections'][0]['fields'] ) );
	}

	// ----- a carrier that does not export orders -----

	public function test_a_rates_only_carrier_gets_no_tab_at_all(): void {
		$plugin = $this->carrier();

		$this->assertSame( [], $plugin->get_settings_providers() );
	}

	public function test_a_carrier_with_a_provider_but_no_shipment_handler_does_not_export(): void {
		$plugin = $this->carrier();
		$this->make_it_export( $plugin, false );

		$this->assertSame( [], $plugin->get_settings_providers(), 'nothing to export without a handler' );
	}

	public function test_a_rates_only_carrier_keeps_its_own_sections_but_gets_no_export_section(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials() ] );

		$providers = $plugin->get_settings_providers();

		$this->assertCount( 1, $providers );
		$this->assertSame( [ 'credentials' ], $this->section_ids( $providers[0] ) );
	}

	public function test_another_plugins_provider_does_not_make_this_carrier_an_exporter(): void {
		$other = $this->carrier( 'boxberry' );
		$this->make_it_export( $other );

		$this->assertSame( [], $this->carrier( 'cdek' )->get_settings_providers() );
		$this->assertTrue( Orders_Registry::instance()->plugin_exports_orders( $other ) );
	}

	public function test_a_handler_registered_without_the_owning_plugin_is_not_an_export(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ) );
		$registry->register_shipment_handler( 'cdek', Mockery::mock( Abstract_Shipment_Handler::class ) );

		$this->assertFalse( $registry->plugin_exports_orders( $this->carrier() ) );
	}

	// ----- the contribution's tab-level attributes and its guard -----

	public function test_the_first_declared_capability_and_legacy_page_become_the_tabs(): void {
		$plugin = $this->carrier(
			'cdek',
			[
				$this->credentials( [ 'legacy_page' => 'wc-settings&tab=integration&section=cdek' ] ),
				$this->credentials( [ 'capability' => 'edit_shop_orders', 'legacy_page' => 'ignored' ], 'token', 'second' ),
			]
		);

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame( 'edit_shop_orders', $provider->get_declared_capability() );
		$this->assertSame( 'wc-settings&tab=integration&section=cdek', $provider->get_legacy_page() );
	}

	public function test_a_contribution_that_is_not_a_provider_is_reported_and_ignored(): void {
		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 0/' ), '2.0.2' );

		$plugin = $this->carrier( 'cdek', [ 'not-a-provider', $this->credentials() ] );

		$this->assertSame( [ 'credentials' ], $this->section_ids( $plugin->get_settings_providers()[0] ) );
	}

	// ----- a setting-id clash must not fatal the admin (#1014 round 2) -----

	public function test_a_contribution_reusing_an_export_setting_id_is_reported_and_left_out_while_the_export_section_stays(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials( [], 'auto_export_orders' ) ] );
		$this->make_it_export( $plugin );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::on( static fn( string $m ): bool => str_contains( $m, '"cdek"' ) && str_contains( $m, 'auto_export_orders' ) ), '2.0.2' );

		$providers = $plugin->get_settings_providers();

		$this->assertCount( 1, $providers );
		$this->assertSame( [ Export_Settings::SECTION_ID ], $this->section_ids( $providers[0] ) );
	}

	public function test_of_two_contributions_sharing_a_setting_id_the_second_is_left_out(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials(), $this->credentials( [], 'api_key', 'again' ) ] );
		$this->make_it_export( $plugin );

		Functions\expect( '_doing_it_wrong' )->once();

		$providers = $plugin->get_settings_providers();

		$this->assertSame( [ 'credentials', Export_Settings::SECTION_ID ], $this->section_ids( $providers[0] ) );
	}

	public function test_a_clash_without_export_leaves_the_first_contribution_in_place(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials(), $this->credentials( [], 'api_key', 'again' ) ] );

		Functions\expect( '_doing_it_wrong' )->once();

		$this->assertSame( [ 'credentials' ], $this->section_ids( $plugin->get_settings_providers()[0] ) );
	}

	// ----- a connection block of the carrier inside the composite tab (#1028) -----

	public function test_a_contributed_connection_section_is_tested_by_the_handler_that_owns_it(): void {
		$setting = Mockery::mock();
		$setting->shouldReceive( 'get_id' )->andReturn( 'token' );

		$handler = Mockery::mock( \Woodev_Abstract_Settings::class . ', \Woodev_Settings_Connection_Test' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [ 'token' => $setting ] );

		$plugin = $this->carrier(
			'cdek',
			[
				Settings_Provider::create_with_sections(
					'cdek',
					'Подключение',
					$handler,
					[],
					Settings_Section::create_connection( 'api', 'Доступ', [ 'token' ], 'Проверить' )
				),
			]
		);
		$this->make_it_export( $plugin );

		$composite = $plugin->get_settings_providers()[0]->get_handler();

		$this->assertTrue( $composite->supports_connection_test( 'api' ) );
		$this->assertFalse( $composite->supports_connection_test( Export_Settings::SECTION_ID ) );
	}

	public function test_a_contributed_handshake_connection_section_with_no_setting_ids_is_still_tested_by_its_handler(): void {
		$handler = Mockery::mock( \Woodev_Abstract_Settings::class . ', \Woodev_Settings_Connection_Test' );
		$handler->shouldReceive( 'get_settings' )->andReturn( [] );

		$plugin = $this->carrier(
			'cdek',
			[
				Settings_Provider::create_with_sections(
					'cdek',
					'Виджет',
					$handler,
					[],
					Settings_Section::create_connection( 'widget', 'Виджет ЛК', [], 'Подключить' )
				),
			]
		);
		$this->make_it_export( $plugin );

		$composite = $plugin->get_settings_providers()[0]->get_handler();

		$this->assertTrue( $composite->supports_connection_test( 'widget' ) );
	}

	public function test_a_contribution_whose_handler_is_not_a_settings_handler_is_reported_and_left_out(): void {
		$null_handler = Settings_Provider::create( 'cdek', 'Битый', null, [ Settings_Section::create( 'broken', 'Битый', [] ) ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 0.*not a Woodev_Abstract_Settings/' ), '2.0.2' );

		$plugin = $this->carrier( 'cdek', [ $null_handler, $this->credentials() ] );

		$this->assertSame( [ 'credentials' ], $this->section_ids( $plugin->get_settings_providers()[0] ) );
	}

	// ----- a section-id clash (a connection id is the owner-map key) must not replace an owner (#1033) -----

	public function test_two_contributions_declaring_the_same_connection_id_keep_the_first_owner_and_leave_the_second_out(): void {
		// the first cannot test, the second can: a silent replace would flip `supports_connection_test` to true
		$plugin = $this->carrier( 'cdek', [ $this->connection( 'api', 'token', false ), $this->connection( 'api', 'other_token', true ) ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 1.*"api"/' ), '2.0.2' );

		$provider  = $plugin->get_settings_providers()[0];
		$composite = $provider->get_handler();

		$this->assertSame( [ 'api' ], $this->section_ids( $provider ), 'one section, not two under one id' );
		$this->assertArrayNotHasKey( 'other_token', $composite->get_settings(), 'the whole contribution is left out' );
		$this->assertFalse( $composite->supports_connection_test( 'api' ), 'the owner is still the first contribution' );
	}

	public function test_a_connection_id_equal_to_an_ordinary_section_id_leaves_the_later_contribution_out(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials(), $this->connection( 'credentials', 'token', true ) ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 1.*"credentials"/' ), '2.0.2' );

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame( [ 'credentials' ], $this->section_ids( $provider ) );
		$this->assertFalse( $provider->get_handler()->supports_connection_test( 'credentials' ) );
	}

	public function test_an_ordinary_section_id_equal_to_an_earlier_connection_id_leaves_the_later_contribution_out(): void {
		$plugin = $this->carrier( 'cdek', [ $this->connection( 'api', 'token', true ), $this->credentials( [], 'api_key', 'api' ) ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 1.*"api"/' ), '2.0.2' );

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame( [ 'api' ], $this->section_ids( $provider ) );
		$this->assertTrue( $provider->get_handler()->supports_connection_test( 'api' ), 'the first owner is intact' );
	}

	public function test_a_connection_id_equal_to_the_export_section_id_is_left_out_and_the_export_section_survives(): void {
		$plugin = $this->carrier( 'cdek', [ $this->connection( Export_Settings::SECTION_ID, 'token', true ) ] );
		$this->make_it_export( $plugin );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 0.*"' . Export_Settings::SECTION_ID . '"/' ), '2.0.2' );

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame( [ Export_Settings::SECTION_ID ], $this->section_ids( $provider ) );
		$this->assertFalse( $provider->get_handler()->supports_connection_test( Export_Settings::SECTION_ID ), '«Выгрузка» is not a connection block' );
	}

	public function test_the_export_section_id_is_free_for_a_carrier_that_does_not_export(): void {
		$plugin = $this->carrier( 'cdek', [ $this->connection( Export_Settings::SECTION_ID, 'token', true ) ] );

		Functions\expect( '_doing_it_wrong' )->never();

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame( [ Export_Settings::SECTION_ID ], $this->section_ids( $provider ) );
		$this->assertTrue( $provider->get_handler()->supports_connection_test( Export_Settings::SECTION_ID ) );
	}

	// ----- a duplicate tab id is reported, first still wins -----

	public function test_a_second_provider_under_the_plugin_id_is_reported_and_the_first_one_wins(): void {
		$plugin = $this->carrier();
		$this->make_it_export( $plugin );
		$framework_tab = $plugin->get_settings_providers()[0];

		// what the old docblock invited: the carrier's own tab under its own plugin id
		$own_tab = $this->credentials();

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				Mockery::pattern( '/Settings_Page_Registry::build_tabs$/' ),
				Mockery::on( static fn( string $message ): bool => str_contains( $message, '"cdek"' ) && str_contains( $message, 'Подключение' ) && str_contains( $message, 'СДЭК' ) ),
				'2.0.2'
			);

		$tabs = Settings_Page_Registry::instance()->build_tabs(
			[
				[ 'provider' => $framework_tab, 'is_woocommerce' => true ],
				[ 'provider' => $own_tab, 'is_woocommerce' => true ],
			],
			static fn( string $capability ): bool => true
		);

		$this->assertCount( 1, $tabs );
		$this->assertSame( 'СДЭК', $tabs[0]['label'], 'the first provider is kept' );
		$this->assertSame( [ 'export' ], array_column( $tabs[0]['sections'], 'id' ) );
	}
}

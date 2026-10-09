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
use Woodev\Framework\Settings\Settings_Group;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Settings\Advanced_Settings;
use Woodev\Framework\Shipping\Settings\Export_Settings;
use Woodev\Framework\Shipping\Settings\Shipping_Tool;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-export-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-advanced-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-status-sync-tool.php';

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
		Functions\when( 'wc_get_order_statuses' )->justReturn( [ 'wc-pending' => 'Pending', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled' ] );
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
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'CDEK WooCommerce Shipping Method' );
		$plugin->shouldReceive( 'get_export_settings' )->andReturnUsing( static fn() => new Export_Settings( $id ) );
		$plugin->shouldReceive( 'get_advanced_settings' )->andReturnUsing( static fn() => new Advanced_Settings( $id ) );
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
		$handler->shouldReceive( 'get_setting' )->andReturnUsing( static fn( $id ) => $id === $setting_id ? $setting : null );

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

	/**
	 * The ids of the sections before the framework's trailing «Дополнительно» (every carrier has it; its own
	 * tests are below).
	 *
	 * @return string[]
	 */
	private function section_ids( Settings_Provider $provider ): array {
		return array_values(
			array_filter(
				array_map( static fn( Settings_Section $section ) => $section->get_id(), $provider->get_sections() ),
				static fn( string $id ): bool => Advanced_Settings::SECTION_ID !== $id
			)
		);
	}

	public function test_the_accepted_contribution_label_is_the_short_name_for_tab_and_emails(): void {
		$descriptor = $this->credentials();
		$carrier = $this->carrier( 'cdek', [ Settings_Provider::create_with_sections( 'cdek', 'СДЭК', $descriptor->get_handler(), [], ...$descriptor->get_sections() ) ] );
		$carrier->shouldReceive( 'get_plugin_name' )->andReturn( 'CDEK WooCommerce Shipping Method' );
		$this->assertSame( 'СДЭК', $carrier->get_settings_providers()[0]->get_label() );
		$this->assertSame( 'СДЭК', $carrier->get_carrier_name() );
	}

	public function test_a_later_contribution_cannot_replace_the_short_carrier_name(): void {
		$carrier = $this->carrier( 'cdek', [ $this->credentials(), $this->credentials( [], 'second_key', 'other' ) ] );
		$this->assertSame( 'Подключение', $carrier->get_carrier_name() );
	}

	// ----- one tab per carrier -----

	public function test_an_exporting_carrier_gets_one_tab_with_the_export_section(): void {
		$plugin = $this->carrier();
		$this->make_it_export( $plugin );

		$providers = $plugin->get_settings_providers();

		$this->assertCount( 1, $providers );
		$this->assertSame( 'cdek', $providers[0]->get_id(), 'the tab id is the plugin id' );
		$this->assertSame( 'CDEK WooCommerce Shipping Method', $providers[0]->get_label(), 'no contribution: plugin-name fallback' );
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
		$this->assertSame( [ 'export', 'advanced' ], array_column( $tabs[0]['sections'], 'id' ) );
		$this->assertSame( [ 'auto_export_orders', 'export_statuses', 'status_delivered' ], array_keys( $tabs[0]['sections'][0]['fields'] ) );
		$this->assertSame( [ 'enable_debug', 'disable_methods_on_cart' ], array_keys( $tabs[0]['sections'][1]['fields'] ) );
	}

	// ----- a carrier that does not export orders -----

	public function test_a_rates_only_carrier_gets_a_tab_with_only_the_additional_section(): void {
		$providers = $this->carrier()->get_settings_providers();

		$this->assertCount( 1, $providers, 'logging and hide-on-cart are every carrier\'s' );
		$this->assertSame( [ Advanced_Settings::SECTION_ID ], array_map( static fn( Settings_Section $s ) => $s->get_id(), $providers[0]->get_sections() ) );
	}

	public function test_a_carrier_with_a_provider_but_no_shipment_handler_does_not_export(): void {
		$plugin = $this->carrier();
		$this->make_it_export( $plugin, false );

		$this->assertSame( [], $this->section_ids( $plugin->get_settings_providers()[0] ), 'nothing to export without a handler' );
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

		$this->assertSame( [], $this->section_ids( $this->carrier( 'cdek' )->get_settings_providers()[0] ) );
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

	public function test_an_empty_section_id_is_an_id_like_any_other_and_a_later_clash_on_it_is_left_out(): void {
		// '' is a valid section id, so it must not double as the "no clash" sentinel
		$plugin = $this->carrier( 'cdek', [ $this->connection( '', 'token', false ), $this->connection( '', 'other_token', true ) ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 1/' ), '2.0.2' );

		$provider  = $plugin->get_settings_providers()[0];
		$composite = $provider->get_handler();

		$this->assertSame( [ '' ], $this->section_ids( $provider ), 'one section, not two under one id' );
		$this->assertArrayNotHasKey( 'other_token', $composite->get_settings() );
		$this->assertFalse( $composite->supports_connection_test( '' ), 'the first owner is not overwritten' );
	}

	public function test_a_connection_section_whose_id_is_a_numeric_string_keeps_its_owner(): void {
		// PHP turns the key '0' into int 0: the owner map must still carry it
		$plugin = $this->carrier( 'cdek', [ $this->connection( '0', 'token', true ) ] );

		Functions\expect( '_doing_it_wrong' )->never();

		$composite = $plugin->get_settings_providers()[0]->get_handler();

		$this->assertTrue( $composite->supports_connection_test( '0' ) );
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
				Mockery::on( static fn( string $message ): bool => str_contains( $message, '"cdek"' ) && str_contains( $message, 'Подключение' ) && str_contains( $message, 'CDEK WooCommerce Shipping Method' ) ),
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
		$this->assertSame( 'CDEK WooCommerce Shipping Method', $tabs[0]['label'], 'the first provider is kept' );
		$this->assertSame( [ 'export', 'advanced' ], array_column( $tabs[0]['sections'], 'id' ) );
	}

	// ----- «Выгрузка заказов», «Дополнительно» (s158) -----

	public function test_the_export_section_is_titled_vygruzka_zakazov_and_the_additional_one_is_last(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials() ] );
		$this->make_it_export( $plugin );

		$sections = $plugin->get_settings_providers()[0]->get_sections();

		$this->assertSame( [ 'credentials', 'export', 'advanced' ], array_map( static fn( Settings_Section $s ) => $s->get_id(), $sections ) );
		$this->assertSame( 'Выгрузка заказов', $sections[1]->get_label() );
		$this->assertSame( 'Дополнительно', $sections[2]->get_label() );
		$this->assertSame( [ 'enable_debug', 'disable_methods_on_cart' ], $sections[2]->get_setting_ids() );
	}

	public function test_a_contribution_reusing_a_logging_setting_id_is_left_out_while_the_additional_section_stays(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials( [], 'enable_debug' ) ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::on( static fn( string $m ): bool => str_contains( $m, 'enable_debug' ) ), '2.0.2' );

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame( [], $this->section_ids( $provider ) );
		$this->assertSame( [ Advanced_Settings::SECTION_ID ], array_map( static fn( Settings_Section $s ) => $s->get_id(), $provider->get_sections() ) );
	}

	public function test_a_section_id_advanced_in_a_contribution_is_left_out(): void {
		$plugin = $this->carrier( 'cdek', [ $this->connection( Advanced_Settings::SECTION_ID, 'token', true ) ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/"cdek".*entry 0.*"advanced"/' ), '2.0.2' );

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame( [], $this->section_ids( $provider ) );
	}

	// ----- what a carrier adds to «Дополнительно» (s165) -----

	private function advanced_of( $plugin ): Settings_Section {
		$sections = $plugin->get_settings_providers()[0]->get_sections();

		return $sections[ count( $sections ) - 1 ];
	}

	private function tool( string $id ): Shipping_Tool {
		return Shipping_Tool::create( $id, 'Заголовок', 'Описание', 'Кнопка', static fn() => null );
	}

	public function test_an_empty_extension_leaves_the_additional_section_exactly_as_the_framework_builds_it(): void {
		$plain = $this->carrier();

		$with_empty_extension = $this->carrier();
		$with_empty_extension->shouldReceive( 'get_advanced_section_extension' )->andReturn( [ 'actions' => [], 'groups' => [], 'description' => '' ] );

		$this->assertEquals( $this->advanced_of( $plain ), $this->advanced_of( $with_empty_extension ) );
		$this->assertSame( [], $this->advanced_of( $plain )->get_groups() );
		$this->assertSame( [], $this->advanced_of( $plain )->get_actions() );
		$this->assertSame( '', $this->advanced_of( $plain )->get_description() );
	}

	public function test_a_carrier_extends_the_additional_section_with_actions_a_group_and_a_description(): void {
		$plugin = $this->carrier();
		$plugin->shouldReceive( 'get_advanced_section_extension' )->andReturn(
			[
				'description' => '<p>Текст</p>',
				'actions'     => [ $this->tool( 'on' ), $this->tool( 'off' ) ],
				'groups'      => [ Settings_Group::create( 'hooks', 'Вебхуки' )->with_actions( [ 'on', 'off' ] ) ],
			]
		);

		$section = $this->advanced_of( $plugin );

		$this->assertSame( 'advanced', $section->get_id() );
		$this->assertSame( [ 'enable_debug', 'disable_methods_on_cart' ], $section->get_setting_ids() );
		$this->assertSame( '<p>Текст</p>', $section->get_description() );
		$this->assertSame( [ 'on', 'off' ], array_map( static fn( Shipping_Tool $t ) => $t->get_id(), $section->get_actions() ) );
		$this->assertSame( [ 'logging-and-cart', 'hooks' ], array_map( static fn( Settings_Group $g ) => $g->get_id(), $section->get_groups() ) );
		$this->assertSame( [ 'enable_debug', 'disable_methods_on_cart' ], $section->get_groups()[0]->get_setting_ids() );
		$this->assertSame( 'Журнал и корзина', $section->get_groups()[0]->get_title() );
	}

	public function test_actions_alone_do_not_group_the_sections_own_fields(): void {
		$plugin = $this->carrier();
		$plugin->shouldReceive( 'get_advanced_section_extension' )->andReturn( [ 'actions' => [ $this->tool( 'on' ) ] ] );

		$section = $this->advanced_of( $plugin );

		$this->assertCount( 1, $section->get_actions() );
		$this->assertSame( [], $section->get_groups() );
	}

	public function test_a_wrong_shaped_extension_is_reported_and_the_valid_part_kept(): void {
		$plugin = $this->carrier();
		$plugin->shouldReceive( 'get_advanced_section_extension' )->andReturn(
			[
				'actions'     => [ $this->tool( 'on' ), 'not a tool' ],
				'groups'      => 'not an array',
				'description' => 42,
				'colour'      => 'red',
			]
		);

		Functions\expect( '_doing_it_wrong' )->times( 4 );

		$section = $this->advanced_of( $plugin );

		$this->assertSame( [ 'on' ], array_map( static fn( Shipping_Tool $t ) => $t->get_id(), $section->get_actions() ) );
		$this->assertSame( [], $section->get_groups() );
		$this->assertSame( '', $section->get_description() );
	}

	// ----- the carrier's own fields inside «Выгрузка заказов» -----

	public function test_a_carrier_can_append_its_own_setting_to_the_export_section(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials( [], 'label_format', 'labels' ) ] );
		$plugin->shouldReceive( 'get_export_section_setting_ids' )->andReturn( [ 'label_format' ] );
		$this->make_it_export( $plugin );

		$sections = $plugin->get_settings_providers()[0]->get_sections();
		$export   = $sections[1];

		$this->assertSame( 'export', $export->get_id() );
		$this->assertSame( [ 'auto_export_orders', 'export_statuses', 'status_delivered', 'label_format' ], $export->get_setting_ids(), 'after the framework\'s own fields' );
	}

	public function test_an_export_section_id_nobody_owns_is_reported_and_skipped(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials() ] );
		$plugin->shouldReceive( 'get_export_section_setting_ids' )->andReturn( [ 'nobody_owns_this', 'status_delivered', 7 ] );
		$this->make_it_export( $plugin );

		Functions\expect( '_doing_it_wrong' )->times( 3 );

		$export = $plugin->get_settings_providers()[0]->get_sections()[1];

		$this->assertSame( [ 'auto_export_orders', 'export_statuses', 'status_delivered' ], $export->get_setting_ids(), 'a framework id is not added twice' );
	}

	public function test_a_carrier_that_does_not_export_ignores_the_export_section_seam_silently(): void {
		$plugin = $this->carrier( 'cdek', [ $this->credentials( [], 'label_format' ) ] );
		$plugin->shouldReceive( 'get_export_section_setting_ids' )->andReturn( [ 'label_format' ] );

		Functions\expect( '_doing_it_wrong' )->never();

		$this->assertSame( [ 'credentials' ], $this->section_ids( $plugin->get_settings_providers()[0] ) );
	}

	// ----- «Обновить статусы сейчас» -----

	private function export_with_cron_hook( Shipping_Plugin $plugin, ?string $hook ): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( $plugin->get_id(), 'СДЭК', '_cdek_marker', [ $plugin->get_id() ], null === $hook ? [] : [ 'cron_hook' => $hook ] ),
			$plugin
		);
		$registry->register_shipment_handler( $plugin->get_id(), Mockery::mock( Abstract_Shipment_Handler::class ) );
	}

	public function test_a_carrier_with_a_cron_hook_gets_the_refresh_button_under_the_export_fields(): void {
		Functions\when( 'has_action' )->justReturn( 10 );

		$plugin = $this->carrier();
		$this->export_with_cron_hook( $plugin, 'cdek_update_orders' );

		$export  = $plugin->get_settings_providers()[0]->get_sections()[0];
		$actions = $export->get_actions();

		$this->assertCount( 1, $actions );
		$this->assertSame( 'sync_delivery_statuses', $actions[0]->get_id() );
		$this->assertSame( 'Обновить статусы сейчас', $actions[0]->to_array()['button'] );
		$this->assertFalse( $actions[0]->is_disabled() );
	}

	public function test_a_webhook_only_carrier_with_no_cron_hook_gets_no_button(): void {
		$plugin = $this->carrier();
		$this->export_with_cron_hook( $plugin, null );

		$this->assertSame( [], $plugin->get_settings_providers()[0]->get_sections()[0]->get_actions() );
	}

	public function test_a_cron_hook_nobody_listens_to_gives_a_disabled_button(): void {
		Functions\when( 'has_action' )->justReturn( false );

		$plugin = $this->carrier();
		$this->export_with_cron_hook( $plugin, 'cdek_update_orders' );

		$action = $plugin->get_settings_providers()[0]->get_sections()[0]->get_actions()[0];

		$this->assertTrue( $action->is_disabled() );
		$this->assertNotSame( '', $action->get_status_text() );
	}
	public function test_declared_boxes_add_packaging_to_this_carriers_own_tab(): void {
		Functions\when( 'wc_get_dimension' )->returnArg( 1 );
		$plugin = $this->carrier();
		$plugin->shouldReceive( 'get_box_presets' )->andReturn( [
			[ 'id' => 'M', 'name' => 'Medium', 'length' => 10, 'width' => 10, 'height' => 10, 'cost_mode' => 'carrier' ],
			[ 'id' => 'L', 'name' => 'Large', 'length' => 20, 'width' => 20, 'height' => 20, 'cost_mode' => 'merchant' ],
		] );
		$providers = $plugin->get_settings_providers();
		$this->assertCount( 1, $providers );
		$this->assertSame( 'cdek', $providers[0]->get_id() );
		$this->assertSame( [ 'packaging' ], $this->section_ids( $providers[0] ) );
		$this->assertNotNull( $providers[0]->get_handler()->get_setting( 'box_M_charge' ) );
		$this->assertNotNull( $providers[0]->get_handler()->get_setting( 'box_L_cost' ) );
	}

	public function test_a_carrier_that_packs_and_exports_gets_its_own_then_packaging_then_export_then_additional_sections(): void {
		Functions\when( 'wc_get_dimension' )->returnArg( 1 );
		$plugin = $this->carrier( 'cdek', [ $this->credentials() ] );
		$plugin->shouldReceive( 'get_box_presets' )->andReturn( [
			[ 'id' => 'M', 'name' => 'Medium', 'length' => 10, 'width' => 10, 'height' => 10, 'cost_mode' => 'carrier' ],
		] );
		$this->make_it_export( $plugin );

		$provider = $plugin->get_settings_providers()[0];

		$this->assertSame(
			[ 'credentials', 'packaging', 'export', 'advanced' ],
			array_map( static fn( Settings_Section $section ) => $section->get_id(), $provider->get_sections() )
		);
		$this->assertSame(
			[ 'Упаковка', 'Выгрузка заказов', 'Дополнительно' ],
			array_map( static fn( Settings_Section $section ) => $section->get_label(), array_slice( $provider->get_sections(), 1 ) )
		);
		$this->assertNotNull( $provider->get_handler()->get_setting( 'box_M_charge' ) );
		$this->assertNotNull( $provider->get_handler()->get_setting( 'auto_export_orders' ) );
		$this->assertNotNull( $provider->get_handler()->get_setting( 'enable_debug' ) );
	}

}

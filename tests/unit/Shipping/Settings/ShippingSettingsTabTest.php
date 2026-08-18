<?php
/**
 * Unit tests for Shipping_Settings_Tab — the registrar of the store-level «Доставка»
 * tab (design S1/S9, issue #362). Section visibility is DERIVED from declarations
 * (declare_shipping_plugin() / set_location_section() / declare_map_needed()), never
 * configured — this file pins that derivation, plus the composite handler `register()`
 * builds out of whichever sections were declared.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Settings\Composite_Settings_Handler;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-section.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-page-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-composite-settings-handler.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-environment.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';

class ShippingSettingsTabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// hook_once()'s add_action() is never actually fired in these tests — register()
		// is invoked directly, exactly like LocationProviderRegistryTest invokes collect()
		// directly instead of firing a real `init` action.
		Functions\when( 'add_action' )->justReturn( true );

		// register() now also boots Checkout_Field_Policy (Task 6, issue #362), which
		// hooks two real filters — stub add_filter so that call never touches the real
		// WP function.
		Functions\when( 'add_filter' )->justReturn( true );

		// Building the «Поля» section now constructs a real Checkout_Field_Settings
		// (Task 5), which registers settings/controls through Woodev_Abstract_Settings —
		// stub the WP primitives that path touches, same as LocationProviderRegistryTest
		// stubs for Location_Settings.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				return $default;
			}
		);

		Shipping_Settings_Tab::reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Shipping_Settings_Tab::reset_for_tests();
		Settings_Page_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	/**
	 * A minimal stand-in for Location_Settings — Composite_Settings_Handler's
	 * constructor only ever calls get_settings() on a child, so that is the only
	 * method this stub needs.
	 *
	 * @return \Woodev_Abstract_Settings
	 */
	private function location_handler_stub() {
		$handler = Mockery::mock();
		$handler->shouldReceive( 'get_settings' )->andReturn( [] );

		return $handler;
	}

	public function test_no_tab_without_a_shipping_plugin(): void {
		$tab = Shipping_Settings_Tab::instance();

		$this->assertFalse( $tab->is_needed() );
		$this->assertSame( [], $tab->build_sections() ); // pure builder, no WP.
	}

	public function test_sections_follow_declarations(): void {
		$tab = Shipping_Settings_Tab::instance();

		$tab->declare_shipping_plugin(); // any Shipping_Plugin → tab + «Поля».
		$this->assertTrue( $tab->is_needed() );
		$this->assertSame(
			[ 'fields' ],
			array_map( static function ( $s ) { return $s->get_id(); }, $tab->build_sections() )
		);

		$tab->set_location_section( $this->location_handler_stub(), [ 'active_provider', 'field_mode' ] );
		$tab->declare_map_needed();
		$this->assertSame(
			[ 'location', 'fields', 'map' ],
			array_map( static function ( $s ) { return $s->get_id(); }, $tab->build_sections() )
		);
	}

	/**
	 * set_location_section()/declare_map_needed() called BEFORE declare_shipping_plugin()
	 * must not make the tab appear early — is_needed() gates everything, exactly like
	 * Location_Provider_Registry's own activation gate gates its collection.
	 */
	public function test_location_and_map_declarations_alone_do_not_open_the_tab(): void {
		$tab = Shipping_Settings_Tab::instance();

		$tab->set_location_section( $this->location_handler_stub(), [ 'active_provider' ] );
		$tab->declare_map_needed();

		$this->assertFalse( $tab->is_needed() );
		$this->assertSame( [], $tab->build_sections() );
	}

	public function test_register_builds_one_service_with_a_composite_handler(): void {
		$tab = Shipping_Settings_Tab::instance();

		$tab->declare_shipping_plugin();
		$tab->set_location_section( $this->location_handler_stub(), [ 'active_provider', 'field_mode' ] );
		$tab->declare_map_needed();

		// register() is the callback hook_once() bound to `init` priority 25; called
		// directly here rather than firing a real `init` action, matching how
		// LocationProviderRegistryTest invokes collect() directly.
		$tab->register();

		$provider = Settings_Page_Registry::instance()->get_provider( Shipping_Settings_Tab::SERVICE_ID );

		$this->assertNotNull( $provider );
		$this->assertSame( 'shipping', $provider->get_id() );
		$this->assertSame( 'Доставка', $provider->get_label() );
		$this->assertInstanceOf( Composite_Settings_Handler::class, $provider->get_handler() );
		$this->assertSame(
			[ 'location', 'fields', 'map' ],
			array_map( static function ( $s ) { return $s->get_id(); }, $provider->get_sections() )
		);
	}

	/**
	 * Without a location handler or a Pickup_Handler, register() still builds a valid
	 * composite over the «Поля» handler alone — «Локация» and «Карта» simply never
	 * declared themselves.
	 */
	public function test_register_without_location_or_map_still_registers_fields_only(): void {
		$tab = Shipping_Settings_Tab::instance();
		$tab->declare_shipping_plugin();
		$tab->register();

		$provider = Settings_Page_Registry::instance()->get_provider( Shipping_Settings_Tab::SERVICE_ID );

		$this->assertSame(
			[ 'fields' ],
			array_map( static function ( $s ) { return $s->get_id(); }, $provider->get_sections() )
		);
	}

	/**
	 * declare_shipping_plugin() must be idempotent — a second call (a second plugin in
	 * the fleet, exactly like Location_Provider_Registry::declare_needed()) must not
	 * hook register() twice.
	 */
	public function test_declare_shipping_plugin_hooks_init_only_once(): void {
		$hook_calls = 0;
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hook_calls ) {
				if ( 'init' === $hook ) {
					++$hook_calls;
				}

				return true;
			}
		);

		$tab = Shipping_Settings_Tab::instance();
		$tab->declare_shipping_plugin();
		$tab->declare_shipping_plugin();

		$this->assertSame( 1, $hook_calls );
	}
}

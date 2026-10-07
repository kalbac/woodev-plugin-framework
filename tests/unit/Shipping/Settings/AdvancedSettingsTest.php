<?php
/**
 * Unit: the «Дополнительно» settings of a carrier plugin (s158) — logging and hide-on-cart.
 *
 * Both keys are the ones the shipped v1 carrier plugins stored inside their integration option
 * (`woocommerce_{plugin id}_settings`), so `Advanced_Settings` carries them over once, like `Export_Settings`.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Settings\Advanced_Settings;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-advanced-settings.php';

/**
 * @covers \Woodev\Framework\Shipping\Settings\Advanced_Settings
 */
final class AdvancedSettingsTest extends TestCase {

	private const PLUGIN_ID = 'cdek_shipping';

	private const LEGACY_OPTION = 'woocommerce_cdek_shipping_settings';

	private const DEBUG_OPTION = 'woodev_cdek_shipping_advanced_enable_debug';

	private const CART_OPTION = 'woodev_cdek_shipping_advanced_disable_methods_on_cart';

	private const FLAG_OPTION = 'woodev_cdek_shipping_advanced_migrated_from_integration';

	/** @var array<string,mixed> the `wp_options` table, in memory. */
	private array $options = [];

	/** @var array<int,string> every option name written. */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		$this->writes  = [];

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;
				$this->writes[]         = $name;

				return true;
			}
		);
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wc_string_to_bool' )->alias(
			static function ( $value ) {
				if ( ! is_string( $value ) && ! is_bool( $value ) ) {
					throw new \TypeError( 'wc_string_to_bool() takes string|bool' );
				}

				return is_bool( $value ) ? $value : in_array( strtolower( $value ), [ 'yes', 'true', '1' ], true );
			}
		);
	}

	private function settings(): Advanced_Settings {
		return new Advanced_Settings( self::PLUGIN_ID );
	}

	public function test_the_section_owns_logging_then_hide_on_cart(): void {
		$this->assertSame( [ 'enable_debug', 'disable_methods_on_cart' ], $this->settings()->get_owned_setting_ids() );
	}

	public function test_both_are_off_by_default(): void {
		$settings = $this->settings();

		$this->assertFalse( $settings->is_logging_enabled() );
		$this->assertFalse( $settings->is_hidden_on_cart() );
	}

	public function test_logging_carries_a_visible_warning_and_a_tooltip(): void {
		$control = $this->settings()->get_setting( 'enable_debug' )->get_control();

		$this->assertSame( 'Логирование', $this->settings()->get_setting( 'enable_debug' )->get_name() );
		$this->assertSame( 'Не включайте без необходимости: в лог записывается много данных', $control->get_description() );
		$this->assertNotSame( '', $control->get_tooltip() );
	}

	public function test_saved_values_are_stored_under_the_plugins_advanced_options_and_read_back(): void {
		$settings = $this->settings();
		$settings->update_value( 'enable_debug', true );
		$settings->update_value( 'disable_methods_on_cart', true );

		$this->assertSame( 'yes', $this->options[ self::DEBUG_OPTION ] );
		$this->assertSame( 'yes', $this->options[ self::CART_OPTION ] );

		$reloaded = $this->settings();
		$this->assertTrue( $reloaded->is_logging_enabled() );
		$this->assertTrue( $reloaded->is_hidden_on_cart() );
	}

	// ----- the v1 carry-over -----

	public function test_a_v1_site_with_logging_and_hide_on_cart_on_keeps_both(): void {
		$this->options[ self::LEGACY_OPTION ] = [ 'enable_debug' => 'yes', 'disable_methods_on_cart' => 'yes', 'api_key' => 'secret' ];

		$settings = $this->settings();

		$this->assertTrue( $settings->is_logging_enabled() );
		$this->assertTrue( $settings->is_hidden_on_cart() );
		$this->assertSame( 'yes', $this->options[ self::FLAG_OPTION ] );
	}

	public function test_a_v1_site_that_never_touched_the_keys_gets_the_defaults_and_nothing_is_invented(): void {
		$this->options[ self::LEGACY_OPTION ] = [ 'api_key' => 'secret' ];

		$settings = $this->settings();

		$this->assertFalse( $settings->is_logging_enabled() );
		$this->assertArrayNotHasKey( self::DEBUG_OPTION, $this->options );
		$this->assertArrayNotHasKey( self::CART_OPTION, $this->options );
		$this->assertSame( 'yes', $this->options[ self::FLAG_OPTION ] );
	}

	public function test_no_v1_option_means_nothing_is_written_and_no_flag(): void {
		$this->settings();

		$this->assertSame( [], $this->writes );
	}

	public function test_a_malformed_v1_option_is_not_done(): void {
		$this->options[ self::LEGACY_OPTION ] = 'garbage';

		$this->settings();

		$this->assertSame( [], $this->writes );
	}

	public function test_a_value_not_written_by_v1_is_treated_as_not_set(): void {
		$this->options[ self::LEGACY_OPTION ] = [ 'enable_debug' => 1, 'disable_methods_on_cart' => [ 'yes' ] ];

		$settings = $this->settings(); // a TypeError from wc_string_to_bool() would surface here

		$this->assertFalse( $settings->is_logging_enabled() );
		$this->assertArrayNotHasKey( self::DEBUG_OPTION, $this->options );
	}

	public function test_a_value_already_saved_on_the_new_page_is_never_overwritten_and_v1_stays_as_it_was(): void {
		$legacy                               = [ 'enable_debug' => 'yes' ];
		$this->options[ self::LEGACY_OPTION ] = $legacy;
		$this->options[ self::DEBUG_OPTION ]  = 'no';

		$this->assertFalse( $this->settings()->is_logging_enabled() );
		$this->assertSame( $legacy, $this->options[ self::LEGACY_OPTION ] );
		$this->assertNotContains( self::LEGACY_OPTION, $this->writes );
	}

	public function test_the_integration_handlers_option_key_wins_and_is_not_asked_once_done(): void {
		$this->options['woocommerce_handler_given_settings'] = [ 'enable_debug' => 'yes' ];

		$settings = new Advanced_Settings( self::PLUGIN_ID, static fn(): ?string => 'woocommerce_handler_given_settings' );
		$this->assertTrue( $settings->is_logging_enabled() );

		$called = false;
		new Advanced_Settings(
			self::PLUGIN_ID,
			static function () use ( &$called ): ?string {
				$called = true;

				return null;
			}
		);
		$this->assertFalse( $called );
	}
}

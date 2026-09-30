<?php
/**
 * Unit: the «Выгрузка» settings of a carrier plugin (#1007, round 3).
 *
 * The auto-export settings live on the plugin's own tab of the framework settings page
 * (`woodev-settings`), not on WooCommerce → Settings → Integrations. The shipped v1 carrier plugins
 * kept the same two values inside their integration option (`woocommerce_{plugin id}_settings`), so
 * `Export_Settings` carries them over once, without touching the v1 option.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace Woodev\Tests\Unit\Shipping\Settings;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Settings\Export_Settings;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/admin/orders/class-order-actions.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-order-automation.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-export-settings.php';

/**
 * @covers \Woodev\Framework\Shipping\Settings\Export_Settings
 */
final class ExportSettingsTest extends TestCase {

	private const PLUGIN_ID = 'cdek_shipping';

	private const LEGACY_OPTION = 'woocommerce_cdek_shipping_settings';

	private const AUTO_EXPORT_OPTION = 'woodev_cdek_shipping_export_auto_export_orders';

	private const STATUSES_OPTION = 'woodev_cdek_shipping_export_export_statuses';

	private const FLAG_OPTION = 'woodev_cdek_shipping_export_migrated_from_integration';

	/** @var array<string,mixed> the `wp_options` table, in memory. */
	private array $options = [];

	/** @var array<int,string> every option name written, in order. */
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
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_string_to_bool' )->alias( static fn( $value ) => in_array( strtolower( (string) $value ), [ 'yes', 'true', '1' ], true ) );
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $status ) => 'Status ' . $status );
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	private function settings(): Export_Settings {
		return new Export_Settings( self::PLUGIN_ID );
	}

	// ----- the settings themselves -----

	public function test_the_section_owns_the_two_v1_keys_in_order(): void {
		$this->assertSame( [ 'auto_export_orders', 'export_statuses' ], $this->settings()->get_owned_setting_ids() );
	}

	public function test_nothing_stored_means_auto_export_off_and_processing_picked(): void {
		$settings = $this->settings();

		$this->assertFalse( $settings->is_auto_export_enabled(), 'default OFF (spec §12)' );
		$this->assertSame( [ 'processing' ], $settings->get_export_statuses() );
	}

	public function test_only_the_statuses_the_export_button_is_offered_in_can_be_picked(): void {
		$options = $this->settings()->get_setting( 'export_statuses' )->get_options();

		$this->assertSame( array_map( static fn( $status ) => 'wc-' . $status, \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::EXPORTABLE_STATUSES ), array_keys( $options ) );
	}

	// ----- save / load -----

	public function test_values_saved_through_the_handler_are_stored_under_the_plugins_own_options_and_read_back(): void {
		$settings = $this->settings();

		$settings->update_value( 'auto_export_orders', true );
		$settings->update_value( 'export_statuses', [ 'wc-on-hold', 'wc-processing' ] );

		$this->assertSame( 'yes', $this->options[ self::AUTO_EXPORT_OPTION ] );
		$this->assertSame( [ 'wc-on-hold', 'wc-processing' ], $this->options[ self::STATUSES_OPTION ] );

		$reloaded = $this->settings();
		$this->assertTrue( $reloaded->is_auto_export_enabled() );
		$this->assertSame( [ 'on-hold', 'processing' ], $reloaded->get_export_statuses() );

		$reloaded->update_value( 'auto_export_orders', false );
		$this->assertSame( 'no', $this->options[ self::AUTO_EXPORT_OPTION ] );
		$this->assertFalse( $this->settings()->is_auto_export_enabled() );
	}

	public function test_a_status_outside_the_list_is_refused_on_save(): void {
		$this->expectException( \Woodev_Plugin_Exception::class );

		$this->settings()->update_value( 'export_statuses', [ 'wc-not-a-status' ] );
	}

	public function test_the_statuses_are_read_without_the_wc_prefix_once_each_and_an_empty_selection_means_none(): void {
		$this->options[ self::STATUSES_OPTION ] = [ 'wc-processing', 'on-hold', 'wc-processing', '', 7 ];
		$this->assertSame( [ 'processing', 'on-hold' ], $this->settings()->get_export_statuses() );

		$this->options[ self::STATUSES_OPTION ] = [];
		$this->assertSame( [], $this->settings()->get_export_statuses(), 'nothing picked => nothing exported' );
	}

	// ----- a saved status the framework no longer exports on -----

	public function test_an_unsupported_saved_status_stays_in_the_list_marked_and_is_warned_about(): void {
		$this->options[ self::STATUSES_OPTION ] = [ 'wc-processing', 'wc-shipped' ];

		$settings = $this->settings();
		$options  = $settings->get_setting( 'export_statuses' )->get_options();

		$this->assertSame( [ 'wc-shipped' => 'Status shipped' ], $settings->get_unsupported_statuses() );
		$this->assertArrayHasKey( 'wc-shipped', $options, 'the saved value the field could not show would be erased by the next save' );
		$this->assertStringContainsString( 'не поддерживается', $options['wc-shipped'] );
		$this->assertStringContainsString( 'Статус «Status shipped» больше не поддерживается', $settings->get_section_description() );
	}

	public function test_an_unsupported_saved_status_survives_a_save_that_keeps_it_and_goes_when_deselected(): void {
		$this->options[ self::STATUSES_OPTION ] = [ 'wc-processing', 'wc-shipped' ];

		$settings = $this->settings();
		$settings->update_value( 'export_statuses', [ 'wc-processing', 'wc-shipped' ] );
		$this->assertSame( [ 'wc-processing', 'wc-shipped' ], $this->options[ self::STATUSES_OPTION ] );

		$settings->update_value( 'export_statuses', [ 'wc-processing' ] );
		$this->assertSame( [], $this->settings()->get_unsupported_statuses(), 'gone only when the merchant deselects it' );
		$this->assertStringNotContainsString( 'больше не поддерживается', $this->settings()->get_section_description() );
	}

	public function test_no_warning_when_every_saved_status_is_supported(): void {
		$this->options[ self::STATUSES_OPTION ] = [ 'wc-processing', 'wc-on-hold' ];

		$settings = $this->settings();

		$this->assertSame( [], $settings->get_unsupported_statuses() );
		$this->assertStringNotContainsString( 'больше не поддерживается', $settings->get_section_description() );
	}

	// ----- migration from the v1 integration option -----

	public function test_a_v1_site_with_auto_export_on_keeps_it_on(): void {
		$this->options[ self::LEGACY_OPTION ] = [
			'auto_export_orders' => 'yes',
			'export_statuses'    => [ 'wc-processing', 'wc-on-hold' ],
			'api_key'            => 'secret',
		];

		$settings = $this->settings();

		$this->assertTrue( $settings->is_auto_export_enabled() );
		$this->assertSame( [ 'processing', 'on-hold' ], $settings->get_export_statuses() );
		$this->assertSame( 'yes', $this->options[ self::AUTO_EXPORT_OPTION ] );
		$this->assertSame( [ 'wc-processing', 'wc-on-hold' ], $this->options[ self::STATUSES_OPTION ] );
		$this->assertSame( 'yes', $this->options[ self::FLAG_OPTION ] );
	}

	public function test_a_v1_site_with_auto_export_off_keeps_it_off_with_its_statuses(): void {
		$this->options[ self::LEGACY_OPTION ] = [
			'auto_export_orders' => 'no',
			'export_statuses'    => [ 'wc-on-hold' ],
		];

		$settings = $this->settings();

		$this->assertFalse( $settings->is_auto_export_enabled() );
		$this->assertSame( 'no', $this->options[ self::AUTO_EXPORT_OPTION ] );
		$this->assertSame( [ 'on-hold' ], $settings->get_export_statuses() );
	}

	public function test_a_status_saved_without_the_wc_prefix_is_carried_over_with_it_once(): void {
		$this->options[ self::LEGACY_OPTION ] = [
			'auto_export_orders' => 'yes',
			'export_statuses'    => [ 'processing', 'wc-processing', 'on-hold', '', 7 ],
		];

		$this->settings();

		$this->assertSame( [ 'wc-processing', 'wc-on-hold' ], $this->options[ self::STATUSES_OPTION ] );
	}

	public function test_an_unsupported_v1_status_is_carried_over_untouched_and_reported(): void {
		$this->options[ self::LEGACY_OPTION ] = [
			'auto_export_orders' => 'yes',
			'export_statuses'    => [ 'wc-processing', 'wc-shipped' ],
		];

		$settings = $this->settings();

		$this->assertSame( [ 'wc-processing', 'wc-shipped' ], $this->options[ self::STATUSES_OPTION ], 'never erased silently' );
		$this->assertSame( [ 'wc-shipped' => 'Status shipped' ], $settings->get_unsupported_statuses() );
	}

	public function test_a_v1_site_that_never_touched_the_keys_gets_the_defaults_and_nothing_is_invented(): void {
		$this->options[ self::LEGACY_OPTION ] = [ 'api_key' => 'secret' ];

		$settings = $this->settings();

		$this->assertFalse( $settings->is_auto_export_enabled() );
		$this->assertArrayNotHasKey( self::AUTO_EXPORT_OPTION, $this->options );
		$this->assertArrayNotHasKey( self::STATUSES_OPTION, $this->options );
		$this->assertSame( 'yes', $this->options[ self::FLAG_OPTION ], 'still one-time' );
	}

	public function test_a_fresh_install_with_no_integration_option_is_marked_done_and_left_on_defaults(): void {
		$settings = $this->settings();

		$this->assertFalse( $settings->is_auto_export_enabled() );
		$this->assertSame( [ self::FLAG_OPTION ], $this->writes );
	}

	public function test_a_malformed_integration_option_is_ignored(): void {
		$this->options[ self::LEGACY_OPTION ] = 'garbage';

		$settings = $this->settings();

		$this->assertFalse( $settings->is_auto_export_enabled() );
		$this->assertSame( 'yes', $this->options[ self::FLAG_OPTION ] );
	}

	public function test_the_migration_runs_once_and_a_second_run_changes_nothing(): void {
		$this->options[ self::LEGACY_OPTION ] = [
			'auto_export_orders' => 'yes',
			'export_statuses'    => [ 'wc-processing' ],
		];

		$this->settings();
		$written = $this->writes;

		// the merchant turns it off on the new page, and the v1 option still says «yes»
		$this->settings()->update_value( 'auto_export_orders', false );
		$written_after_save = $this->writes;

		$again = $this->settings();

		$this->assertSame( $written_after_save, $this->writes, 'a later construction writes nothing' );
		$this->assertCount( 3, $written, 'auto-export, statuses, the done flag' );
		$this->assertFalse( $again->is_auto_export_enabled(), 'the v1 value does not come back over the merchant\'s choice' );
	}

	public function test_a_value_already_saved_on_the_new_page_is_never_overwritten_by_the_v1_one(): void {
		$this->options[ self::LEGACY_OPTION ]        = [
			'auto_export_orders' => 'yes',
			'export_statuses'    => [ 'wc-on-hold' ],
		];
		$this->options[ self::AUTO_EXPORT_OPTION ] = 'no';
		$this->options[ self::STATUSES_OPTION ]    = [ 'wc-processing' ];

		$settings = $this->settings();

		$this->assertFalse( $settings->is_auto_export_enabled() );
		$this->assertSame( [ 'processing' ], $settings->get_export_statuses() );
	}

	public function test_the_v1_option_is_left_as_it_was(): void {
		$legacy = [
			'auto_export_orders' => 'yes',
			'export_statuses'    => [ 'wc-processing' ],
			'api_key'            => 'secret',
		];

		$this->options[ self::LEGACY_OPTION ] = $legacy;

		$this->settings();

		$this->assertSame( $legacy, $this->options[ self::LEGACY_OPTION ] );
		$this->assertNotContains( self::LEGACY_OPTION, $this->writes );
	}
}

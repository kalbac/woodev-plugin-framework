<?php
/**
 * Unit: the auto-export settings every carrier gets from the framework (#1007).
 *
 * `Shipping_Integration` is the carrier's own settings screen (WooCommerce → Settings → Integration),
 * one per carrier plugin, and the place the shipped v1 plugins kept `auto_export_orders` and
 * `export_statuses`. The framework now supplies both, so a carrier plugin gets them without code.
 *
 * @package Woodev\Tests\Unit\Shipping\Settings
 */

namespace {

	if ( ! class_exists( 'WC_Integration', false ) ) {
		/**
		 * Minimal WooCommerce integration base — same shape as the stub in
		 * ShippingIntegrationIsConfiguredTest, whichever file loads first wins.
		 */
		class WC_Integration {

			/** @var string */
			public $id;

			/** @var array */
			public $form_fields = [];

			/** @var string */
			public $method_title = '';

			/** @var string */
			public $method_description = '';

			/** @var array */
			public $settings = [];
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Settings {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
	use Woodev\Framework\Shipping\Order\Order_Automation;
	use Woodev\Framework\Shipping\Settings\Shipping_Integration;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Integration double exposing the two protected builders.
	 */
	class Woodev_Test_Auto_Export_Integration extends Shipping_Integration {

		/** @var string */
		public $id = 'auto_export_test';

		/** @var mixed what the merchant saved under `export_statuses` */
		public $saved_statuses = [ 'wc-processing' ];

		public function __construct() {}

		/** @return mixed */
		public function get_option( $key, $empty_value = null ) {
			return $this->saved_statuses;
		}

		public function prepare_field(): void {
			$this->prepare_export_statuses_field();
		}

		/** @return array<string,string> */
		public function unsupported(): array {
			return $this->get_unsupported_export_statuses();
		}

		/** @return array<string,array<string,mixed>> */
		public function auto_export_fields(): array {
			return $this->get_auto_export_form_fields();
		}

		/** @return array<string,string> */
		public function status_options(): array {
			return $this->get_export_status_options();
		}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return Shipping_Plugin */
		protected function init_plugin(): Shipping_Plugin {
			throw new \LogicException( 'not needed' );
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Settings\Shipping_Integration::get_auto_export_form_fields
	 * @covers \Woodev\Framework\Shipping\Settings\Shipping_Integration::get_export_status_options
	 * @covers \Woodev\Framework\Shipping\Settings\Shipping_Integration::get_unsupported_export_statuses
	 * @covers \Woodev\Framework\Shipping\Settings\Shipping_Integration::prepare_export_statuses_field
	 */
	final class ShippingIntegrationAutoExportFieldsTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $status ): string => 'Name of ' . $status );
		}

		public function test_the_keys_are_the_ones_the_v1_carrier_plugins_stored(): void {
			$fields = ( new Woodev_Test_Auto_Export_Integration() )->auto_export_fields();

			$this->assertSame( [ 'auto_export_orders', 'export_statuses' ], array_keys( $fields ), 'a v1 site keeps what it had chosen' );
			$this->assertSame( 'auto_export_orders', Order_Automation::SETTING_AUTO_EXPORT );
			$this->assertSame( 'export_statuses', Order_Automation::SETTING_EXPORT_STATUSES );
		}

		public function test_auto_export_is_off_by_default(): void {
			$fields = ( new Woodev_Test_Auto_Export_Integration() )->auto_export_fields();

			$this->assertSame( 'checkbox', $fields['auto_export_orders']['type'] );
			$this->assertSame( 'no', $fields['auto_export_orders']['default'] );
			$this->assertSame( 'Автоэкспорт', $fields['auto_export_orders']['title'] );
		}

		public function test_the_statuses_are_a_multiselect_defaulting_to_processing(): void {
			$fields = ( new Woodev_Test_Auto_Export_Integration() )->auto_export_fields();

			$this->assertSame( 'multiselect', $fields['export_statuses']['type'] );
			$this->assertSame( [ 'wc-processing' ], $fields['export_statuses']['default'] );
			$this->assertSame( 'Статусы для автоэкспорта', $fields['export_statuses']['title'] );
		}

		public function test_the_cancellation_at_the_carrier_is_not_a_setting(): void {
			$fields = ( new Woodev_Test_Auto_Export_Integration() )->auto_export_fields();

			$this->assertCount( 2, $fields, 'cancelling on cancel / full refund is always on' );
			$this->assertStringContainsString( 'независимо от этой настройки', $fields['auto_export_orders']['description'] );
		}

		public function test_the_status_choices_are_exactly_the_ones_the_export_gate_accepts_with_their_names(): void {
			$options = ( new Woodev_Test_Auto_Export_Integration() )->status_options();

			$this->assertSame( [ 'wc-pending', 'wc-on-hold', 'wc-processing' ], array_keys( $options ) );
			$this->assertSame( array_map( static fn( $status ) => 'wc-' . $status, Order_Actions::EXPORTABLE_STATUSES ), array_keys( $options ) );
			$this->assertSame( 'Name of processing', $options['wc-processing'] );
		}

		public function test_the_status_names_are_not_read_while_the_integration_is_built(): void {
			$fields = ( new Woodev_Test_Auto_Export_Integration() )->auto_export_fields();

			$this->assertSame( [], $fields['export_statuses']['options'], 'the names are translated text: filled when the form is drawn' );
		}

		public function test_a_saved_status_outside_the_allowed_set_is_reported_with_its_name(): void {
			$integration                = new Woodev_Test_Auto_Export_Integration();
			$integration->saved_statuses = [ 'wc-processing', 'wc-pickup-ready', 'completed' ];

			$this->assertSame(
				[
					'wc-pickup-ready' => 'Name of pickup-ready',
					'wc-completed'    => 'Name of completed',
				],
				$integration->unsupported(),
				'a v1 site could pick any non-final status; the ones v2 no longer exports on are named, not dropped'
			);
		}

		public function test_supported_empty_and_malformed_selections_report_nothing(): void {
			$integration = new Woodev_Test_Auto_Export_Integration();

			foreach ( [ [ 'wc-pending', 'wc-on-hold', 'wc-processing' ], [], '', null, [ '', 7, 'wc-' ] ] as $saved ) {
				$integration->saved_statuses = $saved;
				$this->assertSame( [], $integration->unsupported() );
			}
		}

		public function test_the_unsupported_status_stays_in_the_list_and_the_warning_names_it(): void {
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( '__' )->returnArg();

			$integration                 = new Woodev_Test_Auto_Export_Integration();
			$integration->saved_statuses = [ 'wc-processing', 'wc-pickup-ready' ];
			$integration->form_fields    = $integration->auto_export_fields();

			ob_start();
			$integration->prepare_field();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'больше не поддерживается для автоэкспорта', $html );
			$this->assertStringContainsString( 'Name of pickup-ready', $html );
			$this->assertSame(
				[ 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-pickup-ready' ],
				array_keys( $integration->form_fields['export_statuses']['options'] ),
				'kept in the field, so saving the screen does not erase it before the merchant has seen the warning'
			);
		}
	}
}

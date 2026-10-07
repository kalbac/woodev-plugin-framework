<?php
/**
 * Integration: a shipping plugin's failures survive an Error log threshold, and its gated debug lines do not
 * reach the log while «Логирование» is off (s158 FW-B round 2, F3 + the manual status sync's F2).
 *
 * Runs against the REAL `WC_Logger`, with a capturing handler and an explicit threshold: the inherited
 * `Woodev_WooCommerce_Plugin::log()` calls `WC_Logger::add()` without a level, which WooCommerce records at NOTICE
 * and drops at an Error threshold — a mock of `add()` cannot tell the difference.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping {

	use Woodev\Framework\Shipping\Settings\Status_Sync_Tool;
	use Woodev\Tests\Integration\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::log_error
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::log_debug
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::log_api_request
	 */
	class ShippingLogSeverityTest extends TestCase {

		/** @var Woodev_Log_Capture_Handler */
		private $capture;

		/** @var mixed the logger the plugin held before the test. */
		private $original_logger;

		/** @return \Woodev\Framework\Shipping\Shipping_Plugin */
		private function plugin() {
			return woodev_realistic_shipping_plugin();
		}

		private function logger_property(): \ReflectionProperty {
			$property = new \ReflectionProperty( \Woodev\Framework\Woocommerce_Plugin::class, 'logger' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			return $property;
		}

		protected function setUp(): void {
			parent::setUp();

			$this->original_logger = $this->logger_property()->getValue( $this->plugin() );
			$this->capture         = new Woodev_Log_Capture_Handler();

			// make sure WooCommerce's own «logging enabled» switch does not hide the real behaviour
			update_option( 'woocommerce_logs_logging_enabled', 'yes' );
		}

		protected function tearDown(): void {
			$this->logger_property()->setValue( $this->plugin(), $this->original_logger );
			$this->plugin()->get_advanced_settings()->update_value( 'enable_debug', false );

			parent::tearDown();
		}

		/** The plugin writes to a real WC_Logger holding the given threshold. */
		private function with_threshold( string $threshold ): void {
			$this->logger_property()->setValue( $this->plugin(), new \WC_Logger( [ $this->capture ], $threshold ) );
		}

		private function set_logging( bool $on ): void {
			$this->plugin()->get_advanced_settings()->update_value( 'enable_debug', $on );
		}

		/** @return string[] the messages recorded. */
		private function messages(): array {
			return array_column( $this->capture->entries, 'message' );
		}

		public function test_the_inherited_log_is_dropped_at_an_error_threshold_which_is_why_failures_do_not_use_it(): void {

			$this->with_threshold( 'error' );

			$this->plugin()->log( 'a failure written the old way' );

			$this->assertSame( [], $this->messages(), 'NOTICE severity does not pass an Error threshold' );
		}

		public function test_a_failure_is_kept_at_an_error_threshold_with_logging_off(): void {

			$this->with_threshold( 'error' );
			$this->set_logging( false );

			$this->plugin()->log_error( 'rate calculation failed', 'realistic_courier' );

			$this->assertSame( [ 'rate calculation failed' ], $this->messages() );
			$this->assertSame( 'error', $this->capture->entries[0]['level'] );
			$this->assertSame( 'realistic_courier', $this->capture->entries[0]['context']['source'], 'the log source id is kept' );
		}

		public function test_a_debug_line_is_omitted_with_logging_off_whatever_the_threshold(): void {

			$this->with_threshold( 'debug' );
			$this->set_logging( false );

			$this->plugin()->log_debug( 'availability diagnostics' );
			$this->plugin()->log_api_request( [ 'uri' => 'https://api.example.test' ], [ 'code' => 200 ] );

			$this->assertSame( [], $this->messages() );
		}

		public function test_with_logging_on_debug_lines_and_api_traffic_are_written_at_the_debug_level(): void {

			$this->with_threshold( 'debug' );
			$this->set_logging( true );

			$this->plugin()->log_debug( 'availability diagnostics' );
			$this->plugin()->log_api_request( [ 'uri' => 'https://api.example.test' ], [ 'code' => 200 ] );

			$this->assertCount( 3, $this->capture->entries );
			$this->assertSame( [ 'debug' ], array_values( array_unique( array_column( $this->capture->entries, 'level' ) ) ) );
		}

		public function test_with_logging_on_an_error_threshold_still_drops_the_debug_lines(): void {

			$this->with_threshold( 'error' );
			$this->set_logging( true );

			$this->plugin()->log_debug( 'availability diagnostics' );
			$this->plugin()->log_error( 'a failure' );

			$this->assertSame( [ 'a failure' ], $this->messages() );
		}

		public function test_the_manual_status_sync_failure_is_logged_at_error_without_the_token_with_logging_off(): void {

			$this->with_threshold( 'error' );
			$this->set_logging( false );

			// the carrier's refresh callback throws an exception carrying a credential-bearing URL
			$hook = 'woodev_log_severity_test_refresh';
			add_action(
				$hook,
				static function (): void {
					throw new \RuntimeException( 'API failed at https://example.test/?access_token=C8_SYNTHETIC_TOKEN' );
				}
			);

			$provider = \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider::create( 'logsev', 'Carrier', '_marker_logsev', [ 'logsev' ], [ 'cron_hook' => $hook ] );
			$plugin   = $this->plugin();
			$tool     = Status_Sync_Tool::create(
				[ $provider ],
				static function ( string $message ) use ( $plugin ): void {
					$plugin->log_error( $message );
				}
			);

			$result = call_user_func( $tool->get_callback(), [] );

			$this->assertFalse( $result->is_success() );
			$this->assertStringNotContainsString( 'C8_SYNTHETIC_TOKEN', $result->get_message() );
			$this->assertCount( 1, $this->capture->entries, 'the failure is logged even with logging off' );
			$this->assertStringNotContainsString( 'C8_SYNTHETIC_TOKEN', $this->capture->entries[0]['message'] );
			$this->assertStringContainsString( 'access_token=[REDACTED]', $this->capture->entries[0]['message'] );
			$this->assertSame( 'error', $this->capture->entries[0]['level'] );

			remove_all_actions( $hook );
		}
	}

	/**
	 * A WooCommerce log handler that keeps what it is handed.
	 */
	final class Woodev_Log_Capture_Handler implements \WC_Log_Handler_Interface {

		/** @var array<int,array{level:string,message:string,context:array}> */
		public array $entries = [];

		/**
		 * @param int    $timestamp Log timestamp.
		 * @param string $level     emergency|alert|critical|error|warning|notice|info|debug.
		 * @param string $message   Log message.
		 * @param array  $context   Additional information.
		 * @return bool
		 */
		public function handle( $timestamp, $level, $message, $context ) {
			$this->entries[] = [
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			];

			return true;
		}
	}
}

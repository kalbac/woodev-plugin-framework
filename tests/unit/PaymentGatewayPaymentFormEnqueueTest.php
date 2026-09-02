<?php
/**
 * Pins Woodev_Payment_Gateway::enqueue_payment_form_assets() to the JS file that actually
 * exists on disk — #738: the enqueue historically built the JS src from the handle
 * ('woodev-payment-gateway-payment-form' . '.js'), a file never shipped. The only script
 * that defines `window.Woodev_Payment_Form_Handler` (required by
 * Woodev_Payment_Gateway_Payment_Form::$js_handler_base_class_name) is
 * `woodev-payment-gateway-frontend.js`. This test locks the src to that real file so the
 * mismatch cannot come back; a sibling test locks the handle/CSS in place, since the handle
 * is the part third parties may depend on.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		/**
		 * Minimal WooCommerce gateway base for the payment-form enqueue double.
		 */
		class WC_Payment_Gateway {}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';

	/**
	 * Minimal plugin double exposing only what enqueue_payment_form_assets() touches.
	 */
	class Woodev_Test_Plugin_For_Payment_Form_Enqueue {

		/** @return string */
		public function get_payment_gateway_framework_assets_url() {
			return 'https://example.test/wp-content/plugins/x/woodev/payment-gateway/assets';
		}
	}

	/**
	 * Exposes the protected enqueue as public, with the constructor and the one abstract
	 * method bypassed the same way the filter-guard doubles in
	 * PaymentGatewayFilterReturnGuardsTest do.
	 */
	class Woodev_Testable_Payment_Gateway_Payment_Form_Enqueue extends \Woodev_Payment_Gateway {

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return Woodev_Test_Plugin_For_Payment_Form_Enqueue */
		public function get_plugin() {
			return new Woodev_Test_Plugin_For_Payment_Form_Enqueue();
		}

		/** Runs the protected enqueue under test. */
		public function run_enqueue_payment_form_assets(): void {
			$this->enqueue_payment_form_assets();
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway::enqueue_payment_form_assets
	 */
	final class PaymentGatewayPaymentFormEnqueueTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			// Not on the My Account page — bypasses the early return so the enqueue runs.
			Functions\when( 'is_account_page' )->justReturn( false );

			// get_payment_form_js_localized_script_params() runs unconditionally to build the
			// localize_script() argument, even when localize_script() itself bails.
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'esc_html__' )->returnArg( 1 );

			// localize_script() bails immediately once wp_script_is() says the handle isn't
			// loaded — keeps this test scoped to the enqueue src/handle, not the localize path.
			Functions\when( 'wp_script_is' )->justReturn( false );
		}

		public function test_enqueued_js_src_points_at_the_file_that_exists_on_disk(): void {
			$scripts = [];
			Functions\when( 'wp_enqueue_script' )->alias(
				static function ( $handle, $src, $deps, $ver, $footer ) use ( &$scripts ) {
					$scripts[ $handle ] = $src;
				}
			);
			Functions\when( 'wp_enqueue_style' )->justReturn( null );

			( new \Woodev_Testable_Payment_Gateway_Payment_Form_Enqueue() )->run_enqueue_payment_form_assets();

			$this->assertArrayHasKey( 'woodev-payment-gateway-payment-form', $scripts );
			$this->assertStringEndsWith(
				'/js/frontend/woodev-payment-gateway-frontend.js',
				$scripts['woodev-payment-gateway-payment-form']
			);
		}

		/**
		 * The handle stays 'woodev-payment-gateway-payment-form' even though the JS file
		 * behind it no longer matches it — third parties may hang wp_add_inline_script()
		 * or a dependency off that handle (#738) — and the CSS enqueue is untouched by the
		 * fix: it already resolved to a file that exists.
		 */
		public function test_the_handle_is_unchanged_and_the_style_still_resolves_to_the_payment_form_css(): void {
			$styles = [];
			Functions\when( 'wp_enqueue_script' )->justReturn( null );
			Functions\when( 'wp_enqueue_style' )->alias(
				static function ( $handle, $src, $deps, $ver ) use ( &$styles ) {
					$styles[ $handle ] = $src;
				}
			);

			( new \Woodev_Testable_Payment_Gateway_Payment_Form_Enqueue() )->run_enqueue_payment_form_assets();

			$this->assertArrayHasKey( 'woodev-payment-gateway-payment-form', $styles );
			$this->assertStringEndsWith(
				'/css/frontend/woodev-payment-gateway-payment-form.css',
				$styles['woodev-payment-gateway-payment-form']
			);
		}
	}
}

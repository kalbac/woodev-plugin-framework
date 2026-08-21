<?php
/**
 * Verifies #385: Woodev_Payment_Gateway_Hosted::get_payment_url() returns string|false
 * as documented, never a WC_Order object, when the hosted pay page URL is unavailable.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		/**
		 * Minimal WooCommerce gateway base for the hosted gateway test double.
		 */
		class WC_Payment_Gateway {}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-hosted.php';

	/**
	 * Exposes the protected get_payment_url() under test with inert collaborators.
	 */
	class Woodev_Testable_Payment_Gateway_Hosted_Url extends \Woodev_Payment_Gateway_Hosted {

		/** @var object order stub returned by get_order() */
		private $order;

		/**
		 * @param object $order order stub
		 * @since 2.0.2
		 */
		public function __construct( object $order ) {
			$this->order = $order;
		}

		/**
		 * @return array
		 * @since 2.0.2
		 */
		protected function get_method_form_fields(): array {
			return [];
		}

		/**
		 * Skips the real order-hydration pipeline; returns the injected stub instead.
		 *
		 * @param int|object $order_id order id
		 * @return object
		 * @since 2.0.2
		 */
		public function get_order( $order_id ) {
			return $this->order;
		}

		/**
		 * Simulates an unconfigured hosted pay page endpoint.
		 *
		 * @param object|null $order order
		 * @return string
		 * @since 2.0.2
		 */
		public function get_hosted_pay_page_url( $order = null ) {
			return '';
		}

		/**
		 * @param object $order order
		 * @return array
		 * @since 2.0.2
		 */
		public function get_hosted_pay_page_params( $order ) {
			return [];
		}

		/**
		 * Unused by this test; implements the abstract method so the class can be instantiated.
		 *
		 * @param array $request_response_data unused
		 * @return object
		 * @since 2.0.2
		 */
		protected function get_transaction_response( $request_response_data ) {
			return new \stdClass();
		}

		/**
		 * @return bool
		 * @since 2.0.2
		 */
		public function empty_cart_before_redirect() {
			return false;
		}

		/**
		 * Exposes the protected get_payment_url() for direct assertions.
		 *
		 * @param int $order_id order id
		 * @return string|false
		 * @since 2.0.2
		 */
		public function expose_get_payment_url( $order_id ) {
			return $this->get_payment_url( $order_id );
		}
	}
}

namespace Woodev\Tests\Unit {

	/**
	 * @covers \Woodev_Payment_Gateway_Hosted
	 */
	final class PaymentGatewayHostedPaymentUrlTest extends TestCase {

		/**
		 * When the hosted pay page URL is unavailable, get_payment_url() must return
		 * false (per its own docblock), never the WC_Order object it built internally.
		 *
		 * @return void
		 * @since 2.0.2
		 */
		public function test_get_payment_url_returns_false_when_hosted_pay_page_url_is_empty(): void {
			$gateway = new \Woodev_Testable_Payment_Gateway_Hosted_Url( new \stdClass() );

			$result = $gateway->expose_get_payment_url( 123 );

			$this->assertFalse( $result, 'get_payment_url() must return false, not the order object, when the hosted pay page URL is empty' );
		}

		/**
		 * process_payment() must surface a 'failure' result rather than handing an
		 * object to WooCommerce as a redirect URL (which fatals in wp_redirect() on
		 * the classic checkout path, or serializes an object into window.location on
		 * the AJAX path).
		 *
		 * @return void
		 * @since 2.0.2
		 */
		public function test_process_payment_returns_failure_when_hosted_pay_page_url_is_empty(): void {
			$gateway = new \Woodev_Testable_Payment_Gateway_Hosted_Url( new \stdClass() );

			$result = $gateway->process_payment( 123 );

			$this->assertSame( [ 'result' => 'failure' ], $result );
		}
	}
}

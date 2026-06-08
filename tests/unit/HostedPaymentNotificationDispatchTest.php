<?php
/**
 * Failing test for V-1: get_order_from_response() unguarded credit-card dispatch.
 *
 * Reproduces the bug at woodev/payment-gateway/class-payment-gateway-hosted.php:440-452
 * where the value-based switch() dispatches on $response->get_payment_type() instead
 * of instanceof. If a hosted gateway returns PAYMENT_TYPE_CREDIT_CARD from a response
 * that does NOT implement Woodev_Payment_Gateway_API_Payment_Notification_Credit_Card_Response,
 * the call to $response->get_exp_month() fatals on checkout.
 *
 * Test currently FAILS with a fatal Error. After B-1a fix (instanceof guards), it PASSES
 * because the dispatch correctly skips the unsafe call.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( '\WC_Payment_Gateway', false ) ) {
		class HostedDispatchTest_WC_Payment_Gateway_Stub {}

		class_alias( HostedDispatchTest_WC_Payment_Gateway_Stub::class, 'WC_Payment_Gateway' );
	}

}

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-payment-notification-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-payment-notification-credit-card-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-payment-notification-loans-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-authorization-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-hosted.php';

	if ( ! interface_exists( 'Woodev_Test_Response_Without_Sub_Interface', false ) ) {
		interface Woodev_Test_Response_Without_Sub_Interface extends \Woodev_Payment_Gateway_API_Payment_Notification_Response {}
	}

	if ( ! class_exists( 'Woodev_Test_Response_Without_Sub_Implementation', false ) ) {
		class Woodev_Test_Response_Without_Sub_Implementation implements \Woodev_Test_Response_Without_Sub_Interface {
			public function get_order_id() { return 42; }
			public function transaction_cancelled() { return false; }
			public function get_account_number() { return '1111'; }
			public function is_ipn() { return false; }
			public function get_payment_type() { return \Woodev_Payment_Gateway::PAYMENT_TYPE_CREDIT_CARD; }
			public function transaction_approved() { return true; }
			public function transaction_held() { return false; }
			public function get_status_message() { return ''; }
			public function get_status_code() { return ''; }
			public function get_transaction_id() { return ''; }
			public function get_user_message() { return ''; }
			public function to_string() { return ''; }
			public function to_string_safe() { return ''; }
		}
	}

	if ( ! class_exists( 'Woodev_Test_Hosted_For_Dispatch_Test', false ) ) {
		class Woodev_Test_Hosted_For_Dispatch_Test extends \Woodev_Payment_Gateway_Hosted {
			public $stub_order = null;
			public function __construct() {}
			public function get_hosted_pay_page_url( $order = null ) { return ''; }
			protected function get_transaction_response( $request_response_data ) { return null; }
			protected function get_method_form_fields(): array { return []; }
			public function get_order( $order ) { return $this->stub_order; }
			public function call_get_order_from_response( $response ) {
				return $this->get_order_from_response( $response );
			}
		}
	}
}

namespace Woodev\Tests\Unit {

	use Mockery;

	class HostedPaymentNotificationDispatchTest extends TestCase {

		public function test_credit_card_payment_type_is_skipped_when_response_lacks_sub_interface() {
			$response = new \Woodev_Test_Response_Without_Sub_Implementation();

			$gateway = new \Woodev_Test_Hosted_For_Dispatch_Test();

			$order = $this->make_order_stub();
			$gateway->stub_order = $order;

			\Brain\Monkey\Functions\when( 'wc_get_order' )->justReturn( true );

			$result = $gateway->call_get_order_from_response( $response );

			$this->assertSame( $order, $result, 'get_order_from_response should return the order' );
		}

		private function make_order_stub() {
			$order          = new \stdClass();
			$order->payment = new \stdClass();
			$order->payment->account_number = '';

			return $order;
		}
	}

}

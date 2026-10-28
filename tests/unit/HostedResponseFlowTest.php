<?php
/**
 * Regression coverage for hosted notification outcomes and attempt references (#1480).
 *
 * @package Woodev\Tests\Unit
 */

namespace {
	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		class WC_Payment_Gateway {}
	}
	// Defer the fallback until execution: eager declaration shadows richer shipping
	// fixtures while PHPUnit is still collecting the full suite.
	function woodev_hosted_flow_ensure_order_stub(): void {
		if ( ! class_exists( 'WC_Order', false ) ) {
			class WC_Order {}
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Payment_Gateway\Api\Pending_Notification_Response;

	/** Exercises the real hydration, validation, outcome routing and reference helpers. */
	class Hosted_Response_Flow_Gateway extends \Woodev_Payment_Gateway_Hosted {
		public $id = 'hosted-flow';
		public $response;
		public $verification_exception;
		public $processing_exception;
		public $logger;
		public $outcome = '';
		public $references = [];
		public $form_post = false;
		public $debug_enabled = true;

		public function __construct() {}
		protected function get_method_form_fields(): array { return []; }
		public function get_payment_type() { return 'credit-card'; }
		public function use_form_post() { return $this->form_post; }
		public function use_auto_form_post() { return false; }
		public function supports_tokenization() { return false; }
		public function debug_log() { return $this->debug_enabled; }
		public function get_plugin() { return $this->logger; }
		protected function get_transaction_response( $request_response_data ) { return $this->response; }
		public function add_transaction_data( $order, $response = null ) {
			if ( $this->processing_exception ) {
				throw $this->processing_exception;
			}
		}
		public function get_hosted_pay_page_url( $order = null ) {
			$this->references[] = $order->unique_transaction_ref;
			return 'https://example.test/pay';
		}
		protected function get_hosted_pay_page_params( $order ) {
			$this->references[] = $order->unique_transaction_ref;
			return [];
		}
		public function render_pay_page_form( $order, $request_params ) {}
		public function log_hosted_pay_page_request( $request ) {}
		public function payment_url( int $order_id ) { return $this->get_payment_url( $order_id ); }
		protected function validate_transaction_response( $order, $response ) {
			parent::validate_transaction_response( $order, $response );
			if ( $this->verification_exception ) {
				throw $this->verification_exception;
			}
		}
		protected function do_transaction_held( \WC_Order $order, $response ) { $this->outcome = 'held'; }
		protected function do_transaction_pending( \WC_Order $order, \Woodev_Payment_Gateway_API_Payment_Notification_Response $response ): void { $this->outcome = 'acknowledged'; }
		protected function do_transaction_failed( \WC_Order $order, $response ) { $this->outcome = 'failed'; }
		protected function do_invalid_transaction_response( $order, $response ) { $this->outcome = 'invalid'; }
		public function mark_order_as_failed( $order, $error_message, $response = null ) {
			$order->update_status( 'failed', $error_message );
		}
	}

	/** Keeps the direct gateway's attempt entry point covered after the shared split. */
	class Direct_Response_Flow_Gateway extends \Woodev_Payment_Gateway_Direct {
		public $id = 'hosted-flow';
		public $order;
		public $references = [];
		public function __construct() {}
		protected function get_method_form_fields(): array { return []; }
		public function get_order( $order ) { return $this->order; }
		public function supports_tokenization() { return false; }
		protected function do_transaction( $order ) {
			$this->references[] = $order->unique_transaction_ref;
			return false;
		}
	}

	final class HostedResponseFlowTest extends TestCase {
		private $gateway;
		private $order;
		private $retry_count;
		private $logs;

		protected function setUp(): void {
			parent::setUp();
			\woodev_hosted_flow_ensure_order_stub();
			Functions\when( 'is_multisite' )->justReturn( false );
			Functions\when( 'get_bloginfo' )->justReturn( 'Shop' );
			Functions\when( 'wp_specialchars_decode' )->returnArg();
			Functions\when( 'add_query_arg' )->alias( static function ( $params, $url ) { return $url; } );
			$this->retry_count = '';
			$this->logs = [];
			$this->order = Mockery::mock( \WC_Order::class );
			$this->order->shouldReceive( 'get_total' )->andReturn( 10 );
			$this->order->shouldReceive( 'get_user_id' )->andReturn( 0 );
			$this->order->shouldReceive( 'get_order_number' )->andReturn( '#42' );
			$this->order->shouldReceive( 'needs_payment' )->andReturn( true );
			$this->order->shouldReceive( 'get_meta' )->with( '_wc_hosted-flow_retry_count', true, 'edit' )
				->andReturnUsing( function () { return $this->retry_count; } );
			Functions\when( 'wc_get_order' )->justReturn( $this->order );
			$this->gateway = new Hosted_Response_Flow_Gateway();
			$this->gateway->logger = Mockery::mock();
			$this->gateway->logger->shouldReceive( 'log' )->andReturnUsing( function ( $message ) { $this->logs[] = $message; } );
		}

		private function response( bool $pending = true, bool $extended = true, bool $approved = false, bool $ipn = true ) {
			$response = Mockery::mock( $extended ? Pending_Notification_Response::class : \Woodev_Payment_Gateway_API_Payment_Notification_Response::class );
			$response->shouldReceive( 'get_order_id' )->andReturn( 42 );
			$response->shouldReceive( 'get_account_number' )->andReturn( null );
			$response->shouldReceive( 'to_string_safe' )->andReturn( 'notification' );
			$response->shouldReceive( 'transaction_approved' )->andReturn( $approved );
			$response->shouldReceive( 'transaction_held', 'transaction_cancelled' )->andReturn( false );
			$response->shouldReceive( 'is_ipn' )->andReturn( $ipn );
			$response->shouldReceive( 'get_status_code', 'get_status_message', 'get_transaction_id' )->andReturn( '' );
			if ( $extended ) {
				$response->shouldReceive( 'transaction_pending' )->andReturn( $pending );
			}
			return $response;
		}

		public function test_intermediate_notification_preserves_status_and_acknowledges(): void {
			$this->order->shouldNotReceive( 'update_status', 'update_meta_data', 'save_meta_data' );
			$this->gateway->response = $this->response();
			$this->gateway->handle_transaction_response_request();
			$this->assertSame( 'acknowledged', $this->gateway->outcome );
		}

		/** @dataProvider verification_errors */
		public function test_unverified_notification_is_logged_without_failing_an_unpaid_order( string $exception_class ): void {
			$this->order->shouldNotReceive( 'update_status', 'update_meta_data', 'save_meta_data' );
			$this->gateway->response = $this->response();
			$this->gateway->verification_exception = new $exception_class( 'Invalid signature' );
			$this->gateway->handle_transaction_response_request();
			$this->assertSame( 'invalid', $this->gateway->outcome );
			$this->assertContains( 'IPN processing error: Invalid signature', $this->logs );
		}

		public function test_processing_failure_is_logged_and_not_acknowledged_or_marked_failed(): void {
			$this->gateway->debug_enabled = false;
			$this->gateway->response = $this->response( true, true, true );
			$this->gateway->processing_exception = new \RuntimeException( 'Transaction data write failed' );
			$this->order->shouldNotReceive( 'update_status' );
			Functions\expect( 'status_header' )->once()->with( 500 );

			try {
				$this->gateway->handle_transaction_response_request();
				$this->fail( 'The processing exception must escape so the provider can retry.' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'Transaction data write failed', $exception->getMessage() );
			}

			$this->assertContains( 'IPN processing error: Transaction data write failed', $this->logs );
			$this->assertNotSame( 'failed', $this->gateway->outcome );
		}

		public function test_processing_failure_on_redirect_back_is_logged_and_routes_the_buyer_without_rethrowing(): void {
			$this->gateway->debug_enabled = false;
			$this->gateway->response = $this->response( true, true, true, false );
			$this->gateway->processing_exception = new \RuntimeException( 'Transaction data write failed' );
			$this->order->shouldNotReceive( 'update_status' );
			Functions\expect( 'status_header' )->never();

			$this->gateway->handle_transaction_response_request();

			$this->assertSame( 'invalid', $this->gateway->outcome );
			$this->assertContains( 'Redirect-back processing error: Transaction data write failed', $this->logs );
		}

		public static function verification_errors(): array {
			return [ [ \Woodev_Payment_Gateway_Exception::class ], [ \RuntimeException::class ] ];
		}

		/** @dataProvider declined_responses */
		public function test_a_genuine_decline_still_fails_the_order( bool $extended ): void {
			$this->order->shouldReceive( 'update_status' )->once()->with( 'failed', '' );
			$this->gateway->response = $this->response( false, $extended );
			$this->gateway->handle_transaction_response_request();
			$this->assertSame( 'failed', $this->gateway->outcome );
		}

		public static function declined_responses(): array { return [ [ true ], [ false ] ]; }

		/** @dataProvider stored_counters */
		public function test_repeated_responses_reuse_the_stored_reference_without_writing_meta( $counter, string $reference ): void {
			$this->retry_count = $counter;
			$this->order->shouldNotReceive( 'update_meta_data', 'save_meta_data', 'update_status' );
			$this->gateway->response = $this->response();
			$this->gateway->handle_transaction_response_request();
			$this->assertSame( $reference, $this->order->unique_transaction_ref );
			$this->gateway->handle_transaction_response_request();
			$this->assertSame( $reference, $this->order->unique_transaction_ref );
			$this->assertSame( $counter, $this->retry_count );
		}

		public static function stored_counters(): array { return [ [ '', '42' ], [ 0, '42' ], [ '3', '42-3' ] ]; }

		/** @dataProvider payment_modes */
		public function test_only_new_payment_requests_advance_the_reference( bool $form_post ): void {
			$this->gateway->form_post = $form_post;
			$this->order->shouldReceive( 'update_meta_data' )->twice()->withArgs( function ( $key, $value ) {
				$this->assertSame( '_wc_hosted-flow_retry_count', $key );
				$this->retry_count = $value;
				return true;
			} );
			$this->order->shouldReceive( 'save_meta_data' )->twice();
			$this->order->shouldNotReceive( 'update_status' );
			for ( $attempt = 0; $attempt < 2; ++$attempt ) {
				if ( $form_post ) {
					$this->gateway->generate_pay_form( 42 );
				} else {
					$this->gateway->payment_url( 42 );
				}
				// Notifications and ordinary lookups between attempts do not consume a suffix.
				$this->gateway->response = $this->response();
				$this->gateway->handle_transaction_response_request();
				$this->gateway->get_order( 42 );
			}
			$this->assertSame( [ '42', '42', '42-1', '42-1' ], $this->gateway->references );
			$this->assertSame( 1, $this->retry_count );
		}

		public function test_direct_payments_still_advance_the_shared_attempt_reference(): void {
			$gateway = new Direct_Response_Flow_Gateway();
			$gateway->order = $this->order;
			$this->order->payment_total = '10.00';
			$this->order->shouldReceive( 'update_meta_data' )->twice()->withArgs( function ( $key, $value ) {
				$this->retry_count = $value;
				return '_wc_hosted-flow_retry_count' === $key;
			} );
			$this->order->shouldReceive( 'save_meta_data' )->twice();
			$gateway->process_payment( 42 );
			$gateway->process_payment( 42 );
			$this->assertSame( [ '42', '42-1' ], $gateway->references );
		}

		public static function payment_modes(): array { return [ [ true ], [ false ] ]; }
	}
}

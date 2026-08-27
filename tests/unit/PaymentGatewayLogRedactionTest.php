<?php
/**
 * Tests for the log-boundary redaction fix at the payment-gateway log sinks — #594 re-sweep.
 *
 * These three sites write through `$plugin->log()` / `$gateway->get_plugin()->log()` rather
 * than `error_log()`, so the card's original sweep — keyed on `error_log` — could not see
 * them. A payment gateway's caught exceptions are where carrier/API credentials actually
 * live, so this file pins that each site's caught exception message is routed through
 * {@see \Woodev_API_Base::redact_secret_log_text()} before it reaches the plugin's log sink.
 *
 * Site 1: Woodev_Payment_Gateway_Hosted::handle_transaction_response_request()'s catch of
 *         Woodev_Payment_Gateway_Exception.
 * Site 2: Woodev_Payment_Gateway_Abstract_Payment_Handler::process_order_transaction_approved()'s
 *         catch of Exception.
 * Site 3: Woodev_Payment_Gateway_Payment_Tokens_Handler::remove_token()'s catch of
 *         Woodev_Plugin_Exception.
 *
 * Both site 1 and site 2 also write the RAW (unredacted) exception message into a WC_Order
 * note — that is deliberate (shop staff need the real failure reason) and tracked separately;
 * this file does not touch or assert on that behavior.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		/**
		 * Minimal WooCommerce gateway base for the gateway test doubles.
		 */
		class WC_Payment_Gateway {}
	}

	if ( ! class_exists( 'WC_Order', false ) ) {
		/**
		 * Minimal WooCommerce order base for the order test double.
		 */
		class WC_Order {

			/**
			 * @return int
			 */
			public function get_id(): int {
				return 123;
			}
		}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/exceptions/class-payment-gateway-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-hosted.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/handlers/abstract-payment-handler.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/payment-tokens/class-payment-gateway-payment-tokens-handler.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';

	/* ------------------------------------------------------------------- *
	 * Site 1 test double — Woodev_Payment_Gateway_Hosted.
	 *
	 * get_transaction_response() is made to throw directly (rather than
	 * building a fake response object) so the ONLY log() call in the run is
	 * the catch block's processing-error log — log_transaction_response_request()
	 * never runs, since it sits after get_transaction_response() in the try
	 * block and is gated on the very same debug_log() flag.
	 *
	 * do_invalid_transaction_response() is no-op'd: the real implementation
	 * unconditionally calls $response->is_ipn() (fatal on a null $response)
	 * and always ends in exit()/die(), which would kill the test process.
	 * ------------------------------------------------------------------- */

	if ( ! class_exists( 'Woodev_Test_Hosted_For_Redaction_Test', false ) ) {
		/**
		 * Hosted gateway test double for the site 1 redaction test.
		 */
		class Woodev_Test_Hosted_For_Redaction_Test extends \Woodev_Payment_Gateway_Hosted {

			/** @var string gateway id, mirrors WC_Payment_Gateway::$id */
			public $id;

			/** @var string message the simulated Woodev_Payment_Gateway_Exception carries */
			public $exception_message = '';

			/**
			 * Skips the real constructor (settings/hooks bootstrap).
			 */
			public function __construct() {}

			/**
			 * @param object|null $order unused
			 * @return string
			 */
			public function get_hosted_pay_page_url( $order = null ) {
				return '';
			}

			/**
			 * @return array
			 */
			protected function get_method_form_fields(): array {
				return [];
			}

			/**
			 * Throws before any response object is built, so the try block's
			 * subsequent log_transaction_response_request() call never runs.
			 *
			 * @param array $request_response_data unused
			 * @return object
			 * @throws \Woodev_Payment_Gateway_Exception
			 */
			protected function get_transaction_response( $request_response_data ) {
				throw new \Woodev_Payment_Gateway_Exception( $this->exception_message );
			}

			/**
			 * No-op: the real implementation calls $response->is_ipn() unconditionally
			 * (fatal when $response is null) and always exits/dies.
			 *
			 * @param object|null $order    unused
			 * @param object|null $response unused
			 * @return void
			 */
			protected function do_invalid_transaction_response( $order, $response ) {}
		}
	}

	/* ------------------------------------------------------------------- *
	 * Site 2 test double — Woodev_Payment_Gateway_Abstract_Payment_Handler.
	 * ------------------------------------------------------------------- */

	if ( ! class_exists( 'Woodev_Test_Payment_Handler_For_Redaction', false ) ) {
		/**
		 * Payment handler test double exposing the protected method under test.
		 */
		class Woodev_Test_Payment_Handler_For_Redaction extends \Woodev_Payment_Gateway_Abstract_Payment_Handler {

			/**
			 * Unused by this test; implements the abstract method so the class can be instantiated.
			 *
			 * @param \WC_Order $order unused
			 * @return array
			 */
			public function process_order_payment( \WC_Order $order ) {
				return [];
			}

			/**
			 * @param \WC_Order                             $order    order
			 * @param \Woodev_Payment_Gateway_API_Response $response API response
			 * @return void
			 */
			public function call_process_order_transaction_approved( $order, $response ) {
				$this->process_order_transaction_approved( $order, $response );
			}
		}
	}

	if ( ! class_exists( 'Woodev_Test_Order_For_Redaction', false ) ) {
		/**
		 * Order test double with its own add_order_note() no-op, independent of
		 * whichever WC_Order stub above happened to win the class_exists() race
		 * across the suite.
		 */
		class Woodev_Test_Order_For_Redaction extends \WC_Order {

			/**
			 * @return int
			 */
			public function get_id(): int {
				return 456;
			}

			/**
			 * @param string $note unused
			 * @return void
			 */
			public function add_order_note( $note ) {}
		}
	}

	/* ------------------------------------------------------------------- *
	 * Site 3 test double — Woodev_Payment_Gateway_Payment_Tokens_Handler.
	 *
	 * user_has_token() is overridden to bypass the real token-cache lookup,
	 * which is unrelated to the redaction behavior under test here.
	 * ------------------------------------------------------------------- */

	if ( ! class_exists( 'Woodev_Test_Payment_Tokens_Handler_For_Redaction', false ) ) {
		/**
		 * Payment tokens handler test double.
		 */
		class Woodev_Test_Payment_Tokens_Handler_For_Redaction extends \Woodev_Payment_Gateway_Payment_Tokens_Handler {

			/**
			 * @param int          $user_id        unused
			 * @param object       $token          unused
			 * @param string|null  $environment_id unused
			 * @return bool
			 */
			public function user_has_token( $user_id, $token, $environment_id = null ) {
				return true;
			}
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;

	/**
	 * Class PaymentGatewayLogRedactionTest.
	 */
	class PaymentGatewayLogRedactionTest extends TestCase {

		/**
		 * The secret a foreign API/carrier exception embeds in its message.
		 *
		 * @var string
		 */
		private const SECRET = 'LIVESECRET';

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			// add_hooks()/the tokens handler constructor register WP hooks; not under test here.
			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'add_action' )->justReturn( true );
		}

		/* ----------------------------------------------------------------------- *
		 * Site 1: Woodev_Payment_Gateway_Hosted::handle_transaction_response_request()
		 * ----------------------------------------------------------------------- */

		/**
		 * A secret embedded in a caught Woodev_Payment_Gateway_Exception message must be
		 * redacted before the hosted transaction-response handler logs it.
		 *
		 * @return void
		 */
		public function test_hosted_transaction_response_handler_redacts_a_secret_in_the_processing_error_log(): void {
			$gateway                    = new \Woodev_Test_Hosted_For_Redaction_Test();
			$gateway->id                = 'test-hosted-gateway';
			$gateway->exception_message = 'carrier rejected api_key=' . self::SECRET;

			$plugin   = Mockery::mock();
			$captured = null;
			$plugin->shouldReceive( 'log' )
				->once()
				->with(
					Mockery::on(
						static function ( $message ) use ( &$captured ) {
							$captured = $message;
							return true;
						}
					),
					'test-hosted-gateway'
				);

			$this->set_private( $gateway, 'plugin', $plugin );
			$this->set_private( $gateway, 'debug_mode', \Woodev_Payment_Gateway::DEBUG_MODE_LOG );

			$gateway->handle_transaction_response_request();

			$this->assertSame(
				'Redirect-back processing error: carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
				$captured
			);
		}

		/**
		 * Control: a processing-error message carrying NO secret must reach the rendered
		 * log() line byte-for-byte.
		 *
		 * @return void
		 */
		public function test_hosted_transaction_response_handler_leaves_a_processing_error_without_a_secret_untouched(): void {
			$gateway                    = new \Woodev_Test_Hosted_For_Redaction_Test();
			$gateway->id                = 'test-hosted-gateway';
			$gateway->exception_message = 'gateway endpoint unreachable';

			$plugin   = Mockery::mock();
			$captured = null;
			$plugin->shouldReceive( 'log' )
				->once()
				->with(
					Mockery::on(
						static function ( $message ) use ( &$captured ) {
							$captured = $message;
							return true;
						}
					),
					'test-hosted-gateway'
				);

			$this->set_private( $gateway, 'plugin', $plugin );
			$this->set_private( $gateway, 'debug_mode', \Woodev_Payment_Gateway::DEBUG_MODE_LOG );

			$gateway->handle_transaction_response_request();

			$this->assertSame(
				'Redirect-back processing error: gateway endpoint unreachable',
				$captured
			);
		}

		/* ----------------------------------------------------------------------- *
		 * Site 2: Woodev_Payment_Gateway_Abstract_Payment_Handler::process_order_transaction_approved()
		 * ----------------------------------------------------------------------- */

		/**
		 * A secret embedded in a caught Exception message must be redacted before the
		 * approved-transaction handler logs it.
		 *
		 * @return void
		 */
		public function test_approved_transaction_handler_redacts_a_secret_in_the_error_log(): void {
			$this->assert_approved_transaction_log(
				'carrier rejected api_key=' . self::SECRET,
				'Error handling approved transaction: carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK
			);
		}

		/**
		 * Control: an approved-transaction-handling failure message carrying NO secret
		 * must reach the rendered log() line byte-for-byte.
		 *
		 * @return void
		 */
		public function test_approved_transaction_handler_leaves_a_message_without_a_secret_untouched(): void {
			$this->assert_approved_transaction_log(
				'card issuer declined the request',
				'Error handling approved transaction: card issuer declined the request'
			);
		}

		/**
		 * Drives Woodev_Payment_Gateway_Abstract_Payment_Handler::process_order_transaction_approved()
		 * with a collaborator that throws $exception_message, and asserts the complete
		 * rendered log() line equals $expected_log_line.
		 *
		 * @param string $exception_message the message the thrown Exception carries
		 * @param string $expected_log_line the complete rendered log() line expected
		 * @return void
		 */
		private function assert_approved_transaction_log( string $exception_message, string $expected_log_line ): void {
			$plugin   = Mockery::mock();
			$captured = null;
			$plugin->shouldReceive( 'log' )
				->once()
				->with(
					Mockery::on(
						static function ( $message ) use ( &$captured ) {
							$captured = $message;
							return true;
						}
					)
				);

			$gateway = Mockery::mock( \Woodev_Payment_Gateway::class );
			$gateway->shouldReceive( 'get_plugin' )->andReturn( $plugin );
			$gateway->shouldReceive( 'get_test_transaction_approved_message' )
				->once()
				->andThrow( new \Exception( $exception_message ) );

			$response = Mockery::mock( \Woodev_Payment_Gateway_API_Response::class );
			$response->shouldReceive( 'get_payment_type' )->andReturn( 'test' );

			$order = new \Woodev_Test_Order_For_Redaction();

			$handler = new \Woodev_Test_Payment_Handler_For_Redaction( $gateway );

			$handler->call_process_order_transaction_approved( $order, $response );

			$this->assertSame( $expected_log_line, $captured );
		}

		/* ----------------------------------------------------------------------- *
		 * Site 3: Woodev_Payment_Gateway_Payment_Tokens_Handler::remove_token()
		 * ----------------------------------------------------------------------- */

		/**
		 * A secret embedded in a caught Woodev_Plugin_Exception message — raised by the
		 * live remove_tokenized_payment_method() API call — must be redacted before
		 * remove_token() logs it.
		 *
		 * @return void
		 */
		public function test_remove_token_redacts_a_secret_in_the_error_log(): void {
			$this->assert_remove_token_log(
				'carrier rejected api_key=' . self::SECRET,
				'carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK
			);
		}

		/**
		 * Control: a remove_tokenized_payment_method() failure message carrying NO secret
		 * must reach the rendered log() line byte-for-byte.
		 *
		 * @return void
		 */
		public function test_remove_token_leaves_a_message_without_a_secret_untouched(): void {
			$this->assert_remove_token_log(
				'token endpoint unavailable',
				'token endpoint unavailable'
			);
		}

		/**
		 * Drives Woodev_Payment_Gateway_Payment_Tokens_Handler::remove_token() with a live
		 * API call that throws $exception_message, and asserts the complete rendered
		 * log() line equals $expected_log_line.
		 *
		 * @param string $exception_message the message the thrown Woodev_Plugin_Exception carries
		 * @param string $expected_log_line the complete rendered log() line expected
		 * @return void
		 */
		private function assert_remove_token_log( string $exception_message, string $expected_log_line ): void {
			$plugin   = Mockery::mock();
			$captured = null;
			$plugin->shouldReceive( 'log' )
				->once()
				->with(
					Mockery::on(
						static function ( $message ) use ( &$captured ) {
							$captured = $message;
							return true;
						}
					),
					'test-tokens-gateway'
				);

			$api = Mockery::mock();
			$api->shouldReceive( 'supports_remove_tokenized_payment_method' )->andReturn( true );
			$api->shouldReceive( 'remove_tokenized_payment_method' )
				->once()
				->andThrow( new \Woodev_Plugin_Exception( $exception_message ) );

			$gateway = Mockery::mock( \Woodev_Payment_Gateway::class );
			$gateway->shouldReceive( 'get_environment' )->andReturn( 'production' );
			$gateway->shouldReceive( 'get_api' )->andReturn( $api );
			$gateway->shouldReceive( 'get_customer_id' )->andReturn( 'cust_1' );
			$gateway->shouldReceive( 'debug_log' )->andReturn( true );
			$gateway->shouldReceive( 'get_plugin' )->andReturn( $plugin );
			$gateway->shouldReceive( 'get_id' )->andReturn( 'test-tokens-gateway' );

			$token = Mockery::mock();
			$token->shouldReceive( 'get_id' )->andReturn( 'tok_123' );

			$handler = new \Woodev_Test_Payment_Tokens_Handler_For_Redaction( $gateway );

			$result = $handler->remove_token( 1, $token, 'production' );

			$this->assertFalse( $result );
			$this->assertSame( $expected_log_line, $captured );
		}

		/* ----------------------------------------------------------------------- *
		 * Helpers
		 * ----------------------------------------------------------------------- */

		/**
		 * Sets a private property via reflection.
		 *
		 * Walks up the class hierarchy to find the DECLARING class: as of PHP 8.5,
		 * `new ReflectionProperty( $object, $property )` throws "Property does not
		 * exist" for a private property declared only in an ancestor class — the
		 * reflection must be built against the class that actually declares it.
		 *
		 * @param object $object   Target.
		 * @param string $property Property name.
		 * @param mixed  $value    Value.
		 * @return void
		 */
		private function set_private( $object, string $property, $value ): void {
			for ( $class = get_class( $object ); $class; $class = get_parent_class( $class ) ) {
				if ( property_exists( $class, $property ) ) {
					break;
				}
			}

			$reflection = new \ReflectionProperty( $class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection->setAccessible( true );
			}
			$reflection->setValue( $object, $value );
		}
	}
}

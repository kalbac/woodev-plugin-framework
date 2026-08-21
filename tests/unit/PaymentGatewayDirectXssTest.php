<?php
/**
 * Unit tests for direct payment gateway customer notices.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		/**
		 * Minimal WooCommerce gateway base for the direct gateway test double.
		 */
		class WC_Payment_Gateway {}
	}

	if ( ! class_exists( 'WC_Order', false ) ) {
		/**
		 * Minimal WooCommerce order base for the direct gateway test double.
		 */
		class WC_Order {

			/**
			 * Gets the order ID used by other unit-test doubles.
			 *
			 * @return int
			 */
			public function get_id(): int {
				return 123;
			}
		}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-direct.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/payment-tokens/class-payment-gateway-payment-token.php';

	/**
	 * Supplies a user ID to the direct gateway transaction.
	 */
	class Woodev_Test_Direct_Xss_Order extends WC_Order {

		/**
		 * Gets the order user ID.
		 *
		 * @return int
		 * @since 2.0.2
		 */
		public function get_user_id(): int {
			return 1;
		}
	}

	/**
	 * Exposes the protected direct gateway transaction with inert dependencies.
	 */
	class Woodev_Testable_Payment_Gateway_Direct_Xss extends Woodev_Payment_Gateway_Direct {

		/** @var object */
		private $api;

		/** @var object */
		private $payment_tokens_handler_double;

		/**
		 * Initializes the transaction dependencies without constructing a full gateway.
		 *
		 * @param object $api API response provider
		 * @param object $payment_tokens_handler token persistence handler
		 * @since 2.0.2
		 */
		public function __construct( object $api, object $payment_tokens_handler ) {
			$this->api                           = $api;
			$this->payment_tokens_handler_double = $payment_tokens_handler;
		}

		/**
		 * Runs the protected add-payment-method transaction.
		 *
		 * @param WC_Order $order order being processed
		 * @return array
		 * @since 2.0.2
		 */
		public function run_add_payment_method_transaction( WC_Order $order ): array {
			return $this->do_add_payment_method_transaction( $order );
		}

		/**
		 * Gets the API double.
		 *
		 * @return object
		 * @since 2.0.2
		 */
		public function get_api(): object {
			return $this->api;
		}

		/**
		 * Gets the token persistence double.
		 *
		 * @return object
		 * @since 2.0.2
		 */
		public function get_payment_tokens_handler(): object {
			return $this->payment_tokens_handler_double;
		}

		/**
		 * Treats the double as a credit-card gateway.
		 *
		 * @return bool
		 * @since 2.0.2
		 */
		public function is_credit_card_gateway(): bool {
			return true;
		}

		/**
		 * Gets the gateway ID.
		 *
		 * @return string
		 * @since 2.0.2
		 */
		public function get_id(): string {
			return 'test-direct-gateway';
		}

		/**
		 * Supplies no settings because the test does not initialize the gateway.
		 *
		 * @return array
		 * @since 2.0.2
		 */
		protected function get_method_form_fields(): array {
			return [];
		}

		/**
		 * Skips user-meta persistence outside the message rendering under test.
		 *
		 * @param mixed $response API response
		 * @return void
		 * @since 2.0.2
		 */
		protected function add_add_payment_method_transaction_data( $response ): void {}

		/**
		 * Skips customer-meta persistence outside the message rendering under test.
		 *
		 * @param mixed $order order being processed
		 * @param mixed $response API response
		 * @return void
		 * @since 2.0.2
		 */
		protected function add_add_payment_method_customer_data( $order, $response ): void {}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway_Direct
	 */
	final class PaymentGatewayDirectXssTest extends TestCase {

		/**
		 * @return void
		 * @since 2.0.2
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'do_action' )->justReturn( true );
			Functions\when( 'esc_html' )->alias(
				static function ( string $value ): string {
					return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
				}
			);
		}

		/**
		 * Customer notices must render stored token data as text rather than executable markup.
		 *
		 * @return void
		 * @since 2.0.2
		 */
		public function test_add_payment_method_notice_escapes_token_details(): void {
			$payload = '<img src=x onerror=alert(1)>';
			$token   = \Mockery::mock( \Woodev_Payment_Gateway_Payment_Token::class );
			$token->shouldReceive( 'get_id' )->andReturn( 'token-id' );
			$token->shouldReceive( 'get_type_full' )->andReturn( $payload );
			$token->shouldReceive( 'get_last_four' )->andReturn( $payload );
			$token->shouldReceive( 'get_exp_date' )->andReturn( $payload );

			$response = new class( $token ) {

				/** @var Woodev_Payment_Gateway_Payment_Token */
				private $token;

				/**
				 * @param \Woodev_Payment_Gateway_Payment_Token $token payment token
				 */
				public function __construct( \Woodev_Payment_Gateway_Payment_Token $token ) {
					$this->token = $token;
				}

				/** @return bool */
				public function transaction_approved(): bool {
					return true;
				}

				/** @return \Woodev_Payment_Gateway_Payment_Token */
				public function get_payment_token(): \Woodev_Payment_Gateway_Payment_Token {
					return $this->token;
				}
			};
			$api = new class( $response ) {

				/** @var object */
				private $response;

				/**
				 * @param object $response tokenization response
				 */
				public function __construct( object $response ) {
					$this->response = $response;
				}

				/**
				 * @param \WC_Order $order order being tokenized
				 * @return object
				 */
				public function tokenize_payment_method( \WC_Order $order ): object {
					return $this->response;
				}
			};
			$handler = new class {

				/**
				 * @param int                                  $user_id user ID
				 * @param \Woodev_Payment_Gateway_Payment_Token $token payment token
				 * @return void
				 */
				public function add_token( int $user_id, \Woodev_Payment_Gateway_Payment_Token $token ): void {}
			};
			$gateway = new \Woodev_Testable_Payment_Gateway_Direct_Xss( $api, $handler );
			$result  = $gateway->run_add_payment_method_transaction( new \Woodev_Test_Direct_Xss_Order() );

			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $result['message'] );
			$this->assertStringNotContainsString( $payload, $result['message'] );
		}
	}
}

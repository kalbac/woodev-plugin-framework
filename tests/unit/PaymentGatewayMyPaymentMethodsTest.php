<?php
/**
 * Unit tests for the My Payment Methods renderer.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/handlers/script-handler.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/payment-tokens/class-payment-gateway-payment-token.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-my-payment-methods.php';

	/**
	 * Minimal plugin double for the details HTML filter name.
	 */
	class Woodev_Test_Payment_Gateway_Plugin {

		/**
		 * Gets the gateway ID.
		 *
		 * @return string
		 * @since 2.0.2
		 */
		public function get_id(): string {
			return 'test-gateway';
		}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/exceptions/class-payment-gateway-exception.php';

	/**
	 * Drives `ajax_save_payment_method()` far enough to reach its catch block with a FOREIGN
	 * exception — one thrown by the gateway's own token handler, i.e. plugin-authored code
	 * wrapping a third-party SDK, which is the case #610 is about.
	 *
	 * `load_tokens()` and `save_token_data()` are the only things stubbed, and only because
	 * they read WordPress state this test has no business reproducing. The catch block, the
	 * redaction and the response are the real ones.
	 */
	class Woodev_Test_My_Payment_Methods_For_Redaction extends Woodev_Payment_Gateway_My_Payment_Methods {

		/** @var object the plugin double whose gateway throws. */
		public $plugin_double;

		public function __construct() {}

		/**
		 * Seeds the one token the handler looks up, instead of reading the DB.
		 *
		 * @return void
		 */
		protected function load_tokens() {
			$this->tokens = [ 'tok_1' => new \Woodev_Payment_Gateway_Payment_Token( 'tok_1', [ 'type' => 'credit_card' ] ) ];
		}

		/**
		 * @param \Woodev_Payment_Gateway_Payment_Token $token token.
		 * @param array                                 $data  posted data.
		 * @return \Woodev_Payment_Gateway_Payment_Token
		 */
		protected function save_token_data( \Woodev_Payment_Gateway_Payment_Token $token, array $data ) {
			return $token;
		}

		/**
		 * @return object
		 */
		public function get_plugin() {
			return $this->plugin_double;
		}
	}

	/**
	 * Exposes the details renderer without initializing its WordPress hooks.
	 */
	class Woodev_Testable_Payment_Gateway_My_Payment_Methods extends Woodev_Payment_Gateway_My_Payment_Methods {

		/**
		 * Renders details HTML for a token.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token to render
		 * @return string
		 * @since 2.0.2
		 */
		public function render_details_html( Woodev_Payment_Gateway_Payment_Token $token ): string {
			return $this->get_payment_method_details_html( $token );
		}

		/**
		 * Gets the minimal plugin double needed by the renderer.
		 *
		 * @return Woodev_Test_Payment_Gateway_Plugin
		 * @since 2.0.2
		 */
		public function get_plugin(): Woodev_Test_Payment_Gateway_Plugin {
			return new Woodev_Test_Payment_Gateway_Plugin();
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway_My_Payment_Methods
	 */
	final class PaymentGatewayMyPaymentMethodsTest extends TestCase {

		/**
		 * @return void
		 * @since 2.0.2
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'esc_html' )->alias(
				static function ( string $value ): string {
					return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8', false );
				}
			);
		}

		/**
		 * A stored token field must remain text when its details are rendered on My Account.
		 *
		 * @return void
		 * @since 2.0.2
		 */
		public function test_details_html_escapes_a_malicious_last_four_value(): void {
			$token = new \Woodev_Payment_Gateway_Payment_Token(
				'token-id',
				[
					'type'      => 'bank',
					'last_four' => '<img src=x onerror=alert(1)>',
				]
			);
			$methods = ( new \ReflectionClass( \Woodev_Testable_Payment_Gateway_My_Payment_Methods::class ) )->newInstanceWithoutConstructor();
			$html    = $methods->render_details_html( $token );

			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
			$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $html );
		}

		/* ------------------------------------------------------------------- *
		 * #610 — the one response boundary whose reader is the CUSTOMER.
		 *
		 * Operator decision, 27.08.2026: every OTHER caught-exception response in
		 * this codebase keeps its raw message, because the reader is the shop
		 * admin and the provider's answer has to reach them (#608). This endpoint
		 * answers the account page, so its reader is the customer, and
		 * `update_token()` calls into the gateway's API — a plugin-authored client
		 * wrapping a third-party SDK, free to put anything in its message.
		 * ------------------------------------------------------------------- */

		/**
		 * @return void
		 */
		public function test_the_customer_facing_response_redacts_a_secret(): void {
			$this->assertSame(
				'carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
				$this->drive_save( 'carrier rejected api_key=LIVESECRET' )
			);
		}

		/**
		 * The control: an ordinary failure reaches the customer byte-for-byte, so the
		 * assertion above could not pass for a redactor that emptied or mangled
		 * everything — which would leave the customer with no idea what went wrong.
		 *
		 * @return void
		 */
		public function test_the_customer_facing_response_leaves_an_ordinary_message_intact(): void {
			$this->assertSame(
				'Карта отклонена банком',
				$this->drive_save( 'Карта отклонена банком' )
			);
		}

		/**
		 * Drives the real `ajax_save_payment_method()` with a token handler that throws
		 * $message, and returns what reached `wp_send_json_error()`.
		 *
		 * @param string $message what the gateway's own client throws.
		 * @return string
		 */
		private function drive_save( string $message ): string {
			Functions\when( 'check_ajax_referer' )->justReturn( true );
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'sanitize_text_field' )->returnArg();

			$_POST['token_id'] = 'tok_1';
			$_POST['data']     = '';

			$handler = \Mockery::mock();
			$handler->shouldReceive( 'user_has_token' )->andReturn( true );
			$handler->shouldReceive( 'update_token' )->once()->andThrow(
				new \Woodev_Payment_Gateway_Exception( $message )
			);

			$gateway = \Mockery::mock();
			$gateway->shouldReceive( 'get_payment_tokens_handler' )->andReturn( $handler );

			$plugin = \Mockery::mock();
			$plugin->shouldReceive( 'get_id' )->andReturn( 'test-gateway' );
			$plugin->shouldReceive( 'get_gateway_from_token' )->andReturn( $gateway );

			$captured = null;
			Functions\expect( 'wp_send_json_error' )
				->once()
				->with(
					\Mockery::on(
						static function ( $payload ) use ( &$captured ) {
							$captured = $payload;
							return true;
						}
					)
				);

			$methods                = new \Woodev_Test_My_Payment_Methods_For_Redaction();
			$methods->plugin_double = $plugin;

			$methods->ajax_save_payment_method();

			$_POST = [];

			return (string) $captured;
		}
	}
}

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
	}
}

<?php
/**
 * Unit tests for the checkout payment form's saved-payment-method rendering.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/handlers/script-handler.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/payment-tokens/class-payment-gateway-payment-token.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-payment-form.php';

	/**
	 * Minimal gateway double exposing only what the payment form title needs.
	 */
	class Woodev_Test_Payment_Form_Gateway {

		/**
		 * Gets the gateway ID used to build the payment-form filter name.
		 *
		 * @return string
		 * @since 2.0.2
		 */
		public function get_id(): string {
			return 'test-gateway';
		}
	}

	/**
	 * Exposes the protected saved-payment-method title renderer without initializing WordPress hooks.
	 */
	class Woodev_Testable_Payment_Gateway_Payment_Form extends Woodev_Payment_Gateway_Payment_Form {

		/**
		 * Renders the saved payment method title for a token.
		 *
		 * @param Woodev_Payment_Gateway_Payment_Token $token token to render
		 * @return string
		 * @since 2.0.2
		 */
		public function render_title_html( Woodev_Payment_Gateway_Payment_Token $token ): string {
			return $this->get_saved_payment_method_title( $token );
		}

		/**
		 * Gets the gateway double needed by the renderer.
		 *
		 * @return Woodev_Test_Payment_Form_Gateway
		 * @since 2.0.2
		 */
		public function get_gateway() {
			return new Woodev_Test_Payment_Form_Gateway();
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway_Payment_Form
	 */
	final class PaymentGatewayPaymentFormXssTest extends TestCase {

		/**
		 * @return void
		 * @since 2.0.2
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'apply_filters' )->returnArg( 2 );
		}

		/**
		 * Builds the testable payment form without running its constructor.
		 *
		 * @return \Woodev_Testable_Payment_Gateway_Payment_Form
		 * @since 2.0.2
		 */
		private function build_form(): \Woodev_Testable_Payment_Gateway_Payment_Form {
			return ( new \ReflectionClass( \Woodev_Testable_Payment_Gateway_Payment_Form::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * A malicious nickname must render as text, not executable markup.
		 *
		 * @return void
		 * @since 2.0.2
		 */
		public function test_title_escapes_a_malicious_nickname(): void {
			$payload = '<img src=x onerror=alert(1)>';

			$token = \Mockery::mock( \Woodev_Payment_Gateway_Payment_Token::class );
			$token->shouldReceive( 'get_image_url' )->andReturn( '' );
			$token->shouldReceive( 'get_last_four' )->andReturn( '4242' );
			$token->shouldReceive( 'get_type_full' )->andReturn( 'Visa' );
			$token->shouldReceive( 'get_nickname' )->andReturn( $payload );
			$token->shouldReceive( 'get_exp_month' )->andReturn( '' );
			$token->shouldReceive( 'get_exp_year' )->andReturn( '' );

			$html = $this->build_form()->render_title_html( $token );

			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
			$this->assertStringNotContainsString( $payload, $html );
		}

		/**
		 * A malicious expiration month must render as text, not executable markup.
		 *
		 * The value is interpolated inside a translated "(expires %s)" string; only the
		 * substituted value is attacker-controlled and must be escaped independently of
		 * the translation string around it.
		 *
		 * @return void
		 * @since 2.0.2
		 */
		public function test_title_escapes_a_malicious_expiry_date(): void {
			$payload = '<img src=x onerror=alert(1)>';

			$token = \Mockery::mock( \Woodev_Payment_Gateway_Payment_Token::class );
			$token->shouldReceive( 'get_image_url' )->andReturn( '' );
			$token->shouldReceive( 'get_last_four' )->andReturn( '4242' );
			$token->shouldReceive( 'get_type_full' )->andReturn( 'Visa' );
			$token->shouldReceive( 'get_nickname' )->andReturn( '' );
			$token->shouldReceive( 'get_exp_month' )->andReturn( $payload );
			$token->shouldReceive( 'get_exp_year' )->andReturn( '2027' );
			$token->shouldReceive( 'get_exp_date' )->andReturn( $payload . '/27' );

			$html = $this->build_form()->render_title_html( $token );

			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;/27', $html );
			$this->assertStringNotContainsString( $payload, $html );
		}

		/**
		 * Regression guard: the already-correctly-escaped neighbour fields (last four,
		 * card type) must remain escaped for their own HTML context.
		 *
		 * @return void
		 * @since 2.0.2
		 */
		public function test_title_escapes_last_four_and_type(): void {
			$markup_payload = '<img src=x onerror=alert(1)>';
			$attr_payload   = '"><script>alert(1)</script>';

			$token = \Mockery::mock( \Woodev_Payment_Gateway_Payment_Token::class );
			$token->shouldReceive( 'get_image_url' )->andReturn( '' );
			$token->shouldReceive( 'get_last_four' )->andReturn( $markup_payload );
			$token->shouldReceive( 'get_type_full' )->andReturn( $attr_payload );
			$token->shouldReceive( 'get_nickname' )->andReturn( '' );
			$token->shouldReceive( 'get_exp_month' )->andReturn( '' );
			$token->shouldReceive( 'get_exp_year' )->andReturn( '' );

			$html = $this->build_form()->render_title_html( $token );

			// last_four is text content -> esc_html context.
			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
			$this->assertStringNotContainsString( $markup_payload, $html );

			// type (no image URL, so rendered as plain text) is escaped for text content too.
			$this->assertStringNotContainsString( $attr_payload, $html );
		}
	}
}

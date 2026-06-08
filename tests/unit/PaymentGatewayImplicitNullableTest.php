<?php
/**
 * Verifies the H1 fix: no implicit-nullable parameters remain in the
 * payment-gateway tree. PHP 8.4+ deprecates implicit-nullable parameters
 * (e.g. `WC_Order $order = null` without `?WC_Order`).
 *
 * Before H1: this test enumerates and lists all sites. The current
 * RealisticPaymentFixtureTest masks the deprecation with error_reporting(),
 * hiding ~46 sites. This test does not mask — it asserts there are zero.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';

}

namespace Woodev\Tests\Unit {

	class PaymentGatewayImplicitNullableTest extends TestCase {

		/**
		 * No method in the payment-gateway tree may declare a non-nullable
		 * typed parameter with a `null` default value. PHP 8.4+ deprecates this
		 * pattern. The fix is to use explicit nullable type: `?Foo $bar = null`.
		 *
		 * This test loads the main payment gateway class and the abstract hosted
		 * handler — the two largest files in the directory — and asserts no
		 * implicit-nullable parameters are present.
		 *
		 * @return void
		 */
		public function test_payment_gateway_class_has_no_implicit_nullable_parameters(): void {
			$sites = $this->find_implicit_nullable_sites( \Woodev_Payment_Gateway::class );

			$this->assertEmpty(
				$sites,
				"Woodev_Payment_Gateway declares implicit-nullable parameters (PHP 8.4+ deprecation). Add explicit '?' to each type:\n  " . implode( "\n  ", $sites )
			);
		}

		/**
		 * @return void
		 */
		public function test_abstract_hosted_handler_has_no_implicit_nullable_parameters(): void {
			require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/handlers/abstract-hosted-payment-handler.php';

			$reflection = new \ReflectionClass( \Woodev_Payment_Gateway_Abstract_Hosted_Payment_Handler::class );
			$sites      = $this->find_implicit_nullable_sites_in_class( $reflection );

			$this->assertEmpty(
				$sites,
				"Woodev_Payment_Gateway_Abstract_Hosted_Payment_Handler declares implicit-nullable parameters (PHP 8.4+ deprecation). Add explicit '?' to each type:\n  " . implode( "\n  ", $sites )
			);
		}

		/**
		 * @return void
		 */
		public function test_abstract_payment_handler_has_no_implicit_nullable_parameters(): void {
			require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/handlers/abstract-payment-handler.php';

			$reflection = new \ReflectionClass( \Woodev_Payment_Gateway_Abstract_Payment_Handler::class );
			$sites      = $this->find_implicit_nullable_sites_in_class( $reflection );

			$this->assertEmpty(
				$sites,
				"Woodev_Payment_Gateway_Abstract_Payment_Handler declares implicit-nullable parameters (PHP 8.4+ deprecation). Add explicit '?' to each type:\n  " . implode( "\n  ", $sites )
			);
		}

		/**
		 * @return void
		 */
		public function test_payment_tokens_handler_has_no_implicit_nullable_parameters(): void {
			require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/payment-tokens/class-payment-gateway-payment-tokens-handler.php';

			$reflection = new \ReflectionClass( \Woodev_Payment_Gateway_Payment_Tokens_Handler::class );
			$sites      = $this->find_implicit_nullable_sites_in_class( $reflection );

			$this->assertEmpty(
				$sites,
				"Woodev_Payment_Gateway_Payment_Tokens_Handler declares implicit-nullable parameters (PHP 8.4+ deprecation). Add explicit '?' to each type:\n  " . implode( "\n  ", $sites )
			);
		}

		/**
		 * @return void
		 */
		public function test_capture_handler_has_no_implicit_nullable_parameters(): void {
			require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/handlers/capture.php';

			$reflection = new \ReflectionClass( \Woodev_Payment_Gateway_Capture_Handler::class );
			$sites      = $this->find_implicit_nullable_sites_in_class( $reflection );

			$this->assertEmpty(
				$sites,
				"Woodev_Payment_Gateway_Capture_Handler declares implicit-nullable parameters (PHP 8.4+ deprecation). Add explicit '?' to each type:\n  " . implode( "\n  ", $sites )
			);
		}

		/**
		 * Helper: find implicit-nullable parameters in a class loaded via the
		 * project autoloader. Loads the file with the same classmap resolution
		 * the production runtime uses.
		 *
		 * @param string $class FQCN.
		 * @return string[] List of "Class::method($param) at file:line" entries.
		 */
		private function find_implicit_nullable_sites( string $class ): array {
			if ( ! class_exists( $class ) ) {
				return [];
			}
			return $this->find_implicit_nullable_sites_in_class( new \ReflectionClass( $class ) );
		}

		/**
		 * Helper: enumerate implicit-nullable parameters in a reflection class.
		 *
		 * @param \ReflectionClass $rc Class to inspect.
		 * @return string[]
		 */
		private function find_implicit_nullable_sites_in_class( \ReflectionClass $rc ): array {
			$sites = [];
			foreach ( $rc->getMethods() as $method ) {
				foreach ( $method->getParameters() as $param ) {
					$type = $param->getType();
					if ( ! $type ) {
						continue;
					}
					if ( $type->allowsNull() ) {
						continue;
					}
					if ( ! $param->isDefaultValueAvailable() ) {
						continue;
					}
					if ( $param->getDefaultValue() !== null ) {
						continue;
					}
					$sites[] = sprintf(
						'%s::%s( $%s ) at %s:%d',
						$rc->getName(),
						$method->getName(),
						$param->getName(),
						$rc->getFileName(),
						$method->getStartLine()
					);
				}
			}
			return $sites;
		}
	}

}

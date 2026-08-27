<?php
/**
 * Guards on filter RETURN values at the six customer-facing sites — #613, from the #599 audit.
 *
 * The framework hands out a filter and then uses what comes back. Where that value is
 * dereferenced immediately (`$order->method()`, `array_merge()`, `array_key_exists()`), a
 * plugin returning the wrong type does not break the plugin — it breaks the page. These six
 * do it on paths a CUSTOMER sees: `process_payment()` at checkout, and the "Payment methods"
 * screen on My Account.
 *
 * The rule applied, settled in s100 and reaffirmed on #613: degrade to a safe default; never
 * throw, and never disable a protection. Here the safe default is always the PRE-FILTER value.
 *
 * Every site gets a PAIR:
 *   - a garbage return must not fatal, and the pre-filter value must survive;
 *   - a legitimate return must still be HONOURED.
 * The second half is what makes the pair worth writing: `return $order;` — a guard that simply
 * ignores the filter — passes the first test and breaks the hook.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		/**
		 * Minimal WooCommerce gateway base.
		 */
		class WC_Payment_Gateway {}
	}

	if ( ! class_exists( 'WC_Order', false ) ) {
		/**
		 * Minimal WooCommerce order base.
		 */
		class WC_Order {}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-direct.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-hosted.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/payment-tokens/class-payment-gateway-payment-token.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-my-payment-methods.php';

	/**
	 * A WC_Order stand-in that answers only what `get_order()` touches, and accepts the
	 * dynamic properties that method assigns.
	 */
	class Woodev_Test_Order_For_Filter_Guards extends \WC_Order {

		/** @var string */
		public $number;

		// Declared rather than left dynamic: `get_order()` assigns all four, and PHP 8.2+
		// deprecates creating a property on the fly. The real WC_Order tolerates it through
		// WC_Data's magic accessors; this stand-in has none.

		/** @var string */
		public $payment_total;

		/** @var string */
		public $customer_id;

		/** @var \stdClass */
		public $payment;

		/** @var string */
		public $description;

		/** @var \stdClass */
		public $capture;

		/**
		 * @param string $number order number.
		 */
		public function __construct( string $number = '42' ) {
			$this->number = $number;
		}

		/** @return float */
		public function get_total() {
			return 10.0;
		}

		/** @return int */
		public function get_user_id() {
			return 0;
		}

		/** @return string */
		public function get_order_number() {
			return $this->number;
		}
	}

	/**
	 * Drives the real `get_order()` / `get_order_for_capture()` with the heavy collaborators
	 * stubbed. The filter call and the guard under test are the real ones.
	 */
	class Woodev_Test_Gateway_For_Filter_Guards extends \Woodev_Payment_Gateway {

		/** @var string mirrors WC_Payment_Gateway::$id, which the stub above does not declare. */
		public $id = 'guards-gateway';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return string */
		public function get_method_title() {
			return 'Guards Gateway';
		}

		/** @return string */
		public function get_payment_type() {
			return 'credit-card';
		}

		/**
		 * @param int   $user_id user id.
		 * @param array $args    unused.
		 * @return false
		 */
		public function get_customer_id( $user_id, $args = [] ) {
			return false;
		}

		/**
		 * @param object $order order.
		 * @return object
		 */
		protected function get_order_with_unique_transaction_ref( $order ) {
			return $order;
		}

		/**
		 * @param object $order order.
		 * @param string $key   meta key.
		 * @return string
		 */
		public function get_order_meta( $order, $key ) {
			return '';
		}
	}

	/**
	 * Hosted's `get_order()` is `parent::get_order()` plus its own filter — both guards run.
	 */
	class Woodev_Test_Hosted_Gateway_For_Filter_Guards extends \Woodev_Payment_Gateway_Hosted {

		/** @var string */
		public $id = 'guards-hosted';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return string */
		public function get_method_title() {
			return 'Guards Hosted';
		}

		/** @return string */
		public function get_payment_type() {
			return 'credit-card';
		}

		/**
		 * @param object|null $order unused.
		 * @return string
		 */
		public function get_hosted_pay_page_url( $order = null ) {
			return '';
		}

		/**
		 * @param array $request_response_data unused.
		 * @return object
		 */
		protected function get_transaction_response( $request_response_data ) {
			return new \stdClass();
		}

		/**
		 * @param int   $user_id user id.
		 * @param array $args    unused.
		 * @return false
		 */
		public function get_customer_id( $user_id, $args = [] ) {
			return false;
		}

		/**
		 * @param object $order order.
		 * @return object
		 */
		protected function get_order_with_unique_transaction_ref( $order ) {
			return $order;
		}

		/**
		 * @param object $order order.
		 * @param string $key   meta key.
		 * @return string
		 */
		public function get_order_meta( $order, $key ) {
			return '';
		}
	}

	/**
	 * Direct's `get_order()` is `parent::get_order()`, then posted-payment handling, then its
	 * own filter. With an empty `$_POST` the middle section is skipped entirely, so the drive
	 * reaches the guard under test without inventing card data.
	 */
	class Woodev_Test_Direct_Gateway_For_Filter_Guards extends \Woodev_Payment_Gateway_Direct {

		/** @var string */
		public $id = 'guards-direct';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return string */
		public function get_method_title() {
			return 'Guards Direct';
		}

		/** @return string */
		public function get_payment_type() {
			return 'credit-card';
		}

		/** @return string */
		public function get_id_dasherized() {
			return 'guards-direct';
		}

		/**
		 * @param int   $user_id user id.
		 * @param array $args    unused.
		 * @return false
		 */
		public function get_customer_id( $user_id, $args = [] ) {
			return false;
		}

		/**
		 * @param object $order order.
		 * @return object
		 */
		protected function get_order_with_unique_transaction_ref( $order ) {
			return $order;
		}

		/**
		 * @param object $order order.
		 * @param string $key   meta key.
		 * @return string
		 */
		public function get_order_meta( $order, $key ) {
			return '';
		}
	}

	/**
	 * The core token `add_payment_methods_list_item_edit_action()` is handed. It asks only
	 * for `get_token()`, which it then resolves through the plugin's gateways.
	 */
	class WC_Payment_Token_For_Guards {

		/** @return string */
		public function get_token() {
			return 'tok_guards';
		}
	}

	/**
	 * The tokens handler `get_token_by_id()` walks to, answering with a framework token.
	 */
	class Woodev_Test_Tokens_Handler_For_Guards {

		/**
		 * @param int    $user_id  user id.
		 * @param string $token_id token id.
		 * @return \Woodev_Payment_Gateway_Payment_Token
		 */
		public function get_token( $user_id, $token_id ) {
			return new \Woodev_Payment_Gateway_Payment_Token( $token_id, [ 'type' => 'credit_card' ] );
		}
	}

	/**
	 * The gateway `get_token_by_id()` iterates over.
	 */
	class Woodev_Test_Gateway_For_Guards_Tokens {

		/** @return Woodev_Test_Tokens_Handler_For_Guards */
		public function get_payment_tokens_handler() {
			return new Woodev_Test_Tokens_Handler_For_Guards();
		}
	}

	/**
	 * Minimal plugin double whose id fixes the two My Account filter names.
	 */
	class Woodev_Test_Plugin_For_Filter_Guards {

		/** @return string */
		public function get_id(): string {
			return 'test-gateway';
		}

		/** @return array<int, Woodev_Test_Gateway_For_Guards_Tokens> */
		public function get_gateways(): array {
			return [ new Woodev_Test_Gateway_For_Guards_Tokens() ];
		}
	}

	/**
	 * Exposes the two customer-facing array sinks without booting WordPress hooks.
	 */
	class Woodev_Testable_My_Payment_Methods_For_Guards extends \Woodev_Payment_Gateway_My_Payment_Methods {

		public function __construct() {}

		/** @return Woodev_Test_Plugin_For_Filter_Guards */
		public function get_plugin() {
			return new Woodev_Test_Plugin_For_Filter_Guards();
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @coversNothing
	 */
	final class PaymentGatewayFilterReturnGuardsTest extends TestCase {

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'wp_specialchars_decode' )->returnArg( 1 );
			Functions\when( 'is_multisite' )->justReturn( false );
			Functions\when( 'get_bloginfo' )->justReturn( 'Shop' );
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway::get_order() — `..._get_order_base`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_get_order_falls_back_when_the_filter_returns_a_non_order(): void {
			$order = new \Woodev_Test_Order_For_Filter_Guards();

			$this->filter_returns( 'wc_payment_gateway_guards-gateway_get_order_base', 'not an order' );

			$this->assertSame( $order, ( new \Woodev_Test_Gateway_For_Filter_Guards() )->get_order( $order ) );
		}

		/**
		 * The control: a filter that returns a REAL order is still honoured, so the guard
		 * cannot be satisfied by ignoring the hook.
		 *
		 * @return void
		 */
		public function test_get_order_honours_a_filter_that_returns_a_real_order(): void {
			$order       = new \Woodev_Test_Order_For_Filter_Guards( '42' );
			$replacement = new \Woodev_Test_Order_For_Filter_Guards( '99' );

			$this->filter_returns( 'wc_payment_gateway_guards-gateway_get_order_base', $replacement );

			$this->assertSame( $replacement, ( new \Woodev_Test_Gateway_For_Filter_Guards() )->get_order( $order ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway_My_Payment_Methods — two array sinks on the
		 * customer's account page.
		 * ------------------------------------------------------------------ */

		/**
		 * `array_key_exists()` throws a TypeError on a non-array since PHP 8.
		 *
		 * @return void
		 */
		public function test_the_columns_filter_falls_back_when_it_returns_a_non_array(): void {
			$this->filter_returns( 'wc_test-gateway_my_payment_methods_table_headers', 'not an array' );

			$columns = $this->methods()->add_payment_methods_columns( [ 'method' => 'Method' ] );

			// The method's OWN columns, built before the filter runs — that is the safe
			// default here, not the caller's bare input.
			$this->assertSame(
				[
					'method'  => 'Method',
					'title'   => 'Title',
					'details' => 'Details',
				],
				$columns
			);
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_the_columns_filter_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns( 'wc_test-gateway_my_payment_methods_table_headers', [ 'mine' => 'Mine' ] );

			$this->assertSame( [ 'mine' => 'Mine' ], $this->methods()->add_payment_methods_columns( [ 'method' => 'Method' ] ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway::get_order_for_capture() — `..._get_order_for_capture`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_get_order_for_capture_falls_back_when_the_filter_returns_a_non_order(): void {
			$order = new \Woodev_Test_Order_For_Filter_Guards();

			$this->filter_returns( 'wc_payment_gateway_guards-gateway_get_order_for_capture', [ 'not', 'an', 'order' ] );

			$this->assertSame( $order, ( new \Woodev_Test_Gateway_For_Filter_Guards() )->get_order_for_capture( $order ) );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_order_for_capture_honours_a_filter_that_returns_a_real_order(): void {
			$order       = new \Woodev_Test_Order_For_Filter_Guards( '42' );
			$replacement = new \Woodev_Test_Order_For_Filter_Guards( '99' );

			$this->filter_returns( 'wc_payment_gateway_guards-gateway_get_order_for_capture', $replacement );

			$this->assertSame( $replacement, ( new \Woodev_Test_Gateway_For_Filter_Guards() )->get_order_for_capture( $order ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway_Hosted::get_order() — `..._get_order`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_hosted_get_order_falls_back_when_the_filter_returns_a_non_order(): void {
			$order = new \Woodev_Test_Order_For_Filter_Guards();

			$this->filter_returns( 'wc_payment_gateway_guards-hosted_get_order', null );

			$this->assertSame( $order, ( new \Woodev_Test_Hosted_Gateway_For_Filter_Guards() )->get_order( $order ) );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_hosted_get_order_honours_a_filter_that_returns_a_real_order(): void {
			$order       = new \Woodev_Test_Order_For_Filter_Guards( '42' );
			$replacement = new \Woodev_Test_Order_For_Filter_Guards( '99' );

			$this->filter_returns( 'wc_payment_gateway_guards-hosted_get_order', $replacement );

			$this->assertSame( $replacement, ( new \Woodev_Test_Hosted_Gateway_For_Filter_Guards() )->get_order( $order ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway_Direct::get_order() — `..._get_order`, and the
		 * highest-traffic path in the gateway: process_payment() at checkout.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_direct_get_order_falls_back_when_the_filter_returns_a_non_order(): void {
			$order = new \Woodev_Test_Order_For_Filter_Guards();

			$this->filter_returns( 'wc_payment_gateway_guards-direct_get_order', false );

			$this->assertSame( $order, ( new \Woodev_Test_Direct_Gateway_For_Filter_Guards() )->get_order( $order ) );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_direct_get_order_honours_a_filter_that_returns_a_real_order(): void {
			$order       = new \Woodev_Test_Order_For_Filter_Guards( '42' );
			$replacement = new \Woodev_Test_Order_For_Filter_Guards( '99' );

			$this->filter_returns( 'wc_payment_gateway_guards-direct_get_order', $replacement );

			$this->assertSame( $replacement, ( new \Woodev_Test_Direct_Gateway_For_Filter_Guards() )->get_order( $order ) );
		}

		/* ------------------------------------------------------------------ *
		 * My Payment Methods — the custom-actions array sink.
		 * ------------------------------------------------------------------ */

		/**
		 * `array_merge()` throws a TypeError on a non-array since PHP 8.
		 *
		 * @return void
		 */
		public function test_the_custom_actions_filter_falls_back_when_it_returns_a_non_array(): void {
			$this->filter_returns( 'wc_test-gateway_my_payment_methods_table_method_actions', 'not an array' );

			$item = $this->methods()->add_payment_methods_list_item_edit_action(
				[ 'method' => [ 'gateway' => 'test-gateway' ], 'actions' => [] ],
				new \WC_Payment_Token_For_Guards()
			);

			$this->assertIsArray( $item['actions'] );
			$this->assertNotContains( 'not an array', $item['actions'] );
		}

		/**
		 * The control: a real array of custom actions still reaches the rendered item.
		 *
		 * @return void
		 */
		public function test_the_custom_actions_filter_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns(
				'wc_test-gateway_my_payment_methods_table_method_actions',
				[ [ 'url' => '#mine', 'name' => 'Mine' ] ]
			);

			$item = $this->methods()->add_payment_methods_list_item_edit_action(
				[ 'method' => [ 'gateway' => 'test-gateway' ], 'actions' => [] ],
				new \WC_Payment_Token_For_Guards()
			);

			$this->assertContains( [ 'url' => '#mine', 'name' => 'Mine' ], $item['actions'] );
		}

		/**
		 * A gateway double whose plugin id matches the filter names above.
		 *
		 * @return \Woodev_Testable_My_Payment_Methods_For_Guards
		 */
		private function methods(): \Woodev_Testable_My_Payment_Methods_For_Guards {
			return new \Woodev_Testable_My_Payment_Methods_For_Guards();
		}

		/**
		 * Makes `apply_filters()` return $value for $hook and the unfiltered value otherwise.
		 *
		 * @param string $hook  hook name to intercept.
		 * @param mixed  $value what the plugin returns.
		 * @return void
		 */
		private function filter_returns( string $hook, $value ): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) use ( $hook, $value ) {
					return $hook === $tag ? $value : $filtered;
				}
			);
		}
	}
}

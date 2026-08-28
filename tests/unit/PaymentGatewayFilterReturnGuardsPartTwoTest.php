<?php
/**
 * Guards on filter RETURN values at the admin/background remainder of #613 — tranche 5,
 * from the #599 audit. Tranche 1 (PR #619) covered the six customer-facing sites; this
 * covers the rest: admin screens, an admin AJAX handler, and the held-order path.
 *
 * The rule applied is the same as tranche 1: degrade to a safe default; never throw, and
 * never disable a protection. The safe default is always the PRE-FILTER value, except for
 * the "held order status" sites, whose safe default is the fixed 'on-hold' status once the
 * filtered value fails validation against `wc_get_order_statuses()`.
 *
 * Every site gets a PAIR:
 *   - a garbage return must not fatal, and the pre-filter value must survive;
 *   - a legitimate return must still be HONOURED.
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
		 *
		 * get_id() returns 123 to match the shape every other guarded `WC_Order` stub in
		 * this suite uses (see PaymentGatewayDirectXssTest.php) — whichever test file's
		 * version loads first "wins" for the whole run, so a mismatched value here would be
		 * a behavior change for unrelated tests, not a local detail (see gotcha
		 * a-class-exists-guarded-test-stub-is-won-by-whoever-loads-first).
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

	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-direct.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/handlers/abstract-payment-handler.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/admin/class-payment-gateway-admin-payment-token-editor.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/class-payment-gateway-api-response-message-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/handlers/script-handler.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway-payment-form.php';

	/* ------------------------------------------------------------------ *
	 * Admin token editor doubles
	 * ------------------------------------------------------------------ */

	/**
	 * A gateway double whose `get_payment_type()` deliberately isn't 'credit-card', so
	 * `get_fields()`'s pre-filter value is a plain empty array — keeping the token-editor
	 * tests isolated from the credit-card field set and its `get_api()` dependency.
	 */
	class Woodev_Test_Gateway_For_Token_Editor_Guards extends \Woodev_Payment_Gateway {

		/** @var string */
		public $id = 'guards-token-editor';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return string */
		public function get_payment_type() {
			return '';
		}
	}

	/**
	 * Exposes the three protected/guarded sinks under test without booting the real
	 * constructor's `add_action()` calls.
	 */
	class Woodev_Testable_Admin_Payment_Token_Editor_For_Guards extends \Woodev_Payment_Gateway_Admin_Payment_Token_Editor {

		/**
		 * @param \Woodev_Payment_Gateway $gateway gateway double.
		 */
		public function __construct( $gateway ) {
			$this->gateway = $gateway;
		}

		/** @return array */
		public function call_get_columns() {
			return $this->get_columns();
		}

		/** @return array */
		public function call_get_fields() {
			return $this->get_fields();
		}

		/**
		 * @param int|string $token_id token id.
		 * @param array      $data     token data.
		 * @return array|bool
		 */
		public function call_validate_token_data( $token_id, $data ) {
			return $this->validate_token_data( $token_id, $data );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Payment form doubles
	 * ------------------------------------------------------------------ */

	/**
	 * A gateway double for the payment form's two rendering-chain filters.
	 */
	class Woodev_Test_Gateway_For_Payment_Form_Guards extends \Woodev_Payment_Gateway {

		/** @var string */
		public $id = 'guards-form';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return string */
		public function get_payment_type() {
			return '';
		}

		/**
		 * Bypasses the real `assert( $this->supports_payment_form() )` guard, which calls
		 * into the (stubbed-empty) WC_Payment_Gateway::supports().
		 *
		 * @return array
		 */
		public function get_payment_method_defaults() {
			return [
				'account-number' => '',
				'expiry'         => '',
				'csc'            => '',
			];
		}
	}

	/**
	 * Exposes the two protected field-builder methods under test.
	 */
	class Woodev_Testable_Payment_Form_For_Guards extends \Woodev_Payment_Gateway_Payment_Form {

		/**
		 * @param \Woodev_Payment_Gateway $gateway gateway double.
		 */
		public function __construct( $gateway ) {
			$this->gateway = $gateway;
		}

		/** @return array */
		public function call_get_payment_fields() {
			return $this->get_payment_fields();
		}

		/** @return array */
		public function call_get_credit_card_fields() {
			return $this->get_credit_card_fields();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Direct gateway — add payment method transaction result doubles
	 * ------------------------------------------------------------------ */

	/**
	 * A failed tokenize-payment-method API response, so the transaction result built inside
	 * `do_add_payment_method_transaction()` is reached without needing token/customer-data
	 * collaborators.
	 */
	class Woodev_Test_Response_For_Add_Payment_Method_Guards {

		/** @return false */
		public function transaction_approved() {
			return false;
		}

		/** @return string */
		public function get_status_code() {
			return '';
		}

		/** @return string */
		public function get_status_message() {
			return '';
		}
	}

	/**
	 * The gateway API double `do_add_payment_method_transaction()` calls into.
	 */
	class Woodev_Test_Api_For_Add_Payment_Method_Guards {

		/**
		 * @param object $order unused.
		 * @return Woodev_Test_Response_For_Add_Payment_Method_Guards
		 */
		public function tokenize_payment_method( $order ) {
			return new \Woodev_Test_Response_For_Add_Payment_Method_Guards();
		}
	}

	/**
	 * A Direct gateway double exposing `do_add_payment_method_transaction()`.
	 */
	class Woodev_Test_Direct_Gateway_For_Add_Payment_Method_Guards extends \Woodev_Payment_Gateway_Direct {

		/** @var string */
		public $id = 'guards-add-payment-method';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return string */
		public function get_payment_type() {
			return 'credit-card';
		}

		/** @return Woodev_Test_Api_For_Add_Payment_Method_Guards */
		public function get_api() {
			return new \Woodev_Test_Api_For_Add_Payment_Method_Guards();
		}

		/**
		 * @param \WC_Order $order order.
		 * @return array
		 */
		public function call_do_add_payment_method_transaction( \WC_Order $order ) {
			return $this->do_add_payment_method_transaction( $order );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Held order status doubles — shared across the three call sites
	 * ------------------------------------------------------------------ */

	/**
	 * A gateway double used for both `validate_held_order_status()` and
	 * `mark_order_as_held()` (`class-payment-gateway.php`) and, via composition, for
	 * `Woodev_Payment_Gateway_Abstract_Payment_Handler::get_held_order_status()`.
	 */
	class Woodev_Test_Gateway_For_Held_Status_Guards extends \Woodev_Payment_Gateway {

		/** @var string */
		public $id = 'guards-held-status';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return string */
		public function get_method_title() {
			return 'Guards Held Status';
		}
	}

	/**
	 * A WC_Order stand-in that records the status `has_status()` was asked to check —
	 * that argument IS the validated `$order_status` under test.
	 */
	class Woodev_Test_Order_For_Held_Status_Guards {

		/** @var string|null the status `has_status()` was called with */
		public $held_check_status;

		/** @var bool what `has_status()` should answer */
		public $has_status_result = false;

		/**
		 * @param string $status status to check.
		 * @return bool
		 */
		public function has_status( $status ) {
			$this->held_check_status = $status;

			return $this->has_status_result;
		}

		/**
		 * @param string $status status to set.
		 * @param string $note   order note.
		 */
		public function update_status( $status, $note ) {}

		/**
		 * @param string $note order note.
		 */
		public function add_order_note( $note ) {}
	}

	/**
	 * Exposes `Woodev_Payment_Gateway_Abstract_Payment_Handler::get_held_order_status()`
	 * (already public) without booting the real constructor's `add_hooks()`.
	 */
	class Woodev_Testable_Payment_Handler_For_Held_Status_Guards extends \Woodev_Payment_Gateway_Abstract_Payment_Handler {

		/**
		 * @param \Woodev_Payment_Gateway $gateway gateway double.
		 */
		public function __construct( $gateway ) {
			$this->gateway = $gateway;
		}

		/**
		 * Unused by these tests — only the abstract signature needs satisfying.
		 *
		 * @param \WC_Order $order unused.
		 * @return array
		 */
		public function process_order_payment( \WC_Order $order ) {
			return [];
		}
	}

	/**
	 * A WC_Order stand-in for `process_payment()`'s zero-dollar, held-order path. Mirrors
	 * `has_status()`'s recording trick above.
	 */
	class Woodev_Test_Order_For_Process_Payment_Guards extends \WC_Order {

		/** @var string */
		public $payment_total = '0.00';

		/** @var string */
		public $customer_id;

		/** @var \stdClass */
		public $payment;

		/** @var string */
		public $description;

		/** @var string|null the status `has_status()` was called with */
		public $held_check_status;

		/** @var bool what `has_status()` should answer */
		public $has_status_result = false;

		/** @return float */
		public function get_total() {
			return 0.0;
		}

		/** @return int */
		public function get_user_id() {
			return 0;
		}

		/** @return string */
		public function get_order_number() {
			return '42';
		}

		/**
		 * @param string $status status to check.
		 * @return bool
		 */
		public function has_status( $status ) {
			$this->held_check_status = $status;

			return $this->has_status_result;
		}

		public function payment_complete() {}
	}

	/**
	 * A Direct gateway double that drives `process_payment()`'s zero-dollar path down to
	 * the held-order-status guard, with every unrelated collaborator neutralised.
	 */
	class Woodev_Test_Direct_Gateway_For_Process_Payment_Guards extends \Woodev_Payment_Gateway_Direct {

		/** @var string */
		public $id = 'guards-process-payment';

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
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
		 * @param object|null $order unused.
		 * @return string
		 */
		public function get_return_url( $order = null ) {
			return 'https://example.test/checkout/order-received/';
		}

		/** @return false */
		public function supports_tokenization() {
			return false;
		}

		/**
		 * @param object      $order    order.
		 * @param object|null $response unused.
		 */
		public function add_transaction_data( $order, $response = null ) {}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @coversNothing
	 */
	final class PaymentGatewayFilterReturnGuardsPartTwoTest extends TestCase {

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'wp_specialchars_decode' )->returnArg( 1 );
			Functions\when( 'is_multisite' )->justReturn( false );
			Functions\when( 'get_bloginfo' )->justReturn( 'Shop' );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'wc_get_order_statuses' )->justReturn( self::order_statuses() );
		}

		/**
		 * Defines `WC()` for the ONE code path that calls it —
		 * `Woodev_Payment_Gateway_Direct::process_payment()`'s `isset( WC()->cart )`.
		 *
		 * Deliberately NOT in `setUp()`. Brain Monkey DEFINES a stubbed function for the
		 * whole PHP process and PHP cannot un-define it, so a blanket `WC` stub here made
		 * `function_exists( 'WC' )` true for every test class that ran afterwards — 268
		 * errors in `Checkout_Config`, `Pickup_Handler` and friends, all of which assume
		 * WooCommerce is ABSENT under the unit runtime. Gotcha
		 * `brain-monkey-function-pollution`; it is also what card #606 was about.
		 *
		 * The four callers are annotated `@runInSeparateProcess`, so the definition lives
		 * and dies in a child process and never reaches the rest of the suite.
		 *
		 * @return void
		 */
		private function define_wc(): void {
			Functions\when( 'WC' )->justReturn( new \stdClass() );
		}

		/* ------------------------------------------------------------------ *
		 * Admin_Payment_Token_Editor::get_columns() — `..._token_editor_columns`
		 * ------------------------------------------------------------------ */

		/**
		 * `count()` throws a TypeError on a non-Countable/array since PHP 8 — the view does
		 * it twice, for a table `colspan`.
		 *
		 * @return void
		 */
		public function test_get_columns_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'wc_payment_gateway_guards-token-editor_token_editor_columns', 'not an array' );

			$this->assertSame(
				[
					'default' => 'Default',
					'actions' => '',
				],
				$this->token_editor()->call_get_columns()
			);
		}

		/**
		 * The control: a filter that returns a REAL array of columns is still honoured.
		 *
		 * @return void
		 */
		public function test_get_columns_honours_a_filter_that_returns_an_array(): void {
			$this->filter_returns( 'wc_payment_gateway_guards-token-editor_token_editor_columns', [ 'mine' => 'Mine' ] );

			$this->assertSame( [ 'mine' => 'Mine' ], $this->token_editor()->call_get_columns() );
		}

		/* ------------------------------------------------------------------ *
		 * Admin_Payment_Token_Editor::get_fields() — `..._token_editor_fields`
		 * ------------------------------------------------------------------ */

		/**
		 * `array_keys()` throws a TypeError on a non-array since PHP 8 — fatals the
		 * "add token" AJAX handler, which calls `array_fill_keys( array_keys( $fields ), '' )`.
		 *
		 * @return void
		 */
		public function test_get_fields_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'wc_payment_gateway_guards-token-editor_token_editor_fields', 'not an array' );

			$this->assertSame( [], $this->token_editor()->call_get_fields() );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_fields_honours_a_filter_that_returns_an_array(): void {
			$this->filter_returns(
				'wc_payment_gateway_guards-token-editor_token_editor_fields',
				[ 'custom' => [ 'label' => 'Custom' ] ]
			);

			$this->assertSame(
				[ 'custom' => [ 'label' => 'Custom' ] ],
				$this->token_editor()->call_get_fields()
			);
		}

		/* ------------------------------------------------------------------ *
		 * Admin_Payment_Token_Editor::validate_token_data() — `..._token_editor_validate_token_data`
		 * ------------------------------------------------------------------ */

		/**
		 * The docblock promises "array or false"; the old code checked only truthiness, so a
		 * truthy non-array reached the persisted token constructor and corrupted saved data.
		 *
		 * @return void
		 */
		public function test_validate_token_data_falls_back_when_the_filter_returns_a_truthy_non_array(): void {
			$data = [
				'type'      => 'credit_card',
				'last_four' => '4242',
			];

			$this->filter_returns( 'wc_payment_gateway_guards-token-editor_token_editor_validate_token_data', 'yes' );

			$this->assertSame( $data, $this->token_editor()->call_validate_token_data( 'tok_1', $data ) );
		}

		/**
		 * The control: a real array is still honoured.
		 *
		 * @return void
		 */
		public function test_validate_token_data_honours_a_filter_that_returns_an_array(): void {
			$data      = [ 'type' => 'credit_card' ];
			$validated = [
				'type'      => 'credit_card',
				'last_four' => '0000',
			];

			$this->filter_returns( 'wc_payment_gateway_guards-token-editor_token_editor_validate_token_data', $validated );

			$this->assertSame( $validated, $this->token_editor()->call_validate_token_data( 'tok_1', $data ) );
		}

		/**
		 * The control's other legitimate value: `false` (skip saving this token) must also
		 * still be honoured, not just arrays.
		 *
		 * @return void
		 */
		public function test_validate_token_data_honours_a_filter_that_returns_false(): void {
			$data = [ 'type' => 'credit_card' ];

			$this->filter_returns( 'wc_payment_gateway_guards-token-editor_token_editor_validate_token_data', false );

			$this->assertFalse( $this->token_editor()->call_validate_token_data( 'tok_1', $data ) );
		}

		/**
		 * @return \Woodev_Testable_Admin_Payment_Token_Editor_For_Guards
		 */
		private function token_editor(): \Woodev_Testable_Admin_Payment_Token_Editor_For_Guards {
			return new \Woodev_Testable_Admin_Payment_Token_Editor_For_Guards( new \Woodev_Test_Gateway_For_Token_Editor_Guards() );
		}

		/* ------------------------------------------------------------------ *
		 * API_Response_Message_Helper::get_user_message() — `..._transaction_response_user_message`
		 * ------------------------------------------------------------------ */

		/**
		 * A non-string reaching `new Woodev_Payment_Gateway_Exception( $message )` fatals:
		 * native `Exception::__construct( string $message )` is typed and does not accept an
		 * array/object. The exception-throwing hop (a downstream `get_user_message()`
		 * implementation) lives outside this repo and is NOT exercised here — only this
		 * helper's own filter return is verified.
		 *
		 * @return void
		 */
		public function test_get_user_message_falls_back_when_the_filter_returns_a_non_string(): void {
			$this->filter_returns( 'wc_payment_gateway_transaction_response_user_message', [ 'not', 'a', 'string' ] );

			$helper = new \Woodev_Payment_Gateway_API_Response_Message_Helper();

			$this->assertSame(
				'Произошла ошибка, попробуйте еще раз или попробуйте альтернативный способ оплаты',
				$helper->get_user_message( 'error' )
			);
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_user_message_honours_a_filter_that_returns_a_string(): void {
			$this->filter_returns( 'wc_payment_gateway_transaction_response_user_message', 'Custom message' );

			$helper = new \Woodev_Payment_Gateway_API_Response_Message_Helper();

			$this->assertSame( 'Custom message', $helper->get_user_message( 'error' ) );
		}

		/* ------------------------------------------------------------------ *
		 * Payment_Form::get_payment_fields() / get_credit_card_fields() —
		 * same rendering chain, `render_payment_fields()`'s `foreach`.
		 * ------------------------------------------------------------------ */

		/**
		 * `foreach` on a non-array is a Warning, not a fatal — the loop is silently skipped,
		 * so checkout renders no payment fields at all.
		 *
		 * @return void
		 */
		public function test_get_payment_fields_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'wc_guards-form_payment_form_default_payment_form_fields', 'not an array' );

			$this->assertSame( [], $this->payment_form()->call_get_payment_fields() );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_payment_fields_honours_a_filter_that_returns_an_array(): void {
			$this->filter_returns(
				'wc_guards-form_payment_form_default_payment_form_fields',
				[ 'custom-field' => [ 'type' => 'text' ] ]
			);

			$this->assertSame(
				[ 'custom-field' => [ 'type' => 'text' ] ],
				$this->payment_form()->call_get_payment_fields()
			);
		}

		/**
		 * Same consequence as above — this feeds `get_payment_fields()`'s own filtered
		 * result, which `render_payment_fields()` iterates.
		 *
		 * @return void
		 */
		public function test_get_credit_card_fields_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'wc_guards-form_payment_form_default_credit_card_fields', 'not an array' );

			$fields = $this->payment_form()->call_get_credit_card_fields();

			$this->assertArrayHasKey( 'card-number', $fields );
			$this->assertArrayHasKey( 'card-expiry', $fields );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_credit_card_fields_honours_a_filter_that_returns_an_array(): void {
			$this->filter_returns(
				'wc_guards-form_payment_form_default_credit_card_fields',
				[ 'custom-field' => [ 'type' => 'text' ] ]
			);

			$this->assertSame(
				[ 'custom-field' => [ 'type' => 'text' ] ],
				$this->payment_form()->call_get_credit_card_fields()
			);
		}

		/**
		 * @return \Woodev_Testable_Payment_Form_For_Guards
		 */
		private function payment_form(): \Woodev_Testable_Payment_Form_For_Guards {
			return new \Woodev_Testable_Payment_Form_For_Guards( new \Woodev_Test_Gateway_For_Payment_Form_Guards() );
		}

		/* ------------------------------------------------------------------ *
		 * Direct::do_add_payment_method_transaction() — `..._add_payment_method_transaction_result`
		 * ------------------------------------------------------------------ */

		/**
		 * Array-offset access (`$result['message']`/`$result['success']`) on a non-array is a
		 * PHP Warning, not a fatal — degraded UX, not a crash. The `is_array()` guard this
		 * needed already existed two methods away in the same file, at `process_payment()`.
		 *
		 * @return void
		 */
		public function test_add_payment_method_transaction_result_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns(
				'wc_payment_gateway_guards-add-payment-method_add_payment_method_transaction_result',
				'not an array'
			);

			$gateway = new \Woodev_Test_Direct_Gateway_For_Add_Payment_Method_Guards();
			$result  = $gateway->call_do_add_payment_method_transaction( new \WC_Order() );

			$this->assertIsArray( $result );
			$this->assertFalse( $result['success'] );
			$this->assertSame( 'Unknown Error', $result['message'] );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_add_payment_method_transaction_result_honours_a_filter_that_returns_an_array(): void {
			$this->filter_returns(
				'wc_payment_gateway_guards-add-payment-method_add_payment_method_transaction_result',
				[
					'message' => 'Custom',
					'success' => true,
				]
			);

			$gateway = new \Woodev_Test_Direct_Gateway_For_Add_Payment_Method_Guards();
			$result  = $gateway->call_do_add_payment_method_transaction( new \WC_Order() );

			$this->assertSame(
				[
					'message' => 'Custom',
					'success' => true,
				],
				$result
			);
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway::validate_held_order_status() — the guard
		 * shared by all three "held order status" implementations.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_validate_held_order_status_degrades_a_non_real_status_to_on_hold(): void {
			$gateway = new \Woodev_Test_Gateway_For_Held_Status_Guards();

			$this->assertSame( 'on-hold', $gateway->validate_held_order_status( [ 'not', 'a', 'status' ] ) );
			$this->assertSame( 'on-hold', $gateway->validate_held_order_status( 'not-a-real-status' ) );
			$this->assertSame( 'on-hold', $gateway->validate_held_order_status( '' ) );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_validate_held_order_status_honours_a_real_status(): void {
			$gateway = new \Woodev_Test_Gateway_For_Held_Status_Guards();

			$this->assertSame( 'processing', $gateway->validate_held_order_status( 'processing' ) );
			$this->assertSame( 'on-hold', $gateway->validate_held_order_status( 'on-hold' ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway::mark_order_as_held() — deprecated
		 * `wc_payment_gateway_{id}_held_order_status` feeding current
		 * `wc_{id}_held_order_status`.
		 * ------------------------------------------------------------------ */

		/**
		 * Neither filter validates its return, so a value that isn't a real order status
		 * silently defeats `has_status()` and lets a held order proceed as if approved.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @return void
		 */
		public function test_mark_order_as_held_falls_back_to_on_hold_when_both_filters_return_garbage(): void {
			$this->define_wc();

			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) {
					if ( 'wc_payment_gateway_guards-held-status_held_order_status' === $tag ) {
						return [ 'garbage' ];
					}
					if ( 'wc_guards-held-status_held_order_status' === $tag ) {
						return 'also-garbage';
					}

					return $filtered;
				}
			);

			$order   = new \Woodev_Test_Order_For_Held_Status_Guards();
			$gateway = new \Woodev_Test_Gateway_For_Held_Status_Guards();

			$gateway->mark_order_as_held( $order, 'reason' );

			$this->assertSame( 'on-hold', $order->held_check_status );
		}

		/**
		 * The control: a real status from the current (non-deprecated) filter is honoured —
		 * the deprecated-then-current chaining must stay intact.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @return void
		 */
		public function test_mark_order_as_held_honours_a_real_status_from_the_current_filter(): void {
			$this->define_wc();

			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) {
					if ( 'wc_guards-held-status_held_order_status' === $tag ) {
						return 'processing';
					}

					return $filtered;
				}
			);

			$order   = new \Woodev_Test_Order_For_Held_Status_Guards();
			$gateway = new \Woodev_Test_Gateway_For_Held_Status_Guards();

			$gateway->mark_order_as_held( $order, 'reason' );

			$this->assertSame( 'processing', $order->held_check_status );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Payment_Gateway_Abstract_Payment_Handler::get_held_order_status()
		 * — the second independent "held order status" implementation.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_get_held_order_status_falls_back_when_both_filters_return_garbage(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) {
					if ( 'wc_payment_gateway_guards-held-status_held_order_status' === $tag ) {
						return [ 'garbage' ];
					}
					if ( 'wc_guards-held-status_held_order_status' === $tag ) {
						return 'also-garbage';
					}

					return $filtered;
				}
			);

			$handler = new \Woodev_Testable_Payment_Handler_For_Held_Status_Guards( new \Woodev_Test_Gateway_For_Held_Status_Guards() );

			$this->assertSame( 'on-hold', $handler->get_held_order_status( new \WC_Order() ) );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_held_order_status_honours_a_real_status_from_the_current_filter(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) {
					if ( 'wc_guards-held-status_held_order_status' === $tag ) {
						return 'cancelled';
					}

					return $filtered;
				}
			);

			$handler = new \Woodev_Testable_Payment_Handler_For_Held_Status_Guards( new \Woodev_Test_Gateway_For_Held_Status_Guards() );

			$this->assertSame( 'cancelled', $handler->get_held_order_status( new \WC_Order() ) );
		}

		/* ------------------------------------------------------------------ *
		 * Direct::process_payment() — the third "held order status" call site,
		 * and the highest-traffic path in the gateway (checkout payment approval).
		 * ------------------------------------------------------------------ */

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @return void
		 */
		public function test_process_payment_falls_back_to_on_hold_when_the_filter_returns_a_non_real_status(): void {
			$this->define_wc();

			$this->filter_returns( 'wc_guards-process-payment_held_order_status', [ 'garbage' ] );

			$order   = new \Woodev_Test_Order_For_Process_Payment_Guards();
			$gateway = new \Woodev_Test_Direct_Gateway_For_Process_Payment_Guards();

			$gateway->process_payment( $order );

			$this->assertSame( 'on-hold', $order->held_check_status );
		}

		/**
		 * The control.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @return void
		 */
		public function test_process_payment_honours_a_real_status_from_the_filter(): void {
			$this->define_wc();

			$this->filter_returns( 'wc_guards-process-payment_held_order_status', 'failed' );

			$order   = new \Woodev_Test_Order_For_Process_Payment_Guards();
			$gateway = new \Woodev_Test_Direct_Gateway_For_Process_Payment_Guards();

			$gateway->process_payment( $order );

			$this->assertSame( 'failed', $order->held_check_status );
		}

		/**
		 * A realistic `wc_get_order_statuses()` map (unprefixed keys stripped by the guard).
		 *
		 * @return array<string, string>
		 */
		private static function order_statuses(): array {
			return [
				'wc-pending'    => 'Pending payment',
				'wc-processing' => 'Processing',
				'wc-on-hold'    => 'On hold',
				'wc-completed'  => 'Completed',
				'wc-cancelled'  => 'Cancelled',
				'wc-refunded'   => 'Refunded',
				'wc-failed'     => 'Failed',
			];
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

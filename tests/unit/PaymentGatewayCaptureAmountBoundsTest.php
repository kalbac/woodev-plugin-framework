<?php
/**
 * Verifies #781: Woodev_Payment_Gateway_Capture_Handler::perform_capture() bounds the
 * capture amount to `(0, get_order_capture_maximum()]` instead of forwarding whatever
 * it is given straight to the gateway's API.
 *
 * The bound is checked against `$order->capture->amount` — the value resolved by
 * `get_order_for_capture()` — never the raw `$amount` argument, because:
 *  - a falsy `$amount` (`null` or `0`) is a documented request to capture the full
 *    remaining balance, resolved by `get_order_for_capture()` before the guard runs;
 *    bounding the raw argument would wrongly reject that request as "zero amount".
 *  - `get_order_for_capture()` also runs an extensibility filter that can rewrite the
 *    order's capture amount after the raw argument is read, so validating the raw
 *    argument would leave that path unguarded.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		/**
		 * Minimal WooCommerce gateway base for the gateway test double.
		 */
		class WC_Payment_Gateway {}
	}

	if ( ! class_exists( 'WC_Order', false ) ) {
		/**
		 * Minimal WooCommerce order base for the order test double.
		 *
		 * get_id() returns 123 (not 0) to match the shape every other guarded
		 * `WC_Order` stub in this suite uses (see PaymentGatewayDirectXssTest.php).
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
	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/exceptions/class-payment-gateway-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/api/interface-payment-gateway-api-response.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/handlers/capture.php';

	/**
	 * Order double carrying the real WC_Order meta storage shape
	 * (get_meta()/update_meta_data()/save_meta_data()) that
	 * Woodev_Payment_Gateway::get_order_meta()/update_order_meta() expect.
	 */
	class Woodev_Test_Capture_Bounds_Order extends WC_Order {

		/** @var int */
		private $id;

		/** @var float */
		private $total;

		/** @var array<string, mixed> */
		private $meta = [];

		/** @var stdClass capture context, matches production usage: $order->capture->amount */
		public $capture;

		/**
		 * @param int   $id order id
		 * @param float $total order total
		 */
		public function __construct( int $id, float $total ) {
			$this->id    = $id;
			$this->total = $total;
		}

		/** @return int */
		public function get_id(): int {
			return $this->id;
		}

		/** @return string */
		public function get_status() {
			return 'processing';
		}

		/** @return float */
		public function get_total() {
			return $this->total;
		}

		/** @return string */
		public function get_currency() {
			return 'USD';
		}

		/**
		 * @param string $note note text
		 * @return void
		 */
		public function add_order_note( $note ) {}

		/**
		 * @param string $transaction_id unused
		 * @return bool
		 */
		public function payment_complete( $transaction_id = '' ) {
			return true;
		}

		/**
		 * @param string $key meta key
		 * @param bool   $single unused
		 * @param string $context unused
		 * @return mixed
		 */
		public function get_meta( $key, $single = true, $context = 'view' ) {
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * @param string $key meta key
		 * @param mixed  $value meta value
		 * @return void
		 */
		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		/** @return void */
		public function save_meta_data() {}
	}

	/**
	 * Fake API whose credit_card_capture() always approves, and which counts how many
	 * times it was reached — the regression assertion for a rejected amount is that
	 * this stays at zero.
	 */
	class Woodev_Test_Capture_Bounds_Api {

		/** @var int */
		public $capture_called_count = 0;

		/**
		 * @param object $order order being captured
		 * @return object
		 */
		public function credit_card_capture( $order ) {
			++$this->capture_called_count;

			$response = \Mockery::mock( \Woodev_Payment_Gateway_API_Response::class );
			$response->shouldReceive( 'transaction_approved' )->andReturn( true );
			$response->shouldReceive( 'get_transaction_id' )->andReturn( '' );

			return $response;
		}
	}

	/**
	 * Exposes Woodev_Payment_Gateway_Capture_Handler's dependencies with inert collaborators.
	 */
	class Woodev_Testable_Payment_Gateway_Capture_Bounds extends \Woodev_Payment_Gateway {

		/** @var object */
		public $api;

		/**
		 * Switchable partial-capture support; both the "does this gateway support it"
		 * and "is it turned on" methods key off this single flag, since every test
		 * case in this suite that needs partial capture at all needs it fully on.
		 *
		 * @var bool
		 * @since 2.0.2
		 */
		public $partial_capture_enabled = false;

		/**
		 * Skips the real constructor (settings/hooks bootstrap); this test only needs
		 * the capture-related collaborator methods below.
		 *
		 * @since 2.0.2
		 */
		public function __construct() {}

		/**
		 * @return array
		 * @since 2.0.2
		 */
		protected function get_method_form_fields(): array {
			return [];
		}

		/**
		 * @return string
		 * @since 2.0.2
		 */
		public function get_id() {
			return 'test-capture-bounds-gw';
		}

		/**
		 * @return string
		 * @since 2.0.2
		 */
		public function get_method_title() {
			return 'Test Capture Bounds Gateway';
		}

		/**
		 * @return bool
		 * @since 2.0.2
		 */
		public function supports_credit_card_capture() {
			return true;
		}

		/**
		 * @return bool
		 * @since 2.0.2
		 */
		public function supports_credit_card_partial_capture() {
			return $this->partial_capture_enabled;
		}

		/**
		 * @return bool
		 * @since 2.0.2
		 */
		public function is_partial_capture_enabled() {
			return $this->partial_capture_enabled;
		}

		/**
		 * Keeps the constructor from auto-hooking maybe_capture_paid_order(), which is
		 * outside the scope of this test.
		 *
		 * @return bool
		 * @since 2.0.2
		 */
		public function is_paid_capture_enabled() {
			return false;
		}

		/**
		 * @return object
		 * @since 2.0.2
		 */
		public function get_api() {
			return $this->api;
		}

		/**
		 * Reimplements the real amount-resolution contract from
		 * Woodev_Payment_Gateway::get_order_for_capture() — a falsy `$amount` resolves
		 * to the remaining balance via the same `Woodev_Helper::number_format()` the
		 * production code uses — while skipping the site-name/description/filter
		 * machinery that is unrelated to the amount-bound guard under test (the same
		 * trade-off PaymentGatewayCaptureStockReductionLeakTest makes for its own,
		 * different, concern).
		 *
		 * @param object     $order order
		 * @param float|null $amount amount to capture; falsy means "capture the remaining balance"
		 * @return object
		 * @since 2.0.2
		 */
		public function get_order_for_capture( $order, $amount = null ) {

			$order->capture = new \stdClass();

			$total_captured = (float) $this->get_order_meta( $order, 'capture_total' );

			if ( ! $amount ) {
				$amount = $order->get_total() - $total_captured;
			}

			$order->capture->amount = \Woodev_Helper::number_format( $amount );

			return $order;
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway_Capture_Handler
	 */
	final class PaymentGatewayCaptureAmountBoundsTest extends TestCase {

		/** @var \Woodev_Testable_Payment_Gateway_Capture_Bounds */
		private $gateway;

		/** @var \Woodev_Payment_Gateway_Capture_Handler */
		private $handler;

		/** @var \Woodev_Test_Capture_Bounds_Api */
		private $api;

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'wc_price' )->justReturn( '$0.00' );
			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'remove_filter' )->justReturn( true );

			$this->api = new \Woodev_Test_Capture_Bounds_Api();

			$this->gateway      = new \Woodev_Testable_Payment_Gateway_Capture_Bounds();
			$this->gateway->api = $this->api;

			$this->handler = new \Woodev_Payment_Gateway_Capture_Handler( $this->gateway );
		}

		/**
		 * Builds an order that is otherwise fully eligible for capture (ready, not
		 * expired, not already captured), so every test below fails or succeeds solely
		 * on the amount-bound guard.
		 *
		 * @param float      $total order total
		 * @param string|null $authorization_amount explicit `authorization_amount` meta;
		 *        when null, get_order_capture_maximum() falls back to the order total
		 * @return \Woodev_Test_Capture_Bounds_Order
		 */
		private function make_capturable_order( float $total, ?string $authorization_amount = null ): \Woodev_Test_Capture_Bounds_Order {

			$order = new \Woodev_Test_Capture_Bounds_Order( 500, $total );

			$this->gateway->update_order_meta( $order, 'trans_id', 'TXN-BOUNDS' );

			if ( null !== $authorization_amount ) {
				$this->gateway->update_order_meta( $order, 'authorization_amount', $authorization_amount );
			}

			return $order;
		}

		/**
		 * @return void
		 */
		public function test_amount_above_the_capture_maximum_is_rejected(): void {

			$order = $this->make_capturable_order( 100.0 );

			$result = $this->handler->perform_capture( $order, 150.0 );

			$this->assertFalse( $result['success'] );
			$this->assertSame( 400, $result['code'] );
			$this->assertSame( 0, $this->api->capture_called_count, 'credit_card_capture() must never be reached for an over-the-maximum amount' );
		}

		/**
		 * @return void
		 */
		public function test_negative_amount_is_rejected(): void {

			$order = $this->make_capturable_order( 100.0 );

			$result = $this->handler->perform_capture( $order, -10.0 );

			$this->assertFalse( $result['success'] );
			$this->assertSame( 400, $result['code'] );
			$this->assertSame( 0, $this->api->capture_called_count, 'credit_card_capture() must never be reached for a negative amount' );
		}

		/**
		 * A null amount is the documented "capture the full remaining balance" request
		 * (resolved by get_order_for_capture(), not by this guard) — it must still
		 * succeed, i.e. the guard must bound the RESOLVED amount, not reject the raw
		 * `null` argument as if it were zero.
		 *
		 * @return void
		 */
		public function test_null_amount_still_resolves_to_the_order_total_and_succeeds(): void {

			$order = $this->make_capturable_order( 100.0 );

			$result = $this->handler->perform_capture( $order );

			$this->assertTrue( $result['success'] );
			$this->assertSame( 1, $this->api->capture_called_count );
			$this->assertSame( '100.00', $order->capture->amount );
		}

		/**
		 * A literal 0 is, by the same falsy-amount contract as null, also "capture the
		 * full remaining balance" — it must resolve and succeed, not be rejected as an
		 * invalid zero amount.
		 *
		 * @return void
		 */
		public function test_zero_amount_resolves_to_the_remaining_balance_and_succeeds(): void {

			$order = $this->make_capturable_order( 100.0 );

			$result = $this->handler->perform_capture( $order, 0 );

			$this->assertTrue( $result['success'] );
			$this->assertSame( 1, $this->api->capture_called_count );
			$this->assertSame( '100.00', $order->capture->amount );
		}

		/**
		 * The boundary itself: an amount exactly equal to the maximum must still be
		 * captured, not rejected by an off-by-one `>=` guard.
		 *
		 * @return void
		 */
		public function test_amount_exactly_at_the_capture_maximum_succeeds(): void {

			$order = $this->make_capturable_order( 100.0 );

			$result = $this->handler->perform_capture( $order, 100.0 );

			$this->assertTrue( $result['success'] );
			$this->assertSame( 1, $this->api->capture_called_count );
		}

		/**
		 * get_order_capture_maximum() delegates to get_order_authorization_amount(),
		 * which a gateway may override to authorize more (or less) than the order
		 * total. The bound must be checked against that value, never against
		 * $order->get_total() directly.
		 *
		 * @return void
		 */
		public function test_bound_uses_the_capture_maximum_not_the_order_total(): void {

			$order = $this->make_capturable_order( 200.0, '80.00' );

			$result = $this->handler->perform_capture( $order, 90.0 );

			$this->assertFalse( $result['success'] );
			$this->assertSame( 400, $result['code'] );
			$this->assertSame( 0, $this->api->capture_called_count, 'an amount below the order total but above the authorization amount must still be rejected' );
		}

		/**
		 * Regression guard for #781 round 2: `capture_total` accumulates across
		 * requests, so a bound checked only against the full maximum lets two
		 * sequential partial captures each pass individually while together
		 * exceeding the authorization (measured: 60 then 50 against a 100
		 * authorization reached the gateway both times and left capture_total at
		 * 110). The bound must be checked against what remains of the
		 * authorization, so the second call here must be rejected before it ever
		 * reaches credit_card_capture(), and capture_total must stay at the first
		 * capture's value.
		 *
		 * @return void
		 */
		public function test_second_partial_capture_exceeding_remaining_capacity_is_rejected(): void {

			$this->gateway->partial_capture_enabled = true;

			$order = $this->make_capturable_order( 100.0 );

			$first = $this->handler->perform_capture( $order, 60.0 );

			$this->assertTrue( $first['success'] );
			$this->assertSame( 1, $this->api->capture_called_count );
			$this->assertSame( '60.00', $this->gateway->get_order_meta( $order, 'capture_total' ) );

			$second = $this->handler->perform_capture( $order, 50.0 );

			$this->assertFalse( $second['success'] );
			$this->assertSame( 400, $second['code'] );
			$this->assertSame( 1, $this->api->capture_called_count, 'credit_card_capture() must not be reached a second time' );
			$this->assertSame( '60.00', $this->gateway->get_order_meta( $order, 'capture_total' ), 'capture_total must stay at the first capture\'s value' );
		}

		/**
		 * The companion boundary case: a second partial capture that fits exactly in
		 * what remains of the authorization must still succeed — without this case,
		 * a fix that simply refuses every second capture would also pass
		 * test_second_partial_capture_exceeding_remaining_capacity_is_rejected().
		 *
		 * @return void
		 */
		public function test_second_partial_capture_within_remaining_capacity_succeeds(): void {

			$this->gateway->partial_capture_enabled = true;

			$order = $this->make_capturable_order( 100.0 );

			$first = $this->handler->perform_capture( $order, 60.0 );

			$this->assertTrue( $first['success'] );

			$second = $this->handler->perform_capture( $order, 40.0 );

			$this->assertTrue( $second['success'] );
			$this->assertSame( 2, $this->api->capture_called_count );
			$this->assertSame( '100.00', $this->gateway->get_order_meta( $order, 'capture_total' ) );
		}

		/**
		 * Decision recorded for #781 round 2: with a partial `authorization_amount`
		 * (below the order total), a bulk capture (`$amount = null`) resolves via
		 * `get_order_for_capture()` to the remaining order balance, which is above
		 * what remains of the authorization. Before this card that request reached
		 * the gateway and was refused there; this guard now refuses it locally
		 * instead, with a message naming the amount actually still capturable —
		 * capturing more than was authorized cannot succeed anyway, and a local
		 * refusal carries a clearer message than a round trip to the gateway.
		 *
		 * @return void
		 */
		public function test_bulk_capture_on_a_partially_authorized_order_is_rejected_locally(): void {

			$order = $this->make_capturable_order( 200.0, '80.00' );

			$result = $this->handler->perform_capture( $order );

			$this->assertFalse( $result['success'] );
			$this->assertSame( 400, $result['code'] );
			$this->assertSame( 0, $this->api->capture_called_count, 'a bulk capture that would exceed the authorization must never reach the gateway' );
		}
	}
}

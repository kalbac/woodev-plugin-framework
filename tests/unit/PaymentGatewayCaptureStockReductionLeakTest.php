<?php
/**
 * Verifies #401: Woodev_Payment_Gateway_Capture_Handler::perform_capture() no longer
 * leaks the `woocommerce_payment_complete_reduce_order_stock` suppression filter past
 * its own payment_complete() call, so a later, unrelated payment_complete() in the same
 * request (e.g. the "Capture charge" bulk order action) reduces stock normally.
 *
 * Brain Monkey's apply_filters() does not itself invoke callbacks registered via
 * add_filter() — it only records that they were "added". To prove the filter is
 * actually removed (not just that remove_filter() was called with plausible-looking
 * arguments), this test stubs add_filter()/remove_filter() with a tiny in-memory
 * registry and fires the captured callback itself, the same way WooCommerce would.
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
		 * Because this class is declared behind a `class_exists()` guard in the
		 * global namespace, whichever test file's version PHPUnit loads first for
		 * the whole run "wins" for every other test file too — an id of 0 fails
		 * `$order_id > 0` checks (e.g. Woodev_Order_Compatibility::update_order_meta())
		 * in unrelated tests that construct a bare `new WC_Order()`.
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

	if ( ! class_exists( 'Woodev_Test_Fake_Hook_Registry', false ) ) {
		/**
		 * A minimal in-memory stand-in for WordPress's filter storage, used to prove
		 * add_filter()/remove_filter() calls actually take effect rather than merely
		 * asserting they were called with plausible-looking arguments.
		 */
		class Woodev_Test_Fake_Hook_Registry {

			/** @var array<string, array<int, array{0: int, 1: callable}>> */
			private $filters = [];

			/**
			 * @param string   $hook hook name
			 * @param callable $callback callback
			 * @param int      $priority priority
			 * @return true
			 */
			public function add( $hook, $callback, $priority = 10 ) {
				$this->filters[ $hook ][] = [ $priority, $callback ];

				return true;
			}

			/**
			 * @param string   $hook hook name
			 * @param callable $callback callback
			 * @param int      $priority priority
			 * @return bool
			 */
			public function remove( $hook, $callback, $priority = 10 ) {
				if ( ! isset( $this->filters[ $hook ] ) ) {
					return false;
				}

				foreach ( $this->filters[ $hook ] as $i => $entry ) {
					if ( $priority === $entry[0] && $callback === $entry[1] ) {
						unset( $this->filters[ $hook ][ $i ] );

						return true;
					}
				}

				return false;
			}

			/**
			 * Fires every callback still registered for the hook, in order, the same
			 * way WordPress's apply_filters() would.
			 *
			 * @param string $hook hook name
			 * @param mixed  ...$args value followed by any extra filter args
			 * @return mixed
			 */
			public function apply( $hook, ...$args ) {
				$value = array_shift( $args );

				foreach ( $this->filters[ $hook ] ?? [] as $entry ) {
					$value = call_user_func( $entry[1], $value, ...$args );
				}

				return $value;
			}

			/**
			 * @param string $hook hook name
			 * @return bool
			 */
			public function has_any( $hook ) {
				return ! empty( $this->filters[ $hook ] );
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
	 * Order double whose payment_complete() fires the
	 * `woocommerce_payment_complete_reduce_order_stock` filter through the fake hook
	 * registry, exactly like WC_Order::payment_complete() does, and records the result.
	 */
	class Woodev_Test_Capture_Order extends WC_Order {

		/** @var int */
		private $id;

		/** @var float */
		private $total;

		/** @var array<string, mixed> */
		private $meta = [];

		/** @var Woodev_Test_Fake_Hook_Registry */
		private $hooks;

		/** @var bool */
		private $throws;

		/** @var stdClass capture context, matches production usage: $order->capture->amount */
		public $capture;

		/** @var bool[] each payment_complete() call's resolved stock-reduction flag */
		public $stock_reduction_results = [];

		/**
		 * @param int                            $id order id
		 * @param float                          $total order total
		 * @param Woodev_Test_Fake_Hook_Registry $hooks shared fake filter registry
		 * @param bool                           $throws whether payment_complete() should throw
		 */
		public function __construct( int $id, float $total, Woodev_Test_Fake_Hook_Registry $hooks, bool $throws = false ) {
			$this->id      = $id;
			$this->total   = $total;
			$this->hooks   = $hooks;
			$this->throws  = $throws;
			$this->capture = (object) [ 'amount' => $total ];
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

		/**
		 * @param string $transaction_id unused
		 * @return bool
		 */
		public function payment_complete( $transaction_id = '' ) {

			if ( $this->throws ) {
				throw new \RuntimeException( 'simulated payment_complete() failure' );
			}

			$reduce                          = $this->hooks->apply( 'woocommerce_payment_complete_reduce_order_stock', true, $this->get_id() );
			$this->stock_reduction_results[] = (bool) $reduce;

			return true;
		}
	}

	/**
	 * Fake API whose credit_card_capture() always approves.
	 */
	class Woodev_Test_Capture_Api {

		/**
		 * @param object $order order being captured
		 * @return object
		 */
		public function credit_card_capture( $order ) {
			$response = \Mockery::mock( \Woodev_Payment_Gateway_API_Response::class );
			$response->shouldReceive( 'transaction_approved' )->andReturn( true );
			$response->shouldReceive( 'get_transaction_id' )->andReturn( '' );

			return $response;
		}
	}

	/**
	 * Exposes Woodev_Payment_Gateway_Capture_Handler's dependencies with inert collaborators.
	 */
	class Woodev_Testable_Payment_Gateway_Capture extends \Woodev_Payment_Gateway {

		/** @var object */
		public $api;

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
			return 'test-capture-gw';
		}

		/**
		 * @return string
		 * @since 2.0.2
		 */
		public function get_method_title() {
			return 'Test Capture Gateway';
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
			return false;
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
		 * Bypasses the real get_order_for_capture() pipeline (site-name/description
		 * building via wp_specialchars_decode() etc.), which is unrelated to the
		 * stock-reduction filter leak under test here.
		 *
		 * @param object     $order order
		 * @param float|null $amount unused
		 * @return object
		 * @since 2.0.2
		 */
		public function get_order_for_capture( $order, $amount = null ) {
			$order->capture = (object) [ 'amount' => $amount ?? $order->get_total() ];

			return $order;
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway_Capture_Handler
	 */
	final class PaymentGatewayCaptureStockReductionLeakTest extends TestCase {

		/** @var \Woodev_Test_Fake_Hook_Registry */
		private $hooks;

		/** @var \Woodev_Testable_Payment_Gateway_Capture */
		private $gateway;

		/** @var \Woodev_Payment_Gateway_Capture_Handler */
		private $handler;

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			$this->hooks = new \Woodev_Test_Fake_Hook_Registry();

			Functions\when( 'wc_price' )->justReturn( '$0.00' );

			Functions\when( 'add_filter' )
				->alias(
					function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
						return $this->hooks->add( $hook, $callback, $priority );
					}
				);

			Functions\when( 'remove_filter' )
				->alias(
					function ( $hook, $callback, $priority = 10 ) {
						return $this->hooks->remove( $hook, $callback, $priority );
					}
				);

			$this->gateway      = new \Woodev_Testable_Payment_Gateway_Capture();
			$this->gateway->api = new \Woodev_Test_Capture_Api();
			$this->handler       = new \Woodev_Payment_Gateway_Capture_Handler( $this->gateway );
		}

		/**
		 * Reproduces the "Capture charge" bulk order action: the first fully-captured
		 * order must have ITS OWN stock reduction suppressed (correct, intentional
		 * behavior — stock was already reduced at authorization), but a later,
		 * unrelated payment_complete() in the same request must NOT be affected.
		 *
		 * @return void
		 */
		public function test_stock_reduction_filter_does_not_leak_to_a_later_order_in_the_same_request(): void {
			$order_a = new \Woodev_Test_Capture_Order( 100, 100.0, $this->hooks );
			$this->gateway->update_order_meta( $order_a, 'trans_id', 'TXN-A' );

			$result_a = $this->handler->perform_capture( $order_a );

			$this->assertTrue( $result_a['success'] );
			$this->assertSame(
				[ false ],
				$order_a->stock_reduction_results,
				'the captured order\'s own stock reduction must be suppressed (it was already reduced at authorization)'
			);

			// a later, unrelated order completing payment in the SAME request (e.g. another
			// listener on woocommerce_order_status_changed) must reduce stock normally
			$order_b = new \Woodev_Test_Capture_Order( 200, 50.0, $this->hooks );
			$order_b->payment_complete();

			$this->assertSame(
				[ true ],
				$order_b->stock_reduction_results,
				'an unrelated order completing payment afterwards must not inherit the suppression installed for order #100'
			);
		}

		/**
		 * The filter must be removed even when payment_complete() itself throws, so a
		 * failure while capturing one order in a bulk action does not leak suppression
		 * into every order processed afterwards.
		 *
		 * @return void
		 */
		public function test_filter_is_removed_even_when_payment_complete_throws(): void {
			$order_a = new \Woodev_Test_Capture_Order( 100, 100.0, $this->hooks, true );
			$this->gateway->update_order_meta( $order_a, 'trans_id', 'TXN-A' );

			try {
				$this->handler->perform_capture( $order_a );
				$this->fail( 'Expected the simulated payment_complete() failure to propagate' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'simulated payment_complete() failure', $exception->getMessage() );
			}

			$order_b = new \Woodev_Test_Capture_Order( 200, 50.0, $this->hooks );
			$order_b->payment_complete();

			$this->assertSame(
				[ true ],
				$order_b->stock_reduction_results,
				'the filter must be removed via finally even though payment_complete() threw'
			);
		}
	}
}

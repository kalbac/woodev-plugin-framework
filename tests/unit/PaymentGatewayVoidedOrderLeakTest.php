<?php
/**
 * Verifies #392: mark_order_as_voided() / maybe_cancel_voided_order() no longer leak
 * across orders processed within the same request (e.g. a bulk refund action, WP-CLI,
 * or a REST/webhook run handling several orders).
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

	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';

	/**
	 * Exposes the void/refund-status hooks under test with inert collaborators.
	 */
	class Woodev_Testable_Payment_Gateway_Voided_Leak extends \Woodev_Payment_Gateway {

		/**
		 * Skips the real constructor (settings/hooks bootstrap); this test only needs
		 * the void/refund-status methods.
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
		public function get_method_title() {
			return 'Test Gateway';
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway
	 */
	final class PaymentGatewayVoidedOrderLeakTest extends TestCase {

		/** @var array<int, object> order id => order double, resolved by wc_get_order() */
		private $orders = [];

		/** @var \Woodev_Test_Fake_Hook_Registry */
		private $hooks;

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			$this->orders = [];
			$this->hooks  = new \Woodev_Test_Fake_Hook_Registry();

			Functions\when( 'wc_price' )->justReturn( '$10.00' );

			Functions\when( 'wc_get_order' )
				->alias(
					function ( $order_id ) {
						return $this->orders[ $order_id ] ?? null;
					}
				);

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
		}

		/**
		 * Builds an order double. Voided orders are never `cancelled` already, so
		 * `has_status( 'cancelled' )` must answer false for mark_order_as_voided() to
		 * install the filter.
		 *
		 * @param int $id order id
		 * @return \Mockery\MockInterface
		 */
		private function make_order( int $id ) {
			$order = \Mockery::mock();
			$order->shouldReceive( 'get_id' )->andReturn( $id );
			$order->shouldReceive( 'has_status' )->with( 'cancelled' )->andReturn( false );
			$order->shouldReceive( 'get_currency' )->andReturn( 'USD' );
			$order->refund = (object) [ 'amount' => 12.34 ];

			$this->orders[ $id ] = $order;

			return $order;
		}

		/**
		 * Reproduces the bulk-action scenario from the bug report: void order #100,
		 * then fully refund an unrelated order #200 in the SAME request (simulated by
		 * firing the `woocommerce_order_fully_refunded_status` filter WordPress-style,
		 * through the fake registry, rather than calling the callback directly).
		 * Order #200 must keep its own status and must never receive order #100's
		 * void note.
		 *
		 * @return void
		 */
		public function test_maybe_cancel_voided_order_does_not_act_on_a_different_order_in_the_same_request(): void {
			$order_100 = $this->make_order( 100 );
			$order_100->shouldReceive( 'add_order_note' )->once();

			$order_200 = $this->make_order( 200 );
			$order_200->shouldReceive( 'add_order_note' )->never();

			$response_100 = \Mockery::mock();
			$response_100->shouldReceive( 'get_transaction_id' )->andReturn( false );

			$gateway = new \Woodev_Testable_Payment_Gateway_Voided_Leak();

			// void order #100 — installs the woocommerce_order_fully_refunded_status filter
			$gateway->mark_order_as_voided( $order_100, $response_100 );

			// simulate WC firing the filter for order #200 being fully refunded, in the same request
			$result_for_200 = $this->hooks->apply( 'woocommerce_order_fully_refunded_status', 'refunded', 200 );

			$this->assertSame( 'refunded', $result_for_200, 'order #200 must keep its own fully-refunded status, not be forced to cancelled' );

			// order #100's own fully-refunded event must still be handled correctly afterwards
			$result_for_100 = $this->hooks->apply( 'woocommerce_order_fully_refunded_status', 'refunded', 100 );

			$this->assertSame( 'cancelled', $result_for_100, 'order #100 must still be cancelled when its own fully-refunded event fires' );
		}

		/**
		 * Once every order the filter was installed for has been resolved, the filter
		 * must be removed so it cannot affect a later, wholly unrelated refund event
		 * (e.g. a third order refunded further down the same bulk action, with no void
		 * involved at all).
		 *
		 * @return void
		 */
		public function test_filter_is_removed_after_the_voided_order_is_resolved(): void {
			$order_100 = $this->make_order( 100 );
			$order_100->shouldReceive( 'add_order_note' )->once();

			$response_100 = \Mockery::mock();
			$response_100->shouldReceive( 'get_transaction_id' )->andReturn( false );

			$gateway = new \Woodev_Testable_Payment_Gateway_Voided_Leak();

			$gateway->mark_order_as_voided( $order_100, $response_100 );
			$this->hooks->apply( 'woocommerce_order_fully_refunded_status', 'refunded', 100 );

			$this->assertFalse(
				$this->hooks->has_any( 'woocommerce_order_fully_refunded_status' ),
				'the filter must be removed once its order was resolved, so a later, unrelated refund in the same request is left untouched'
			);
		}
	}
}

<?php
/**
 * Verifies #398: Woodev_Payment_Gateway::add_transaction_data() writes the core order
 * transaction id through the HPOS-safe WC_Order setter, never update_post_meta(), which
 * bypasses the orders table when HPOS (custom order tables) is enabled.
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
		 * Minimal WooCommerce order base for the gateway test double.
		 */
		class WC_Order {

			/**
			 * @return int
			 */
			public function get_id(): int {
				return 0;
			}
		}
	}

	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';

	/**
	 * Order double that records core-prop writes (set_transaction_id()/save()) and
	 * meta writes (update_meta_data()) separately, so the test can prove the
	 * transaction id goes through the WC_Order setter rather than update_post_meta().
	 */
	class Woodev_Test_Transaction_Data_Order extends WC_Order {

		/** @var array<string, mixed> */
		private $meta = [];

		/** @var string|null */
		private $transaction_id;

		/** @var int */
		private $save_calls = 0;

		/**
		 * @return int
		 */
		public function get_id(): int {
			return 555;
		}

		/**
		 * @param string $key meta key
		 * @param mixed  $value meta value
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		/**
		 * @return void
		 */
		public function save_meta_data(): void {}

		/**
		 * @param string $value transaction id
		 * @return void
		 */
		public function set_transaction_id( $value ): void {
			$this->transaction_id = $value;
		}

		/**
		 * @return string
		 */
		public function get_transaction_id(): string {
			return (string) $this->transaction_id;
		}

		/**
		 * @return void
		 */
		public function save(): void {
			++$this->save_calls;
		}

		/**
		 * @return int
		 */
		public function get_save_call_count(): int {
			return $this->save_calls;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_meta_writes(): array {
			return $this->meta;
		}
	}

	/**
	 * Exposes add_transaction_data() under test with inert collaborators.
	 */
	class Woodev_Testable_Payment_Gateway_Transaction_Data extends \Woodev_Payment_Gateway {

		/**
		 * Skips the real constructor (settings/hooks bootstrap); this test only needs
		 * add_transaction_data().
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
			return 'test-gateway';
		}

		/**
		 * @return array
		 * @since 2.0.2
		 */
		public function get_environments() {
			return [ 'production' => 'Production' ];
		}

		/**
		 * @return bool
		 * @since 2.0.2
		 */
		public function supports_customer_id() {
			return false;
		}

		/**
		 * @return bool
		 * @since 2.0.2
		 */
		public function is_credit_card_gateway() {
			return false;
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * @covers \Woodev_Payment_Gateway
	 */
	final class PaymentGatewayTransactionIdHposTest extends TestCase {

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'current_time' )->justReturn( '2026-08-21 00:00:00' );
			Functions\when( 'do_action' )->justReturn( true );

			// update_post_meta() bypasses the WC_Order object entirely, so it is never
			// stubbed to succeed here — it must not be called at all.
			Functions\expect( 'update_post_meta' )->never();
		}

		/**
		 * @return void
		 */
		public function test_add_transaction_data_uses_the_core_setter_and_saves_the_order(): void {
			$order    = new \Woodev_Test_Transaction_Data_Order();
			$response = \Mockery::mock();
			$response->shouldReceive( 'get_transaction_id' )->andReturn( 'TXN-42' );

			$gateway = new \Woodev_Testable_Payment_Gateway_Transaction_Data();
			$gateway->add_transaction_data( $order, $response );

			$this->assertSame(
				'TXN-42',
				$order->get_transaction_id(),
				'add_transaction_data() must call $order->set_transaction_id() so get_transaction_id() reflects it under HPOS'
			);
			$this->assertGreaterThan(
				0,
				$order->get_save_call_count(),
				'the order must be saved so the HPOS-backed transaction id is actually persisted'
			);
			// the framework's own trans_id meta must still be written (data contract, unchanged)
			$this->assertSame( 'TXN-42', $order->get_meta_writes()['_wc_test-gateway_trans_id'] ?? null );
		}

		/**
		 * When there is no transaction id to record, neither the setter nor the meta
		 * write should be touched.
		 *
		 * @return void
		 */
		public function test_add_transaction_data_skips_transaction_id_when_response_has_none(): void {
			$order    = new \Woodev_Test_Transaction_Data_Order();
			$response = \Mockery::mock();
			$response->shouldReceive( 'get_transaction_id' )->andReturn( '' );

			$gateway = new \Woodev_Testable_Payment_Gateway_Transaction_Data();
			$gateway->add_transaction_data( $order, $response );

			$this->assertSame( '', $order->get_transaction_id() );
			$this->assertSame( 0, $order->get_save_call_count() );
		}
	}
}

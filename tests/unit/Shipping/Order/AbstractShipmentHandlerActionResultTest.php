<?php
/**
 * Unit: the {@see \Woodev\Framework\Shipping\Order\Action_Result} that
 * `Abstract_Shipment_Handler::export()` / `cancel()` return (card #872).
 *
 * Pins two things:
 *  1. a carrier failure's text rides on the result (secrets redacted), and a response
 *     with no id is a failure with NO text (#860);
 *  2. the carrier's text is for the MERCHANT only (#608/#610) — the handler never
 *     writes it to the order (meta, note, customer-visible or not), and nothing
 *     outside the two merchant surfaces even names {@see Action_Result}.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/api/interface-shipping-api.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-action-result.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-async-request.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/utilities/class-woodev-background-job-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/abstract-shipment-handler.php';

	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

	if ( ! class_exists( 'ActionResult_Test_Shipment_Handler' ) ) {
		/**
		 * Minimal concrete subclass; the extractor hands back a test-controlled id.
		 */
		class ActionResult_Test_Shipment_Handler extends Abstract_Shipment_Handler {

			/** @var string what extract_carrier_order_id() returns */
			public string $next_carrier_order_id = 'CARRIER-1';

			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return $this->next_carrier_order_id;
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Mockery;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Framework\Shipping\Order\Action_Result;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Action_Result
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export
	 * @covers \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::cancel
	 */
	final class AbstractShipmentHandlerActionResultTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Location_Provider_Registry::instance()->reset_for_tests();
		}

		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * @param mixed $api           a Shipping_API mock
		 * @param mixed $order_handler a Shipping_Order_Handler mock
		 * @param mixed $retry_handler a Woodev_Background_Job_Handler mock
		 */
		private function handler( $api, $order_handler, $retry_handler = null ): \ActionResult_Test_Shipment_Handler {
			$retry_handler = $retry_handler ?? Mockery::mock( '\Woodev_Background_Job_Handler' );

			return new \ActionResult_Test_Shipment_Handler(
				$api,
				$order_handler,
				$retry_handler,
				'test',
				Mockery::mock( Popular_Settlement_Store::class )
			);
		}

		private function failing_api( string $method, string $text ) {
			$api = Mockery::mock( '\Woodev\Framework\Shipping\Api\Shipping_API' );
			$api->shouldReceive( $method )->andThrow( new \Woodev_API_Exception( $text ) );

			return $api;
		}

		// ----- the value object -----

		public function test_success_and_failure_carry_their_parts(): void {
			$ok = Action_Result::success( 'CARRIER-1', 'Принято' );

			$this->assertTrue( $ok->is_success() );
			$this->assertSame( 'CARRIER-1', $ok->get_carrier_order_id() );
			$this->assertSame( 'Принято', $ok->get_message() );

			$failed = Action_Result::failure( 'Неверный индекс получателя' );

			$this->assertFalse( $failed->is_success() );
			$this->assertSame( '', $failed->get_carrier_order_id() );
			$this->assertSame( 'Неверный индекс получателя', $failed->get_message() );
		}

		public function test_merchant_message_prefixes_the_carrier_name_and_falls_back_when_there_is_no_text(): void {
			$this->assertSame(
				'СДЭК: Неверный индекс получателя',
				Action_Result::failure( 'Неверный индекс получателя' )->merchant_message( 'СДЭК', 'generic' )
			);
			$this->assertSame( 'generic', Action_Result::failure()->merchant_message( 'СДЭК', 'generic' ) );
			$this->assertSame( 'generic', Action_Result::failure( "  \n" )->merchant_message( 'СДЭК', 'generic' ), 'whitespace is no text' );
			$this->assertSame( 'Только текст', Action_Result::failure( 'Только текст' )->merchant_message( '', 'generic' ) );
		}

		// ----- the handler carries the carrier's text -----

		public function test_a_failed_export_carries_the_carriers_text_and_is_still_queued_for_retry(): void {
			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldNotReceive( 'set' );

			$retry_handler = Mockery::mock( '\Woodev_Background_Job_Handler' );
			$retry_handler->shouldReceive( 'create_job' )->once();
			$retry_handler->shouldReceive( 'dispatch' )->once();

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 55 );

			$result = $this->handler( $this->failing_api( 'create_order', 'Неверный индекс получателя' ), $order_handler, $retry_handler )
				->export( $order );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'Неверный индекс получателя', $result->get_message() );
		}

		public function test_a_failed_cancel_carries_the_carriers_text(): void {
			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldNotReceive( 'set' );

			$result = $this->handler( $this->failing_api( 'cancel_order', 'Заказ уже передан курьеру' ), $order_handler )
				->cancel( Mockery::mock( '\WC_Order' ) );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'Заказ уже передан курьеру', $result->get_message() );
		}

		public function test_a_secret_echoed_in_the_carriers_text_is_redacted(): void {
			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );

			$result = $this->handler( $this->failing_api( 'cancel_order', 'rejected api_key=LIVESECRET' ), $order_handler )
				->cancel( Mockery::mock( '\WC_Order' ) );

			$this->assertStringNotContainsString( 'LIVESECRET', $result->get_message() );
		}

		// ----- merchant-only: the buyer never receives the carrier's text -----

		/**
		 * A failed export writes NOTHING to the order — no meta through the order handler, no
		 * order note (customer-visible or private), no direct meta write. So the carrier's
		 * wording cannot reach an email, «Мой аккаунт» or the order-received page (#608/#610).
		 */
		public function test_a_failed_export_writes_nothing_to_the_order(): void {
			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldNotReceive( 'set' );

			$retry_handler = Mockery::mock( '\Woodev_Background_Job_Handler' );
			$retry_handler->shouldReceive( 'create_job' )->once();
			$retry_handler->shouldReceive( 'dispatch' )->once();

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 55 );
			$order->shouldNotReceive( 'add_order_note' );
			$order->shouldNotReceive( 'update_meta_data' );
			$order->shouldNotReceive( 'set_customer_note' );
			$order->shouldNotReceive( 'save' );

			$this->handler( $this->failing_api( 'create_order', 'Неверный индекс получателя' ), $order_handler, $retry_handler )
				->export( $order );

			$this->addToAssertionCount( 1 ); // the shouldNotReceive() expectations are the assertion.
		}

		public function test_a_failed_cancel_writes_nothing_to_the_order(): void {
			$order_handler = Mockery::mock( Shipping_Order_Handler::class );
			$order_handler->shouldReceive( 'get' )->andReturn( 'CARRIER-1' );
			$order_handler->shouldNotReceive( 'set' );

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldNotReceive( 'add_order_note' );
			$order->shouldNotReceive( 'update_meta_data' );
			$order->shouldNotReceive( 'set_customer_note' );

			$this->handler( $this->failing_api( 'cancel_order', 'Заказ уже передан курьеру' ), $order_handler )
				->cancel( $order );

			$this->addToAssertionCount( 1 );
		}

		/**
		 * Structural pin: `Action_Result` is a MERCHANT-surface type. Only the handler that
		 * builds it, the two surfaces that show it (the REST orders route and the
		 * order-edit metabox) and `Order_Actions` — the admin-only performer both the orders
		 * page and the order wizard's immediate export (#974) go through — may name it; a
		 * storefront / checkout / email / tracking file that starts to would be a path for the
		 * carrier's text to a buyer.
		 */
		public function test_only_the_handler_and_the_merchant_surfaces_name_action_result(): void {
			$root    = dirname( __DIR__, 4 ) . '/woodev';
			$allowed = [
				'shipping-method/order/class-action-result.php',
				'shipping-method/order/abstract-shipment-handler.php',
				'shipping-method/rest-api/class-orders-controller.php',
				'shipping-method/admin/class-shipping-admin-order.php',
				'shipping-method/admin/orders/class-order-actions.php',
			];

			$found = [];

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), 'Action_Result' ) ) {
					$found[] = ltrim( str_replace( [ $root, '\\' ], [ '', '/' ], $file->getPathname() ), '/' );
				}
			}

			// The class map lists the FQCN — it is a loader index, not a consumer.
			$found = array_values( array_diff( $found, [ 'class-map.php' ] ) );
			sort( $found );
			sort( $allowed );

			$this->assertSame( $allowed, $found );
		}
	}
}

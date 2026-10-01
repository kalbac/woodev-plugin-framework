<?php
/**
 * Integration: the delayed-export-retry hook is bound ONCE, however many carrier plugins are loaded (card #1009).
 *
 * `Shipping_Plugin::add_hooks()` wires `Orders_Registry::run_export_retry()` to {@see Export_Retry::HOOK}
 * in EVERY request of a shipping plugin (#954), and `Orders_Registry` is a process-wide singleton, so two
 * carrier plugins must end up with exactly one callback — otherwise every due retry would run twice and
 * export the order twice. `add_action()` ignores a second identical callback, which is what this relies on;
 * nothing pinned it, because the other integration tests re-bind the hook themselves through
 * `register_provider()` after `reset_for_tests()`.
 *
 * Two tests, two halves of the claim:
 *
 *  1. the AMBIENT state — the two real fixture plugins the suite bootstrap loads
 *     (`Woodev_Test_Shipping_Method_Plugin`, `Woodev_Realistic_Shipping_Plugin`), each through its real
 *     constructor and so its real `add_hooks()`. Nothing in this test touches the registry first;
 *  2. the hook bound by `Shipping_Plugin::add_hooks()` ALONE. The fixtures also call `register_provider()`,
 *     and the registry's own wiring binds the same callback, so the first test cannot tell the two
 *     bindings apart. Here the hook is removed, two plugins that register NO carrier run the real
 *     (private) `add_hooks()`, and the hook must be back exactly once — then a retry payload fired at it
 *     reaches the export of the right carrier and of that carrier only.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping {

	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Api\Shipping_API;
	use Woodev\Framework\Shipping\Order\Export_Retry;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Integration\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_hooks
	 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::run_export_retry
	 */
	class ExportRetryHookBoundOnceTest extends TestCase {

		private const MARKER_A = '_woodev_once_a_marker';
		private const MARKER_B = '_woodev_once_b_marker';

		private const CARRIER_ID_A = '_woodev_once_a_carrier_order_id';
		private const CARRIER_ID_B = '_woodev_once_b_carrier_order_id';

		protected function tearDown(): void {
			Orders_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * Every callback bound to the retry hook, at any priority.
		 *
		 * @return array<int,mixed> the callables.
		 */
		private function retry_callbacks(): array {
			global $wp_filter;

			if ( ! isset( $wp_filter[ Export_Retry::HOOK ] ) ) {
				return [];
			}

			$callbacks = [];

			foreach ( $wp_filter[ Export_Retry::HOOK ]->callbacks as $at_priority ) {
				foreach ( $at_priority as $registered ) {
					$callbacks[] = $registered['function'];
				}
			}

			return $callbacks;
		}

		private function assert_bound_once_to_the_registry(): void {
			$callbacks = $this->retry_callbacks();

			$this->assertCount( 1, $callbacks, 'the retry hook has exactly one callback, not one per carrier plugin' );
			$this->assertSame( [ Orders_Registry::instance(), 'run_export_retry' ], $callbacks[0] );
		}

		/**
		 * The two real carrier plugins of the suite, through their real constructors.
		 *
		 * @return void
		 */
		public function test_two_loaded_carrier_plugins_leave_one_callback_on_the_retry_hook(): void {
			$this->assertInstanceOf( Shipping_Plugin::class, \Woodev_Test_Shipping_Method_Plugin::instance() );
			$this->assertInstanceOf( Shipping_Plugin::class, \Woodev_Realistic_Shipping_Plugin::instance() );
			$this->assertNotSame(
				\Woodev_Test_Shipping_Method_Plugin::instance(),
				\Woodev_Realistic_Shipping_Plugin::instance(),
				'two distinct plugins, or this test proves nothing about several of them'
			);

			$this->assert_bound_once_to_the_registry();
		}

		/**
		 * `Shipping_Plugin::add_hooks()` on its own binds the hook once for any number of plugins, and the
		 * action then reaches the export of the carrier that owns the order.
		 *
		 * @return void
		 */
		public function test_add_hooks_of_two_plugins_binds_the_hook_once_and_a_retry_reaches_the_right_carrier(): void {
			// A process that has not bound the hook yet: drop every trace of it, registry's own wiring included.
			$registry = Orders_Registry::instance();
			$registry->reset_for_tests();
			$this->assertSame( [], $this->retry_callbacks(), 'the starting point: nobody listens' );

			foreach ( [ new \Woodev_Once_Bare_Plugin_A(), new \Woodev_Once_Bare_Plugin_B() ] as $plugin ) {
				$add_hooks = new \ReflectionMethod( Shipping_Plugin::class, 'add_hooks' );
				if ( PHP_VERSION_ID < 80100 ) {
					$add_hooks->setAccessible( true );
				}
				$add_hooks->invoke( $plugin );
			}

			// Neither plugin registered a carrier, so this is the work of Shipping_Plugin::add_hooks() alone.
			$this->assert_bound_once_to_the_registry();

			// Two carriers, each with its own handler and its own (counting) carrier API.
			$api_a = new \Woodev_Once_Fake_Api();
			$api_b = new \Woodev_Once_Fake_Api();

			foreach ( [
				'once_a' => [ self::MARKER_A, self::CARRIER_ID_A, $api_a ],
				'once_b' => [ self::MARKER_B, self::CARRIER_ID_B, $api_b ],
			] as $id => [ $marker, $carrier_id_meta, $api ] ) {
				$registry->register_provider(
					Orders_Provider::create( $id, $id, $marker, [ $id ], [ 'carrier_order_id_meta_key' => $carrier_id_meta ] )
				);
				$registry->register_shipment_handler(
					$id,
					new \Woodev_Once_Shipment_Handler( $api, new Shipping_Order_Handler( [ 'carrier_order_id' => $carrier_id_meta ] ), $id )
				);
			}

			$this->assert_bound_once_to_the_registry();

			$order = wc_create_order();
			$order->set_status( 'processing' );
			$order->update_meta_data( self::MARKER_B, '1' );
			$order->save();

			// The payload Action Scheduler hands the hook: the order id Export_Retry::enqueue() stored.
			do_action( Export_Retry::HOOK, $order->get_id() );

			$this->assertSame( 0, $api_a->create_calls, 'the other carrier is not called' );
			$this->assertSame( 1, $api_b->create_calls, 'the order is exported once — by its own carrier, through the one callback' );

			wp_cache_flush();
			$this->assertSame( 'CARRIER-' . $order->get_id(), wc_get_order( $order->get_id() )->get_meta( self::CARRIER_ID_B ) );
		}
	}
}

namespace {

	if ( ! class_exists( 'Woodev_Once_Bare_Plugin_A' ) ) {

		/**
		 * A `Shipping_Plugin` that registers no carrier. The constructor is bypassed: only the registry
		 * and `add_hooks()` matter here, and the test runs `add_hooks()` itself.
		 */
		class Woodev_Once_Bare_Plugin_A extends \Woodev\Framework\Shipping\Shipping_Plugin {

			public function __construct() {}

			/** @return array */
			protected function get_shipping_method_classes(): array {
				return [];
			}

			/** @return string */
			protected function get_file() {
				return __FILE__;
			}

			/** @return string */
			public function get_plugin_name() {
				return 'Card 1009 bare carrier plugin A';
			}

			/** @return int */
			public function get_download_id() {
				return 0;
			}

			/** @return \Woodev\Framework\Shipping\Api\Shipping_API|null */
			public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
				return null;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Once_Bare_Plugin_B' ) ) {

		/** The second carrier plugin of the same process. */
		class Woodev_Once_Bare_Plugin_B extends Woodev_Once_Bare_Plugin_A {

			/** @return string */
			public function get_plugin_name() {
				return 'Card 1009 bare carrier plugin B';
			}
		}
	}

	if ( ! class_exists( 'Woodev_Once_Fake_Response' ) ) {

		/** A carrier response carrying just an order id. */
		class Woodev_Once_Fake_Response implements \Woodev_API_Response {

			/** @var string */
			public string $order_id;

			/** @param string $order_id the carrier's id. */
			public function __construct( string $order_id ) {
				$this->order_id = $order_id;
			}

			/** @inheritDoc */
			public function to_string(): string {
				return $this->order_id;
			}

			/** @inheritDoc */
			public function to_string_safe(): string {
				return $this->order_id;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Once_Fake_Api' ) ) {

		/** Offline `Shipping_API` that counts `create_order()` calls. */
		class Woodev_Once_Fake_Api implements \Woodev\Framework\Shipping\Api\Shipping_API {

			/** @var int */
			public int $create_calls = 0;

			/** @inheritDoc */
			public function create_order( \WC_Order $order ): \Woodev_API_Response {
				++$this->create_calls;

				return new Woodev_Once_Fake_Response( 'CARRIER-' . $order->get_id() );
			}

			/** @inheritDoc */
			public function cancel_order( string $order_id ): \Woodev_API_Response {
				return new Woodev_Once_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function calculate_rates( array $params ): \Woodev_API_Response {
				return new Woodev_Once_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_pickup_points( array $params ): \Woodev_API_Response {
				return new Woodev_Once_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_order( string $order_id ): \Woodev_API_Response {
				return new Woodev_Once_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_tracking( string $tracking_number ): \Woodev_API_Response {
				return new Woodev_Once_Fake_Response( '' );
			}

			/** @inheritDoc */
			public function get_request(): \Woodev_API_Request {
				throw new \LogicException( 'not used' );
			}

			/** @inheritDoc */
			public function get_response(): ?\Woodev_API_Response {
				return null;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Once_Shipment_Handler' ) ) {

		/** The REAL handler, lock and order re-read included; only the carrier is fake. */
		class Woodev_Once_Shipment_Handler extends \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler {

			/** @inheritDoc */
			protected function extract_carrier_order_id( \Woodev_API_Response $response ): string {
				return $response instanceof Woodev_Once_Fake_Response ? $response->order_id : '';
			}
		}
	}
}

<?php
/**
 * Unit: `GET /shipping/orders/{id}/actions/{action}/fields/{field}/options` — the options of a select that loads
 * them when its dialog opens (kalbac/woocommerce-yandex-delivery#84).
 *
 * Covers: the same gate as the write route (capability, order, carrier, the action being offered now), the field having
 * to be a declared `async_options` select, the carrier's answer being re-sanitised, an empty list being a valid 200, a
 * carrier `WP_Error` / exception / non-list answer being a 502, and a list row carrying NO options.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\{Order_Action_Fields, Order_Actions, Orders_Provider, Orders_Registry};
use Woodev\Framework\Shipping\Order\{Abstract_Shipment_Handler, Action_Result};
use Woodev\Framework\Shipping\Rest_Api\Orders_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
// #1000: `Order_Actions::for_order()` reads the order's edit lock, so the lock's doubles must be loaded.
require_once dirname( __DIR__ ) . '/Admin/order-edit-lock-fixtures.php';
require_once dirname( __DIR__ ) . '/Admin/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::get_field_options
 */
final class OrdersControllerFieldOptionsTest extends TestCase {

	/** @var array<string,mixed> post meta, keyed by meta key, for the active order. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);

		// Order_Row_Builder::build() runs for real here (perform_action() rebuilds
		// the row on success) — same WC-free stubs OrderRowBuilderTest uses.
		Functions\when( 'wc_get_order_status_name' )->alias( static function ( string $status ): string {
			return ucfirst( $status );
		} );
		Functions\when( 'wc_price' )->alias( static function ( $amount ): string {
			return (string) $amount;
		} );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text, bool $remove_breaks = false ): string {
				return trim( strip_tags( $text ) );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function controller(): Orders_Controller {
		return new Orders_Controller( Orders_Registry::instance() );
	}

	private function register_provider( array $args = [] ): Orders_Provider {
		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], $args );

		Orders_Registry::instance()->register_provider( $provider );

		return $provider;
	}

	private function register_handler( bool $supports_update = false ): Abstract_Shipment_Handler {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( $supports_update );
		// #1204: a carrier that declares nothing keeps today's behaviour.
		$handler->shouldReceive( 'supports_refusal' )->andReturn( false )->byDefault();
		$handler->shouldReceive( 'get_handed_over_statuses' )->andReturn( [] )->byDefault();
		$handler->shouldReceive( 'get_refusable_statuses' )->andReturn( [] )->byDefault();
		$handler->shouldReceive( 'is_handed_over' )->andReturn( false )->byDefault();
		$handler->shouldReceive( 'is_refusable' )->andReturn( false )->byDefault();

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		return $handler;
	}

	/**
	 * An order double whose marker meta matches 'cdek', so
	 * {@see Orders_Controller::resolve_matched_provider()} resolves it.
	 */
	private function order( string $status = 'pending' ): \WC_Order {
		$this->meta['_cdek_marker'] = '1';

		$order = Mockery::mock( '\WC_Order' );

		$defaults = [
			'get_id'                           => 123,
			'get_order_number'                 => '123',
			'get_edit_order_url'                => 'https://example.test/wp-admin/post.php?post=123&action=edit',
			'get_status'                        => $status,
			'get_date_created'                 => null,
			'get_formatted_billing_full_name'  => 'Иван Иванов',
			'get_customer_id'                   => 0,
			'get_billing_email'                 => 'ivan@example.test',
			'get_billing_phone'                 => '+79991234567',
			'get_payment_method_title'           => 'Банковская карта',
			'get_formatted_order_total'          => '1000 руб.',
			'needs_payment'                      => false,
			'get_shipping_method'                => 'СДЭК',
			'get_shipping_total'                 => '300',
			'get_shipping_postcode'              => '',
			'get_shipping_state'                 => '',
			'get_shipping_city'                  => '',
			'get_shipping_address_1'             => '',
			'get_billing_postcode'               => '',
			'get_billing_state'                  => '',
			'get_billing_city'                   => '',
			'get_billing_address_1'              => '',
			'get_shipping_methods'               => [],
		];

		foreach ( $defaults as $method => $value ) {
			$order->shouldReceive( $method )->andReturn( $value );
		}

		// Called by Orders_Controller::reread_order() after a successful action. Declared
		// `byDefault()` so a test that cares CAN replace it with a precise expectation —
		// a plain `shouldReceive()` here would match any argument first and silently
		// absorb the call, leaving that test's `->once()->with( true )` never satisfied.
		$order->shouldReceive( 'read_meta_data' )->byDefault();

		Functions\when( 'wc_get_order' )->justReturn( $order );

		return $order;
	}

	private function request( int $id, string $action, string $field ): \WP_REST_Request {
		return new \WP_REST_Request(
			[
				'id'     => $id,
				'action' => $action,
				'field'  => $field,
			]
		);
	}

	/**
	 * Offers the `pick_interval` extra with an `async_options` select, and answers the options filter with `$answer`.
	 *
	 * @param mixed    $answer what the carrier's options filter returns (or a callable that throws).
	 * @param int|null $calls  incremented on every options call.
	 */
	private function stub_interval_action( $answer, ?int &$calls = null ): void {
		$calls = 0;

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) use ( $answer, &$calls ) {
				if ( 'woodev_shipping_order_actions' === $hook ) {
					$value[] = [
						'action'      => 'pick_interval',
						'label'       => 'Выгрузить с интервалом',
						'title'       => '',
						'destructive' => false,
						'fields'      => [
							[
								'id'            => 'interval',
								'type'          => 'select',
								'label'         => 'Интервал',
								'required'      => true,
								'async_options' => true,
								// Declared options are ignored for an async select.
								'options'       => [ 'stale' => 'Устаревший' ],
							],
							[
								'id'      => 'static',
								'type'    => 'select',
								'label'   => 'Статический',
								'options' => [ 'a' => 'A' ],
							],
						],
					];

					return $value;
				}

				if ( 'woodev_shipping_order_action_field_options' === $hook ) {
					++$calls;

					if ( is_callable( $answer ) ) {
						return $answer( ...$args );
					}

					return $answer;
				}

				return $value;
			}
		);
	}

	public function test_permission_check_is_the_write_routes_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( true );

		$this->assertTrue( $this->controller()->perform_action_permissions_check( new \WP_REST_Request() ) );
	}

	public function test_the_row_ships_the_field_without_options_and_asks_the_carrier_nothing(): void {
		$this->register_provider();
		$this->register_handler();
		$this->stub_interval_action( [ [ 'value' => 'x', 'label' => 'X' ] ], $calls );
		$order = $this->order( 'completed' );

		$actions = ( new Order_Actions( Orders_Registry::instance() ) )->for_row( $order, Orders_Registry::instance()->get_provider( 'cdek' ) );
		$field   = $actions[0]['fields'][0];

		$this->assertTrue( $field['async_options'] );
		$this->assertSame( [], $field['options'], 'the declared options are dropped: the carrier supplies them per order' );
		$this->assertSame( '', $field['default'] );
		$this->assertSame( 0, $calls, 'building the row never loads the options' );
	}

	public function test_a_declared_async_select_is_answered_with_the_carriers_sanitised_options(): void {
		$this->register_provider();
		$this->register_handler();
		$received = null;
		$this->stub_interval_action(
			static function ( ...$args ) use ( &$received ) {
				$received = $args;

				return [
					[ 'value' => 'a/b', 'label' => 'Утро' ],
					[ 'value' => 'a/b', 'label' => 'Дубль' ],
					[ 'value' => '', 'label' => 'Пустое' ],
					'bad' => 'Через ключ',
				];
			},
			$calls
		);
		$order = $this->order( 'completed' );

		$result = $this->controller()->get_field_options( $this->request( 123, 'pick_interval', 'interval' ) );

		$this->assertSame(
			[
				'options' => [
					[ 'value' => 'a/b', 'label' => 'Утро' ],
					[ 'value' => 'bad', 'label' => 'Через ключ' ],
				],
			],
			$result
		);
		$this->assertSame( 1, $calls );
		$this->assertSame( $order, $received[0], 'the filter gets the order' );
		$this->assertSame( [ 'pick_interval', 'interval' ], array_slice( $received, 1, 2 ) );
	}

	public function test_an_empty_list_is_a_valid_answer(): void {
		$this->register_provider();
		$this->register_handler();
		$this->stub_interval_action( [] );
		$this->order( 'completed' );

		$this->assertSame(
			[ 'options' => [] ],
			$this->controller()->get_field_options( $this->request( 123, 'pick_interval', 'interval' ) )
		);
	}

	public function test_the_list_is_cut_at_the_ceiling(): void {
		$this->register_provider();
		$this->register_handler();
		$many = [];
		for ( $i = 1; $i <= Order_Action_Fields::MAX_ASYNC_OPTIONS + 20; $i++ ) {
			$many[] = [ 'value' => 'v' . $i, 'label' => 'L' . $i ];
		}
		$this->stub_interval_action( $many );
		$this->order( 'completed' );

		$result = $this->controller()->get_field_options( $this->request( 123, 'pick_interval', 'interval' ) );

		$this->assertCount( Order_Action_Fields::MAX_ASYNC_OPTIONS, $result['options'] );
	}

	public function test_a_static_or_unknown_field_is_a_404_and_the_carrier_is_not_asked(): void {
		$this->register_provider();
		$this->register_handler();
		$this->stub_interval_action( [ [ 'value' => 'x', 'label' => 'X' ] ], $calls );
		$this->order( 'completed' );

		foreach ( [ 'static', 'missing' ] as $field ) {
			$result = $this->controller()->get_field_options( $this->request( 123, 'pick_interval', $field ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 404, $result->get_error_data()['status'] );
			$this->assertSame( 'woodev_shipping_orders_unknown_field', $result->get_error_code() );
		}

		$this->assertSame( 0, $calls );
	}

	public function test_an_action_the_gate_does_not_offer_is_a_400_and_the_carrier_is_not_asked(): void {
		$this->register_provider();
		$this->register_handler();
		$this->stub_interval_action( [ [ 'value' => 'x', 'label' => 'X' ] ], $calls );
		$this->order( 'completed' );

		$result = $this->controller()->get_field_options( $this->request( 123, 'export', 'interval' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'woodev_shipping_orders_action_not_available', $result->get_error_code() );
		$this->assertSame( 0, $calls );
	}

	public function test_an_unknown_order_is_a_404(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$result = $this->controller()->get_field_options( $this->request( 999, 'pick_interval', 'interval' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_an_unresolvable_carrier_is_a_400(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$result = $this->controller()->get_field_options( $this->request( 123, 'pick_interval', 'interval' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_a_carrier_wp_error_passes_its_merchant_sentence_on_as_a_502(): void {
		$this->register_provider();
		$this->register_handler();
		$this->stub_interval_action( new \WP_Error( 'x', 'Яндекс Доставка не ответила.' ) );
		$this->order( 'completed' );

		$result = $this->controller()->get_field_options( $this->request( 123, 'pick_interval', 'interval' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertSame( 'Яндекс Доставка не ответила.', $result->get_error_message() );
	}

	public function test_an_exception_or_a_non_list_answer_is_a_generic_502(): void {
		$this->register_provider();
		$this->register_handler();
		$this->order( 'completed' );

		foreach (
			[
				static function () {
					throw new \RuntimeException( 'secret token abc' );
				},
				null,
				'not a list',
			] as $answer
		) {
			$this->stub_interval_action( $answer );

			$result = $this->controller()->get_field_options( $this->request( 123, 'pick_interval', 'interval' ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 502, $result->get_error_data()['status'] );
			$this->assertStringNotContainsString( 'secret', $result->get_error_message(), 'a thrown message never reaches the browser' );
			$this->assertSame( 'Не удалось получить варианты у перевозчика. Попробуйте ещё раз.', $result->get_error_message() );
		}
	}
}

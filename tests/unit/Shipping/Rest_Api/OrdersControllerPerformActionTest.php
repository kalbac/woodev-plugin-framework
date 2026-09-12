<?php
/**
 * Unit: `POST /shipping/orders/{id}/actions/{action}` — performs one row action
 * (card #824).
 *
 * Covers: refusing an action the server's OWN recomputed gate does not list (never
 * trusting the client's button), `export()` returning `''` (#860) not being reported
 * as success, the permission gate, and the unknown-order/unknown-carrier guards.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Rest_Api\Orders_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::perform_action
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::perform_action_permissions_check
 */
final class OrdersControllerPerformActionTest extends TestCase {

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

		Functions\when( 'wc_get_order' )->justReturn( $order );

		return $order;
	}

	private function request( int $id, string $action ): \WP_REST_Request {
		return new \WP_REST_Request(
			[
				'id'     => $id,
				'action' => $action,
			]
		);
	}

	// ----- permission gate -----

	public function test_permission_check_requires_edit_shop_orders(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( true );

		$this->assertTrue( $this->controller()->perform_action_permissions_check( new \WP_REST_Request() ) );
	}

	// ----- unknown order / carrier -----

	public function test_unknown_order_is_a_404(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$result = $this->controller()->perform_action( $this->request( 999, Order_Actions::EXPORT ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_unresolvable_carrier_is_a_400(): void {
		// No provider registered at all, so resolve_matched_provider() finds nothing.
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$result = $this->controller()->perform_action( $this->request( 123, Order_Actions::EXPORT ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ----- never trusts the client's button -----

	public function test_an_action_outside_the_computed_list_is_refused(): void {
		$this->register_provider();
		$this->register_handler();

		// 'completed' offers neither export (wrong status) nor update/cancel (never
		// exported) — the computed list is empty.
		$order = $this->order( 'completed' );

		$result = $this->controller()->perform_action( $this->request( 123, Order_Actions::EXPORT ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'woodev_shipping_orders_action_not_available', $result->get_error_code() );
	}

	// ----- #860: export() returning '' is not success -----

	public function test_export_returning_an_empty_id_is_reported_as_a_failure_not_a_success(): void {
		$this->register_provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );
		$handler = $this->register_handler();
		$handler->shouldReceive( 'export' )->once()->andReturn( '' );

		$order = $this->order( 'pending' );

		$result = $this->controller()->perform_action( $this->request( 123, Order_Actions::EXPORT ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_a_successful_export_returns_the_rebuilt_row_and_a_message(): void {
		$this->register_provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );
		$handler = $this->register_handler();
		$handler->shouldReceive( 'export' )->once()->andReturn( 'CARRIER-1' );

		$order = $this->order( 'pending' );

		$result = $this->controller()->perform_action( $this->request( 123, Order_Actions::EXPORT ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'row', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertSame( 123, $result['row']['id'] );
		$this->assertNotSame( '', $result['message'] );
	}

	public function test_a_successful_cancel_returns_the_rebuilt_row(): void {
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->register_provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );
		$handler = $this->register_handler();
		$handler->shouldReceive( 'cancel' )->once()->andReturn( true );

		$order = $this->order( 'processing' );

		$result = $this->controller()->perform_action( $this->request( 123, Order_Actions::CANCEL ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['row']['is_exported'] );
	}

	public function test_a_failed_cancel_is_a_502(): void {
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->register_provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );
		$handler = $this->register_handler();
		$handler->shouldReceive( 'cancel' )->once()->andReturn( false );

		$order = $this->order( 'processing' );

		$result = $this->controller()->perform_action( $this->request( 123, Order_Actions::CANCEL ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}
}

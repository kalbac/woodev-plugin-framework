<?php
/**
 * Unit: `GET /shipping/orders/{id}/preview` — what a shop owner needs to see about one order
 * without opening it (card #875).
 *
 * Covers: the response shape, 404 for an unknown order, a missing SKU / empty note / null
 * carrier rendering as genuinely ABSENT (`null`/`''`, never the string `'null'` or `'0'`), and
 * the `null`-provider degradation (carrier unresolved => empty actions, null tracking, unknown
 * delivery status) — the same degradation {@see \Woodev\Tests\Unit\OrderRowBuilderTest} already
 * pins for the row shape, since {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder::build_preview()}
 * reuses the very same private helpers.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
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
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::get_preview
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder::build_preview
 */
final class OrdersControllerPreviewTest extends TestCase {

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
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ): string {
				return ucfirst( $status );
			}
		);
		Functions\when( 'wc_price' )->alias(
			static function ( $amount ): string {
				return (string) $amount;
			}
		);
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

	private function request( int $id ): \WP_REST_Request {
		return new \WP_REST_Request( [ 'id' => $id ] );
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
	 * @param array<int,\WC_Order_Item_Product> $items line items {@see \WC_Order::get_items()} returns.
	 */
	private function order( array $overrides = [], array $items = [], bool $with_marker = true ): \WC_Order {
		if ( $with_marker ) {
			$this->meta['_cdek_marker'] = '1';
		}

		$order = Mockery::mock( '\WC_Order' );

		$defaults = array_merge(
			[
				'get_id'                          => 239,
				'get_order_number'                => '239',
				'get_edit_order_url'              => 'https://example.test/wp-admin/post.php?post=239&action=edit',
				'get_status'                       => 'processing',
				'get_date_created'                => null,
				'get_formatted_billing_full_name' => 'Иван Иванов',
				// Read by Order_Row_Builder::without_leading_name(): WooCommerce's address
				// format opens with the recipient, and the preview shows the name already.
				'get_formatted_shipping_full_name' => 'Иван Иванов',
				'get_customer_id'                  => 0,
				'get_billing_email'                => 'ivan@example.test',
				'get_billing_phone'                => '+79991234567',
				'get_formatted_billing_address'    => '',
				'get_formatted_shipping_address'   => '',
				'get_payment_method_title'          => 'Банковская карта',
				'get_formatted_order_total'         => '1000 руб.',
				'needs_payment'                     => false,
				'get_shipping_method'               => 'СДЭК Курьер',
				'get_shipping_total'                => '300',
				'get_shipping_postcode'              => '',
				'get_shipping_state'                  => '',
				'get_shipping_city'                   => '',
				'get_shipping_address_1'              => '',
				'get_billing_postcode'                => '',
				'get_billing_state'                   => '',
				'get_billing_city'                     => '',
				'get_billing_address_1'                => '',
				'get_customer_note'                    => '',
				'get_items'                             => $items,
			],
			$overrides
		);

		foreach ( $defaults as $method => $value ) {
			$order->shouldReceive( $method )->andReturn( $value );
		}

		Functions\when( 'wc_get_order' )->justReturn( $order );

		return $order;
	}

	/**
	 * A `WC_Order_Item_Product` double.
	 */
	private function item( string $name, ?string $sku, int $quantity, string $total ): \WC_Order_Item_Product {
		$item = Mockery::mock( '\WC_Order_Item_Product' );
		$item->shouldReceive( 'get_name' )->andReturn( $name );
		$item->shouldReceive( 'get_quantity' )->andReturn( $quantity );
		$item->shouldReceive( 'get_total' )->andReturn( $total );

		if ( null === $sku ) {
			$item->shouldReceive( 'get_product' )->andReturn( null );

			return $item;
		}

		$product = Mockery::mock( '\WC_Product' );
		$product->shouldReceive( 'get_sku' )->andReturn( $sku );
		$item->shouldReceive( 'get_product' )->andReturn( $product );

		return $item;
	}

	// ----- 404 -----

	public function test_unknown_order_is_a_404(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$result = $this->controller()->get_preview( $this->request( 999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	// ----- shape -----

	public function test_the_preview_carries_the_full_shape(): void {
		$this->register_provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$order = $this->order(
			[
				'get_formatted_billing_address'  => 'г. Москва, ул. Тверская, д. 1<br/>Россия',
				'get_formatted_shipping_address' => 'г. Москва, ул. Тверская, д. 1<br/>Россия',
				'get_customer_note'               => 'Позвоните перед доставкой',
			],
			[ $this->item( 'Демо-товар', 'SKU-1', 2, '1990' ) ]
		);

		$result = $this->controller()->get_preview( $this->request( 239 ) );

		$this->assertSame( 239, $result['id'] );
		$this->assertSame( '239', $result['order_number'] );
		$this->assertSame( 'processing', $result['status']['slug'] );
		$this->assertSame( [ 'id' => 'cdek', 'label' => 'СДЭК' ], $result['carrier'] );
		$this->assertSame( 'Иван Иванов', $result['customer']['name'] );
		$this->assertSame( "г. Москва, ул. Тверская, д. 1\nРоссия", $result['billing']['address'] );
		$this->assertSame( 'ivan@example.test', $result['billing']['email'] );
		$this->assertSame( "г. Москва, ул. Тверская, д. 1\nРоссия", $result['shipping']['address'] );
		$this->assertSame( 'СДЭК Курьер', $result['shipping']['method_title'] );
		$this->assertSame( 'Банковская карта', $result['payment']['method_title'] );
		$this->assertFalse( $result['payment']['needs_payment'] );
		$this->assertSame( 'Позвоните перед доставкой', $result['customer_note'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Демо-товар', $result['items'][0]['name'] );
		$this->assertSame( 'SKU-1', $result['items'][0]['sku'] );
		$this->assertSame( 2, $result['items'][0]['quantity'] );
		$this->assertIsArray( $result['actions'] );
	}

	// ----- absence rules -----

	public function test_a_missing_sku_is_null_not_a_placeholder_string(): void {
		$this->register_provider();
		$this->register_handler();

		$this->order( [], [ $this->item( 'Без SKU', null, 1, '500' ) ] );

		$result = $this->controller()->get_preview( $this->request( 239 ) );

		$this->assertNull( $result['items'][0]['sku'] );
	}

	public function test_an_empty_note_is_an_empty_string(): void {
		$this->register_provider();
		$this->register_handler();

		$this->order( [ 'get_customer_note' => '' ] );

		$result = $this->controller()->get_preview( $this->request( 239 ) );

		$this->assertSame( '', $result['customer_note'] );
	}

	public function test_an_unresolvable_carrier_is_null_and_degrades_gracefully(): void {
		// No provider registered at all — resolve_matched_provider() finds nothing.
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 239 );
		$order->shouldReceive( 'get_order_number' )->andReturn( '239' );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.test/x' );
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$order->shouldReceive( 'get_date_created' )->andReturn( null );
		$order->shouldReceive( 'get_formatted_billing_full_name' )->andReturn( '' );
		$order->shouldReceive( 'get_formatted_shipping_full_name' )->andReturn( '' );
		$order->shouldReceive( 'get_customer_id' )->andReturn( 0 );
		$order->shouldReceive( 'get_billing_email' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( '' );
		$order->shouldReceive( 'get_formatted_billing_address' )->andReturn( '' );
		$order->shouldReceive( 'get_formatted_shipping_address' )->andReturn( '' );
		$order->shouldReceive( 'get_payment_method_title' )->andReturn( '' );
		$order->shouldReceive( 'get_formatted_order_total' )->andReturn( '' );
		$order->shouldReceive( 'needs_payment' )->andReturn( false );
		$order->shouldReceive( 'get_shipping_method' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_total' )->andReturn( '0' );
		$order->shouldReceive( 'get_shipping_postcode' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_state' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_city' )->andReturn( '' );
		$order->shouldReceive( 'get_shipping_address_1' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_postcode' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_state' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_city' )->andReturn( '' );
		$order->shouldReceive( 'get_billing_address_1' )->andReturn( '' );
		$order->shouldReceive( 'get_customer_note' )->andReturn( '' );
		$order->shouldReceive( 'get_items' )->andReturn( [] );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$result = $this->controller()->get_preview( $this->request( 239 ) );

		$this->assertNull( $result['carrier'] );
		$this->assertSame( [], $result['actions'] );
		$this->assertNull( $result['tracking']['number'] );
		$this->assertNull( $result['tracking']['url'] );
		$this->assertSame( 'unknown', $result['delivery_status']['canonical'] );
		$this->assertNull( $result['delivery_status']['raw'] );
	}
}

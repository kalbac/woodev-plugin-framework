<?php
/**
 * Unit: the admin order wizard's REST transport (#710 spec D4, card #968).
 *
 * Pinned: the capability gate (`edit_shop_orders`, per route), that only the known payload keys
 * reach the service — the route's own `id` is never read as part of an order — and that the
 * service's `WP_Error`s pass through UNTOUCHED (statuses and structured 422 data are the
 * transport contract), while successes get the declared bodies.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Rest_Api\Order_Editor_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__ ) . '/Order/order-persistence-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Order_Editor_Controller
 */
final class OrderEditorControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ) {
				return new class( $data ) {
					/** @var mixed */
					public $data;

					/** @var int */
					public $status = 200;

					/** @param mixed $data body. */
					public function __construct( $data ) {
						$this->data = $data;
					}

					/** @param int $status HTTP status. */
					public function set_status( int $status ): void {
						$this->status = $status;
					}
				};
			}
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);
	}

	/**
	 * A request double with a JSON body and route params.
	 *
	 * @param array<string,mixed>|null $json   the JSON body (null = not JSON).
	 * @param array<string,mixed>      $params the route params.
	 * @return \WP_REST_Request
	 */
	private function request( ?array $json, array $params = [] ): \WP_REST_Request {
		$request = Mockery::mock( '\WP_REST_Request' );
		$request->shouldReceive( 'get_json_params' )->andReturn( $json );
		$request->shouldReceive( 'get_body_params' )->andReturn( [ 'items' => 'from-form' ] );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( string $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);

		return $request;
	}

	/**
	 * @param \WC_Order|null $order the order the editor returns.
	 * @return \WC_Order
	 */
	private function saved_order( int $id = 77, string $number = '77' ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_order_number' )->andReturn( $number );

		return $order;
	}

	private function controller( Order_Editor $editor ): Order_Editor_Controller {
		return new Order_Editor_Controller( Orders_Registry::instance(), $editor );
	}

	public function test_the_capability_is_edit_shop_orders(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( true );

		$this->assertTrue( $this->controller( Mockery::mock( Order_Editor::class ) )->permissions_check( $this->request( [] ) ) );

		Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( false );

		$this->assertFalse( $this->controller( Mockery::mock( Order_Editor::class ) )->permissions_check( $this->request( [] ) ) );
	}

	public function test_create_hands_only_the_known_payload_keys_to_the_service_and_answers_201(): void {
		$editor = Mockery::mock( Order_Editor::class );
		$editor->shouldReceive( 'create' )->once()->with(
			[
				'items'         => [ [ 'product_id' => 10 ] ],
				'shipping_line' => [ 'method_id' => 'cdek_courier' ],
			]
		)->andReturn( $this->saved_order( 77, 'A-77' ) );

		$response = $this->controller( $editor )->create_order(
			$this->request(
				[
					'items'         => [ [ 'product_id' => 10 ] ],
					'shipping_line' => [ 'method_id' => 'cdek_courier' ],
					'id'            => 5,
					'status_hack'   => 'x',
				]
			)
		);

		$this->assertSame( 201, $response->status );
		$this->assertSame( 77, $response->data['id'] );
		$this->assertSame( 'A-77', $response->data['number'] );
		$this->assertStringContainsString( 'A-77', $response->data['message'] );
	}

	public function test_a_form_body_is_read_when_the_request_is_not_json(): void {
		$editor = Mockery::mock( Order_Editor::class );
		$editor->shouldReceive( 'create' )->once()->with( [ 'items' => 'from-form' ] )->andReturn( $this->saved_order() );

		$this->controller( $editor )->create_order( $this->request( null ) );
	}

	public function test_update_takes_the_id_from_the_route_never_from_the_body(): void {
		$editor = Mockery::mock( Order_Editor::class );
		$editor->shouldReceive( 'update' )->once()->with( 123, [ 'status' => 'processing' ] )->andReturn( $this->saved_order( 123, '123' ) );

		$response = $this->controller( $editor )->update_order(
			$this->request(
				[
					'id'     => 999,
					'status' => 'processing',
				],
				[ 'id' => '123' ]
			)
		);

		$this->assertSame( 200, $response->status );
		$this->assertSame( 123, $response->data['id'] );
	}

	public function test_load_returns_the_prefill_untouched(): void {
		$prefill = [
			'order' => [ 'id' => 123 ],
			'items' => [],
		];

		$editor = Mockery::mock( Order_Editor::class );
		$editor->shouldReceive( 'load' )->once()->with( 123 )->andReturn( $prefill );

		$response = $this->controller( $editor )->load_order( $this->request( null, [ 'id' => '123' ] ) );

		$this->assertSame( $prefill, $response->data );
	}

	public function test_the_services_errors_pass_through_with_their_status_and_data(): void {
		$error = new \WP_Error(
			'woodev_shipping_order_invalid',
			'Заказ не сохранён',
			[
				'status' => 422,
				'errors' => [
					[
						'field'   => 'items',
						'code'    => 'items_required',
						'message' => 'x',
					],
				],
			]
		);

		$editor = Mockery::mock( Order_Editor::class );
		$editor->shouldReceive( 'create' )->andReturn( $error );
		$editor->shouldReceive( 'update' )->andReturn( $error );
		$editor->shouldReceive( 'load' )->andReturn( $error );

		$controller = $this->controller( $editor );

		$this->assertSame( $error, $controller->create_order( $this->request( [] ) ) );
		$this->assertSame( $error, $controller->update_order( $this->request( [], [ 'id' => '5' ] ) ) );
		$this->assertSame( $error, $controller->load_order( $this->request( null, [ 'id' => '5' ] ) ) );
	}
}

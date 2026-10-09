<?php
/**
 * Unit: the toolbar-actions routes behind the carrier's page-level buttons (s164).
 *
 * Covers the route table and its gates (page capability to read, `edit_shop_orders` to write — never the other way
 * round), the HTTP shape of each answer (the buttons, the dialog, a submit's per-order results, a 422 with
 * `data.errors`, a refused or failed row button) and that an unknown or dialog-less action is a 404.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Framework\Shipping\Rest_Api\Toolbar_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Toolbar_Controller
 */
final class ToolbarControllerTest extends TestCase {

	/** @var array<string,callable> hook => callback( $value, ...$args ). */
	private $hooks = [];

	protected function setUp(): void {
		parent::setUp();

		$this->hooks = [];

		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => strip_tags( $text ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( string $text ): string => strip_tags( $text ) );
		Functions\when( 'error_log' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				return isset( $this->hooks[ $hook ] ) ? ( $this->hooks[ $hook ] )( $value, ...$args ) : $value;
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) {
				$order = Mockery::mock( '\WC_Order' );
				$order->shouldReceive( 'get_id' )->andReturn( $id );
				$order->shouldReceive( 'get_order_number' )->andReturn( (string) $id );

				return $order;
			}
		);
	}

	private function controller(): Toolbar_Controller {
		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] );
		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldReceive( 'resolve_provider_for_order' )->andReturn( $provider );
		$registry->shouldReceive( 'get_page_capability' )->andReturn( 'manage_woocommerce' );

		return new Toolbar_Controller( $registry );
	}

	private function declare_courier( ?callable $perform = null, ?callable $row = null ): void {
		$this->hooks['woodev_shipping_orders_toolbar_actions'] = static fn( $value ) => array_merge(
			$value,
			[
				[
					'id'       => 'call_courier',
					'provider' => 'cdek',
					'label'    => 'Вызвать курьера',
					'count'    => 2,
				],
				[
					'id'       => 'hidden_one',
					'provider' => 'cdek',
					'label'    => 'Скрытое',
					'count'    => 0,
				],
			]
		);
		$this->hooks['woodev_shipping_orders_toolbar_dialog']  = static function ( $value, $id ) {
			if ( 'call_courier' !== $id ) {
				return $value;
			}

			return [
				'tabs' => [
					[
						'id'     => 'call',
						'type'   => 'form',
						'label'  => 'Вызов',
						'fields' => [
							[
								'id'       => 'orders',
								'type'     => 'orders',
								'label'    => 'Заказы',
								'required' => true,
								'options'  => [
									[
										'value' => '1047',
										'label' => '#1047',
									],
									[
										'value' => '1050',
										'label' => '#1050',
									],
								],
							],
							[
								'id'       => 'day',
								'type'     => 'date',
								'label'    => 'День',
								'required' => true,
							],
						],
					],
					[
						'id'      => 'intakes',
						'type'    => 'list',
						'label'   => 'Заявки',
						'columns' => [
							[
								'id'    => 'number',
								'label' => '№',
							],
						],
						'rows'    => [
							[
								'id'      => 'uuid-1',
								'cells'   => [ 'number' => '13312783' ],
								'actions' => [
									[
										'action'      => 'cancel',
										'label'       => 'Отменить',
										'destructive' => true,
									],
								],
							],
						],
					],
				],
			];
		};

		if ( null !== $perform ) {
			$this->hooks['woodev_shipping_perform_toolbar_action'] = $perform;
		}

		if ( null !== $row ) {
			$this->hooks['woodev_shipping_perform_toolbar_row_action'] = $row;
		}
	}

	private function request( array $params ): \WP_REST_Request {
		return new \WP_REST_Request( $params );
	}

	// ----- routes -----

	public function test_the_routes_are_registered_with_the_right_methods_and_gates(): void {
		$registered = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
			}
		);

		$this->controller()->register_routes();

		$this->assertSame(
			[
				'/shipping/orders/toolbar-actions',
				'/shipping/orders/toolbar-actions/(?P<id>[a-z0-9_-]+)',
				'/shipping/orders/toolbar-actions/(?P<id>[a-z0-9_-]+)/rows',
			],
			array_keys( $registered )
		);

		$list = $registered['/shipping/orders/toolbar-actions'];
		$this->assertSame( 'GET', $list['methods'] );
		$this->assertSame( 'read_permissions_check', $list['permission_callback'][1] );

		[ $read, $write ] = $registered['/shipping/orders/toolbar-actions/(?P<id>[a-z0-9_-]+)'];
		$this->assertSame( [ 'GET', 'read_permissions_check' ], [ $read['methods'], $read['permission_callback'][1] ] );
		$this->assertSame( [ 'POST', 'write_permissions_check' ], [ $write['methods'], $write['permission_callback'][1] ] );

		$rows = $registered['/shipping/orders/toolbar-actions/(?P<id>[a-z0-9_-]+)/rows'];
		$this->assertSame( [ 'POST', 'write_permissions_check' ], [ $rows['methods'], $rows['permission_callback'][1] ] );
		$this->assertTrue( $rows['args']['tab']['required'] && $rows['args']['row']['required'] && $rows['args']['action']['required'] );
	}

	public function test_reading_needs_the_page_capability_and_writing_needs_edit_shop_orders(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		$this->assertTrue( $this->controller()->read_permissions_check( new \WP_REST_Request() ) );

		Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( false );
		$this->assertFalse( $this->controller()->write_permissions_check( new \WP_REST_Request() ) );
	}

	// ----- reads -----

	public function test_the_buttons_are_the_visible_ones(): void {
		$this->declare_courier();

		$response = $this->controller()->get_actions( $this->request( [] ) );

		$this->assertSame( [ 'call_courier' ], array_column( $response['actions'], 'id' ) );
		$this->assertSame( 2, $response['actions'][0]['count'] );
	}

	public function test_the_dialog_is_served_and_an_unknown_action_is_a_404(): void {
		$this->declare_courier();

		$dialog = $this->controller()->get_dialog( $this->request( [ 'id' => 'call_courier' ] ) );

		$this->assertSame( 'call_courier', $dialog['id'] );
		$this->assertSame( [ 'call', 'intakes' ], array_column( $dialog['dialog']['tabs'], 'id' ) );

		$missing = $this->controller()->get_dialog( $this->request( [ 'id' => 'nope' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	public function test_a_declared_action_whose_carrier_gives_no_dialog_is_a_404(): void {
		$this->declare_courier();

		$result = $this->controller()->get_dialog( $this->request( [ 'id' => 'hidden_one' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	// ----- submit -----

	public function test_a_submit_answers_one_result_per_order_and_the_refreshed_dialog(): void {
		$this->declare_courier(
			static fn( $value, $action, $order ) => 1047 === $order->get_id()
				? Action_Result::success()
				: Action_Result::failure( 'Адрес не найден' )
		);

		$response = $this->controller()->submit(
			$this->request(
				[
					'id'      => 'call_courier',
					'payload' => [
						'orders' => [ '1047', '1050' ],
						'day'    => '2026-10-12',
					],
				]
			)
		);

		$this->assertSame( 'call_courier', $response['action'] );
		$this->assertSame( 2, $response['requested'] );
		$this->assertSame( 1, $response['succeeded'] );
		$this->assertSame( 1, $response['failed'] );
		$this->assertSame( [ true, false ], array_column( $response['results'], 'ok' ) );
		$this->assertSame( 'СДЭК: Адрес не найден', $response['results'][1]['message'] );
		$this->assertSame( [ 'success', 'error' ], array_keys( $response['messages'] ) );
		$this->assertSame( [ 'call', 'intakes' ], array_column( $response['dialog']['tabs'], 'id' ), 'the list tab is current after the run' );
	}

	public function test_a_submit_that_does_not_fit_is_a_422_with_one_error_per_field_and_runs_nothing(): void {
		$ran = 0;

		$this->declare_courier(
			static function () use ( &$ran ) {
				++$ran;

				return Action_Result::success();
			}
		);

		$result = $this->controller()->submit(
			$this->request(
				[
					'id'      => 'call_courier',
					'payload' => [
						'orders' => [ '1047' ],
						'day'    => '',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_shipping_orders_invalid_payload', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( [ 'day' ], array_column( $result->get_error_data()['errors'], 'field' ) );
		$this->assertSame( 0, $ran );
	}

	public function test_a_submit_for_an_unknown_action_is_a_404(): void {
		$this->declare_courier();

		$result = $this->controller()->submit( $this->request( [ 'id' => 'nope' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_a_run_where_every_order_failed_is_still_a_200(): void {
		$this->declare_courier( static fn() => Action_Result::failure( 'Нельзя' ) );

		$response = $this->controller()->submit(
			$this->request(
				[
					'id'      => 'call_courier',
					'payload' => [
						'orders' => [ '1047' ],
						'day'    => '2026-10-12',
					],
				]
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response, 'a failed order is not a failed request' );
		$this->assertSame( 1, $response['failed'] );
	}

	// ----- row buttons -----

	public function test_a_row_button_answers_its_message_and_the_refreshed_dialog(): void {
		$this->declare_courier( null, static fn() => Action_Result::success( '', 'Вызов отменён' ) );

		$response = $this->controller()->perform_row_action(
			$this->request(
				[
					'id'     => 'call_courier',
					'tab'    => 'intakes',
					'row'    => 'uuid-1',
					'action' => 'cancel',
				]
			)
		);

		$this->assertSame( 'Вызов отменён', $response['message'] );
		$this->assertSame( [ 'call', 'intakes' ], array_column( $response['dialog']['tabs'], 'id' ) );
	}

	public function test_a_row_button_the_dialog_does_not_offer_is_a_400(): void {
		$this->declare_courier( null, static fn() => Action_Result::success() );

		$result = $this->controller()->perform_row_action(
			$this->request(
				[
					'id'     => 'call_courier',
					'tab'    => 'intakes',
					'row'    => 'uuid-1',
					'action' => 'erase',
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_shipping_orders_toolbar_row_action_not_available', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_a_row_button_the_carrier_refused_is_a_502_with_its_reason(): void {
		$this->declare_courier( null, static fn() => Action_Result::failure( 'Заявка уже в работе' ) );

		$result = $this->controller()->perform_row_action(
			$this->request(
				[
					'id'     => 'call_courier',
					'tab'    => 'intakes',
					'row'    => 'uuid-1',
					'action' => 'cancel',
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_shipping_orders_toolbar_row_action_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertSame( 'Заявка уже в работе', $result->get_error_message() );
	}
}

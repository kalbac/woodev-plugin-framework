<?php
/**
 * Integration: an order another manager holds the native edit lock on refuses the carrier actions —
 * on BOTH WooCommerce order datastores (#1000).
 *
 * The wizard (#982) takes the order's native lock; an export or cancel landing under it would make
 * the wizard's save fail on the now-exported order. So the row greys its actions out, the action
 * route answers 409, and the bulk route skips the order — with the reason — and runs the rest.
 * The lock's own owner is never blocked by it.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class OrderLockedActionsRestTest extends TestCase {

	private const MARKER = '_woodev_test_locked_row_marker';

	/** @var Abstract_Shipment_Handler&\PHPUnit\Framework\MockObject\MockObject */
	private $handler;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler = $this->createMock( Abstract_Shipment_Handler::class );
		$this->handler->method( 'supports_update' )->willReturn( false );

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create( 'locked_row', 'Locked Row', self::MARKER, [ 'locked_row' ], [ 'carrier_order_id_meta_key' => '_woodev_test_locked_row_carrier_id' ] )
		);
		$registry->register_shipment_handler( 'locked_row', $this->handler );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/** @return array<string,array{0:bool}> */
	public function datastore_provider(): array {
		return [
			'HPOS'       => [ true ],
			'legacy CPT' => [ false ],
		];
	}

	/**
	 * @param bool $hpos true => HPOS; false => legacy CPT.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * @return int the logged-in manager's id.
	 */
	private function login_as_manager(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * @return int a new exportable order of the test carrier.
	 */
	private function create_order(): int {
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( self::MARKER, '1' );

		return $order->save();
	}

	/**
	 * Writes the native lock for the selected datastore.
	 *
	 * @param int  $order_id order to lock.
	 * @param int  $user_id  manager who owns the lock.
	 * @param bool $hpos     active datastore.
	 * @return void
	 */
	private function set_edit_lock( int $order_id, int $user_id, bool $hpos ): void {
		if ( ! $hpos ) {
			update_post_meta( $order_id, '_edit_lock', time() . ':' . $user_id );

			return;
		}

		$order = wc_get_order( $order_id );

		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->update_meta_data( '_edit_lock', time() . ':' . $user_id );
		$order->save_meta_data();
	}

	/**
	 * @return int a second manager, the lock's owner.
	 */
	private function other_manager(): int {
		return self::factory()->user->create( [ 'role' => 'shop_manager', 'display_name' => 'Мария' ] );
	}

	/**
	 * @param string $method HTTP method.
	 * @param string $route  route.
	 * @param array  $body   JSON body.
	 * @return \WP_REST_Response
	 */
	private function send( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( [] !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_row_greys_out_every_action_of_an_order_another_manager_is_editing( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();
		$id = $this->create_order();
		$this->set_edit_lock( $id, $this->other_manager(), $hpos );

		$rows = $this->send( 'GET', '/woodev/v1/shipping/orders' )->get_data()['rows'];
		$row  = array_values( array_filter( $rows, static fn( $r ) => $r['id'] === $id ) )[0];

		$this->assertSame( [ 'edit', 'export' ], array_column( $row['actions'], 'action' ), 'greyed out, not removed' );

		foreach ( $row['actions'] as $action ) {
			$this->assertTrue( $action['disabled'], $action['action'] );
			$this->assertSame( 'Мария', $action['lock_owner'], $action['action'] );
		}
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_action_route_answers_409_and_never_reaches_the_carrier_for_a_locked_order( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();
		$id = $this->create_order();
		$this->set_edit_lock( $id, $this->other_manager(), $hpos );
		$this->handler->expects( $this->never() )->method( 'export' );

		$response = $this->send( 'POST', '/woodev/v1/shipping/orders/' . $id . '/actions/export' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woodev_shipping_order_locked', $response->get_data()['code'] );
		$this->assertSame( 'This order is already being edited by Мария', $response->get_data()['message'] );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_bulk_route_skips_the_locked_order_with_its_reason_and_runs_the_rest( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();
		$free   = $this->create_order();
		$locked = $this->create_order();
		$this->set_edit_lock( $locked, $this->other_manager(), $hpos );
		$this->handler->expects( $this->once() )->method( 'export' )->willReturn( Action_Result::success( 'CARRIER-1' ) );

		$response = $this->send( 'POST', '/woodev/v1/shipping/orders/bulk/export', [ 'ids' => [ $free, $locked ] ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['succeeded'] );
		$this->assertSame( 1, $data['skipped'] );
		$this->assertSame(
			[ [ 'id' => $locked, 'message' => 'This order is already being edited by Мария' ] ],
			$data['locked']
		);
		$this->assertArrayHasKey( 'warning', $data['messages'] );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_managers_own_lock_never_blocks_their_actions( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$manager_id = $this->login_as_manager();
		$id         = $this->create_order();
		$this->set_edit_lock( $id, $manager_id, $hpos );
		$this->handler->expects( $this->once() )->method( 'export' )->willReturn( Action_Result::success( 'CARRIER-1' ) );

		$rows = $this->send( 'GET', '/woodev/v1/shipping/orders' )->get_data()['rows'];
		$row  = array_values( array_filter( $rows, static fn( $r ) => $r['id'] === $id ) )[0];

		$this->assertSame( [ 'edit', 'export' ], array_column( $row['actions'], 'action' ) );
		$this->assertArrayNotHasKey( 'disabled', $row['actions'][1] );
		$this->assertSame( 200, $this->send( 'POST', '/woodev/v1/shipping/orders/' . $id . '/actions/export' )->get_status() );
	}
}

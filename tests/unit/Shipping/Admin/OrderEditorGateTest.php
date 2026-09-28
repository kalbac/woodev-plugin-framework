<?php
/**
 * Unit: what the order editor answers BEFORE it writes anything (#710 spec D4 / D5, card #968).
 *
 * The transport contract's error statuses: 404 (no such order, or not a row of the orders page),
 * 409 (not editable — exported / final status / finished delivery), 422 (the payload's problems as
 * data). Pinned here, with no WooCommerce writes at all — the writes themselves are proven by the
 * integration tests on both datastores.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Order_Payload_Validator;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Order/order-persistence-fixtures.php';
require_once __DIR__ . '/order-editor-lock-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::create
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::update
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::load
 */
final class OrderEditorGateTest extends TestCase {

	/** @var array<int,array<string,mixed>> post meta by order id, then meta key. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		// `update()` takes the order's edit lock first (#981 round 4); here it is always granted.
		$GLOBALS['wpdb'] = new Order_Editor_Fake_Wpdb();

		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $post_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ): string {
				return ucfirst( $status );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
		Orders_Registry::instance()->register_provider(
			Orders_Provider::create(
				'cdek',
				'СДЭК',
				'_cdek_marker',
				[ 'cdek_courier' ],
				[
					'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
					'marker_writer'             => static function ( \WC_Order $order, array $context ): void {
					},
				]
			)
		);
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	private function editor(): Order_Editor {
		$registry = Orders_Registry::instance();

		return new Order_Editor( $registry, new Order_Payload_Validator( $registry ) );
	}

	/**
	 * An order double; its marker is set unless `$marked` is false.
	 *
	 * @param int    $id     order id.
	 * @param string $status order status.
	 * @param bool   $marked whether it carries the carrier marker.
	 * @return \WC_Order
	 */
	private function order( int $id = 123, string $status = 'pending', bool $marked = true ): \WC_Order {
		if ( $marked ) {
			$this->meta[ $id ]['_cdek_marker'] = '1';
		}

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'read_meta_data' )->with( true )->andReturnNull();

		return $order;
	}

	/**
	 * @param \WP_Error|mixed $result a service result.
	 * @return int the HTTP status carried by the error.
	 */
	private function status_of( $result ): int {
		$this->assertInstanceOf( \WP_Error::class, $result );

		return (int) $result->get_error_data()['status'];
	}

	public function test_an_unknown_order_is_a_404_on_every_route(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->assertSame( 404, $this->status_of( $this->editor()->update( 999, [] ) ) );
		$this->assertSame( 404, $this->status_of( $this->editor()->load( 999 ) ) );
	}

	public function test_a_zero_id_never_reaches_woodcommerce(): void {
		Functions\expect( 'wc_get_order' )->never();

		$this->assertSame( 404, $this->status_of( $this->editor()->load( 0 ) ) );
	}

	public function test_an_order_that_is_not_a_row_of_the_page_is_a_404_not_a_409(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 123, 'pending', false ) );

		$result = $this->editor()->update( 123, [] );

		$this->assertSame( 404, $this->status_of( $result ) );
		$this->assertSame( 'woodev_shipping_orders_unknown_order', $result->get_error_code() );
	}

	public function test_an_exported_order_is_a_409_with_the_reason(): void {
		$this->meta[123]['_cdek_carrier_order_id'] = 'CARRIER-1';
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		$result = $this->editor()->load( 123 );

		$this->assertSame( 409, $this->status_of( $result ) );
		$this->assertSame( 'woodev_shipping_order_not_editable', $result->get_error_code() );
		$this->assertStringContainsString( 'отмените выгрузку', $result->get_error_message() );
	}

	public function test_a_final_status_is_a_409(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 123, 'completed' ) );

		$this->assertSame( 409, $this->status_of( $this->editor()->update( 123, [] ) ) );
		$this->assertSame( 409, $this->status_of( $this->editor()->load( 123 ) ) );
	}

	public function test_the_gate_answers_before_the_payload_is_looked_at(): void {
		// 404 / 409 come ahead of 422: a stale row must not be told to «fix the fields».
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 123, 'refunded' ) );

		$this->assertSame( 409, $this->status_of( $this->editor()->update( 123, [] ) ) );
	}

	public function test_an_invalid_payload_on_an_editable_order_is_a_422_with_the_problems_as_data(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		$result = $this->editor()->update( 123, [] );
		$data   = $result->get_error_data();

		$this->assertSame( 422, $this->status_of( $result ) );
		$this->assertSame( 'woodev_shipping_order_invalid', $result->get_error_code() );

		$fields = array_column( $data['errors'], 'field' );

		$this->assertContains( 'items', $fields );
		$this->assertContains( 'shipping_line', $fields );

		foreach ( $data['errors'] as $error ) {
			$this->assertSame( [ 'field', 'code', 'message' ], array_keys( $error ) );
		}
	}

	public function test_the_row_is_read_again_before_the_write_so_a_racing_export_is_refused(): void {
		// The stale-row race (spec D5): the row was editable when the request arrived and the
		// payload is valid, but the order was exported while the request was being checked. The
		// SECOND read — on a fresh order — must refuse it, and nothing may be written.
		$validator = Mockery::mock( Order_Payload_Validator::class );
		$validator->shouldReceive( 'validate' )->once()->andReturn(
			[
				'data'   => [],
				'errors' => [],
			]
		);

		$first = $this->order();

		$exported = Mockery::mock( '\WC_Order' );
		$exported->shouldReceive( 'get_id' )->andReturn( 123 );
		$exported->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$exported->shouldReceive( 'read_meta_data' )->with( true )->andReturnNull();
		$exported->shouldNotReceive( 'save' );
		$exported->shouldNotReceive( 'set_address' );

		$reads = 0;

		Functions\when( 'wc_get_order' )->alias(
			function () use ( &$reads, $first, $exported ) {
				++$reads;

				if ( 2 === $reads ) {
					$this->meta[123]['_cdek_carrier_order_id'] = 'CARRIER-1';
				}

				return 1 === $reads ? $first : $exported;
			}
		);

		$result = ( new Order_Editor( Orders_Registry::instance(), $validator ) )->update( 123, [] );

		$this->assertSame( 2, $reads );
		$this->assertSame( 409, $this->status_of( $result ) );
		$this->assertSame( 'woodev_shipping_order_not_editable', $result->get_error_code() );
	}

	public function test_an_invalid_payload_stops_after_one_read_of_the_order(): void {
		$reads = 0;

		Functions\when( 'wc_get_order' )->alias(
			function () use ( &$reads ) {
				++$reads;

				return $this->order();
			}
		);

		$this->editor()->update( 123, [] );

		$this->assertSame( 1, $reads, 'the second read is the pre-write re-check, reached only by a valid payload' );
	}

	public function test_create_answers_422_without_creating_anything(): void {
		Functions\expect( 'wc_create_order' )->never();

		$result = $this->editor()->create( [] );

		$this->assertSame( 422, $this->status_of( $result ) );
		$this->assertContains( 'billing.country', array_column( $result->get_error_data()['errors'], 'field' ) );
	}
}

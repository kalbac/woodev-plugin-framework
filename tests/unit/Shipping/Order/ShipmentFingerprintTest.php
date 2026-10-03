<?php
/**
 * Unit: the fingerprint of an order as it was handed to the carrier (#947).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Order\Shipment_Fingerprint;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

/**
 * @covers \Woodev\Framework\Shipping\Order\Shipment_Fingerprint
 */
final class ShipmentFingerprintTest extends TestCase {

	/** @var array<string,mixed> the post meta of order 123 */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, int $flags = 0 ) {
				return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double of wp_json_encode().
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);
	}

	/**
	 * @param array{0:string,1:string,2:float,3?:float} ...$products name, sku, quantity, unit price
	 * @return array<int,\WC_Order_Item_Product>
	 */
	private function items( array ...$products ): array {
		$items = [];

		foreach ( $products as $index => $spec ) {
			$item = Mockery::mock( '\\WC_Order_Item_Product' );
			$item->shouldReceive( 'get_name' )->andReturn( $spec[0] );
			$item->shouldReceive( 'get_quantity' )->andReturn( $spec[2] );
			$item->shouldReceive( 'get_tax_class' )->andReturn( 'standard' );
			$item->shouldReceive( 'get_taxes' )->andReturn( [] );
			$item->shouldReceive( 'unit_price' )->andReturn( $spec[3] ?? 100.0 );

			$product = Mockery::mock( '\\WC_Product' );
			$product->shouldReceive( 'get_sku' )->andReturn( $spec[1] );
			$product->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
			$item->shouldReceive( 'get_product' )->andReturn( $product );

			$items[ $index + 1 ] = $item;
		}

		return $items;
	}

	/**
	 * An order double. Every part of the fingerprint can be overridden by getter name.
	 *
	 * @param array<string,mixed> $overrides getter name => value; `items` => product items, `methods` => [ [method_id, instance_id] ].
	 * @return \Mockery\MockInterface&\WC_Order
	 */
	private function order( array $overrides = [] ) {
		$values = array_merge(
			[
				'get_total'                => '1300.00',
				'get_shipping_first_name'  => 'Иван',
				'get_shipping_last_name'   => 'Иванов',
				'get_billing_first_name'   => 'Пётр',
				'get_billing_last_name'    => 'Петров',
				'get_shipping_phone'       => '+7 (999) 123-45-67',
				'get_billing_phone'        => '+7 (900) 000-00-00',
				'get_shipping_country'     => 'RU',
				'get_shipping_state'       => 'MOW',
				'get_shipping_city'        => 'Москва',
				'get_shipping_postcode'    => '101000',
				'get_shipping_address_1'   => 'ул. Тверская, 1',
				'get_shipping_address_2'   => '',
				'get_billing_country'      => 'RU',
				'get_billing_state'        => 'SPE',
				'get_billing_city'         => 'Санкт-Петербург',
				'get_billing_postcode'     => '190000',
				'get_billing_address_1'    => 'Невский пр., 1',
				'get_billing_address_2'    => '',
			],
			$overrides
		);

		$items   = $values['items'] ?? $this->items( [ 'Книга', 'BK-1', 2.0 ], [ 'Ручка', 'PN-1', 1.0 ] );
		$methods = [];

		foreach ( $values['methods'] ?? [ [ 'cdek_courier', 5 ] ] as $spec ) {
			$method = Mockery::mock( '\\WC_Order_Item_Shipping' );
			$method->shouldReceive( 'get_method_id' )->andReturn( $spec[0] );
			$method->shouldReceive( 'get_instance_id' )->andReturn( $spec[1] );
			$methods[] = $method;
		}

		unset( $values['items'], $values['methods'] );

		$order = Mockery::mock( '\\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( $items );
		$order->shouldReceive( 'get_items' )->with( 'fee' )->andReturn( [] );
		$order->shouldReceive( 'get_qty_refunded_for_item' )->andReturn( 0 );
		$order->shouldReceive( 'get_item_total' )->andReturnUsing(
			static function ( $item ) {
				return $item->unit_price();
			}
		);
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( $methods );

		foreach ( $values as $getter => $value ) {
			$order->shouldReceive( $getter )->andReturn( $value );
		}

		return $order;
	}

	public function test_the_meta_key_and_the_version_are_the_frameworks_own_and_stable(): void {
		$this->assertSame( '_woodev_shipment_fingerprint', Shipment_Fingerprint::META );
		$this->assertSame( 'v1', Shipment_Fingerprint::VERSION );
	}

	public function test_the_fingerprint_is_versioned_and_stable_for_the_same_order(): void {
		$first  = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' );
		$second = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' );

		$this->assertMatchesRegularExpression( '/^v1:[0-9a-f]{64}$/', $first );
		$this->assertSame( $first, $second );
	}

	public function test_it_does_not_change_with_an_order_of_lines_or_methods_or_stray_whitespace(): void {
		$base = Shipment_Fingerprint::for_order(
			$this->order(
				[
					'items'   => $this->items( [ 'Книга', 'BK-1', 2.0 ], [ 'Ручка', 'PN-1', 1.0 ] ),
					'methods' => [ [ 'cdek_courier', 5 ], [ 'cdek_pickup', 7 ] ],
				]
			)
		);

		$reordered = Shipment_Fingerprint::for_order(
			$this->order(
				[
					'items'                 => $this->items( [ 'Ручка', 'PN-1', 1.0 ], [ 'Книга', 'BK-1', 2.0 ] ),
					'methods'               => [ [ 'cdek_pickup', 7 ], [ 'cdek_courier', 5 ] ],
					'get_shipping_city'     => '  Москва ',
					'get_shipping_phone'    => '79991234567',
					'get_shipping_postcode' => "101000\n",
				]
			)
		);

		$this->assertSame( $base, $reordered );
	}

	public function test_it_ignores_what_the_carrier_request_does_not_depend_on(): void {
		// Notes, the status, the payment method and any meta are simply not read: a double that
		// would fail on any of them being asked for proves the fingerprint never touches them.
		$order = $this->order();
		$order->shouldNotReceive( 'get_status' );
		$order->shouldNotReceive( 'get_customer_note' );
		$order->shouldNotReceive( 'get_payment_method' );
		$order->shouldNotReceive( 'get_customer_order_notes' );

		$before = Shipment_Fingerprint::for_order( $order );
		$this->meta['_some_unrelated_meta'] = 'x';

		$this->assertSame( $before, Shipment_Fingerprint::for_order( $order ) );
	}

	/**
	 * @dataProvider provide_changes_that_matter
	 *
	 * @param array<string,mixed> $overrides what changes in the order
	 * @param string              $pickup    the pickup point id after the change
	 */
	public function test_it_changes_when_something_the_request_depends_on_changes( array $overrides, string $pickup = 'PVZ-1' ): void {
		$base = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' );

		$this->assertNotSame( $base, Shipment_Fingerprint::for_order( $this->order( $overrides ), $pickup ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1?:string}>
	 */
	public function provide_changes_that_matter(): array {
		$items = function ( array ...$products ): array {
			return $this->items( ...$products );
		};

		return [
			'quantity'             => [ [ 'items' => $items( [ 'Книга', 'BK-1', 3.0 ], [ 'Ручка', 'PN-1', 1.0 ] ) ] ],
			'item added'           => [ [ 'items' => $items( [ 'Книга', 'BK-1', 2.0 ], [ 'Ручка', 'PN-1', 1.0 ], [ 'Блокнот', 'NB-1', 1.0 ] ) ] ],
			'item removed'         => [ [ 'items' => $items( [ 'Книга', 'BK-1', 2.0 ] ) ] ],
			'item price'           => [ [ 'items' => $items( [ 'Книга', 'BK-1', 2.0, 150.0 ], [ 'Ручка', 'PN-1', 1.0 ] ) ] ],
			'city'                 => [ [ 'get_shipping_city' => 'Тверь' ] ],
			'street'               => [ [ 'get_shipping_address_1' => 'ул. Арбат, 5' ] ],
			'postcode'             => [ [ 'get_shipping_postcode' => '101001' ] ],
			'recipient name'       => [ [ 'get_shipping_first_name' => 'Сергей' ] ],
			'recipient phone'      => [ [ 'get_shipping_phone' => '+7 999 123-45-68' ] ],
			'shipping method'      => [ [ 'methods' => [ [ 'cdek_pickup', 7 ] ] ] ],
			'shipping instance'    => [ [ 'methods' => [ [ 'cdek_courier', 6 ] ] ] ],
			'pickup point'         => [ [], 'PVZ-2' ],
			'pickup point removed' => [ [], '' ],
			'order total'          => [ [ 'get_total' => '1400.00' ] ],
		];
	}

	public function test_an_empty_shipping_address_falls_back_to_billing_the_way_the_order_shows_it(): void {
		$billing_only = $this->order(
			[
				'get_shipping_city'      => '',
				'get_shipping_postcode'  => '',
				'get_shipping_address_1' => '',
				'get_shipping_first_name' => '',
				'get_shipping_last_name'  => '',
				'get_shipping_phone'      => '',
			]
		);

		$this->assertNotSame( Shipment_Fingerprint::for_order( $this->order() ), Shipment_Fingerprint::for_order( $billing_only ) );

		// …and a change of the BILLING address now matters, because that is what the order ships to.
		$billing_edited = $this->order(
			[
				'get_shipping_city'       => '',
				'get_shipping_postcode'   => '',
				'get_shipping_address_1'  => '',
				'get_shipping_first_name' => '',
				'get_shipping_last_name'  => '',
				'get_shipping_phone'      => '',
				'get_billing_city'        => 'Казань',
			]
		);

		$this->assertNotSame( Shipment_Fingerprint::for_order( $billing_only ), Shipment_Fingerprint::for_order( $billing_edited ) );
	}

	public function test_a_billing_edit_is_ignored_while_the_order_ships_to_its_own_address(): void {
		$edited = $this->order( [ 'get_billing_city' => 'Казань', 'get_billing_phone' => '+7 900 111-11-11' ] );

		// The shipping phone is set, so the billing phone is not the recipient's.
		$this->assertSame( Shipment_Fingerprint::for_order( $this->order() ), Shipment_Fingerprint::for_order( $edited ) );
	}

	public function test_stored_returns_the_meta_or_an_empty_string(): void {
		$this->assertSame( '', Shipment_Fingerprint::stored( $this->order() ) );

		$this->meta[ Shipment_Fingerprint::META ] = 'v1:abc';

		$this->assertSame( 'v1:abc', Shipment_Fingerprint::stored( $this->order() ) );
	}

	public function test_an_order_exported_unchanged_is_not_outdated(): void {
		$this->meta[ Shipment_Fingerprint::META ] = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' );

		$this->assertTrue( Shipment_Fingerprint::is_comparable( $this->order() ) );
		$this->assertFalse( Shipment_Fingerprint::is_outdated( $this->order(), 'PVZ-1' ) );
	}

	public function test_an_order_changed_after_the_export_is_outdated(): void {
		$this->meta[ Shipment_Fingerprint::META ] = Shipment_Fingerprint::for_order( $this->order(), 'PVZ-1' );

		$this->assertTrue( Shipment_Fingerprint::is_outdated( $this->order( [ 'get_shipping_city' => 'Тверь' ] ), 'PVZ-1' ) );
		$this->assertTrue( Shipment_Fingerprint::is_outdated( $this->order(), 'PVZ-2' ) );
	}

	public function test_a_fingerprint_of_another_version_is_unknown_never_changed(): void {
		$this->meta[ Shipment_Fingerprint::META ] = 'v0:' . str_repeat( 'a', 64 );

		$order = $this->order( [ 'get_shipping_city' => 'Тверь' ] );

		$this->assertFalse( Shipment_Fingerprint::is_comparable( $order ) );
		$this->assertFalse( Shipment_Fingerprint::is_outdated( $order ) );
	}

	public function test_an_order_exported_before_the_fingerprint_existed_is_unknown_never_changed(): void {
		$order = $this->order( [ 'get_shipping_city' => 'Тверь' ] );

		$this->assertFalse( Shipment_Fingerprint::is_comparable( $order ) );
		$this->assertFalse( Shipment_Fingerprint::is_outdated( $order ) );
	}

	public function test_record_writes_the_current_fingerprint_through_the_orders_own_meta_api_and_saves(): void {
		$order    = $this->order();
		$expected = Shipment_Fingerprint::for_order( $order, 'PVZ-1' );

		$order->shouldReceive( 'update_meta_data' )->once()->with( '_woodev_shipment_fingerprint', $expected )->ordered();
		$order->shouldReceive( 'save_meta_data' )->once()->ordered();

		Shipment_Fingerprint::record( $order, 'PVZ-1' );
	}

	public function test_clear_deletes_the_fingerprint_off_the_fresh_order_and_the_callers_copy(): void {
		$fresh  = Mockery::mock( '\\WC_Order' );
		$caller = Mockery::mock( '\\WC_Order' );

		foreach ( [ $fresh, $caller ] as $order ) {
			$order->shouldReceive( 'get_meta' )->with( '_woodev_shipment_fingerprint' )->andReturn( 'v1:abc' );
			$order->shouldReceive( 'delete_meta_data' )->once()->with( '_woodev_shipment_fingerprint' );
			$order->shouldReceive( 'save_meta_data' )->once();
		}

		Shipment_Fingerprint::clear( $fresh, $caller );
	}

	public function test_clear_is_a_no_op_with_no_datastore_write_when_nothing_was_recorded(): void {
		$order = Mockery::mock( '\\WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( '_woodev_shipment_fingerprint' )->andReturn( '' );
		$order->shouldNotReceive( 'delete_meta_data' );
		$order->shouldNotReceive( 'save_meta_data' );

		Shipment_Fingerprint::clear( $order, $order );

		$this->addToAssertionCount( 1 );
	}
}

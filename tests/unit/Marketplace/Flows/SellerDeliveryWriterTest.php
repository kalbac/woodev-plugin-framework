<?php
/**
 * Unit tests for the delivery of an order the seller delivers.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Flows\Seller_Delivery_Writer;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Tests\Unit\Marketplace\Support\Stateful_Order;
use Woodev\Tests\Unit\TestCase;

/**
 * The order's recipient, phone, address and comment are seeded and then left alone: a field follows the marketplace only while it
 * holds what the framework wrote. The shipping line needs WooCommerce's classes and is covered by the integration suite.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Seller_Delivery_Writer
 * @covers \Woodev\Framework\Marketplace\Flows\Seller_Order
 */
final class SellerDeliveryWriterTest extends TestCase {
	/**
	 * @var Stateful_Order
	 */
	private $order;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wc_sanitize_phone_number' )->returnArg();

		$this->order = new Stateful_Order();
	}

	public function test_the_first_read_seeds_the_empty_fields_and_marks_the_order_paid(): void {
		$this->apply( self::delivery()->with_comment( 'Звонить заранее' ) );

		$this->assertSame( 'Анна', $this->order->props['shipping_first_name'] );
		$this->assertSame( 'Анна', $this->order->props['billing_first_name'] );
		$this->assertSame( '+79991111111', $this->order->props['shipping_phone'] );
		$this->assertSame( '+79991111111', $this->order->props['billing_phone'] );
		$this->assertSame( 'Москва, Тверская 1', $this->order->props['shipping_address_1'] );
		$this->assertSame( '', $this->order->props['shipping_city'], 'The city is not parsed out of the address.' );
		$this->assertSame( 'Звонить заранее', $this->order->props['customer_note'] );
		$this->assertSame( 'Тест', $this->order->props['payment_method_title'] );
		$this->assertNotNull( $this->order->props['date_paid'] );
		$this->assertTrue( Seller_Order::is_seller_delivered( $this->order->order ) );
		$this->assertSame( '55.75', Seller_Order::delivery( $this->order->order )->latitude() );
	}

	public function test_a_field_nobody_changed_follows_the_marketplace(): void {
		$this->apply( self::delivery() );
		$this->apply( self::delivery()->with_phone( '+79992222222' )->with_recipient( 'Ольга' ) );

		$this->assertSame( '+79992222222', $this->order->props['shipping_phone'] );
		$this->assertSame( '+79992222222', $this->order->props['billing_phone'] );
		$this->assertSame( 'Ольга', $this->order->props['shipping_first_name'] );
	}

	public function test_a_corrected_phone_and_address_are_never_written_over(): void {
		$this->apply( self::delivery() );

		$this->order->props['shipping_phone']     = '+79000000000';
		$this->order->props['billing_phone']      = '+79000000000';
		$this->order->props['shipping_address_1'] = 'Москва, Арбат 2';

		for ( $poll = 0; $poll < 3; $poll++ ) {
			$this->apply( self::delivery()->with_phone( '+79992222222' )->with_address( 'Москва, Тверская 99' ) );
		}

		$this->assertSame( '+79000000000', $this->order->props['shipping_phone'] );
		$this->assertSame( 'Москва, Арбат 2', $this->order->props['shipping_address_1'] );
		$this->assertSame( '+79992222222', Seller_Order::delivery( $this->order->order )->phone(), 'The marketplace value stays known.' );
		$this->assertSame( 'Москва, Тверская 99', Seller_Order::delivery( $this->order->order )->address() );
	}

	public function test_a_resolved_city_makes_the_whole_address_the_merchants(): void {
		$this->apply( self::delivery() );

		$this->order->props['shipping_city'] = 'Москва';
		$this->apply( self::delivery()->with_address( 'Казань, Баумана 1' ) );

		$this->assertSame( 'Москва, Тверская 1', $this->order->props['shipping_address_1'] );
	}

	public function test_a_phone_typed_before_the_marketplace_told_one_is_never_seeded_over(): void {
		$this->apply( new Seller_Delivery( 'Тест', 'Доставка Тест' ) );
		$this->order->props['billing_phone'] = '+79000000000';

		$this->apply( self::delivery() );

		$this->assertSame( '+79000000000', $this->order->props['billing_phone'] );
		$this->assertSame( '', $this->order->props['shipping_phone'], 'The phone is one field: a typed half keeps the whole.' );
		$this->assertSame( 'Анна', $this->order->props['shipping_first_name'] );
	}

	public function test_a_value_a_later_read_leaves_out_stays_known(): void {
		$this->apply( self::delivery()->with_phone( '+79991111111', '123' ) );
		$this->apply( new Seller_Delivery( 'Тест', 'Доставка Тест' ) );

		$known = Seller_Order::delivery( $this->order->order );

		$this->assertSame( '123', $known->phone_extension() );
		$this->assertSame( 'Москва, Тверская 1', $known->address() );
		$this->assertSame( 'Москва, Тверская 1', $this->order->props['shipping_address_1'] );
	}

	public function test_the_marketplaces_data_is_kept_per_unit_and_refreshed(): void {
		$first  = self::order( 'u1', self::delivery()->with_payload( [ 'address' => 'Тверская 1', 'comment' => 'a' ] ) );
		$second = self::order( 'u1', self::delivery()->with_payload( [ 'address' => '', 'comment' => 'b' ] ) );

		( new Seller_Delivery_Writer() )->apply( $this->order->order, $first );
		( new Seller_Delivery_Writer() )->apply( $this->order->order, $second );

		$this->assertSame(
			[
				'#u1' => [
					'address' => 'Тверская 1',
					'comment' => 'b',
				],
			],
			Seller_Order::payload( $this->order->order )
		);
	}

	public function test_each_unit_of_a_grouped_order_keeps_its_own_data(): void {
		$one   = self::order( 'u1', self::delivery()->with_payload( [ 'id' => 1 ] ) );
		$two   = self::order( 'u2', self::delivery()->with_payload( [ 'id' => 2 ] ) );
		$whole = self::order( 'cart', self::delivery() )->with_units( [ $one, $two ] );

		( new Seller_Delivery_Writer() )->apply( $this->order->order, $whole );

		$this->assertSame( [ '#u1' => [ 'id' => 1 ], '#u2' => [ 'id' => 2 ] ], Seller_Order::payload( $this->order->order ) );
	}

	public function test_an_erased_order_gets_no_buyer_data_again(): void {
		$this->order->meta['_anonymized'] = 'yes';

		$this->apply( self::delivery()->with_payload( [ 'phone' => '+79991111111' ] ) );

		$this->assertSame( '', $this->order->props['shipping_phone'] );
		$this->assertNull( Seller_Order::delivery( $this->order->order ) );
		$this->assertSame( [], Seller_Order::payload( $this->order->order ) );
		$this->assertTrue( Seller_Order::is_seller_delivered( $this->order->order ) );
	}

	public function test_a_cancelled_order_is_not_marked_paid(): void {
		( new Seller_Delivery_Writer() )->apply( $this->order->order, self::order( 'u1', self::delivery(), Status_Map::CANCELLED ) );

		$this->assertNull( $this->order->props['date_paid'] );
	}

	public function test_an_order_the_marketplace_delivers_is_left_alone(): void {
		$this->assertFalse( ( new Seller_Delivery_Writer() )->apply( $this->order->order, self::order( 'u1' ) ) );
		$this->assertSame( [], $this->order->meta );
		$this->assertSame( '', $this->order->props['payment_method_title'] );
	}

	private function apply( Seller_Delivery $delivery ): void {
		( new Seller_Delivery_Writer() )->apply( $this->order->order, self::order( 'u1', $delivery ) );
	}

	private static function delivery(): Seller_Delivery {
		return ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )
			->with_recipient( 'Анна' )
			->with_phone( '+79991111111' )
			->with_address( 'Москва, Тверская 1', '55.75', '37.61' );
	}

	private static function order( string $id, ?Seller_Delivery $delivery = null, string $status = Status_Map::NEW ): Order_DTO {
		$order = new Order_DTO( 1, $id, '', $status, $status, [] );

		return null === $delivery ? $order : $order->with_delivery( $delivery );
	}
}

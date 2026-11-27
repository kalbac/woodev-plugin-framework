<?php
/**
 * Unit tests for the delivery a marketplace describes.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Orders;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Orders\Seller_Delivery
 * @covers \Woodev\Framework\Marketplace\Orders\Order_DTO
 */
final class SellerDeliveryTest extends TestCase {

	public function test_it_survives_storage(): void {
		$delivery = ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )
			->with_recipient( 'Анна' )
			->with_phone( '+79991111111', '1234567' )
			->with_other_phone( '+79993333333', '12' )
			->with_other_phone( '' )
			->with_address( 'Москва, Тверская 1', '55.75', '37.61', true )
			->with_address_note( 'нет адреса' )
			->with_comment( 'домофон' )
			->with_schedule( '2026-11-29', '02:00', '04:00' )
			->with_price( Money::from_string( '1.00', 'RUB' ) )
			->with_payload( [ 'id' => 1 ] );

		$read = Seller_Delivery::from_array( $delivery->to_array() );

		$this->assertSame( $delivery->to_array(), $read->to_array() );
		$this->assertSame( '1.00', $read->price()->to_string() );
		$this->assertCount( 1, $read->other_phones() );
		$this->assertTrue( $read->is_pickup_point() );
		$this->assertSame( [], $read->payload(), 'The payload is stored apart.' );
		$this->assertNull( Seller_Delivery::from_array( '' ) );
		$this->assertNull( Seller_Delivery::from_array( [ 'phone' => '+7' ] ) );
	}

	public function test_a_later_read_replaces_what_it_tells_and_keeps_the_rest(): void {
		$earlier = ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )
			->with_phone( '+79991111111', '123' )
			->with_address( 'Тверская 1', '1', '2' )
			->with_schedule( '2026-11-29', '02:00', '04:00' )
			->with_price( Money::from_string( '1.00', 'RUB' ) );
		$later   = ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )->with_phone( '+79992222222' );

		$merged = $later->merged_over( $earlier );

		$this->assertSame( '+79992222222', $merged->phone() );
		$this->assertSame( '', $merged->phone_extension(), 'A new number comes with its own extension.' );
		$this->assertSame( 'Тверская 1', $merged->address() );
		$this->assertSame( '1', $merged->latitude() );
		$this->assertSame( '04:00', $merged->time_to() );
		$this->assertSame( '1.00', $merged->price()->to_string() );
		$this->assertSame( $later, $later->merged_over( null ) );
	}

	public function test_it_needs_its_titles(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Seller_Delivery( 'Тест', '' );
	}

	public function test_an_order_names_its_scheme_and_delivery(): void {
		$order    = new Order_DTO( 1, 'u1', '', 'new', Status_Map::NEW, [] );
		$delivery = new Seller_Delivery( 'Тест', 'Доставка Тест' );
		$with     = $order->with_scheme( 'dbs' )->with_delivery( $delivery );

		$this->assertSame( 'dbs', $with->scheme() );
		$this->assertSame( $delivery, $with->delivery() );
		$this->assertSame( '', $order->scheme() );
		$this->assertNull( $order->delivery() );
	}

	public function test_a_scheme_code_has_one_shape(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Order_DTO( 1, 'u1', '', 'new', Status_Map::NEW, [] ) )->with_scheme( 'DBS Express' );
	}
}

<?php
/**
 * Unit tests for the buyer data of marketplace orders in WooCommerce's privacy tools.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Flows\Order_Privacy;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Tests\Unit\Marketplace\Support\Stateful_Order;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Order_Privacy
 * @covers \Woodev\Framework\Marketplace\Flows\Seller_Order
 */
final class OrderPrivacyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Order_Privacy::forget();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_it_hooks_woocommerces_order_exporter_and_erasure_once(): void {
		Filters\expectAdded( 'woocommerce_privacy_export_order_personal_data' )->once();
		Actions\expectAdded( 'woocommerce_privacy_before_remove_order_personal_data' )->once();
		Actions\expectAdded( 'woocommerce_privacy_remove_order_personal_data' )->once();

		( new Order_Privacy( 'fake-seller' ) )->register();
		( new Order_Privacy( 'fake-seller' ) )->register();
	}

	public function test_it_exports_the_marketplaces_data_of_its_own_order(): void {
		$order = self::order();
		$data  = ( new Order_Privacy( 'fake-seller' ) )->export( [ [ 'name' => 'x', 'value' => 'y' ] ], $order->order );
		$text  = json_encode( $data, JSON_UNESCAPED_UNICODE );

		$this->assertSame( 'x', $data[0]['name'] );
		$this->assertStringContainsString( 'Анна', $text );
		$this->assertStringContainsString( '+79991111111, добавочный 12', $text );
		$this->assertStringContainsString( 'Тверская 1', $text );
		$this->assertStringContainsString( '55.75, 37.61', $text );
		$this->assertStringContainsString( 'raw-buyer', $text );
	}

	public function test_it_erases_the_marketplaces_data_and_comment_of_its_own_order(): void {
		$order   = self::order();
		$privacy = new Order_Privacy( 'fake-seller' );

		$privacy->erase_comment( $order->order );
		$privacy->erase( $order->order );

		$this->assertSame( '', $order->props['customer_note'] );
		$this->assertSame( [ Seller_Order::META_SELLER_DELIVERED => 'yes' ], $order->meta );
	}

	public function test_it_leaves_other_orders_alone(): void {
		$order   = self::order();
		$privacy = new Order_Privacy( 'another-seller' );

		$privacy->erase_comment( $order->order );
		$privacy->erase( $order->order );

		$this->assertSame( [], $privacy->export( [], $order->order ) );
		$this->assertSame( 'домофон', $order->props['customer_note'] );
		$this->assertArrayHasKey( Seller_Order::META_DELIVERY, $order->meta );
	}

	private static function order(): Stateful_Order {
		$order    = new Stateful_Order();
		$delivery = ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )
			->with_recipient( 'Анна' )
			->with_phone( '+79991111111', '12' )
			->with_address( 'Тверская 1', '55.75', '37.61' )
			->with_comment( 'домофон' );

		$order->props['customer_note']                       = 'домофон';
		$order->meta[ Seller_Order::META_SELLER_DELIVERED ] = 'yes';
		$order->meta[ Seller_Order::META_DELIVERY ]         = $delivery->to_array();
		$order->meta[ Seller_Order::META_PAYLOAD ]          = [ '#u1' => [ 'name' => 'raw-buyer' ] ];

		return $order;
	}
}

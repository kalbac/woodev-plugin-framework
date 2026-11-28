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
use Woodev\Framework\Marketplace\Flows\Seller_Delivery_Writer;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Framework\Marketplace\Orders\Status_Map;
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

	/**
	 * The marketplace reports a phone, the merchant corrects it, the marketplace reports another: the first one stays on the order
	 * as what the framework wrote, so the export carries it beside the latest one.
	 */
	public function test_it_exports_what_the_marketplace_reported_before_a_correction(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wc_sanitize_phone_number' )->returnArg();

		$order = new Stateful_Order();
		$first = ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )
			->with_recipient( 'Анна' )
			->with_phone( '+79991111111' )
			->with_address( 'Тверская 1' )
			->with_comment( 'домофон' );

		self::poll( $order, $first );

		foreach ( [ 'shipping_phone', 'billing_phone' ] as $field ) {
			$order->props[ $field ] = '+79992222222';
		}
		$order->props['shipping_address_1'] = 'Арбат 2';

		self::poll( $order, $first->with_phone( '+79993333333' )->with_address( 'Садовая 3' )->with_recipient( 'Ольга' )->with_comment( 'код 12' ) );

		$exported = array_column( ( new Order_Privacy( 'fake-seller' ) )->export( [], $order->order ), 'value', 'name' );

		$this->assertSame( '+79991111111', $exported['Телефон, записанный в заказ от маркетплейса'] );
		$this->assertSame( 'Тверская 1', $exported['Адрес, записанный в заказ от маркетплейса'] );
		$this->assertSame( 'Ольга', $exported['Получатель, записанный в заказ от маркетплейса'], 'A field nobody changed was written again.' );
		$this->assertSame( 'код 12', $exported['Комментарий, записанный в заказ от маркетплейса'] );
		$this->assertSame( '+79993333333', $exported['Телефоны (от маркетплейса)'] );
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

	private static function poll( Stateful_Order $order, Seller_Delivery $delivery ): void {
		( new Seller_Delivery_Writer() )->apply( $order->order, ( new Order_DTO( 1, 'u1', '', 'new', Status_Map::NEW, [] ) )->with_delivery( $delivery ) );
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

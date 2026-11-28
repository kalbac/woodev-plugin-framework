<?php
/**
 * Unit tests for the editable delivery of an order the seller delivers.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Order\Delivery_Schemes;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Delivery_Panel;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Tests\Unit\Marketplace\Support\Stateful_Order;
use Woodev\Tests\Unit\TestCase;

/**
 * The panel shows what the merchant must know, edits the recipient and address only, and saves only what the merchant changed, for a
 * request that carries its nonce from a user who may edit the order.
 *
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Delivery_Panel
 */
final class SellerDeliveryPanelTest extends TestCase {
	/**
	 * @var Stateful_Order
	 */
	private $order;

	/**
	 * Whether the user may edit the order.
	 *
	 * @var bool
	 */
	private $can = true;

	protected function setUp(): void {
		parent::setUp();

		$this->can   = true;
		$this->order = new Stateful_Order();

		Functions\when( 'current_user_can' )->alias(
			function (): bool {
				return $this->can;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action, $name ): string {
				return '<input type="hidden" name="' . $name . '" value="' . $action . '" />';
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return $nonce === $action ? 1 : false;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'map_deep' )->returnArg();
		Functions\when( 'wc_sanitize_phone_number' )->returnArg();
	}

	protected function tearDown(): void {
		$_POST = [];

		parent::tearDown();
	}

	public function test_an_order_the_marketplace_delivers_gets_no_panel(): void {
		$this->assertSame( '', $this->panel()->render( $this->order->order ) );

		$this->post( [ 'phone' => '+79000000000' ] );
		$this->assertFalse( $this->panel()->save( $this->order->order ) );
	}

	public function test_it_shows_the_fields_the_facts_and_the_marketplaces_value_beside_a_changed_one(): void {
		$this->seller_order(
			self::delivery()
				->with_other_phone( '+79993333333', '77' )
				->with_schedule( '2026-11-29', '02:00', '04:00' )
				->with_address( 'Тверская 1', '', '', true )
		);
		$this->order->props['shipping_phone']     = '+79000000000';
		$this->order->props['shipping_address_1'] = 'Тверская 1';

		$html = $this->panel()->render( $this->order->order );

		$this->assertStringContainsString( 'name="woodev_mp_delivery[phone]" value="+79000000000"', $html );
		$this->assertStringContainsString( 'Тест сообщает: +79991111111', $html );
		$this->assertStringNotContainsString( 'Тест сообщает: Тверская 1', $html );
		$this->assertStringContainsString( '29.11.2026 02:00–04:00', $html );
		$this->assertStringContainsString( 'это адрес пункта', $html );
		$this->assertStringContainsString( '+79993333333, добавочный 77', $html );
		$this->assertStringContainsString( 'name="woocommerce_meta_nonce"', $html );
		$this->assertStringContainsString( 'name="save"', $html );
		$this->assertStringNotContainsString( 'нужен добавочный', $html, 'The merchant replaced the number that needs it.' );
	}

	public function test_it_warns_of_a_missing_address_and_a_needed_extension(): void {
		$this->seller_order( ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )->with_phone( '+79991111111', '1234' )->with_address_note( 'Адрес недоступен: заказ принят в кабинете' ) );
		$this->order->props['shipping_phone'] = '+79991111111';

		$html = $this->panel()->render( $this->order->order );

		$this->assertStringContainsString( 'Адрес недоступен: заказ принят в кабинете', $html );
		$this->assertStringContainsString( 'нужен добавочный: 1234', $html );
	}

	public function test_it_saves_what_the_merchant_changed_to_both_sides(): void {
		$this->seller_order( self::delivery() );
		$this->order->props['shipping_first_name'] = 'Анна';
		$this->order->props['billing_first_name']  = 'Анна';
		$this->order->props['shipping_phone']      = '+79991111111';
		$this->order->props['billing_phone']       = '+79991111111';

		$this->post(
			[
				'first_name' => 'Анна',
				'phone'      => '+79000000000',
				'city'       => 'Москва',
				'total'      => '0',
			]
		);

		$this->assertTrue( $this->panel()->save( $this->order->order ) );
		$this->assertSame( '+79000000000', $this->order->props['shipping_phone'] );
		$this->assertSame( '+79000000000', $this->order->props['billing_phone'] );
		$this->assertSame( 'Москва', $this->order->props['shipping_city'] );
		$this->assertSame( 'Анна', $this->order->props['shipping_first_name'] );
		$this->assertSame( 1, $this->order->saves );
	}

	public function test_an_unchanged_form_saves_nothing(): void {
		$this->seller_order( self::delivery() );
		$this->order->props['billing_phone'] = '+79991111111';

		$this->post( [ 'phone' => '+79991111111', 'city' => '' ] );

		$this->assertFalse( $this->panel()->save( $this->order->order ) );
		$this->assertSame( '', $this->order->props['shipping_phone'] );
		$this->assertSame( 0, $this->order->saves );
	}

	public function test_it_saves_nothing_without_its_nonce_or_the_right(): void {
		$this->seller_order( self::delivery() );

		$this->post( [ 'phone' => '+79000000000' ], 'woodev_mp_delivery_1' );
		$this->assertFalse( $this->panel()->save( $this->order->order ) );

		$this->can = false;
		$this->post( [ 'phone' => '+79000000000' ] );
		$this->assertFalse( $this->panel()->save( $this->order->order ) );
		$this->assertSame( '', $this->order->props['shipping_phone'] );
	}

	public function test_it_names_the_scheme(): void {
		$this->assertSame( '', $this->panel()->scheme_name( $this->order->order ) );

		$this->order->meta[ Seller_Order::META_SCHEME ] = 'dbs';
		$this->assertSame( 'DBS', $this->panel()->scheme_name( $this->order->order ) );

		$this->order->meta[ Seller_Order::META_SCHEME ] = 'other';
		$this->assertSame( 'other', $this->panel()->scheme_name( $this->order->order ) );
	}

	private function seller_order( Seller_Delivery $delivery ): void {
		$this->order->meta[ Seller_Order::META_SELLER_DELIVERED ] = 'yes';
		$this->order->meta[ Seller_Order::META_DELIVERY ]         = $delivery->to_array();
	}

	private function post( array $fields, string $nonce = 'woodev_mp_delivery_700' ): void {
		$_POST = [
			Seller_Delivery_Panel::NONCE_FIELD => $nonce,
			Seller_Delivery_Panel::INPUT       => $fields,
		];
	}

	private function panel(): Seller_Delivery_Panel {
		$adapter = Mockery::mock( Marketplace_Adapter::class . ', ' . Delivery_Schemes::class );
		$adapter->shouldReceive( 'delivery_schemes' )->andReturn( [ 'fbs' => 'FBS', 'dbs' => 'DBS' ] );

		return new Seller_Delivery_Panel( new Screen_Names( 'fake', 'Тест' ), $adapter );
	}

	private static function delivery(): Seller_Delivery {
		return ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )->with_recipient( 'Анна' )->with_phone( '+79991111111' )->with_address( 'Тверская 1' );
	}
}

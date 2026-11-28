<?php
/**
 * «Оформить доставку» for the orders a marketplace's seller delivers: the entry the shipping module asks, and what the order
 * screen and the «Заказы» table say about the delivery service.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Order\Carrier_Delivery_Entry;
use Woodev\Framework\Marketplace\Admin\Order\Carrier_Panel;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Carrier;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Seller_Adapter;
use Woodev\Framework\Marketplace\Admin\Shared\Carrier_State;
use Woodev\Framework\Marketplace\Flows\Carrier_Notice;
use Woodev\Framework\Marketplace\Flows\Order_Unit;
use Woodev\Framework\Marketplace\Flows\Order_Units;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Carrier_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Stateful_Order;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Carrier_Delivery_Entry
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Carrier_Panel
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Carrier_State
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Carrier
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Delivery_Panel::extension_warning
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Delivery_Panel::chosen_time
 */
final class CarrierDeliveryTest extends Carrier_Test_Case {
	/**
	 * Whether the user may open the orders page.
	 *
	 * @var bool
	 */
	private $may_open = true;

	protected function setUp(): void {
		parent::setUp();

		$this->may_open = true;

		Functions\when( 'current_user_can' )->alias(
			function (): bool {
				return $this->may_open;
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, string $key ) {
				return isset( $this->orders[ (int) $id ] ) ? ( $this->orders[ (int) $id ]->meta[ $key ] ?? '' ) : '';
			}
		);
		Functions\when( 'wc_get_order_status_name' )->returnArg();
		Functions\when( 'wc_sanitize_phone_number' )->returnArg();
		Functions\when( 'esc_attr' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html' )->alias( 'htmlspecialchars' );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	public function test_the_entry_claims_the_orders_the_marketplaces_seller_delivers_only(): void {
		$entry = $this->entry();

		$this->assertTrue( $entry->claims( $this->order( 700 )->order ) );
		$this->assertFalse( $entry->claims( $this->delivery_order( 701, [], false )->order ) );
		$this->assertFalse( $entry->claims( $this->delivery_order( 702, [], true, 'other-seller' )->order ) );

		$this->adapter->table = [];
		$this->assertFalse( $entry->claims( $this->orders[700]->order ), 'An adapter that names no hand-over has no deliveries to arrange.' );
	}

	public function test_a_delivery_is_refused_until_the_marketplace_takes_the_hand_over(): void {
		$order = $this->order( 700, 'new' );

		$this->assertSame( 'Сейчас заказ в статусе «Статус new»: доставку можно оформить, когда доступно действие «Передать в доставку».', $this->entry()->refusal_reason( $order->order ) );

		$this->move( $order, 'confirm' );

		$this->assertSame( '', $this->entry()->refusal_reason( $order->order ) );
	}

	public function test_a_grouped_order_waits_until_every_unit_can_be_handed_over(): void {
		$order = $this->order( 700 );
		$this->units( $order, [ [ 'u-1', 'Кружка', 'confirm' ], [ 'u-2', 'Блюдце', 'new' ] ] );

		$this->assertSame(
			'Не все товары заказа готовы: «Блюдце» — в статусе «Статус new». Доставку можно оформить, когда действие «Передать в доставку» доступно для всех товаров заказа.',
			$this->entry()->refusal_reason( $order->order )
		);

		$this->move( $order, 'confirm' );

		$this->assertSame( '', $this->entry()->refusal_reason( $order->order ) );
	}

	public function test_a_cancelled_unit_and_one_past_the_hand_over_do_not_hold_the_delivery(): void {
		$order = $this->order( 700 );
		// The cancelled unit lists every action; the delivered one no longer lists the hand-over.
		$this->units( $order, [ [ 'u-1', 'Кружка', 'confirm' ], [ 'u-2', 'Блюдце', 'cancelled' ], [ 'u-3', 'Ложка', 'sold', [ 'receive' ] ] ] );
		$order->meta[ Order_Units::META ] = [ ( new Order_Unit( 'u-2', 'cancelled', '2026-11-27 10:00:00', [] ) )->to_array() ];

		$this->assertSame( '', $this->entry()->refusal_reason( $order->order ) );

		$this->units( $order, [ [ 'u-2', 'Блюдце', 'cancelled' ] ] );

		$this->assertStringContainsString( 'для этого заказа недоступно', $this->entry()->refusal_reason( $order->order ) );
	}

	public function test_a_delivery_is_refused_without_a_licence_or_a_switched_on_account(): void {
		$order          = $this->order( 700 );
		$this->licensed = false;

		$this->assertNotSame( '', $this->entry()->refusal_reason( $order->order ) );

		$this->licensed = true;
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->assertStringContainsString( 'выключено', $this->entry()->refusal_reason( $order->order ) );
	}

	public function test_an_order_whose_account_is_gone_cannot_get_a_delivery(): void {
		$order = $this->order( 700 );
		$this->db->query( 'DELETE FROM ' . Sqlite_Tables::table( 'postings' ) );

		$this->assertStringContainsString( 'не найдено', $this->entry()->refusal_reason( $order->order ) );
	}

	public function test_the_wizard_is_told_about_the_extension_the_chosen_day_and_the_pickup_point(): void {
		$order                                      = $this->order( 700 );
		$order->props['shipping_phone']             = '+79990001122';
		$order->meta[ Seller_Order::META_DELIVERY ] = ( new Seller_Delivery( 'Маркет', 'Доставка Маркет' ) )
			->with_phone( '+79990001122', '4455' )
			->with_schedule( '2026-12-05', '10:00', '14:00' )
			->with_address( 'Москва, Тверская, 1', '', '', true )
			->to_array();

		$this->assertSame(
			[
				'Для связи по этому номеру нужен добавочный: 4455. Служба доставки не наберёт его сама.',
				'Покупатель выбрал доставку: 05.12.2026 10:00–14:00.',
				'Доставка в пункт выдачи: это адрес пункта, а не покупателя.',
			],
			$this->entry()->notices( $order->order )
		);

		$order->props['shipping_phone'] = '+79995556677';
		$this->assertCount( 2, $this->entry()->notices( $order->order ), 'A number the merchant typed needs no extension.' );
		$this->assertSame( [], $this->entry()->notices( $this->order( 701 )->order ) );
	}

	public function test_the_button_opens_the_wizard_while_a_delivery_plugin_is_active(): void {
		$order = $this->order( 700 );
		$this->register_delivery_plugin();

		$button = $this->state()->button( $order->order );

		$this->assertSame( [ false, 'Оформить доставку', '' ], [ $button['changes'], $button['label'], $button['reason'] ] );
		$this->assertStringContainsString( 'deliver_order=700', $button['url'] );
		$this->assertStringContainsString( '<a class="button" href="', ( new Carrier_Panel( $this->state() ) )->button( $order->order ) );
	}

	public function test_there_is_no_button_without_a_delivery_plugin_or_the_right_to_open_it(): void {
		$order = $this->order( 700 );
		Orders_Registry::instance()->register_delivery_entry( $this->entry() );

		$this->assertNull( $this->state()->button( $order->order ) );

		$this->register_delivery_plugin();
		$this->may_open = false;

		$this->assertNull( $this->state()->button( $order->order ) );
	}

	public function test_the_button_is_off_with_the_reason_before_the_hand_over_is_available(): void {
		$order = $this->order( 700, 'new' );
		$this->register_delivery_plugin();

		$button = $this->state()->button( $order->order );

		$this->assertSame( '', $button['url'] );
		$this->assertStringContainsString( '«Передать в доставку»', $button['reason'] );
		$this->assertStringContainsString( 'disabled', ( new Carrier_Panel( $this->state() ) )->button( $order->order ) );
	}

	public function test_a_chosen_delivery_service_is_changed_until_the_shipment_is_created(): void {
		$order = $this->order( 700 );
		$this->register_delivery_plugin();
		$order->meta['_cdek_marker'] = '1';

		$this->assertSame( [ true, 'Изменить доставку' ], array_slice( array_values( $this->state()->button( $order->order ) ), 0, 2 ) );

		$order->meta['_cdek_carrier_order_id'] = 'CDEK-1';

		$this->assertNull( $this->state()->button( $order->order ) );
	}

	public function test_the_order_screen_shows_the_delivery_service_and_its_tracking_number(): void {
		$order = $this->order( 700 );
		$this->register_delivery_plugin();
		$panel = new Carrier_Panel( $this->state() );

		$this->assertSame( '', $panel->render( $order->order ) );

		$order->meta['_cdek_marker'] = '1';
		$order->meta['_cdek_track']  = '1234567890';

		$html = $panel->render( $order->order );

		$this->assertStringContainsString( 'СДЭК', $html );
		$this->assertStringContainsString( '>1234567890</a>', $html );
		$this->assertStringContainsString( 'https://cdek.example/1234567890', $html );
	}

	public function test_a_notice_shows_only_while_its_action_is_still_on_offer(): void {
		$order = $this->order( 700, 'deliver' );
		Carrier_Notice::put( $order->order, 'receive', 'Подтвердите вручение: нужен код покупателя.' );

		$this->assertSame( 'Подтвердите вручение: нужен код покупателя.', $this->state()->notice( $order->order ) );
		$this->assertStringContainsString( 'notice-warning', ( new Carrier_Panel( $this->state() ) )->render( $order->order ) );

		$this->move( $order, 'sold' );

		$this->assertSame( '', $this->state()->notice( $order->order ) );
	}

	public function test_the_table_gets_the_link_and_the_notice_of_a_row(): void {
		$order = $this->order( 700 );
		$this->register_delivery_plugin();
		$table = new Site_Orders_Carrier( $this->state() );
		$open  = [
			[ 'id' => 'open' ],
			[ 'id' => 'cancel' ],
		];

		$definition = $table->extend_definition( [ 'row_actions' => $open ] );

		$this->assertSame( [ 'open', 'delivery', 'delivery-change', 'cancel' ], array_column( $definition['row_actions'], 'id' ) );
		$this->assertSame( [ 'open', 'delivery', 'cancel' ], array_column( $table->with_link( $open, $order->order ), 'id' ) );

		$this->move( $order, 'deliver' );
		Carrier_Notice::put( $order->order, 'reject', 'Заказ возвращается.' );

		$this->assertSame(
			[
				[
					'type'  => 'badge',
					'value' => [
						'label'   => 'Проверьте',
						'tone'    => 'warning',
						'tooltip' => 'Заказ возвращается.',
					],
				],
			],
			$table->with_notice( [], $order->order )
		);
	}

	public function test_a_refused_link_keeps_an_address_so_the_table_shows_it_off_with_the_reason(): void {
		$order = $this->order( 700, 'new' );
		$this->register_delivery_plugin();
		$order->order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.test/wp-admin/post.php?post=700' );

		$link = ( new Site_Orders_Carrier( $this->state() ) )->with_link( [ [ 'id' => 'open' ] ], $order->order )[1];

		$this->assertSame( [ 'delivery', false, 'https://example.test/wp-admin/post.php?post=700' ], [ $link['id'], $link['enabled'], $link['url'] ] );
		$this->assertStringContainsString( '«Передать в доставку»', $link['reason'] );
	}

	public function test_a_site_without_a_delivery_plugin_gets_the_table_it_had(): void {
		$table      = new Site_Orders_Carrier( $this->state() );
		$definition = [ 'row_actions' => [ [ 'id' => 'open' ] ] ];
		$order      = $this->order( 700 );

		$this->assertSame( $definition, $table->extend_definition( $definition ) );
		$this->assertSame( $definition['row_actions'], $table->with_link( $definition['row_actions'], $order->order ) );
		$this->assertSame( [], $table->with_notice( [], $order->order ) );
	}

	/**
	 * Replaces the units of an order.
	 *
	 * @param Stateful_Order          $order Order.
	 * @param array<int,array<mixed>> $units Units: id, label, status, and the actions that exist for it.
	 * @return void
	 */
	private function units( Stateful_Order $order, array $units ): void {
		$order->meta[ Fake_Seller_Adapter::META_UNITS ] = $units;
	}

	/**
	 * A seller-delivered order in one status.
	 *
	 * @param int    $id     Order id.
	 * @param string $status Status of its goods.
	 * @return Stateful_Order
	 */
	private function order( int $id, string $status = 'confirm' ): Stateful_Order {
		return $this->delivery_order( $id, [ [ 'u-1', 'Кружка', $status ] ] );
	}

	/**
	 * Registers a carrier plugin that takes orders from the admin, and the marketplace's delivery entry.
	 *
	 * @return void
	 */
	private function register_delivery_plugin(): void {
		$registry = Orders_Registry::instance();

		$registry->register_provider(
			Orders_Provider::create(
				'cdek',
				'СДЭК',
				'_cdek_marker',
				[ 'cdek_courier' ],
				[
					'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
					'tracking_meta_key'         => '_cdek_track',
					'tracking_url_template'     => 'https://cdek.example/{tracking}',
					'marker_writer'             => static function (): void {
					},
				]
			)
		);
		$registry->register_delivery_entry( $this->entry() );
	}

	private function entry(): Carrier_Delivery_Entry {
		return new Carrier_Delivery_Entry( $this->adapter, $this->runner(), $this->registry(), $this->accounts(), self::CREATED_VIA );
	}

	private function state(): Carrier_State {
		return new Carrier_State( $this->adapter, self::CREATED_VIA );
	}
}

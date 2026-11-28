<?php
/**
 * Unit: «Оформить доставку» for an order another module owns (#2010).
 *
 * What a {@see Delivery_Entry} opens and what it does not: the gate (404 / 409), the delivery-only prefill, the payload checks
 * that ignore customer, items and payment, the row action, the link and the seller's cost on the order screen. The writes
 * themselves are proven on both datastores by `SellerDeliveredOrdersTest` (integration).
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\{Delivery_Entry, Order_Actions, Order_Editor, Order_Payload_Validator, Orders_Provider, Orders_Registry};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Order/order-persistence-fixtures.php';
require_once __DIR__ . '/order-editor-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * An entry that claims every order, with a refusal and notices the test sets.
 */
final class Fake_Delivery_Entry implements Delivery_Entry {

	/** @var bool */
	public $claims = true;

	/** @var string */
	public $refusal = '';

	/** @var string[] */
	public $notices = [];

	public function claims( \WC_Order $order ): bool {
		return $this->claims;
	}

	public function refusal_reason( \WC_Order $order ): string {
		return $this->refusal;
	}

	public function notices( \WC_Order $order ): array {
		return $this->notices;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::load
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::update
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Payload_Validator::validate_delivery
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::get_delivery_url
 */
final class OrderEditorDeliveryTest extends TestCase {

	/** @var array<string,mixed> post meta of the order, by meta key. */
	private $meta = [];

	/** @var Fake_Delivery_Entry */
	private $entry;

	protected function setUp(): void {
		parent::setUp();

		$this->meta  = [];
		$this->entry = new Fake_Delivery_Entry();
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];
		$GLOBALS['wpdb'] = new Order_Editor_Fake_Wpdb();

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_clean' )->alias( static fn( $value ) => is_string( $value ) ? trim( $value ) : $value );
		Functions\when( 'wc_sanitize_phone_number' )->alias( static fn( string $phone ): string => (string) preg_replace( '/[^\d+]/', '', $phone ) );
		Functions\when( 'wc_format_decimal' )->alias( static fn( $number ): string => (string) $number );
		Functions\when( 'wc_format_postcode' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => strip_tags( $text ) );
		Functions\when( 'get_post_meta' )->alias( fn( int $post_id, string $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'wc_get_order_status_name' )->alias( static fn( string $status ): string => ucfirst( $status ) );

		Orders_Registry::instance()->reset_for_tests();
		Orders_Registry::instance()->register_provider( $this->provider() );
		Orders_Registry::instance()->register_delivery_entry( $this->entry );
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek_courier', 'cdek_pickup' ],
			[
				'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
				'marker_writer'             => static function ( \WC_Order $order, array $context ): void {
				},
			]
		);
	}

	private function editor(): Order_Editor {
		$registry = Orders_Registry::instance();

		return new Order_Editor( $registry, $this->validator() );
	}

	private function validator(): Order_Payload_Validator {
		return new Order_Payload_Validator(
			Orders_Registry::instance(),
			[
				'pickup_method_ids' => static fn(): array => [ 'cdek_pickup' ],
				'countries'         => static fn(): array => [ 'RU' => 'Россия' ],
				'states'            => static fn(): array => [],
				'address_rules'     => static fn(): array => [
					'city'      => [
						'required' => true,
						'hidden'   => false,
						'removed'  => false,
					],
					'address_2' => [
						'required' => false,
						'hidden'   => true,
						'removed'  => true,
					],
				],
			]
		);
	}

	/**
	 * An order as the module that owns it wrote it: the address is one line, the recipient and the phone sit on both sides.
	 *
	 * @param array<string,mixed> $override getters to answer differently.
	 * @return \WC_Order
	 */
	private function order( array $override = [] ): \WC_Order {
		$values = $override + [
			'get_id'                  => 123,
			'get_order_number'        => '123',
			'get_status'              => 'processing',
			'get_total'               => '201.00',
			'get_currency'            => 'RUB',
			'get_customer_id'         => 0,
			'is_paid'                 => true,
			'get_payment_method'      => '',
			'get_billing_email'       => '',
			'get_billing_first_name'  => 'Анна',
			'get_billing_phone'       => '+79991111111',
			'get_shipping_address_1'  => 'Москва, Тверская 1',
			'get_shipping_first_name' => '',
			'get_items'               => [],
			'get_shipping_methods'    => [],
		];
		$order  = Mockery::mock( '\WC_Order' );

		foreach ( [ 'billing', 'shipping' ] as $section ) {
			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ] as $key ) {
				$values += [ "get_{$section}_{$key}" => '' ];
			}
		}

		foreach ( $values as $method => $value ) {
			$order->shouldReceive( $method )->andReturn( $value );
		}

		$order->shouldReceive( 'read_meta_data' )->andReturnNull();
		$order->shouldNotReceive( 'save' );

		return $order;
	}

	private function status_of( $result ): int {
		$this->assertInstanceOf( \WP_Error::class, $result );

		return (int) $result->get_error_data()['status'];
	}

	public function test_a_claimed_order_without_a_carrier_loads_the_delivery_prefill(): void {
		$this->entry->notices = [ 'Нужен добавочный: 123.' ];
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		$prefill = $this->editor()->load( 123 );

		$this->assertSame( 'delivery', $prefill['entry'] );
		$this->assertSame( [ 'Нужен добавочный: 123.' ], $prefill['notices'] );
		$this->assertSame( '', $prefill['carrier'] );
		$this->assertNull( $prefill['shipping_line'] );
		$this->assertSame( 'Москва, Тверская 1', $prefill['shipping']['address_1'] );
		$this->assertSame( 'Анна', $prefill['shipping']['first_name'], 'The recipient falls back to the billing side.' );
		$this->assertSame( '+79991111111', $prefill['shipping']['phone'] );
	}

	public function test_a_line_whose_product_is_gone_is_sent_without_a_product_and_said_so(): void {
		$item = Mockery::mock( '\WC_Order_Item_Product' );
		$item->shouldReceive( 'get_quantity' )->andReturn( 1 );
		$item->shouldReceive( 'get_product_id' )->andReturn( 55 );
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
		$item->shouldReceive( 'get_name' )->andReturn( 'Товар' );
		$item->shouldReceive( 'get_subtotal' )->andReturn( '100' );
		Functions\when( 'wc_get_order' )->justReturn( $this->order( [ 'get_items' => [ 7 => $item ] ] ) );
		Functions\when( 'wc_get_product' )->justReturn( false );
		Functions\when( 'wc_get_rounding_precision' )->justReturn( 6 );

		$prefill = $this->editor()->load( 123 );

		$this->assertSame( 0, $prefill['items'][0]['product_id'] );
		$this->assertCount( 1, $prefill['notices'] );
		$this->assertStringContainsString( 'есть в магазине', $prefill['notices'][0] );
	}

	public function test_a_saved_carrier_line_is_prefilled_with_the_sellers_cost_not_its_zero_total(): void {
		$line = Mockery::mock( '\WC_Order_Item_Shipping' );
		$line->shouldReceive( 'get_method_id' )->andReturn( 'cdek_courier' );
		$line->shouldReceive( 'get_instance_id' )->andReturn( 3 );
		$line->shouldReceive( 'get_name' )->andReturn( 'Курьер' );
		$line->shouldReceive( 'get_total' )->andReturn( '0' );
		$line->shouldReceive( 'get_meta_data' )->andReturn(
			[
				(object) [
					'key'   => Order_Editor::SELLER_COST_ITEM_META,
					'value' => '350',
				],
				(object) [
					'key'   => 'tariff',
					'value' => '137',
				],
			]
		);
		$this->meta['_cdek_marker'] = '1';
		Functions\when( 'wc_get_order' )->justReturn( $this->order( [ 'get_shipping_methods' => [ 9 => $line ] ] ) );

		$prefill = $this->editor()->load( 123 );

		$this->assertSame( 'cdek', $prefill['carrier'] );
		$this->assertSame( '350', $prefill['shipping_line']['cost'] );
		$this->assertSame( [ 'tariff' => '137' ], $prefill['shipping_line']['meta'] );
	}

	public function test_the_entrys_refusal_is_a_409_in_its_own_words(): void {
		$this->entry->refusal = 'Сначала примите заказ в работу.';
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		foreach ( [ $this->editor()->load( 123 ), $this->editor()->update( 123, [] ) ] as $result ) {
			$this->assertSame( 409, $this->status_of( $result ) );
			$this->assertSame( 'Сначала примите заказ в работу.', $result->get_error_message() );
		}
	}

	public function test_a_claimed_order_in_a_final_status_or_already_exported_is_refused(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( [ 'get_status' => 'completed' ] ) );

		$this->assertSame( 409, $this->status_of( $this->editor()->load( 123 ) ) );

		$this->meta['_cdek_marker']           = '1';
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		$result = $this->editor()->update( 123, [] );

		$this->assertSame( 409, $this->status_of( $result ) );
		$this->assertStringContainsString( 'отмените выгрузку', $result->get_error_message() );
	}

	public function test_an_order_no_entry_claims_and_no_carrier_owns_stays_a_404(): void {
		$this->entry->claims = false;
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		$this->assertSame( 404, $this->status_of( $this->editor()->load( 123 ) ) );
	}

	public function test_a_claimed_order_is_checked_for_its_delivery_only(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order() );

		$result = $this->editor()->update( 123, [] );
		$fields = array_column( $result->get_error_data()['errors'], 'field' );

		$this->assertSame( 422, $this->status_of( $result ) );
		$this->assertSame( [ 'shipping.country', 'shipping_line' ], $fields, 'No customer, items, payment or status is asked of such an order.' );
	}

	public function test_the_delivery_check_ignores_the_rest_of_the_body_and_keeps_a_removed_field(): void {
		$result = $this->validator()->validate_delivery(
			[
				'shipping'      => [
					'country'   => 'ru',
					'city'      => 'Москва',
					'address_1' => 'Тверская 1',
					'address_2' => 'кв. 5',
					'phone'     => '+7 (999) 111-11-11',
				],
				'shipping_line' => [
					'method_id'   => 'cdek_courier',
					'instance_id' => 3,
					'cost'        => 350,
					'label'       => 'Курьер',
				],
				'items'         => [],
				'status'        => 'completed',
				'customer'      => [ 'id' => 999 ],
			]
		);

		$this->assertSame( [], $result['errors'] );
		$this->assertSame( [ 'shipping', 'shipping_line', 'pickup_point', 'fields', 'carrier_fields', 'location' ], array_keys( $result['data'] ) );
		$this->assertSame( 'кв. 5', $result['data']['shipping']['address_2'] );
		$this->assertSame( '+79991111111', $result['data']['shipping']['phone'] );
		$this->assertSame( '350', $result['data']['shipping_line']['cost'] );
	}

	public function test_the_delivery_check_asks_for_the_required_address_and_the_pickup_point(): void {
		$result = $this->validator()->validate_delivery(
			[
				'shipping'      => [ 'country' => 'RU' ],
				'shipping_line' => [
					'method_id' => 'cdek_pickup',
					'cost'      => 200,
				],
			]
		);

		$this->assertSame( [ 'pickup_point.id', 'shipping.city' ], array_column( $result['errors'], 'field' ) );
	}

	public function test_the_row_action_names_the_delivery_and_goes_away_when_the_entry_refuses(): void {
		$actions = new Order_Actions( Orders_Registry::instance() );

		$row = $actions->for_row( $this->order(), $this->provider() );

		$this->assertSame( [ Order_Actions::EDIT ], array_column( $row, 'action' ) );
		$this->assertSame( 'Изменить доставку', $row[0]['label'] );

		$this->entry->refusal = 'Заказ отменён покупателем.';

		$this->assertSame( [], $actions->for_row( $this->order(), $this->provider() ) );

		$this->entry->claims = false;

		$this->assertSame( 'Редактировать', $actions->for_row( $this->order(), $this->provider() )[0]['label'] );
	}

	public function test_the_link_opens_the_orders_page_on_the_order(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->alias( static fn( string $path ): string => 'https://shop.test/wp-admin/' . $path );

		$this->assertSame(
			'https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Fwoodev-shipping-orders&deliver_order=123',
			Orders_Registry::instance()->get_delivery_url( $this->order() )
		);
	}

	public function test_there_is_no_link_when_there_is_nothing_to_open(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg( 1 );
		$registry = Orders_Registry::instance();

		$this->entry->refusal = 'Позже.';
		$this->assertSame( '', $registry->get_delivery_url( $this->order() ), 'the entry refuses' );

		$this->entry->refusal = '';
		$this->entry->claims  = false;
		$this->assertSame( '', $registry->get_delivery_url( $this->order() ), 'no entry claims the order' );

		$registry->reset_for_tests();
		$registry->register_delivery_entry( new Fake_Delivery_Entry() );
		$this->assertSame( '', $registry->get_delivery_url( $this->order() ), 'no carrier plugin' );
	}

	public function test_a_user_who_may_not_open_the_page_gets_no_link(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', Orders_Registry::instance()->get_delivery_url( $this->order() ) );
	}

	public function test_the_sellers_cost_reads_as_words_and_money_on_the_order_screen(): void {
		Functions\when( 'wc_price' )->alias( static fn( float $amount, array $args ): string => $amount . ' ' . $args['currency'] );
		$item = Mockery::mock( '\WC_Order_Item' );
		$item->shouldReceive( 'get_order' )->andReturn( $this->order() );
		$cost = (object) [
			'key'   => Order_Editor::SELLER_COST_ITEM_META,
			'value' => '350',
		];

		$this->assertSame( 'Ваш расход на доставку', Order_Editor::seller_cost_meta_label( $cost->key, $cost ) );
		$this->assertSame( '350 RUB', Order_Editor::seller_cost_meta_value( '350', $cost, $item ) );

		$other = (object) [
			'key'   => 'tariff',
			'value' => '137',
		];

		$this->assertSame( 'tariff', Order_Editor::seller_cost_meta_label( 'tariff', $other ) );
		$this->assertSame( '137', Order_Editor::seller_cost_meta_value( '137', $other, $item ) );
	}
}

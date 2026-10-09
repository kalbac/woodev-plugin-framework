<?php
/**
 * Unit: Order_Row_Builder — the row payload (SP-10 increment 2a, spec M1/D3/D4).
 *
 * DATA ONLY: pins the field GROUPS the row carries, not any markup — this class never
 * renders HTML (that is the page shell's job in 2b).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Carrier_Cancel;
use Woodev\Framework\Shipping\Order\Delivery_Status;

require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/order/class-delivery-status.php';
// #1000: every row now reads the order's edit lock, so the lock's WordPress / WooCommerce doubles must be loaded.
require_once __DIR__ . '/Shipping/Admin/order-edit-lock-fixtures.php';
require_once __DIR__ . '/Shipping/Admin/order-edit-lock-cpt-fixtures.php';

class OrderRowBuilderTest extends TestCase {

	/** @var array<string,mixed> post meta, keyed by meta key, for the active test. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		// Order_Row_Builder's default constructor (card #824) wires an Order_Actions
		// against this process-wide singleton; a handler another test forgot to clean
		// up would otherwise leak into this file's rows.
		Orders_Registry::instance()->reset_for_tests();

		$this->meta = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_edit_user_link' )->alias( static function ( int $user_id ): string {
			return "https://example.test/wp-admin/user-edit.php?user_id={$user_id}";
		} );
		Functions\when( 'wc_price' )->alias( static function ( $amount ): string {
			return (string) $amount;
		} );
		// Mirrors wp-includes/formatting.php: drop script/style wholesale, then tags.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text, bool $remove_breaks = false ): string {
				$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
				$text = strip_tags( $text );

				if ( $remove_breaks ) {
					$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
				}

				return trim( $text );
			}
		);
		Functions\when( 'wc_get_order_status_name' )->alias( static function ( string $status ): string {
			return ucfirst( $status );
		} );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Builds a WC_Order double with sane defaults, overridable per test.
	 *
	 * @param array<string,mixed> $overrides getter name => return value.
	 * @return \WC_Order
	 */
	private function make_order( array $overrides = [] ): \WC_Order {
		$created = Mockery::mock( '\WC_DateTime' );
		$created->shouldReceive( 'date' )->with( \DATE_ATOM )->andReturn( '2026-09-07T12:00:00+00:00' );

		$defaults = [
			'get_id'                          => 123,
			'get_order_number'                => '123',
			'get_edit_order_url'               => 'https://example.test/wp-admin/post.php?post=123&action=edit',
			'get_status'                       => 'processing',
			'get_date_created'                => $created,
			'get_formatted_billing_full_name' => 'Иван Иванов',
			'get_customer_id'                  => 0,
			'get_billing_email'                => 'ivan@example.test',
			'get_billing_phone'                => '+79991234567',
			'get_payment_method_title'          => 'Банковская карта',
			'get_formatted_order_total'         => '1000 руб.',
			'needs_payment'                     => false,
			'get_shipping_method'               => 'СДЭК',
			'get_shipping_total'                => '300',
			'get_shipping_postcode'             => '',
			'get_shipping_state'                => '',
			'get_shipping_city'                 => '',
			'get_shipping_address_1'            => '',
			'get_billing_postcode'              => '',
			'get_billing_state'                 => '',
			'get_billing_city'                  => '',
			'get_billing_address_1'             => '',
			'get_shipping_methods'              => [],
		];

		$values = array_merge( $defaults, $overrides );

		$order = Mockery::mock( '\WC_Order' );
		foreach ( $values as $method => $value ) {
			$order->shouldReceive( $method )->andReturn( $value );
		}

		return $order;
	}

	private function provider( array $args = [], array $method_ids = [ 'cdek' ] ): Orders_Provider {
		return Orders_Provider::create( 'cdek', 'СДЭК', '_wc_edostavka_shipping', $method_ids, $args );
	}

	/**
	 * A row builder whose `type` resolution is pinned to a given fake shipping-method
	 * instance, bypassing the real `WC_Shipping_Zones::get_shipping_method()` — which
	 * does live DB lookups and is exercised for real only by the integration suite.
	 */
	private function row_builder_resolving_method_to( $method ): Order_Row_Builder {
		return new class( $method ) extends Order_Row_Builder {
			private $method;

			public function __construct( $method ) {
				parent::__construct();
				$this->method = $method;
			}

			protected function get_shipping_method_by_instance_id( int $instance_id ) {
				return $this->method;
			}
		};
	}

	// ----- customer -----

	public function test_customer_uses_billing_name_email_phone_and_user_link(): void {
		$order = $this->make_order( [ 'get_customer_id' => 42 ] );

		$row = ( new Order_Row_Builder() )->build( $order, null );

		$this->assertSame( 'Иван Иванов', $row['customer']['name'] );
		$this->assertSame( 'ivan@example.test', $row['customer']['email'] );
		$this->assertSame( '+79991234567', $row['customer']['phone'] );
		$this->assertSame( 42, $row['customer']['user_id'] );
		$this->assertSame( 'https://example.test/wp-admin/user-edit.php?user_id=42', $row['customer']['user_edit_url'] );
	}

	/**
	 * M1: the fallback to `display_name` when the billing name is empty.
	 */
	public function test_customer_falls_back_to_display_name_when_billing_name_is_empty(): void {
		$order = $this->make_order(
			[
				'get_formatted_billing_full_name' => '',
				'get_customer_id'                 => 7,
			]
		);

		$user           = new \stdClass();
		$user->display_name = 'ivan petrov';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$row = ( new Order_Row_Builder() )->build( $order, null );

		$this->assertSame( 'Ivan Petrov', $row['customer']['name'] );
	}

	public function test_customer_has_no_edit_url_for_a_guest(): void {
		$order = $this->make_order( [ 'get_customer_id' => 0 ] );

		$row = ( new Order_Row_Builder() )->build( $order, null );

		$this->assertSame( 0, $row['customer']['user_id'] );
		$this->assertNull( $row['customer']['user_edit_url'] );
	}

	// ----- payment -----

	public function test_payment_carries_title_total_and_needs_payment_only(): void {
		$order = $this->make_order( [ 'needs_payment' => true ] );

		$row = ( new Order_Row_Builder() )->build( $order, null );

		$this->assertSame(
			[
				'method_title'    => 'Банковская карта',
				'formatted_total' => '1000 руб.',
				'needs_payment'   => true,
			],
			$row['payment']
		);
	}

	// ----- shipping / destination -----

	public function test_shipping_carries_method_and_total(): void {
		$order = $this->make_order();

		$row = ( new Order_Row_Builder() )->build( $order, null );

		$this->assertSame( 'СДЭК', $row['shipping']['method_title'] );
		$this->assertSame( '300', $row['shipping']['formatted_total'] );
	}

	/**
	 * The row is JSON consumed by React, which escapes what it is given — so money
	 * has to reach it as display TEXT. `wc_price()` and `get_formatted_order_total()`
	 * both return MARKUP, and until s126 that markup was printed verbatim in the
	 * «Доставка» and «Оплата» columns.
	 *
	 * The other tests here mock `wc_price()` down to a bare amount, so every one of
	 * them stayed green through that defect. This one feeds the real shape.
	 */
	public function test_money_reaches_the_row_as_plain_text_never_markup(): void {
		Functions\when( 'wc_price' )->alias(
			static function ( $amount ): string {
				return '<span class="woocommerce-Price-amount amount"><bdi>' . $amount
					. ',00&nbsp;<span class="woocommerce-Price-currencySymbol" translate="no">&#8381;</span></bdi></span>';
			}
		);

		$order = $this->make_order(
			[
				'get_formatted_order_total' => '<span class="woocommerce-Price-amount amount"><bdi>2 400,00&nbsp;'
					. '<span class="woocommerce-Price-currencySymbol" translate="no">&#8381;</span></bdi></span>',
			]
		);

		$row = ( new Order_Row_Builder() )->build( $order, null );

		// U+00A0 before the symbol is WooCommerce's own separator and is preserved.
		$this->assertSame( "300,00\u{00A0}\u{20BD}", $row['shipping']['formatted_total'] );
		$this->assertSame( "2 400,00\u{00A0}\u{20BD}", $row['payment']['formatted_total'] );
	}

	public function test_destination_falls_back_to_shipping_address_when_no_pickup_point(): void {
		$order = $this->make_order(
			[
				'get_shipping_postcode'  => '123456',
				'get_shipping_state'     => 'МО',
				'get_shipping_city'      => 'Москва',
				'get_shipping_address_1' => 'ул. Ленина, 1',
			]
		);

		$row = ( new Order_Row_Builder() )->build( $order, $this->provider() );

		$this->assertSame( 'address', $row['shipping']['destination_kind'] );
		$this->assertSame( '123456, МО, Москва, ул. Ленина, 1', $row['shipping']['destination_text'] );
	}

	public function test_destination_falls_back_to_billing_address_when_shipping_address_is_empty(): void {
		$order = $this->make_order(
			[
				'get_billing_postcode'  => '654321',
				'get_billing_state'     => 'СПб',
				'get_billing_city'      => 'Санкт-Петербург',
				'get_billing_address_1' => 'Невский пр., 1',
			]
		);

		$row = ( new Order_Row_Builder() )->build( $order, $this->provider() );

		$this->assertSame( 'address', $row['shipping']['destination_kind'] );
		$this->assertSame( '654321, СПб, Санкт-Петербург, Невский пр., 1', $row['shipping']['destination_text'] );
	}

	public function test_destination_prefers_a_populated_pickup_point(): void {
		$this->meta['_wc_edostavka_pickup_point'] = [ 'address' => 'ПВЗ, ул. Мира, 5' ];

		$order    = $this->make_order(
			[
				'get_shipping_postcode'  => '123456',
				'get_shipping_state'     => 'МО',
				'get_shipping_city'      => 'Москва',
				'get_shipping_address_1' => 'ул. Ленина, 1',
			]
		);
		$provider = $this->provider( [ 'pickup_point_meta_key' => '_wc_edostavka_pickup_point' ] );

		$row = ( new Order_Row_Builder() )->build( $order, $provider );

		$this->assertSame( 'pickup', $row['shipping']['destination_kind'] );
		$this->assertSame( 'ПВЗ, ул. Мира, 5', $row['shipping']['destination_text'] );
	}

	public function test_destination_falls_back_to_address_when_pickup_point_meta_key_is_declared_but_unpopulated(): void {
		$order    = $this->make_order(
			[
				'get_shipping_postcode'  => '123456',
				'get_shipping_state'     => 'МО',
				'get_shipping_city'      => 'Москва',
				'get_shipping_address_1' => 'ул. Ленина, 1',
			]
		);
		$provider = $this->provider( [ 'pickup_point_meta_key' => '_wc_edostavka_pickup_point' ] );

		$row = ( new Order_Row_Builder() )->build( $order, $provider );

		$this->assertSame( 'address', $row['shipping']['destination_kind'] );
	}

	// ----- tracking -----

	public function test_tracking_builds_the_url_from_the_template(): void {
		$this->meta['_wc_edostavka_tracking_code'] = '1234567890';

		$provider = $this->provider(
			[
				'tracking_meta_key'     => '_wc_edostavka_tracking_code',
				'tracking_url_template' => 'https://cdek.ru/track/{tracking}',
			]
		);

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertSame( '1234567890', $row['tracking']['number'] );
		$this->assertSame( 'https://cdek.ru/track/1234567890', $row['tracking']['url'] );
	}

	/**
	 * No number means null number AND null url — '—' is a display fallback, not this
	 * class's job.
	 */
	public function test_tracking_is_null_not_a_dash_when_no_number(): void {
		$provider = $this->provider( [ 'tracking_meta_key' => '_wc_edostavka_tracking_code' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertNull( $row['tracking']['number'] );
		$this->assertNull( $row['tracking']['url'] );
	}

	public function test_tracking_url_is_null_when_no_template_declared_even_with_a_number(): void {
		$this->meta['_wc_edostavka_tracking_code'] = '1234567890';

		$provider = $this->provider( [ 'tracking_meta_key' => '_wc_edostavka_tracking_code' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertSame( '1234567890', $row['tracking']['number'] );
		$this->assertNull( $row['tracking']['url'] );
	}

	public function test_tracking_is_null_when_provider_has_no_tracking_meta_key(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $this->provider() );

		$this->assertNull( $row['tracking']['number'] );
		$this->assertNull( $row['tracking']['url'] );
	}

	// ----- delivery_status -----

	public function test_delivery_status_resolves_via_the_providers_status_map(): void {
		$this->meta['_wc_edostavka_status'] = 'CDEK_ACCEPTED';

		$provider = $this->provider(
			[
				'status_meta_key' => '_wc_edostavka_status',
				'status_map'      => [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ],
			]
		);

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertSame( Delivery_Status::IN_TRANSIT, $row['delivery_status']['canonical'] );
		$this->assertSame( 'CDEK_ACCEPTED', $row['delivery_status']['raw'] );
	}

	public function test_delivery_status_is_unknown_when_provider_has_no_status_meta_key(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $this->provider() );

		$this->assertSame( Delivery_Status::UNKNOWN, $row['delivery_status']['canonical'] );
		$this->assertNull( $row['delivery_status']['raw'] );
	}

	public function test_delivery_status_is_unknown_when_provider_is_null(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), null );

		$this->assertSame( Delivery_Status::UNKNOWN, $row['delivery_status']['canonical'] );
	}

	// ----- delivery_status: the framework's own cancellation marker (#1037) -----

	public function test_a_cancelled_shipment_reads_cancelled_whatever_the_carriers_raw_status_still_says(): void {
		$this->meta['_wc_edostavka_status']         = 'CDEK_ACCEPTED';
		$this->meta['_woodev_shipment_cancelled_at'] = '1790000000';

		$provider = $this->provider(
			[
				'status_meta_key'    => '_wc_edostavka_status',
				'status_map'         => [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ],
				'status_labels'      => [ 'CDEK_ACCEPTED' => 'Принят СДЭК' ],
			]
		);

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertSame( Delivery_Status::CANCELLED, $row['delivery_status']['canonical'] );
		$this->assertSame( 'Отменено', $row['delivery_status']['canonical_label'] );
		$this->assertSame( 'CDEK_ACCEPTED', $row['delivery_status']['raw'], 'the carrier\'s own value is never rewritten or hidden' );
		$this->assertSame( 'Принят СДЭК', $row['delivery_status']['raw_label'] );
	}

	public function test_a_cancelled_shipment_of_a_carrier_with_no_status_meta_key_reads_cancelled(): void {
		$this->meta['_woodev_shipment_cancelled_at'] = '1790000000';

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $this->provider() );

		$this->assertSame( Delivery_Status::CANCELLED, $row['delivery_status']['canonical'] );
		$this->assertNull( $row['delivery_status']['raw'] );
	}

	public function test_the_row_and_the_action_gates_read_the_same_canonical_status(): void {
		$this->meta['_wc_edostavka_status']         = 'CDEK_ACCEPTED';
		$this->meta['_woodev_shipment_cancelled_at'] = '1790000000';

		$provider = $this->provider(
			[
				'status_meta_key' => '_wc_edostavka_status',
				'status_map'      => [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ],
			]
		);
		$order    = $this->make_order();

		$row = ( new Order_Row_Builder() )->build( $order, $provider );

		$this->assertSame( $row['delivery_status']['canonical'], Order_Actions::resolve_canonical_status( $order, $provider ) );
	}

	// ----- type: all four outcomes -----

	public function test_type_is_unknown_when_provider_is_null(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), null );

		$this->assertSame( 'unknown', $row['type'] );
	}

	public function test_type_is_unknown_when_the_order_has_no_matching_shipping_item(): void {
		$order = $this->make_order( [ 'get_shipping_methods' => [] ] );

		$row = ( new Order_Row_Builder() )->build( $order, $this->provider() );

		$this->assertSame( 'unknown', $row['type'] );
	}

	/**
	 * Round 2, defect 1: every real carrier ships at least two methods (courier and
	 * pickup), and a single `method_id` reported `unknown` `type` for whichever one
	 * it did not name. Both the courier line and the pickup line on the SAME
	 * multi-method provider must resolve.
	 */
	public function test_type_resolves_for_the_first_declared_method_id(): void {
		require_once __DIR__ . '/OrderRowBuilderFakeShippingMethodFixture.php';

		$item = Mockery::mock( '\WC_Order_Item_Shipping' );
		$item->shouldReceive( 'get_method_id' )->andReturn( 'cdek_courier' );
		$item->shouldReceive( 'get_instance_id' )->andReturn( 5 );

		$order    = $this->make_order( [ 'get_shipping_methods' => [ $item ] ] );
		$provider = $this->provider( [], [ 'cdek_courier', 'cdek_pickup' ] );

		$method  = new Order_Row_Builder_Fake_Shipping_Method( \Woodev\Framework\Shipping\Shipping_Method::TYPE_COURIER );
		$builder = $this->row_builder_resolving_method_to( $method );

		$row = $builder->build( $order, $provider );

		$this->assertSame( 'courier', $row['type'] );
	}

	/**
	 * The same provider's SECOND method id also resolves — the order's shipping
	 * line simply does not match the first id at all (a different order, a
	 * different chosen method), so the loop must try the next one rather than
	 * stopping at the first miss.
	 */
	public function test_type_resolves_for_a_later_method_id_when_the_first_does_not_match(): void {
		require_once __DIR__ . '/OrderRowBuilderFakeShippingMethodFixture.php';

		$item = Mockery::mock( '\WC_Order_Item_Shipping' );
		$item->shouldReceive( 'get_method_id' )->andReturn( 'cdek_pickup' );
		$item->shouldReceive( 'get_instance_id' )->andReturn( 5 );

		$order    = $this->make_order( [ 'get_shipping_methods' => [ $item ] ] );
		$provider = $this->provider( [], [ 'cdek_courier', 'cdek_pickup' ] );

		$method  = new Order_Row_Builder_Fake_Shipping_Method( \Woodev\Framework\Shipping\Shipping_Method::TYPE_PICKUP );
		$builder = $this->row_builder_resolving_method_to( $method );

		$row = $builder->build( $order, $provider );

		$this->assertSame( 'pickup', $row['type'] );
	}

	/**
	 * `type` is resolved via `Shipping_Method::get_delivery_type()`
	 * (`is_courier_shipping()`/`is_pickup_shipping()`/`is_postal_shipping()`), NOT
	 * `instanceof` against three optional flavor subclasses — see
	 * `Order_Row_Builder::resolve_type()`'s own docblock for why. The fake shipping
	 * method extends `Shipping_Method` directly, exactly like the real
	 * `Checkout_Config_Fake_Shipping_Method` fixture elsewhere in this suite does, and
	 * reports its type purely through `get_delivery_type()`.
	 */
	public function test_type_is_courier_when_the_method_reports_the_courier_delivery_type(): void {
		require_once __DIR__ . '/OrderRowBuilderFakeShippingMethodFixture.php';

		$item = Mockery::mock( '\WC_Order_Item_Shipping' );
		$item->shouldReceive( 'get_method_id' )->andReturn( 'cdek' );
		$item->shouldReceive( 'get_instance_id' )->andReturn( 5 );

		$order = $this->make_order( [ 'get_shipping_methods' => [ $item ] ] );

		$method  = new Order_Row_Builder_Fake_Shipping_Method( \Woodev\Framework\Shipping\Shipping_Method::TYPE_COURIER );
		$builder = $this->row_builder_resolving_method_to( $method );

		$row = $builder->build( $order, $this->provider() );

		$this->assertSame( 'courier', $row['type'] );
	}

	public function test_type_is_pickup_when_the_method_reports_the_pickup_delivery_type(): void {
		require_once __DIR__ . '/OrderRowBuilderFakeShippingMethodFixture.php';

		$item = Mockery::mock( '\WC_Order_Item_Shipping' );
		$item->shouldReceive( 'get_method_id' )->andReturn( 'cdek' );
		$item->shouldReceive( 'get_instance_id' )->andReturn( 5 );

		$order = $this->make_order( [ 'get_shipping_methods' => [ $item ] ] );

		$method  = new Order_Row_Builder_Fake_Shipping_Method( \Woodev\Framework\Shipping\Shipping_Method::TYPE_PICKUP );
		$builder = $this->row_builder_resolving_method_to( $method );

		$row = $builder->build( $order, $this->provider() );

		$this->assertSame( 'pickup', $row['type'] );
	}

	public function test_type_is_postal_when_the_method_reports_the_postal_delivery_type(): void {
		require_once __DIR__ . '/OrderRowBuilderFakeShippingMethodFixture.php';

		$item = Mockery::mock( '\WC_Order_Item_Shipping' );
		$item->shouldReceive( 'get_method_id' )->andReturn( 'cdek' );
		$item->shouldReceive( 'get_instance_id' )->andReturn( 5 );

		$order = $this->make_order( [ 'get_shipping_methods' => [ $item ] ] );

		$method  = new Order_Row_Builder_Fake_Shipping_Method( \Woodev\Framework\Shipping\Shipping_Method::TYPE_POSTAL );
		$builder = $this->row_builder_resolving_method_to( $method );

		$row = $builder->build( $order, $this->provider() );

		$this->assertSame( 'postal', $row['type'] );
	}

	/**
	 * A resolved value that is not a `Shipping_Method` at all — `false`, WC's own
	 * "not found" sentinel — resolves to `unknown`, not a fatal.
	 */
	public function test_type_is_unknown_when_the_resolved_method_is_not_a_shipping_method(): void {
		$item = Mockery::mock( '\WC_Order_Item_Shipping' );
		$item->shouldReceive( 'get_method_id' )->andReturn( 'cdek' );
		$item->shouldReceive( 'get_instance_id' )->andReturn( 5 );

		$order = $this->make_order( [ 'get_shipping_methods' => [ $item ] ] );

		$builder = $this->row_builder_resolving_method_to( false );

		$row = $builder->build( $order, $this->provider() );

		$this->assertSame( 'unknown', $row['type'] );
	}

	public function test_type_is_unknown_when_the_shipping_item_has_no_instance_id(): void {
		$item = Mockery::mock( '\WC_Order_Item_Shipping' );
		$item->shouldReceive( 'get_method_id' )->andReturn( 'cdek' );
		$item->shouldReceive( 'get_instance_id' )->andReturn( 0 );

		$order = $this->make_order( [ 'get_shipping_methods' => [ $item ] ] );

		$row = ( new Order_Row_Builder() )->build( $order, $this->provider() );

		$this->assertSame( 'unknown', $row['type'] );
	}

	// ----- increment-1 fields survive -----

	public function test_increment_1_fields_are_kept(): void {
		$order = $this->make_order();

		$row = ( new Order_Row_Builder() )->build( $order, $this->provider() );

		$this->assertSame( 123, $row['id'] );
		$this->assertSame( '123', $row['order_number'] );
		$this->assertSame( 'https://example.test/wp-admin/post.php?post=123&action=edit', $row['edit_url'] );
		$this->assertSame( '2026-09-07T12:00:00+00:00', $row['date_created'] );
		$this->assertSame( [ 'slug' => 'processing', 'label' => 'Processing' ], $row['status'] );
		$this->assertSame( [ 'id' => 'cdek', 'label' => 'СДЭК' ], $row['carrier'] );
	}

	// ----- is_exported / actions (card #824) -----

	public function test_is_exported_false_when_provider_is_null(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), null );

		$this->assertFalse( $row['is_exported'] );
	}

	public function test_is_exported_false_when_provider_has_no_carrier_order_id_meta_key(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $this->provider() );

		$this->assertFalse( $row['is_exported'] );
	}

	/**
	 * #860: an ABSENT meta key must read the same as a present-but-empty one — both
	 * are "not exported".
	 */
	public function test_is_exported_false_when_meta_key_is_absent(): void {
		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_wc_edostavka_carrier_order_id' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertFalse( $row['is_exported'] );
	}

	/**
	 * #860: a stored EMPTY string (a failed export) must not count as exported.
	 */
	public function test_is_exported_false_when_meta_value_is_an_empty_string(): void {
		$this->meta['_wc_edostavka_carrier_order_id'] = '';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_wc_edostavka_carrier_order_id' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertFalse( $row['is_exported'] );
	}

	public function test_is_exported_true_when_meta_value_is_non_empty(): void {
		$this->meta['_wc_edostavka_carrier_order_id'] = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_wc_edostavka_carrier_order_id' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertTrue( $row['is_exported'] );
	}

	// ----- cancel_failed (card #1007) -----

	public function test_cancel_failed_is_false_for_an_ordinary_row(): void {
		$this->meta['_wc_edostavka_carrier_order_id'] = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_wc_edostavka_carrier_order_id' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertFalse( $row['cancel_failed'] );
	}

	public function test_cancel_failed_is_false_without_a_provider(): void {
		$this->meta[ Carrier_Cancel::FAILED_META ] = 'CARRIER-1';

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), null );

		$this->assertFalse( $row['cancel_failed'] );
	}

	public function test_cancel_failed_is_true_while_the_shipment_the_cancellation_failed_for_is_still_stored(): void {
		$this->meta['_wc_edostavka_carrier_order_id'] = 'CARRIER-1';
		$this->meta[ Carrier_Cancel::FAILED_META ]    = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_wc_edostavka_carrier_order_id' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $provider );

		$this->assertTrue( $row['cancel_failed'] );
	}

	/**
	 * #1011: the row of a CANCELLED order is on the page by default now, and the marker is what
	 * tells the merchant the carrier still holds its request.
	 */
	public function test_a_cancelled_orders_row_carries_the_not_cancelled_at_the_carrier_marker(): void {
		$this->meta['_wc_edostavka_carrier_order_id'] = 'CARRIER-1';
		$this->meta[ Carrier_Cancel::FAILED_META ]    = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_wc_edostavka_carrier_order_id' ] );

		$row = ( new Order_Row_Builder() )->build( $this->make_order( [ 'get_status' => 'cancelled' ] ), $provider );

		$this->assertTrue( $row['cancel_failed'] );
	}

	public function test_cancel_failed_is_false_once_the_shipment_is_another_one_or_gone(): void {
		$this->meta[ Carrier_Cancel::FAILED_META ] = 'CARRIER-1';
		$provider                                  = $this->provider( [ 'carrier_order_id_meta_key' => '_wc_edostavka_carrier_order_id' ] );

		$this->meta['_wc_edostavka_carrier_order_id'] = 'CARRIER-2'; // exported again.
		$this->assertFalse( ( new Order_Row_Builder() )->build( $this->make_order(), $provider )['cancel_failed'] );

		$this->meta['_wc_edostavka_carrier_order_id'] = ''; // cancelled by hand later.
		$this->assertFalse( ( new Order_Row_Builder() )->build( $this->make_order(), $provider )['cancel_failed'] );
	}

	/** s164: the row always states its badges — `[]` when no carrier plugin hung any. */
	public function test_flags_is_an_empty_list_when_nothing_hooks_the_filter(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $this->provider() );

		$this->assertArrayHasKey( 'flags', $row );
		$this->assertSame( [], $row['flags'] );
	}

	/** s164: what a carrier plugin adds through `woodev_shipping_order_row_flags` rides on the row, sanitised. */
	public function test_flags_carries_the_sanitised_filter_answer_for_this_order_and_provider(): void {
		$order    = $this->make_order();
		$provider = $this->provider();
		$seen     = null;

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => strip_tags( $text ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) use ( &$seen ) {
				if ( 'woodev_shipping_order_row_flags' === $hook ) {
					$seen = $args;

					return [
						[
							'label' => 'Нужно вызвать курьера',
							'tone'  => 'warn',
						],
						[ 'label' => '' ],
					];
				}

				return $value;
			}
		);

		$row = ( new Order_Row_Builder() )->build( $order, $provider );

		$this->assertSame( [ $order, $provider ], $seen );
		$this->assertSame(
			[
				[
					'label' => 'Нужно вызвать курьера',
					'tone'  => 'warn',
				],
			],
			$row['flags']
		);
	}

	public function test_actions_is_empty_when_provider_is_null(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), null );

		$this->assertSame( [], $row['actions'] );
	}

	public function test_actions_without_a_handler_are_only_the_edit_action(): void {
		$row = ( new Order_Row_Builder() )->build( $this->make_order(), $this->provider() );

		$this->assertSame( [ 'edit' ], array_column( $row['actions'], 'action' ) );
	}

	/**
	 * Plumbing only — {@see \Woodev\Tests\Unit\ShippingOrderActionsTest} covers the
	 * full gate; this proves the row actually carries `Order_Actions`' output rather
	 * than a hardcoded shape. Since #972 the row's set is `for_row()`: `for_order()`'s plus
	 * «Редактировать» while the order is still editable.
	 */
	public function test_actions_reflects_order_actions_for_row(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		$order = $this->make_order( [ 'get_status' => 'processing' ] );

		$row = ( new Order_Row_Builder() )->build( $order, $this->provider() );

		$this->assertSame( [ Order_Actions::EDIT, Order_Actions::EXPORT ], array_column( $row['actions'], 'action' ) );
	}

	// ----- multi-marker guard (#928) -----

	/**
	 * Registers two carriers and returns an order whose meta ROWS are the given map.
	 *
	 * The guard reads through the order object (`WC_Data::meta_exists()`, the same on the CPT
	 * and HPOS datastores), so the rows live on the order double and `get_post_meta()` is
	 * deliberately left empty: a guard that peeked at post meta would see nothing here.
	 *
	 * @param array<string,string> $markers marker meta key => stored value (may be '').
	 */
	private function order_carrying_markers( array $markers ): \WC_Order {
		Functions\stubs( [ 'add_action', 'add_filter', 'remove_action', 'remove_filter' ] );

		$registry = Orders_Registry::instance();
		$registry->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ) );
		$registry->register_provider( Orders_Provider::create( 'yandex', 'Яндекс', '_yandex_marker', [ 'yandex' ] ) );

		$order = $this->make_order( [ 'get_id' => 4242 ] );
		$order->shouldReceive( 'meta_exists' )->andReturnUsing(
			static function ( $key = '' ) use ( $markers ): bool {
				return array_key_exists( $key, $markers );
			}
		);
		// Serve the stored VALUES from the same map, so a guard that reads values through
		// the order sees the '' and stays silent — which is what the empty-value test catches.
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( $key = '' ) use ( $markers ) {
				return $markers[ $key ] ?? '';
			}
		);

		return $order;
	}

	/**
	 * Two registered carriers' markers on one order is reported once, naming the order,
	 * both provider ids and the #928 assumption, under `WP_DEBUG`.
	 *
	 * WP_DEBUG cannot be un-defined once set, so this runs isolated (same discipline as
	 * ShippingOrdersRegistryTest's WP_DEBUG-dependent tests).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_reports_an_order_carrying_two_carriers_markers_under_wp_debug(): void {
		define( 'WP_DEBUG', true );

		$order = $this->order_carrying_markers( [ '_cdek_marker' => '1', '_yandex_marker' => '1' ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					static function ( $message ) {
						return is_string( $message )
							&& false !== strpos( $message, '4242' )
							&& false !== strpos( $message, 'cdek' )
							&& false !== strpos( $message, 'yandex' )
							&& false !== strpos( $message, '#928' );
					}
				),
				'2.0.2'
			);

		$builder = new Order_Row_Builder();
		$builder->build( $order, null );
		// The same order again in the same request must not report twice.
		$builder->build( $order, null );
	}

	/**
	 * Presence semantics: the query's `EXISTS` clauses count a marker by its meta ROW, so a
	 * second marker stored with an EMPTY value still breaks the filter and must be reported.
	 * A value-based guard read `''` as "no marker" and stayed silent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_reports_a_second_marker_stored_with_an_empty_value(): void {
		define( 'WP_DEBUG', true );

		$order = $this->order_carrying_markers( [ '_cdek_marker' => 'CDEK-1', '_yandex_marker' => '' ] );

		Functions\expect( '_doing_it_wrong' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::on(
					static function ( $message ) {
						return is_string( $message )
							&& false !== strpos( $message, 'cdek' )
							&& false !== strpos( $message, 'yandex' );
					}
				),
				'2.0.2'
			);

		( new Order_Row_Builder() )->build( $order, null );
	}

	/**
	 * The HPOS storage shape: the meta lives ONLY on the order object and `get_post_meta()`
	 * returns nothing for it. The guard must still see both markers.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_reads_markers_through_the_order_object_not_post_meta(): void {
		define( 'WP_DEBUG', true );

		$order = $this->order_carrying_markers( [ '_cdek_marker' => '1', '_yandex_marker' => '1' ] );

		$this->assertSame( [], $this->meta, 'the CPT post-meta source must be empty for this test to prove anything' );

		Functions\expect( '_doing_it_wrong' )->once();

		( new Order_Row_Builder() )->build( $order, null );
	}

	/**
	 * The guard runs through the registry the builder is given, not the singleton.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_reports_through_the_injected_registry(): void {
		define( 'WP_DEBUG', true );

		Functions\stubs( [ 'add_action', 'add_filter', 'remove_action', 'remove_filter' ] );

		$order = $this->make_order( [ 'get_id' => 4242 ] );

		$registry = Mockery::mock( Orders_Registry::class );
		$registry->shouldReceive( 'report_multiple_markers' )->once()->with( $order );

		( new Order_Row_Builder( new Order_Actions( $registry ), $registry ) )->build( $order, null );
	}

	/**
	 * Control: the ordinary one-marker order is silent even under `WP_DEBUG`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_build_is_silent_for_a_single_marker_order_under_wp_debug(): void {
		define( 'WP_DEBUG', true );

		$order = $this->order_carrying_markers( [ '_cdek_marker' => '1' ] );

		Functions\expect( '_doing_it_wrong' )->never();

		( new Order_Row_Builder() )->build( $order, null );
	}

	/**
	 * The guard is `WP_DEBUG`-only: a two-marker order produces nothing when it is off
	 * (the suite's default — this test defines nothing and runs in the shared process).
	 */
	public function test_build_is_silent_for_a_two_marker_order_when_wp_debug_is_off(): void {
		$this->assertFalse( defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG must be off for this test to be meaningful' );

		$order = $this->order_carrying_markers( [ '_cdek_marker' => '1', '_yandex_marker' => '1' ] );

		Functions\expect( '_doing_it_wrong' )->never();

		( new Order_Row_Builder() )->build( $order, null );
	}
}

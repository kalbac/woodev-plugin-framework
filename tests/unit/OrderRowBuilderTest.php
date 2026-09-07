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
use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\Delivery_Status;

require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/class-shipping-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/order/class-delivery-status.php';

class OrderRowBuilderTest extends TestCase {

	/** @var array<string,mixed> post meta, keyed by meta key, for the active test. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_edit_user_link' )->alias( static function ( int $user_id ): string {
			return "https://example.test/wp-admin/user-edit.php?user_id={$user_id}";
		} );
		Functions\when( 'wc_price' )->alias( static function ( $amount ): string {
			return (string) $amount;
		} );
		Functions\when( 'wc_get_order_status_name' )->alias( static function ( string $status ): string {
			return ucfirst( $status );
		} );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);
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

	private function provider( array $args = [] ): Orders_Provider {
		return Orders_Provider::create( 'cdek', 'СДЭК', '_wc_edostavka_shipping', 'cdek', $args );
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
}

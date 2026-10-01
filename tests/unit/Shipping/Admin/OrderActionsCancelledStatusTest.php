<?php
/**
 * Unit: the ONE canonical delivery-status resolution, with the framework's own cancellation marker
 * (#1037) — `Order_Actions::resolve_delivery_status()` / `resolve_canonical_status()` and the gates
 * that read them.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::resolve_delivery_status
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::resolve_canonical_status
 */
final class OrderActionsCancelledStatusTest extends TestCase {

	private const MARKER = '_woodev_shipment_cancelled_at';

	/** @var array<string,mixed> post meta of the active order, by meta key. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ): string {
				return ucfirst( $status );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider( array $args = [] ): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek' ],
			array_merge(
				[
					'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
					'status_meta_key'           => '_cdek_status',
					'status_map'                => [
						'ON_THE_WAY' => Delivery_Status::IN_TRANSIT,
						'DELIVERED'  => Delivery_Status::DELIVERED,
					],
					'status_labels'             => [ 'ON_THE_WAY' => 'В пути у СДЭК' ],
				],
				$args
			)
		);
	}

	private function order( string $status = 'processing' ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );

		return $order;
	}

	// ----- the resolver -----

	public function test_without_the_marker_the_raw_status_resolves_through_the_map(): void {
		$this->meta['_cdek_status'] = 'ON_THE_WAY';

		$resolved = Order_Actions::resolve_delivery_status( $this->order(), $this->provider() );

		$this->assertSame( Delivery_Status::IN_TRANSIT, $resolved['canonical'] );
		$this->assertSame( 'ON_THE_WAY', $resolved['raw'] );
	}

	public function test_the_marker_overrides_the_raw_status_and_keeps_it_beside_the_canonical_one(): void {
		$this->meta['_cdek_status'] = 'ON_THE_WAY';
		$this->meta[ self::MARKER ] = '1790000000';

		$resolved = Order_Actions::resolve_delivery_status( $this->order(), $this->provider() );

		$this->assertSame( Delivery_Status::CANCELLED, $resolved['canonical'] );
		$this->assertSame( 'Отменено', $resolved['canonical_label'] );
		$this->assertSame( 'ON_THE_WAY', $resolved['raw'] );
		$this->assertSame( 'В пути у СДЭК', $resolved['raw_label'] );
		$this->assertSame( 'error', Delivery_Status::tone( $resolved['canonical'] ) );
	}

	public function test_the_marker_overrides_an_unmapped_raw_status_and_a_missing_one(): void {
		$this->meta[ self::MARKER ] = '1790000000';

		$this->assertSame( Delivery_Status::CANCELLED, Order_Actions::resolve_delivery_status( $this->order(), $this->provider() )['canonical'], 'no raw status at all' );

		$this->meta['_cdek_status'] = 'SOMETHING_NEW';

		$this->assertSame( Delivery_Status::CANCELLED, Order_Actions::resolve_delivery_status( $this->order(), $this->provider() )['canonical'], 'an unmapped one' );
	}

	public function test_a_carrier_with_no_status_meta_key_still_honours_the_frameworks_own_marker(): void {
		$provider = $this->provider( [ 'status_meta_key' => null, 'status_map' => [] ] );

		$this->assertSame( Delivery_Status::UNKNOWN, Order_Actions::resolve_delivery_status( $this->order(), $provider )['canonical'] );

		$this->meta[ self::MARKER ] = '1790000000';

		$this->assertSame( Delivery_Status::CANCELLED, Order_Actions::resolve_delivery_status( $this->order(), $provider )['canonical'] );
	}

	public function test_an_order_with_no_carrier_resolves_unknown_marker_or_not(): void {
		$this->meta[ self::MARKER ] = '1790000000';

		$this->assertSame( Delivery_Status::UNKNOWN, Order_Actions::resolve_delivery_status( $this->order(), null )['canonical'] );
	}

	public function test_the_canonical_accessor_is_the_canonical_of_the_one_resolver_with_and_without_the_marker(): void {
		$this->meta['_cdek_status'] = 'DELIVERED';
		$this->meta[ self::MARKER ] = '1790000000';
		$order                      = $this->order();
		$provider                   = $this->provider();

		$this->assertSame( Delivery_Status::CANCELLED, Order_Actions::resolve_canonical_status( $order, $provider ) );
		$this->assertSame(
			Order_Actions::resolve_delivery_status( $order, $provider )['canonical'],
			Order_Actions::resolve_canonical_status( $order, $provider )
		);
		$this->assertSame( Delivery_Status::DELIVERED, Order_Actions::resolve_canonical_status( $order, $provider, false ), 'the edit gate reads the carrier alone' );
	}

	// ----- the gates -----

	/**
	 * After a successful cancel the stored carrier id is gone: the shipment is not «exported», so
	 * «Отменить» disappears and «Выгрузить» returns — the status reading «Отменено» must not change that.
	 */
	public function test_a_cancelled_shipment_offers_export_again_and_no_cancel(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( false );
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		$this->meta['_cdek_status'] = 'ON_THE_WAY';
		$this->meta[ self::MARKER ] = '1790000000';

		$ids = array_column( ( new Order_Actions( Orders_Registry::instance() ) )->for_order( $this->order(), $this->provider() ), 'action' );

		$this->assertSame( [ Order_Actions::EXPORT ], $ids );
	}

	/**
	 * A cancelled shipment has no live carrier order any more, so the order may be edited and
	 * exported again: the marker must not read as a «final delivery status» to the edit gate.
	 */
	public function test_the_edit_gate_does_not_treat_the_cancellation_marker_as_a_final_delivery_status(): void {
		$this->meta['_cdek_status'] = 'ON_THE_WAY';
		$this->meta[ self::MARKER ] = '1790000000';

		$this->assertTrue( Order_Actions::is_editable( $this->order(), $this->provider() ) );
		$this->assertSame( '', Order_Actions::not_editable_reason( $this->order(), $this->provider() ) );
	}

	public function test_the_edit_gate_still_refuses_a_delivery_the_carrier_itself_finished(): void {
		$this->meta['_cdek_status'] = 'DELIVERED';
		$this->meta[ self::MARKER ] = '1790000000';

		$this->assertFalse( Order_Actions::is_editable( $this->order(), $this->provider() ) );
	}
}

<?php
/**
 * Unit tests for the «attention» row flag of shipment facts.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Shipment_Cancellation;
use Woodev\Framework\Shipping\Order\Shipment_Facts_Flag;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-order-lock.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipment-facts.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipment-facts-events.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipment-facts-flag.php';

/** @covers \Woodev\Framework\Shipping\Order\Shipment_Facts_Flag */
final class ShipmentFactsFlagTest extends TestCase {

	private const ISSUE = [ 'code' => '13', 'label' => 'Контактное лицо отсутствует', 'at' => '2026-11-02T10:00:00+0300' ];

	private const COST = [ 'from' => 465.0, 'to' => 520.0, 'currency' => 'RUB' ];

	/** @var array<string,mixed> order meta fake. */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $key ] ?? '' );
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create(
			'test',
			'Тестовая доставка',
			'_test_marker',
			[ 'test_shipping' ],
			[
				'status_meta_key' => '_test_status',
				'status_map'      => [
					'ROAD'      => Delivery_Status::IN_TRANSIT,
					'DELIVERED' => Delivery_Status::DELIVERED,
					'LOST'      => Delivery_Status::FAILED,
				],
			]
		);
	}

	/**
	 * An order that answers meta reads only: a flag callback runs for every row, so anything else fails the test.
	 *
	 * @return \WC_Order&\Mockery\MockInterface
	 */
	private function order() {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 55 );
		$order->shouldReceive( 'get_meta' )->andReturnUsing( fn( string $key ) => $this->meta[ $key ] ?? '' );

		return $order;
	}

	private function attend( array $attention ): void {
		$this->meta['_woodev_shipment_fact_attention_test'] = $attention;
	}

	public function test_an_order_with_nothing_to_attend_to_gets_no_flag(): void {
		$this->assertSame( [], Shipment_Facts_Flag::add_flags( [], $this->order(), $this->provider() ) );
		$this->assertNull( Shipment_Facts_Flag::build( $this->order(), $this->provider() ) );
	}

	public function test_a_standing_issue_is_an_error_flag_with_its_wording(): void {
		$this->attend( [ 'issue' => self::ISSUE ] );
		$this->meta['_test_status'] = 'ROAD';

		$this->assertSame(
			[
				[
					'label' => 'Проблема доставки',
					'tone'  => 'error',
					'title' => 'Контактное лицо отсутствует',
				],
			],
			Shipment_Facts_Flag::add_flags( [], $this->order(), $this->provider() )
		);
	}

	public function test_a_cost_change_is_a_warn_flag_with_the_two_figures(): void {
		$this->attend( [ 'cost' => self::COST ] );

		$flag = Shipment_Facts_Flag::build( $this->order(), $this->provider() );

		$this->assertSame( 'Стоимость изменена перевозчиком', $flag['label'] );
		$this->assertSame( 'warn', $flag['tone'] );
		$this->assertSame( "465 → 520 \u{20BD}", $flag['title'] );
	}

	public function test_an_issue_outranks_a_cost_change_and_the_row_gets_one_flag(): void {
		$this->attend( [ 'issue' => self::ISSUE, 'cost' => self::COST ] );
		$this->meta['_test_status'] = 'ROAD';

		$flags = Shipment_Facts_Flag::add_flags( [ [ 'label' => 'Чужой', 'tone' => 'info' ] ], $this->order(), $this->provider() );

		$this->assertCount( 2, $flags, 'the carrier\'s own flag plus exactly one of ours' );
		$this->assertSame( 'Проблема доставки', $flags[1]['label'] );
	}

	public function test_a_final_status_settles_the_issue_but_not_the_cost_change(): void {
		$this->attend( [ 'issue' => self::ISSUE, 'cost' => self::COST ] );
		$this->meta['_test_status'] = 'DELIVERED';

		$flag = Shipment_Facts_Flag::build( $this->order(), $this->provider() );

		$this->assertSame( 'Стоимость изменена перевозчиком', $flag['label'], 'the issue is gone, the price change stays' );
	}

	public function test_a_final_status_clears_an_issue_that_stood_alone(): void {
		$this->attend( [ 'issue' => self::ISSUE ] );
		$this->meta['_test_status'] = 'DELIVERED';

		$this->assertNull( Shipment_Facts_Flag::build( $this->order(), $this->provider() ) );
	}

	public function test_a_cancelled_shipment_is_final_too(): void {
		$this->attend( [ 'issue' => self::ISSUE ] );
		$this->meta[ Shipment_Cancellation::CANCELLED_AT_META ] = '1790000000';

		$this->assertNull( Shipment_Facts_Flag::build( $this->order(), $this->provider() ) );
	}

	public function test_a_failed_delivery_attempt_is_not_final(): void {
		$this->attend( [ 'issue' => self::ISSUE ] );
		$this->meta['_test_status'] = 'LOST';

		$this->assertSame( 'Проблема доставки', Shipment_Facts_Flag::build( $this->order(), $this->provider() )['label'] );
	}

	public function test_another_carriers_attention_is_not_shown(): void {
		$this->meta['_woodev_shipment_fact_attention_other'] = [ 'cost' => self::COST ];

		$this->assertNull( Shipment_Facts_Flag::build( $this->order(), $this->provider() ) );
	}

	public function test_foreign_payloads_pass_through(): void {
		$this->attend( [ 'cost' => self::COST ] );

		$this->assertSame( 'not-an-array', Shipment_Facts_Flag::add_flags( 'not-an-array', $this->order(), $this->provider() ) );
		$this->assertSame( [], Shipment_Facts_Flag::add_flags( [], 'not-an-order', $this->provider() ) );
		$this->assertSame( [], Shipment_Facts_Flag::add_flags( [], $this->order(), null ) );
	}

	public function test_the_flag_is_registered_on_the_row_flags_filter(): void {
		Filters\expectAdded( 'woodev_shipping_order_row_flags' )->once()->with( [ Shipment_Facts_Flag::class, 'add_flags' ], 20, 3 );

		Shipment_Facts_Flag::register();
	}
}

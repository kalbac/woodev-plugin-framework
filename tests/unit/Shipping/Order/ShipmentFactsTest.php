<?php
/**
 * Unit tests for the shipment facts value object: what a carrier hands in is normalised, never guessed at.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Order\Shipment_Facts;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipment-facts.php';

/** @covers \Woodev\Framework\Shipping\Order\Shipment_Facts */
final class ShipmentFactsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	public function test_a_new_set_reports_nothing(): void {
		$facts = Shipment_Facts::create();

		$this->assertTrue( $facts->is_empty() );
		$this->assertNull( $facts->get_cost() );
		$this->assertFalse( $facts->reports_delivery_date() );
		$this->assertNull( $facts->get_issues() );
		$this->assertFalse( $facts->reports_courier() );
	}

	public function test_the_object_is_immutable(): void {
		$empty = Shipment_Facts::create();
		$empty->with_cost( 100.0 )->with_issues( [] )->with_courier( null )->with_delivery_date( null );

		$this->assertTrue( $empty->is_empty(), 'a with_*() call returns a copy' );
	}

	public function test_a_cost_is_rounded_and_its_currency_normalised(): void {
		$this->assertSame( [ 'amount' => 465.5, 'currency' => 'USD' ], Shipment_Facts::create()->with_cost( 465.499, ' usd ' )->get_cost() );
		$this->assertSame( 'RUB', Shipment_Facts::create()->with_cost( 10.0 )->get_cost()['currency'], 'the default is the rouble' );
		$this->assertSame( 'RUB', Shipment_Facts::create()->with_cost( 10.0, 'рубли' )->get_cost()['currency'], 'a non-ISO code falls back' );
	}

	/**
	 * @return array<string,array{0:float}>
	 */
	public function unusable_amounts(): array {
		return [
			'negative' => [ -1.0 ],
			'nan'      => [ NAN ],
			'infinite' => [ INF ],
		];
	}

	/**
	 * @dataProvider unusable_amounts
	 */
	public function test_an_unusable_cost_stays_not_reported( float $amount ): void {
		$this->assertTrue( Shipment_Facts::create()->with_cost( $amount )->is_empty() );
	}

	public function test_cost_components_are_independent_and_none_is_not_zero(): void {
		$facts = Shipment_Facts::create()->with_cost( 100.0 )->with_cost( 120.0, 'RUB', ' TOTAL ' )->with_cost( null, 'RUB', 'insurance' )->with_cost( 0.0, 'RUB', 'fee' );

		$this->assertFalse( $facts->is_empty() );
		$this->assertSame(
			[
				'delivery'  => [ 'amount' => 100.0, 'currency' => 'RUB' ],
				'total'     => [ 'amount' => 120.0, 'currency' => 'RUB' ],
				'insurance' => null,
				'fee'       => [ 'amount' => 0.0, 'currency' => 'RUB' ],
			],
			$facts->get_costs()
		);
		$this->assertSame( [ 'amount' => 120.0, 'currency' => 'RUB' ], $facts->get_cost( 'total' ) );
		$this->assertNull( $facts->get_cost( 'insurance' ) );
		$this->assertFalse( Shipment_Facts::create()->with_cost( null )->is_empty(), '«none» is a report' );
	}

	public function test_an_unusable_component_key_is_ignored(): void {
		$this->assertTrue( Shipment_Facts::create()->with_cost( 10.0, 'RUB', 'bad key!' )->is_empty() );
		$this->assertTrue( Shipment_Facts::create()->with_cost( 10.0, 'RUB', '' )->is_empty() );
	}

	public function test_a_date_keeps_its_day_kind_and_window(): void {
		$facts = Shipment_Facts::create()->with_delivery_date( '2026-11-03T10:00:00+0300', 'agreed', [ 'from' => '10:00', 'to' => '14:00' ] );

		$this->assertTrue( $facts->reports_delivery_date() );
		$this->assertSame(
			[
				'date'   => '2026-11-03',
				'kind'   => 'agreed',
				'window' => [ 'from' => '10:00', 'to' => '14:00' ],
			],
			$facts->get_delivery_date()
		);
	}

	public function test_an_unknown_date_kind_is_planned_and_an_empty_window_is_none(): void {
		$date = Shipment_Facts::create()->with_delivery_date( '2026-11-03', 'whenever', [ 'from' => ' ', 'to' => '' ] )->get_delivery_date();

		$this->assertSame( 'planned', $date['kind'] );
		$this->assertNull( $date['window'] );
	}

	public function test_a_null_date_is_reported_as_none_and_a_malformed_one_is_not_reported(): void {
		$none = Shipment_Facts::create()->with_delivery_date( null );
		$this->assertTrue( $none->reports_delivery_date() );
		$this->assertNull( $none->get_delivery_date() );

		$this->assertFalse( Shipment_Facts::create()->with_delivery_date( 'завтра' )->reports_delivery_date() );
	}

	public function test_issues_are_normalised_and_the_ones_without_a_code_dropped(): void {
		$facts = Shipment_Facts::create()->with_issues(
			[
				[ 'code' => 13, 'label' => ' Контактное лицо отсутствует ', 'at' => '2026-11-03T10:00:00+0300' ],
				[ 'label' => 'no code' ],
				[ 'code' => '  ' ],
				'garbage',
				[ 'code' => 'X' ],
			]
		);

		$this->assertSame(
			[
				[ 'code' => '13', 'label' => 'Контактное лицо отсутствует', 'at' => '2026-11-03T10:00:00+0300' ],
				[ 'code' => 'X', 'label' => '', 'at' => '' ],
			],
			$facts->get_issues()
		);
	}

	public function test_an_empty_issue_list_is_reported_as_none(): void {
		$this->assertSame( [], Shipment_Facts::create()->with_issues( [] )->get_issues() );
		$this->assertFalse( Shipment_Facts::create()->with_issues( [] )->is_empty() );
	}

	public function test_a_courier_keeps_the_fields_the_carrier_gave(): void {
		$courier = Shipment_Facts::create()->with_courier( [ 'name' => 'Иван', 'plate' => 'А123ВС36' ] )->get_courier();

		$this->assertSame(
			[ 'name' => 'Иван', 'phone' => '', 'vehicle' => '', 'plate' => 'А123ВС36' ],
			$courier
		);
	}

	public function test_a_courier_with_nothing_filled_in_is_none(): void {
		$facts = Shipment_Facts::create()->with_courier( [ 'name' => ' ', 'phone' => '' ] );

		$this->assertTrue( $facts->reports_courier() );
		$this->assertNull( $facts->get_courier() );
	}
}

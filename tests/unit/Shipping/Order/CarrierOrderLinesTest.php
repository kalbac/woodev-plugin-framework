<?php
/**
 * Unit tests for carrier order lines.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Order\Carrier_Order_Line;
	use Woodev\Framework\Shipping\Order\Carrier_Order_Lines;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Carrier_Order_Line
	 * @covers \Woodev\Framework\Shipping\Order\Carrier_Order_Lines
	 */
	final class CarrierOrderLinesTest extends TestCase {

		public function test_reconciles_three_units_to_an_exact_order_total_by_splitting_the_line(): void {
			$line   = new Carrier_Order_Line( 'Товар', 'SKU', 3, 3333, 9999 );
			$result = Carrier_Order_Lines::reconcile( [ $line ], 10000 );

			$this->assertCount( 2, $result );
			$this->assertSame( [ 2, 1 ], array_map( static function ( Carrier_Order_Line $item ): int {
				return $item->get_quantity();
			}, $result ) );
			$this->assertSame( [ 3333, 3334 ], array_map( static function ( Carrier_Order_Line $item ): int {
				return $item->get_unit_price_minor();
			}, $result ) );
			$this->assertSame( 10000, self::unit_total( $result ) );
		}

		public function test_spreads_a_coupon_adjusted_target_by_largest_remainder(): void {
			$lines = [
				new Carrier_Order_Line( 'A', 'A', 1, 10000, 10000 ),
				new Carrier_Order_Line( 'B', 'B', 1, 5000, 5000 ),
			];

		$result = Carrier_Order_Lines::reconcile( $lines, 14000 );

		$this->assertSame( [ 9333, 4667 ], array_map( static function ( Carrier_Order_Line $item ): int {
			return $item->get_unit_price_minor();
		}, $result ) );
		$this->assertSame( 14000, self::unit_total( $result ) );
		$this->assertSame( [ 9333, 4667 ], array_map( static function ( Carrier_Order_Line $item ): int {
			return $item->get_total_minor();
		}, $result ) );
		$this->assertSame( 'A', $result[0]->get_sku() );
	}

	public function test_leaves_already_exact_totals_untouched(): void {
		$line  = new Carrier_Order_Line( 'Товар', 'SKU', 2, 125, 250 );
		$result = Carrier_Order_Lines::reconcile( [ $line ], 250 );

		$this->assertSame( [ $line ], $result );
	}

	public function test_reconciles_when_line_totals_match_but_unit_price_totals_do_not(): void {
		$line   = new Carrier_Order_Line( 'Товар', 'SKU', 3, 3333, 10000 );
		$result = Carrier_Order_Lines::reconcile( [ $line ], 10000 );

		$this->assertCount( 2, $result );
		$this->assertSame( 10000, self::unit_total( $result ) );
	}

	public function test_zero_and_negative_targets_are_guarded(): void {
		$line = new Carrier_Order_Line( 'Товар', 'SKU', 1, 100, 100 );

		$this->assertSame( [ $line ], Carrier_Order_Lines::reconcile( [ $line ], -1 ) );
		$zeroed = Carrier_Order_Lines::reconcile( [ $line ], 0 );
		$this->assertSame( 0, self::unit_total( $zeroed ) );
		$this->assertSame( 0, $zeroed[0]->get_unit_price_minor() );
		$this->assertSame( [], Carrier_Order_Lines::reconcile( [], 0 ) );
	}

	public function test_does_not_reconcile_negative_line_values_or_an_unpriced_positive_target(): void {
		$negative = new Carrier_Order_Line( 'Возврат', '', 1, -100, -100 );
		$free     = new Carrier_Order_Line( 'Бесплатно', '', 1, 0, 0 );

		$this->assertSame( [ $negative ], Carrier_Order_Lines::reconcile( [ $negative ], 100 ) );
		$this->assertSame( [ $free ], Carrier_Order_Lines::reconcile( [ $free ], 100 ) );
	}

	public function test_handles_large_quantities_without_float_arithmetic(): void {
		$line   = new Carrier_Order_Line( 'Товар', 'BULK', 1000000, 100, 100000000 );
		$result = Carrier_Order_Lines::reconcile( [ $line ], 100000001 );

		$this->assertSame( 100000001, self::unit_total( $result ) );
		$this->assertCount( 2, $result );
		$this->assertSame( [ 999999, 1 ], array_map( static function ( Carrier_Order_Line $item ): int {
			return $item->get_quantity();
		}, $result ) );
	}

	public function test_converts_money_with_half_up_rounding(): void {
		$this->assertSame( 1001, Carrier_Order_Lines::to_minor_units( 10.005, 2 ) );
		$this->assertSame( 1000, Carrier_Order_Lines::to_minor_units( 10.004, 2 ) );
		$this->assertSame( 11, Carrier_Order_Lines::to_minor_units( 10.5, 0 ) );
	}

	public function test_builds_rounded_product_lines_and_uses_the_requested_tax_mode(): void {
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		$item    = $this->item( 'Книга', 'BK-1', 2, [ 'total' => [ 1 => '1.11' ] ] );
		$order   = $this->order( [ 11 => $item ], [ 'get_item_total' => 10.005, 'inc_tax' => true ] );
		$lines   = Carrier_Order_Lines::from_order( $order, true );

		$this->assertCount( 1, $lines );
		$this->assertSame( 1001, $lines[0]->get_unit_price_minor() );
		$this->assertSame( 2002, $lines[0]->get_total_minor() );
		$this->assertSame( 'BK-1', $lines[0]->get_sku() );
		$this->assertSame( [ 'total' => [ 1 => '1.11' ] ], $lines[0]->get_tax_info()['taxes'] );
		$this->assertTrue( $lines[0]->get_tax_info()['includes_tax'] );
	}

	public function test_skips_zero_and_fully_refunded_quantities_and_reduces_partial_refunds(): void {
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		$zero      = $this->item( 'Ноль', 'ZERO', 0 );
		$refunded  = $this->item( 'Возврат', 'REF', 2 );
		$partial   = $this->item( 'Частичный', 'PART', 3 );
		$order     = $this->order(
			[ 1 => $zero, 2 => $refunded, 3 => $partial ],
				[ 'get_qty_refunded_for_item' => [ 1 => 0, 2 => 2, 3 => 1 ] ]
		);

		$lines = Carrier_Order_Lines::from_order( $order );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'PART', $lines[0]->get_sku() );
		$this->assertSame( 2, $lines[0]->get_quantity() );
		$this->assertSame( 2000, $lines[0]->get_total_minor() );
	}

	public function test_optional_builder_reconciliation_defaults_to_order_total_less_shipping(): void {
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
		$item  = $this->item( 'Книга', 'BK-1', 3 );
		$order = $this->order(
			[ 1 => $item ],
			[
				'get_total'         => '100.00',
				'get_shipping_total' => '15.00',
				'get_total_tax'      => '0.00',
				'get_item_total'     => '28.33',
			]
		);

		$lines = Carrier_Order_Lines::from_order( $order, false, true );

		$this->assertSame( 8500, self::unit_total( $lines ) );
		$this->assertCount( 2, $lines );
	}

	/**
	 * Sums unit prices multiplied by quantities.
	 *
	 * @param Carrier_Order_Line[] $lines Lines to sum.
	 * @return int
	 */
	private static function unit_total( array $lines ): int {
		$total = 0;

		foreach ( $lines as $line ) {
			$total += $line->get_unit_price_minor() * $line->get_quantity();
		}

		return $total;
	}

	/**
	 * Creates an item double.
	 *
	 * @param string              $name  Product name.
	 * @param string              $sku   Product SKU.
	 * @param int                 $qty   Quantity.
	 * @param array<string,mixed>  $taxes Tax metadata.
	 * @return \WC_Order_Item_Product
	 */
	private function item( string $name, string $sku, int $qty, array $taxes = [] ): \WC_Order_Item_Product {
		$item = Mockery::mock( '\\WC_Order_Item_Product' );
		$item->shouldReceive( 'get_name' )->andReturn( $name );
		$item->shouldReceive( 'get_quantity' )->andReturn( $qty );
		$item->shouldReceive( 'get_tax_class' )->andReturn( 'standard' );
		$item->shouldReceive( 'get_taxes' )->andReturn( $taxes );

		$product = Mockery::mock( '\\WC_Product' );
		$product->shouldReceive( 'get_sku' )->andReturn( $sku );
		$product->shouldReceive( 'get_tax_status' )->andReturn( 'taxable' );
		$item->shouldReceive( 'get_product' )->andReturn( $product );

		return $item;
	}

	/**
	 * Creates an order double with the methods used by the builder.
	 *
	 * @param array<int,\WC_Order_Item_Product> $items    Line items.
	 * @param array<string,mixed>               $overrides Return values by method name.
	 * @return \WC_Order
	 */
	private function order( array $items, array $overrides = [] ): \WC_Order {
		$order = Mockery::mock( '\\WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( $items );
		$order->shouldReceive( 'get_qty_refunded_for_item' )->andReturnUsing(
			static function ( int $item_id ) use ( $overrides ): int {
				$refunds = $overrides['get_qty_refunded_for_item'] ?? [];
				return $refunds[ $item_id ] ?? 0;
			}
		);
		$order->shouldReceive( 'get_item_total' )->with( Mockery::type( '\\WC_Order_Item_Product' ), $overrides['inc_tax'] ?? false )->andReturn( $overrides['get_item_total'] ?? 10.00 );
		$order->shouldReceive( 'get_total' )->andReturn( $overrides['get_total'] ?? '0.00' );
		$order->shouldReceive( 'get_shipping_total' )->andReturn( $overrides['get_shipping_total'] ?? '0.00' );
		$order->shouldReceive( 'get_shipping_tax' )->andReturn( $overrides['get_shipping_tax'] ?? '0.00' );
		$order->shouldReceive( 'get_total_tax' )->andReturn( $overrides['get_total_tax'] ?? '0.00' );

		return $order;
	}
	}
}

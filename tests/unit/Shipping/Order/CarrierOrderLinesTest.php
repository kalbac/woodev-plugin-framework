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

		public function test_reconciles_three_units_and_balances_the_remainder(): void {
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

		public function test_spreads_coupon_target_by_largest_remainder(): void {
			$lines = [
				new Carrier_Order_Line( 'A', 'A', 1, 10000, 10000 ),
				new Carrier_Order_Line( 'B', 'B', 1, 5000, 5000 ),
			];
			$result = Carrier_Order_Lines::reconcile( $lines, 14000 );

			$this->assertSame( [ 9333, 4667 ], array_map( static function ( Carrier_Order_Line $item ): int {
				return $item->get_unit_price_minor();
			}, $result ) );
			$this->assertSame( 14000, self::unit_total( $result ) );
		}

		public function test_remainder_ties_distribute_a_deficit_of_two_by_input_order(): void {
			$lines = [
				9 => new Carrier_Order_Line( 'A', 'A', 1, 1, 1 ),
				4 => new Carrier_Order_Line( 'B', 'B', 1, 1, 1 ),
				7 => new Carrier_Order_Line( 'C', 'C', 1, 1, 1 ),
			];
			$result = Carrier_Order_Lines::reconcile( $lines, 8 );

			$this->assertSame( [ 3, 3, 2 ], array_map( static function ( Carrier_Order_Line $item ): int {
				return $item->get_unit_price_minor();
			}, $result ) );
			$this->assertSame( 8, self::unit_total( $result ) );
		}

		public function test_leaves_already_exact_totals_untouched(): void {
			$line = new Carrier_Order_Line( 'Товар', 'SKU', 2, 125, 250 );

			$this->assertSame( [ $line ], Carrier_Order_Lines::reconcile( [ $line ], 250 ) );
		}

		public function test_throws_when_target_is_negative(): void {
			$this->expectException( \UnexpectedValueException::class );
			Carrier_Order_Lines::reconcile( [ new Carrier_Order_Line( 'Товар', 'SKU', 1, 100, 100 ) ], -1 );
		}

		public function test_reconciles_to_zero_and_accepts_an_empty_zero_target(): void {
			$line   = new Carrier_Order_Line( 'Товар', 'SKU', 1, 100, 100 );
			$result = Carrier_Order_Lines::reconcile( [ $line ], 0 );

			$this->assertSame( 0, self::unit_total( $result ) );
			$this->assertSame( [], Carrier_Order_Lines::reconcile( [], 0 ) );
		}

		public function test_throws_when_negative_fee_cannot_be_reconciled_with_product_lines(): void {
			$this->expectException( \UnexpectedValueException::class );
			Carrier_Order_Lines::reconcile( [ new Carrier_Order_Line( 'Возврат', '', 1, -100, -100 ) ], 100 );
		}

		public function test_throws_when_positive_target_has_no_priced_lines(): void {
			$this->expectException( \UnexpectedValueException::class );
			Carrier_Order_Lines::reconcile( [ new Carrier_Order_Line( 'Бесплатно', '', 1, 0, 0 ) ], 100 );
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

		public function test_builds_rounded_product_lines_with_requested_tax_mode(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$item  = $this->item( 'Книга', 'BK-1', 2, [ 'total' => [ 1 => '1.11' ] ] );
			$order = $this->order( [ 11 => $item ], [ 'get_item_total' => 10.005, 'inc_tax' => true ] );
			$lines = Carrier_Order_Lines::from_order( $order, true );

			$this->assertCount( 1, $lines );
			$this->assertSame( 1001, $lines[0]->get_unit_price_minor() );
			$this->assertSame( 2002, $lines[0]->get_total_minor() );
			$this->assertSame( 'BK-1', $lines[0]->get_sku() );
			$this->assertSame( [ 'total' => [ 1 => '1.11' ] ], $lines[0]->get_tax_info()['taxes'] );
			$this->assertTrue( $lines[0]->get_tax_info()['includes_tax'] );
		}

		public function test_skips_zero_and_fully_refunded_quantities_and_reduces_partial_refunds(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$zero     = $this->item( 'Ноль', 'ZERO', 0 );
			$refunded = $this->item( 'Возврат', 'REF', 2 );
			$partial  = $this->item( 'Частичный', 'PART', 3 );
			$order    = $this->order(
				[ 1 => $zero, 2 => $refunded, 3 => $partial ],
				[ 'get_qty_refunded_for_item' => [ 1 => 0, 2 => 2, 3 => 1 ] ]
			);
			$lines = Carrier_Order_Lines::from_order( $order );

			$this->assertCount( 1, $lines );
			$this->assertSame( 'PART', $lines[0]->get_sku() );
			$this->assertSame( 2, $lines[0]->get_quantity() );
			$this->assertSame( 2000, $lines[0]->get_total_minor() );
		}

		public function test_refund_aware_reconciliation_keeps_remaining_product_prices(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$item  = $this->item( 'Товар', 'SKU', 3 );
			$order = $this->order(
				[ 1 => $item ],
				[
					'get_total' => '100.00',
					'get_total_refunded' => '30.00',
					'get_shipping_total' => '10.00',
					'get_item_total' => 30.00,
					'get_qty_refunded_for_item' => [ 1 => 1 ],
				]
			);
			$lines = Carrier_Order_Lines::from_order( $order, false, true );

			$this->assertSame( 6000, self::unit_total( $lines ) );
			$this->assertSame( 2, $lines[0]->get_quantity() );
		}

		public function test_money_only_refund_reconciles_without_reducing_quantity(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$item  = $this->item( 'Товар', 'SKU', 3 );
			$order = $this->order(
				[ 1 => $item ],
				[
					'get_total' => '100.00',
					'get_total_refunded' => '10.00',
					'get_shipping_total' => '10.00',
					'get_item_total' => 30.00,
				]
			);
			$lines = Carrier_Order_Lines::from_order( $order, false, true );

			$this->assertSame( 8000, self::unit_total( $lines ) );
			$this->assertSame( 3, array_sum( array_map( static function ( Carrier_Order_Line $line ): int {
				return $line->get_quantity();
			}, $lines ) ) );
		}

		public function test_emits_fees_separately_and_reconciles_only_product_drift(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$item  = $this->item( 'Товар', 'SKU', 1 );
			$fee   = $this->fee( 'Сбор', 5.00 );
			$order = $this->order(
				[ 1 => $item ],
				[
					'fees' => [ 2 => $fee ],
					'get_total' => '115.00',
					'get_shipping_total' => '10.00',
					'get_item_total' => 99.99,
				]
			);
			$lines = Carrier_Order_Lines::from_order( $order, false, true );

			$this->assertSame( [ 'SKU', '' ], array_map( static function ( Carrier_Order_Line $line ): string {
				return $line->get_sku();
			}, $lines ) );
			$this->assertSame( 'Сбор', $lines[1]->get_name() );
			$this->assertSame( [ 10000, 500 ], array_map( static function ( Carrier_Order_Line $line ): int {
				return $line->get_total_minor();
			}, $lines ) );
		}

		public function test_keeps_negative_fees_separate(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$order = $this->order( [], [ 'fees' => [ 2 => $this->fee( 'Скидка', -5.00 ) ] ] );
			$lines = Carrier_Order_Lines::from_order( $order );

			$this->assertCount( 1, $lines );
			$this->assertSame( -500, $lines[0]->get_total_minor() );
		}

		public function test_apportions_fees_only_when_explicitly_requested(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$order = $this->order(
				[ 1 => $this->item( 'Товар', 'SKU', 1 ) ],
				[
					'fees' => [ 2 => $this->fee( 'Сбор', 5.00 ) ],
					'get_total' => '105.00',
					'get_item_total' => 100.00,
				]
			);
			$lines = Carrier_Order_Lines::from_order( $order, false, true, true );

			$this->assertCount( 1, $lines );
			$this->assertSame( 'SKU', $lines[0]->get_sku() );
			$this->assertSame( 10500, self::unit_total( $lines ) );
		}

		public function test_keeps_fees_separate_when_apportionment_is_requested_without_reconciliation(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$order = $this->order(
				[ 1 => $this->item( 'Товар', 'SKU', 1 ) ],
				[ 'fees' => [ 2 => $this->fee( 'Сбор', 5.00 ) ], 'get_item_total' => 100.00 ]
			);
			$lines = Carrier_Order_Lines::from_order( $order, false, false, true );

			$this->assertSame( [ 'SKU', '' ], array_map( static function ( Carrier_Order_Line $line ): string {
				return $line->get_sku();
			}, $lines ) );
			$this->assertSame( [ 10000, 500 ], array_map( static function ( Carrier_Order_Line $line ): int {
				return $line->get_total_minor();
			}, $lines ) );
		}

		public function test_from_order_throws_when_refunded_quantities_leave_no_lines_for_remaining_total(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$order = $this->order(
				[ 1 => $this->item( 'Возврат', 'REF', 1 ) ],
				[
					'get_total' => '30.00',
					'get_qty_refunded_for_item' => [ 1 => 1 ],
				]
			);
			$this->expectException( \UnexpectedValueException::class );

			Carrier_Order_Lines::from_order( $order, false, true );
		}

		public function test_reconciles_after_refunded_shipping_and_shipping_tax(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$order = $this->order(
				[ 1 => $this->item( 'Товар', 'SKU', 1 ) ],
				[
					'get_total' => '122.00',
					'get_total_refunded' => '6.00',
					'get_shipping_total' => '10.00',
					'get_shipping_tax' => '2.00',
					'get_total_tax' => '12.00',
					'get_total_tax_refunded' => '1.00',
					'get_total_shipping_refunded' => '5.00',
					'get_total_shipping_tax_refunded' => '1.00',
					'get_item_total' => 100.00,
				]
			);
			$lines = Carrier_Order_Lines::from_order( $order, false, true );

			$this->assertSame( 10000, self::unit_total( $lines ) );
		}

		public function test_reconciles_tax_inclusive_and_exclusive_prices_after_a_taxed_product_refund(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$exclusive_order = $this->order(
				[ 1 => $this->item( 'Товар', 'SKU', 3 ) ],
				[
					'get_total' => '120.00',
					'get_total_refunded' => '36.00',
					'get_shipping_total' => '10.00',
					'get_shipping_tax' => '2.00',
					'get_total_tax' => '20.00',
					'get_total_tax_refunded' => '6.00',
					'get_item_total' => 30.00,
					'get_qty_refunded_for_item' => [ 1 => 1 ],
				]
			);
			$inclusive_order = $this->order(
				[ 1 => $this->item( 'Товар', 'SKU', 3 ) ],
				[
					'get_total' => '120.00',
					'get_total_refunded' => '36.00',
					'get_shipping_total' => '10.00',
					'get_shipping_tax' => '2.00',
					'get_total_tax' => '20.00',
					'get_total_tax_refunded' => '6.00',
					'get_item_total' => 36.00,
					'inc_tax' => true,
					'get_qty_refunded_for_item' => [ 1 => 1 ],
				]
			);

			$exclusive = Carrier_Order_Lines::from_order( $exclusive_order, false, true );
			$inclusive = Carrier_Order_Lines::from_order( $inclusive_order, true, true );

			$this->assertSame( 6000, self::unit_total( $exclusive ) );
			$this->assertSame( 7200, self::unit_total( $inclusive ) );
		}

		public function test_skips_fully_refunded_fee_including_its_refunded_tax(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$order = $this->order(
				[],
				[
					'fees' => [ 2 => $this->fee( 'Сбор', 5.00, 2.00, [ 'total' => [ 1 => '2.00' ] ] ) ],
					'get_total_refunded_for_item' => [ 2 => 5.00 ],
					'get_tax_refunded_for_item' => [ 2 => [ 1 => 2.00 ] ],
				]
			);
			$lines = Carrier_Order_Lines::from_order( $order, true );

			$this->assertSame( [], $lines );
		}

		public function test_reconciles_fractional_remaining_quantity_after_refund(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$order = $this->order(
				[ 1 => $this->item( 'Товар', 'SKU', 3 ) ],
				[
					'get_total' => '100.00',
					'get_total_refunded' => '45.00',
					'get_shipping_total' => '10.00',
					'get_item_total' => 30.00,
					'get_qty_refunded_for_item' => [ 1 => -1.5 ],
				]
			);
			$lines = Carrier_Order_Lines::from_order( $order, false, true );

			$this->assertCount( 1, $lines );
			$this->assertSame( 1, $lines[0]->get_quantity() );
			$this->assertSame( 4500, self::unit_total( $lines ) );
		}

		public function test_represents_fractional_quantity_as_one_line_with_full_value(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$item  = $this->item( 'Половина', 'HALF', 0.5 );
			$order = $this->order( [ 1 => $item ], [ 'get_item_total' => 20.00 ] );
			$lines = Carrier_Order_Lines::from_order( $order );

			$this->assertCount( 1, $lines );
			$this->assertSame( 1, $lines[0]->get_quantity() );
			$this->assertSame( 1000, $lines[0]->get_total_minor() );
			$this->assertSame( 1000, $lines[0]->get_unit_price_minor() );
		}

		public function test_optional_builder_reconciliation_defaults_to_order_total_less_shipping(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			$item  = $this->item( 'Книга', 'BK-1', 3 );
			$order = $this->order(
				[ 1 => $item ],
				[
					'get_total' => '100.00',
					'get_shipping_total' => '15.00',
					'get_item_total' => '28.33',
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
		 * @param float               $qty   Quantity.
		 * @param array<string,mixed> $taxes Tax metadata.
		 * @return \WC_Order_Item_Product
		 */
		private function item( string $name, string $sku, float $qty, array $taxes = [] ): \WC_Order_Item_Product {
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
		 * Creates a fee double.
		 *
		 * @param string $name  Fee name.
		 * @param float  $total Fee total.
		 * @return \WC_Order_Item_Fee
		 */
		private function fee( string $name, float $total, float $total_tax = 0.00, array $taxes = [] ): \WC_Order_Item_Fee {
			$fee = Mockery::mock( '\\WC_Order_Item_Fee' );
			$fee->shouldReceive( 'get_name' )->andReturn( $name );
			$fee->shouldReceive( 'get_total' )->andReturn( $total );
			$fee->shouldReceive( 'get_total_tax' )->andReturn( $total_tax );
			$fee->shouldReceive( 'get_tax_class' )->andReturn( '' );
			$fee->shouldReceive( 'get_taxes' )->andReturn( $taxes );

			return $fee;
		}

		/**
		 * Creates an order double with the methods used by the builder.
		 *
		 * @param array<int,\WC_Order_Item_Product> $items     Line items.
		 * @param array<string,mixed>                $overrides Return values by method name.
		 * @return \WC_Order
		 */
		private function order( array $items, array $overrides = [] ): \WC_Order {
			$order = Mockery::mock( '\\WC_Order' );
			$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( $items );
			$order->shouldReceive( 'get_items' )->with( 'fee' )->andReturn( $overrides['fees'] ?? [] );
			$order->shouldReceive( 'get_qty_refunded_for_item' )->andReturnUsing(
				static function ( int $item_id ) use ( $overrides ): float {
					$refunds = $overrides['get_qty_refunded_for_item'] ?? [];
					return $refunds[ $item_id ] ?? 0;
				}
			);
			$order->shouldReceive( 'get_item_total' )->with( Mockery::type( '\\WC_Order_Item_Product' ), $overrides['inc_tax'] ?? false )->andReturn( $overrides['get_item_total'] ?? 10.00 );
			$order->shouldReceive( 'get_total' )->andReturn( $overrides['get_total'] ?? '0.00' );
			$order->shouldReceive( 'get_shipping_total' )->andReturn( $overrides['get_shipping_total'] ?? '0.00' );
			$order->shouldReceive( 'get_shipping_tax' )->andReturn( $overrides['get_shipping_tax'] ?? '0.00' );
			$order->shouldReceive( 'get_total_tax' )->andReturn( $overrides['get_total_tax'] ?? '0.00' );
			$order->shouldReceive( 'get_total_refunded' )->andReturn( $overrides['get_total_refunded'] ?? '0.00' );
			$order->shouldReceive( 'get_total_shipping_refunded' )->andReturn( $overrides['get_total_shipping_refunded'] ?? '0.00' );
			$order->shouldReceive( 'get_total_shipping_tax_refunded' )->andReturn( $overrides['get_total_shipping_tax_refunded'] ?? '0.00' );
			$order->shouldReceive( 'get_total_tax_refunded' )->andReturn( $overrides['get_total_tax_refunded'] ?? '0.00' );
			$order->shouldReceive( 'get_total_refunded_for_item' )->andReturnUsing(
				static function ( int $item_id, string $item_type = 'line_item' ) use ( $overrides ): float {
					$refunds = $overrides['get_total_refunded_for_item'] ?? [];
					return $refunds[ $item_id ] ?? ( is_numeric( $refunds ) ? (float) $refunds : 0.00 );
				}
			);
			$order->shouldReceive( 'get_tax_refunded_for_item' )->andReturnUsing(
				static function ( int $item_id, int $tax_id, string $item_type = 'line_item' ) use ( $overrides ): float {
					$refunds = $overrides['get_tax_refunded_for_item'] ?? [];
					return $refunds[ $item_id ][ $tax_id ] ?? 0.00;
				}
			);

			return $order;
		}
	}
}

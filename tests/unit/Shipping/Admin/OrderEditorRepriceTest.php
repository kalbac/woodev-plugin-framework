<?php
/**
 * Unit: the order editor must not reprice a line nobody changed (#1327).
 *
 * The wizard's prefill sends every line's unit price back computed from the pre-discount subtotal. Saving
 * only an address must therefore leave a coupon-bearing line exactly as it was (subtotal 1000, total 900),
 * and an intentional quantity/price/line-set change must re-apply the order's coupons the WooCommerce way
 * (`WC_Order::recalculate_coupons()`) instead of silently dropping the discount.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\{Order_Editor, Orders_Registry};
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::apply_items
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::price_line
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::refresh_discounts
 */
final class OrderEditorRepriceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wc_get_rounding_precision' )->justReturn( 2 );
		Functions\when( 'wc_format_decimal' )->alias(
			static function ( $value ) {
				return number_format( (float) $value, 2, '.', '' );
			}
		);
		Functions\when( 'wc_maybe_adjust_line_item_product_stock' )->justReturn( true );
	}

	/**
	 * A saved line of the given shape: product 7, no variation.
	 *
	 * @param int    $quantity quantity.
	 * @param string $subtotal pre-discount subtotal.
	 * @param string $total    line total after the coupon.
	 * @return \Mockery\MockInterface
	 */
	private function saved_line( int $quantity, string $subtotal, string $total ) {
		$item = Mockery::mock( '\WC_Order_Item_Product' );
		$item->shouldReceive( 'get_id' )->andReturn( 5 );
		$item->shouldReceive( 'get_product_id' )->andReturn( 7 );
		$item->shouldReceive( 'get_variation_id' )->andReturn( 0 );
		$item->shouldReceive( 'get_quantity' )->andReturn( $quantity );
		$item->shouldReceive( 'get_subtotal' )->andReturn( $subtotal );
		$item->shouldReceive( 'get_total' )->andReturn( $total );

		return $item;
	}

	/**
	 * @param \Mockery\MockInterface $item the saved line.
	 * @return \Mockery\MockInterface
	 */
	private function order_with( $item ) {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( [ 5 => $item ] );

		return $order;
	}

	/**
	 * @param int         $quantity wanted quantity.
	 * @param string|null $price    wanted unit price, or null for «as it was».
	 * @return array<int, array<string, mixed>>
	 */
	private function wanted( int $quantity, ?string $price ): array {
		return [
			[
				'item_id'      => 5,
				'product_id'   => 7,
				'variation_id' => 0,
				'quantity'     => $quantity,
				'price'        => $price,
				'product'      => Mockery::mock( '\WC_Product' ),
			],
		];
	}

	/**
	 * @param \WC_Order                        $order  the order.
	 * @param array<int, array<string, mixed>> $wanted wanted lines.
	 * @return bool whether apply_items reported a repricing.
	 */
	private function apply_items( $order, array $wanted ): bool {
		$repriced = false;
		$method   = new \ReflectionMethod( Order_Editor::class, 'apply_items' );
		$args     = [ $order, $wanted, true, &$repriced ];

		$method->invokeArgs( new Order_Editor( Mockery::mock( Orders_Registry::class ) ), $args );

		return $repriced;
	}

	public function test_an_address_only_save_keeps_a_coupon_bearing_line_untouched(): void {
		// Prefill sends subtotal / quantity = 1000.00 back as the price.
		$item = $this->saved_line( 1, '1000.00', '900.00' );
		$item->shouldNotReceive( 'set_subtotal' );
		$item->shouldNotReceive( 'set_total' );
		$item->shouldNotReceive( 'set_quantity' );

		$this->assertFalse( $this->apply_items( $this->order_with( $item ), $this->wanted( 1, '1000.00' ) ) );
	}

	public function test_a_line_without_a_wanted_price_is_not_repriced_either(): void {
		$item = $this->saved_line( 2, '2000.00', '1800.00' );
		$item->shouldNotReceive( 'set_subtotal' );
		$item->shouldNotReceive( 'set_total' );
		$item->shouldNotReceive( 'set_quantity' );

		$this->assertFalse( $this->apply_items( $this->order_with( $item ), $this->wanted( 2, null ) ) );
	}

	public function test_a_rounded_prefill_price_does_not_count_as_a_price_change(): void {
		// 100.00 / 3 = 33.333…; the prefill sends 33.33, and 3 × 33.33 must not overwrite the saved 100.00.
		$item = $this->saved_line( 3, '100.00', '90.00' );
		$item->shouldNotReceive( 'set_subtotal' );
		$item->shouldNotReceive( 'set_total' );
		$item->shouldNotReceive( 'set_quantity' );

		$this->assertFalse( $this->apply_items( $this->order_with( $item ), $this->wanted( 3, '33.33' ) ) );
	}

	public function test_a_changed_quantity_reprices_the_line_and_is_reported(): void {
		$item = $this->saved_line( 1, '1000.00', '900.00' );
		$item->shouldReceive( 'set_quantity' )->once()->with( 2 );
		$item->shouldReceive( 'set_subtotal' )->once()->with( '2000.00' );
		$item->shouldReceive( 'set_total' )->once()->with( '2000.00' );

		$this->assertTrue( $this->apply_items( $this->order_with( $item ), $this->wanted( 2, '1000.00' ) ) );
	}

	public function test_a_changed_price_reprices_the_line_and_is_reported(): void {
		$item = $this->saved_line( 1, '1000.00', '900.00' );
		$item->shouldReceive( 'set_quantity' )->once()->with( 1 );
		$item->shouldReceive( 'set_subtotal' )->once()->with( '1200.00' );
		$item->shouldReceive( 'set_total' )->once()->with( '1200.00' );

		$this->assertTrue( $this->apply_items( $this->order_with( $item ), $this->wanted( 1, '1200.00' ) ) );
	}

	public function test_a_removed_line_is_reported_as_a_change(): void {
		$item = $this->saved_line( 1, '1000.00', '900.00' );
		$order = $this->order_with( $item );
		$order->shouldReceive( 'remove_item' )->once()->with( 5 );

		$this->assertTrue( $this->apply_items( $order, [] ) );
	}

	/**
	 * @param \WC_Order $order    the order.
	 * @param bool      $repriced whether a line changed.
	 * @param bool      $is_update whether the order already existed.
	 */
	private function refresh( $order, bool $repriced, bool $is_update = true ): void {
		$method = new \ReflectionMethod( Order_Editor::class, 'refresh_discounts' );
		$method->invoke( new Order_Editor( Mockery::mock( Orders_Registry::class ) ), $order, $repriced, $is_update );
	}

	public function test_coupons_are_reapplied_when_an_edit_changed_the_lines(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'coupon' )->andReturn( [ 1 => new \stdClass() ] );
		$order->shouldReceive( 'recalculate_coupons' )->once();

		$this->refresh( $order, true );
	}

	public function test_coupons_are_left_alone_when_no_line_changed(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldNotReceive( 'recalculate_coupons' );

		$this->refresh( $order, false );
	}

	public function test_an_order_without_coupons_is_not_recalculated(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'coupon' )->andReturn( [] );
		$order->shouldNotReceive( 'recalculate_coupons' );

		$this->refresh( $order, true );
	}

	public function test_a_create_never_recalculates_coupons(): void {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldNotReceive( 'recalculate_coupons' );

		$this->refresh( $order, true, false );
	}
}

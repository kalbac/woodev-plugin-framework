<?php
/**
 * Tests for {@see \Woodev\Framework\Shipping\Shipping_Rate::to_array()} — the shape
 * handed to `WC_Shipping_Method::add_rate()`.
 *
 * Why this file exists (#764): until 2.0.2 `to_array()` wrapped the meta in an extra
 * level keyed by the method id (`[ 'edostavka' => [ 'edostavka_rate' => ... ] ]`).
 * Nothing in the framework produced a rate WITH meta — all four shipping fixtures and
 * every test built `Shipping_Rate` with four arguments — so the wrapper was never
 * executed and never asserted. The first real plugin to migrate hit it immediately:
 * `woocommerce-edostavka` writes a flat `edostavka_rate` key and reads it back off the
 * shipping order item, which is a release-blocking installed-site contract (ADR-005).
 *
 * These assertions pin the flat shape so the wrapper cannot come back unnoticed.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Rate::to_array
 */
class ShippingRateToArrayTest extends TestCase {

	/**
	 * Meta is emitted exactly as it was supplied — no wrapper level.
	 */
	public function test_meta_data_is_flat(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'СДЭК доставка',
			'350',
			null,
			[
				'edostavka_rate' => [
					'period_min' => 2,
					'period_max' => 4,
				],
			]
		);

		$array = $rate->to_array();

		$this->assertSame(
			[
				'edostavka_rate' => [
					'period_min' => 2,
					'period_max' => 4,
				],
			],
			$array['meta_data'],
			'meta_data must be the flat key => value array the rate was built with.'
		);
	}

	/**
	 * The specific regression #764 is about: the plugin's own key stays reachable at
	 * the top level, which is where `WC_Order_Item_Shipping::get_meta()` looks for it.
	 */
	public function test_a_plugin_meta_key_is_not_nested_under_the_method_id(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'СДЭК доставка',
			'350',
			null,
			[ 'edostavka_rate' => [ 'total_sum' => 350 ] ]
		);

		$array = $rate->to_array();

		$this->assertArrayHasKey( 'edostavka_rate', $array['meta_data'] );
		$this->assertArrayNotHasKey(
			'edostavka',
			$array['meta_data'],
			'A wrapper keyed by the method id moves the plugin key one level down and breaks the order-item meta contract.'
		);
	}

	/**
	 * A rate with no meta produces no meta rows. The wrapper used to yield
	 * `[ 'edostavka' => [] ]` here — one junk order-item meta row per rate.
	 */
	public function test_empty_meta_data_stays_empty(): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'СДЭК доставка', '350' );

		$this->assertSame( [], $rate->to_array()['meta_data'] );
	}

	/**
	 * The rest of the shape is unchanged by #764 — pinned so the fix cannot drift into it.
	 */
	public function test_the_other_keys_are_unchanged(): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'СДЭК доставка', '350' );

		$array = $rate->to_array();

		$this->assertSame( 'edostavka:5', $array['id'] );
		$this->assertSame( 'СДЭК доставка', $array['label'] );
		$this->assertSame( '350', $array['cost'] );
		$this->assertArrayNotHasKey( 'package', $array, 'package is included only when explicitly set.' );
	}

	/**
	 * `package` is still emitted when the rate carries one.
	 */
	public function test_package_is_included_when_set(): void {

		$package = [ 'destination' => [ 'country' => 'RU' ] ];

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'СДЭК доставка', '350', $package );

		$this->assertSame( $package, $rate->to_array()['package'] );
	}
}

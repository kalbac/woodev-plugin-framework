<?php
/**
 * Tests for the rate attributes {@see \Woodev\Framework\Shipping\Shipping_Rate} carries
 * beyond the four it models, and for what it does INSTEAD of throwing — #766.
 *
 * Why this file exists. Two defects surfaced together when `woocommerce-edostavka` moved
 * its calculation onto the `rate_package()` seam:
 *
 * 1. `to_array()` emitted exactly five keys, so a rate could not carry `taxes`/`calc_tax`
 *    — which `WC_Shipping_Method::add_rate()` DOES accept — nor any key a third party had
 *    added through the plugin's own rate filter. While the plugin called `add_rate()`
 *    itself the extra keys survived; through the DTO they vanished with no message.
 * 2. The constructor threw `InvalidArgumentException` on an empty `label` and on a `cost`
 *    that was not a string or array. Both run on the SHIPPING CALCULATION path: a
 *    merchant who clears the Title field, and an `array_sum()` that returns a number,
 *    are ordinary shop data — not a programming error worth a white screen at checkout.
 *
 * The assertions below pin both halves: what the rate now carries, and what it now
 * degrades rather than throws over. Each was falsified against the pre-fix class.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Rate
 */
class ShippingRateAttributesTest extends TestCase {

	/**
	 * Every `_doing_it_wrong()` the class under test raised, one entry per call.
	 *
	 * @var array<int, array>
	 */
	private array $reports = [];

	/**
	 * `_doing_it_wrong()` is stubbed here rather than left to chance.
	 *
	 * The DTO guards the call with `function_exists()`, so whether it fires at all
	 * depends on whether some OTHER test file in the run happened to define the function
	 * through Brain Monkey first — and `phpunit.xml` sets `executionOrder="depends,defects"`,
	 * which reorders the run. Stubbing it makes these tests independent of that, and
	 * turns the report into something the tests can assert on instead of merely survive.
	 *
	 * @return void
	 */
	protected function setUp(): void {

		parent::setUp();

		$this->reports = [];

		Functions\when( '_doing_it_wrong' )->alias(
			function ( $function_name, $message, $version ) {
				$this->reports[] = compact( 'function_name', 'message', 'version' );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * What the rate can carry to add_rate()
	 * ------------------------------------------------------------------ */

	/**
	 * `taxes` and `calc_tax` are accepted by `add_rate()` and must survive the DTO.
	 */
	public function test_add_rate_args_reach_the_array(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'CDEK',
			'350',
			null,
			[],
			[
				'taxes'    => [ 1 => '12.50' ],
				'calc_tax' => 'per_item',
			]
		);

		$array = $rate->to_array();

		$this->assertSame( [ 1 => '12.50' ], $array['taxes'] );
		$this->assertSame( 'per_item', $array['calc_tax'] );
	}

	/**
	 * A key nobody in the framework knows about still travels: `add_rate()` runs its
	 * argument array through `woocommerce_shipping_method_add_rate_args`, which is where
	 * a third party consumes one. Narrowing the array to a whitelist would break exactly
	 * the extension the DTO was meant to preserve.
	 */
	public function test_an_unknown_third_party_key_is_not_dropped(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'CDEK',
			'350',
			null,
			[],
			[ 'some_third_party_key' => 'kept' ]
		);

		$this->assertSame( 'kept', $rate->to_array()['some_third_party_key'] );
	}

	/**
	 * The keys the DTO owns cannot be shadowed from the args — they are written last.
	 */
	public function test_owned_keys_win_over_args(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'CDEK',
			'350',
			null,
			[ 'edostavka_rate' => [ 'period_min' => 2 ] ],
			[
				'id'        => 'hijacked',
				'label'     => 'hijacked',
				'cost'      => '999',
				'meta_data' => [ 'hijacked' => true ],
			]
		);

		$array = $rate->to_array();

		$this->assertSame( 'edostavka:5', $array['id'] );
		$this->assertSame( 'CDEK', $array['label'] );
		$this->assertSame( '350', $array['cost'] );
		$this->assertSame( [ 'edostavka_rate' => [ 'period_min' => 2 ] ], $array['meta_data'] );
	}

	/* ------------------------------------------------------------------ *
	 * What must NOT reach add_rate(), because add_rate() discards it
	 * ------------------------------------------------------------------ */

	/**
	 * `description` and `delivery_time` exist on `WC_Shipping_Rate` (WooCommerce 9.2.0+)
	 * but `add_rate()` never sets them on the object it builds — measured against
	 * WooCommerce 11.0.1, whose `add_rate()` sets id, method id, instance id, label,
	 * cost, taxes and tax status and nothing else. Emitting them into the args array
	 * would look like wiring while doing nothing, so they are held back and applied to
	 * the rate object afterwards instead.
	 */
	public function test_post_add_rate_attributes_are_held_back_from_the_array(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'CDEK',
			'350',
			null,
			[],
			[
				'description'   => 'Dispatched from the Moscow hub',
				'delivery_time' => '2-4 days',
				'calc_tax'      => 'per_order',
			]
		);

		$array = $rate->to_array();

		$this->assertArrayNotHasKey( 'description', $array );
		$this->assertArrayNotHasKey( 'delivery_time', $array );
		$this->assertSame( 'per_order', $array['calc_tax'], 'An ordinary add_rate() arg must still travel.' );

		$this->assertSame(
			[
				'description'   => 'Dispatched from the Moscow hub',
				'delivery_time' => '2-4 days',
			],
			$rate->get_post_add_rate_attributes(),
			'The held-back attributes must still be reachable for the caller that applies them.'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Degradation instead of an exception on the calculation path
	 * ------------------------------------------------------------------ */

	/**
	 * An empty label is what a merchant produces by clearing the Title field. Before
	 * this fix the constructor threw, which is a fatal while a customer is calculating
	 * shipping.
	 */
	public function test_an_empty_label_does_not_throw(): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', '' );

		$this->assertSame( '', $rate->get_label() );
	}

	/**
	 * Degrading is not the same as staying quiet: the developer must be able to find out
	 * their builder produced a rate WooCommerce will drop. `_doing_it_wrong()` is how the
	 * rest of the framework says so — loud under `WP_DEBUG`, silent in production.
	 */
	public function test_an_empty_label_is_reported(): void {

		new Shipping_Rate( 'edostavka', 'edostavka:5', '   ' );

		$this->assertCount( 1, $this->reports );
		$this->assertStringContainsString( 'empty label', $this->reports[0]['message'] );
		$this->assertStringContainsString( 'edostavka:5', $this->reports[0]['message'] );
	}

	/**
	 * A label that is present is not reported — a guard that shouts on every rate is a
	 * guard the developer learns to ignore.
	 */
	public function test_a_present_label_is_not_reported(): void {

		new Shipping_Rate( 'edostavka', 'edostavka:5', 'CDEK', 350 );

		$this->assertSame( [], $this->reports );
	}

	/**
	 * A number is a legitimate cost: `array_sum()` returns one. It is accepted, and it
	 * is kept as the NUMBER it was — `add_rate()` totals and formats it itself.
	 *
	 * @dataProvider provide_numeric_costs
	 *
	 * @param int|float $cost the supplied cost.
	 */
	public function test_a_numeric_cost_is_accepted_unchanged( $cost ): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'CDEK', $cost );

		$this->assertSame( $cost, $rate->get_cost() );
		$this->assertSame( [], $this->reports, 'An ordinary number is not a builder mistake.' );
	}

	/**
	 * @return array<string, array{0: int|float}>
	 */
	public function provide_numeric_costs(): array {
		return [
			'integer'         => [ 350 ],
			'float'           => [ 350.5 ],
			'zero'            => [ 0 ],
			'float with tail' => [ 1234.56 ],
			'tiny float'      => [ 0.00000001 ],
			'huge float'      => [ 1.0e20 ],
		];
	}

	/**
	 * A numeric cost must NOT be stringified on its way in, and the two entries in the
	 * provider above that look absurd are the reason.
	 *
	 * `(string) $float` emits scientific notation outside a narrow range. Handed
	 * `'1.0E+20'`, `wc_format_decimal()` takes its `! is_float()` branch, strips every
	 * character outside `[0-9.-]`, and returns `1.02` — the same value passed as a FLOAT
	 * goes through `sprintf( '%.6f', ... )` and survives. So stringifying here would have
	 * quoted a 1e20 delivery at one rouble two kopecks, silently. Measured against
	 * WooCommerce 11.0.1; found by the critic pass on this change.
	 *
	 * This test pins the type, which is the thing that matters — an assertion on the
	 * formatted price would be asserting WooCommerce's formatter, not our contract.
	 */
	public function test_a_float_cost_stays_a_float(): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'CDEK', 1.0e20 );

		$this->assertIsFloat( $rate->get_cost() );
		$this->assertSame( 1.0e20, $rate->get_cost() );
	}

	/**
	 * A per-item array cost is untouched — `add_rate()` sums it and can calculate tax
	 * per item from it.
	 */
	public function test_an_array_cost_is_untouched(): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'CDEK', [ '100', '250' ] );

		$this->assertSame( [ '100', '250' ], $rate->get_cost() );
	}

	/**
	 * A value that is neither scalar nor array carries no amount, so it degrades to zero
	 * rather than being cast — casting an object either fatals or invents a price
	 * (gotcha `a-cast-is-not-a-degradation`).
	 *
	 * @dataProvider provide_costs_without_an_amount
	 *
	 * @param mixed $cost the supplied cost.
	 */
	public function test_a_cost_carrying_no_amount_degrades_to_zero( $cost ): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'CDEK', $cost );

		$this->assertSame( '0', $rate->get_cost() );

		$this->assertCount( 1, $this->reports, 'A degraded cost must be reported, not swallowed.' );
		$this->assertStringContainsString( 'carries no amount', $this->reports[0]['message'] );
	}

	/**
	 * `NAN` and `INF` are the two floats that carry no amount. They matter separately
	 * from the rest: they pass `is_float()`, so a type check alone lets them through to
	 * `wc_format_decimal()`, and `(string) NAN` additionally raises a PHP 8 warning.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_costs_without_an_amount(): array {
		return [
			'null'   => [ null ],
			'bool'   => [ true ],
			'object' => [ new \stdClass() ],
			'NAN'    => [ NAN ],
			'INF'    => [ INF ],
		];
	}

	/* ------------------------------------------------------------------ *
	 * What still throws — a genuinely broken invariant
	 * ------------------------------------------------------------------ */

	/**
	 * A rate with no id cannot be rendered, selected or stored by WooCommerce at all:
	 * `add_rate()` returns early and `$this->rates` is keyed by it. That is a
	 * programming error, and it keeps throwing.
	 */
	public function test_an_empty_rate_id_still_throws(): void {

		$this->expectException( \InvalidArgumentException::class );

		new Shipping_Rate( 'edostavka', '', 'CDEK' );
	}

	/**
	 * Same for the method id.
	 */
	public function test_an_empty_method_id_still_throws(): void {

		$this->expectException( \InvalidArgumentException::class );

		new Shipping_Rate( '', 'edostavka:5', 'CDEK' );
	}

	/**
	 * And so does a package that is neither a flag nor package data.
	 */
	public function test_a_malformed_package_still_throws(): void {

		$this->expectException( \InvalidArgumentException::class );

		new Shipping_Rate( 'edostavka', 'edostavka:5', 'CDEK', '350', 'not a package' );
	}

	/* ------------------------------------------------------------------ *
	 * Immutability: every copy carries the args
	 * ------------------------------------------------------------------ */

	/**
	 * `with_label()` is what the framework uses to substitute the method title for an
	 * empty label, so everything else must survive the copy.
	 */
	public function test_with_label_returns_a_copy_carrying_everything_else(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'',
			'350',
			true,
			[ 'edostavka_rate' => [ 'period_min' => 2 ] ],
			[ 'calc_tax' => 'per_item', 'description' => 'from the hub' ]
		);

		$relabelled = $rate->with_label( 'CDEK courier' );

		$this->assertNotSame( $rate, $relabelled );
		$this->assertSame( '', $rate->get_label(), 'The original must be untouched.' );
		$this->assertSame( 'CDEK courier', $relabelled->get_label() );
		$this->assertSame( '350', $relabelled->get_cost() );
		$this->assertTrue( $relabelled->get_package() );
		$this->assertSame( [ 'edostavka_rate' => [ 'period_min' => 2 ] ], $relabelled->get_meta_data() );
		$this->assertSame( [ 'calc_tax' => 'per_item', 'description' => 'from the hub' ], $relabelled->get_args() );
	}

	/**
	 * The pre-existing copy constructors must carry the args too — the whole point of
	 * the DTO is that a rate does not lose attributes on its way to WooCommerce, and
	 * `with_meta_data()` sits on exactly that path.
	 */
	public function test_the_other_copies_carry_the_args(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'CDEK',
			'350',
			null,
			[],
			[ 'calc_tax' => 'per_item' ]
		);

		$this->assertSame( [ 'calc_tax' => 'per_item' ], $rate->with_meta_data( [ 'a' => 'b' ] )->get_args() );
		$this->assertSame( [ 'calc_tax' => 'per_item' ], $rate->with_cost( '400' )->get_args() );
	}

	/**
	 * `with_args()` merges, and the supplied keys win.
	 */
	public function test_with_args_merges_and_the_supplied_keys_win(): void {

		$rate = new Shipping_Rate(
			'edostavka',
			'edostavka:5',
			'CDEK',
			'350',
			null,
			[],
			[ 'calc_tax' => 'per_order', 'description' => 'old' ]
		);

		$merged = $rate->with_args( [ 'description' => 'new', 'delivery_time' => '2-4 days' ] );

		$this->assertSame(
			[
				'calc_tax'      => 'per_order',
				'description'   => 'new',
				'delivery_time' => '2-4 days',
			],
			$merged->get_args()
		);
		$this->assertSame( 'old', $rate->get_arg( 'description' ), 'The original must be untouched.' );
	}

	/**
	 * `get_arg()` reports absence with the caller's own default, so a `null` VALUE is
	 * distinguishable from a missing key.
	 */
	public function test_get_arg_distinguishes_a_null_value_from_a_missing_key(): void {

		$rate = new Shipping_Rate( 'edostavka', 'edostavka:5', 'CDEK', '350', null, [], [ 'calc_tax' => null ] );

		$this->assertNull( $rate->get_arg( 'calc_tax', 'fallback' ) );
		$this->assertSame( 'fallback', $rate->get_arg( 'nothing_here', 'fallback' ) );
	}
}

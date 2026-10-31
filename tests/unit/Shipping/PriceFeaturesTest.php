<?php
/**
 * The price features a shipping method declares (#1417): markup, free shipping, rounding and the delivery time —
 * their controls, the order the framework applies them in, and how they meet the existing cost limits.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\{Fee_Payments, Shipping_Helper, Shipping_Method, Shipping_Rate};
use Woodev\Tests\Unit\TestCase;

// the cost-limits probe (and through it the rate-cache probe and the `WC_Shipping_Method` stub) lives there
require_once __DIR__ . '/CostLimitsTest.php';

/**
 * The probe: answers which coupons grant free shipping and exposes the finished rate.
 */
class Woodev_Test_Price_Features_Method extends Woodev_Test_Cost_Limits_Method {

	/**
	 * Coupon codes that grant free shipping.
	 *
	 * @var string[]
	 */
	public array $free_shipping_coupons = [];

	/**
	 * Whether one coupon grants free shipping.
	 *
	 * @param string $code coupon code.
	 * @return bool
	 */
	protected function coupon_grants_free_shipping( string $code ): bool {
		return in_array( $code, $this->free_shipping_coupons, true );
	}

	/**
	 * The rate the framework finishes for a package (price features, delivery days, box cost, cost limits).
	 *
	 * @param array $package package.
	 * @return Shipping_Rate|null
	 */
	public function finished_rate( array $package ): ?Shipping_Rate {
		return $this->calculate_rate( $package );
	}
}

/**
 * A carrier whose installed sites store the free-shipping threshold under its own key.
 */
class Woodev_Test_Price_Features_Legacy_Key_Method extends Woodev_Test_Price_Features_Method {

	/**
	 * The CDEK key.
	 *
	 * @var string
	 */
	const OPTION_FREE_SHIPPING_FROM = 'free';
}

/**
 * @coversDefaultClass \Woodev\Framework\Shipping\Shipping_Method
 */
final class PriceFeaturesTest extends TestCase {

	/**
	 * Whether the shop uses coupons.
	 *
	 * @var bool
	 */
	private bool $coupons_enabled = true;

	/**
	 * Stubs what the rate path and the form reach.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->coupons_enabled = true;

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wc_get_weight' )->returnArg( 1 );
		Functions\when( 'wc_get_dimension' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wc_coupons_enabled' )->alias( fn() => $this->coupons_enabled );
	}

	/**
	 * A probe that declared `$features`, saved `$options` and whose carrier quotes `$cost`.
	 *
	 * @param string[]             $features declared features.
	 * @param array<string, mixed> $options  saved instance options.
	 * @param mixed                $cost     the carrier's cost.
	 * @param string               $class    probe class.
	 * @return Woodev_Test_Price_Features_Method
	 */
	private function method( array $features, array $options = [], $cost = 100, string $class = Woodev_Test_Price_Features_Method::class ): Woodev_Test_Price_Features_Method {
		$method                = new $class();
		$method->supports      = $features;
		$method->option_values = $options;
		$method->next_rate     = new Shipping_Rate( 'cost-limits', 'cost-limits:1', 'Courier', $cost );

		return $method;
	}

	/**
	 * The cost WooCommerce is handed for `$package`.
	 *
	 * @param Woodev_Test_Price_Features_Method $method  probe.
	 * @param array                             $package package.
	 * @return float
	 */
	private function price( Woodev_Test_Price_Features_Method $method, array $package = [ 'contents_cost' => 1000 ] ): float {
		$method->calculate_shipping( $package );

		$this->assertCount( 1, $method->added_rates );

		return (float) $method->added_rates[0]['cost'];
	}

	// ---- keys and declaration ---------------------------------------------------------------------------

	/**
	 * The feature names and option keys are installed-site contracts: the new ones are the Yandex/CDEK keys, and the
	 * existing cost-limits and fee-payments ones did not move.
	 *
	 * @return void
	 */
	public function test_the_keys_are_pinned_and_the_existing_ones_are_unchanged(): void {
		$this->assertSame( 'cost-limits', Shipping_Method::FEATURE_COST_LIMITS );
		$this->assertSame( 'fee-payments', Shipping_Method::FEATURE_FEE_PAYMENTS );
		$this->assertSame( 'min_cost', Shipping_Method::OPTION_MIN_COST );
		$this->assertSame( 'max_cost', Shipping_Method::OPTION_MAX_COST );
		$this->assertSame( 'fee_payments', Fee_Payments::OPTION_KEY );

		$this->assertSame( 'markup', Shipping_Method::FEATURE_MARKUP );
		$this->assertSame( 'free-shipping', Shipping_Method::FEATURE_FREE_SHIPPING );
		$this->assertSame( 'rounding', Shipping_Method::FEATURE_ROUNDING );
		$this->assertSame( 'delivery-time', Shipping_Method::FEATURE_DELIVERY_TIME );

		$this->assertSame( 'fee', Shipping_Method::OPTION_MARKUP );
		$this->assertSame( 'fee_type', Shipping_Method::OPTION_MARKUP_BASE );
		$this->assertSame( 'free_cost', Shipping_Method::OPTION_FREE_SHIPPING_FROM );
		$this->assertSame( 'coupon_free_shipping', Shipping_Method::OPTION_FREE_SHIPPING_COUPON );
		$this->assertSame( 'round_cost', Shipping_Method::OPTION_ROUNDING );
		$this->assertSame( 'round_cost_range', Shipping_Method::OPTION_ROUNDING_STEP );
		$this->assertSame( 'show_delivery_time', Shipping_Method::OPTION_SHOW_DELIVERY_TIME );
		$this->assertSame( 'additional_time', Shipping_Method::OPTION_EXTRA_DELIVERY_DAYS );
	}

	/**
	 * A method that declares nothing gets no new control and an unchanged price, whatever is saved.
	 *
	 * @return void
	 */
	public function test_a_method_that_declares_nothing_gets_no_fields_and_an_unchanged_price(): void {
		$options = [
			'fee'                  => '50',
			'fee_type'             => 'shipping',
			'free_cost'            => '10',
			'coupon_free_shipping' => 'yes',
			'round_cost'           => 'ceil',
			'round_cost_range'     => '100',
			'show_delivery_time'   => 'no',
			'additional_time'      => '3',
		];
		$method  = $this->method( [], $options, 123.45 );

		$method->init_form_fields();

		foreach ( array_keys( $options ) as $key ) {
			$this->assertArrayNotHasKey( $key, $method->instance_form_fields );
		}

		$this->assertSame( 123.45, $this->price( $method, [ 'contents_cost' => 5000, 'applied_coupons' => [ 'free' ] ] ) );
		$untouched = $this->method( [], $options, 123.45 );

		$this->assertSame( $untouched->next_rate, $untouched->finished_rate( [ 'contents_cost' => 5000 ] ), 'the very rate the carrier returned' );
	}

	/**
	 * Each feature brings its own controls, keyed by its option keys, and only its own.
	 *
	 * @return void
	 */
	public function test_each_feature_brings_its_own_controls(): void {
		$expected = [
			Shipping_Method::FEATURE_MARKUP        => [ 'fee', 'fee_type' ],
			Shipping_Method::FEATURE_FREE_SHIPPING => [ 'free_cost', 'coupon_free_shipping' ],
			Shipping_Method::FEATURE_ROUNDING      => [ 'round_cost', 'round_cost_range' ],
			Shipping_Method::FEATURE_DELIVERY_TIME => [ 'show_delivery_time', 'additional_time' ],
		];
		$all      = array_merge( ...array_values( $expected ) );

		foreach ( $expected as $feature => $keys ) {
			$method = $this->method( [ $feature ] );
			$method->init_form_fields();

			foreach ( $all as $key ) {
				$this->assertSame( in_array( $key, $keys, true ), isset( $method->instance_form_fields[ $key ] ), $feature . ' / ' . $key );
			}

			foreach ( $keys as $key ) {
				$this->assertNotSame( '', $method->instance_form_fields[ $key ]['title'] );
				$this->assertNotSame( '', $method->instance_form_fields[ $key ]['desc_tip'] );
			}
		}
	}

	/**
	 * The defaults leave a new instance's price alone and show the delivery period.
	 *
	 * @return void
	 */
	public function test_the_defaults_change_nothing(): void {
		$method = $this->method( [ Shipping_Method::FEATURE_MARKUP, Shipping_Method::FEATURE_FREE_SHIPPING, Shipping_Method::FEATURE_ROUNDING, Shipping_Method::FEATURE_DELIVERY_TIME ] );
		$method->init_form_fields();

		$fields = $method->instance_form_fields;

		$this->assertSame( '', $fields['fee']['default'] );
		$this->assertSame( 'order', $fields['fee_type']['default'] );
		$this->assertSame( '', $fields['free_cost']['default'] );
		$this->assertSame( 'no', $fields['coupon_free_shipping']['default'] );
		$this->assertSame( 'none', $fields['round_cost']['default'] );
		$this->assertSame( '10', $fields['round_cost_range']['default'] );
		$this->assertSame( 'yes', $fields['show_delivery_time']['default'] );
		$this->assertSame( '', $fields['additional_time']['default'] );

		$this->assertSame( 100.0, $this->price( $method ) );
	}

	/**
	 * The coupon switch is offered only while the shop uses coupons.
	 *
	 * @return void
	 */
	public function test_the_coupon_switch_needs_coupons_in_the_shop(): void {
		$this->coupons_enabled = false;

		$method = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ] );
		$method->init_form_fields();

		$this->assertArrayHasKey( 'free_cost', $method->instance_form_fields );
		$this->assertArrayNotHasKey( 'coupon_free_shipping', $method->instance_form_fields );
	}

	/**
	 * A carrier that redefines an option constant keeps its installed sites' key, in the form and in the price.
	 *
	 * @return void
	 */
	public function test_a_carrier_can_keep_its_own_stored_key(): void {
		$method = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free' => '500' ], 100, Woodev_Test_Price_Features_Legacy_Key_Method::class );
		$method->init_form_fields();

		$this->assertArrayHasKey( 'free', $method->instance_form_fields );
		$this->assertArrayNotHasKey( 'free_cost', $method->instance_form_fields );
		$this->assertSame( 500.0, $method->get_free_shipping_threshold() );
		$this->assertSame( 0.0, $this->price( $method ) );
	}

	// ---- markup -----------------------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: array<string, string>, 1: array, 2: float}>
	 */
	public function markups(): array {
		return [
			'fixed amount'                       => [ [ 'fee' => '50' ], [ 'contents_cost' => 1000 ], 150.0 ],
			'percent of the order (default)'     => [ [ 'fee' => '10%' ], [ 'contents_cost' => 1000 ], 200.0 ],
			'percent of the shipping'            => [ [ 'fee' => '10%', 'fee_type' => 'shipping' ], [ 'contents_cost' => 1000 ], 110.0 ],
			'comma decimal'                      => [ [ 'fee' => ' 10,5 ' ], [ 'contents_cost' => 1000 ], 110.5 ],
			'empty is no markup'                 => [ [ 'fee' => '' ], [ 'contents_cost' => 1000 ], 100.0 ],
			'percent of the subtotal with coupons' => [ [ 'fee' => '10%' ], [ 'contents_cost' => 800, 'cart_subtotal' => 1000, 'applied_coupons' => [ 'sale' ] ], 200.0 ],
			'percent of an empty order is of the shipping' => [ [ 'fee' => '10%' ], [ 'contents_cost' => 0 ], 110.0 ],
		];
	}

	/**
	 * @dataProvider markups
	 * @param array<string, string> $options  saved options.
	 * @param array                 $package  package.
	 * @param float                 $expected price.
	 * @return void
	 */
	public function test_the_markup_is_added_to_the_carrier_price( array $options, array $package, float $expected ): void {
		$this->assertEqualsWithDelta( $expected, $this->price( $this->method( [ Shipping_Method::FEATURE_MARKUP ], $options ), $package ), 0.0001 );
	}

	/**
	 * With fee payments declared too, the markup applies only for the chosen payment methods.
	 *
	 * @return void
	 */
	public function test_the_markup_follows_the_fee_payments_list(): void {
		$features = [ Shipping_Method::FEATURE_MARKUP, Shipping_Method::FEATURE_FEE_PAYMENTS ];
		$options  = [
			'fee'          => '50',
			'fee_payments' => [ 'cod' ],
		];

		$card          = $this->method( $features, $options );
		$card->payment = 'card';
		$this->assertSame( 100.0, $this->price( $card ) );

		$cod          = $this->method( $features, $options );
		$cod->payment = 'cod';
		$this->assertSame( 150.0, $this->price( $cod ) );
	}

	// ---- free shipping ----------------------------------------------------------------------------------

	/**
	 * Above the threshold the rate is free; exactly at it the carrier price is quoted without the markup; below it the markup applies.
	 *
	 * @return void
	 */
	public function test_the_free_shipping_threshold(): void {
		$features = [ Shipping_Method::FEATURE_MARKUP, Shipping_Method::FEATURE_FREE_SHIPPING ];
		$options  = [
			'fee'       => '50',
			'free_cost' => '5000',
		];

		$this->assertSame( 0.0, $this->price( $this->method( $features, $options ), [ 'contents_cost' => 5000.01 ] ) );
		$this->assertSame( 100.0, $this->price( $this->method( $features, $options ), [ 'contents_cost' => 5000 ] ) );
		$this->assertSame( 150.0, $this->price( $this->method( $features, $options ), [ 'contents_cost' => 4999 ] ) );
		$this->assertSame( 150.0, $this->price( $this->method( $features, [ 'fee' => '50', 'free_cost' => '' ] ), [ 'contents_cost' => 99999 ] ), 'no threshold' );
	}

	/**
	 * With coupons applied the threshold is measured on the subtotal before discounts.
	 *
	 * @return void
	 */
	public function test_the_threshold_is_measured_on_the_subtotal_while_coupons_are_applied(): void {
		$package = [
			'contents_cost'   => 4000,
			'cart_subtotal'   => 6000,
			'applied_coupons' => [ 'sale' ],
		];

		$this->assertSame( 0.0, $this->price( $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '5000' ] ), $package ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: string[], 3: float}>
	 */
	public function coupons(): array {
		return [
			'switched on, coupon grants it'  => [ 'yes', true, [ 'free' ], 0.0 ],
			'switched off'                   => [ 'no', true, [ 'free' ], 100.0 ],
			'the shop does not use coupons'  => [ 'yes', false, [ 'free' ], 100.0 ],
			'the coupon is only a discount'  => [ 'yes', true, [ 'sale' ], 100.0 ],
		];
	}

	/**
	 * @dataProvider coupons
	 * @param string   $switch   saved switch.
	 * @param bool     $enabled  whether the shop uses coupons.
	 * @param string[] $applied  applied coupons.
	 * @param float    $expected price.
	 * @return void
	 */
	public function test_a_free_shipping_coupon( string $switch, bool $enabled, array $applied, float $expected ): void {
		$this->coupons_enabled = $enabled;

		$method                        = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'coupon_free_shipping' => $switch ] );
		$method->free_shipping_coupons = [ 'free' ];

		$this->assertSame( $expected, $this->price( $method, [ 'contents_cost' => 1000, 'cart_subtotal' => 1000, 'applied_coupons' => $applied ] ) );
	}

	// ---- rounding ---------------------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: float, 1: string, 2: string, 3: float}>
	 */
	public function roundings(): array {
		return [
			'none'                       => [ 107.0, 'none', '10', 107.0 ],
			'up to tens'                 => [ 101.0, 'ceil', '10', 110.0 ],
			'down to hundreds'           => [ 199.0, 'floor', '100', 100.0 ],
			'nearest whole'              => [ 107.5, 'round', 'round', 108.0 ],
			'up to whole'                => [ 107.2, 'ceil', 'round', 108.0 ],
			'float noise is not lifted'  => [ 100 * 1.07, 'ceil', 'round', 107.0 ],
			'unknown step is tens'       => [ 101.0, 'ceil', '7', 110.0 ],
			'unknown mode does nothing'  => [ 101.0, 'up', '10', 101.0 ],
			'free stays free'            => [ 0.0, 'ceil', '100', 0.0 ],
		];
	}

	/**
	 * @dataProvider roundings
	 * @param float  $cost     cost.
	 * @param string $mode     mode.
	 * @param string $step     step.
	 * @param float  $expected rounded.
	 * @return void
	 */
	public function test_a_cost_is_rounded_to_the_step( float $cost, string $mode, string $step, float $expected ): void {
		$this->assertSame( $expected, Shipping_Helper::round_cost_to_step( $cost, $mode, $step ) );

		$method = $this->method( [ Shipping_Method::FEATURE_ROUNDING ], [ 'round_cost' => $mode, 'round_cost_range' => $step ], $cost );
		$this->assertSame( $expected, $this->price( $method ) );
	}

	// ---- the order --------------------------------------------------------------------------------------

	/**
	 * Carrier price → markup → free shipping → rounding → cost limits.
	 *
	 * @return void
	 */
	public function test_the_features_apply_in_their_fixed_order(): void {
		$all = [ Shipping_Method::FEATURE_MARKUP, Shipping_Method::FEATURE_FREE_SHIPPING, Shipping_Method::FEATURE_ROUNDING, Shipping_Method::FEATURE_COST_LIMITS ];

		// the markup is rounded, not the carrier price: 95 + 7 = 102 → 110 (rounding first would give 100 + 7 = 107)
		$this->assertSame( 110.0, $this->price( $this->method( $all, [ 'fee' => '7', 'round_cost' => 'ceil' ], 95 ) ) );

		// the limits come after the rounding: 100 → 100 (ceil tens), then raised to the minimum 125, not rounded to 130
		$this->assertSame( 125.0, $this->price( $this->method( $all, [ 'round_cost' => 'ceil', 'min_cost' => '125' ], 100 ) ) );

		// the maximum holds the marked-up price
		$this->assertSame( 300.0, $this->price( $this->method( $all, [ 'fee' => '500', 'max_cost' => '300' ], 100 ) ) );

		// free wins over the markup and the rounding
		$this->assertSame( 0.0, $this->price( $this->method( $all, [ 'fee' => '500', 'free_cost' => '10', 'round_cost' => 'ceil' ], 101 ) ) );
	}

	/**
	 * A rate the free-shipping feature made free is not raised to the minimum cost.
	 *
	 * @return void
	 */
	public function test_a_rate_made_free_stays_free_under_the_minimum(): void {
		$method = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING, Shipping_Method::FEATURE_COST_LIMITS ], [ 'free_cost' => '10', 'min_cost' => '300' ] );

		$this->assertSame( 0.0, $this->price( $method ) );
	}

	/**
	 * A per-item cost array is scaled to the marked-up total, keeping the mix.
	 *
	 * @return void
	 */
	public function test_a_per_item_cost_array_is_scaled_to_the_new_total(): void {
		$method = $this->method( [ Shipping_Method::FEATURE_MARKUP ], [ 'fee' => '100' ], [ 'a' => 60, 'b' => 40 ] );

		$method->calculate_shipping( [ 'contents_cost' => 1000 ] );

		$cost = $method->added_rates[0]['cost'];

		$this->assertEqualsWithDelta( 120.0, $cost['a'], 0.0001 );
		$this->assertEqualsWithDelta( 80.0, $cost['b'], 0.0001 );
	}

	// ---- delivery time ----------------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string[], 1: array<string, string>, 2: int, 3: int, 4: string|null}>
	 */
	public function delivery_times(): array {
		$declared = [ Shipping_Method::FEATURE_DELIVERY_TIME ];

		return [
			'extra days on both ends'           => [ $declared, [ 'additional_time' => '1' ], 2, 4, '3-5 days' ],
			'one day'                           => [ $declared, [], 1, 0, '1 day' ],
			'switched off'                      => [ $declared, [ 'show_delivery_time' => 'no', 'additional_time' => '1' ], 2, 4, null ],
			'malformed extra days are none'     => [ $declared, [ 'additional_time' => 'abc' ], 2, 4, '2-4 days' ],
			'negative extra days are none'      => [ $declared, [ 'additional_time' => '-3' ], 2, 4, '2-4 days' ],
			'zero at both ends is not shown'    => [ $declared, [], 0, 0, null ],
			'zero plus extra days is shown'     => [ $declared, [ 'additional_time' => '2' ], 0, 0, '2 days' ],
			'undeclared: days without extras'   => [ [], [ 'additional_time' => '5', 'show_delivery_time' => 'no' ], 2, 4, '2-4 days' ],
		];
	}

	/**
	 * @dataProvider delivery_times
	 * @param string[]              $features declared features.
	 * @param array<string, string> $options  saved options.
	 * @param int                   $min      reported shortest delivery.
	 * @param int                   $max      reported longest delivery.
	 * @param string|null           $expected the rate's delivery_time.
	 * @return void
	 */
	public function test_the_reported_delivery_days_become_the_delivery_time( array $features, array $options, int $min, int $max, ?string $expected ): void {
		$method            = $this->method( $features, $options );
		$method->next_rate = $method->next_rate->with_delivery_days( $min, $max );

		$this->assertSame( $expected, $method->finished_rate( [ 'contents_cost' => 1000 ] )->get_arg( 'delivery_time' ) );
	}

	/**
	 * A merchant who switched the period off hides even one the carrier wrote as text.
	 *
	 * @return void
	 */
	public function test_switching_the_period_off_hides_a_text_period_too(): void {
		$method            = $this->method( [ Shipping_Method::FEATURE_DELIVERY_TIME ], [ 'show_delivery_time' => 'no' ] );
		$method->next_rate = $method->next_rate->with_args( [ 'delivery_time' => 'tomorrow' ] );

		$this->assertSame( '', $method->finished_rate( [] )->get_arg( 'delivery_time' ) );

		$shown            = $this->method( [ Shipping_Method::FEATURE_DELIVERY_TIME ] );
		$shown->next_rate = $shown->next_rate->with_args( [ 'delivery_time' => 'tomorrow' ] );

		$this->assertSame( 'tomorrow', $shown->finished_rate( [] )->get_arg( 'delivery_time' ) );
	}

	/**
	 * The reported days never reach `add_rate()`.
	 *
	 * @return void
	 */
	public function test_the_delivery_days_stay_out_of_add_rate(): void {
		$rate = ( new Shipping_Rate( 'm', 'm:1', 'Courier', 100 ) )->with_delivery_days( 3, 1 );

		$this->assertSame(
			[
				'min' => 3,
				'max' => 3,
			],
			$rate->get_delivery_days()
		);
		$this->assertArrayNotHasKey( Shipping_Rate::ARG_DELIVERY_DAYS, $rate->to_array() );
		$this->assertNull( ( new Shipping_Rate( 'm', 'm:1', 'Courier', 100 ) )->get_delivery_days() );
	}

	// ---- rate cache -------------------------------------------------------------------------------------

	/**
	 * The coupons and the measured order amount key the cache of a method whose price depends on them, and only of such a method.
	 *
	 * @return void
	 */
	public function test_the_cache_key_carries_the_coupons_only_when_the_price_depends_on_them(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$package = [
			'contents_cost'   => 800,
			'cart_subtotal'   => 1000,
			'applied_coupons' => [ 'b', 'a' ],
		];

		$declared = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ] )->get_rate_cache_context( $package );

		$this->assertSame(
			[
				'coupons' => [ 'a', 'b' ],
				'amount'  => 1000.0,
			],
			$declared['price']
		);

		$this->assertArrayNotHasKey( 'price', $this->method( [ Shipping_Method::FEATURE_ROUNDING ] )->get_rate_cache_context( $package ) );
		$this->assertArrayNotHasKey( 'price', $this->method( [] )->get_rate_cache_context( $package ) );
	}
}

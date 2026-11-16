<?php
/**
 * The price features a shipping method declares (#1417): markup, free shipping, rounding and the delivery time —
 * their controls, the order the framework applies them in, and how they meet the existing cost limits.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\{Fee_Payments, Order_Amount, Shipping_Helper, Shipping_Method, Shipping_Rate};
use Woodev\Tests\Unit\TestCase;

// the cost-limits probe (and through it the rate-cache probe and the `WC_Shipping_Method` stub) lives there
require_once __DIR__ . '/CostLimitsTest.php';

// the amount the cart puts on the package, which the method reads back
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-order-amount.php';

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
	 * How many times the packing step ran.
	 *
	 * @var int
	 */
	public int $pack_calls = 0;

	/**
	 * Counts the packing step.
	 *
	 * @param array $package package.
	 * @return \Woodev_Packer_Result|null
	 */
	protected function pack_package( array $package ): ?\Woodev_Packer_Result {
		++$this->pack_calls;

		return parent::pack_package( $package );
	}

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
 * A carrier whose installed sites store the order-amount limits under CDEK's keys.
 */
class Woodev_Test_Price_Features_Order_Amount_Legacy_Key_Method extends Woodev_Test_Price_Features_Method {

	/**
	 * The CDEK minimum key.
	 *
	 * @var string
	 */
	const OPTION_MIN_ORDER_AMOUNT = 'min_price';

	/**
	 * The CDEK maximum key.
	 *
	 * @var string
	 */
	const OPTION_MAX_ORDER_AMOUNT = 'max_price';
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

		Order_Amount::reset();
		Order_Amount::use_runtime_for_tests( null, 2 );

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
	 * Forgets the cart stand-in.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Order_Amount::reset();

		parent::tearDown();
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
	private function method(
		array $features,
		array $options = [],
		$cost = 100,
		string $class = Woodev_Test_Price_Features_Method::class
	): Woodev_Test_Price_Features_Method {
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
		$this->assertSame( 'order-amount-limits', Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS );

		$this->assertSame( 'fee', Shipping_Method::OPTION_MARKUP );
		$this->assertSame( 'fee_type', Shipping_Method::OPTION_MARKUP_BASE );
		$this->assertSame( 'free_cost', Shipping_Method::OPTION_FREE_SHIPPING_FROM );
		$this->assertSame( 'coupon_free_shipping', Shipping_Method::OPTION_FREE_SHIPPING_COUPON );
		$this->assertSame( 'free_before_discount', Shipping_Method::OPTION_FREE_SHIPPING_BEFORE_DISCOUNT );
		$this->assertSame( 'round_cost', Shipping_Method::OPTION_ROUNDING );
		$this->assertSame( 'round_cost_range', Shipping_Method::OPTION_ROUNDING_STEP );
		$this->assertSame( 'show_delivery_time', Shipping_Method::OPTION_SHOW_DELIVERY_TIME );
		$this->assertSame( 'additional_time', Shipping_Method::OPTION_EXTRA_DELIVERY_DAYS );
		$this->assertSame( 'min_order_cost', Shipping_Method::OPTION_MIN_ORDER_AMOUNT );
		$this->assertSame( 'max_order_cost', Shipping_Method::OPTION_MAX_ORDER_AMOUNT );
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
			Shipping_Method::FEATURE_FREE_SHIPPING => [ 'free_cost', 'free_before_discount', 'coupon_free_shipping' ],
			Shipping_Method::FEATURE_ROUNDING      => [ 'round_cost', 'round_cost_range' ],
			Shipping_Method::FEATURE_DELIVERY_TIME => [ 'show_delivery_time', 'additional_time' ],
			Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS => [ 'min_order_cost', 'max_order_cost' ],
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
		$method = $this->method(
			[
				Shipping_Method::FEATURE_MARKUP,
				Shipping_Method::FEATURE_FREE_SHIPPING,
				Shipping_Method::FEATURE_ROUNDING,
				Shipping_Method::FEATURE_DELIVERY_TIME,
				Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS,
			]
		);
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
		$this->assertSame( '', $fields['min_order_cost']['default'] );
		$this->assertSame( '', $fields['max_order_cost']['default'] );

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
		$method = $this->method(
			[ Shipping_Method::FEATURE_FREE_SHIPPING ],
			[ 'free' => '500' ],
			100,
			Woodev_Test_Price_Features_Legacy_Key_Method::class
		);
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
			'percent of the amount after coupons' => [
				[ 'fee' => '10%' ],
				[
					'contents_cost'   => 800,
					'cart_subtotal'   => 1000,
					'applied_coupons' => [ 'sale' ],
				],
				180.0,
			],
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
		$no_threshold = $this->method( $features, [ 'fee' => '50', 'free_cost' => '' ] );

		$this->assertSame( 150.0, $this->price( $no_threshold, [ 'contents_cost' => 99999 ] ), 'no threshold' );
	}

	/**
	 * The package as the cart delivers it to WooCommerce: what the applied coupons took off, and whether prices are
	 * shown with tax, decide the amount the cart puts on the package ({@see Order_Amount}).
	 *
	 * @param array $package      the package WooCommerce builds.
	 * @param float $discount     discount total (without tax).
	 * @param float $discount_tax tax on the discount.
	 * @param bool  $with_tax     whether prices are displayed with tax.
	 * @param int   $decimals     the shop's price decimals.
	 * @return array
	 */
	private function through_cart( array $package, float $discount, float $discount_tax = 0.0, bool $with_tax = false, int $decimals = 2 ): array {

		Order_Amount::use_runtime_for_tests(
			new class( $discount, $discount_tax, $with_tax ) {
				private $discount;
				private $discount_tax;
				private $with_tax;

				public function __construct( $discount, $discount_tax, $with_tax ) {
					$this->discount     = $discount;
					$this->discount_tax = $discount_tax;
					$this->with_tax     = $with_tax;
				}

				public function get_discount_total() {
					return $this->discount;
				}

				public function get_discount_tax() {
					return $this->discount_tax;
				}

				public function display_prices_including_tax() {
					return $this->with_tax;
				}
			},
			$decimals
		);

		return Order_Amount::add_amount_to_packages( [ $package ] )[0];
	}

	/**
	 * The card's case: threshold 7900, goods 9023, a coupon takes 2707 off, 6316 is left to pay — the delivery is
	 * paid by default, and stays free for a shop that chose to measure the threshold before the discount.
	 *
	 * @return void
	 */
	public function test_a_coupon_that_takes_the_order_under_the_threshold_makes_delivery_paid(): void {
		$package = [
			'contents_cost'   => 6316,
			'cart_subtotal'   => 9023,
			'applied_coupons' => [ 'sale' ],
		];

		$package = $this->through_cart( $package, 2707 );

		$default = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '7900' ] );

		$this->assertSame( 100.0, $this->price( $default, $package ), 'default: after the discount' );

		$before = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '7900', 'free_before_discount' => 'yes' ] );

		$this->assertSame( 0.0, $this->price( $before, $package ), 'the option on: the amount before the discount' );
	}

	/**
	 * A coupon that leaves the order above the threshold keeps the delivery free.
	 *
	 * @return void
	 */
	public function test_a_coupon_that_leaves_the_order_above_the_threshold_keeps_delivery_free(): void {
		$method  = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '5000' ] );
		$package = $this->through_cart( [ 'contents_cost' => 8023, 'cart_subtotal' => 9023, 'applied_coupons' => [ 'sale' ] ], 1000 );

		$this->assertSame( 0.0, $this->price( $method, $package ) );
	}

	/**
	 * Without a coupon the same amount is measured: the cart subtotal, nothing taken off.
	 *
	 * @return void
	 */
	public function test_the_threshold_without_a_coupon_is_measured_on_the_subtotal(): void {
		$options = [ 'free_cost' => '7900' ];

		$this->assertSame( 0.0, $this->price( $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), [ 'contents_cost' => 9023, 'cart_subtotal' => 9023 ] ) );
		$this->assertSame( 100.0, $this->price( $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), [ 'contents_cost' => 7000, 'cart_subtotal' => 7000 ] ) );
	}

	/**
	 * Prices shown with tax: the tax on the discount is taken off too, as WooCommerce's Free shipping does; shown
	 * without tax it is not.
	 *
	 * @return void
	 */
	public function test_the_tax_on_the_discount_is_taken_off_only_when_prices_include_tax(): void {
		$package = [
			'contents_cost'   => 7000,
			'cart_subtotal'   => 9000,
			'applied_coupons' => [ 'sale' ],
		];

		// 9000 - 1000 - 200 = 7800 is under 7900
		$with_tax = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '7900' ] );

		$this->assertSame( 100.0, $this->price( $with_tax, $this->through_cart( $package, 1000, 200, true ) ) );

		// 9000 - 1000 = 8000 is above 7900: the discount tax of an ex-tax display is not part of the amount
		$without_tax = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '7900' ] );

		$this->assertSame( 0.0, $this->price( $without_tax, $this->through_cart( $package, 1000, 200, false ) ) );
	}

	/**
	 * Exactly at the threshold after the discount the order is not free yet; float noise does not move it.
	 *
	 * @return void
	 */
	public function test_exactly_at_the_threshold_after_the_discount_is_not_free(): void {
		$method  = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '7900.1' ] );
		$package = $this->through_cart( [ 'contents_cost' => 7900.1, 'cart_subtotal' => 9000, 'applied_coupons' => [ 'sale' ] ], 1099.9 );

		$this->assertSame( 100.0, $this->price( $method, $package ) );
	}

	/**
	 * A sum that picked up float noise is the money it is: a tax-inclusive subtotal 1000.10 + 200.02 is
	 * `1200.1200000000001` as a float, and at a threshold or a limit of exactly 1200.12 the order is AT it — not
	 * above (no free delivery) and not over (the method stays offered).
	 *
	 * @return void
	 */
	public function test_a_package_without_a_coupon_is_compared_at_the_shop_precision(): void {
		$subtotal = 1000.10 + 200.02;
		$package  = [ 'contents_cost' => 1000.10, 'cart_subtotal' => $subtotal ];

		$this->assertNotSame( 1200.12, $subtotal, 'the raw float carries noise' );

		$threshold = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '1200.12' ] );

		$this->assertSame( 100.0, $this->price( $threshold, $package ), 'at the threshold, strict >' );

		$maximum = $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], [ 'max_order_cost' => '1200.12' ] );

		$this->assertSame( 100.0, $this->price( $maximum, $package ), 'an amount equal to the maximum is inside' );

		$minimum = $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], [ 'min_order_cost' => '1200.12' ] );

		$this->assertSame( 100.0, $this->price( $minimum, $package ), 'an amount equal to the minimum is inside' );
	}

	/**
	 * The admin order wizard sums its lines as floats (`79.90 + 39.90` is `119.80000000000001`) and sends no coupon and
	 * no prepared amount: exactly at the threshold the carrier's price is quoted.
	 *
	 * @return void
	 */
	public function test_a_wizard_package_at_the_threshold_is_not_free(): void {
		$cost = 0.0;

		foreach ( [ 79.90, 39.90 ] as $line_total ) {
			$cost += $line_total;
		}

		$package = [ 'contents_cost' => $cost, 'cart_subtotal' => $cost, 'applied_coupons' => [] ];

		$this->assertNotSame( 119.8, $cost );
		$this->assertSame( 100.0, $this->price( $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '119.80' ] ), $package ) );
	}

	/**
	 * The same normalising applies to the threshold measured before the discount, and to a coupon package the cart
	 * did not annotate (its contents cost).
	 *
	 * @return void
	 */
	public function test_the_other_amount_sources_are_normalised_too(): void {
		$noisy = 1000.10 + 200.02;

		$before = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '1200.12', 'free_before_discount' => 'yes' ] );

		$this->assertSame( 100.0, $this->price( $before, [ 'contents_cost' => 1, 'cart_subtotal' => $noisy, 'applied_coupons' => [ 'sale' ] ] ) );

		$unannotated = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '1200.12' ] );

		$this->assertSame( 100.0, $this->price( $unannotated, [ 'contents_cost' => $noisy, 'cart_subtotal' => 5000, 'applied_coupons' => [ 'sale' ] ] ) );
	}

	/**
	 * A shop with three price decimals: 11.004 less 1 is 10.004, above the threshold 10.002 although two decimals
	 * would make it 10.00; and exactly 10.002 is still not free.
	 *
	 * @return void
	 */
	public function test_the_amount_keeps_the_shop_price_decimals(): void {
		$options = [ 'free_cost' => '10.002' ];
		$coupon  = [ 'contents_cost' => 10.004, 'cart_subtotal' => 11.004, 'applied_coupons' => [ 'sale' ] ];

		$above = $this->through_cart( $coupon, 1.0, 0.0, false, 3 );

		$this->assertSame( '10.004', $above[ Order_Amount::PACKAGE_KEY ] );
		$this->assertSame( 0.0, $this->price( $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), $above ) );

		$equal = $this->through_cart( $coupon, 1.002, 0.0, false, 3 );

		$this->assertSame( '10.002', $equal[ Order_Amount::PACKAGE_KEY ] );
		$this->assertSame( 100.0, $this->price( $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), $equal ), 'at the threshold, strict >' );

		$minimum = $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], [ 'min_order_cost' => '10.002' ] );
		$minimum->calculate_shipping( $above );

		$this->assertCount( 1, $minimum->added_rates, '10.004 clears the minimum 10.002' );
	}

	/**
	 * A coupon package with no cart behind it (nothing to ask) is measured on the contents cost, which is already
	 * after the discounts.
	 *
	 * @return void
	 */
	public function test_a_coupon_package_without_a_cart_uses_the_contents_cost(): void {
		$method = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_cost' => '5000' ] );

		$this->assertSame( 100.0, $this->price( $method, [ 'contents_cost' => 4000, 'cart_subtotal' => 6000, 'applied_coupons' => [ 'sale' ] ] ) );
	}

	/**
	 * The markup and the order-amount limits follow the amount after the discounts, whatever the threshold option says.
	 *
	 * @return void
	 */
	public function test_the_markup_and_the_limits_follow_the_amount_after_the_discounts(): void {
		$package = [
			'contents_cost'   => 400,
			'cart_subtotal'   => 1000,
			'applied_coupons' => [ 'sale' ],
		];

		$package = $this->through_cart( $package, 600 );
		$method  = $this->method(
			[ Shipping_Method::FEATURE_MARKUP, Shipping_Method::FEATURE_FREE_SHIPPING, Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ],
			[ 'fee' => '10%', 'free_before_discount' => 'yes', 'min_order_cost' => '500' ]
		);

		$method->calculate_shipping( $package );

		$this->assertCount( 0, $method->added_rates, '400 after the discount is under the minimum 500' );

		$method = $this->method(
			[ Shipping_Method::FEATURE_MARKUP, Shipping_Method::FEATURE_FREE_SHIPPING ],
			[ 'fee' => '10%', 'free_before_discount' => 'yes' ]
		);

		$this->assertSame( 140.0, $this->price( $method, $package ), '10 % of 400, not of 1000' );
	}

	// ---- the amount on the package, through WooCommerce's session rate cache ------------------------------

	/**
	 * What `WC_Shipping::calculate_shipping_for_package()` does with a package: the rates are kept in the session
	 * under the package's hash, and served while the hash is unchanged — the shipping method is not called.
	 *
	 * @param array                              $session session store, by reference.
	 * @param Woodev_Test_Price_Features_Method $method  probe.
	 * @param array                              $package package as the cart filter delivered it.
	 * @return float|null the cost WooCommerce offers, `null` when the method offers no rate.
	 */
	private function calculate_like_woocommerce( array &$session, Woodev_Test_Price_Features_Method $method, array $package ) {

		$hash = 'wc_ship_' . md5( (string) json_encode( $package ) );

		if ( ! array_key_exists( $hash, $session ) ) {
			$method->added_rates = [];
			$method->calculate_shipping( $package );

			$session[ $hash ] = $method->added_rates[0]['cost'] ?? null;
		}

		return null === $session[ $hash ] ? null : (float) $session[ $hash ];
	}

	/**
	 * One physical product (8000) and two virtual ones (2000 each); the coupon takes 10 % off the first two and not
	 * the third. The package holds only the physical product, so changing the virtual quantities moves the discount
	 * while the package's lines, subtotal and coupon code stay the same.
	 *
	 * @param int $virtual_a quantity of the discounted virtual product.
	 * @param int $virtual_b quantity of the excluded virtual product.
	 * @return array the package after the cart filter.
	 */
	private function cart_state( int $virtual_a, int $virtual_b ): array {

		$package = [
			'contents'        => [ 'p' => [ 'product_id' => 1, 'quantity' => 1, 'line_total' => 7200 ] ],
			'contents_cost'   => 7200,
			'cart_subtotal'   => 8000 + 2000 * $virtual_a + 2000 * $virtual_b,
			'applied_coupons' => [ 'sale' ],
		];

		return $this->through_cart( $package, 800 + 200 * $virtual_a );
	}

	/**
	 * A change of quantities that moves the coupon discount but not the subtotal or the codes reaches the method:
	 * the free rate is not served from the session once the order falls under the threshold — and back.
	 *
	 * @return void
	 */
	public function test_a_moved_discount_is_a_new_package_for_the_session_rate_cache(): void {
		$options = [ 'free_cost' => '12900' ];
		$session = [];

		// subtotal 14000 less 1000 is 13000: free
		$first = $this->cart_state( 1, 2 );
		$this->assertSame( 0.0, $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), $first ) );

		// the same subtotal, the same codes, the same lines, 1200 off: 12800 is under the threshold
		$second = $this->cart_state( 2, 1 );
		$this->assertSame( $first['cart_subtotal'], $second['cart_subtotal'] );
		$this->assertSame( 100.0, $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), $second ) );

		// and back: the first package is served from the session, still free
		$this->assertSame( 0.0, $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), $this->cart_state( 1, 2 ) ) );
		$this->assertCount( 2, $session, 'two packages, two hashes' );
	}

	/**
	 * The reverse transition: a paid rate is not kept once a smaller discount brings the order above the threshold.
	 *
	 * @return void
	 */
	public function test_a_discount_that_shrinks_makes_delivery_free_again(): void {
		$options = [ 'free_cost' => '12900' ];
		$session = [];

		$this->assertSame( 100.0, $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), $this->cart_state( 2, 1 ) ) );
		$this->assertSame( 0.0, $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options ), $this->cart_state( 1, 2 ) ) );
	}

	/**
	 * The minimum order amount follows a moved discount too: the method is hidden when the discount takes the order
	 * under it, and offered again when the discount shrinks.
	 *
	 * @return void
	 */
	public function test_the_order_amount_limit_follows_a_moved_discount_through_the_session_cache(): void {
		$options = [ 'min_order_cost' => '12900' ];
		$session = [];

		$this->assertSame( 100.0, $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], $options ), $this->cart_state( 1, 2 ) ), '13000 clears the minimum' );
		$this->assertNull( $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], $options ), $this->cart_state( 2, 1 ) ), '12800 does not' );
		$this->assertSame( 100.0, $this->calculate_like_woocommerce( $session, $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], $options ), $this->cart_state( 1, 2 ) ) );
	}

	/**
	 * A cart without a coupon keeps its package exactly as WooCommerce built it, and so do its hashes.
	 *
	 * @return void
	 */
	public function test_a_package_without_a_coupon_is_left_alone(): void {
		Order_Amount::use_runtime_for_tests( $this->through_cart_stub(), 2 );

		$package = [ 'contents_cost' => 1000, 'cart_subtotal' => 1000, 'applied_coupons' => [] ];

		$this->assertSame( [ $package ], Order_Amount::add_amount_to_packages( [ $package ] ) );
		$this->assertSame( 'not packages', Order_Amount::add_amount_to_packages( 'not packages' ) );
		$this->assertSame( [], Order_Amount::add_amount_to_packages( [] ) );
	}

	/**
	 * A package with no numeric subtotal gets nothing: there is nothing to take the discount off.
	 *
	 * @return void
	 */
	public function test_a_package_without_a_subtotal_gets_no_amount(): void {
		Order_Amount::use_runtime_for_tests( $this->through_cart_stub(), 2 );

		$package = [ 'contents_cost' => 1000, 'applied_coupons' => [ 'sale' ] ];

		$this->assertSame( [ $package ], Order_Amount::add_amount_to_packages( [ $package ] ) );
	}

	/**
	 * The filter is wired once, however many plugins register it.
	 *
	 * @return void
	 */
	public function test_the_amount_filter_is_wired_once(): void {
		\Brain\Monkey\Filters\expectAdded( 'woocommerce_cart_shipping_packages' )->once();

		Order_Amount::register();
		Order_Amount::register();

		$this->assertSame( 1, \Brain\Monkey\Filters\has( 'woocommerce_cart_shipping_packages', [ Order_Amount::class, 'add_amount_to_packages' ] ) ? 1 : 0 );
	}

	/**
	 * A cart stand-in that took nothing off.
	 *
	 * @return object
	 */
	private function through_cart_stub(): object {
		return new class() {
			public function get_discount_total() {
				return 0.0;
			}

			public function get_discount_tax() {
				return 0.0;
			}

			public function display_prices_including_tax() {
				return false;
			}
		};
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
		$all = [
			Shipping_Method::FEATURE_MARKUP,
			Shipping_Method::FEATURE_FREE_SHIPPING,
			Shipping_Method::FEATURE_ROUNDING,
			Shipping_Method::FEATURE_COST_LIMITS,
		];

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
		$method = $this->method(
			[ Shipping_Method::FEATURE_FREE_SHIPPING, Shipping_Method::FEATURE_COST_LIMITS ],
			[
				'free_cost' => '10',
				'min_cost'  => '300',
			]
		);

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

	/**
	 * A per-item array that sums to 0 still carries a positive markup, in one extra entry.
	 *
	 * @return void
	 */
	public function test_a_zero_total_cost_array_keeps_a_positive_markup(): void {
		$array  = $this->method( [ Shipping_Method::FEATURE_MARKUP ], [ 'fee' => '50' ], [ 'a' => 0, 'b' => 0 ] );
		$scalar = $this->method( [ Shipping_Method::FEATURE_MARKUP ], [ 'fee' => '50' ], 0 );

		$array->calculate_shipping( [ 'contents_cost' => 1000 ] );

		$cost = $array->added_rates[0]['cost'];

		$this->assertSame( 50.0, (float) array_sum( $cost ) );
		$this->assertSame( 0.0, (float) $cost['a'] );
		$this->assertSame( 0.0, (float) $cost['b'] );
		$this->assertSame( 50.0, $this->price( $scalar ), 'the scalar zero is unchanged' );
	}

	/**
	 * A zero-total array stays a zero-total array when nothing is added to it.
	 *
	 * @return void
	 */
	public function test_a_zero_total_cost_array_without_a_markup_stays_free(): void {
		$method = $this->method( [ Shipping_Method::FEATURE_MARKUP ], [ 'fee' => '' ], [ 'a' => 0, 'b' => 0 ] );

		$method->calculate_shipping( [ 'contents_cost' => 1000 ] );

		$this->assertSame( 0.0, (float) array_sum( $method->added_rates[0]['cost'] ) );
		$this->assertArrayNotHasKey( 'woodev_surcharge', $method->added_rates[0]['cost'] );
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
	public function test_the_reported_delivery_days_become_the_delivery_time(
		array $features,
		array $options,
		int $min,
		int $max,
		?string $expected
	): void {
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

		$package  = $this->through_cart( $package, 200 );
		$declared = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ] )->get_rate_cache_context( $package );

		$this->assertSame(
			[
				'coupons' => [ 'a', 'b' ],
				'amount'  => 800.0,
				'coupon_free' => false,
			],
			$declared['price']
		);

		$before = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], [ 'free_before_discount' => 'yes' ] );

		$this->assertSame( 1000.0, $before->get_rate_cache_context( $package )['price']['threshold_amount'], 'the option on: the second amount keys the cache' );
		$this->assertArrayNotHasKey( 'threshold_amount', $declared['price'] );

		$this->assertArrayNotHasKey( 'price', $this->method( [ Shipping_Method::FEATURE_ROUNDING ] )->get_rate_cache_context( $package ) );
		$this->assertArrayNotHasKey( 'coupon_free', $this->method( [ Shipping_Method::FEATURE_MARKUP ] )->get_rate_cache_context( $package )['price'] );
		$this->assertArrayNotHasKey( 'price', $this->method( [] )->get_rate_cache_context( $package ) );
	}

	/**
	 * A coupon's free-shipping flag edited behind an unchanged code, and the shop-wide coupon switch, change the cache key.
	 *
	 * @return void
	 */
	public function test_the_effective_free_shipping_coupon_is_part_of_the_cache_identity(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$package = [
			'contents_cost'   => 1000,
			'applied_coupons' => [ 'sale' ],
		];
		$options = [ 'coupon_free_shipping' => 'yes' ];

		$key = function ( Woodev_Test_Price_Features_Method $method ) use ( $package ): string {
			return (string) wp_json_encode( $method->get_rate_cache_context( $package ) );
		};

		$paid = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options );
		$free = $this->method( [ Shipping_Method::FEATURE_FREE_SHIPPING ], $options );

		$free->free_shipping_coupons = [ 'sale' ];

		$this->assertNotSame( $key( $paid ), $key( $free ), 'the coupon flag was switched on behind the same code' );

		$enabled_key = $key( $free );

		$this->coupons_enabled = false;

		$this->assertNotSame( $enabled_key, $key( $free ), 'the shop switched its coupons off' );
		$this->assertSame( $key( $paid ), $key( $free ), 'with coupons off the flagged coupon no longer counts' );
	}

	// ---- order-amount limits ----------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: array<string, string>, 1: array<string, mixed>, 2: bool}>
	 */
	public function order_amount_limits(): array {
		$limits = [
			'min_order_cost' => '500',
			'max_order_cost' => '2000',
		];

		return [
			'below the minimum'         => [ $limits, [ 'contents_cost' => 499.99 ], false ],
			'at the minimum'            => [ $limits, [ 'contents_cost' => 500 ], true ],
			'inside'                    => [ $limits, [ 'contents_cost' => 1000 ], true ],
			'at the maximum'            => [ $limits, [ 'contents_cost' => 2000 ], true ],
			'above the maximum'         => [ $limits, [ 'contents_cost' => 2000.01 ], false ],
			'only a minimum, below'     => [ [ 'min_order_cost' => '500' ], [ 'contents_cost' => 100 ], false ],
			'only a minimum, far above' => [ [ 'min_order_cost' => '500' ], [ 'contents_cost' => 999999 ], true ],
			'only a maximum, above'     => [ [ 'max_order_cost' => '2000' ], [ 'contents_cost' => 2500 ], false ],
			'only a maximum, zero cart' => [ [ 'max_order_cost' => '2000' ], [ 'contents_cost' => 0 ], true ],
			'no bound'                  => [ [], [ 'contents_cost' => 999999 ], true ],
			'empty bounds'              => [ [ 'min_order_cost' => '', 'max_order_cost' => '' ], [ 'contents_cost' => 0 ], true ],
			'zero is no bound'          => [ [ 'min_order_cost' => '0', 'max_order_cost' => '0' ], [ 'contents_cost' => 5000 ], true ],
			'a comma decimal'           => [ [ 'min_order_cost' => '500,5' ], [ 'contents_cost' => 500.4 ], false ],
		];
	}

	/**
	 * The method is offered only inside the inclusive bounds; the carrier is not called outside them.
	 *
	 * @dataProvider order_amount_limits
	 *
	 * @param array<string, string> $options  saved bounds.
	 * @param array<string, mixed>  $package  package.
	 * @param bool                  $expected whether the method is offered.
	 * @return void
	 */
	public function test_the_method_is_offered_only_inside_the_order_amount_limits( array $options, array $package, bool $expected ): void {
		$method = $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], $options );

		$method->calculate_shipping( $package );

		$this->assertCount( $expected ? 1 : 0, $method->added_rates );
		$this->assertSame( $expected ? 1 : 0, $method->carrier_calls, 'no carrier call outside the bounds' );
	}

	/**
	 * Outside the bounds nothing past availability runs: no cache lookup, no packing, no carrier call.
	 *
	 * @return void
	 */
	public function test_an_order_outside_the_limits_reaches_neither_the_cache_nor_the_packing(): void {
		Functions\when( 'get_transient' )->alias(
			function (): bool {
				$this->fail( 'the rate cache was read for an order outside the limits' );
			}
		);

		$method = $this->method(
			[ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS, Shipping_Method::FEATURE_RATE_CACHE, Shipping_Method::FEATURE_BOX_PACKING ],
			[ 'max_order_cost' => '2000' ]
		);

		$method->calculate_shipping( [ 'contents_cost' => 5000 ] );

		$this->assertSame( [], $method->added_rates );
		$this->assertSame( 0, $method->pack_calls );
		$this->assertSame( 0, $method->carrier_calls );
	}

	/**
	 * The amount is the cart subtotal when the package has one, else the contents cost — the donors' rule, which differs
	 * from the price features' amount when a subtotal comes without a coupon.
	 *
	 * @return void
	 */
	public function test_the_order_amount_is_the_subtotal_before_the_contents_cost(): void {
		$method = $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], [ 'min_order_cost' => '500' ] );

		$method->calculate_shipping(
			[
				'contents_cost' => 300,
				'cart_subtotal' => 900,
			]
		);

		$this->assertCount( 1, $method->added_rates, 'the subtotal 900 clears the minimum, no coupon applied' );

		$method = $this->method( [ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ], [ 'max_order_cost' => '500' ] );

		$method->calculate_shipping(
			[
				'contents_cost' => 300,
				'cart_subtotal' => 900,
			]
		);

		$this->assertCount( 0, $method->added_rates, 'the subtotal 900 exceeds the maximum although the contents cost does not' );
	}

	/**
	 * A method that did not declare the feature ignores saved bounds, and a carrier can keep its own stored keys.
	 *
	 * @return void
	 */
	public function test_undeclared_bounds_are_ignored_and_the_keys_can_be_redefined(): void {
		$undeclared = $this->method( [], [ 'min_order_cost' => '500' ] );

		$undeclared->init_form_fields();

		$this->assertArrayNotHasKey( 'min_order_cost', $undeclared->instance_form_fields );
		$this->assertSame( 100.0, $this->price( $undeclared, [ 'contents_cost' => 1 ] ) );

		$legacy = $this->method(
			[ Shipping_Method::FEATURE_ORDER_AMOUNT_LIMITS ],
			[ 'min_price' => '500' ],
			100,
			Woodev_Test_Price_Features_Order_Amount_Legacy_Key_Method::class
		);

		$legacy->init_form_fields();

		$this->assertArrayHasKey( 'min_price', $legacy->instance_form_fields );
		$this->assertArrayNotHasKey( 'min_order_cost', $legacy->instance_form_fields );

		$legacy->calculate_shipping( [ 'contents_cost' => 100 ] );

		$this->assertSame( [], $legacy->added_rates );
	}
}

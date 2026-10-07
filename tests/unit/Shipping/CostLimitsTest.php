<?php
/**
 * «Minimum / maximum delivery cost» for a carrier whose price is calculated: the helpers, the
 * framework step that applies them to the finished rate, and the admin form around them.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Shipping_Helper;
use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Tests\Unit\TestCase;

// the method probe and the `WC_Shipping_Method` stub live there; whichever file loads first defines them
require_once __DIR__ . '/ShippingRateCacheTest.php';

/** The probe: records the cost handed to `add_rate()` and answers the post-data seam the validators read. */
class Woodev_Test_Cost_Limits_Method extends Woodev_Test_Shipping_Method_For_Rate_Cache {

	/** @var array<int, array> every args array handed to add_rate(). */
	public array $added_rates = [];

	/** @var array<string, mixed> what the settings form posted. */
	public array $post = [];

	/** @var \Woodev_Packer_Result|null what the packing step answers (box-packing tests). */
	public ?\Woodev_Packer_Result $packed = null;

	/**
	 * @param array $package package.
	 * @return \Woodev_Packer_Result|null
	 */
	protected function pack_package( array $package ): ?\Woodev_Packer_Result {
		return $this->packed;
	}

	/**
	 * @param array $args rate args.
	 * @return void
	 */
	public function add_rate( $args = [] ) {
		parent::add_rate( $args );

		$this->added_rates[] = $args;
	}

	/** @return array */
	public function get_post_data() {
		return $this->post;
	}

	/**
	 * @param string $key option key.
	 * @return string
	 */
	public function get_field_key( $key ) {
		return 'woocommerce_cost-limits_' . $key;
	}

	/**
	 * @param string $key   key.
	 * @param mixed  $value posted value.
	 * @return string
	 */
	public function validate_price_field( $key, $value ) {
		return '' === (string) $value ? '' : (string) $value;
	}
}

/**
 * @coversDefaultClass \Woodev\Framework\Shipping\Shipping_Method
 */
final class CostLimitsTest extends TestCase {

	/** @return void */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wc_get_weight' )->returnArg( 1 );
		Functions\when( 'wc_get_dimension' )->returnArg( 1 );
		Functions\when( 'wc_format_decimal' )->alias( static fn( $value ) => preg_replace( '/[^0-9.\-]/', '', str_replace( ',', '.', (string) $value ) ) );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
	}

	/**
	 * @param array<string, mixed> $options  the saved instance options.
	 * @param bool                 $declared whether the method declared the feature.
	 * @param mixed                $cost     the cost the carrier's `rate_package()` returns.
	 * @return Woodev_Test_Cost_Limits_Method
	 */
	private function method( array $options, bool $declared = true, $cost = 100 ): Woodev_Test_Cost_Limits_Method {
		$method         = new Woodev_Test_Cost_Limits_Method();
		$method->supports = $declared ? [ Shipping_Method::FEATURE_COST_LIMITS ] : [];
		$method->option_values = $options;
		$method->next_rate = new Shipping_Rate( 'cost-limits', 'cost-limits:1', 'Courier', $cost );

		return $method;
	}

	/**
	 * @param Woodev_Test_Cost_Limits_Method $method the probe.
	 * @return mixed the cost WooCommerce was handed.
	 */
	private function calculated_cost( Woodev_Test_Cost_Limits_Method $method ) {
		$method->calculate_shipping( [ 'contents_cost' => 100 ] );

		$this->assertCount( 1, $method->added_rates );

		return $method->added_rates[0]['cost'];
	}

	// ---- the helpers ------------------------------------------------------------------------------------

	/** @return array<string, array{0: mixed, 1: float|null}> */
	public function stored_limits(): array {
		return [
			'integer string'       => [ '250', 250.0 ],
			'decimal'              => [ '250.5', 250.5 ],
			'comma decimal'        => [ ' 250,5 ', 250.5 ],
			'zero is a limit'      => [ '0', 0.0 ],
			'int'                  => [ 300, 300.0 ],
			'empty string'         => [ '', null ],
			'whitespace'           => [ '  ', null ],
			'never saved'          => [ null, null ],
			'text'                 => [ 'abc', null ],
			'negative'             => [ '-5', null ],
			'bool'                 => [ true, null ],
			'array'                => [ [ '1' ], null ],
			'infinity'             => [ 'INF', null ],
		];
	}

	/**
	 * @dataProvider stored_limits
	 * @param mixed      $raw      stored value.
	 * @param float|null $expected the limit.
	 * @return void
	 */
	public function test_a_stored_limit_is_a_non_negative_number_or_none( $raw, ?float $expected ): void {
		$this->assertSame( $expected, Shipping_Helper::normalize_cost_limit( $raw ) );
	}

	/** @return array<string, array{0: float, 1: float|null, 2: float|null, 3: float}> */
	public function limited_costs(): array {
		return [
			'inside the range'      => [ 400.0, 300.0, 500.0, 400.0 ],
			'below the minimum'     => [ 100.0, 300.0, 500.0, 300.0 ],
			'above the maximum'     => [ 900.0, 300.0, 500.0, 500.0 ],
			'minimum only, raised'  => [ 100.0, 300.0, null, 300.0 ],
			'minimum only, above'   => [ 900.0, 300.0, null, 900.0 ],
			'maximum only, lowered' => [ 900.0, null, 500.0, 500.0 ],
			'maximum only, below'   => [ 100.0, null, 500.0, 100.0 ],
			'no limits'             => [ 123.45, null, null, 123.45 ],
			'equal limits: fixed (low)'  => [ 100.0, 300.0, 300.0, 300.0 ],
			'equal limits: fixed (high)' => [ 900.0, 300.0, 300.0, 300.0 ],
			'contradicting: max wins'    => [ 400.0, 500.0, 300.0, 300.0 ],
			'a free rate stays free'     => [ 0.0, 300.0, 500.0, 0.0 ],
			'a maximum of zero'          => [ 100.0, null, 0.0, 0.0 ],
		];
	}

	/**
	 * @dataProvider limited_costs
	 * @param float      $cost     calculated cost.
	 * @param float|null $min      minimum.
	 * @param float|null $max      maximum.
	 * @param float      $expected result.
	 * @return void
	 */
	public function test_a_cost_is_pulled_into_the_range( float $cost, ?float $min, ?float $max, float $expected ): void {
		$this->assertSame( $expected, Shipping_Helper::limit_cost( $cost, $min, $max ) );
	}

	// ---- the declaration --------------------------------------------------------------------------------

	/** @return void */
	public function test_a_method_that_did_not_declare_the_feature_is_never_limited(): void {
		$method = $this->method( [ 'min_cost' => '300', 'max_cost' => '400' ], false );

		$this->assertFalse( $method->supports_cost_limits() );
		$this->assertNull( $method->get_min_cost() );
		$this->assertNull( $method->get_max_cost() );
		$this->assertSame( 100.0, (float) $this->calculated_cost( $method ), 'saved limits are inert without the declaration' );
	}

	/** @return void */
	public function test_a_declared_method_with_empty_limits_keeps_the_calculated_cost(): void {
		$method = $this->method( [ 'min_cost' => '', 'max_cost' => '' ] );

		$this->assertTrue( $method->supports_cost_limits() );
		$this->assertNull( $method->get_min_cost() );
		$this->assertNull( $method->get_max_cost() );
		$this->assertSame( 100.0, (float) $this->calculated_cost( $method ) );
	}

	// ---- the framework step -----------------------------------------------------------------------------

	/** @return void */
	public function test_the_minimum_raises_the_calculated_rate(): void {
		$this->assertSame( 300.0, (float) $this->calculated_cost( $this->method( [ 'min_cost' => '300' ] ) ) );
	}

	/** @return void */
	public function test_the_maximum_lowers_the_calculated_rate(): void {
		$this->assertSame( 80.0, (float) $this->calculated_cost( $this->method( [ 'max_cost' => '80' ] ) ) );
	}

	/** @return void */
	public function test_equal_limits_fix_the_price(): void {
		$this->assertSame( 250.0, (float) $this->calculated_cost( $this->method( [ 'min_cost' => '250', 'max_cost' => '250' ], true, 40 ) ) );
		$this->assertSame( 250.0, (float) $this->calculated_cost( $this->method( [ 'min_cost' => '250', 'max_cost' => '250' ], true, 900 ) ) );
	}

	/** @return void */
	public function test_a_free_rate_stays_free_whatever_the_minimum(): void {
		$this->assertSame( 0.0, (float) $this->calculated_cost( $this->method( [ 'min_cost' => '300' ], true, 0 ) ) );
	}

	/**
	 * @param int    $parcels how many parcels the packed result has (one 5×5×5 box per unit).
	 * @param string $box_cost fixed cost of one box.
	 * @return \Woodev_Packer_Result
	 */
	private function packed( int $parcels, string $box_cost = '25' ): \Woodev_Packer_Result {
		$box = new \Woodev_Packer_Box_Implementation( 5, 5, 5, 0, 1, 'store', 'Store', [ 'origin' => 'store', 'cost' => $box_cost ] );

		return \Woodev_Packer_Dispatcher::pack( 'boxes', [ new \Woodev_Packer_Input_Item( 5, 5, 5, 1, $parcels, 'line', 5 ) ], [ $box ] );
	}

	/** @return void */
	public function test_a_free_carrier_rate_is_not_raised_to_the_minimum_by_a_box_cost(): void {
		$method           = $this->method( [ 'min_cost' => '300' ], true, 0 );
		$method->supports = [ Shipping_Method::FEATURE_COST_LIMITS, Shipping_Method::FEATURE_BOX_PACKING ];
		$method->packed   = $this->packed( 1 );

		$this->assertSame( 25.0, (float) $this->calculated_cost( $method ), 'free from the carrier + a 25 box: the box is the only charge, not the 300 minimum' );
	}

	/** @return void */
	public function test_a_free_carrier_rate_with_several_boxes_stays_below_the_minimum_too(): void {
		$method           = $this->method( [ 'min_cost' => '300' ], true, 0 );
		$method->supports = [ Shipping_Method::FEATURE_COST_LIMITS, Shipping_Method::FEATURE_BOX_PACKING ];
		$method->packed   = $this->packed( 3 );

		$this->assertSame( 75.0, (float) $this->calculated_cost( $method ) );
	}

	/** @return void */
	public function test_the_maximum_still_bounds_a_free_rate_with_boxes(): void {
		$method           = $this->method( [ 'min_cost' => '300', 'max_cost' => '50' ], true, 0 );
		$method->supports = [ Shipping_Method::FEATURE_COST_LIMITS, Shipping_Method::FEATURE_BOX_PACKING ];
		$method->packed   = $this->packed( 3 );

		$this->assertSame( 50.0, (float) $this->calculated_cost( $method ), 'the ceiling holds for the final price, free or not' );
	}

	/** @return void */
	public function test_a_priced_carrier_rate_with_boxes_is_held_to_the_minimum_as_before(): void {
		$method           = $this->method( [ 'min_cost' => '300' ], true, 100 );
		$method->supports = [ Shipping_Method::FEATURE_COST_LIMITS, Shipping_Method::FEATURE_BOX_PACKING ];
		$method->packed   = $this->packed( 1 );

		$this->assertSame( 300.0, (float) $this->calculated_cost( $method ), '100 + 25 = 125 is below the 300 minimum' );
	}

	/** @return void */
	public function test_a_free_per_item_cost_array_with_boxes_is_not_raised_to_the_minimum(): void {
		$method           = $this->method( [ 'min_cost' => '300' ], true, [ 'a' => 0, 'b' => 0 ] );
		$method->supports = [ Shipping_Method::FEATURE_COST_LIMITS, Shipping_Method::FEATURE_BOX_PACKING ];
		$method->packed   = $this->packed( 1 );

		$cost = $this->calculated_cost( $method );

		$this->assertIsArray( $cost );
		$this->assertEqualsWithDelta( 25.0, array_sum( $cost ), 0.0001 );
	}

	/** @return void */
	public function test_a_rate_with_a_fee_already_in_it_is_limited_after_the_fee(): void {
		$fee_included = Shipping_Helper::apply_fee( 200.0, '150' );

		$this->assertSame( 350.0, $fee_included );
		$this->assertSame( 300.0, (float) $this->calculated_cost( $this->method( [ 'max_cost' => '300' ], true, $fee_included ) ), 'the ceiling holds for the price the customer pays, fee included' );
	}

	/** @return void */
	public function test_a_per_item_cost_array_is_scaled_to_the_limited_total(): void {
		$method = $this->method( [ 'min_cost' => '300' ], true, [ 'a' => 60, 'b' => 40 ] );

		$cost = $this->calculated_cost( $method );

		$this->assertIsArray( $cost );
		$this->assertEqualsWithDelta( 300.0, array_sum( $cost ), 0.0001 );
		$this->assertEqualsWithDelta( 180.0, $cost['a'], 0.0001, 'the mix of the entries, and so the tax on them, is kept' );
		$this->assertEqualsWithDelta( 120.0, $cost['b'], 0.0001 );
	}

	/** @return void */
	public function test_apply_cost_limits_is_idempotent_and_public_for_a_carrier(): void {
		$method = $this->method( [ 'min_cost' => '100', 'max_cost' => '500' ] );

		$this->assertSame( 500.0, $method->apply_cost_limits( 900.0 ) );
		$this->assertSame( 500.0, $method->apply_cost_limits( $method->apply_cost_limits( 900.0 ) ) );
		$this->assertSame( 0.0, $method->apply_cost_limits( 0.0 ) );
	}

	// ---- the admin form ---------------------------------------------------------------------------------

	/** @return void */
	public function test_a_valid_minimum_is_stored_and_an_empty_one_means_no_limit(): void {
		$method = $this->method( [] );

		$this->assertSame( '250', $method->validate_min_cost_field( 'min_cost', '250' ) );
		$this->assertSame( '250.5', $method->validate_min_cost_field( 'min_cost', '250,5' ) );
		$this->assertSame( '', $method->validate_min_cost_field( 'min_cost', '' ) );
		$this->assertSame( '', $method->validate_min_cost_field( 'min_cost', null ) );
	}

	/** @return array<string, array{0: string}> */
	public function refused_values(): array {
		return [
			'text'     => [ 'abc' ],
			'negative' => [ '-10' ],
		];
	}

	/**
	 * @dataProvider refused_values
	 * @param string $value posted value.
	 * @return void
	 */
	public function test_a_non_number_or_a_negative_one_is_refused( string $value ): void {
		$method = $this->method( [] );

		$this->expectException( \Exception::class );
		$method->validate_min_cost_field( 'min_cost', $value );
	}

	/**
	 * @dataProvider refused_values
	 * @param string $value posted value.
	 * @return void
	 */
	public function test_a_bad_maximum_is_refused_too( string $value ): void {
		$method = $this->method( [] );

		$this->expectException( \Exception::class );
		$method->validate_max_cost_field( 'max_cost', $value );
	}

	/**
	 * Mirrors the loop of WooCommerce's `WC_Shipping_Method::process_admin_options()`: every field is run
	 * through its `validate_{key}_field()`, a field that throws keeps the value it had and the error is
	 * collected, the rest are saved one by one without a rollback. Checked against the real WooCommerce
	 * source when this was written (the unit suite has no WooCommerce loaded).
	 *
	 * @param array<string, string> $saved  the stored option values.
	 * @param array<string, string> $posted the posted `min_cost` / `max_cost`.
	 * @return array{0: array<string, string>, 1: string[]} the stored values after the save, the errors.
	 */
	private function save( array $saved, array $posted ): array {
		$method                     = $this->method( $saved );
		$method->post               = [];
		$errors                     = [];
		$stored                     = $saved;

		foreach ( $posted as $key => $value ) {
			$method->post[ 'woocommerce_cost-limits_' . $key ] = $value;
		}

		foreach ( [ 'min_cost', 'max_cost' ] as $key ) {
			try {
				$stored[ $key ]         = $method->{'validate_' . $key . '_field'}( $key, $method->post[ 'woocommerce_cost-limits_' . $key ] ?? null );
				$method->option_values  = $stored;
			} catch ( \Exception $e ) {
				$errors[] = $e->getMessage();
			}
		}

		return [ $stored, $errors ];
	}

	/** @return void */
	public function test_a_contradicting_pair_saves_neither_limit(): void {
		[ $stored, $errors ] = $this->save( [ 'min_cost' => '100', 'max_cost' => '400' ], [ 'min_cost' => '500', 'max_cost' => '300' ] );

		$this->assertSame( '100', $stored['min_cost'], 'the new minimum is not stored' );
		$this->assertSame( '400', $stored['max_cost'], 'the new maximum is not stored' );
		$this->assertCount( 1, $errors, 'one clear error for the pair' );
		$this->assertSame( 'Минимальная стоимость доставки не может быть больше максимальной. Значения не сохранены.', $errors[0] );
	}

	/** @return void */
	public function test_a_contradicting_pair_over_empty_saved_limits_stays_empty(): void {
		[ $stored, $errors ] = $this->save( [], [ 'min_cost' => '500', 'max_cost' => '300' ] );

		$this->assertSame( '', $stored['min_cost'] ?? '' );
		$this->assertSame( '', $stored['max_cost'] ?? '' );
		$this->assertCount( 1, $errors );
	}

	/** @return void */
	public function test_a_valid_pair_is_saved_whole(): void {
		[ $stored, $errors ] = $this->save( [ 'min_cost' => '100', 'max_cost' => '400' ], [ 'min_cost' => '200', 'max_cost' => '250' ] );

		$this->assertSame( [ '200', '250' ], [ $stored['min_cost'], $stored['max_cost'] ] );
		$this->assertSame( [], $errors );
	}

	/** @return void */
	public function test_equal_limits_are_a_valid_pair(): void {
		[ $stored, $errors ] = $this->save( [], [ 'min_cost' => '300', 'max_cost' => '300' ] );

		$this->assertSame( [ '300', '300' ], [ $stored['min_cost'], $stored['max_cost'] ] );
		$this->assertSame( [], $errors );
	}

	/** @return void */
	public function test_a_new_maximum_below_the_saved_minimum_is_refused_when_the_minimum_is_not_posted_as_a_change(): void {
		[ $stored, $errors ] = $this->save( [ 'min_cost' => '300', 'max_cost' => '900' ], [ 'min_cost' => '300', 'max_cost' => '200' ] );

		$this->assertSame( [ '300', '900' ], [ $stored['min_cost'], $stored['max_cost'] ] );
		$this->assertCount( 1, $errors );
	}

	/** @return void */
	public function test_an_invalid_minimum_is_compared_with_the_saved_one_for_the_maximum(): void {
		// the minimum 'abc' is refused (the saved 100 stays); the maximum 50 would contradict that saved 100
		[ $stored, $errors ] = $this->save( [ 'min_cost' => '100', 'max_cost' => '400' ], [ 'min_cost' => 'abc', 'max_cost' => '50' ] );

		$this->assertSame( [ '100', '400' ], [ $stored['min_cost'], $stored['max_cost'] ], 'neither changes' );
		$this->assertCount( 2, $errors, 'the bad minimum and the maximum that contradicts the minimum that stays' );
	}

	/** @return void */
	public function test_an_invalid_maximum_is_compared_with_the_saved_one_for_the_minimum(): void {
		// the maximum 'abc' is refused (the saved 400 stays); the minimum 500 would contradict that saved 400
		[ $stored, $errors ] = $this->save( [ 'min_cost' => '100', 'max_cost' => '400' ], [ 'min_cost' => '500', 'max_cost' => 'abc' ] );

		$this->assertSame( [ '100', '400' ], [ $stored['min_cost'], $stored['max_cost'] ] );
		$this->assertCount( 2, $errors );
	}

	/** @return void */
	public function test_clearing_one_limit_never_contradicts(): void {
		[ $stored, $errors ] = $this->save( [ 'min_cost' => '100', 'max_cost' => '400' ], [ 'min_cost' => '', 'max_cost' => '50' ] );

		$this->assertSame( [ '', '50' ], [ $stored['min_cost'], $stored['max_cost'] ] );
		$this->assertSame( [], $errors );
	}

	/** @return void */
	public function test_a_maximum_below_the_posted_minimum_keeps_the_saved_maximum(): void {
		$method       = $this->method( [ 'max_cost' => '400' ] );
		$method->post = [ 'woocommerce_cost-limits_min_cost' => '500' ];

		$this->assertSame( '400', $method->validate_max_cost_field( 'max_cost', '300' ), 'the minimum validator reports the pair; the maximum is simply not changed' );
	}

	/** @return void */
	public function test_a_maximum_below_a_saved_minimum_is_refused_when_the_posted_minimum_is_invalid(): void {
		$method       = $this->method( [ 'min_cost' => '100' ] );
		$method->post = [ 'woocommerce_cost-limits_min_cost' => 'abc' ];

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Максимальная стоимость доставки не может быть меньше минимальной.' );
		$method->validate_max_cost_field( 'max_cost', '50' );
	}

	/** @return void */
	public function test_a_minimum_above_the_posted_maximum_is_refused(): void {
		$method       = $this->method( [] );
		$method->post = [ 'woocommerce_cost-limits_max_cost' => '300' ];

		$this->expectException( \Exception::class );
		$method->validate_min_cost_field( 'min_cost', '500' );
	}

	/** @return void */
	public function test_a_maximum_equal_to_or_above_the_minimum_is_accepted(): void {
		$method       = $this->method( [] );
		$method->post = [ 'woocommerce_cost-limits_min_cost' => '500' ];

		$this->assertSame( '500', $method->validate_max_cost_field( 'max_cost', '500' ), 'equal limits are the fixed price' );
		$this->assertSame( '800', $method->validate_max_cost_field( 'max_cost', '800' ) );
	}

	/** @return void */
	public function test_a_maximum_alone_or_a_minimum_alone_is_accepted(): void {
		$method = $this->method( [] );

		$this->assertSame( '300', $method->validate_max_cost_field( 'max_cost', '300' ), 'no minimum posted' );
		$this->assertSame( '', $method->validate_max_cost_field( 'max_cost', '' ), 'an empty maximum is no limit, whatever the minimum' );

		$method->post = [ 'woocommerce_cost-limits_min_cost' => '' ];
		$this->assertSame( '300', $method->validate_max_cost_field( 'max_cost', '300' ) );
	}

	/** @return void */
	public function test_the_controls_are_empty_by_default_with_a_placeholder_and_only_for_a_declaring_method(): void {
		$declared = $this->method( [] );
		$declared->init_form_fields();

		foreach ( [ 'min_cost' => 'Минимальная стоимость доставки', 'max_cost' => 'Максимальная стоимость доставки' ] as $key => $title ) {
			$this->assertArrayHasKey( $key, $declared->instance_form_fields );
			$this->assertSame( $title, $declared->instance_form_fields[ $key ]['title'] );
			$this->assertSame( 'price', $declared->instance_form_fields[ $key ]['type'] );
			$this->assertSame( '', $declared->instance_form_fields[ $key ]['default'], 'empty, never «0»' );
			$this->assertSame( 'Не ограничено', $declared->instance_form_fields[ $key ]['placeholder'] );
			$this->assertNotSame( '', $declared->instance_form_fields[ $key ]['desc_tip'] );
		}

		$plain = $this->method( [], false );
		$plain->init_form_fields();

		$this->assertArrayNotHasKey( 'min_cost', $plain->instance_form_fields );
		$this->assertArrayNotHasKey( 'max_cost', $plain->instance_form_fields );
	}
}

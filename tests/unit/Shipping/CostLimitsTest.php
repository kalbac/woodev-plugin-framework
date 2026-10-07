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

	/** @return void */
	public function test_a_maximum_below_the_posted_minimum_is_refused(): void {
		$method       = $this->method( [] );
		$method->post = [ 'woocommerce_cost-limits_min_cost' => '500' ];

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Максимальная стоимость доставки не может быть меньше минимальной.' );
		$method->validate_max_cost_field( 'max_cost', '300' );
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

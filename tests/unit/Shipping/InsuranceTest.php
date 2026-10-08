<?php
/**
 * Insurance mode, declared value, quote/order parity and cache identity (#1157).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Shipping_Helper;
use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Rate_Cache;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/ShippingRateCacheTest.php';

/** Carrier whose insurance default is always, as CDEK will declare. */
class Woodev_Test_Always_Insured_Method extends Woodev_Test_Shipping_Method_For_Rate_Cache {

	/** @return string */
	protected function get_default_insurance_mode(): string {
		return self::INSURANCE_ALWAYS;
	}
}

/** @coversDefaultClass \Woodev\Framework\Shipping\Shipping_Method */
final class InsuranceTest extends TestCase {

	/** @return void */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/** @return array */
	public function modes_and_payments(): array {
		$cases = [];
		foreach ( [ 'none', 'always', 'delivery_payment' ] as $mode ) {
			foreach ( [ 'cod', 'bacs', '' ] as $payment ) {
				$cases[ $mode . '/' . $payment ] = [ $mode, $payment, 'always' === $mode || ( 'delivery_payment' === $mode && 'cod' === $payment ) ];
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider modes_and_payments
	 * @param string $mode Mode.
	 * @param string $payment Chosen gateway.
	 * @param bool $enabled Expected decision.
	 * @return void
	 */
	public function test_three_modes_for_cod_prepaid_and_nothing_chosen( string $mode, string $payment, bool $enabled ): void {
		$method = new Woodev_Test_Shipping_Method_For_Rate_Cache();
		$method->supports[] = Shipping_Method::FEATURE_INSURANCE;
		$method->option_values[ Shipping_Method::OPTION_INSURANCE ] = $mode;

		$expected = [ 'enabled' => $enabled, 'declared_value' => $enabled ? 80.0 : 0.0 ];
		$this->assertSame( $expected, $method->resolve_insurance_for_package( [ 'chosen_payment_method' => $payment, 'contents_cost' => 80 ] ) );
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( $payment );
		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product' )->andReturn( false );
		$item->shouldReceive( 'get_total' )->andReturn( 80 );
		$this->assertSame( $expected, $method->resolve_insurance_for_order( $order, [ $item ] ) );
	}

	/** @return void */
	public function test_only_an_opted_in_form_has_the_three_choices_and_the_carrier_default(): void {
		$method = new Woodev_Test_Always_Insured_Method();
		$method->init_form_fields();
		$this->assertArrayNotHasKey( Shipping_Method::OPTION_INSURANCE, $method->instance_form_fields );
		$this->assertSame( [ 'enabled' => false, 'declared_value' => 0.0 ], $method->resolve_insurance_for_package( [ 'contents_cost' => 100 ] ) );
		$this->assertSame( [ 'enabled' => false, 'declared_value' => 0.0 ], $method->resolve_insurance_for_order( Mockery::mock( 'WC_Order' ) ), 'unsupported methods need no order or session data' );

		$method->add_support( Shipping_Method::FEATURE_INSURANCE );
		$field = $method->instance_form_fields[ Shipping_Method::OPTION_INSURANCE ];
		$this->assertSame( 'select', $field['type'] );
		$this->assertSame( 'Учитывать страховку', $field['title'] );
		$this->assertNotEmpty( $field['desc_tip'] );
		$this->assertSame( [ 'none', 'always', 'delivery_payment' ], array_keys( $field['options'] ) );
		$this->assertSame( 'always', $field['default'] );
		$this->assertTrue( $method->resolve_insurance_for_package( [ 'contents_cost' => 100 ] )['enabled'] );

		$method->option_values[ Shipping_Method::OPTION_INSURANCE ] = 'none';
		$this->assertFalse( $method->resolve_insurance_for_package( [ 'contents_cost' => 100 ] )['enabled'], 'saved choice overrides the carrier default' );
	}

	/** @return void */
	public function test_the_framework_default_is_none_and_bad_saved_values_disable_insurance(): void {
		$method = new Woodev_Test_Shipping_Method_For_Rate_Cache();
		$method->supports[] = Shipping_Method::FEATURE_INSURANCE;
		$method->init_form_fields();
		$this->assertSame( 'none', $method->instance_form_fields[ Shipping_Method::OPTION_INSURANCE ]['default'] );
		$this->assertSame( 'none', $method->get_insurance_mode() );
		$method->option_values[ Shipping_Method::OPTION_INSURANCE ] = [ 'always' ];
		$this->assertSame( 'none', $method->get_insurance_mode() );
	}

	/** @return void */
	public function test_cod_gateways_are_filterable_and_package_payment_wins_over_the_session(): void {
		$method = new Woodev_Test_Shipping_Method_For_Rate_Cache();
		$method->supports[] = Shipping_Method::FEATURE_INSURANCE;
		$method->option_values[ Shipping_Method::OPTION_INSURANCE ] = 'delivery_payment';
		$method->payment = 'cod';
		$this->assertTrue( $method->resolve_insurance_for_package( [] )['enabled'] );
		$this->assertFalse( $method->resolve_insurance_for_package( [ 'chosen_payment_method' => 'bacs' ] )['enabled'] );

		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => 'woodev_shipping_insurance_cod_gateways' === $tag ? [ 'cod', 'terminal_on_receipt' ] : $value );
		$this->assertTrue( $method->resolve_insurance_for_package( [ 'chosen_payment_method' => 'terminal_on_receipt' ] )['enabled'] );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => 'woodev_shipping_insurance_cod_gateways' === $tag ? 'bad return' : $value );
		$this->assertFalse( $method->resolve_insurance_for_package( [] )['enabled'] );
	}

	/** @return void */
	public function test_declared_value_is_discounted_goods_for_this_package_excluding_tax_shipping_and_fees(): void {
		$package = [
			'contents' => [
				[ 'quantity' => 2, 'line_subtotal' => 100, 'line_total' => '80.50', 'line_tax' => 16.1 ],
				[ 'quantity' => 1, 'line_subtotal' => 30, 'line_total' => 20 ],
			],
			'contents_cost' => 999,
			'cart_subtotal' => 999,
			'shipping_total' => 50,
			'fees' => 5,
		];
		$this->assertSame( 100.5, Shipping_Helper::get_package_declared_value( $package ) );
		$this->assertSame( 20.0, Shipping_Helper::get_package_declared_value( [ 'contents' => [ $package['contents'][1] ] ] ) );
		$this->assertSame( 0.0, Shipping_Helper::get_package_declared_value( [ 'contents' => [], 'contents_cost' => 999 ] ) );
		$this->assertSame( 70.0, Shipping_Helper::get_package_declared_value( [ 'contents_cost' => '70' ] ) );
	}

	/** @return void */
	public function test_bad_values_are_zero_and_valid_lines_are_still_counted(): void {
		$this->assertSame( 10.0, Shipping_Helper::get_package_declared_value( [ 'contents' => [ [ 'line_total' => -1 ], [ 'line_total' => INF ], [ 'line_total' => [] ], [], 'bad', [ 'line_total' => 10 ] ] ] ) );
		$this->assertSame( 0.0, Shipping_Helper::get_package_declared_value( [] ) );
	}

	/** @return void */
	public function test_order_creation_matches_the_quote_and_uses_order_payment_without_session_fallback(): void {
		$method = new Woodev_Test_Shipping_Method_For_Rate_Cache();
		$method->supports[] = Shipping_Method::FEATURE_INSURANCE;
		$method->option_values[ Shipping_Method::OPTION_INSURANCE ] = 'delivery_payment';
		$method->payment = 'cod';
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );
		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product' )->andReturn( $product );
		$item->shouldReceive( 'get_total' )->andReturn( '80.50' );
		$virtual = Mockery::mock( 'WC_Order_Item_Product' );
		$virtual_product = Mockery::mock( 'WC_Product' );
		$virtual_product->shouldReceive( 'needs_shipping' )->andReturn( false );
		$virtual->shouldReceive( 'get_product' )->andReturn( $virtual_product );
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( [ $item, $virtual ] );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'cod', '', 'cod' );
		$quote = $method->resolve_insurance_for_package( [ 'chosen_payment_method' => 'cod', 'contents' => [ [ 'line_total' => '80.50' ] ] ] );
		$this->assertSame( $quote, $method->resolve_insurance_for_order( $order ) );
		$this->assertFalse( $method->resolve_insurance_for_order( $order )['enabled'] );
		$this->assertSame( $quote, $method->resolve_insurance_for_order( $order, [ $item ] ) );
	}

	/** @return void */
	public function test_cache_context_distinguishes_insurance_mode_filter_and_discounted_value(): void {
		$method = new Woodev_Test_Shipping_Method_For_Rate_Cache();
		$method->supports[] = Shipping_Method::FEATURE_INSURANCE;
		$method->option_values[ Shipping_Method::OPTION_INSURANCE ] = 'delivery_payment';
		$package = [ 'contents_cost' => 80, 'chosen_payment_method' => 'bacs' ];
		$off = $method->get_rate_cache_context( $package );
		$cache = new Shipping_Rate_Cache();
		$off_key = $cache->build_key( $method, $package );
		$package['chosen_payment_method'] = 'cod';
		$on = $method->get_rate_cache_context( $package );
		$this->assertNotSame( $off, $on );
		$this->assertNotNull( $off_key );
		$this->assertNotSame( $off_key, $cache->build_key( $method, $package ) );
		$this->assertSame( [ 'enabled' => true, 'declared_value' => 80.0 ], $on['insurance'] );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => 'woodev_shipping_insurance_cod_gateways' === $tag ? [] : $value );
		$this->assertNotSame( $on, $method->get_rate_cache_context( $package ), 'a changed COD definition cannot reuse an insured rate' );
		$method->option_values[ Shipping_Method::OPTION_INSURANCE ] = 'always';
		$before_discount = $method->get_rate_cache_context( $package );
		$package['contents'] = [ [ 'line_total' => 70 ] ];
		$this->assertNotSame( $before_discount, $method->get_rate_cache_context( $package ), 'line values key insurance even when aggregate cost stayed the same' );
	}
}

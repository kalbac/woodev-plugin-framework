<?php
/** Framework packaging policy: compatibility, allocation, defaults, charging and cache identity. */
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Packaging;
use Woodev\Framework\Shipping\Settings\Boxes_Settings;
use Woodev\Framework\Shipping\Settings\Packaging_Settings;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;

require_once __DIR__ . '/ShippingMethodBoxPackingTest.php';

class Packaging_Test_Method extends \ShippingMethodBoxPackingTest_Method {
	public Shipping_Plugin $owner;
	public ?Shipping_Rate $quote = null;
	protected function get_plugin(): Shipping_Plugin { return $this->owner; }
	public function get_id(): string { return 'packaging_test'; }
	public function supports_box_packing(): bool { return true; }
	protected function chosen_payment_method(): string { return ''; }
	protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
		$this->received_packed = $packed;
		return $this->quote;
	}
}

final class PackagingTest extends TestCase {
	private array $options = [];
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( $defaults, $args ) );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_get_dimension' )->returnArg( 1 );
		Functions\when( 'wc_get_weight' )->returnArg( 1 );
		Functions\when( 'wc_string_to_bool' )->alias( static fn( $value ) => in_array( $value, [ true, 'yes', '1' ], true ) );
		Shipping_Settings_Tab::reset_for_tests();
	}
	protected function tearDown(): void {
		Shipping_Settings_Tab::reset_for_tests();
		parent::tearDown();
	}
	private function preset( string $mode = 'carrier', string $cost = '' ): array {
		return [ 'id' => 'CARTON_M', 'name' => 'M', 'length' => 10, 'width' => 10, 'height' => 10, 'max_weight' => 1, 'box_weight' => 0, 'cost_mode' => $mode, 'cost' => $cost ];
	}
	private function method( array $presets = [] ): Packaging_Test_Method {
		$settings = new Packaging_Settings( 'carrier', $presets );
		$plugin = Mockery::mock( Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_packaging_settings' )->andReturn( $settings );
		$plugin->shouldReceive( 'get_pickup_handler' )->andReturnNull();
		$method = ( new \ReflectionClass( Packaging_Test_Method::class ) )->newInstanceWithoutConstructor();
		$method->owner = $plugin;
		return $method;
	}
	private function invoke( $method, string $name, ...$args ) {
		$reflection = new \ReflectionMethod( $method, $name );
		if ( PHP_VERSION_ID < 80100 ) { $reflection->setAccessible( true ); }
		return $reflection->invokeArgs( $method, $args );
	}
	private function item( int $quantity = 2, float $length = 5 ): \Woodev_Packer_Input_Item {
		return new \Woodev_Packer_Input_Item( $length, 5, 5, 1, $quantity, 'line', 5 );
	}
	public function test_old_store_rows_remain_enabled_and_free_and_new_rows_roundtrip(): void {
		$old = Boxes_Settings::parse( 'Old; 10; 10; 10; 1; 0.1' )[0];
		$this->assertTrue( $old['enabled'] );
		$this->assertSame( '', $old['cost'] );
		$text = 'Old; 10; 10; 10; 1; 0.1; 2,5%; no';
		$box = Boxes_Settings::parse( $text )[0];
		$this->assertFalse( $box['enabled'] );
		$this->assertSame( '2.5%', $box['cost'] );
		$this->assertSame( $box, Boxes_Settings::parse( Boxes_Settings::sanitize_list( $text ) )[0] );
		$this->assertFalse( Boxes_Settings::is_valid_list( 'Bad; 10; 10; 10; 0; 0; -1; yes' ) );
	}
	public function test_disabled_store_boxes_are_not_used(): void {
		$this->options['woodev_boxes_boxes'] = 'Disabled; 10; 10; 10; 0; 0; 50; no';
		$result = \Woodev_WC_Packer_Dispatcher::pack( 'boxes', [ $this->item() ] );
		$this->assertSame( 2, $result->get_package_count() );
		$this->assertSame( '', $result->get_packages()[0]->get_box_origin() );
	}
	public function test_store_box_wins_even_when_carrier_box_packs_more_and_costs_less(): void {
		$carrier = Packaging::to_boxes( [ array_merge( $this->preset( 'fixed', '1' ), [ 'max_weight' => 10 ] ) + [ 'enabled' => true, 'origin' => 'carrier' ] ] )[0];
		$store = new \Woodev_Packer_Box_Implementation( 5, 5, 5, 0, 1, 'store', 'Store', [ 'origin' => 'store', 'cost' => '100' ] );
		$result = \Woodev_Packer_Dispatcher::pack( 'boxes', [ $this->item() ], [ $carrier, $store ] );
		$this->assertSame( [ 'store', 'store' ], array_map( static fn( $p ) => $p->get_box_origin(), $result->get_packages() ) );
		$this->assertSame( 200.0, Packaging::get_cost( $result, [] ) );
	}
	public function test_leftovers_single_preserves_allocations_and_separately_is_default(): void {
		$items = [ $this->item( 2, 50 ), new \Woodev_Packer_Input_Item( 60, 5, 5, 2, 1, 'other', 6 ) ];
		$single = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [], 'single' );
		$separate = \Woodev_Packer_Dispatcher::pack( 'boxes', $items, [] );
		$this->assertSame( 1, $single->get_package_count() );
		$this->assertSame( 3, $separate->get_package_count() );
		$this->assertSame( [ [ 'key' => 'line', 'product_id' => 5, 'quantity' => 2 ], [ 'key' => 'other', 'product_id' => 6, 'quantity' => 1 ] ], $single->get_packages()[0]->get_items() );
		$this->assertSame( 4.0, $single->get_total_weight() );
	}
	public function test_fixed_and_percent_cost_are_per_package_using_allocated_contents(): void {
		$box = new \Woodev_Packer_Box_Implementation( 5, 5, 5, 0, 1, 'store', 'Store', [ 'origin' => 'store', 'cost' => '10%' ] );
		$result = \Woodev_Packer_Dispatcher::pack( 'boxes', [ $this->item( 3 ) ], [ $box ] );
		$this->assertSame( 3, $result->get_package_count() );
		$this->assertSame( 9.0, Packaging::get_cost( $result, [ 'line' => [ 'quantity' => 3, 'line_total' => 90 ] ] ) );
		$fixed = Packaging::to_boxes( [ $this->preset( 'fixed', '7' ) + [ 'enabled' => true, 'origin' => 'carrier' ] ] );
		$this->assertSame( 21.0, Packaging::get_cost( \Woodev_Packer_Dispatcher::pack( 'boxes', [ $this->item( 3 ) ], $fixed ), [] ) );
	}
	public function test_carrier_priced_boxes_are_counted_but_never_charged_twice(): void {
		$this->options['woodev_carrier_packaging_box_CARTON_M_enabled'] = 'yes';
		$settings = new Packaging_Settings( 'carrier', [ $this->preset() ] );
		$packed = \Woodev_Packer_Dispatcher::pack( 'boxes', [ $this->item() ], Packaging::to_boxes( $settings->get_boxes() ) );
		$this->assertSame( [ [ 'id' => 'CARTON_M', 'count' => 2 ] ], Packaging::get_carrier_boxes( $packed ) );
		$this->assertSame( 0.0, Packaging::get_cost( $packed, [ 'line' => [ 'quantity' => 2, 'line_total' => 100 ] ] ) );
		$settings->get_setting( 'box_CARTON_M_charge' )->set_value( false );
		$packed = \Woodev_Packer_Dispatcher::pack( 'boxes', [ $this->item() ], Packaging::to_boxes( $settings->get_boxes() ) );
		$this->assertSame( [], Packaging::get_carrier_boxes( $packed ) );
	}
	public function test_carrier_defaults_and_instance_overrides_and_legacy_virtual_work(): void {
		$this->options['woodev_carrier_packaging_packing_algorithm'] = 'boxes';
		$this->options['woodev_carrier_packaging_unpacked_algorithm'] = 'single';
		$method = $this->method();
		$this->assertSame( 'boxes', $this->invoke( $method, 'get_packing_algorithm' ) );
		$this->assertSame( 'single', $this->invoke( $method, 'get_unpacked_algorithm' ) );
		$method->stored_options = [ 'packing_algorithm' => 'virtual', 'unpacked_algorithm' => 'separately' ];
		$this->assertSame( 'virtual', $this->invoke( $method, 'get_packing_algorithm' ) );
		$this->assertSame( 'separately', $this->invoke( $method, 'get_unpacked_algorithm' ) );
	}
	public function test_integration_default_is_read_until_new_default_is_saved(): void {
		$this->options['woocommerce_carrier_settings'] = [ 'packing_algorithm' => 'single' ];
		$this->assertSame( 'single', ( new Packaging_Settings( 'carrier', [] ) )->get_default_algorithm( 'packing_algorithm' ) );
		$this->options['woodev_carrier_packaging_packing_algorithm'] = 'boxes';
		$this->assertSame( 'boxes', ( new Packaging_Settings( 'carrier', [] ) )->get_default_algorithm( 'packing_algorithm' ) );
	}
	public function test_cache_identity_includes_toggles_costs_and_leftovers(): void {
		$this->options['woodev_carrier_packaging_packing_algorithm'] = 'boxes';
		$method = $this->method( [ $this->preset( 'merchant' ) ] );
		$before = $method->get_rate_cache_context( [] );
		$settings = $method->owner->get_packaging_settings();
		$settings->get_setting( 'box_CARTON_M_enabled' )->set_value( true );
		$settings->get_setting( 'box_CARTON_M_cost' )->set_value( '5%' );
		$method->stored_options['unpacked_algorithm'] = 'single';
		$after = $method->get_rate_cache_context( [] );
		$this->assertNotSame( $before, $after );
		$this->assertSame( 'single', $after['packing']['leftovers'] );
		$this->assertSame( '5%', $after['packing']['carrier_boxes'][0]['cost'] );
	}
	public function test_preset_controls_match_modes_and_leftovers_have_show_if(): void {
		$settings = new Packaging_Settings( 'carrier', [ $this->preset( 'fixed', '7' ), array_merge( $this->preset( 'merchant' ), [ 'id' => 'SECOND' ] ) ] );
		$this->assertTrue( $settings->get_setting( 'box_CARTON_M_cost' )->get_control()->is_disabled() );
		$this->assertFalse( $settings->get_setting( 'box_SECOND_cost' )->get_control()->is_disabled() );
		$this->assertSame( [ 'setting' => 'packing_algorithm', 'value' => 'boxes' ], $settings->get_setting( 'unpacked_algorithm' )->get_show_if_conditions() );
	}
	public function test_rate_template_adds_the_store_box_cost_once(): void {
		$this->options['woodev_boxes_boxes'] = 'Small; 5; 5; 5; 1; 0; 3; yes';
		$method = $this->method();
		$method->stored_options = [ 'packing_algorithm' => 'boxes' ];
		$method->quote = new Shipping_Rate( 'test', 'carrier', 'Delivery', 10 );
		$product = Mockery::mock( '\WC_Product' );
		$product->shouldReceive( 'is_virtual' )->andReturnFalse();
		foreach ( [ 'length', 'width', 'height' ] as $dimension ) { $product->shouldReceive( 'get_' . $dimension )->andReturn( 5 ); }
		$product->shouldReceive( 'get_weight' )->andReturn( 1 );
		$rate = $this->invoke( $method, 'calculate_rate', [ 'contents' => [ 'line' => [ 'data' => $product, 'quantity' => 2 ] ] ] );
		$this->assertSame( 16.0, $rate->get_cost() );
		$this->assertSame( 10, $method->quote->get_cost() );
	}
	public function test_fixed_declaration_wins_over_a_written_option_in_ui_and_rating(): void {
		$this->options['woodev_carrier_packaging_box_CARTON_M_cost'] = '999';
		$settings = new Packaging_Settings( 'carrier', [ $this->preset( 'fixed', '7' ) ] );
		$this->assertSame( '7', $settings->get_value( 'box_CARTON_M_cost' ) );
		$this->assertSame( '7', $settings->get_boxes()[0]['cost'] );
	}
	public function test_cache_changes_when_values_shift_between_lines_even_with_same_total(): void {
		$method = $this->method();
		$method->stored_options['packing_algorithm'] = 'boxes';
		$first = [ 'contents_cost' => 100, 'contents' => [ 'a' => [ 'quantity' => 1, 'line_total' => 10 ], 'b' => [ 'quantity' => 1, 'line_total' => 90 ] ] ];
		$second = $first;
		$second['contents']['a']['line_total'] = 90;
		$second['contents']['b']['line_total'] = 10;
		$this->assertNotSame( $method->get_rate_cache_context( $first ), $method->get_rate_cache_context( $second ) );
	}
	public function test_disabled_presets_stay_out_of_packer_and_carrier_request(): void {
		$settings = new Packaging_Settings( 'carrier', [ $this->preset() ] );
		$packed = \Woodev_Packer_Dispatcher::pack( 'boxes', [ $this->item() ], Packaging::to_boxes( $settings->get_boxes() ) );
		$this->assertSame( [], Packaging::get_carrier_boxes( $packed ) );
		$this->assertSame( '', $packed->get_packages()[0]->get_box_id() );
	}
	public function test_carrier_rate_path_gets_counted_presets_and_keeps_quoted_cost(): void {
		$this->options['woodev_carrier_packaging_box_CARTON_M_enabled'] = 'yes';
		$method = $this->method( [ $this->preset() ] );
		$method->stored_options['packing_algorithm'] = 'boxes';
		$method->quote = new Shipping_Rate( 'test', 'carrier', 'Delivery', 100 );
		$product = Mockery::mock( '\WC_Product' );
		$product->shouldReceive( 'is_virtual' )->andReturnFalse();
		foreach ( [ 'length', 'width', 'height' ] as $dimension ) { $product->shouldReceive( 'get_' . $dimension )->andReturn( 5 ); }
		$product->shouldReceive( 'get_weight' )->andReturn( 1 );
		$rate = $this->invoke( $method, 'calculate_rate', [ 'contents' => [ 'line' => [ 'data' => $product, 'quantity' => 2 ] ] ] );
		$this->assertSame( 100, $rate->get_cost() );
		$this->assertSame( [ [ 'id' => 'CARTON_M', 'count' => 2 ] ], Packaging::get_carrier_boxes( $method->received_packed ) );
	}

	public function test_new_defaults_offer_three_choices_but_saved_virtual_remains_visible(): void {
		$this->assertSame( [ 'separately', 'single', 'boxes' ], array_keys( ( new Packaging_Settings( 'carrier', [] ) )->get_setting( 'packing_algorithm' )->get_options() ) );
		$this->options['woocommerce_carrier_settings'] = [ 'packing_algorithm' => 'virtual' ];
		$settings = new Packaging_Settings( 'carrier', [] );
		$this->assertSame( 'virtual', $settings->get_default_algorithm( 'packing_algorithm' ) );
		$this->assertArrayHasKey( 'virtual', $settings->get_setting( 'packing_algorithm' )->get_options() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_zone_control_retains_stored_virtual_and_resolves_default_for_leftovers(): void {
		$method = $this->method();
		$method->stored_options['packing_algorithm'] = 'virtual';
		$field = json_decode( $method->generate_select_html( 'packing_algorithm', [ 'options' => [ 'default' => 'Default' ] + Packaging_Settings::packing_options() ] ), true );
		$this->assertArrayHasKey( 'virtual', $field['options'] );
		$this->assertArrayHasKey( 'boxes', $field['options'] );
		$leftovers = json_decode( $method->generate_select_html( 'unpacked_algorithm', [] ), true );
		$this->assertSame( 'separately', $leftovers['custom_attributes']['data-woodev-packing-default'] );
	}

}

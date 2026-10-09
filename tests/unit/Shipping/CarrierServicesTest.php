<?php
/**
 * Additional carrier services: the declaration seam, the instance option, the quote/order resolver and the
 * rate-cache identity (#1145).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Carrier_Service;
use Woodev\Framework\Shipping\Packaging;
use Woodev\Framework\Shipping\Settings\Packaging_Settings;
use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Framework\Shipping\Shipping_Rate_Cache;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/ShippingRateCacheTest.php';

/** A carrier declaring two services: insurance (a value, from the goods) and an SMS notice (no value). */
class Woodev_Test_Services_Method extends Woodev_Test_Shipping_Method_For_Rate_Cache {

	/** @return array */
	protected function declare_services(): array {
		return [
			new Carrier_Service( 'INSURANCE', 'Страхование', 'sum' ),
			new Carrier_Service( 'SMS_NOTICE', 'SMS-уведомление' ),
		];
	}
}

/** The two-service carrier, with the quote step opened and the rates it adds recorded. */
class Woodev_Test_Quoting_Services_Method extends Woodev_Test_Services_Method {

	/** @var array<int, array<string,mixed>> the arguments of every rate handed to WooCommerce. */
	public array $rates_added = [];

	/**
	 * @param array $args rate args.
	 * @return void
	 */
	public function add_rate( $args = [] ) {
		$this->rates_added[] = $args;
	}

	/**
	 * @param array $package package.
	 * @return Shipping_Rate|null
	 */
	public function quote( array $package ): ?Shipping_Rate {
		return $this->calculate_rate( $package );
	}
}

/**
 * A carrier whose services compute their own value — and one it adds by itself, the way CDEK's boxes travel as
 * `CARTON_BOX_*` services whose value is the number of boxes of that kind.
 */
class Woodev_Test_Custom_Services_Method extends Woodev_Test_Shipping_Method_For_Rate_Cache {

	/** @var int|null what the «try on» service computes; null = nothing to give. */
	public $try_on = 2;

	/** @return array */
	protected function declare_services(): array {
		return [
			[ 'code' => 'TRY_ON', 'name' => 'Примерка', 'parameter' => 'minutes', 'parameter_source' => 'custom' ],
			new Carrier_Service( 'CARTON_BOX_CARTON_M', 'Коробка M', 'count', Carrier_Service::SOURCE_CUSTOM, '', false ),
		];
	}

	/**
	 * @param Carrier_Service $service service.
	 * @param array           $subject subject.
	 * @return int|float|string|null
	 */
	protected function resolve_service_parameter( Carrier_Service $service, array $subject ) {
		if ( 'TRY_ON' === $service->get_code() ) {
			return $this->try_on;
		}

		foreach ( Packaging::get_carrier_boxes( $subject['packed'] ) as $box ) {
			if ( 'CARTON_BOX_' . $box['id'] === $service->get_code() ) {
				return $box['count'];
			}
		}

		return null;
	}
}

/** @coversDefaultClass \Woodev\Framework\Shipping\Shipping_Method */
final class CarrierServicesTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = [];

	/** @return void */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_get_dimension' )->returnArg( 1 );
		Functions\when( 'wc_get_weight' )->returnArg( 1 );
		Functions\when( 'wc_string_to_bool' )->alias( static fn( $value ) => in_array( $value, [ true, 'yes', '1' ], true ) );
		Functions\when( 'is_admin' )->justReturn( false );
	}

	/** @return array<string,mixed> a package with two priced lines. */
	private function package(): array {
		return [ 'contents' => [ [ 'line_total' => '80.50' ], [ 'line_total' => 20 ] ] ];
	}

	/**
	 * @param string $id    product line id.
	 * @param mixed  $total line total.
	 * @return \Mockery\MockInterface
	 */
	private function line( string $id, $total ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'needs_shipping' )->andReturn( true );
		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product' )->andReturn( $product );
		$item->shouldReceive( 'get_total' )->andReturn( $total );
		return $item;
	}

	// ---- Carrier_Service ---------------------------------------------------------------------------------

	/** @return void */
	public function test_a_service_keeps_what_the_carrier_declared_and_trims_it(): void {
		$service = new Carrier_Service( ' INSURANCE ', ' Страхование ', ' sum ', Carrier_Service::SOURCE_DECLARED_VALUE, ' Покрывает стоимость товаров ' );

		$this->assertSame( 'INSURANCE', $service->get_code() );
		$this->assertSame( 'Страхование', $service->get_name() );
		$this->assertSame( 'sum', $service->get_parameter() );
		$this->assertTrue( $service->has_parameter() );
		$this->assertSame( 'declared_value', $service->get_parameter_source() );
		$this->assertSame( 'Покрывает стоимость товаров', $service->get_description() );
		$this->assertTrue( $service->is_selectable(), 'a service is offered to the merchant unless the carrier says otherwise' );

		$bare = new Carrier_Service( 'SMS_NOTICE' );
		$this->assertSame( 'SMS_NOTICE', $bare->get_name(), 'no name: the code stands in' );
		$this->assertNull( $bare->get_parameter() );
		$this->assertFalse( $bare->has_parameter() );
		$this->assertNull( ( new Carrier_Service( 'X', '', '   ' ) )->get_parameter(), 'a blank parameter name is no parameter' );
	}

	/** @return void */
	public function test_an_empty_code_or_an_unknown_source_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Carrier_Service( '   ' );
	}

	/** @return void */
	public function test_an_unknown_parameter_source_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Carrier_Service( 'X', 'X', 'p', 'guess' );
	}

	/** @return void */
	public function test_a_declaration_array_builds_a_service_and_a_bad_one_gives_null(): void {
		$service = Carrier_Service::from_array( [ 'code' => 'CARTON_BOX_1', 'name' => 'Коробка', 'parameter' => 'count', 'parameter_source' => 'custom', 'selectable' => false ] );
		$this->assertInstanceOf( Carrier_Service::class, $service );
		$this->assertFalse( $service->is_selectable() );
		$this->assertSame( 'custom', $service->get_parameter_source() );

		$this->assertNull( Carrier_Service::from_array( [] ) );
		$this->assertNull( Carrier_Service::from_array( [ 'code' => 12 ] ) );
		$this->assertNull( Carrier_Service::from_array( [ 'code' => 'X', 'parameter' => 'p', 'parameter_source' => 'guess' ] ) );
	}

	/** @return void */
	public function test_the_list_is_keyed_by_code_and_drops_what_is_unusable(): void {
		$first = new Carrier_Service( 'A', 'First' );
		$list  = Carrier_Service::normalize_list( [ $first, new Carrier_Service( 'A', 'Again' ), 'text', [ 'name' => 'no code' ], [ 'code' => 'B' ], null, new Carrier_Service( '5' ) ] );

		$this->assertSame( [ 'A', 'B', '5' ], array_map( 'strval', array_keys( $list ) ) );
		$this->assertSame( $first, $list['A'], 'the first entry of a code wins' );
		$this->assertSame( [], Carrier_Service::normalize_list( 'not a list' ) );
	}

	// ---- the instance option ----------------------------------------------------------------------------

	/** @return void */
	public function test_a_carrier_that_declared_nothing_has_no_control_no_services_and_its_old_cache_identity(): void {
		$method = new Woodev_Test_Shipping_Method_For_Rate_Cache();
		$method->init_form_fields();

		$this->assertFalse( $method->supports_services() );
		$this->assertArrayNotHasKey( Shipping_Method::OPTION_SERVICES, $method->instance_form_fields );
		$this->assertSame( [], $method->resolve_services_for_package( $this->package() ) );
		$this->assertSame( [], $method->resolve_services_for_order( Mockery::mock( 'WC_Order' ) ), 'nothing declared needs no order data' );
		$this->assertArrayNotHasKey( 'services', $method->get_rate_cache_context( $this->package() ) );
	}

	/** @return void */
	public function test_the_control_lists_the_declared_services_by_name(): void {
		$method = new Woodev_Test_Services_Method();
		$method->init_form_fields();

		$field = $method->instance_form_fields[ Shipping_Method::OPTION_SERVICES ];

		$this->assertSame( 'services', Shipping_Method::OPTION_SERVICES, 'the v1 CDEK key, so a migration carries the value 1:1' );
		$this->assertSame( 'multiselect', $field['type'] );
		$this->assertSame( 'Дополнительные услуги', $field['title'] );
		$this->assertSame( [], $field['default'] );
		$this->assertSame( [ 'INSURANCE' => 'Страхование', 'SMS_NOTICE' => 'SMS-уведомление' ], $field['options'] );
		$this->assertNotEmpty( $field['desc_tip'] );
	}

	/** @return void */
	public function test_an_automatic_service_is_not_offered_and_alone_it_renders_no_control(): void {
		$method = new Woodev_Test_Custom_Services_Method();
		$method->init_form_fields();

		$this->assertSame( [ 'TRY_ON' => 'Примерка' ], $method->instance_form_fields[ Shipping_Method::OPTION_SERVICES ]['options'] );

		$only_automatic = new class() extends Woodev_Test_Shipping_Method_For_Rate_Cache {
			/** @return array */
			protected function declare_services(): array {
				return [ new Carrier_Service( 'CARTON_BOX_1', 'Коробка', 'count', Carrier_Service::SOURCE_CUSTOM, '', false ) ];
			}
		};
		$only_automatic->init_form_fields();

		$this->assertTrue( $only_automatic->supports_services() );
		$this->assertArrayNotHasKey( Shipping_Method::OPTION_SERVICES, $only_automatic->instance_form_fields, 'nothing for the merchant to pick' );
	}

	/** @return void */
	public function test_the_stored_shape_is_a_plain_list_of_codes_and_survives_a_v1_value(): void {
		$method = new Woodev_Test_Services_Method();

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE', 'INSURANCE' ];
		$this->assertSame( [ 'INSURANCE', 'SMS_NOTICE' ], $method->get_selected_service_codes(), 'the carrier\'s declaration order, whatever order was saved' );

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE', 'INSURANCE', 'GONE', '', 7, [ 'INSURANCE' ] ];
		$this->assertSame( [ 'INSURANCE' ], $method->get_selected_service_codes(), 'a duplicate, a withdrawn code and junk are dropped' );

		foreach ( [ '', 'INSURANCE', 5, null, false ] as $junk ) {
			$method->option_values[ Shipping_Method::OPTION_SERVICES ] = $junk;
			$this->assertSame( [], $method->get_selected_service_codes() );
		}

		$method->option_values = [];
		$this->assertSame( [], $method->get_selected_service_codes(), 'nothing saved yet: nothing chosen' );
	}

	// ---- the rate request --------------------------------------------------------------------------------

	/** @return void */
	public function test_the_quote_carries_the_chosen_services_and_the_goods_value_as_the_parameter(): void {
		$method = new Woodev_Test_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE', 'SMS_NOTICE' ];

		$this->assertSame(
			[
				[ 'code' => 'INSURANCE', 'name' => 'Страхование', 'parameter' => 100.5 ],
				[ 'code' => 'SMS_NOTICE', 'name' => 'SMS-уведомление', 'parameter' => null ],
			],
			$method->resolve_services_for_package( $this->package() )
		);

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];
		$this->assertSame( [ [ 'code' => 'SMS_NOTICE', 'name' => 'SMS-уведомление', 'parameter' => null ] ], $method->resolve_services_for_package( $this->package() ) );

		$method->option_values = [];
		$this->assertSame( [], $method->resolve_services_for_package( $this->package() ), 'declared is not chosen' );
	}

	/** @return void */
	public function test_a_service_that_needs_a_value_is_never_sent_without_one(): void {
		$method = new Woodev_Test_Custom_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'TRY_ON' ];

		$this->assertSame( [ [ 'code' => 'TRY_ON', 'name' => 'Примерка', 'parameter' => 2 ] ], $method->resolve_services_for_package( [] ) );

		foreach ( [ null, [ 1 ], NAN, INF, '', '  ' ] as $nothing ) {
			$method->try_on = $nothing;
			$this->assertSame( [], $method->resolve_services_for_package( [] ), 'no usable value: the service is left out' );
		}

		$method->try_on = ' 15 ';
		$this->assertSame( '15', $method->resolve_services_for_package( [] )[0]['parameter'] );
	}

	/** @return void */
	public function test_carton_boxes_travel_as_automatic_services_computed_from_the_packed_parcels(): void {
		$this->options['woodev_carrier_packaging_box_CARTON_M_enabled'] = 'yes';
		$settings = new Packaging_Settings( 'carrier', [ [ 'id' => 'CARTON_M', 'name' => 'M', 'length' => 10, 'width' => 10, 'height' => 10, 'max_weight' => 1, 'box_weight' => 0, 'cost_mode' => 'carrier', 'cost' => '' ] ] );
		$packed   = \Woodev_Packer_Dispatcher::pack( 'boxes', [ new \Woodev_Packer_Input_Item( 5, 5, 5, 1, 2, 'line', 5 ) ], Packaging::to_boxes( $settings->get_boxes() ) );

		$method = new Woodev_Test_Custom_Services_Method();

		$this->assertSame( [], $method->resolve_services_for_package( [], null ), 'nothing packed: the box service does not apply, and nothing is chosen' );
		$this->assertSame(
			[ [ 'code' => 'CARTON_BOX_CARTON_M', 'name' => 'Коробка M', 'parameter' => 2 ] ],
			$method->resolve_services_for_package( [], $packed ),
			'added with no tick: the merchant never chose it'
		);

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'TRY_ON', 'CARTON_BOX_CARTON_M' ];
		$this->assertSame( [ 'TRY_ON', 'CARTON_BOX_CARTON_M' ], array_column( $method->resolve_services_for_package( [], $packed ), 'code' ), 'a code of an automatic service in the saved list does not select it twice' );
		$this->assertSame( [ 'TRY_ON' ], $method->get_selected_service_codes() );
	}

	// ---- the export ---------------------------------------------------------------------------------------

	/** @return void */
	public function test_the_export_asks_the_same_rule_as_the_quote(): void {
		$method = new Woodev_Test_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE', 'SMS_NOTICE' ];

		$kept    = $this->line( 'a', '80.50' );
		$other   = $this->line( 'b', 20 );
		$virtual = Mockery::mock( 'WC_Order_Item_Product' );
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'needs_shipping' )->andReturn( false );
		$virtual->shouldReceive( 'get_product' )->andReturn( $product );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( [ $kept, $other, $virtual ] );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( [] );

		$quote = $method->resolve_services_for_package( $this->package() );

		$this->assertSame( $quote, $method->resolve_services_for_order( $order ), 'what was quoted is what the export asks for' );
		$this->assertSame( 80.5, $method->resolve_services_for_order( $order, [ $kept ] )[0]['parameter'], 'a split order values only its own lines' );
	}

	/** @return void */
	public function test_the_export_passes_the_order_and_the_lines_to_a_custom_value(): void {
		$method = new class() extends Woodev_Test_Services_Method {
			/** @var array */
			public array $subjects = [];

			/**
			 * @param Carrier_Service $service service.
			 * @param array           $subject subject.
			 * @return int|float|string|null
			 */
			protected function resolve_service_parameter( Carrier_Service $service, array $subject ) {
				$this->subjects[] = $subject;
				return parent::resolve_service_parameter( $service, $subject );
			}
		};
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE' ];

		$line  = $this->line( 'a', 40 );
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_items' )->andReturn( [ $line ] );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( [] );

		$method->resolve_services_for_order( $order, [ $line ] );
		$method->resolve_services_for_package( $this->package() );

		$this->assertSame( 'order', $method->subjects[0]['context'] );
		$this->assertSame( $order, $method->subjects[0]['order'] );
		$this->assertSame( [ $line ], $method->subjects[0]['items'] );
		$this->assertSame( 40.0, $method->subjects[0]['declared_value'] );
		$this->assertSame( 'package', $method->subjects[1]['context'] );
		$this->assertNull( $method->subjects[1]['order'] );
	}

	// ---- the filter ---------------------------------------------------------------------------------------

	/** @return void */
	public function test_a_plugin_can_adjust_the_resolved_list_and_a_bad_return_is_ignored(): void {
		$method = new Woodev_Test_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return 'woodev_shipping_resolved_services' === $tag
					? array_merge( $value, [ [ 'code' => ' EXTRA ', 'parameter' => 3 ], [ 'name' => 'no code' ], 'junk', [ 'code' => 'BAD', 'name' => 'Bad', 'parameter' => [ 1 ] ] ] )
					: $value;
			}
		);
		$this->assertSame(
			[
				[ 'code' => 'SMS_NOTICE', 'name' => 'SMS-уведомление', 'parameter' => null ],
				[ 'code' => 'EXTRA', 'name' => 'EXTRA', 'parameter' => 3 ],
				[ 'code' => 'BAD', 'name' => 'Bad', 'parameter' => null ],
			],
			$method->resolve_services_for_package( $this->package() )
		);

		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => 'woodev_shipping_resolved_services' === $tag ? 'bad return' : $value );
		$this->assertSame( [ [ 'code' => 'SMS_NOTICE', 'name' => 'SMS-уведомление', 'parameter' => null ] ], $method->resolve_services_for_package( $this->package() ) );
	}

	// ---- the rate cache -----------------------------------------------------------------------------------

	/** @return void */
	public function test_the_cache_key_follows_the_chosen_services_and_their_values(): void {
		$method = new Woodev_Test_Services_Method();
		$cache  = new Shipping_Rate_Cache();
		$pack   = $this->package();

		$none = $cache->build_key( $method, $pack );
		$this->assertNotNull( $none );

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];
		$sms = $cache->build_key( $method, $pack );
		$this->assertNotSame( $none, $sms, 'a chosen service is a different quote' );

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE' ];
		$insured = $cache->build_key( $method, $pack );
		$this->assertNotSame( $sms, $insured );
		$this->assertSame( [ [ 'code' => 'INSURANCE', 'name' => 'Страхование', 'parameter' => 100.5 ] ], $method->get_rate_cache_context( $pack )['services'] );

		// the instance settings carry only the CODES: a changed goods value must key the quote on its own
		$pack['contents'][1]['line_total'] = 50;
		$this->assertNotSame( $insured, $cache->build_key( $method, $pack ), 'the value the carrier is asked to cover is part of the identity' );

		$method->option_values = [];
		$pack['contents'][1]['line_total'] = 20;
		$this->assertSame( $none, $cache->build_key( $method, $pack ), 'nothing chosen again: the first quote is served' );
	}

	// ---- the quote snapshot (the shipping line records what was quoted) ----------------------------------

	/**
	 * @param Woodev_Test_Quoting_Services_Method $method the carrier.
	 * @param array|null                          $package the package, or null for the default one.
	 * @return mixed the snapshot the quote carries as rate meta.
	 */
	private function quoted_snapshot( Woodev_Test_Quoting_Services_Method $method, ?array $package = null ) {
		$method->next_rate = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 350 );

		return $method->quote( $package ?? $this->package() )->get_meta_data()[ Shipping_Method::META_QUOTED_SERVICES ] ?? null;
	}

	/**
	 * An order whose shipping line holds `$snapshot` (null = no such meta) and two product lines.
	 *
	 * @param mixed $snapshot the shipping line meta value.
	 * @return array{0: \Mockery\MockInterface, 1: \Mockery\MockInterface} the order and its first product line.
	 */
	private function order_with_snapshot( $snapshot ): array {
		$kept  = $this->line( 'a', '80.50' );
		$other = $this->line( 'b', 20 );

		$shipping = Mockery::mock( 'WC_Order_Item_Shipping' );
		$shipping->shouldReceive( 'get_method_id' )->andReturn( 'rate-cache-method' );
		$shipping->shouldReceive( 'get_meta' )->with( Shipping_Method::META_QUOTED_SERVICES, true )->andReturn( null === $snapshot ? '' : $snapshot );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( [ $kept, $other ] );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( [ $shipping ] );

		return [ $order, $kept ];
	}

	/** @return void */
	public function test_the_quote_carries_its_services_as_rate_meta(): void {
		$method = new Woodev_Test_Quoting_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE' ];

		$snapshot = $this->quoted_snapshot( $method );

		$this->assertIsString( $snapshot, 'a scalar, so it survives the admin wizard\'s meta round trip' );
		$this->assertSame(
			[ 'version' => 1, 'services' => [ [ 'code' => 'INSURANCE', 'name' => 'Страхование', 'parameter' => 100.5 ] ] ],
			json_decode( $snapshot, true )
		);
		$this->assertSame( '_woodev_quoted_services', Shipping_Method::META_QUOTED_SERVICES );
	}

	/** @return void */
	public function test_a_quote_of_nothing_is_recorded_as_an_empty_list(): void {
		$method = new Woodev_Test_Quoting_Services_Method();

		$this->assertSame( [ 'version' => 1, 'services' => [] ], json_decode( (string) $this->quoted_snapshot( $method ), true ) );
	}

	/** @return void */
	public function test_a_method_that_declared_no_service_writes_no_snapshot(): void {
		$method = new class() extends Woodev_Test_Quoting_Services_Method {
			/** @return array */
			protected function declare_services(): array {
				return [];
			}
		};

		$this->assertNull( $this->quoted_snapshot( $method ) );
	}

	/** @return void */
	public function test_the_snapshot_reaches_the_rate_added_at_checkout_and_survives_the_rate_cache(): void {
		$store = [];
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		$method = new Woodev_Test_Quoting_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE' ];
		$method->next_rate = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 350 );

		$method->calculate_shipping( $this->package() );
		$method->calculate_shipping( $this->package() );

		$this->assertSame( 1, $method->carrier_calls, 'the second quote is the cached one' );
		$this->assertCount( 2, $method->rates_added );

		foreach ( $method->rates_added as $args ) {
			$this->assertSame( 'INSURANCE', json_decode( $args['meta_data'][ Shipping_Method::META_QUOTED_SERVICES ], true )['services'][0]['code'] );
		}
	}

	/** @return void */
	public function test_an_unchanged_order_exports_the_quoted_services_not_the_current_settings(): void {
		$method = new Woodev_Test_Quoting_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE' ];

		[ $order ] = $this->order_with_snapshot( $this->quoted_snapshot( $method ) );

		// the merchant edits the zone method after the order was placed
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];

		$this->assertSame(
			[ [ 'code' => 'INSURANCE', 'name' => 'Страхование', 'parameter' => 100.5 ] ],
			$method->resolve_services_for_order( $order ),
			'what was quoted is what gets billed'
		);

		// ...and dropping the option altogether, or adding one, changes nothing either
		$method->option_values = [];
		$this->assertSame( [ 'INSURANCE' ], array_column( $method->resolve_services_for_order( $order ), 'code' ) );
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE', 'SMS_NOTICE' ];
		$this->assertSame( [ 'INSURANCE' ], array_column( $method->resolve_services_for_order( $order ), 'code' ) );
	}

	/** @return void */
	public function test_an_order_quoted_without_services_does_not_gain_one_later(): void {
		$method = new Woodev_Test_Quoting_Services_Method();
		[ $order ] = $this->order_with_snapshot( $this->quoted_snapshot( $method ) );

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];

		$this->assertSame( [], $method->resolve_services_for_order( $order ), 'an empty snapshot is an answer, not a missing one' );
	}

	/** @return void */
	public function test_an_order_without_a_snapshot_is_resolved_from_the_settings_as_before(): void {
		$method = new Woodev_Test_Quoting_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];

		foreach ( [ null, 'not json', '{"version":2,"services":[]}', '{"version":1}', '[]' ] as $stored ) {
			[ $order ] = $this->order_with_snapshot( $stored );
			$this->assertSame( [ 'SMS_NOTICE' ], array_column( $method->resolve_services_for_order( $order ), 'code' ), 'stored: ' . var_export( $stored, true ) );
		}

		// no shipping line of this method at all
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_items' )->andReturn( [] );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( [] );
		$this->assertSame( [ 'SMS_NOTICE' ], array_column( $method->resolve_services_for_order( $order ), 'code' ) );
	}

	/** @return void */
	public function test_requoting_the_order_refreshes_the_snapshot(): void {
		$method = new Woodev_Test_Quoting_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE' ];
		[ $first ] = $this->order_with_snapshot( $this->quoted_snapshot( $method ) );

		// the merchant changes the method; the admin wizard re-quotes the order, which writes a new line with the new rate's meta
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];
		[ $requoted ] = $this->order_with_snapshot( $this->quoted_snapshot( $method ) );

		$this->assertSame( [ 'INSURANCE' ], array_column( $method->resolve_services_for_order( $first ), 'code' ), 'untouched: the old snapshot' );
		$this->assertSame( [ 'SMS_NOTICE' ], array_column( $method->resolve_services_for_order( $requoted ), 'code' ), 're-quoted: the new one' );
	}

	/**
	 * Pins the documented limitation (#1145 round 3): the framework has no shipment ↔ shipping-line link, so an order
	 * with several lines of one method is exported from the FIRST line's snapshot, like every other per-line datum.
	 *
	 * @return void
	 */
	public function test_an_order_with_several_lines_of_one_method_exports_the_first_lines_snapshot(): void {
		$method = new Woodev_Test_Quoting_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE' ];
		$first_snapshot = $this->quoted_snapshot( $method );

		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'SMS_NOTICE' ];
		$second_snapshot = $this->quoted_snapshot( $method );

		$lines = [];

		foreach ( [ $first_snapshot, $second_snapshot ] as $snapshot ) {
			$shipping = Mockery::mock( 'WC_Order_Item_Shipping' );
			$shipping->shouldReceive( 'get_method_id' )->andReturn( 'rate-cache-method' );
			$shipping->shouldReceive( 'get_meta' )->with( Shipping_Method::META_QUOTED_SERVICES, true )->andReturn( $snapshot );
			$lines[] = $shipping;
		}

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( [ $this->line( 'a', '80.50' ) ] );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( $lines );

		$this->assertSame( [ 'INSURANCE' ], array_column( $method->get_quoted_services( $order ), 'code' ), 'the first matching line is read' );
		$this->assertSame( [ 'INSURANCE' ], array_column( $method->resolve_services_for_order( $order ), 'code' ), 'and the export follows it' );
	}

	/** @return void */
	public function test_a_split_shipment_keeps_the_quoted_codes_and_values_a_declared_value_from_its_own_lines(): void {
		$method = new Woodev_Test_Quoting_Services_Method();
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'INSURANCE', 'SMS_NOTICE' ];
		[ $order, $kept ] = $this->order_with_snapshot( $this->quoted_snapshot( $method ) );

		$method->option_values = [];

		$this->assertSame(
			[
				[ 'code' => 'INSURANCE', 'name' => 'Страхование', 'parameter' => 80.5 ],
				[ 'code' => 'SMS_NOTICE', 'name' => 'SMS-уведомление', 'parameter' => null ],
			],
			$method->resolve_services_for_order( $order, [ $kept ] )
		);
	}

	/** @return void */
	public function test_a_computed_parameter_is_frozen_in_the_snapshot(): void {
		$method = new class() extends Woodev_Test_Custom_Services_Method {
			/**
			 * @param array $package package.
			 * @return Shipping_Rate|null
			 */
			public function quote( array $package ): ?Shipping_Rate {
				$this->next_rate = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 350 );
				return $this->calculate_rate( $package );
			}
		};
		$method->option_values[ Shipping_Method::OPTION_SERVICES ] = [ 'TRY_ON' ];

		[ $order ] = $this->order_with_snapshot( $method->quote( [] )->get_meta_data()[ Shipping_Method::META_QUOTED_SERVICES ] );

		$method->try_on = 9;

		$this->assertSame( 2, $method->resolve_services_for_order( $order )[0]['parameter'], 'the quoted value, not today\'s' );
	}
}

<?php
/**
 * {@see Admin_Rate_Calculator} — rates for a package the admin order wizard built by hand
 * (#965, spec D2).
 *
 * The zone lookup is WooCommerce's (`WC_Shipping_Zones`), so it is stood in for by overriding
 * {@see Admin_Rate_Calculator::resolve_methods()}; everything else — the package, the state code,
 * the per-provider grouping, the rate shape — runs for real.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base — `Shipping_Method` extends it, see
		 * ShippingMethodFilterReturnGuardsTest for why it is declared this way.
		 */
		class WC_Shipping_Method {

			/** @var string */
			public $id;

			/** @var array */
			public array $supports = [];

			/** @var array */
			public $instance_form_fields = [];

			/** @var array */
			public $settings = [];

			/** @var string */
			public $title = '';

			/**
			 * @param string $feature feature flag.
			 * @return bool
			 */
			public function supports( $feature ) {
				return in_array( $feature, $this->supports, true );
			}

			/**
			 * @param string $key     option key.
			 * @param mixed  $default fallback.
			 * @return mixed
			 */
			public function get_option( $key, $default = null ) {
				return $this->settings[ $key ] ?? $default;
			}

			/** @return string */
			public function get_title() {
				return $this->title;
			}
		}
	}

	if ( ! class_exists( 'WC_Shipping_Rate', false ) ) {
		/**
		 * The slice of `WC_Shipping_Rate` the calculator reads.
		 */
		class WC_Shipping_Rate {

			/** @var array<string, mixed> */
			private array $data;

			/**
			 * @param array<string, mixed> $data rate fields.
			 */
			public function __construct( array $data ) {
				$this->data = $data;
			}

			/** @return string */
			public function get_id() {
				return $this->data['id'];
			}

			/** @return string */
			public function get_method_id() {
				return $this->data['method_id'];
			}

			/** @return int */
			public function get_instance_id() {
				return $this->data['instance_id'];
			}

			/** @return string */
			public function get_label() {
				return $this->data['label'];
			}

			/** @return string */
			public function get_cost() {
				return $this->data['cost'];
			}

			/** @return array */
			public function get_meta_data() {
				return $this->data['meta'] ?? [];
			}

			/** @return string */
			public function get_delivery_time() {
				return $this->data['delivery_time'] ?? '';
			}

			/** @return string */
			public function get_description() {
				return $this->data['description'] ?? '';
			}
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Admin {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Admin\Orders\Admin_Rate_Calculator;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-method.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/admin/orders/class-orders-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/admin/orders/class-admin-rate-calculator.php';

	/**
	 * Calculator with the WooCommerce zone lookup replaced by fixed answers.
	 */
	final class Admin_Rate_Calculator_With_Fixed_Zone extends Admin_Rate_Calculator {

		/** @var array<int, Shipping_Method> */
		public array $zone_methods = [];

		/** @var array<string, mixed>|null the package the zone lookup was asked about. */
		public ?array $zone_asked_about = null;

		/** @var array<string, array<string, string>> what `WC()->countries->get_states()` answers per country. */
		public array $states = [];

		/**
		 * @param string $country country code.
		 * @return array<string, string>
		 */
		protected function get_country_states( string $country ): array {
			return $this->states[ $country ] ?? [];
		}

		/**
		 * @param string $postcode postcode as typed.
		 * @param string $country  country code.
		 * @return string
		 */
		protected function format_postcode( string $postcode, string $country ): string {
			return strtoupper( str_replace( ' ', '', $postcode ) );
		}

		/**
		 * @param array<string, mixed> $package WooCommerce package.
		 * @return array{0: object, 1: Shipping_Method[]}
		 */
		protected function resolve_methods( array $package ): array {
			$this->zone_asked_about = $package;

			$zone = Mockery::mock( '\WC_Shipping_Zone' );
			$zone->shouldReceive( 'get_id' )->andReturn( 1 );
			$zone->shouldReceive( 'get_zone_name' )->andReturn( 'Russia' );

			return [ $zone, $this->zone_methods ];
		}
	}

	/**
	 * @coversDefaultClass \Woodev\Framework\Shipping\Admin\Orders\Admin_Rate_Calculator
	 */
	final class AdminRateCalculatorTest extends TestCase {

		/** @var array<string, array<string, string>> what `WC()->countries->get_states()` answers per country. */
		private array $states = [];

		/** @return void */
		protected function setUp(): void {
			parent::setUp();

			// The Settings API handler behind a carrier's order fields (D7) merges its arguments with this.
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);

			$this->states = [
				'RU' => [
					'МОСКВА'          => 'Москва',
					'САНКТ-ПЕТЕРБУРГ' => 'Санкт-Петербург',
				],
				'US' => [
					'CA' => 'California',
					'NY' => 'New York',
				],
				'KZ' => [],
			];
		}

		/** @return Admin_Rate_Calculator_With_Fixed_Zone */
		private function calculator( Orders_Provider ...$providers ): Admin_Rate_Calculator_With_Fixed_Zone {
			$registry = Mockery::mock( Orders_Registry::class );
			$registry->shouldReceive( 'get_providers' )->andReturn( $providers );

			$calculator         = new Admin_Rate_Calculator_With_Fixed_Zone( $registry );
			$calculator->states = $this->states;

			return $calculator;
		}

		/**
		 * @param string   $id         provider id.
		 * @param string[] $method_ids the provider's WooCommerce method ids.
		 * @return Orders_Provider
		 */
		private function provider( string $id, array $method_ids ): Orders_Provider {
			return Orders_Provider::create( $id, strtoupper( $id ) . ' label', '_' . $id . '_marker', $method_ids );
		}

		/**
		 * @param string                        $id    method id.
		 * @param bool                          $pickup whether it is a pickup method.
		 * @param array<int, \WC_Shipping_Rate> $rates what the seam answers.
		 * @return Shipping_Method&\Mockery\MockInterface
		 */
		private function method( string $id, bool $pickup, array $rates ) {
			$method     = Mockery::mock( Shipping_Method::class );
			$method->id = $id;
			$method->shouldReceive( 'is_pickup_shipping' )->andReturn( $pickup );
			$method->shouldReceive( 'get_admin_rates_for_package' )->andReturnUsing(
				static function () use ( $rates ) {
					$keyed = [];

					foreach ( $rates as $rate ) {
						$keyed[ $rate->get_id() ] = $rate;
					}

					return $keyed;
				}
			);

			return $method;
		}

		/**
		 * @param array<string, mixed> $overrides fields to override.
		 * @return \WC_Shipping_Rate
		 */
		private function rate( array $overrides = [] ): \WC_Shipping_Rate {
			return new \WC_Shipping_Rate(
				array_merge(
					[
						'id'          => 'test_shipping:3',
						'method_id'   => 'test_shipping',
						'instance_id' => 3,
						'label'       => 'Курьер',
						'cost'        => '250.50',
					],
					$overrides
				)
			);
		}

		/**
		 * @param float $price       the product's own price.
		 * @param bool  $shippable   whether it needs shipping.
		 * @param int   $id          product id.
		 * @param int   $parent_id   parent id (variations).
		 * @param string $weight     the product's weight in the store's unit ('' = none set).
		 * @return \WC_Product&\Mockery\MockInterface
		 */
		private function product( float $price = 100.0, bool $shippable = true, int $id = 12, int $parent_id = 0, string $weight = '' ) {
			$product = Mockery::mock( $parent_id > 0 ? '\WC_Product_Variation' : '\WC_Product' );
			$product->shouldReceive( 'needs_shipping' )->andReturn( $shippable );
			$product->shouldReceive( 'get_price' )->andReturn( (string) $price );
			$product->shouldReceive( 'get_id' )->andReturn( $id );
			$product->shouldReceive( 'get_parent_id' )->andReturn( $parent_id );
			$product->shouldReceive( 'get_weight' )->andReturn( $weight );
			$product->shouldReceive( 'get_variation_attributes' )->andReturn( [ 'attribute_pa_color' => 'red' ] );

			return $product;
		}

		/**
		 * @return Location_Record
		 */
		private function moscow_region_record(): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => 'test:region:moscow',
					'provider_id' => 'test',
					'level'       => Location_Record::LEVEL_REGION,
					'country'     => 'ru',
					'region'      => [
						'name' => 'Москва',
						'type' => 'г',
					],
					'postcode'    => '101000',
					'label'       => 'Москва',
				]
			);
		}

		/* ------------------------------------------------------------------ *
		 * State code (spec D2 «Mine 1b»)
		 * ------------------------------------------------------------------ */

		/**
		 * @covers ::resolve_state_code
		 *
		 * @return void
		 */
		public function test_a_state_label_maps_to_the_woocommerce_code(): void {
			$this->assertSame( 'МОСКВА', $this->calculator()->resolve_state_code( 'RU', 'Москва' ) );
			$this->assertSame( 'МОСКВА', $this->calculator()->resolve_state_code( 'RU', 'москва' ), 'case-insensitive' );
			$this->assertSame( 'CA', $this->calculator()->resolve_state_code( 'US', 'California' ) );
		}

		/**
		 * @covers ::resolve_state_code
		 *
		 * @return void
		 */
		public function test_a_code_the_country_knows_is_kept(): void {
			$this->assertSame( 'МОСКВА', $this->calculator()->resolve_state_code( 'RU', 'МОСКВА' ) );
			$this->assertSame( 'NY', $this->calculator()->resolve_state_code( 'US', 'ny' ), 'a code is matched case-insensitively too' );
		}

		/**
		 * A code WooCommerce's checkout would refuse must not become a made-up zone match.
		 *
		 * @covers ::resolve_state_code
		 *
		 * @return void
		 */
		public function test_an_unknown_state_in_a_country_with_a_list_yields_empty(): void {
			$this->assertSame( '', $this->calculator()->resolve_state_code( 'RU', 'MOW' ) );
		}

		/**
		 * @covers ::resolve_state_code
		 *
		 * @return void
		 */
		public function test_a_country_without_a_state_list_takes_the_value_as_typed(): void {
			$this->assertSame( 'Алматы', $this->calculator()->resolve_state_code( 'KZ', 'Алматы' ) );
			$this->assertSame( 'Somewhere', $this->calculator()->resolve_state_code( 'ZZ', 'Somewhere' ), 'unknown country: no list at all' );
		}

		/**
		 * @covers ::resolve_state_code
		 *
		 * @return void
		 */
		public function test_an_empty_state_stays_empty(): void {
			$this->assertSame( '', $this->calculator()->resolve_state_code( 'RU', '  ' ) );
		}

		/* ------------------------------------------------------------------ *
		 * Destination
		 * ------------------------------------------------------------------ */

		/**
		 * @covers ::normalize_destination
		 *
		 * @return void
		 */
		public function test_the_record_fills_what_the_manager_did_not_type(): void {
			$destination = $this->calculator()->normalize_destination( [ 'address' => 'ул Тверская 1' ], $this->moscow_region_record() );

			$this->assertSame(
				[
					'country'   => 'RU',
					'state'     => 'МОСКВА',
					'postcode'  => '101000',
					'city'      => '',
					'address'   => 'ул Тверская 1',
					'address_1' => 'ул Тверская 1',
					'address_2' => '',
				],
				$destination
			);
		}

		/**
		 * @covers ::normalize_destination
		 *
		 * @return void
		 */
		public function test_typed_fields_win_over_the_record_and_the_postcode_is_formatted(): void {
			$destination = $this->calculator()->normalize_destination(
				[
					'country'   => 'us',
					'state'     => 'California',
					'city'      => 'Los Angeles',
					'postcode'  => '90 001',
					'address_2' => 'apt 4',
				],
				$this->moscow_region_record()
			);

			$this->assertSame( 'US', $destination['country'] );
			$this->assertSame( 'CA', $destination['state'] );
			$this->assertSame( 'Los Angeles', $destination['city'] );
			$this->assertSame( '90001', $destination['postcode'] );
			$this->assertSame( 'apt 4', $destination['address_2'] );
		}

		/* ------------------------------------------------------------------ *
		 * Package (spec D2 first bullet, «Mine 2»)
		 * ------------------------------------------------------------------ */

		/**
		 * @covers ::build_package
		 *
		 * @return void
		 */
		public function test_the_package_carries_the_edited_price_and_only_shippable_lines(): void {
			$package = $this->calculator()->build_package(
				[
					[
						'product'  => $this->product( 100.0, true, 12 ),
						'quantity' => 2,
						'price'    => 150.0,
					],
					[
						'product'  => $this->product( 999.0, false, 13 ),
						'quantity' => 1,
						'price'    => null,
					],
					[
						'product'  => $this->product( 40.0, true, 14 ),
						'quantity' => 3,
						'price'    => null,
					],
				],
				[ 'country' => 'RU' ],
				7
			);

			$this->assertCount( 2, $package['contents'], 'a virtual product is not in a shipping package' );

			$totals = array_map(
				static function ( array $line ): array {
					return [ $line['quantity'], $line['line_total'], $line['line_subtotal'] ];
				},
				array_values( $package['contents'] )
			);

			$this->assertSame( [ [ 2, 300.0, 300.0 ], [ 3, 120.0, 120.0 ] ], $totals, 'edited price for the first, own price for the third' );
			$this->assertSame( 420.0, $package['contents_cost'] );
			$this->assertSame( 420.0, $package['cart_subtotal'] );
			$this->assertSame( [], $package['applied_coupons'] );
			$this->assertSame( [ 'ID' => 7 ], $package['user'] );
			$this->assertSame( [ 'country' => 'RU' ], $package['destination'] );
		}

		/**
		 * Mine 2: a package-hash-keyed cache must not see a shopper's package as its own.
		 *
		 * @covers ::build_package
		 *
		 * @return void
		 */
		public function test_the_package_is_marked_as_an_admin_calculator_package(): void {
			$package = $this->calculator()->build_package( [], [] );

			$this->assertTrue( $package[ Admin_Rate_Calculator::PACKAGE_MARKER ] );
		}

		/**
		 * @covers ::build_package
		 *
		 * @return void
		 */
		public function test_a_variation_line_carries_its_parent_and_attributes(): void {
			$package = $this->calculator()->build_package(
				[
					[
						'product'  => $this->product( 10.0, true, 55, 50 ),
						'quantity' => 1,
						'price'    => null,
					],
				],
				[]
			);

			$line = array_values( $package['contents'] )[0];

			$this->assertSame( 50, $line['product_id'] );
			$this->assertSame( 55, $line['variation_id'] );
			$this->assertSame( [ 'attribute_pa_color' => 'red' ], $line['variation'] );
		}

		/* ------------------------------------------------------------------ *
		 * calculate() — grouping by provider, O12
		 * ------------------------------------------------------------------ */

		/**
		 * @covers ::calculate
		 *
		 * @return void
		 */
		public function test_rates_are_grouped_by_provider_and_only_its_own_methods_count(): void {
			$calculator               = $this->calculator(
				$this->provider( 'test', [ 'test_shipping' ] ),
				$this->provider( 'realistic', [ 'realistic_shipping', 'realistic_pickup_shipping' ] )
			);
			$calculator->zone_methods = [
				$this->method( 'test_shipping', false, [ $this->rate() ] ),
				$this->method(
					'realistic_pickup_shipping',
					true,
					[
						$this->rate(
							[
								'id'            => 'realistic_pickup_shipping:5',
								'method_id'     => 'realistic_pickup_shipping',
								'instance_id'   => 5,
								'label'         => 'ПВЗ',
								'cost'          => '120',
								'delivery_time' => '2-3 дня',
								'description'   => 'Пункт выдачи',
								'meta'          => [ 'tariff_code' => 137 ],
							]
						),
					]
				),
				// A method no provider claims must not surface under any carrier (O12).
				$this->method( 'somebody_elses_method', false, [ $this->rate( [ 'id' => 'somebody_elses_method:9' ] ) ] ),
			];

			$result = $calculator->calculate(
				[
					[
						'product'  => $this->product(),
						'quantity' => 1,
						'price'    => null,
					],
				],
				[ 'country' => 'RU' ]
			);

			$this->assertTrue( $result['needs_shipping'] );
			$this->assertSame( [ 'id' => 1, 'name' => 'Russia' ], $result['zone'] );
			$this->assertSame( [ 'test', 'realistic' ], array_column( $result['providers'], 'id' ) );
			$this->assertSame( [ 'TEST label', 'REALISTIC label' ], array_column( $result['providers'], 'label' ) );

			$this->assertSame(
				[
					[
						'id'            => 'test_shipping:3',
						'method_id'     => 'test_shipping',
						'instance_id'   => 3,
						'label'         => 'Курьер',
						'cost'          => 250.5,
						'delivery_time' => '',
						'description'   => '',
						'is_pickup'     => false,
						'meta'          => [],
						'order_fields'  => [],
					],
				],
				$result['providers'][0]['rates']
			);

			$this->assertSame(
				[
					[
						'id'            => 'realistic_pickup_shipping:5',
						'method_id'     => 'realistic_pickup_shipping',
						'instance_id'   => 5,
						'label'         => 'ПВЗ',
						'cost'          => 120.0,
						'delivery_time' => '2-3 дня',
						'description'   => 'Пункт выдачи',
						'is_pickup'     => true,
						'meta'          => [ 'tariff_code' => 137 ],
						'order_fields'  => [],
					],
				],
				$result['providers'][1]['rates'],
				'rate meta survives to the response'
			);
		}

		/**
		 * D7 (#973): a carrier's own order fields ride on each of its rates, declared per tariff — the
		 * declaration is asked once per method instance with that method's context.
		 *
		 * @covers ::calculate
		 *
		 * @return void
		 */
		public function test_a_rate_carries_the_carriers_order_fields_declared_for_its_tariff(): void {
			$asked    = [];
			$provider = Orders_Provider::create(
				'test',
				'TEST label',
				'_test_marker',
				[ 'test_shipping', 'test_pickup_shipping' ],
				[
					'order_fields' => static function ( array $context ) use ( &$asked ): array {
						$asked[] = [ $context['method_id'], $context['instance_id'], $context['rate_id'], $context['is_pickup'] ];

						$fields = [
							'declared_value' => [
								'meta_key' => '_test_declared_value',
								'control'  => 'number',
								'name'     => 'Объявленная ценность',
							],
						];

						if ( ! $context['is_pickup'] ) {
							$fields['call_before'] = [
								'meta_key' => '_test_call_before',
								'control'  => 'toggle',
								'name'     => 'Позвонить',
							];
						}

						return $fields;
					},
				]
			);

			$courier              = $this->method( 'test_shipping', false, [ $this->rate(), $this->rate( [ 'id' => 'test_shipping:3:express', 'label' => 'Экспресс' ] ) ] );
			$courier->instance_id = 3;
			$pickup               = $this->method( 'test_pickup_shipping', true, [ $this->rate( [ 'id' => 'test_pickup_shipping:5', 'method_id' => 'test_pickup_shipping', 'instance_id' => 5 ] ) ] );
			$pickup->instance_id  = 5;

			$calculator               = $this->calculator( $provider );
			$calculator->zone_methods = [ $courier, $pickup ];

			$rates = $calculator->calculate(
				[ [ 'product' => $this->product(), 'quantity' => 1, 'price' => null ] ],
				[ 'country' => 'RU' ]
			)['providers'][0]['rates'];

			$this->assertCount( 3, $rates );
			$this->assertSame( [ 'declared_value', 'call_before' ], array_column( $rates[0]['order_fields'], 'id' ) );
			$this->assertSame( $rates[0]['order_fields'], $rates[1]['order_fields'], 'every rate of one method carries the same declaration' );
			$this->assertSame( [ 'declared_value' ], array_column( $rates[2]['order_fields'], 'id' ), 'a pickup tariff asks for less' );
			$this->assertSame( 'number', $rates[0]['order_fields'][0]['controlType'] );
			$this->assertSame(
				[ [ 'test_shipping', 3, 'test_shipping:3', false ], [ 'test_pickup_shipping', 5, 'test_pickup_shipping:5', true ] ],
				$asked,
				'asked once per method instance, not once per rate'
			);
		}

		/**
		 * A provider with nothing in this zone still appears, so the wizard can say so.
		 *
		 * @covers ::calculate
		 *
		 * @return void
		 */
		public function test_a_provider_with_no_method_in_the_zone_appears_with_no_rates(): void {
			$calculator               = $this->calculator( $this->provider( 'test', [ 'test_shipping' ] ) );
			$calculator->zone_methods = [];

			$result = $calculator->calculate(
				[
					[
						'product'  => $this->product(),
						'quantity' => 1,
						'price'    => null,
					],
				],
				[ 'country' => 'RU' ]
			);

			$this->assertSame( [ [ 'id' => 'test', 'label' => 'TEST label', 'rates' => [] ] ], $result['providers'] );
		}

		/**
		 * The wizard hands the pickup routes an explicit weight in GRAMS, so the calculator reports
		 * the package's: line weights times quantities, converted from the store's unit by the same
		 * authority the storefront's cart weight uses.
		 *
		 * @covers ::calculate
		 * @covers ::package_weight_grams
		 *
		 * @return void
		 */
		public function test_it_reports_the_package_weight_in_grams(): void {
			// The store keeps kilograms; the conversion authority answers grams.
			Functions\when( 'wc_get_weight' )->alias(
				static function ( $weight, $unit ) {
					return 'g' === $unit ? (float) $weight * 1000 : $weight;
				}
			);

			$calculator               = $this->calculator( $this->provider( 'test', [ 'test_shipping' ] ) );
			$calculator->zone_methods = [];

			$result = $calculator->calculate(
				[
					[
						'product'  => $this->product( 100.0, true, 12, 0, '1.5' ),
						'quantity' => 2,
						'price'    => null,
					],
					[
						'product'  => $this->product( 50.0, true, 13, 0, '0.25' ),
						'quantity' => 1,
						'price'    => null,
					],
					// Needs no shipping, so it is not in the package and weighs nothing here.
					[
						'product'  => $this->product( 10.0, false, 14, 0, '9' ),
						'quantity' => 1,
						'price'    => null,
					],
				],
				[ 'country' => 'RU' ]
			);

			$this->assertSame( 3250, $result['weight'] );
		}

		/**
		 * @covers ::calculate
		 * @covers ::package_weight_grams
		 *
		 * @return void
		 */
		public function test_a_package_with_no_weights_reports_zero_which_the_pickup_routes_read_as_unknown(): void {
			$calculator               = $this->calculator( $this->provider( 'test', [ 'test_shipping' ] ) );
			$calculator->zone_methods = [];

			$result = $calculator->calculate(
				[
					[
						'product'  => $this->product(),
						'quantity' => 3,
						'price'    => null,
					],
				],
				[ 'country' => 'RU' ]
			);

			$this->assertSame( 0, $result['weight'] );
		}

		/**
		 * @covers ::calculate
		 *
		 * @return void
		 */
		public function test_a_package_with_nothing_to_ship_prices_nothing_and_skips_the_zone_lookup(): void {
			$calculator               = $this->calculator( $this->provider( 'test', [ 'test_shipping' ] ) );
			$calculator->zone_methods = [ $this->method( 'test_shipping', false, [ $this->rate() ] ) ];

			$result = $calculator->calculate(
				[
					[
						'product'  => $this->product( 10.0, false ),
						'quantity' => 1,
						'price'    => null,
					],
				],
				[ 'country' => 'RU' ]
			);

			$this->assertFalse( $result['needs_shipping'] );
			$this->assertNull( $result['zone'] );
			$this->assertNull( $calculator->zone_asked_about, 'no zone lookup for an empty package' );
			$this->assertSame( [], $result['providers'][0]['rates'] );
		}

		/**
		 * The zone is asked about the package the wizard built — with the STATE CODE in it.
		 *
		 * @covers ::calculate
		 *
		 * @return void
		 */
		public function test_the_zone_is_resolved_for_the_built_package(): void {
			$calculator = $this->calculator( $this->provider( 'test', [ 'test_shipping' ] ) );
			$record     = $this->moscow_region_record();

			$calculator->calculate(
				[
					[
						'product'  => $this->product(),
						'quantity' => 1,
						'price'    => 10.0,
					],
				],
				$calculator->normalize_destination( [], $record ),
				$record,
				9
			);

			$this->assertSame( 'МОСКВА', $calculator->zone_asked_about['destination']['state'] );
			$this->assertSame( [ 'ID' => 9 ], $calculator->zone_asked_about['user'] );
			$this->assertSame( 10.0, $calculator->zone_asked_about['contents_cost'] );
		}

		/**
		 * The record reaches the seam (Mine 3) — not just the package.
		 *
		 * @covers ::calculate
		 *
		 * @return void
		 */
		public function test_the_destination_record_is_handed_to_every_method(): void {
			$record = $this->moscow_region_record();

			$method     = Mockery::mock( Shipping_Method::class );
			$method->id = 'test_shipping';
			$method->shouldReceive( 'is_pickup_shipping' )->andReturn( false );
			$method->shouldReceive( 'get_admin_rates_for_package' )
				->once()
				->with( Mockery::type( 'array' ), $record )
				->andReturn( [] );

			$calculator               = $this->calculator( $this->provider( 'test', [ 'test_shipping' ] ) );
			$calculator->zone_methods = [ $method ];

			$calculator->calculate(
				[
					[
						'product'  => $this->product(),
						'quantity' => 1,
						'price'    => null,
					],
				],
				[ 'country' => 'RU' ],
				$record
			);
		}
	}
}

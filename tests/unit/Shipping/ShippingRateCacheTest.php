<?php
/**
 * Shipping_Rate_Cache — the transient-backed cache of successful rates (#958, spec §6 / SP-6).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base — identical to the stub in
		 * `ShippingMethodFilterReturnGuardsTest`; whichever file loads first wins, so the two must match.
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
}

namespace Woodev\Tests\Unit\Shipping {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Framework\Shipping\Shipping_Rate;
	use Woodev\Framework\Shipping\Shipping_Rate_Cache;
	use Woodev\Tests\Unit\TestCase;

	/** Minimal Shipping_Plugin double (the real constructor is bypassed). */
	class Woodev_Test_Shipping_Plugin_For_Rate_Cache extends Shipping_Plugin {

		/** @var \Woodev\Framework\Shipping\Pickup\Pickup_Handler|null */
		public $pickup_handler = null;

		public function __construct() {}

		/** @return \Woodev\Framework\Shipping\Pickup\Pickup_Handler|null */
		public function get_pickup_handler(): ?\Woodev\Framework\Shipping\Pickup\Pickup_Handler {
			return $this->pickup_handler;
		}

		/** @return array */
		protected function get_shipping_method_classes(): array {
			return [];
		}

		/** @return string */
		protected function get_file() {
			return __FILE__;
		}

		/** @return string */
		public function get_plugin_name() {
			return 'Rate Cache Plugin';
		}

		/** @return int */
		public function get_download_id() {
			return 0;
		}

		/** @return string */
		public function get_id() {
			return 'rate-cache';
		}

		/** @return string */
		public function get_id_underscored() {
			return 'rate_cache';
		}

		/** @return null */
		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}

		/**
		 * @param string      $message log line.
		 * @param string|null $log_id  log id.
		 * @return void
		 */
		public function log( $message, $log_id = null ) {}

		/**
		 * @param string      $message log line.
		 * @param string|null $log_id  log id.
		 * @return void
		 */
		public function log_error( $message, $log_id = null ): void {}
	}

	/** Minimal Shipping_Method double that counts carrier calls. */
	class Woodev_Test_Shipping_Method_For_Rate_Cache extends Shipping_Method {

		/** @var string */
		public $id = 'rate-cache-method';

		/** @var int */
		public $instance_id = 1;

		/** @var array */
		public $instance_settings = [];

		/** @var array opted in by default: the cache is OFF for a method that does not declare the feature. */
		public array $supports = [ Shipping_Method::FEATURE_RATE_CACHE ];

		/** @var array */
		public $instance_form_fields = [];

		/** @var array options returned by get_option() — kept apart from instance_settings so a test can move one without the other. */
		public array $option_values = [];

		/** @var string payment method the probe pretends is chosen. */
		public string $payment = '';

		/** @var \Woodev\Framework\Shipping\Pickup\Pickup_Handler|null */
		public $pickup_handler = null;

		/** @var Shipping_Rate|null */
		public ?Shipping_Rate $next_rate = null;

		/** @var \Throwable|null */
		public ?\Throwable $next_exception = null;

		/** @var int */
		public int $carrier_calls = 0;

		/** @var int */
		public int $added = 0;

		public function __construct() {}

		/**
		 * @param array $args rate args.
		 * @return void
		 */
		public function add_rate( $args = [] ) {
			++$this->added;
		}

		/** @return string */
		public function get_title() {
			return 'Rate Cache Method';
		}

		/** @return string */
		public static function get_method_id(): string {
			return 'rate-cache-method';
		}

		/** @return string */
		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/**
		 * @param string $key           option key.
		 * @param mixed  $empty_value   fallback.
		 * @return mixed
		 */
		public function get_option( $key, $empty_value = null ) {
			return $this->option_values[ $key ] ?? $empty_value;
		}

		/** @return string */
		protected function chosen_payment_method(): string {
			return $this->payment;
		}

		/** @return Shipping_Plugin */
		protected function get_plugin(): Shipping_Plugin {
			$plugin                 = new Woodev_Test_Shipping_Plugin_For_Rate_Cache();
			$plugin->pickup_handler = $this->pickup_handler;

			return $plugin;
		}

		/**
		 * @param array                      $package unused.
		 * @param \Woodev_Packer_Result|null $packed  unused.
		 * @return Shipping_Rate|null
		 */
		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
			++$this->carrier_calls;

			if ( null !== $this->next_exception ) {
				throw $this->next_exception;
			}

			return $this->next_rate;
		}
	}

	/** A carrier that adds a global setting (its origin / account) through the context seam. */
	class Woodev_Test_Shipping_Method_With_Origin extends Woodev_Test_Shipping_Method_For_Rate_Cache {

		/** @var mixed read from a «global plugin setting». */
		public $origin = 'MOW';

		/**
		 * @param array $package package.
		 * @return array
		 */
		public function get_rate_cache_context( array $package ): array {
			$context           = parent::get_rate_cache_context( $package );
			$context['origin'] = $this->origin;

			return $context;
		}
	}

	/**
	 * @coversDefaultClass \Woodev\Framework\Shipping\Shipping_Rate_Cache
	 */
	final class ShippingRateCacheTest extends TestCase {

		/** @var array<string, mixed> in-memory transients. */
		private array $store = [];

		/** @var int[] TTLs handed to set_transient(). */
		private array $ttls = [];

		/** @var array<string, callable> filter overrides by tag. */
		private array $filters = [];

		/** @var string */
		private string $currency = 'RUB';

		/** @var int get/set_transient calls — a method that did not opt in must leave this at zero. */
		private int $transient_calls = 0;

		/** @var array<string, mixed> WooCommerce options by name. */
		private array $options = [];

		/** @return void */
		protected function setUp(): void {
			parent::setUp();

			// the default-dimensions handler converts its built-in defaults (#955): units are not under test here
			Functions\when( 'wc_get_weight' )->returnArg( 1 );
			Functions\when( 'wc_get_dimension' )->returnArg( 1 );

			$this->store    = [];
			$this->ttls     = [];
			$this->filters  = [];
			$this->currency = 'RUB';

			$this->transient_calls = 0;
			$this->options         = [];

			Functions\when( 'get_transient' )->alias(
				function ( $key ) {
					++$this->transient_calls;

					return $this->store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				function ( $key, $value, $ttl = 0 ) {
					++$this->transient_calls;

					$this->store[ $key ] = $value;
					$this->ttls[]        = $ttl;

					return true;
				}
			);
			Functions\when( 'get_woocommerce_currency' )->alias( fn() => $this->currency );
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
			// the store's boxes (#1138) strip tags from a box's name
			Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
			Functions\when( 'wp_unslash' )->returnArg( 1 );
			// the effective package values read the store's default dimensions (#955), a settings handler
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			Functions\when( 'apply_filters' )->alias(
				function ( $tag, $value = null, ...$args ) {
					return isset( $this->filters[ $tag ] ) ? ( $this->filters[ $tag ] )( $value, ...$args ) : $value;
				}
			);
			Functions\when( 'do_action' )->justReturn( null );
		}

		/** @return void */
		protected function tearDown(): void {
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			parent::tearDown();
		}

		/**
		 * @param array $overrides package overrides.
		 * @return array
		 */
		private function package( array $overrides = [] ): array {
			return array_merge(
				[
					'contents'      => [
						'a' => [
							'product_id'   => 10,
							'variation_id' => 0,
							'quantity'     => 2,
						],
					],
					'contents_cost' => 100,
					'destination'   => [
						'country'  => 'RU',
						'state'    => 'MOW',
						'city'     => 'Moscow',
						'postcode' => '101000',
					],
				],
				$overrides
			);
		}

		/** @return Shipping_Rate */
		private function rate(): Shipping_Rate {
			return new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 350, null, [ 'days' => 3 ], [ 'description' => 'Fast' ] );
		}

		/** @return Woodev_Test_Shipping_Method_For_Rate_Cache */
		private function method(): Woodev_Test_Shipping_Method_For_Rate_Cache {
			$method            = new Woodev_Test_Shipping_Method_For_Rate_Cache();
			$method->next_rate = $this->rate();

			return $method;
		}

		/**
		 * @param Woodev_Test_Shipping_Method_For_Rate_Cache $method  method.
		 * @param array                                      $package package.
		 * @return string
		 */
		private function key( $method, array $package ): string {
			return (string) ( new Shipping_Rate_Cache() )->build_key( $method, $package );
		}

		/** @return void */
		public function test_second_calculation_is_served_from_the_cache(): void {
			$method = $this->method();

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 1, $method->carrier_calls, 'the carrier is asked once' );
			$this->assertSame( 2, $method->added, 'both calculations still add the rate' );
			$this->assertCount( 1, $this->store );
		}

		/** @return void */
		public function test_a_hit_restores_the_whole_rate(): void {
			$cache  = new Shipping_Rate_Cache();
			$method = $this->method();

			$this->assertNull( $cache->get( $method, $this->package() ) );
			$this->assertTrue( $cache->put( $method, $this->package(), $this->rate() ) );

			$restored = $cache->get( $method, $this->package() );

			$this->assertInstanceOf( Shipping_Rate::class, $restored );
			$this->assertEquals( $this->rate()->to_array(), $restored->to_array() );
			$this->assertSame( 'Fast', $restored->get_arg( 'description' ) );
			$this->assertSame( 350, $restored->get_cost() );
		}

		/** @return void */
		public function test_a_carrier_exception_is_not_cached(): void {
			$method                 = $this->method();
			$method->next_exception = new \Woodev_Plugin_Exception( 'carrier down' );

			$method->calculate_shipping( $this->package() );

			$this->assertSame( [], $this->store );

			$method->next_exception = null;
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 2, $method->carrier_calls, 'a failure is retried, not remembered' );
			$this->assertCount( 1, $this->store );
		}

		/** @return void */
		public function test_an_empty_result_is_not_cached(): void {
			$method            = $this->method();
			$method->next_rate = null;

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( [], $this->store );
			$this->assertSame( 2, $method->carrier_calls );
		}

		/** @return void */
		public function test_a_rate_supplied_by_the_pre_calculate_filter_is_neither_read_from_nor_written_to_the_cache(): void {
			$this->filters['woodev_shipping_method_pre_calculate_rate'] = fn() => $this->rate();

			$method = $this->method();
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 0, $method->carrier_calls );
			$this->assertSame( [], $this->store );
		}

		/** @return void */
		public function test_a_rate_that_carries_an_object_is_not_stored(): void {
			$cache = new Shipping_Rate_Cache();
			$rate  = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 100, null, [ 'obj' => new \stdClass() ] );

			$this->assertFalse( $cache->put( $this->method(), $this->package(), $rate ) );
			$this->assertSame( [], $this->store );
		}

		/** @return array<string, array{0: array}> */
		public function package_changes(): array {
			return [
				'another product'         => [ [ 'contents' => [ 'a' => [ 'product_id' => 11, 'variation_id' => 0, 'quantity' => 2 ] ] ] ],
				'another variation'       => [ [ 'contents' => [ 'a' => [ 'product_id' => 10, 'variation_id' => 5, 'quantity' => 2 ] ] ] ],
				'another quantity'        => [ [ 'contents' => [ 'a' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 3 ] ] ] ],
				'an extra line'           => [
					[
						'contents' => [
							'a' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 2 ],
							'b' => [ 'product_id' => 12, 'variation_id' => 0, 'quantity' => 1 ],
						],
					],
				],
				'another contents cost'   => [ [ 'contents_cost' => 101 ] ],
				'another country'         => [ [ 'destination' => [ 'country' => 'BY', 'state' => 'MOW', 'city' => 'Moscow', 'postcode' => '101000' ] ] ],
				'another state'           => [ [ 'destination' => [ 'country' => 'RU', 'state' => 'SPE', 'city' => 'Moscow', 'postcode' => '101000' ] ] ],
				'another city'            => [ [ 'destination' => [ 'country' => 'RU', 'state' => 'MOW', 'city' => 'Tver', 'postcode' => '101000' ] ] ],
				'another postcode'        => [ [ 'destination' => [ 'country' => 'RU', 'state' => 'MOW', 'city' => 'Moscow', 'postcode' => '101001' ] ] ],
			];
		}

		/**
		 * @dataProvider package_changes
		 * @param array $change package override.
		 * @return void
		 */
		public function test_key_changes_with_every_package_input_that_matters( array $change ): void {
			$method = $this->method();

			$this->assertNotSame( $this->key( $method, $this->package() ), $this->key( $method, $this->package( $change ) ) );
		}

		/** @return void */
		public function test_key_ignores_cart_order_postcode_spacing_and_case(): void {
			$method = $this->method();
			$two    = [
				'a' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 2 ],
				'b' => [ 'product_id' => 12, 'variation_id' => 0, 'quantity' => 1 ],
			];

			$this->assertSame(
				$this->key( $method, $this->package( [ 'contents' => $two ] ) ),
				$this->key( $method, $this->package( [ 'contents' => array_reverse( $two, true ) ] ) )
			);
			$this->assertSame(
				$this->key( $method, $this->package( [ 'destination' => [ 'country' => 'GB', 'postcode' => 'sw1a 1aa' ] ] ) ),
				$this->key( $method, $this->package( [ 'destination' => [ 'country' => 'gb', 'postcode' => 'SW1A1AA' ] ] ) )
			);
		}

		/** @return void */
		public function test_key_changes_with_product_dimensions_weight_and_shipping_class(): void {
			$method = $this->method();

			$make = function ( array $values ): array {
				$product = Mockery::mock( 'WC_Product' );
				$product->shouldReceive( 'get_length' )->andReturn( $values['l'] );
				$product->shouldReceive( 'get_width' )->andReturn( $values['w'] );
				$product->shouldReceive( 'get_height' )->andReturn( $values['h'] );
				$product->shouldReceive( 'get_weight' )->andReturn( $values['kg'] );
				$product->shouldReceive( 'get_shipping_class_id' )->andReturn( $values['c'] );
				$product->shouldReceive( 'is_virtual' )->andReturn( $values['v'] ?? false );

				return $this->package( [ 'contents' => [ 'a' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 1, 'data' => $product ] ] ] );
			};

			$base = [ 'l' => '10', 'w' => '10', 'h' => '10', 'kg' => '1', 'c' => 0 ];
			$key  = $this->key( $method, $make( $base ) );

			$this->assertSame( $key, $this->key( $method, $make( $base ) ) );

			foreach ( [ 'l' => '11', 'w' => '11', 'h' => '11', 'kg' => '2', 'c' => 3, 'v' => true ] as $field => $value ) {
				$this->assertNotSame( $key, $this->key( $method, $make( array_merge( $base, [ $field => $value ] ) ) ), $field );
			}
		}

		/** @return void */
		public function test_key_changes_with_currency_instance_and_method(): void {
			$method = $this->method();
			$key    = $this->key( $method, $this->package() );

			$this->currency = 'USD';
			$this->assertNotSame( $key, $this->key( $method, $this->package() ) );
			$this->currency = 'RUB';

			$other              = $this->method();
			$other->instance_id = 2;
			$this->assertNotSame( $key, $this->key( $other, $this->package() ) );

			$other     = $this->method();
			$other->id = 'another-method';
			$this->assertNotSame( $key, $this->key( $other, $this->package() ) );
		}

		/** @return void */
		public function test_key_parts_filter_extends_the_key_and_a_non_array_return_is_ignored(): void {
			$method = $this->method();
			$key    = $this->key( $method, $this->package() );

			$this->filters['woodev_shipping_rate_cache_key_parts'] = static function ( array $parts ) {
				$parts['pickup_point'] = 'PVZ-1';

				return $parts;
			};
			$with_point = $this->key( $method, $this->package() );
			$this->assertNotSame( $key, $with_point );

			$this->filters['woodev_shipping_rate_cache_key_parts'] = static fn() => 'garbage';
			$this->assertSame( $key, $this->key( $method, $this->package() ) );
		}

		/** @return void */
		public function test_changed_method_settings_invalidate_the_cache(): void {
			$method                    = $this->method();
			$method->instance_settings = [ 'markup' => '10', 'title' => 'Courier' ];

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 1, $method->carrier_calls );

			$method->instance_settings = [ 'markup' => '20', 'title' => 'Courier' ];
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 2, $method->carrier_calls, 'new settings, new key' );

			// Key order inside the settings does not matter.
			$method->instance_settings = [ 'title' => 'Courier', 'markup' => '20' ];
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 2, $method->carrier_calls );
		}

		/** @return void */
		public function test_the_enabled_filter_switches_the_cache_off(): void {
			$this->filters['woodev_shipping_rate_cache_enabled'] = static fn() => false;

			$method = $this->method();
			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 2, $method->carrier_calls );
			$this->assertSame( [], $this->store );
		}

		/** @return void */
		public function test_the_ttl_defaults_to_ten_minutes_is_filterable_and_zero_disables(): void {
			$method = $this->method();
			$method->calculate_shipping( $this->package() );
			$this->assertSame( [ 600 ], $this->ttls );

			$this->store = [];
			$this->ttls  = [];
			$this->filters['woodev_shipping_rate_cache_ttl'] = static fn() => 120;
			$method->calculate_shipping( $this->package() );
			$this->assertSame( [ 120 ], $this->ttls );

			$this->store = [];
			$this->ttls  = [];
			$this->filters['woodev_shipping_rate_cache_ttl'] = static fn() => 0;
			$method->calculate_shipping( $this->package() );
			$this->assertSame( [], $this->store );

			$this->filters['woodev_shipping_rate_cache_ttl'] = static fn() => 'soon';
			$this->assertSame( 600, ( new Shipping_Rate_Cache() )->get_ttl( $method, $this->package() ) );
		}

		/** @return void */
		public function test_a_malformed_stored_entry_is_a_miss(): void {
			$cache  = new Shipping_Rate_Cache();
			$method = $this->method();
			$key    = $this->key( $method, $this->package() );

			foreach ( [ 'string', [ 'v' => 99, 'rate' => [] ], [ 'v' => 1, 'rate' => [ 'id' => '' ] ], [ 'v' => 1, 'rate' => [ 'method_id' => '', 'id' => '', 'label' => 'x', 'cost' => 1 ] ] ] as $bad ) {
				$this->store[ $key ] = $bad;
				$this->assertNull( $cache->get( $method, $this->package() ) );
			}
		}

		/** @return array{0: array, 1: Mockery\MockInterface} a one-line package around a product mock. */
		private function package_with_product( bool $virtual = false ): array {
			$product = Mockery::mock( 'WC_Product' );
			$product->shouldReceive( 'get_length' )->andReturn( '10' );
			$product->shouldReceive( 'get_width' )->andReturn( '10' );
			$product->shouldReceive( 'get_height' )->andReturn( '10' );
			$product->shouldReceive( 'get_weight' )->andReturn( '1' );
			$product->shouldReceive( 'get_shipping_class_id' )->andReturn( 0 );
			$product->shouldReceive( 'is_virtual' )->andReturn( $virtual );

			return [ $this->package( [ 'contents' => [ 'a' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 1, 'data' => $product ] ] ] ), $product ];
		}

		/**
		 * A product with no dimensions of its own, in a cart line, as the cache reads it.
		 *
		 * @param array<string,string> $own the product's own values by `l`, `w`, `h`, `kg`.
		 * @return array the package.
		 */
		private function package_with_dimensions( array $own ): array {
			$product = Mockery::mock( 'WC_Product' );
			$product->shouldReceive( 'get_length' )->andReturn( $own['l'] ?? '' );
			$product->shouldReceive( 'get_width' )->andReturn( $own['w'] ?? '' );
			$product->shouldReceive( 'get_height' )->andReturn( $own['h'] ?? '' );
			$product->shouldReceive( 'get_weight' )->andReturn( $own['kg'] ?? '' );
			$product->shouldReceive( 'get_shipping_class_id' )->andReturn( 0 );
			$product->shouldReceive( 'is_virtual' )->andReturn( false );

			return $this->package( [ 'contents' => [ 'a' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 1, 'data' => $product ] ] ] );
		}

		/**
		 * #955: the key follows what is PACKED. A changed store default is a new key for a product that
		 * relies on it, and a product that spells the default out shares the key of one that relies on it.
		 *
		 * @return void
		 */
		public function test_key_reflects_the_effective_dimensions_with_the_store_defaults(): void {
			$method = $this->method();

			$bare = $this->package_with_dimensions( [] );
			$none = $this->key( $method, $bare );

			$this->options['woodev_default_dimensions_default_length'] = '30';
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			$with_default = $this->key( $method, $bare );

			$this->assertNotNull( $none );
			$this->assertNotSame( $none, $with_default, 'a default length is a new key for a product without one' );

			$this->assertSame(
				$with_default,
				$this->key( $method, $this->package_with_dimensions( [ 'l' => '30' ] ) ),
				'a product with the default spelled out packs the same'
			);
			$this->assertNotSame(
				$with_default,
				$this->key( $method, $this->package_with_dimensions( [ 'l' => '31' ] ) ),
				'a product with its own length does not follow the default'
			);

			$own_before = $this->key( $method, $this->package_with_dimensions( [ 'l' => '31' ] ) );

			$this->options['woodev_default_dimensions_default_length'] = '40';
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			$this->assertNotSame( $with_default, $this->key( $method, $bare ), 'a changed default changes the key' );
			$this->assertSame(
				$own_before,
				$this->key( $method, $this->package_with_dimensions( [ 'l' => '31' ] ) ),
				'a product with its own length is unaffected by the default'
			);
		}

		/** @return void */
		public function test_a_method_that_did_not_opt_in_never_touches_a_transient(): void {
			$method           = $this->method();
			$method->supports = [];

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertFalse( $method->supports_rate_cache() );
			$this->assertSame( 2, $method->carrier_calls );
			$this->assertSame( 0, $this->transient_calls, 'no get_transient / set_transient at all' );
			$this->assertSame( [], $this->store );

			// The filter can veto the cache, never force it on.
			$this->filters['woodev_shipping_rate_cache_enabled'] = static fn() => true;
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 0, $this->transient_calls );
		}

		/** @return void */
		public function test_opting_in_with_add_support_turns_the_cache_on(): void {
			$method           = $this->method();
			$method->supports = [];
			$method->supports[] = Shipping_Method::FEATURE_RATE_CACHE;

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertTrue( $method->supports_rate_cache() );
			$this->assertSame( 1, $method->carrier_calls );
		}

		/** @return void */
		public function test_a_changed_pickup_point_is_a_different_key_and_the_old_point_still_hits(): void {
			$point   = [ 'locality' => 'moscow', 'type' => 'pvz', 'point_id' => 'A' ];
			$handler = Mockery::mock( \Woodev\Framework\Shipping\Pickup\Pickup_Handler::class );
			$handler->shouldReceive( 'get_selected_point_for_method' )->with( 'rate-cache-method' )->andReturnUsing(
				static function () use ( &$point ) {
					return $point;
				}
			);

			$method                 = $this->method();
			$method->pickup_handler = $handler;

			$method->next_rate = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'PVZ', 100 );
			$method->calculate_shipping( $this->package() );

			$point             = [ 'locality' => 'moscow', 'type' => 'pvz', 'point_id' => 'B' ];
			$method->next_rate = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'PVZ', 250 );
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 2, $method->carrier_calls, 'point B is a new key — the carrier is asked again' );

			$point = [ 'locality' => 'moscow', 'type' => 'pvz', 'point_id' => 'A' ];
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 2, $method->carrier_calls, 'point A is served from its own entry' );

			// Same point id in another locality / of another type is another point.
			$a = $this->key( $method, $this->package() );
			$point = [ 'locality' => 'tver', 'type' => 'pvz', 'point_id' => 'A' ];
			$this->assertNotSame( $a, $this->key( $method, $this->package() ) );
			$point = [ 'locality' => 'moscow', 'type' => 'postamat', 'point_id' => 'A' ];
			$this->assertNotSame( $a, $this->key( $method, $this->package() ) );
		}

		/** @return void */
		public function test_a_method_without_a_pickup_type_gets_a_null_pickup_part(): void {
			$handler = Mockery::mock( \Woodev\Framework\Shipping\Pickup\Pickup_Handler::class );
			$handler->shouldReceive( 'get_selected_point_for_method' )->andReturn( null );

			$method                 = $this->method();
			$method->pickup_handler = $handler;

			$this->assertNull( $method->get_rate_cache_context( $this->package() )['pickup'] );
			$this->assertNull( $this->method()->get_rate_cache_context( $this->package() )['pickup'], 'no handler at all' );
		}

		/** @return void */
		public function test_the_chosen_payment_method_is_part_of_the_key(): void {
			$method          = $this->method();
			$method->payment = 'bacs';
			$key             = $this->key( $method, $this->package() );

			$method->payment = 'cod';
			$cod             = $this->key( $method, $this->package() );
			$this->assertNotSame( $key, $cod );

			$method->calculate_shipping( $this->package() );
			$method->payment = 'bacs';
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 2, $method->carrier_calls, 'COD → prepaid is a miss' );
		}

		/** @return array<string, array{0: array}> */
		public function street_changes(): array {
			return [
				'address'   => [ [ 'address' => 'Tverskaya 1' ] ],
				'address_1' => [ [ 'address_1' => 'Tverskaya 1' ] ],
				'address_2' => [ [ 'address_2' => 'flat 5' ] ],
			];
		}

		/**
		 * @dataProvider street_changes
		 * @param array $street street fields.
		 * @return void
		 */
		public function test_the_street_address_is_part_of_the_key( array $street ): void {
			$method = $this->method();
			$base   = $this->package()['destination'];

			$this->assertNotSame(
				$this->key( $method, $this->package() ),
				$this->key( $method, $this->package( [ 'destination' => array_merge( $base, $street ) ] ) )
			);
		}

		/** @return void */
		public function test_a_global_setting_added_through_the_context_seam_changes_the_key(): void {
			$method = new Woodev_Test_Shipping_Method_With_Origin();
			$method->next_rate = $this->rate();

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 1, $method->carrier_calls );

			$method->origin = 'SPE';
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 2, $method->carrier_calls, 'a changed origin is a new key' );
			$this->assertSame( 'SPE', $method->get_rate_cache_context( $this->package() )['origin'] );
		}

		/** @return void */
		public function test_the_virtual_flag_and_the_store_units_are_part_of_the_key(): void {
			$method = $this->method();

			[ $physical ] = $this->package_with_product( false );
			[ $virtual ]  = $this->package_with_product( true );
			$this->assertNotSame( $this->key( $method, $physical ), $this->key( $method, $virtual ), 'virtual flag' );

			$this->options = [ 'woocommerce_dimension_unit' => 'cm', 'woocommerce_weight_unit' => 'kg' ];
			$base          = $this->key( $method, $physical );

			$this->options['woocommerce_dimension_unit'] = 'in';
			$this->assertNotSame( $base, $this->key( $method, $physical ), 'dimension unit' );

			$this->options['woocommerce_dimension_unit'] = 'cm';
			$this->options['woocommerce_weight_unit']    = 'lbs';
			$this->assertNotSame( $base, $this->key( $method, $physical ), 'weight unit' );
		}

		/** @return void */
		public function test_the_effective_packing_mode_is_part_of_the_key(): void {
			$method = $this->method();
			$base   = $this->key( $method, $this->package() );

			$method->supports[] = Shipping_Method::FEATURE_BOX_PACKING;
			$packing            = $this->key( $method, $this->package() );
			$this->assertNotSame( $base, $packing, 'box packing declared' );

			$method->option_values['packing_algorithm'] = \Woodev_Packer_Dispatcher::ALGORITHM_SINGLE;
			$this->assertNotSame( $packing, $this->key( $method, $this->package() ), 'algorithm read through get_option()' );
		}

		/**
		 * #1138: the store's boxes are what the `boxes` algorithm packs into — editing the list is a new quote,
		 * but only for a method that packs into it: the other algorithms ignore the list.
		 *
		 * @return void
		 */
		public function test_the_store_boxes_are_part_of_the_key_only_for_the_boxes_algorithm(): void {
			$method             = $this->method();
			$method->supports[] = Shipping_Method::FEATURE_BOX_PACKING;

			$method->option_values['packing_algorithm'] = \Woodev_Packer_Dispatcher::ALGORITHM_BOXES;
			$this->options['woodev_boxes_boxes']         = 'Small; 20; 15; 10';
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			$one_box = $this->key( $method, $this->package() );

			$this->options['woodev_boxes_boxes'] = "Small; 20; 15; 10\nBig; 40; 30; 20";
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			$this->assertNotSame( $one_box, $this->key( $method, $this->package() ), 'a box added to the list' );

			$method->option_values['packing_algorithm'] = \Woodev_Packer_Dispatcher::ALGORITHM_SEPARATELY;
			$separately                                 = $this->key( $method, $this->package() );
			$this->options['woodev_boxes_boxes']         = 'Other; 5; 5; 5';
			\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
			$this->assertSame( $separately, $this->key( $method, $this->package() ), 'the list is not read by another algorithm' );
		}

		/** @return void */
		public function test_a_context_that_is_not_plain_finite_data_disables_caching(): void {
			$method = new Woodev_Test_Shipping_Method_With_Origin();
			$method->next_rate = $this->rate();
			$cache  = new Shipping_Rate_Cache();

			foreach ( [ 'INF' => INF, 'NAN' => NAN, 'object' => new \stdClass(), 'closure' => static fn() => 1, 'invalid UTF-8' => "\xB1\x31", 'nested INF' => [ 'a' => [ INF ] ] ] as $label => $bad ) {
				$method->origin = $bad;

				$this->assertNull( $cache->build_key( $method, $this->package() ), $label );
				$this->assertFalse( $cache->put( $method, $this->package(), $this->rate() ), $label );
			}

			$method->origin = INF;
			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 2, $method->carrier_calls, 'never a shared hash: both calls reach the carrier' );
			$this->assertSame( [], $this->store );
		}

		/** @return void */
		public function test_a_self_referencing_context_disables_caching_instead_of_recursing_forever(): void {
			$method            = new Woodev_Test_Shipping_Method_With_Origin();
			$method->next_rate = $this->rate();

			$loop         = [];
			$loop['self'] = &$loop;
			$method->origin = $loop;

			$this->assertNull( ( new Shipping_Rate_Cache() )->build_key( $method, $this->package() ) );

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 2, $method->carrier_calls, 'both calls reach the carrier and return rates' );
			$this->assertSame( [], $this->store );
		}

		/** @return void */
		public function test_a_context_nested_past_the_depth_limit_disables_caching_and_a_normal_one_still_caches(): void {
			$method            = new Woodev_Test_Shipping_Method_With_Origin();
			$method->next_rate = $this->rate();
			$cache             = new Shipping_Rate_Cache();

			$nest = static function ( int $levels ) {
				$value = 'leaf';

				for ( $i = 0; $i < $levels; $i++ ) {
					$value = [ 'n' => $value ];
				}

				return $value;
			};

			$method->origin = $nest( 8 );
			$this->assertNotNull( $cache->build_key( $method, $this->package() ), 'realistic nesting is hashed' );

			$method->origin = $nest( 5000 );
			$this->assertNull( $cache->build_key( $method, $this->package() ), 'absurd nesting is declined' );

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 2, $method->carrier_calls );
			$this->assertSame( [], $this->store );

			$method->origin = $nest( 8 );
			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );
			$this->assertSame( 3, $method->carrier_calls, 'a normal nested context is cached after the first call' );
		}

		/** @return void */
		public function test_a_failed_encode_disables_caching_instead_of_hashing_an_empty_string(): void {
			Functions\when( 'wp_json_encode' )->justReturn( false );

			$method = $this->method();

			$this->assertNull( ( new Shipping_Rate_Cache() )->build_key( $method, $this->package() ) );

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 2, $method->carrier_calls );
			$this->assertSame( [], $this->store );
		}

		/** @return void */
		public function test_an_unplain_key_part_from_the_filter_disables_caching(): void {
			$this->filters['woodev_shipping_rate_cache_key_parts'] = static function ( array $parts ) {
				$parts['handle'] = fopen( 'php://memory', 'r' );

				return $parts;
			};

			$this->assertNull( ( new Shipping_Rate_Cache() )->build_key( $this->method(), $this->package() ) );
		}

		/** @return void */
		public function test_a_context_that_throws_disables_caching(): void {
			$method = new class() extends Woodev_Test_Shipping_Method_For_Rate_Cache {
				/**
				 * @param array $package package.
				 * @return array
				 */
				public function get_rate_cache_context( array $package ): array {
					throw new \RuntimeException( 'no session' );
				}
			};
			$method->next_rate = $this->rate();

			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 2, $method->carrier_calls );
			$this->assertSame( 0, count( $this->store ) );
		}

		/** @return void */
		public function test_a_hit_hands_back_the_current_package_not_the_stored_one(): void {
			$first  = $this->package();
			$second = $this->package( [ 'contents' => array_reverse( $first['contents'], true ), 'rates' => [ 'extra' => 'changed' ] ] );

			$method            = $this->method();
			$method->next_rate = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 350, $first );

			$cache = new Shipping_Rate_Cache();
			$this->assertTrue( $cache->put( $method, $first, $method->next_rate ) );

			$hit = $cache->get( $method, $second );

			$this->assertNotNull( $hit, 'the extra package field is not part of the key' );
			$this->assertSame( $second, $hit->get_package(), 'rebuilt with the CURRENT package' );
			$this->assertSame( [], array_filter( $this->store, static fn( $entry ) => isset( $entry['rate']['package'] ) && is_array( $entry['rate']['package'] ) ), 'the package itself is never stored' );
		}

		/** @return void */
		public function test_a_rate_carrying_some_other_package_is_not_cached(): void {
			$method = $this->method();
			$other  = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 350, [ 'contents' => [ 'x' => [ 'product_id' => 99 ] ] ] );

			$this->assertFalse( ( new Shipping_Rate_Cache() )->put( $method, $this->package(), $other ) );
			$this->assertSame( [], $this->store );
		}

		/** @return void */
		public function test_a_rate_without_a_package_stays_without_one_on_a_hit(): void {
			$cache  = new Shipping_Rate_Cache();
			$method = $this->method();

			$cache->put( $method, $this->package(), $this->rate() );

			$this->assertNull( $cache->get( $method, $this->package() )->get_package() );
		}

		/** @return void */
		public function test_the_calculated_rate_filter_runs_on_a_real_hit_with_the_restored_rate(): void {
			$seen = [];

			$this->filters['woodev_shipping_method_calculated_rate'] = static function ( $rate ) use ( &$seen ) {
				$seen[] = $rate->get_label() . ':' . $rate->get_cost();

				return new Shipping_Rate( $rate->get_method_id(), $rate->get_id(), $rate->get_label() . ' (filtered)', $rate->get_cost() + 10, $rate->get_package(), $rate->get_meta_data(), $rate->get_args() );
			};

			$method = $this->method();
			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );
			$method->calculate_shipping( $this->package() );

			$this->assertSame( 1, $method->carrier_calls );
			$this->assertSame( [ 'Courier:350', 'Courier:350', 'Courier:350' ], $seen, 'the filter sees the RAW rate every time: a filtered rate never poisons the cache' );
			$this->assertSame( 3, $method->added );
		}
	}
}

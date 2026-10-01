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

		public function __construct() {}

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
	}

	/** Minimal Shipping_Method double that counts carrier calls. */
	class Woodev_Test_Shipping_Method_For_Rate_Cache extends Shipping_Method {

		/** @var string */
		public $id = 'rate-cache-method';

		/** @var int */
		public $instance_id = 1;

		/** @var array */
		public $instance_settings = [];

		/** @var array */
		public array $supports = [];

		/** @var array */
		public $instance_form_fields = [];

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

		/** @return Shipping_Plugin */
		protected function get_plugin(): Shipping_Plugin {
			return new Woodev_Test_Shipping_Plugin_For_Rate_Cache();
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

		/** @return void */
		protected function setUp(): void {
			parent::setUp();

			$this->store    = [];
			$this->ttls     = [];
			$this->filters  = [];
			$this->currency = 'RUB';

			Functions\when( 'get_transient' )->alias(
				function ( $key ) {
					return $this->store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				function ( $key, $value, $ttl = 0 ) {
					$this->store[ $key ] = $value;
					$this->ttls[]        = $ttl;

					return true;
				}
			);
			Functions\when( 'get_woocommerce_currency' )->alias( fn() => $this->currency );
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wp_unslash' )->returnArg( 1 );
			Functions\when( 'apply_filters' )->alias(
				function ( $tag, $value = null, ...$args ) {
					return isset( $this->filters[ $tag ] ) ? ( $this->filters[ $tag ] )( $value, ...$args ) : $value;
				}
			);
			Functions\when( 'do_action' )->justReturn( null );
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

				return $this->package( [ 'contents' => [ 'a' => [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 1, 'data' => $product ] ] ] );
			};

			$base = [ 'l' => '10', 'w' => '10', 'h' => '10', 'kg' => '1', 'c' => 0 ];
			$key  = $this->key( $method, $make( $base ) );

			$this->assertSame( $key, $this->key( $method, $make( $base ) ) );

			foreach ( [ 'l' => '11', 'w' => '11', 'h' => '11', 'kg' => '2', 'c' => 3 ] as $field => $value ) {
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
	}
}

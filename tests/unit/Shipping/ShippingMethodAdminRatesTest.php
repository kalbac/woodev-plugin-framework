<?php
/**
 * {@see Shipping_Method::get_admin_rates_for_package()} — the seam the admin order wizard's rate
 * calculator prices through (#965, spec D2 «Mine 1» and «Mine 3»).
 *
 * An admin REST request defines `REST_REQUEST`, so `should_send_cart_api_request()` vetoes every
 * carrier call and `calculate_shipping()` (final) cannot be reached around it. The seam lifts that
 * veto for one call on one instance — and must not leave it lifted.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base — see ShippingMethodFilterReturnGuardsTest for
		 * why every property `Shipping_Method` reads off `$this` is declared here AND on the double.
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
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Resolution_Cache;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Framework\Shipping\Shipping_Rate;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-service.php';

	/**
	 * Method double: WooCommerce's `get_rates_for_package()` is re-implemented in the shape it has
	 * (reset rates, call `calculate_shipping()`, hand the rates back), because the bare
	 * `WC_Shipping_Method` stub of this suite has none — which lets the real, final
	 * `calculate_shipping()` (veto and all) run under the seam.
	 */
	class Woodev_Test_Shipping_Method_For_Admin_Rates extends Shipping_Method {

		/** @var string */
		public $id = 'admin-rates-method';

		/** @var array */
		public array $supports = [];

		/** @var array */
		public $instance_form_fields = [];

		/** @var array<string, \stdClass> the rates `add_rate()` collected. */
		public array $rates = [];

		/** @var Shipping_Plugin */
		private Shipping_Plugin $test_plugin;

		/** @var Shipping_Rate|null what `rate_package()` returns. */
		public ?Shipping_Rate $rate_package_return = null;

		/** @var array<int, mixed> what the location service answered while `rate_package()` ran. */
		public array $seen_customer_record = [];

		/**
		 * @param Shipping_Plugin $plugin owning plugin double.
		 */
		public function __construct( Shipping_Plugin $plugin ) {
			$this->test_plugin = $plugin;
		}

		/**
		 * @param array $args rate args.
		 * @return void
		 */
		public function add_rate( $args = [] ) {
			$this->rates[ $args['id'] ] = (object) $args;
		}

		/** @return string */
		public function get_title() {
			return 'Admin Rates Method';
		}

		/**
		 * @param array $package package.
		 * @return array
		 */
		public function get_rates_for_package( $package ) {
			$this->rates = [];
			$this->calculate_shipping( $package );

			return $this->rates;
		}

		/** @return string */
		public static function get_method_id(): string {
			return 'admin-rates-method';
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
			return $this->test_plugin;
		}

		/**
		 * @param array                      $package unused.
		 * @param \Woodev_Packer_Result|null $packed  unused.
		 * @return Shipping_Rate|null
		 */
		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
			$this->seen_customer_record[] = $this->test_plugin->get_location_service()->get_customer_record();

			return $this->rate_package_return;
		}

		/**
		 * Test-only read of the private veto.
		 *
		 * @return bool
		 */
		public function expose_should_send_cart_api_request(): bool {
			// A closure bound to the declaring class reaches the private method on every PHP version.
			$read = \Closure::bind(
				function (): bool {
					return $this->should_send_cart_api_request();
				},
				$this,
				Shipping_Method::class
			);

			return $read();
		}
	}

	/**
	 * @coversDefaultClass \Woodev\Framework\Shipping\Shipping_Method
	 */
	final class ShippingMethodAdminRatesTest extends TestCase {

		/** @var Customer_Location_Store&\Mockery\MockInterface */
		private $store;

		/** @var Shipping_Plugin&\Mockery\MockInterface */
		private $plugin;

		/** @return void */
		protected function setUp(): void {
			parent::setUp();

			Location_Provider_Registry::instance()->reset_for_tests();

			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( null );
			// calculate_shipping() fronts the carrier call with the rate cache (#958): a miss that stores nothing.
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
			Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
			// the Store API detection (#949) reads the request URI through it
			Functions\when( 'wp_unslash' )->returnArg( 1 );

			// The Store API detection fallback (#949) reads the route through these; the test
			// process defines no `WC()`, so the veto probe reaches it.
			Functions\when( 'wp_unslash' )->returnArg( 1 );
			Functions\when( 'trailingslashit' )->alias(
				static function ( $value ) {
					return rtrim( (string) $value, '/\\' ) . '/';
				}
			);
			Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
			Functions\when( 'wp_parse_url' )->alias(
				static function ( $url, $component = -1 ) {
					return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				}
			);

			$this->store = Mockery::mock( Customer_Location_Store::class );

			$service = new Location_Service(
				Location_Provider_Registry::instance(),
				$this->store,
				Mockery::mock( Location_Resolution_Cache::class )
			);

			$this->plugin = Mockery::mock( Shipping_Plugin::class );
			$this->plugin->shouldReceive( 'get_location_service' )->andReturn( $service );
			$this->plugin->shouldReceive( 'get_accepted_countries' )->andReturn( [] );
			$this->plugin->shouldReceive( 'log' )->andReturnNull();
		}

		/** @return void */
		protected function tearDown(): void {
			Location_Provider_Registry::instance()->reset_for_tests();
			parent::tearDown();
		}

		/**
		 * @return Woodev_Test_Shipping_Method_For_Admin_Rates
		 */
		private function method(): Woodev_Test_Shipping_Method_For_Admin_Rates {
			$method                      = new Woodev_Test_Shipping_Method_For_Admin_Rates( $this->plugin );
			$method->rate_package_return = new Shipping_Rate( 'admin-rates-method', 'admin-rates-method:3', 'Курьер', '250' );

			return $method;
		}

		/**
		 * @return Location_Record
		 */
		private function record(): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => 'test:region:moscow',
					'provider_id' => 'test',
					'level'       => Location_Record::LEVEL_REGION,
					'country'     => 'RU',
					'label'       => 'Москва',
				]
			);
		}

		/**
		 * The whole point (#962 §4): under `REST_REQUEST` the seam prices, the plain path does not —
		 * and the veto is back the moment the call returns.
		 *
		 * @covers ::get_admin_rates_for_package
		 * @covers ::should_send_cart_api_request
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @return void
		 */
		public function test_the_seam_prices_inside_a_rest_request_and_only_for_its_own_call(): void {
			define( 'REST_REQUEST', true );

			$method = $this->method();

			$this->assertFalse( $method->expose_should_send_cart_api_request(), 'precondition: a REST request is vetoed' );

			$plain = $method->get_rates_for_package( [] );
			$this->assertSame( [], $plain, 'precondition: the plain path gets no rate in REST' );

			$rates = $method->get_admin_rates_for_package( [], $this->record() );

			$this->assertSame( [ 'admin-rates-method:3' ], array_keys( $rates ) );
			$this->assertFalse( $method->expose_should_send_cart_api_request(), 'the veto is back after the call' );
			$this->assertSame( [], $method->get_rates_for_package( [] ), 'and the plain path is vetoed again' );
		}

		/**
		 * The XML-RPC arm and the admin arm of the veto are lifted by the same flag.
		 *
		 * @covers ::get_admin_rates_for_package
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @return void
		 */
		public function test_the_seam_lifts_the_admin_after_cart_loaded_arm_too(): void {
			Functions\when( 'is_admin' )->justReturn( true );
			Functions\when( 'did_action' )->justReturn( 1 );

			$method = $this->method();

			$this->assertSame( [], $method->get_rates_for_package( [] ), 'precondition: admin after the cart loaded is vetoed' );
			$this->assertCount( 1, $method->get_admin_rates_for_package( [] ) );
		}

		/**
		 * @covers ::get_admin_rates_for_package
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 *
		 * @return void
		 */
		public function test_the_veto_is_restored_when_the_carrier_throws(): void {
			define( 'REST_REQUEST', true );

			$method = new class( $this->plugin ) extends Woodev_Test_Shipping_Method_For_Admin_Rates {

				/**
				 * @param array $package package.
				 * @return array
				 */
				public function get_rates_for_package( $package ) {
					throw new \RuntimeException( 'not a carrier exception' );
				}
			};

			try {
				$method->get_admin_rates_for_package( [] );
				$this->fail( 'a non-carrier throwable must propagate, not be swallowed' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'not a carrier exception', $exception->getMessage() );
			}

			$this->assertFalse( $method->expose_should_send_cart_api_request() );
		}

		/**
		 * Mine 3: the carrier's own read of "the customer's location" answers the destination —
		 * and the admin's store is neither read nor written.
		 *
		 * @covers ::get_admin_rates_for_package
		 *
		 * @return void
		 */
		public function test_carrier_rate_code_reads_the_destination_record_not_the_admin_store(): void {
			$this->store->shouldNotReceive( 'get_chain' );
			$this->store->shouldNotReceive( 'set' );

			$record = $this->record();
			$method = $this->method();

			$method->get_admin_rates_for_package( [], $record );

			$this->assertCount( 1, $method->seen_customer_record );
			$this->assertSame( $record, $method->seen_customer_record[0]['record'] );
		}

		/**
		 * @covers ::get_admin_rates_for_package
		 *
		 * @return void
		 */
		public function test_no_destination_record_reads_as_none_not_as_the_admin_store(): void {
			$this->store->shouldNotReceive( 'get_chain' );
			$this->store->shouldNotReceive( 'set' );

			$method = $this->method();

			$method->get_admin_rates_for_package( [] );

			$this->assertSame( [ null ], $method->seen_customer_record );
		}

		/**
		 * Rate meta must survive to the caller — `WC_Shipping_Rate` objects, not a flattened copy —
		 * so the create route can copy it onto the shipping item as `WC_Checkout` does.
		 *
		 * @covers ::get_admin_rates_for_package
		 *
		 * @return void
		 */
		public function test_the_rates_come_back_as_the_method_added_them_with_their_meta(): void {
			$method                      = $this->method();
			$method->rate_package_return = new Shipping_Rate(
				'admin-rates-method',
				'admin-rates-method:3',
				'Курьер',
				'250',
				[],
				[ 'tariff_code' => 137 ]
			);

			$rates = $method->get_admin_rates_for_package( [] );

			$this->assertSame( [ 'admin-rates-method:3' ], array_keys( $rates ) );
			$this->assertSame( [ 'tariff_code' => 137 ], (array) $rates['admin-rates-method:3']->meta_data );
		}
	}
}

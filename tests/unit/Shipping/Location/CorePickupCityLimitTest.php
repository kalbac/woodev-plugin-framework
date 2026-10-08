<?php
/**
 * Core_Pickup_City_Limit — the city limit on WooCommerce's own «Самовывоз» (#1176): hooked once per request,
 * store-wide, with no opt-in; the form fields appear only while the location layer is wanted; the availability
 * filter applies the limit to a method the framework does not own.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/** The slice of WooCommerce's method base the integration reads. */
		class WC_Shipping_Method {

			/** @var string */
			public $id;
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\City_Limit;
	use Woodev\Framework\Shipping\Location\City_Limit_Form;
	use Woodev\Framework\Shipping\Location\Core_Pickup_City_Limit;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Tests\Unit\TestCase;

	require_once __DIR__ . '/CityLimitTest.php';
	require_once dirname( __DIR__, 3 ) . '/../woodev/shipping-method/class-instance-field-conditions.php';
	require_once dirname( __DIR__, 3 ) . '/../woodev/shipping-method/location/class-city-limit-form.php';
	require_once dirname( __DIR__, 3 ) . '/../woodev/shipping-method/location/class-core-pickup-city-limit.php';

	/** A `local_pickup` instance: just its stored options. */
	final class Core_Pickup_Test_Method extends \WC_Shipping_Method {

		/** @var string whichever WooCommerce stub loaded first may or may not declare it */
		public $id = 'local_pickup';

		/** @var array<string, mixed> */
		public array $options = [];

		public function __construct( array $options = [] ) {
			$this->id      = 'local_pickup';
			$this->options = $options;
		}

		public function get_option( $key, $empty_value = null ) {
			return $this->options[ $key ] ?? $empty_value;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Core_Pickup_City_Limit
	 */
	final class CorePickupCityLimitTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Core_Pickup_City_Limit::reset_for_tests();
			Location_Provider_Registry::instance()->reset_for_tests();

			Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
		}

		protected function tearDown(): void {
			Core_Pickup_City_Limit::reset_for_tests();
			Location_Provider_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		private function location_layer_wanted( bool $wanted ): void {
			$property = new \ReflectionProperty( Location_Provider_Registry::class, 'needed' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( Location_Provider_Registry::instance(), $wanted );
		}

		private function city( string $key, string $label ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => 'settlement',
					'country'     => 'RU',
					'settlement'  => [ 'name' => $label, 'type' => '' ],
					'label'       => $label,
				]
			);
		}

		private function with_service( City_Limit_Test_Service $service ): void {
			$property = new \ReflectionProperty( Core_Pickup_City_Limit::class, 'service' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( Core_Pickup_City_Limit::instance(), $service );
		}

		// ---- the hooks --------------------------------------------------------------------------------------

		public function test_the_three_hooks_are_added_once_however_many_plugins_ask(): void {
			Filters\expectAdded( 'woocommerce_shipping_instance_form_fields_local_pickup' )->once();
			Filters\expectAdded( 'woocommerce_shipping_local_pickup_is_available' )->once();
			Filters\expectAdded( 'woocommerce_generate_woodev_city_limit_html' )->once();

			Core_Pickup_City_Limit::instance()->register();
			Core_Pickup_City_Limit::instance()->register();
			Core_Pickup_City_Limit::instance()->register();

			$this->assertSame( Core_Pickup_City_Limit::instance(), Core_Pickup_City_Limit::instance(), 'one store-wide instance' );
		}

		// ---- the form fields --------------------------------------------------------------------------------

		public function test_the_fields_are_added_while_a_plugin_wants_the_location_layer(): void {
			$this->location_layer_wanted( true );

			$fields = Core_Pickup_City_Limit::instance()->add_fields( [ 'title' => [ 'type' => 'text' ], 'cost' => [ 'type' => 'text' ] ] );

			$this->assertSame( [ 'title', 'cost', 'city_limit_mode', 'city_limit_cities' ], array_keys( $fields ) );
			$this->assertSame( 'woodev_city_limit', $fields['city_limit_cities']['type'] );

			$condition = json_decode( $fields['city_limit_cities']['custom_attributes']['data-woodev-show-if'], true );
			$this->assertSame( 'woocommerce_local_pickup_city_limit_mode', $condition['conditions'][0]['field'], 'the show-if points at the control WooCommerce names for THIS method' );
			$this->assertSame( '!=', $condition['conditions'][0]['operator'] );
		}

		public function test_without_a_plugin_that_wants_the_location_layer_core_pickup_is_left_alone(): void {
			$this->location_layer_wanted( false );

			$before = [ 'title' => [ 'type' => 'text' ] ];

			$this->assertSame( $before, Core_Pickup_City_Limit::instance()->add_fields( $before ) );
		}

		public function test_a_non_array_set_of_fields_is_handed_back_untouched(): void {
			$this->location_layer_wanted( true );

			$this->assertSame( 'oops', Core_Pickup_City_Limit::instance()->add_fields( 'oops' ) );
		}

		// ---- availability -----------------------------------------------------------------------------------

		public function test_a_method_that_is_already_unavailable_stays_so_without_a_question_asked(): void {
			$service         = new City_Limit_Test_Service();
			$service->throws = true;
			$this->with_service( $service );

			$method = new Core_Pickup_Test_Method( [ 'city_limit_mode' => 'include' ] );

			$this->assertFalse( Core_Pickup_City_Limit::instance()->filter_is_available( false, [], $method ) );
			$this->assertNull( $service->asked_country );
		}

		public function test_something_that_is_not_a_woocommerce_method_is_ignored(): void {
			$this->assertTrue( Core_Pickup_City_Limit::instance()->filter_is_available( true, [], new \stdClass() ) );
			$this->assertTrue( Core_Pickup_City_Limit::instance()->filter_is_available( true ) );
		}

		public function test_no_limit_set_never_builds_the_location_service(): void {
			// no service injected: asking for one would reach the registry, the session and the customer
			$method = new Core_Pickup_Test_Method( [ 'city_limit_mode' => '' ] );

			$this->assertTrue( Core_Pickup_City_Limit::instance()->filter_is_available( true, [ 'destination' => [ 'country' => 'RU' ] ], $method ) );
		}

		public function test_only_these_cities_hides_core_pickup_from_a_customer_elsewhere(): void {
			$service           = new City_Limit_Test_Service();
			$service->customer = $this->city( 'test-cdek:44', 'Москва' );
			$this->with_service( $service );

			$method  = new Core_Pickup_Test_Method(
				[
					'city_limit_mode'   => 'include',
					'city_limit_cities' => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
				]
			);
			$package = [ 'destination' => [ 'country' => 'ru' ] ];

			$this->assertFalse( Core_Pickup_City_Limit::instance()->filter_is_available( true, $package, $method ) );
			$this->assertSame( 'RU', $service->asked_country );

			$service->customer = $this->city( 'test-cdek:394', 'Пушкин' );
			$this->assertTrue( Core_Pickup_City_Limit::instance()->filter_is_available( true, $package, $method ) );
		}

		public function test_not_in_these_cities_hides_core_pickup_from_a_listed_customer(): void {
			$service           = new City_Limit_Test_Service();
			$service->customer = $this->city( 'test-cdek:394', 'Пушкин' );
			$this->with_service( $service );

			$method = new Core_Pickup_Test_Method(
				[
					'city_limit_mode'   => 'exclude',
					'city_limit_cities' => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
				]
			);

			$this->assertFalse( Core_Pickup_City_Limit::instance()->filter_is_available( true, [], $method ) );
		}

		public function test_a_customer_who_has_not_chosen_a_city_keeps_core_pickup(): void {
			$service = new City_Limit_Test_Service();
			$this->with_service( $service );

			$method = new Core_Pickup_Test_Method(
				[
					'city_limit_mode'   => 'include',
					'city_limit_cities' => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
				]
			);

			$this->assertTrue( Core_Pickup_City_Limit::instance()->filter_is_available( true, [], $method ), 'D1' );
		}
	}
}

<?php
/**
 * `Shipping_Method::FEATURE_CITY_LIMIT` — the opt-in city limit of a Woodev method (#1176): the two instance fields
 * exist only for a method that declares the feature, and the framework keeps the method out of the customer's cart
 * when the stored limit excludes the customer's city. Opt-in, like fee payments and cost limits.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Location\City_Limit;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Framework\Shipping\Shipping_Rate;
use Woodev\Tests\Unit\Shipping\Location\City_Limit_Test_Service;
use Woodev\Tests\Unit\TestCase;

// the probe method and plugin double live there; whichever file loads first defines them
require_once __DIR__ . '/CostLimitsTest.php';
require_once __DIR__ . '/Location/CityLimitTest.php';

/** The plugin double, with a location layer a test can steer. */
class Woodev_Test_City_Limit_Plugin extends Woodev_Test_Shipping_Plugin_For_Rate_Cache {

	/** @var City_Limit_Test_Service */
	public static City_Limit_Test_Service $service;

	/** @return Location_Service */
	public function get_location_service(): Location_Service {
		return self::$service;
	}

	/**
	 * @param string      $message log line.
	 * @param string|null $log_id  log id.
	 * @return void
	 */
	public function log_debug( $message, $log_id = null ): void {}
}

/** The method probe, sitting in that plugin. */
class Woodev_Test_City_Limit_Method extends Woodev_Test_Cost_Limits_Method {

	/** @return Shipping_Plugin */
	protected function get_plugin(): Shipping_Plugin {
		return new Woodev_Test_City_Limit_Plugin();
	}
}

/**
 * @coversDefaultClass \Woodev\Framework\Shipping\Shipping_Method
 */
final class CityLimitMethodTest extends TestCase {

	/** @return void */
	protected function setUp(): void {
		parent::setUp();

		Woodev_Test_City_Limit_Plugin::$service = new City_Limit_Test_Service();
		City_Limit::use_zone_pairs_for_tests( null );

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
	}

	/** @return void */
	protected function tearDown(): void {
		City_Limit::use_zone_pairs_for_tests( null );
		City_Limit::use_continents_for_tests( null );

		parent::tearDown();
	}

	/**
	 * @param string $key   key.
	 * @param string $label label.
	 * @return Location_Record
	 */
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

	/**
	 * @param array<string, mixed> $options  saved instance options.
	 * @param bool                 $declared whether the method declared the feature.
	 * @return Woodev_Test_City_Limit_Method
	 */
	private function method( array $options, bool $declared = true ): Woodev_Test_City_Limit_Method {
		$method                = new Woodev_Test_City_Limit_Method();
		$method->supports      = $declared ? [ Shipping_Method::FEATURE_CITY_LIMIT ] : [];
		$method->option_values = $options;
		$method->next_rate     = new Shipping_Rate( 'rate-cache-method', 'rate-cache-method:1', 'Courier', 100 );

		return $method;
	}

	/**
	 * @param Woodev_Test_City_Limit_Method $method the probe.
	 * @return bool whether the method put a rate in the cart.
	 */
	private function rates( Woodev_Test_City_Limit_Method $method ): bool {
		$method->calculate_shipping( [ 'contents_cost' => 100, 'destination' => [ 'country' => 'RU' ] ] );

		return [] !== $method->added_rates;
	}

	// ---- the declaration --------------------------------------------------------------------------------

	/** @return void */
	public function test_the_feature_is_off_unless_declared(): void {
		$this->assertFalse( $this->method( [], false )->supports_city_limit() );
		$this->assertTrue( $this->method( [] )->supports_city_limit() );
	}

	/** @return void */
	public function test_a_declared_method_gets_the_mode_and_the_cities_in_its_instance_form(): void {
		$declared = $this->method( [] );
		$declared->init_form_fields();

		$this->assertArrayHasKey( City_Limit::OPTION_MODE, $declared->instance_form_fields );
		$this->assertArrayHasKey( City_Limit::OPTION_CITIES, $declared->instance_form_fields );
		$this->assertSame( 'select', $declared->instance_form_fields[ City_Limit::OPTION_MODE ]['type'] );
		$this->assertSame( City_Limit::FIELD_TYPE, $declared->instance_form_fields[ City_Limit::OPTION_CITIES ]['type'] );

		$plain = $this->method( [], false );
		$plain->init_form_fields();

		$this->assertArrayNotHasKey( City_Limit::OPTION_MODE, $plain->instance_form_fields );
		$this->assertArrayNotHasKey( City_Limit::OPTION_CITIES, $plain->instance_form_fields );
	}

	/** @return void */
	public function test_the_cities_control_hides_with_the_mode_through_the_forms_own_show_if(): void {
		$method = $this->method( [] );
		$method->init_form_fields();

		// the method's own get_instance_form_fields() runs exactly this over the parent's answer
		$fields    = \Woodev\Framework\Shipping\Instance_Field_Conditions::apply( $method->instance_form_fields, [ $method, 'get_field_key' ] );
		$condition = json_decode( $fields[ City_Limit::OPTION_CITIES ]['custom_attributes']['data-woodev-show-if'], true );

		$this->assertSame( 'woocommerce_cost-limits_city_limit_mode', $condition['conditions'][0]['field'] );
	}

	// ---- availability -----------------------------------------------------------------------------------

	/** @return void */
	public function test_a_method_that_did_not_declare_the_feature_ignores_a_stored_limit(): void {
		Woodev_Test_City_Limit_Plugin::$service->customer = $this->city( 'test-cdek:44', 'Москва' );

		$method = $this->method(
			[
				City_Limit::OPTION_MODE   => 'include',
				City_Limit::OPTION_CITIES => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
			],
			false
		);

		$this->assertTrue( $this->rates( $method ) );
	}

	/** @return void */
	public function test_only_these_cities_keeps_the_method_out_of_the_carts_of_other_cities(): void {
		$options = [
			City_Limit::OPTION_MODE   => 'include',
			City_Limit::OPTION_CITIES => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
		];

		Woodev_Test_City_Limit_Plugin::$service->customer = $this->city( 'test-cdek:44', 'Москва' );
		$this->assertFalse( $this->rates( $this->method( $options ) ), 'Moscow is not in the list' );

		Woodev_Test_City_Limit_Plugin::$service->customer = $this->city( 'test-cdek:394', 'Пушкин' );
		$this->assertTrue( $this->rates( $this->method( $options ) ), 'Pushkin is' );
	}

	/** @return void */
	public function test_not_in_these_cities_keeps_the_method_out_of_a_listed_city_only(): void {
		$options = [
			City_Limit::OPTION_MODE   => 'exclude',
			City_Limit::OPTION_CITIES => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
		];

		Woodev_Test_City_Limit_Plugin::$service->customer = $this->city( 'test-cdek:394', 'Пушкин' );
		$this->assertFalse( $this->rates( $this->method( $options ) ) );

		Woodev_Test_City_Limit_Plugin::$service->customer = $this->city( 'test-cdek:44', 'Москва' );
		$this->assertTrue( $this->rates( $this->method( $options ) ) );
	}

	/** @return void */
	public function test_a_customer_with_no_city_yet_and_a_method_with_no_limit_both_get_the_method(): void {
		$options = [
			City_Limit::OPTION_MODE   => 'include',
			City_Limit::OPTION_CITIES => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
		];

		Woodev_Test_City_Limit_Plugin::$service->customer = null;
		$this->assertTrue( $this->rates( $this->method( $options ) ), 'D1: no record, available' );

		Woodev_Test_City_Limit_Plugin::$service->customer = $this->city( 'test-cdek:44', 'Москва' );
		$this->assertTrue( $this->rates( $this->method( [ City_Limit::OPTION_MODE => '' ] ) ) );
	}

	/** @return void */
	public function test_a_zone_edited_after_the_list_was_made_no_longer_keeps_the_method_from_the_new_region(): void {
		$service             = Woodev_Test_City_Limit_Plugin::$service;
		$service->customer   = $this->city( 'test-cdek:600', 'Омск' );
		$service->state_code = 'САНКТ-ПЕТЕРБУРГ';

		$options = [
			City_Limit::OPTION_MODE   => 'include',
			City_Limit::OPTION_CITIES => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
		];

		City_Limit::use_zone_pairs_for_tests( static fn() => [ [ 'state', 'RU:САНКТ-ПЕТЕРБУРГ' ] ] );
		$this->assertFalse( $this->rates( $this->method( $options ) ) );

		City_Limit::use_zone_pairs_for_tests( static fn() => [ [ 'state', 'RU:ОМСКАЯ ОБЛАСТЬ' ] ] );
		$this->assertTrue( $this->rates( $this->method( $options ) ), 'checkout agrees with the form: the city outside the zone is ignored' );
	}

	/** @return void */
	public function test_a_zone_of_a_continent_and_a_country_still_limits_by_the_continents_cities(): void {
		City_Limit::use_continents_for_tests( [ 'EU' => [ 'BY', 'DE' ] ] );
		City_Limit::use_zone_pairs_for_tests( static fn() => [ [ 'continent', 'EU' ], [ 'country', 'KZ' ] ] );

		Woodev_Test_City_Limit_Plugin::$service->customer = Location_Record::from_array(
			[
				'key'         => 'test-cdek:700',
				'provider_id' => 'test-cdek',
				'level'       => 'settlement',
				'country'     => 'KZ',
				'label'       => 'Алматы',
			]
		);

		$minsk = Location_Record::from_array(
			[
				'key'         => 'test-cdek:9',
				'provider_id' => 'test-cdek',
				'level'       => 'settlement',
				'country'     => 'BY',
				'label'       => 'Минск',
			]
		);

		$this->assertFalse(
			$this->rates(
				$this->method(
					[
						City_Limit::OPTION_MODE   => 'include',
						City_Limit::OPTION_CITIES => City_Limit::encode( [ $minsk ] ),
					]
				)
			),
			'Minsk is in the zone through Europe: an Almaty buyer is not on the list. Dropping it as «outside» would empty the list and show the method.'
		);
	}

	/** @return void */
	public function test_the_stores_guessed_default_city_does_not_hide_the_method(): void {
		$service           = Woodev_Test_City_Limit_Plugin::$service;
		$service->customer = $this->city( 'test-cdek:44', 'Москва' );
		$service->implicit = true;

		$this->assertTrue(
			$this->rates(
				$this->method(
					[
						City_Limit::OPTION_MODE   => 'include',
						City_Limit::OPTION_CITIES => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
					]
				)
			)
		);
	}

	/** @return void */
	public function test_the_limit_does_not_stop_the_carrier_from_being_asked_when_the_method_is_available(): void {
		Woodev_Test_City_Limit_Plugin::$service->customer = $this->city( 'test-cdek:44', 'Москва' );

		$hidden = $this->method(
			[
				City_Limit::OPTION_MODE   => 'include',
				City_Limit::OPTION_CITIES => City_Limit::encode( [ $this->city( 'test-cdek:394', 'Пушкин' ) ] ),
			]
		);
		$this->rates( $hidden );

		$this->assertSame( 0, $hidden->carrier_calls, 'a method the limit hides never costs a carrier request' );
	}
}

<?php
/**
 * The city limit on WooCommerce's own «Самовывоз» (`local_pickup`), against REAL WooCommerce (#1176).
 *
 * The unit suite proves the decision and the hooks against stubs; what only the real thing can say is whether
 * `WC_Shipping_Method` keeps its promises to a method it does not own: that a filter on
 * `woocommerce_shipping_instance_form_fields_local_pickup` reaches the instance form, that a CUSTOM field type
 * is drawn through `woocommerce_generate_{type}_html`, that `process_admin_options()` runs the field's
 * `sanitize_callback`, and that `woocommerce_shipping_local_pickup_is_available` is what rating asks.
 *
 * ⛔ NOT RUN by its author (two integration runs share one test database — the coordinator runs the suite).
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\City_Limit;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Tests\Integration\TestCase;

/**
 * @since 2.0.2
 */
class CityLimitCorePickupTest extends TestCase {

	/** @var \WC_Shipping_Zone|null */
	private ?\WC_Shipping_Zone $zone = null;

	/** @var int */
	private int $instance_id = 0;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! Location_Provider_Registry::instance()->is_needed() ) {
			$this->markTestSkipped( 'No active plugin asked for the location layer, so core pickup gets no city limit (by design).' );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// WooCommerce instantiates the zone methods through this registry.
		apply_filters( 'woocommerce_shipping_methods', [] );

		$this->zone = new \WC_Shipping_Zone();
		$this->zone->set_zone_name( 'City limit integration zone' );
		$this->zone->add_location( 'RU', 'country' );
		$this->zone->save();

		$this->instance_id = $this->zone->add_shipping_method( 'local_pickup' );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_REQUEST['instance_id'] );
		remove_all_filters( Location_Service::FILTER_PROVIDER_FOR_LEVEL );

		if ( null !== $this->zone ) {
			delete_option( 'woocommerce_local_pickup_' . $this->instance_id . '_settings' );
			$this->zone->delete( true );
		}

		parent::tearDown();
	}

	/**
	 * @return \WC_Shipping_Method
	 */
	private function method(): \WC_Shipping_Method {
		return \WC_Shipping_Zones::get_shipping_method( $this->instance_id );
	}

	/**
	 * @param string $key   locality key.
	 * @param string $label label.
	 *
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
	 * @return void
	 */
	public function test_the_instance_form_of_core_pickup_gets_the_two_fields_and_draws_the_cities_row(): void {
		$fields = $this->method()->get_instance_form_fields();

		$this->assertArrayHasKey( City_Limit::OPTION_MODE, $fields );
		$this->assertArrayHasKey( City_Limit::OPTION_CITIES, $fields );

		$html = $this->method()->get_admin_options_html();

		$this->assertStringContainsString( 'name="woocommerce_local_pickup_city_limit_mode"', $html );
		$this->assertStringContainsString( 'name="woocommerce_local_pickup_city_limit_cities"', $html );
		$this->assertStringContainsString( 'class="woodev-city-limit"', $html );
		$this->assertStringContainsString( 'data-woodev-show-if', $html, 'the cities row hides with the mode' );
	}

	/**
	 * @return void
	 */
	public function test_saving_the_form_keeps_the_mode_and_only_valid_compact_cities(): void {
		$_REQUEST['instance_id'] = (string) $this->instance_id;

		$method = $this->method();
		$method->set_post_data(
			[
				'woocommerce_local_pickup_title'             => 'Самовывоз',
				'woocommerce_local_pickup_tax_status'        => 'taxable',
				'woocommerce_local_pickup_cost'              => '',
				'woocommerce_local_pickup_city_limit_mode'   => 'include',
				'woocommerce_local_pickup_city_limit_cities' => wp_json_encode(
					[
						array_merge( $this->city( 'itest:1', 'Пушкин' )->to_array(), [ 'raw' => [ 'x' => str_repeat( 'y', 100 ) ] ] ),
						[ 'key' => 'broken' ],
					],
					JSON_UNESCAPED_UNICODE
				),
			]
		);
		$method->process_admin_options();

		$stored = get_option( 'woocommerce_local_pickup_' . $this->instance_id . '_settings' );

		$this->assertSame( 'include', $stored[ City_Limit::OPTION_MODE ] );
		$this->assertCount( 1, City_Limit::decode( $stored[ City_Limit::OPTION_CITIES ] ) );
		$this->assertStringNotContainsString( 'yyyy', $stored[ City_Limit::OPTION_CITIES ], 'the provider payload is not stored' );
	}

	/**
	 * @return void
	 */
	public function test_core_pickup_is_rated_only_where_the_limit_allows_it(): void {
		// a provider that is only an id, so the ownership rule has someone to find (anonymous: every named class in
		// this directory must extend the shared base — IntegrationSuiteBaseClassTest)
		$provider = new class() extends Abstract_Location_Provider {

			public function get_id(): string {
				return 'itest';
			}

			public function get_name(): string {
				return 'Integration test provider';
			}

			public function get_countries(): array {
				return [ 'RU' ];
			}

			public function is_configured(): bool {
				return true;
			}

			protected function declare_suggest_levels(): array {
				return Location_Record::LEVELS;
			}

			public function suggest( string $query, Location_Scope $scope ): array {
				return [];
			}
		};

		add_filter( Location_Service::FILTER_PROVIDER_FOR_LEVEL, static fn() => $provider );

		update_option(
			'woocommerce_local_pickup_' . $this->instance_id . '_settings',
			[
				'title'                       => 'Самовывоз',
				'tax_status'                  => 'taxable',
				'cost'                        => '',
				City_Limit::OPTION_MODE       => 'include',
				City_Limit::OPTION_CITIES     => City_Limit::encode( [ $this->city( 'itest:1', 'Пушкин' ) ] ),
			]
		);

		$service = new Location_Service();
		$package = [
			'destination'   => [ 'country' => 'RU', 'state' => '', 'postcode' => '', 'city' => '', 'address' => '' ],
			'contents'      => [],
			'contents_cost' => 100,
		];

		// A customer in a listed city keeps it; one elsewhere does not; one who has not chosen keeps it (D1).
		$service->set_customer_record( $this->city( 'itest:1', 'Пушкин' ) );
		$this->assertTrue( $this->method()->is_available( $package ) );

		$service->set_customer_record( $this->city( 'itest:2', 'Москва' ) );
		$this->assertFalse( $this->method()->is_available( $package ) );

		$service->forget_customer_record();
		$this->assertTrue( $this->method()->is_available( $package ) );
	}
}

<?php
/**
 * Issue #296 seam-pin test: Checkout_Config::build_location_block()'s own
 * `defaultCountry` key and Location_Controller::perform_suggest()'s own
 * empty-`country`-param fallback must resolve to the SAME answer — both are
 * backed by the ONE Location_Service::resolve_default_country() method
 * (`checkout field -> WooCommerce store setting -> RU`). This project has
 * already shipped a layer where each side of a client/server boundary was
 * independently green in its own tests and quietly disagreed between them;
 * this file exercises BOTH call sites against the SAME `get_option()` stub
 * so a future change that moves one side without the other fails here
 * first, not on a live checkout.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Checkout\Checkout_Config;
	use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Shipping\Rest_Api\Location_Controller;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-field.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/location/class-location-service.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';

	if ( ! class_exists( '\\WP_REST_Controller' ) ) {
		require_once dirname( __DIR__ ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';
	}

	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/rest-api/trait-rest-rate-limit.php';
	require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/rest-api/class-location-controller.php';

	/**
	 * Minimal \WP_REST_Request stand-in — namespace-scoped, mirrors the same
	 * double `LocationControllerTest` already defines in its own namespace.
	 */
	if ( ! class_exists( __NAMESPACE__ . '\\WP_REST_Request', false ) ) {
		class WP_REST_Request {

			/** @var array<string, mixed> */
			private array $params;

			/**
			 * @param array<string, mixed> $params request params.
			 */
			public function __construct( array $params = [] ) {
				$this->params = $params;
			}

			/**
			 * @param string $key param name.
			 *
			 * @return mixed|null
			 */
			public function get_param( $key ) {
				return $this->params[ $key ] ?? null;
			}

			/**
			 * @param string $key header name.
			 *
			 * @return string|null
			 */
			public function get_header( $key ) {
				return null;
			}
		}
	}

	/**
	 * A single {@see Location_Service} double shared by BOTH sides of the seam
	 * this file pins — every method Checkout_Config's location block AND
	 * Location_Controller's `/suggest` route read is fixed here EXCEPT
	 * {@see Location_Service::resolve_default_country()}, which is
	 * deliberately left un-overridden so the REAL method (get_option() +
	 * apply_filters(), both stubbed per test) runs on both call sites.
	 */
	final class Seam_Fake_Location_Service extends Location_Service {

		private Location_Provider $provider;

		public function __construct( Location_Provider $provider ) {
			$this->provider = $provider;
		}

		public function is_active(): bool {
			return true;
		}

		public function get_customer_record( ?string $for_country = null ): ?array {
			return null;
		}

		public function set_customer_record( Location_Record $record, bool $implicit = false ): bool {
			return true;
		}

		public function is_country_supported( string $country, ?string $level = null ): bool {
			return in_array( strtoupper( trim( $country ) ), $this->provider->get_countries(), true );
		}

		public function get_supported_countries(): array {
			return $this->provider->get_countries();
		}

		public function get_field_mode_region(): string {
			return 'typeahead';
		}

		public function get_field_mode_settlement(): string {
			return 'typeahead';
		}

		public function owns_region_states( string $country, array $final_states ): bool {
			return false;
		}

		public function get_levels_for_country( string $country ): array {
			return [ 'region' => true, 'settlement' => true, 'address' => true ];
		}

		public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
			return $this->provider;
		}
	}

	/**
	 * A suggest-only fake provider covering exactly the countries the test
	 * hands it, spying on every `suggest()` call's own scope.
	 */
	final class Seam_Fake_Provider extends Abstract_Location_Provider {

		/** @var string[] */
		private array $countries;

		/** @var array<int, array{0: string, 1: Location_Scope}> */
		public array $suggest_calls = [];

		/**
		 * @param string[] $countries ISO-3166 alpha-2 codes this fake covers.
		 */
		public function __construct( array $countries ) {
			$this->countries = $countries;
		}

		public function get_id(): string {
			return 'seam-fixture';
		}

		public function get_name(): string {
			return 'Seam Fixture';
		}

		public function get_countries(): array {
			return $this->countries;
		}

		protected function declare_suggest_levels(): array {
			return Location_Record::LEVELS;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			$this->suggest_calls[] = [ $query, $scope ];

			return [];
		}
	}

	/**
	 * Bypasses the rate limiter — mirrors `Location_Controller_Probe` in
	 * `LocationControllerTest`.
	 */
	final class Seam_Location_Controller_Probe extends Location_Controller {

		protected function is_rate_limited( string $key_prefix, int $max, int $window = 60 ): bool {
			return false;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Location_Service::resolve_default_country
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Config::build
	 * @covers \Woodev\Framework\Shipping\Rest_Api\Location_Controller::handle_suggest_request
	 */
	final class LocationDefaultCountrySeamTest extends TestCase {

		public function test_checkout_config_and_suggest_agree_on_the_default_country(): void {
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'wc_clean' )->alias(
				static function ( $value ) {
					return is_string( $value ) ? trim( $value ) : $value;
				}
			);
			Functions\when( 'rest_ensure_response' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			// Location_Service::resolve_default_country() reads wc_get_base_location() (PR #320
			// review, finding 3 — never a raw get_option() read); stubbed explicitly here rather
			// than relying on some OTHER test file having already defined it earlier in the same
			// process (this file's own tests always run against a fresh, single-test PHPUnit
			// invocation too — see composer test:unit's own filter usage).
			Functions\when( 'wc_get_base_location' )->justReturn( [ 'country' => 'KZ', 'state' => 'north' ] );

			$provider = new Seam_Fake_Provider( [ 'KZ' ] );
			$service  = new Seam_Fake_Location_Service( $provider );

			// Side 1: the checkout config block a page load emits.
			$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'N', [ 'KZ' ], $service ) )
				->build( Checkout_Fields::from_array( [] ) );

			// Side 2: the /suggest route, given an EMPTY `country` param — exactly what a
			// checkout with no country field sends (location-cascade.js's own countryFor(),
			// issue #296) and, crucially, the ONLY shape a REAL request can carry: the route
			// registers `country` as `'required' => true` (class-location-controller.php's own
			// register_routes()), so WP_REST_Server::dispatch() rejects a request that omits the
			// key entirely with `rest_missing_callback_param` BEFORE handle_suggest_request() is
			// ever reached — a request built with no `country` key at all (as this test used to)
			// pins a shape production could never produce (PR #320 review, finding 4). Mirrors
			// LocationControllerTest's own `'country' => ''` convention for this exact case.
			$ctrl    = new Seam_Location_Controller_Probe( $service );
			$request = new WP_REST_Request( [ 'q' => 'Ал', 'level' => Location_Record::LEVEL_REGION, 'country' => '' ] );
			$ctrl->handle_suggest_request( $request );

			$this->assertSame( 'KZ', $config['location']['defaultCountry'] );
			$this->assertCount( 1, $provider->suggest_calls, 'the /suggest route must have reached the provider at all' );
			$this->assertSame(
				$config['location']['defaultCountry'],
				$provider->suggest_calls[0][1]->country(),
				'the config block and the /suggest fallback must resolve to the SAME country — the whole point of this test'
			);
		}
	}
}

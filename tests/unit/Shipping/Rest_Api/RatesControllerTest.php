<?php
/**
 * Unit: `POST woodev/v1/shipping/orders/rates` — the rates route of the admin order wizard
 * (#965, spec D2).
 *
 * Covers: the route registration and its capability gate, the request → calculator hand-off
 * (lines at their edited prices, the parsed record, the customer, the normalized destination), and
 * the declared errors — 422 for an unknown product / customer / missing country, 400 for a
 * malformed location record. The pricing itself is {@see \Woodev\Tests\Unit\Shipping\Admin\AdminRateCalculatorTest}.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace {

	if ( ! class_exists( 'WP_REST_Server', false ) ) {
		/**
		 * The one constant the controller reads.
		 */
		class WP_REST_Server {

			const CREATABLE = 'POST';
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Rest_Api {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Admin\Orders\Admin_Rate_Calculator;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Rest_Api\Rates_Controller;
	use Woodev\Tests\Unit\TestCase;

	if ( ! class_exists( '\\WP_REST_Controller' ) ) {
		require_once __DIR__ . '/wp-rest-controller-stub.php';
	}

	require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-rates-controller.php';

	/**
	 * @coversDefaultClass \Woodev\Framework\Shipping\Rest_Api\Rates_Controller
	 */
	final class RatesControllerTest extends TestCase {

		/** @var Admin_Rate_Calculator&\Mockery\MockInterface */
		private $calculator;

		/** @return void */
		protected function setUp(): void {
			parent::setUp();

			$this->calculator = Mockery::mock( Admin_Rate_Calculator::class );

			Functions\when( 'rest_ensure_response' )->returnArg();
			Functions\when( 'absint' )->alias(
				static function ( $value ) {
					return abs( (int) $value );
				}
			);
			Functions\when( 'get_userdata' )->alias(
				static function ( $id ) {
					return 7 === (int) $id ? (object) [ 'ID' => 7 ] : false;
				}
			);
		}

		/** @return Rates_Controller */
		private function controller(): Rates_Controller {
			return new Rates_Controller( Mockery::mock( Orders_Registry::class ), $this->calculator );
		}

		/**
		 * @param array<string, mixed> $params request params.
		 * @return \WP_REST_Request
		 */
		private function request( array $params ): \WP_REST_Request {
			return new \WP_REST_Request(
				array_merge(
					[
						'items'       => [
							[
								'product_id' => 12,
								'quantity'   => 2,
							],
						],
						'destination' => [ 'country' => 'RU' ],
					],
					$params
				)
			);
		}

		/**
		 * @param int $id product id.
		 * @return \WC_Product
		 */
		private function product( int $id ): \WC_Product {
			$product = Mockery::mock( '\WC_Product' );
			$product->shouldReceive( 'get_id' )->andReturn( $id );

			return $product;
		}

		/**
		 * @covers ::get_rates_permissions_check
		 *
		 * @return void
		 */
		public function test_the_route_requires_edit_shop_orders(): void {
			Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( true );
			$this->assertTrue( $this->controller()->get_rates_permissions_check( new \WP_REST_Request() ) );

			Functions\expect( 'current_user_can' )->once()->with( 'edit_shop_orders' )->andReturn( false );
			$this->assertFalse( $this->controller()->get_rates_permissions_check( new \WP_REST_Request() ) );
		}

		/**
		 * @covers ::register_routes
		 *
		 * @return void
		 */
		public function test_the_route_is_registered_as_a_post_gated_by_the_permission_check(): void {
			$captured = [];

			Functions\when( 'register_rest_route' )->alias(
				static function ( $namespace, $route, $args ) use ( &$captured ) {
					$captured[] = [ $namespace, $route, $args ];
				}
			);

			$controller = $this->controller();
			$controller->register_routes();

			$this->assertCount( 1, $captured );

			[ $namespace, $route, $args ] = $captured[0];

			$this->assertSame( 'woodev/v1', $namespace );
			$this->assertSame( '/shipping/orders/rates', $route );
			$this->assertSame( 'POST', $args['methods'] );
			$this->assertSame( [ $controller, 'get_rates' ], $args['callback'] );
			$this->assertSame( [ $controller, 'get_rates_permissions_check' ], $args['permission_callback'] );
			$this->assertTrue( $args['args']['items']['required'] );
			$this->assertSame( 'integer', $args['args']['customer_id']['type'] );
		}

		/**
		 * @covers ::get_rates
		 *
		 * @return void
		 */
		public function test_the_lines_the_customer_and_the_destination_reach_the_calculator(): void {
			$product = $this->product( 12 );
			Functions\expect( 'wc_get_product' )->once()->with( 12 )->andReturn( $product );

			$normalized = [
				'country' => 'RU',
				'state'   => 'МОСКВА',
			];

			$this->calculator->shouldReceive( 'normalize_destination' )
				->once()
				->with(
					[
						'country' => 'RU',
						'city'    => 'Москва',
					],
					null
				)
				->andReturn( $normalized );

			$this->calculator->shouldReceive( 'calculate' )
				->once()
				->with(
					[
						[
							'product'  => $product,
							'quantity' => 2,
							'price'    => 150.5,
						],
					],
					$normalized,
					null,
					7
				)
				->andReturn( [ 'providers' => [] ] );

			$response = $this->controller()->get_rates(
				$this->request(
					[
						'items'       => [
							[
								'product_id' => 12,
								'quantity'   => 2,
								'price'      => '150.5',
							],
						],
						'destination' => [
							'country' => 'RU',
							'city'    => 'Москва',
						],
						'customer_id' => 7,
					]
				)
			);

			$this->assertSame( [ 'providers' => [] ], $response );
		}

		/**
		 * A line without a price keeps the product's own; a variation is looked up by ITS id.
		 *
		 * @covers ::get_rates
		 *
		 * @return void
		 */
		public function test_a_missing_price_is_null_and_a_variation_is_resolved_by_its_own_id(): void {
			$variation = $this->product( 55 );
			Functions\expect( 'wc_get_product' )->once()->with( 55 )->andReturn( $variation );

			$this->calculator->shouldReceive( 'normalize_destination' )->andReturn( [ 'country' => 'RU' ] );
			$this->calculator->shouldReceive( 'calculate' )
				->once()
				->with(
					[
						[
							'product'  => $variation,
							'quantity' => 1,
							'price'    => null,
						],
					],
					Mockery::type( 'array' ),
					null,
					0
				)
				->andReturn( [] );

			$this->controller()->get_rates(
				$this->request(
					[
						'items' => [
							[
								'product_id'   => 50,
								'variation_id' => 55,
								'quantity'     => 1,
							],
						],
					]
				)
			);
		}

		/**
		 * A silently smaller package would price the wrong parcel.
		 *
		 * @covers ::get_rates
		 *
		 * @return void
		 */
		public function test_an_unknown_product_is_a_422_not_a_skipped_line(): void {
			Functions\when( 'wc_get_product' )->justReturn( false );
			$this->calculator->shouldNotReceive( 'calculate' );

			$result = $this->controller()->get_rates( $this->request( [] ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'woodev_rates_unknown_product', $result->code );
			$this->assertSame( 422, $result->data['status'] );
		}

		/**
		 * @covers ::get_rates
		 *
		 * @return void
		 */
		public function test_an_unknown_customer_is_a_422(): void {
			$this->calculator->shouldNotReceive( 'calculate' );

			$result = $this->controller()->get_rates( $this->request( [ 'customer_id' => 99 ] ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'woodev_rates_unknown_customer', $result->code );
			$this->assertSame( 422, $result->data['status'] );
		}

		/**
		 * @covers ::get_rates
		 *
		 * @return void
		 */
		public function test_a_destination_without_a_country_is_a_422(): void {
			Functions\when( 'wc_get_product' )->justReturn( $this->product( 12 ) );

			$this->calculator->shouldReceive( 'normalize_destination' )->andReturn( [ 'country' => '' ] );
			$this->calculator->shouldNotReceive( 'calculate' );

			$result = $this->controller()->get_rates( $this->request( [ 'destination' => [] ] ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'woodev_rates_no_country', $result->code );
			$this->assertSame( 422, $result->data['status'] );
		}

		/**
		 * @covers ::get_rates
		 *
		 * @return void
		 */
		public function test_a_malformed_location_is_a_400(): void {
			$this->calculator->shouldNotReceive( 'calculate' );

			$result = $this->controller()->get_rates( $this->request( [ 'location' => [ 'key' => '' ] ] ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'woodev_location_invalid_record', $result->code );
			$this->assertSame( 400, $result->data['status'] );
		}

		/**
		 * The parsed record is handed on (Mine 3) and also fills the destination.
		 *
		 * @covers ::get_rates
		 *
		 * @return void
		 */
		public function test_a_valid_location_is_parsed_and_handed_to_the_calculator(): void {
			Functions\when( 'wc_get_product' )->justReturn( $this->product( 12 ) );

			$record = [
				'key'         => 'test:region:moscow',
				'provider_id' => 'test',
				'level'       => 'region',
				'country'     => 'RU',
				'label'       => 'Москва',
			];

			$this->calculator->shouldReceive( 'normalize_destination' )
				->once()
				->with( Mockery::type( 'array' ), Mockery::type( Location_Record::class ) )
				->andReturn( [ 'country' => 'RU' ] );

			$this->calculator->shouldReceive( 'calculate' )
				->once()
				->with( Mockery::type( 'array' ), [ 'country' => 'RU' ], Mockery::type( Location_Record::class ), 0 )
				->andReturn( [] );

			$this->controller()->get_rates( $this->request( [ 'location' => $record ] ) );
		}
	}
}

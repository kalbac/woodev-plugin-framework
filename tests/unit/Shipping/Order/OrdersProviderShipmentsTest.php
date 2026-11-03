<?php
/**
 * Unit tests for the parcels a buyer can follow, as an Orders_Provider reports them (#1575).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Filters;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\{Orders_Provider, Orders_Registry};
use Woodev\Framework\Shipping\Exceptions\Shipping_Exception;
use Woodev\Framework\Shipping\Order\Tracking_Shipment;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once __DIR__ . '/tracking-order-fakes.php';

/** @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider */
final class OrdersProviderShipmentsTest extends TestCase {

	use Fakes_Tracking_Orders;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_tracking_orders();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $args optional provider arguments.
	 */
	private function provider( array $args = [] ): Orders_Provider {
		return Orders_Provider::create( 'test', 'Перевозчик', '_marker', [ 'test_shipping' ], $args );
	}

	/** The CDEK / Russian Post shape: a scalar number in one meta and a link template. */
	private function cdek_like(): Orders_Provider {
		return $this->provider(
			[
				'tracking_meta_key'     => '_tracking',
				'tracking_url_template' => 'https://track.test/{tracking}',
			]
		);
	}

	public function test_the_default_path_builds_one_shipment_from_the_scalar_meta_and_the_template(): void {
		$this->meta['_tracking'] = ' ABC 123 ';

		$shipments = $this->cdek_like()->get_tracking_shipments( $this->tracking_order() );

		$this->assertCount( 1, $shipments );
		$this->assertSame( 'ABC 123', $shipments[0]->get_number() );
		$this->assertSame( 'https://track.test/ABC%20123', $shipments[0]->get_url(), 'the number is rawurlencoded into the template' );
	}

	public function test_the_declared_absolute_link_wins_over_the_template(): void {
		$this->meta['_tracking']     = 'ABC';
		$this->meta['_tracking_url'] = 'https://own.test/request/abc';
		$provider                    = $this->provider(
			[
				'tracking_meta_key'     => '_tracking',
				'tracking_url_meta_key' => '_tracking_url',
				'tracking_url_template' => 'https://track.test/{tracking}',
			]
		);

		$this->assertSame( 'https://own.test/request/abc', $provider->get_tracking_shipments( $this->tracking_order() )[0]->get_url() );
	}

	public function test_a_number_without_any_link_declaration_is_a_number_only_shipment(): void {
		$this->meta['_tracking'] = 'ABC';

		$shipments = $this->provider( [ 'tracking_meta_key' => '_tracking' ] )->get_tracking_shipments( $this->tracking_order() );

		$this->assertSame( 'ABC', $shipments[0]->get_number() );
		$this->assertNull( $shipments[0]->get_url() );
	}

	public function test_no_number_means_no_shipment_even_when_a_link_is_stored(): void {
		$this->meta['_tracking_url'] = 'https://own.test/request/abc';
		$provider                    = $this->provider(
			[
				'tracking_meta_key'     => '_tracking',
				'tracking_url_meta_key' => '_tracking_url',
				'tracking_url_template' => 'https://track.test/{tracking}',
			]
		);

		$this->assertSame( [], $provider->get_tracking_shipments( $this->tracking_order() ) );
		$this->assertSame( [], $this->provider()->get_tracking_shipments( $this->tracking_order() ), 'a carrier with no tracking declaration has nothing to show' );
	}

	public function test_a_meta_that_is_not_a_scalar_is_no_number(): void {
		$this->meta['_tracking'] = [ 'TRK' ];

		$this->assertSame( [], $this->cdek_like()->get_tracking_shipments( $this->tracking_order() ) );
	}

	public function test_a_resolver_decides_and_only_displayable_shipments_survive(): void {
		$good     = Tracking_Shipment::create( 'A1', 'https://track.test/A1' );
		$link     = Tracking_Shipment::create( '', 'https://track.test/B2' );
		$provider = $this->provider(
			[
				'tracking_meta_key' => '_tracking',
				'shipments_resolver' => static function ( \WC_Order $order ) use ( $good, $link ) {
					return [ 'A1', Tracking_Shipment::create( '' ), $good, null, $link ];
				},
			]
		);
		$this->meta['_tracking'] = 'IGNORED';

		$this->assertSame( [ $good, $link ], $provider->get_tracking_shipments( $this->tracking_order() ) );
	}

	public function test_a_resolver_that_throws_yields_nothing_and_is_logged_once(): void {
		$provider = $this->provider(
			[
				'shipments_resolver' => static function () {
					throw new \RuntimeException( 'carrier meta is garbage' );
				},
			]
		);
		$plugin   = Mockery::mock( \Woodev\Framework\Shipping\Shipping_Plugin::class );
		$plugin->shouldReceive( 'log' )->once()->with( Mockery::pattern( '/"test".*carrier meta is garbage/' ) );
		Orders_Registry::instance()->register_provider( $provider, $plugin );

		$this->assertSame( [], $provider->get_tracking_shipments( $this->tracking_order() ) );
		$this->assertSame( [], $provider->get_tracking_shipments( $this->tracking_order() ), 'the second page view does not log again' );
	}

	public function test_a_resolver_that_returns_a_non_array_yields_nothing(): void {
		$provider = $this->provider( [ 'shipments_resolver' => static function () { return 'TRK'; } ] );

		$this->assertSame( [], $provider->get_tracking_shipments( $this->tracking_order() ) );
	}

	public function test_the_filter_can_adjust_the_list_on_both_paths(): void {
		$this->meta['_tracking'] = 'ABC';
		$provider                = $this->cdek_like();
		$order                   = $this->tracking_order();
		$extra                   = Tracking_Shipment::create( 'EXTRA' );

		Filters\expectApplied( 'woodev_shipping_order_tracking_shipments' )
			->once()
			->with( Mockery::on( static function ( $list ) { return 1 === count( $list ) && 'ABC' === $list[0]->get_number(); } ), $order, $provider )
			->andReturn( [ $extra, 'junk' ] );

		$this->assertSame( [ $extra ], $provider->get_tracking_shipments( $order ), 'junk a filter returns is dropped' );
	}

	public function test_a_filter_that_returns_a_non_array_leaves_the_list_alone(): void {
		$this->meta['_tracking'] = 'ABC';
		Filters\expectApplied( 'woodev_shipping_order_tracking_shipments' )->once()->andReturn( 'nope' );

		$this->assertSame( 'ABC', $this->cdek_like()->get_tracking_shipments( $this->tracking_order() )[0]->get_number() );
	}

	public function test_a_link_only_resolver_gives_a_shipment_with_no_number(): void {
		$provider = $this->provider(
			[
				'shipments_resolver' => static function () {
					return [ Tracking_Shipment::create( '', 'https://yandex.test/share/1' ) ];
				},
			]
		);

		$shipments = $provider->get_tracking_shipments( $this->tracking_order() );

		$this->assertSame( '', $shipments[0]->get_number() );
		$this->assertSame( 'https://yandex.test/share/1', $shipments[0]->get_url() );
	}

	/**
	 * Critic R2: this is WHY the Yandex resolver (W3) must ship in the same wave as framework 2.0.25.
	 *
	 * A Yandex-like provider declares `tracking_meta_key` = the INTERNAL request id (also its carrier order id) and has
	 * no resolver yet. On the default path the framework cannot know the meta is not a buyer number, so it exposes the
	 * request id as «the tracking number». The same provider with a link-only resolver shows no number.
	 */
	public function test_a_yandex_like_provider_without_a_resolver_would_expose_its_internal_request_id_as_the_number(): void {
		$this->meta['_yandex_delivery_request_id']  = 'req-7f3a-INTERNAL';
		$this->meta['_yandex_delivery_sharing_url'] = 'https://dostavka.yandex.test/share/abc';
		$declared                                   = [
			'tracking_meta_key'     => '_yandex_delivery_request_id',
			'tracking_url_meta_key' => '_yandex_delivery_sharing_url',
		];
		$order = $this->tracking_order();

		$without = $this->provider( $declared )->get_tracking_shipments( $order );
		$this->assertSame( 'req-7f3a-INTERNAL', $without[0]->get_number(), 'the default path prints the request id: W3 must ship with 2.0.25' );

		$with_resolver = $this->provider(
			$declared + [
				'shipments_resolver' => function ( \WC_Order $order ) {
					$url = Tracking_Shipment::valid_url( $this->meta['_yandex_delivery_sharing_url'] ?? '' );

					return null === $url ? [] : [ Tracking_Shipment::create( '', $url ) ];
				},
			]
		)->get_tracking_shipments( $order );
		$this->assertSame( '', $with_resolver[0]->get_number(), 'with the link-only resolver no number is shown' );
		$this->assertSame( 'https://dostavka.yandex.test/share/abc', $with_resolver[0]->get_url() );
	}

	public function test_a_declared_resolver_must_be_callable(): void {
		$this->expectException( Shipping_Exception::class );

		$this->provider( [ 'shipments_resolver' => 'no_such_function_anywhere' ] );
	}

	/**
	 * The spec §5 question: does create() reject unknown argument keys? It does not — it reads only the keys it knows.
	 * So a carrier that declares `shipments_resolver` against an OLDER framework is silently ignored (and that is why
	 * the plugin must require 2.0.25).
	 */
	public function test_create_ignores_unknown_argument_keys(): void {
		$provider = $this->provider( [ 'a_key_from_the_future' => static function () { return []; } ] );

		$this->assertSame( 'test', $provider->get_id() );
	}
}

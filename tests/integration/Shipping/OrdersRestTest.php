<?php
/**
 * Integration: shipping orders REST route (woodev/v1/shipping/orders).
 *
 * Proves the aggregated controller registers under woodev/v1, that a real dispatch
 * honours the capability gate, and — the thing SP-10's M2 measurement is actually
 * about — that the aggregate view returns rows from every registered carrier via the
 * SAME query while a single-carrier request scopes to that carrier only. Modelled on
 * SettingsPageRestTest (rest_get_server / WP_REST_Request / dispatch).
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE (SP-10 increment 1 brief) — a
 * worktree checkout resolves fixtures against the main checkout's tests-cli
 * container, which this worktree is not. The coordinator runs it there.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class OrdersRestTest extends TestCase {

	/** @var string test-only cdek marker meta key. */
	private const CDEK_MARKER = '_woodev_test_cdek_marker';

	/** @var string test-only yandex marker meta key. */
	private const YANDEX_MARKER = '_woodev_test_yandex_marker';

	/**
	 * Registers two test providers and rebuilds the REST server so the orders
	 * controller (re-hooked on rest_api_init) registers its routes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', self::CDEK_MARKER ) );
		$registry->register_provider( Orders_Provider::create( 'yandex', 'Яндекс Доставка', self::YANDEX_MARKER ) );

		// Force a fresh REST server so rest_api_init fires with the re-added hook.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Creates a real WC_Order carrying one carrier's marker meta key.
	 *
	 * @param string $marker_key marker meta key to set.
	 * @return \WC_Order
	 */
	private function create_marked_order( string $marker_key ): \WC_Order {
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( $marker_key, '1' );
		$order->save();

		return $order;
	}

	public function test_orders_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey( '/woodev/v1/shipping/orders', $routes );
	}

	public function test_forbidden_for_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The M2 measurement's real subject: one aggregate request returns rows from
	 * every registered carrier's marker key, and only those.
	 */
	public function test_aggregate_returns_rows_from_every_carrier_and_excludes_unrelated_orders(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cdek_order   = $this->create_marked_order( self::CDEK_MARKER );
		$yandex_order = $this->create_marked_order( self::YANDEX_MARKER );

		$unrelated = wc_create_order();
		$unrelated->set_status( 'processing' );
		$unrelated->save();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' ) );

		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $cdek_order->get_id(), $ids );
		$this->assertContains( $yandex_order->get_id(), $ids );
		$this->assertNotContains( $unrelated->get_id(), $ids );
	}

	public function test_single_carrier_scopes_to_that_carrier_only(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cdek_order   = $this->create_marked_order( self::CDEK_MARKER );
		$yandex_order = $this->create_marked_order( self::YANDEX_MARKER );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'cdek' );

		$response = rest_get_server()->dispatch( $request );
		$ids      = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $cdek_order->get_id(), $ids );
		$this->assertNotContains( $yandex_order->get_id(), $ids );
	}

	public function test_unknown_carrier_is_a_400_not_a_silent_fallback_to_the_aggregate(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'ghost-carrier' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_response_carries_total_and_total_pages_for_pagination(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$this->create_marked_order( self::CDEK_MARKER );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
		$this->assertGreaterThanOrEqual( 1, $data['total'] );
	}

	/**
	 * The increment-1 minimal row shape: id, order number, edit URL, ISO 8601 date
	 * created, WC status (slug + label), and the carrier that matched.
	 */
	public function test_row_carries_the_minimal_increment_1_shape(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$order = $this->create_marked_order( self::CDEK_MARKER );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' ) );

		$row = null;
		foreach ( $response->get_data()['rows'] as $candidate ) {
			if ( $candidate['id'] === $order->get_id() ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row, 'the marked order must appear in the aggregate rows' );
		$this->assertSame( $order->get_order_number(), $row['order_number'] );
		$this->assertNotEmpty( $row['edit_url'] );
		$this->assertNotEmpty( $row['date_created'] );
		$this->assertSame( 'processing', $row['status']['slug'] );
		$this->assertNotEmpty( $row['status']['label'] );
		$this->assertSame( 'cdek', $row['carrier']['id'] );
		$this->assertSame( 'СДЭК', $row['carrier']['label'] );
	}
}

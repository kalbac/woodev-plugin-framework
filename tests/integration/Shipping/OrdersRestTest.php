<?php
/**
 * Integration: shipping orders REST route (woodev/v1/shipping/orders).
 *
 * Proves the aggregated controller registers under woodev/v1, that a real dispatch
 * honours the capability gate, and — the thing SP-10's M2 measurement is actually
 * about — that the aggregate view returns rows from every registered carrier via the
 * SAME query while a single-carrier request scopes to that carrier only. Increment 2a
 * extends this with the full row payload (M1, D3) and the canonical delivery status
 * (D4) end to end against a real `WC_Order` carrying a real shipping-zone method
 * instance. Modelled on SettingsPageRestTest (rest_get_server / WP_REST_Request /
 * dispatch).
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE — a worktree checkout resolves
 * fixtures against the main checkout's tests-cli container, which this worktree is
 * not. The coordinator runs it there.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;
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
		$registry->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', self::CDEK_MARKER, [ 'cdek' ] ) );
		$registry->register_provider( Orders_Provider::create( 'yandex', 'Яндекс Доставка', self::YANDEX_MARKER, [ 'yandex' ] ) );

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

	/**
	 * Increment 2a end to end: a real order carrying a real shipping-zone method
	 * instance, a carrier tracking number, a raw carrier status, and a chosen pickup
	 * point — asserting the full M1/D3 row payload plus the D4 canonical status
	 * mapping, all through one REST dispatch against the real order.
	 *
	 * Uses the ambient `woodev_test_shipping` fixture method (already loaded by every
	 * integration test run) for `type` resolution, rather than a throwaway method id —
	 * that method's own `get_delivery_type()` returns `'pickup'` (issue #709), which is
	 * also why this test's pickup-point/destination assertions and its `type`
	 * assertion agree with each other.
	 *
	 * @return void
	 */
	public function test_full_row_shape_and_status_mapping_end_to_end(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker              = '_woodev_test_full_row_marker';
		$status_meta_key     = '_woodev_test_full_row_status';
		$tracking_meta_key   = '_woodev_test_full_row_tracking';
		$pickup_point_key    = '_woodev_test_full_row_pickup_point';
		$instance_id         = $this->zone_method_instance_id();

		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'full_row_carrier',
				'Full Row Carrier',
				$marker,
				[ 'woodev_test_shipping' ],
				[
					'status_meta_key'        => $status_meta_key,
					'status_map'             => [ 'ACCEPTED' => Delivery_Status::IN_TRANSIT ],
					'status_labels'          => [ 'ACCEPTED' => 'Принят курьером' ],
					'tracking_meta_key'      => $tracking_meta_key,
					'tracking_url_template'  => 'https://example.test/track/{tracking}',
					'pickup_point_meta_key'  => $pickup_point_key,
				]
			)
		);

		// Force a fresh REST server so the freshly registered provider's route args see it.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( $marker, '1' );
		$order->update_meta_data( $status_meta_key, 'ACCEPTED' );
		$order->update_meta_data( $tracking_meta_key, 'TRACK123456' );
		$order->update_meta_data( $pickup_point_key, [ 'address' => 'ПВЗ, ул. Мира, 5' ] );

		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_title( 'Test pickup' );
		$shipping_item->set_method_id( 'woodev_test_shipping' );
		$shipping_item->set_instance_id( $instance_id );
		$shipping_item->set_total( '199' );
		$order->add_item( $shipping_item );
		$order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'full_row_carrier' );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$rows = $response->get_data()['rows'];
		$this->assertCount( 1, $rows );
		$row = $rows[0];

		// Increment 1 fields survive.
		$this->assertSame( $order->get_id(), $row['id'] );
		$this->assertSame( 'full_row_carrier', $row['carrier']['id'] );

		// customer (M1: falls back to display_name — this order has no billing name).
		$this->assertArrayHasKey( 'name', $row['customer'] );
		$this->assertArrayHasKey( 'email', $row['customer'] );
		$this->assertArrayHasKey( 'phone', $row['customer'] );
		$this->assertSame( 0, $row['customer']['user_id'] );
		$this->assertNull( $row['customer']['user_edit_url'] );

		// payment.
		$this->assertFalse( $row['payment']['needs_payment'] );
		$this->assertArrayHasKey( 'method_title', $row['payment'] );
		$this->assertArrayHasKey( 'formatted_total', $row['payment'] );

		// shipping + destination: the pickup point wins over the (absent) address.
		$this->assertSame( 'pickup', $row['shipping']['destination_kind'] );
		$this->assertSame( 'ПВЗ, ул. Мира, 5', $row['shipping']['destination_text'] );

		// type: resolved from the real shipping-zone method instance.
		$this->assertSame( 'pickup', $row['type'] );

		// tracking: number + URL built from the template.
		$this->assertSame( 'TRACK123456', $row['tracking']['number'] );
		$this->assertSame( 'https://example.test/track/TRACK123456', $row['tracking']['url'] );

		// delivery_status: the D4 canonical mapping, raw value preserved alongside it.
		$this->assertSame(
			[
				'canonical'       => Delivery_Status::IN_TRANSIT,
				'canonical_label' => Delivery_Status::label( Delivery_Status::IN_TRANSIT ),
				'raw'             => 'ACCEPTED',
				'raw_label'       => 'Принят курьером',
			],
			$row['delivery_status']
		);
	}

	/**
	 * An unmapped raw carrier status must resolve to a DISTINCT `unknown` end to end
	 * through the real REST route — never a guessed canonical state (D4's single most
	 * important behaviour).
	 *
	 * @return void
	 */
	public function test_an_unmapped_raw_status_resolves_to_unknown_end_to_end(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker          = '_woodev_test_unmapped_status_marker';
		$status_meta_key = '_woodev_test_unmapped_status';

		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'unmapped_status_carrier',
				'Unmapped Status Carrier',
				$marker,
				[ 'unmapped_status_carrier' ],
				[
					'status_meta_key' => $status_meta_key,
					'status_map'      => [ 'ACCEPTED' => Delivery_Status::IN_TRANSIT ],
				]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( $marker, '1' );
		$order->update_meta_data( $status_meta_key, 'SOME_STATUS_NOT_IN_THE_MAP' );
		$order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'unmapped_status_carrier' );

		$response = rest_get_server()->dispatch( $request );
		$row      = $response->get_data()['rows'][0];

		$this->assertSame( Delivery_Status::UNKNOWN, $row['delivery_status']['canonical'] );
		$this->assertSame( 'SOME_STATUS_NOT_IN_THE_MAP', $row['delivery_status']['raw'] );
	}

	// -------------------------------------------------------------------------------
	// SP-10 increment 6 (#826, #827, spec D10/D11) — the filter row's server half.
	//
	// NOT RUN BY THE WORKER THAT AUTHORED THESE TESTS, same as the rest of this file
	// (see the class docblock) — but here it matters MORE than usual: this
	// integration environment runs the LEGACY CPT order datastore, which is exactly
	// the path `meta_query` cannot reach directly (gotcha
	// `wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore`). A unit test can
	// only pin the ARGS Orders_Query::build_args() builds; only a real dispatch here,
	// against a real `WC_Order_Data_Store_CPT`, proves the
	// `woocommerce_order_data_store_cpt_get_orders_query` translation in
	// Orders_Registry::translate_marker_keys_query_var() actually filters the rows —
	// for the delivery-status and tracking-presence filters exactly as it already did
	// for the marker-key scope.
	// -------------------------------------------------------------------------------

	public function test_date_range_after_and_before_scope_to_the_range(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$too_early = $this->create_marked_order( self::CDEK_MARKER );
		$too_early->set_date_created( '2010-01-01T00:00:00' );
		$too_early->save();

		$in_range = $this->create_marked_order( self::CDEK_MARKER );
		$in_range->set_date_created( '2050-06-15T00:00:00' );
		$in_range->save();

		$too_late = $this->create_marked_order( self::CDEK_MARKER );
		$too_late->set_date_created( '2090-01-01T00:00:00' );
		$too_late->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'after', '2050-01-01' );
		$request->set_param( 'before', '2050-12-31' );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $in_range->get_id(), $ids );
		$this->assertNotContains( $too_early->get_id(), $ids );
		$this->assertNotContains( $too_late->get_id(), $ids );
	}

	public function test_an_invalid_date_is_a_400(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'after', '2026-02-30' ); // 30 February does not exist.

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_native_status_filter_scopes_to_the_requested_status(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$on_hold = $this->create_marked_order( self::CDEK_MARKER );
		$on_hold->set_status( 'on-hold' );
		$on_hold->save();

		$processing = $this->create_marked_order( self::CDEK_MARKER );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'status', [ 'on-hold' ] );

		$response = rest_get_server()->dispatch( $request );
		$ids      = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $on_hold->get_id(), $ids );
		$this->assertNotContains( $processing->get_id(), $ids );
	}

	public function test_an_invalid_delivery_status_is_a_400(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'delivery_status', 'not-a-real-canonical-state' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * The real subject of D10's delivery-status filter: the inverted `status_map`
	 * scopes to exactly the orders whose RAW meta maps to the requested canonical
	 * state — proven against the legacy CPT datastore's real `meta_query`
	 * translation, not just the args Orders_Query builds.
	 */
	public function test_delivery_status_filter_scopes_to_orders_mapping_to_that_canonical_state(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker          = '_woodev_test_delivery_status_filter_marker';
		$status_meta_key = '_woodev_test_delivery_status_filter_status';

		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'status_filter_carrier',
				'Status Filter Carrier',
				$marker,
				[ 'status_filter_carrier' ],
				[
					'status_meta_key' => $status_meta_key,
					'status_map'      => [
						'ACCEPTED' => Delivery_Status::IN_TRANSIT,
						'HANDED'   => Delivery_Status::DELIVERED,
					],
				]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$in_transit_order = wc_create_order();
		$in_transit_order->set_status( 'processing' );
		$in_transit_order->update_meta_data( $marker, '1' );
		$in_transit_order->update_meta_data( $status_meta_key, 'ACCEPTED' );
		$in_transit_order->save();

		$delivered_order = wc_create_order();
		$delivered_order->set_status( 'processing' );
		$delivered_order->update_meta_data( $marker, '1' );
		$delivered_order->update_meta_data( $status_meta_key, 'HANDED' );
		$delivered_order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'status_filter_carrier' );
		$request->set_param( 'delivery_status', Delivery_Status::IN_TRANSIT );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $in_transit_order->get_id(), $ids );
		$this->assertNotContains( $delivered_order->get_id(), $ids );
	}

	/**
	 * `unknown` must match an order with no status meta at all AND one whose raw
	 * value the `status_map` does not recognize — both meanings D10 asked to decide,
	 * against a real CPT `NOT IN` translation.
	 */
	public function test_delivery_status_unknown_filter_matches_missing_and_unmapped_raw_status(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker          = '_woodev_test_delivery_status_unknown_marker';
		$status_meta_key = '_woodev_test_delivery_status_unknown_status';

		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'unknown_filter_carrier',
				'Unknown Filter Carrier',
				$marker,
				[ 'unknown_filter_carrier' ],
				[
					'status_meta_key' => $status_meta_key,
					'status_map'      => [ 'ACCEPTED' => Delivery_Status::IN_TRANSIT ],
				]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$mapped_order = wc_create_order();
		$mapped_order->set_status( 'processing' );
		$mapped_order->update_meta_data( $marker, '1' );
		$mapped_order->update_meta_data( $status_meta_key, 'ACCEPTED' );
		$mapped_order->save();

		$no_status_order = wc_create_order();
		$no_status_order->set_status( 'processing' );
		$no_status_order->update_meta_data( $marker, '1' );
		$no_status_order->save();

		$unmapped_order = wc_create_order();
		$unmapped_order->set_status( 'processing' );
		$unmapped_order->update_meta_data( $marker, '1' );
		$unmapped_order->update_meta_data( $status_meta_key, 'SOME_RAW_VALUE_NOT_IN_THE_MAP' );
		$unmapped_order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'unknown_filter_carrier' );
		$request->set_param( 'delivery_status', Delivery_Status::UNKNOWN );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertNotContains( $mapped_order->get_id(), $ids );
		$this->assertContains( $no_status_order->get_id(), $ids );
		$this->assertContains( $unmapped_order->get_id(), $ids );
	}

	public function test_has_tracking_true_scopes_to_orders_with_the_tracking_meta(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker            = '_woodev_test_has_tracking_marker';
		$tracking_meta_key = '_woodev_test_has_tracking_number';

		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'tracking_filter_carrier',
				'Tracking Filter Carrier',
				$marker,
				[ 'tracking_filter_carrier' ],
				[ 'tracking_meta_key' => $tracking_meta_key ]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$with_tracking = wc_create_order();
		$with_tracking->set_status( 'processing' );
		$with_tracking->update_meta_data( $marker, '1' );
		$with_tracking->update_meta_data( $tracking_meta_key, 'TRACK123' );
		$with_tracking->save();

		$without_tracking = wc_create_order();
		$without_tracking->set_status( 'processing' );
		$without_tracking->update_meta_data( $marker, '1' );
		$without_tracking->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'tracking_filter_carrier' );
		$request->set_param( 'has_tracking', true );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $with_tracking->get_id(), $ids );
		$this->assertNotContains( $without_tracking->get_id(), $ids );
	}

	public function test_has_tracking_false_scopes_to_orders_without_the_tracking_meta(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker            = '_woodev_test_has_tracking_false_marker';
		$tracking_meta_key = '_woodev_test_has_tracking_false_number';

		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'tracking_false_filter_carrier',
				'Tracking False Filter Carrier',
				$marker,
				[ 'tracking_false_filter_carrier' ],
				[ 'tracking_meta_key' => $tracking_meta_key ]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$with_tracking = wc_create_order();
		$with_tracking->set_status( 'processing' );
		$with_tracking->update_meta_data( $marker, '1' );
		$with_tracking->update_meta_data( $tracking_meta_key, 'TRACK456' );
		$with_tracking->save();

		$without_tracking = wc_create_order();
		$without_tracking->set_status( 'processing' );
		$without_tracking->update_meta_data( $marker, '1' );
		$without_tracking->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'tracking_false_filter_carrier' );
		$request->set_param( 'has_tracking', false );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $without_tracking->get_id(), $ids );
		$this->assertNotContains( $with_tracking->get_id(), $ids );
	}

	/**
	 * Adds the ambient `woodev_test_shipping` fixture method to a real shipping zone
	 * and returns its instance id.
	 *
	 * A REAL zone row is required, not a fabricated instance id: `Order_Row_Builder`
	 * resolves the method with `WC_Shipping_Zones::get_shipping_method( $instance_id )`,
	 * a database lookup that returns nothing for an id no zone carries — mirrors
	 * `ShippingMethodTitleAndRateAttributesTest::zone_method_instance_id()`.
	 *
	 * @return int
	 */
	private function zone_method_instance_id(): int {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'SP-10 orders row test zone' );
		$zone->save();

		return $zone->add_shipping_method( 'woodev_test_shipping' );
	}
}

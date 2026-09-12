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

	/**
	 * ⚠ The same filter on the AGGREGATE, which is where it was broken (#837 defect 2) — and
	 * the test above cannot see it, because it scopes to ONE carrier and a single provider's
	 * clause is never OR-ed against anybody's.
	 *
	 * Each provider's `unknown` clause is a NEGATIVE statement about that provider's own
	 * status meta, and «carrier B wrote no status meta» is trivially true of every carrier A
	 * order. OR-ed across providers and left unbound, it therefore matches the whole table:
	 * measured on the rig 08.09.2026, `delivery_status=unknown` returned 71 of 71. The fix
	 * binds each clause to its provider's own marker, exactly as the `has_tracking=false`
	 * clause was repaired in s127 — gotcha
	 * `a-negative-meta-clause-or-ed-across-providers-matches-every-order`.
	 *
	 * This test exists because a unit test can only assert the meta_query SHAPE. Whether that
	 * nested AND-over-OR actually selects the right ROWS is a question for the database, and
	 * the answer has to come from real orders.
	 */
	public function test_delivery_status_unknown_on_the_aggregate_does_not_match_another_carriers_mapped_order(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$a_marker = '_woodev_test_unknown_aggregate_a_marker';
		$a_status = '_woodev_test_unknown_aggregate_a_status';
		$b_marker = '_woodev_test_unknown_aggregate_b_marker';
		$b_status = '_woodev_test_unknown_aggregate_b_status';

		// ⚠ Down to EXACTLY the two providers this defect needs, dropping setUp's cdek/yandex.
		// Not tidiness — cost. The aggregate delivery-status filter emits ~3 postmeta LEFT JOINs
		// per status-carrying provider plus one per provider for the scope, and on the legacy CPT
		// datastore those joins multiply: at four providers the query reached TWELVE LEFT JOINs on
		// `wp_postmeta` and MySQL sat in "Sending data" for over four minutes (measured 08.09.2026,
		// which is how this was found — the suite hung). Two status-carrying providers is the
		// minimal reproduction of an OR-across-providers defect, and it runs in milliseconds.
		// The join growth itself is real and tracked separately; it is not what this test asserts.
		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create(
				'unknown_aggregate_a',
				'Unknown Aggregate A',
				$a_marker,
				[ 'unknown_aggregate_a' ],
				[
					'status_meta_key' => $a_status,
					'status_map'      => [ 'A_ACCEPTED' => Delivery_Status::IN_TRANSIT ],
				]
			)
		);
		$registry->register_provider(
			Orders_Provider::create(
				'unknown_aggregate_b',
				'Unknown Aggregate B',
				$b_marker,
				[ 'unknown_aggregate_b' ],
				[
					'status_meta_key' => $b_status,
					'status_map'      => [ 'B_SHIPPED' => Delivery_Status::IN_TRANSIT ],
				]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// Carrier A, and its raw status IS mapped — so it is NOT unknown. Before the fix this
		// row matched anyway, because carrier B's clause said «no B status meta here», which
		// is true of every carrier A order.
		$a_mapped = wc_create_order();
		$a_mapped->set_status( 'processing' );
		$a_mapped->update_meta_data( $a_marker, '1' );
		$a_mapped->update_meta_data( $a_status, 'A_ACCEPTED' );
		$a_mapped->save();

		// Carrier B, mapped — the mirror image, so neither provider is privileged by ordering.
		$b_mapped = wc_create_order();
		$b_mapped->set_status( 'processing' );
		$b_mapped->update_meta_data( $b_marker, '1' );
		$b_mapped->update_meta_data( $b_status, 'B_SHIPPED' );
		$b_mapped->save();

		// Genuinely unknown: carrier A order the carrier never reported on.
		$a_unknown = wc_create_order();
		$a_unknown->set_status( 'processing' );
		$a_unknown->update_meta_data( $a_marker, '1' );
		$a_unknown->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'delivery_status', Delivery_Status::UNKNOWN );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $a_unknown->get_id(), $ids, 'An order its carrier never reported on IS unknown.' );
		$this->assertNotContains(
			$a_mapped->get_id(),
			$ids,
			'A mapped carrier-A order must not match `unknown` just because carrier B wrote nothing on it.'
		);
		$this->assertNotContains( $b_mapped->get_id(), $ids, 'Mirror image of the same rule.' );
	}

	/**
	 * A status filter the merchant DID send, in which nothing is a real WC status, must
	 * narrow to nothing rather than quietly returning the whole default list (#837 defect 3).
	 * Measured on the rig 08.09.2026: `status=["nonsense"]` returned 71 of 71.
	 */
	public function test_a_status_filter_with_nothing_recognized_returns_no_rows(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$order = $this->create_marked_order( self::CDEK_MARKER );
		$order->set_status( 'processing' );
		$order->save();

		$sanity = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$sanity_ids = array_column( rest_get_server()->dispatch( $sanity )->get_data()['rows'], 'id' );
		$this->assertContains( $order->get_id(), $sanity_ids, 'Control: the order is visible without a status filter.' );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'status', [ 'Pending payment' ] );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( [], $data['rows'] );
		$this->assertSame( 0, $data['total'] );
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

	// -------------------------------------------------------------------------------
	// SP-10 increment 7 (#836) — delivery_status_not, status_not, has_pickup_point.
	//
	// NOT RUN BY THE WORKER THAT AUTHORED THESE TESTS, same as the rest of this file.
	// Every aggregate case below uses TWO carriers on purpose: an aggregate with a
	// single provider registered cannot see the OR-across-providers binding defect
	// (#837 defect 2's shape) — «carrier B's meta does not exist» is trivially true
	// of every carrier A order unless each negative clause is bound to its own
	// provider's marker. Each aggregate case below also includes an order with NO
	// meta key at all for the negated concept, per the
	// `a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all` gotcha — only
	// `NOT EXISTS` (LEFT JOIN) sees such a row; a lone `NOT IN` would silently drop it.
	// -------------------------------------------------------------------------------

	/**
	 * The `delivery_status_not` mirror of
	 * {@see self::test_delivery_status_unknown_on_the_aggregate_does_not_match_another_carriers_mapped_order()}:
	 * unbound, "carrier B's status is not DELIVERED" is trivially true of every
	 * carrier A order, because carrier B never writes carrier A's status meta.
	 */
	public function test_delivery_status_not_on_the_aggregate_does_not_match_another_carriers_excluded_order(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$a_marker = '_woodev_test_dsn_agg_a_marker';
		$a_status = '_woodev_test_dsn_agg_a_status';
		$b_marker = '_woodev_test_dsn_agg_b_marker';
		$b_status = '_woodev_test_dsn_agg_b_status';

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create(
				'dsn_agg_a',
				'DSN Aggregate A',
				$a_marker,
				[ 'dsn_agg_a' ],
				[
					'status_meta_key' => $a_status,
					'status_map'      => [
						'A_ACCEPTED' => Delivery_Status::IN_TRANSIT,
						'A_DONE'     => Delivery_Status::DELIVERED,
					],
				]
			)
		);
		$registry->register_provider(
			Orders_Provider::create(
				'dsn_agg_b',
				'DSN Aggregate B',
				$b_marker,
				[ 'dsn_agg_b' ],
				[
					'status_meta_key' => $b_status,
					'status_map'      => [ 'B_DONE' => Delivery_Status::DELIVERED ],
				]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// Carrier A, DELIVERED — must be excluded ("is not delivered" is false for it).
		$a_delivered = wc_create_order();
		$a_delivered->set_status( 'processing' );
		$a_delivered->update_meta_data( $a_marker, '1' );
		$a_delivered->update_meta_data( $a_status, 'A_DONE' );
		$a_delivered->save();

		// Carrier A, IN_TRANSIT — must be included (not delivered).
		$a_in_transit = wc_create_order();
		$a_in_transit->set_status( 'processing' );
		$a_in_transit->update_meta_data( $a_marker, '1' );
		$a_in_transit->update_meta_data( $a_status, 'A_ACCEPTED' );
		$a_in_transit->save();

		// Carrier A, no status meta at all — "unknown" also qualifies as "not delivered",
		// and only NOT EXISTS (not NOT IN) sees a row with no meta key at all.
		$a_no_status = wc_create_order();
		$a_no_status->set_status( 'processing' );
		$a_no_status->update_meta_data( $a_marker, '1' );
		$a_no_status->save();

		// Carrier B, DELIVERED, and carrier B never writes carrier A's status meta —
		// the regression row: unbound, carrier A's "not delivered" clause reads "A's
		// status meta does not exist", which is trivially true here.
		$b_delivered = wc_create_order();
		$b_delivered->set_status( 'processing' );
		$b_delivered->update_meta_data( $b_marker, '1' );
		$b_delivered->update_meta_data( $b_status, 'B_DONE' );
		$b_delivered->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'delivery_status_not', Delivery_Status::DELIVERED );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $a_in_transit->get_id(), $ids );
		$this->assertContains( $a_no_status->get_id(), $ids, 'no status meta at all still counts as "not delivered".' );
		$this->assertNotContains( $a_delivered->get_id(), $ids );
		$this->assertNotContains(
			$b_delivered->get_id(),
			$ids,
			'A delivered carrier-B order must not match "not delivered" just because carrier A wrote nothing on it.'
		);
	}

	/**
	 * The single-carrier scope: `delivery_status_not` excludes exactly the orders
	 * mapped to the requested state, keeps the ones mapped elsewhere, and keeps the
	 * one with no status meta at all (same NOT-EXISTS-vs-NOT-IN gotcha as the
	 * aggregate case above, without needing a second carrier to see it).
	 */
	public function test_delivery_status_not_single_carrier_excludes_the_mapped_state_but_keeps_the_rest(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker          = '_woodev_test_dsn_single_marker';
		$status_meta_key = '_woodev_test_dsn_single_status';

		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'dsn_single_carrier',
				'DSN Single Carrier',
				$marker,
				[ 'dsn_single_carrier' ],
				[
					'status_meta_key' => $status_meta_key,
					'status_map'      => [
						'SINGLE_DONE'  => Delivery_Status::DELIVERED,
						'SINGLE_GOING' => Delivery_Status::IN_TRANSIT,
					],
				]
			)
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$delivered_order = wc_create_order();
		$delivered_order->set_status( 'processing' );
		$delivered_order->update_meta_data( $marker, '1' );
		$delivered_order->update_meta_data( $status_meta_key, 'SINGLE_DONE' );
		$delivered_order->save();

		$transit_order = wc_create_order();
		$transit_order->set_status( 'processing' );
		$transit_order->update_meta_data( $marker, '1' );
		$transit_order->update_meta_data( $status_meta_key, 'SINGLE_GOING' );
		$transit_order->save();

		$no_status_order = wc_create_order();
		$no_status_order->set_status( 'processing' );
		$no_status_order->update_meta_data( $marker, '1' );
		$no_status_order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'dsn_single_carrier' );
		$request->set_param( 'delivery_status_not', Delivery_Status::DELIVERED );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $transit_order->get_id(), $ids );
		$this->assertContains( $no_status_order->get_id(), $ids, 'no status meta at all still counts as "not delivered".' );
		$this->assertNotContains( $delivered_order->get_id(), $ids );
	}

	/**
	 * `status_not` on the aggregate, across the two carriers registered in setUp —
	 * a native `status` arg, so unlike the meta-based filters above it needs no
	 * provider binding, but it must still exclude the requested status for BOTH
	 * carriers' orders, not just one.
	 */
	public function test_status_not_excludes_the_requested_status_across_both_carriers(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cdek_on_hold = $this->create_marked_order( self::CDEK_MARKER );
		$cdek_on_hold->set_status( 'on-hold' );
		$cdek_on_hold->save();

		$yandex_on_hold = $this->create_marked_order( self::YANDEX_MARKER );
		$yandex_on_hold->set_status( 'on-hold' );
		$yandex_on_hold->save();

		$cdek_processing = $this->create_marked_order( self::CDEK_MARKER );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'status_not', [ 'on-hold' ] );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $cdek_processing->get_id(), $ids );
		$this->assertNotContains( $cdek_on_hold->get_id(), $ids );
		$this->assertNotContains( $yandex_on_hold->get_id(), $ids );
	}

	/**
	 * The integration-level confirmation of the unit-pinned "excludes nothing"
	 * behaviour (#836): a `status_not` request in which nothing is a real status
	 * must NOT narrow the result — the honest reading is "no override", not
	 * "exclude the whole table" nor "exclude nothing recognized therefore keep the
	 * default view's cancelled/failed exclusion" (it is the FULL valid list).
	 */
	public function test_a_status_not_filter_with_nothing_recognized_excludes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cdek_order   = $this->create_marked_order( self::CDEK_MARKER );
		$yandex_order = $this->create_marked_order( self::YANDEX_MARKER );

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'status_not', [ 'not-a-real-status' ] );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $cdek_order->get_id(), $ids );
		$this->assertContains( $yandex_order->get_id(), $ids );
	}

	/**
	 * `has_pickup_point=true` on the aggregate, across two carriers each with their
	 * own pickup-point meta key.
	 */
	public function test_has_pickup_point_true_on_the_aggregate_scopes_to_orders_with_a_pickup_point(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$a_marker     = '_woodev_test_pickup_true_a_marker';
		$a_pickup_key = '_woodev_test_pickup_true_a_pickup_point';
		$b_marker     = '_woodev_test_pickup_true_b_marker';
		$b_pickup_key = '_woodev_test_pickup_true_b_pickup_point';

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create( 'pickup_true_a', 'Pickup True A', $a_marker, [ 'pickup_true_a' ], [ 'pickup_point_meta_key' => $a_pickup_key ] )
		);
		$registry->register_provider(
			Orders_Provider::create( 'pickup_true_b', 'Pickup True B', $b_marker, [ 'pickup_true_b' ], [ 'pickup_point_meta_key' => $b_pickup_key ] )
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$a_with_pickup = wc_create_order();
		$a_with_pickup->set_status( 'processing' );
		$a_with_pickup->update_meta_data( $a_marker, '1' );
		$a_with_pickup->update_meta_data( $a_pickup_key, [ 'address' => 'ПВЗ A' ] );
		$a_with_pickup->save();

		$a_without_pickup = wc_create_order();
		$a_without_pickup->set_status( 'processing' );
		$a_without_pickup->update_meta_data( $a_marker, '1' );
		$a_without_pickup->save();

		$b_with_pickup = wc_create_order();
		$b_with_pickup->set_status( 'processing' );
		$b_with_pickup->update_meta_data( $b_marker, '1' );
		$b_with_pickup->update_meta_data( $b_pickup_key, [ 'address' => 'ПВЗ B' ] );
		$b_with_pickup->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'has_pickup_point', true );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $a_with_pickup->get_id(), $ids );
		$this->assertContains( $b_with_pickup->get_id(), $ids );
		$this->assertNotContains( $a_without_pickup->get_id(), $ids );
	}

	/**
	 * The `has_pickup_point=false` mirror of
	 * {@see self::test_has_tracking_false_scopes_to_orders_without_the_tracking_meta()}'s
	 * aggregate regression: unbound, "carrier A's pickup-point key does not exist" is
	 * trivially true of every carrier B order, because carrier A never writes carrier
	 * B's meta. Also covers the no-meta-at-all row (NOT EXISTS vs NOT IN).
	 */
	public function test_has_pickup_point_false_on_the_aggregate_does_not_match_another_carriers_order(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$a_marker     = '_woodev_test_pickup_false_a_marker';
		$a_pickup_key = '_woodev_test_pickup_false_a_pickup_point';
		$b_marker     = '_woodev_test_pickup_false_b_marker';
		$b_pickup_key = '_woodev_test_pickup_false_b_pickup_point';

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create( 'pickup_false_a', 'Pickup False A', $a_marker, [ 'pickup_false_a' ], [ 'pickup_point_meta_key' => $a_pickup_key ] )
		);
		$registry->register_provider(
			Orders_Provider::create( 'pickup_false_b', 'Pickup False B', $b_marker, [ 'pickup_false_b' ], [ 'pickup_point_meta_key' => $b_pickup_key ] )
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// Carrier A, WITH a pickup point — must be excluded.
		$a_with_pickup = wc_create_order();
		$a_with_pickup->set_status( 'processing' );
		$a_with_pickup->update_meta_data( $a_marker, '1' );
		$a_with_pickup->update_meta_data( $a_pickup_key, [ 'address' => 'ПВЗ A' ] );
		$a_with_pickup->save();

		// Carrier A, no pickup-point meta at all — must be included (only NOT EXISTS,
		// via a LEFT JOIN, sees a row with no meta key at all).
		$a_without_pickup = wc_create_order();
		$a_without_pickup->set_status( 'processing' );
		$a_without_pickup->update_meta_data( $a_marker, '1' );
		$a_without_pickup->save();

		// Carrier B, WITH a pickup point, and carrier B never writes carrier A's
		// pickup-point meta — the regression row: unbound, carrier A's "no pickup
		// point" clause reads "A's pickup-point meta does not exist", trivially true
		// here, and would incorrectly let this row through.
		$b_with_pickup = wc_create_order();
		$b_with_pickup->set_status( 'processing' );
		$b_with_pickup->update_meta_data( $b_marker, '1' );
		$b_with_pickup->update_meta_data( $b_pickup_key, [ 'address' => 'ПВЗ B' ] );
		$b_with_pickup->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'has_pickup_point', false );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $a_without_pickup->get_id(), $ids );
		$this->assertNotContains( $a_with_pickup->get_id(), $ids );
		$this->assertNotContains(
			$b_with_pickup->get_id(),
			$ids,
			'A carrier-B order with its own pickup point must not match "no pickup point" just because carrier A wrote nothing on it.'
		);
	}

	// SP-10 #841 — is_exported ("new orders" — never exported to the carrier).

	/**
	 * `is_exported=true` on the aggregate, across two carriers each with their own
	 * carrier-order-id meta key.
	 */
	public function test_is_exported_true_on_the_aggregate_scopes_to_orders_with_a_carrier_order_id(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$a_marker             = '_woodev_test_exported_true_a_marker';
		$a_carrier_order_id_key = '_woodev_test_exported_true_a_carrier_order_id';
		$b_marker             = '_woodev_test_exported_true_b_marker';
		$b_carrier_order_id_key = '_woodev_test_exported_true_b_carrier_order_id';

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create( 'exported_true_a', 'Exported True A', $a_marker, [ 'exported_true_a' ], [ 'carrier_order_id_meta_key' => $a_carrier_order_id_key ] )
		);
		$registry->register_provider(
			Orders_Provider::create( 'exported_true_b', 'Exported True B', $b_marker, [ 'exported_true_b' ], [ 'carrier_order_id_meta_key' => $b_carrier_order_id_key ] )
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$a_exported = wc_create_order();
		$a_exported->set_status( 'processing' );
		$a_exported->update_meta_data( $a_marker, '1' );
		$a_exported->update_meta_data( $a_carrier_order_id_key, 'CDEK-000123' );
		$a_exported->save();

		$a_new = wc_create_order();
		$a_new->set_status( 'processing' );
		$a_new->update_meta_data( $a_marker, '1' );
		$a_new->save();

		$b_exported = wc_create_order();
		$b_exported->set_status( 'processing' );
		$b_exported->update_meta_data( $b_marker, '1' );
		$b_exported->update_meta_data( $b_carrier_order_id_key, 'YANDEX-000456' );
		$b_exported->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'is_exported', true );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $a_exported->get_id(), $ids );
		$this->assertContains( $b_exported->get_id(), $ids );
		$this->assertNotContains( $a_new->get_id(), $ids );
	}

	/**
	 * The `is_exported=false` mirror — the "new orders" view. Same aggregate
	 * regression shape as
	 * {@see self::test_has_pickup_point_false_on_the_aggregate_does_not_match_another_carriers_order()}:
	 * unbound, "carrier A's carrier-order-id key does not exist" is trivially true of
	 * every carrier B order. Also covers the no-meta-at-all row (NOT EXISTS vs NOT IN).
	 */
	public function test_is_exported_false_on_the_aggregate_does_not_match_another_carriers_order(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$a_marker             = '_woodev_test_exported_false_a_marker';
		$a_carrier_order_id_key = '_woodev_test_exported_false_a_carrier_order_id';
		$b_marker             = '_woodev_test_exported_false_b_marker';
		$b_carrier_order_id_key = '_woodev_test_exported_false_b_carrier_order_id';

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create( 'exported_false_a', 'Exported False A', $a_marker, [ 'exported_false_a' ], [ 'carrier_order_id_meta_key' => $a_carrier_order_id_key ] )
		);
		$registry->register_provider(
			Orders_Provider::create( 'exported_false_b', 'Exported False B', $b_marker, [ 'exported_false_b' ], [ 'carrier_order_id_meta_key' => $b_carrier_order_id_key ] )
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// Carrier A, WITH a carrier-order-id — must be excluded.
		$a_exported = wc_create_order();
		$a_exported->set_status( 'processing' );
		$a_exported->update_meta_data( $a_marker, '1' );
		$a_exported->update_meta_data( $a_carrier_order_id_key, 'CDEK-000789' );
		$a_exported->save();

		// Carrier A, no carrier-order-id meta at all — the "new" order — must be
		// included (only NOT EXISTS, via a LEFT JOIN, sees a row with no meta key at all).
		$a_new = wc_create_order();
		$a_new->set_status( 'processing' );
		$a_new->update_meta_data( $a_marker, '1' );
		$a_new->save();

		// Carrier B, WITH a carrier-order-id, and carrier B never writes carrier A's
		// carrier-order-id meta — the regression row: unbound, carrier A's "not
		// exported" clause reads "A's carrier-order-id meta does not exist", trivially
		// true here, and would incorrectly let this row through.
		$b_exported = wc_create_order();
		$b_exported->set_status( 'processing' );
		$b_exported->update_meta_data( $b_marker, '1' );
		$b_exported->update_meta_data( $b_carrier_order_id_key, 'YANDEX-000987' );
		$b_exported->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'is_exported', false );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_column( $response->get_data()['rows'], 'id' );

		$this->assertContains( $a_new->get_id(), $ids );
		$this->assertNotContains( $a_exported->get_id(), $ids );
		$this->assertNotContains(
			$b_exported->get_id(),
			$ids,
			'A carrier-B order with its own carrier-order-id must not match "not exported" just because carrier A wrote nothing on it.'
		);
	}

	/**
	 * #860, on a REAL database: an order whose carrier-order-id meta EXISTS but is the
	 * EMPTY STRING is NOT exported, and belongs in «Новые».
	 *
	 * ⚠ This population is the whole of #860 and neither sibling test above covers it —
	 * they only contrast "has an id" against "has no such meta row at all".
	 * `Abstract_Shipment_Handler::export()` writes the meta UNCONDITIONALLY, including
	 * the empty string when the carrier answered without an id, so a FAILED export
	 * created exactly this row and `EXISTS` counted it as exported: the order dropped
	 * out of «Новые» permanently and the row offered «Обновить»/«Отменить» for a
	 * shipment that was never created.
	 *
	 * It has to be an integration test rather than a unit one because the claim is about
	 * what `WP_Meta_Query` COMPILES to: the positive clause is `!=` against an INNER
	 * JOIN (a row with no meta at all must not match) and the negative one ORs
	 * `NOT EXISTS` with `= ''` on the same key (which must still match a row with no
	 * meta at all, via the LEFT JOIN the `NOT EXISTS` branch forces). A mocked query
	 * asserts the array we built, not the SQL WordPress builds from it — and this suite
	 * runs on the legacy CPT datastore, which is the half the HPOS rig can never prove.
	 */
	public function test_an_empty_carrier_order_id_is_not_exported_on_both_sides_of_the_filter(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$marker               = '_woodev_test_empty_id_marker';
		$carrier_order_id_key = '_woodev_test_empty_id_carrier_order_id';

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create( 'empty_id_a', 'Empty Id A', $marker, [ 'empty_id_a' ], [ 'carrier_order_id_meta_key' => $carrier_order_id_key ] )
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		// The #860 row: export ran, the carrier answered with no id, the meta was
		// written as ''. Not exported.
		$failed_export = wc_create_order();
		$failed_export->set_status( 'processing' );
		$failed_export->update_meta_data( $marker, '1' );
		$failed_export->update_meta_data( $carrier_order_id_key, '' );
		$failed_export->save();

		// Never exported at all — no such meta row. Also not exported.
		$never = wc_create_order();
		$never->set_status( 'processing' );
		$never->update_meta_data( $marker, '1' );
		$never->save();

		// Genuinely exported.
		$exported = wc_create_order();
		$exported->set_status( 'processing' );
		$exported->update_meta_data( $marker, '1' );
		$exported->update_meta_data( $carrier_order_id_key, 'CDEK-42' );
		$exported->save();

		$new_request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$new_request->set_param( 'is_exported', false );

		$new_response = rest_get_server()->dispatch( $new_request );
		$this->assertSame( 200, $new_response->get_status() );

		$new_ids = array_column( $new_response->get_data()['rows'], 'id' );

		$this->assertContains(
			$failed_export->get_id(),
			$new_ids,
			'#860: a failed export stored an empty carrier order id — the order is still new.'
		);
		$this->assertContains( $never->get_id(), $new_ids );
		$this->assertNotContains( $exported->get_id(), $new_ids );

		$exported_request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$exported_request->set_param( 'is_exported', true );

		$exported_response = rest_get_server()->dispatch( $exported_request );
		$this->assertSame( 200, $exported_response->get_status() );

		$exported_ids = array_column( $exported_response->get_data()['rows'], 'id' );

		// The two filters must be exact complements — no row in both, none in neither.
		$this->assertContains( $exported->get_id(), $exported_ids );
		$this->assertNotContains(
			$failed_export->get_id(),
			$exported_ids,
			'#860: the empty-id row must not appear on BOTH sides of the filter.'
		);
		$this->assertNotContains( $never->get_id(), $exported_ids );
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

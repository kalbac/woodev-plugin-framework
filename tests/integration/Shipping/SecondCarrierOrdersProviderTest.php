<?php
/**
 * Integration: SP-10 #830's second carrier — the exact `status_map`/`status_labels`
 * vocabulary `Woodev_Test_Shipping_Method_Plugin::init_test_shipping_orders_page()`
 * registers on the rig (`tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php`),
 * proven end to end through the real REST route.
 *
 * Before #830, `grep -rln "Orders_Provider" tests/_fixtures/` found exactly one file
 * (the `realistic` fixture) — the rig's «Заказы доставки» page ran the degenerate
 * single-carrier case, and the operator, who accepts this page with his eyes, could
 * not see the carrier `FilterPicker`, D6's counter SUM/breakdown, M2's
 * `relation => OR` aggregate, or D4's canonical status over two DIFFERENT raw
 * vocabularies. `OrdersRestTest` already covers the OR-aggregate MECHANISM with
 * synthetic carriers; this file pins the actual SECOND-CARRIER VOCABULARY this
 * change ships — its own marker key, its own raw status words (deliberately unlike
 * `realistic`'s `NEW`/`ACCEPTED`/…), and its own analogous "raw value with no
 * canonical equivalent" gap (`LOST_IN_TRANSIT`, distinct from `realistic`'s
 * `CUSTOMS_HOLD`) — the SAME literal values the real fixture registers, so a typo
 * between this test and the fixture file is exactly the kind of thing this test
 * exists to catch.
 *
 * The provider is registered HERE, ad hoc, mirroring every other
 * `Orders_Registry::register_provider()` call in `OrdersRestTest` — not read off the
 * ambient bootstrap-time registration, because `Orders_Registry` is a process-wide
 * singleton other integration test files (`OrdersRestTest`) reset for their OWN
 * isolated state, and this repo's own convention (see `tests/integration/TestCase.php`'s
 * docblock on `Location_Provider_Registry`) is: a test that cares about registry
 * CONTENTS resets and builds its own known state, never relies on load order across
 * the whole suite.
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

class SecondCarrierOrdersProviderTest extends TestCase {

	/** @var string must match the fixture's own marker_meta_key. */
	private const MARKER = '_woodev_test_shipping_marker';

	/** @var string must match the fixture's own status_meta_key. */
	private const STATUS_META_KEY = '_woodev_test_shipping_status';

	/** @var string must match the fixture's own tracking_meta_key. */
	private const TRACKING_META_KEY = '_woodev_test_shipping_tracking_number';

	/**
	 * Registers a provider carrying the EXACT descriptor
	 * `init_test_shipping_orders_page()` registers on the rig, and rebuilds the REST
	 * server so the orders controller re-registers its routes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create(
				'test_shipping',
				'Тестовая доставка',
				self::MARKER,
				[ 'woodev_test_shipping' ],
				[
					'status_meta_key'       => self::STATUS_META_KEY,
					'status_map'            => [
						'CREATED'             => Delivery_Status::CREATED,
						'PICKED_UP'            => Delivery_Status::IN_TRANSIT,
						'ON_THE_WAY'           => Delivery_Status::IN_TRANSIT,
						'ARRIVED_PVZ'          => Delivery_Status::READY_FOR_PICKUP,
						'HANDED_TO_CLIENT'     => Delivery_Status::DELIVERED,
						'RETURN_STARTED'       => Delivery_Status::RETURNING,
						'RETURNED_TO_SENDER'   => Delivery_Status::RETURNED,
						'CANCELLED_BY_CLIENT'  => Delivery_Status::CANCELLED,
						// 'LOST_IN_TRANSIT' is INTENTIONALLY absent — see the fixture's own docblock.
					],
					'status_labels'         => [
						'CREATED'             => 'Создан',
						'PICKED_UP'           => 'Забран у отправителя',
						'ON_THE_WAY'          => 'В пути',
						'ARRIVED_PVZ'         => 'Прибыл в пункт выдачи',
						'HANDED_TO_CLIENT'    => 'Вручён получателю',
						'RETURN_STARTED'      => 'Оформлен возврат',
						'RETURNED_TO_SENDER'  => 'Возвращён отправителю',
						'CANCELLED_BY_CLIENT' => 'Отменён клиентом',
						'LOST_IN_TRANSIT'     => 'Утерян при перевозке',
					],
					'tracking_meta_key'     => self::TRACKING_META_KEY,
					'tracking_url_template' => 'https://testcarrier.example.test/track/{tracking}',
					'pickup_point_meta_key' => '_woodev_test_shipping_pickup_point',
				]
			)
		);

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
	 * The status this carrier and `realistic` deliberately share the SAME meaning
	 * for ('in transit'), reached through two DIFFERENT raw words — proves D4's
	 * canonical mapping actually inverts a carrier-specific vocabulary rather than
	 * happening to pass through a shared one.
	 */
	public function test_mapped_raw_status_resolves_to_the_canonical_state_end_to_end(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( self::MARKER, '1' );
		$order->update_meta_data( self::STATUS_META_KEY, 'ON_THE_WAY' );
		$order->update_meta_data( self::TRACKING_META_KEY, 'TESTCARRIER-000123' );
		$order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'test_shipping' );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$rows = $response->get_data()['rows'];
		$this->assertCount( 1, $rows );
		$row = $rows[0];

		$this->assertSame(
			[
				'canonical'       => Delivery_Status::IN_TRANSIT,
				'canonical_label' => Delivery_Status::label( Delivery_Status::IN_TRANSIT ),
				'raw'             => 'ON_THE_WAY',
				'raw_label'       => 'В пути',
			],
			$row['delivery_status']
		);

		$this->assertSame( 'TESTCARRIER-000123', $row['tracking']['number'] );
		$this->assertSame( 'https://testcarrier.example.test/track/TESTCARRIER-000123', $row['tracking']['url'] );
	}

	/**
	 * This carrier's OWN gap — `LOST_IN_TRANSIT`, present in `status_labels` but
	 * absent from `status_map` — must resolve to `Delivery_Status::UNKNOWN`, never a
	 * guessed canonical state (D4's single most important behaviour), on a raw
	 * value DIFFERENT from `realistic`'s own `CUSTOMS_HOLD` gap.
	 */
	public function test_this_carriers_own_unmapped_raw_status_resolves_to_unknown_end_to_end(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( self::MARKER, '1' );
		$order->update_meta_data( self::STATUS_META_KEY, 'LOST_IN_TRANSIT' );
		$order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'test_shipping' );

		$response = rest_get_server()->dispatch( $request );
		$row      = $response->get_data()['rows'][0];

		$this->assertSame( Delivery_Status::UNKNOWN, $row['delivery_status']['canonical'] );
		$this->assertSame( 'LOST_IN_TRANSIT', $row['delivery_status']['raw'] );
		$this->assertSame( 'Утерян при перевозке', $row['delivery_status']['raw_label'] );
	}

	/**
	 * `type` resolved from a REAL shipping-zone `woodev_test_shipping` method
	 * instance — `get_delivery_type()` on that method returns `'pickup'` (issue
	 * #709) — the same D3 assertion `OrdersRestTest::test_full_row_shape_and_status_mapping_end_to_end()`
	 * makes, pinned again here against THIS carrier's own provider registration.
	 */
	public function test_type_resolves_from_a_real_shipping_zone_method_instance(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$instance_id = $this->zone_method_instance_id();

		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( self::MARKER, '1' );

		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_title( 'Тестовая доставка' );
		$shipping_item->set_method_id( 'woodev_test_shipping' );
		$shipping_item->set_instance_id( $instance_id );
		$shipping_item->set_total( '0' );
		$order->add_item( $shipping_item );
		$order->save();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$request->set_param( 'carrier', 'test_shipping' );

		$response = rest_get_server()->dispatch( $request );
		$row      = $response->get_data()['rows'][0];

		$this->assertSame( 'pickup', $row['type'] );
	}

	/**
	 * The M2 measurement's actual subject, pinned against THIS carrier: a second,
	 * unrelated marker key must NOT contaminate `test_shipping`'s single-carrier
	 * scope, and the aggregate (no `carrier` param) must still return both — the
	 * SAME `relation => OR` behaviour `OrdersRestTest` already proves generically,
	 * here proven with this carrier's own real marker key on one side.
	 */
	public function test_single_carrier_scope_excludes_a_second_unrelated_marker(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		Orders_Registry::instance()->register_provider(
			Orders_Provider::create( 'unrelated_carrier', 'Unrelated', '_woodev_test_unrelated_marker', [ 'unrelated_carrier' ] )
		);

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$mine = wc_create_order();
		$mine->set_status( 'processing' );
		$mine->update_meta_data( self::MARKER, '1' );
		$mine->save();

		$other = wc_create_order();
		$other->set_status( 'processing' );
		$other->update_meta_data( '_woodev_test_unrelated_marker', '1' );
		$other->save();

		$scoped_request = new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' );
		$scoped_request->set_param( 'carrier', 'test_shipping' );
		$scoped_ids = array_column( rest_get_server()->dispatch( $scoped_request )->get_data()['rows'], 'id' );

		$this->assertContains( $mine->get_id(), $scoped_ids );
		$this->assertNotContains( $other->get_id(), $scoped_ids );

		$aggregate_ids = array_column(
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/shipping/orders' ) )->get_data()['rows'],
			'id'
		);

		$this->assertContains( $mine->get_id(), $aggregate_ids );
		$this->assertContains( $other->get_id(), $aggregate_ids );
	}

	/**
	 * Adds the ambient `woodev_test_shipping` fixture method to a real shipping
	 * zone and returns its instance id — same pattern as
	 * `OrdersRestTest::zone_method_instance_id()`.
	 *
	 * @return int
	 */
	private function zone_method_instance_id(): int {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'SP-10 #830 second-carrier orders test zone' );
		$zone->save();

		return $zone->add_shipping_method( 'woodev_test_shipping' );
	}
}

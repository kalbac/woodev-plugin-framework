<?php
/**
 * Integration: the admin order wizard's REST transport contract — create, update, load — on BOTH
 * WooCommerce order datastores (#710 spec D4 / D5, card #968).
 *
 * The declared responses, dispatched through the real REST server against the real fixture
 * carriers: 401 not logged in, 403 lacking `edit_shop_orders`, 404 unknown order or not a row of
 * the orders page, 409 not editable (exported meanwhile / final status), 422 validation errors as
 * data, 201 / 200 with the declared bodies. Object-level checks are exercised on every `{id}` route.
 * The service's own behaviour (what lands on the order) is {@see OrderEditorDatastoresTest}.
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE — the coordinator runs the integration suite.
 * How both datastores run in one process: see {@see OrderPersistenceDatastoresTest}.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Order_Marker;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class OrderEditorRestTest extends TestCase {

	private const NAMESPACE_ROOT = '/woodev/v1/shipping/orders';
	private const MARKER         = '_woodev_realistic_shipping_marker';
	private const EXPORTED       = '_woodev_test_shipping_carrier_order_id';

	/** @var int the tests' product. */
	private $product_id = 0;

	/**
	 * Registers the fixtures' own providers on a clean registry and rebuilds the REST server, so
	 * the controller (registered on `rest_api_init`) adds its routes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\Woodev_Test_Shipping_Method_Plugin' ) || ! class_exists( '\Woodev_Realistic_Shipping_Plugin' ) ) {
			$this->markTestSkipped( 'The shipping fixture plugins are not loaded.' );
		}

		Orders_Registry::instance()->reset_for_tests();

		$this->register_fixture_provider( \Woodev_Test_Shipping_Method_Plugin::instance(), 'init_test_shipping_orders_page' );
		$this->register_fixture_provider( \Woodev_Realistic_Shipping_Plugin::instance(), 'init_realistic_orders_page' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Товар мастера заказов' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '100' );
		$this->product_id = $product->save();

		// A fresh REST server, so `rest_api_init` fires with the re-added hook.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Runs a fixture's private orders-page registration.
	 *
	 * @param object $plugin fixture plugin instance.
	 * @param string $method its private registration method.
	 * @return void
	 */
	private function register_fixture_provider( $plugin, string $method ): void {
		$reflection = new \ReflectionMethod( $plugin, $method );

		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		$reflection->invoke( $plugin );
	}

	/** @return array<string,array{0:bool}> */
	public function datastore_provider(): array {
		return [
			'HPOS'       => [ true ],
			'legacy CPT' => [ false ],
		];
	}

	/**
	 * @param bool $hpos true => HPOS; false => legacy CPT.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * @return void
	 */
	private function login_as_manager(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Writes the native lock for the selected datastore.
	 *
	 * @param int $order_id order to lock.
	 * @param int $user_id  manager who owns the lock.
	 * @param int $time     lock timestamp.
	 * @return void
	 */
	private function set_edit_lock( int $order_id, int $user_id, int $time, bool $hpos ): void {
		if ( ! $hpos ) {
			update_post_meta( $order_id, '_edit_lock', $time . ':' . $user_id );

			return;
		}

		$order = wc_get_order( $order_id );

		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->update_meta_data( '_edit_lock', $time . ':' . $user_id );
		$order->save_meta_data();
	}

	/**
	 * Reads the native lock for the selected datastore.
	 *
	 * @param int  $order_id order id.
	 * @param bool $hpos     active datastore.
	 * @return string
	 */
	private function get_edit_lock( int $order_id, bool $hpos ): string {
		if ( ! $hpos ) {
			return (string) get_post_meta( $order_id, '_edit_lock', true );
		}

		$order = wc_get_order( $order_id );

		$this->assertInstanceOf( \WC_Order::class, $order );

		return (string) $order->get_meta( '_edit_lock', true );
	}

	/**
	 * The body the wizard sends for a courier delivery.
	 *
	 * @param array<string,mixed> $override top-level keys to replace.
	 * @return array<string,mixed>
	 */
	private function payload( array $override = [] ): array {
		return array_replace(
			[
				'customer'       => [ 'id' => 0 ],
				'billing'        => [
					'first_name' => 'Иван',
					'country'    => 'RU',
					'state'      => 'Москва',
					'city'       => 'Москва',
					'address_1'  => 'ул. Тверская, 1',
					'postcode'   => '125009',
					'email'      => 'ivan-968-rest@example.test',
				],
				'items'          => [
					[
						'product_id' => $this->product_id,
						'quantity'   => 1,
						'price'      => '150',
					],
				],
				'shipping_line'  => [
					'method_id'   => 'woodev_realistic_shipping',
					'instance_id' => 6,
					'label'       => 'Курьер',
					'cost'        => '350',
				],
				'payment_method' => 'cod',
				'status'         => 'pending',
			],
			$override
		);
	}

	/**
	 * Dispatches one request with a JSON body, as the page's `apiFetch` sends it.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  route below `/woodev/v1`.
	 * @param array<string,mixed> $body   JSON body.
	 * @return \WP_REST_Response
	 */
	private function send( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( [] !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Creates a marked order through the route and returns its id.
	 *
	 * @param array<string,mixed> $override payload overrides.
	 * @return int
	 */
	private function create_through_the_route( array $override = [] ): int {
		$response = $this->send( 'POST', self::NAMESPACE_ROOT, $this->payload( $override ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return (int) $response->get_data()['id'];
	}

	// -------------------------------------------------------------------------
	// routes + auth
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function test_the_three_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey( self::NAMESPACE_ROOT, $routes );
		$this->assertArrayHasKey( self::NAMESPACE_ROOT . '/(?P<id>\d+)', $routes );
		$this->assertArrayHasKey( self::NAMESPACE_ROOT . '/(?P<id>\d+)/edit', $routes );

		$methods = [];

		foreach ( $routes[ self::NAMESPACE_ROOT ] as $endpoint ) {
			$methods = array_merge( $methods, array_keys( $endpoint['methods'] ) );
		}

		$this->assertContains( 'GET', $methods, 'the list route of Orders_Controller must survive the merge' );
		$this->assertContains( 'POST', $methods, 'the create route shares its path' );
	}

	/**
	 * @return void
	 */
	public function test_a_visitor_who_is_not_logged_in_gets_401_on_every_route(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->send( 'POST', self::NAMESPACE_ROOT, $this->payload() )->get_status() );
		$this->assertSame( 401, $this->send( 'PUT', self::NAMESPACE_ROOT . '/1', $this->payload() )->get_status() );
		$this->assertSame( 401, $this->send( 'GET', self::NAMESPACE_ROOT . '/1/edit' )->get_status() );
	}

	/**
	 * @return void
	 */
	public function test_a_user_without_edit_shop_orders_gets_403_on_every_route(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertSame( 403, $this->send( 'POST', self::NAMESPACE_ROOT, $this->payload() )->get_status() );
		$this->assertSame( 403, $this->send( 'PUT', self::NAMESPACE_ROOT . '/1', $this->payload() )->get_status() );
		$this->assertSame( 403, $this->send( 'GET', self::NAMESPACE_ROOT . '/1/edit' )->get_status() );
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_answers_201_with_the_declared_body_and_a_marked_order( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$response = $this->send( 'POST', self::NAMESPACE_ROOT, $this->payload() );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( [ 'id', 'number', 'message' ], array_keys( $data ) );
		$this->assertStringContainsString( (string) $data['number'], $data['message'] );

		$order = wc_get_order( $data['id'] );

		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::MARKER, true ) ) );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_answers_422_with_the_problems_as_data_and_writes_nothing( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$before = count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) );

		$response = $this->send( 'POST', self::NAMESPACE_ROOT, $this->payload( [ 'items' => [], 'payment_method' => 'no-such-gateway' ] ) );
		$data     = $response->get_data();

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'woodev_shipping_order_invalid', $data['code'] );
		$this->assertSame( 422, $data['data']['status'] );

		$fields = array_column( $data['data']['errors'], 'field' );

		$this->assertContains( 'items', $fields );
		$this->assertContains( 'payment_method', $fields );

		foreach ( $data['data']['errors'] as $error ) {
			$this->assertSame( [ 'field', 'code', 'message' ], array_keys( $error ) );
		}

		$this->assertSame( $before, count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ) );
	}

	// -------------------------------------------------------------------------
	// update + load
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_load_then_update_round_trips_with_200( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$id = $this->create_through_the_route();

		$loaded = $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' );

		$this->assertSame( 200, $loaded->get_status(), wp_json_encode( $loaded->get_data() ) );
		$this->assertSame( $id, $loaded->get_data()['order']['id'] );
		$this->assertSame( 'realistic', $loaded->get_data()['carrier'] );

		$body = $loaded->get_data();

		$body['items'][0]['quantity'] = 4;

		$updated = $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $body );

		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( $id, $updated->get_data()['id'] );
		$this->assertSame( 4, (int) array_values( wc_get_order( $id )->get_items( 'line_item' ) )[0]->get_quantity() );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_load_takes_woocommerces_shared_edit_lock( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$manager_id = $this->login_as_manager();
		$id         = $this->create_through_the_route();

		$this->assertSame( 200, $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' )->get_status() );
		$lock = explode( ':', $this->get_edit_lock( $id, $hpos ) );

		$this->assertCount( 2, $lock );
		$this->assertGreaterThanOrEqual( time() - 2, (int) $lock[0] );
		$this->assertSame( (string) $manager_id, $lock[1] );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_another_managers_live_edit_lock_refuses_update_with_409( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();
		$id       = $this->create_through_the_route();
		$other_id = self::factory()->user->create( [ 'role' => 'shop_manager', 'display_name' => 'Мария' ] );
		$this->set_edit_lock( $id, $other_id, time(), $hpos );

		$response = $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $this->payload() );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woodev_shipping_order_locked', $response->get_data()['code'] );
		$this->assertSame( 'This order is already being edited by Мария', $response->get_data()['message'] );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_expired_edit_lock_is_ignored_and_replaced_by_the_loading_manager( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$manager_id = $this->login_as_manager();
		$id         = $this->create_through_the_route();
		$other_id   = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		$this->set_edit_lock( $id, $other_id, time() - 151, $hpos );

		$this->assertSame( 200, $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' )->get_status() );
		$lock = explode( ':', $this->get_edit_lock( $id, $hpos ) );

		$this->assertCount( 2, $lock );
		$this->assertSame( (string) $manager_id, $lock[1] );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_lock_owner_can_update_its_own_live_lock( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$manager_id = $this->login_as_manager();
		$id         = $this->create_through_the_route();
		$this->set_edit_lock( $id, $manager_id, time(), $hpos );

		$this->assertSame( 200, $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $this->payload() )->get_status() );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_heartbeat_refreshes_the_native_edit_lock_on_both_datastores( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$manager_id = $this->login_as_manager();
		$id         = $this->create_through_the_route();
		$this->set_edit_lock( $id, $manager_id, time() - 100, $hpos );

		$response = apply_filters( 'heartbeat_received', [], [ 'wc-refresh-order-lock' => $id ], 'woocommerce_page_wc-orders' );
		$lock     = explode( ':', $this->get_edit_lock( $id, $hpos ) );

		$this->assertTrue( $response['wc-refresh-order-lock']['lock'], 'the framework heartbeat handler must refresh through its datastore seam' );
		$this->assertCount( 2, $lock );
		$this->assertGreaterThanOrEqual( time() - 2, (int) $lock[0] );
		$this->assertSame( (string) $manager_id, $lock[1] );
	}

	// -------------------------------------------------------------------------
	// the carrier's own fields (#973, spec D7)
	// -------------------------------------------------------------------------

	/**
	 * The realistic fixture declares one order field — `declared_value`, a number with a floor of
	 * zero, stored under `_woodev_realistic_declared_value`.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_stores_the_declared_carrier_fields_and_drops_what_was_not_declared( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$id    = $this->create_through_the_route( [ 'carrier_fields' => [ 'declared_value' => '1500.5', 'not_declared' => 'x' ] ] );
		$order = wc_get_order( $id );

		$this->assertEquals( 1500.5, $order->get_meta( '_woodev_realistic_declared_value', true ) );
		$this->assertSame( '', (string) $order->get_meta( 'not_declared', true ), 'an id the carrier did not declare never reaches the order' );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_answers_422_on_a_carrier_field_the_declaration_refuses( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$response = $this->send( 'POST', self::NAMESPACE_ROOT, $this->payload( [ 'carrier_fields' => [ 'declared_value' => '-1' ] ] ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertContains( 'carrier_fields.declared_value', array_column( $response->get_data()['data']['errors'], 'field' ) );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_load_returns_the_stored_carrier_fields_and_an_update_changes_or_clears_them( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$id = $this->create_through_the_route( [ 'carrier_fields' => [ 'declared_value' => '1500.5' ] ] );

		$body = $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' )->get_data();

		$this->assertEquals( [ 'declared_value' => 1500.5 ], $body['carrier_fields'] );

		$body['carrier_fields']['declared_value'] = '99';

		$this->assertSame( 200, $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $body )->get_status() );
		$this->assertEquals( 99, wc_get_order( $id )->get_meta( '_woodev_realistic_declared_value', true ) );

		$body['carrier_fields']['declared_value'] = '';

		$this->assertSame( 200, $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $body )->get_status() );
		$this->assertSame( '', (string) wc_get_order( $id )->get_meta( '_woodev_realistic_declared_value', true ), 'a cleared field is removed, not stored empty' );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_answers_422_on_an_invalid_payload_and_leaves_the_order_alone( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$id = $this->create_through_the_route();

		$response = $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $this->payload( [ 'items' => [] ] ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertCount( 1, wc_get_order( $id )->get_items( 'line_item' ) );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_unknown_order_and_a_plain_woocommerce_order_are_404_on_every_id_route( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$plain = wc_create_order();
		$plain->save();

		foreach ( [ 99999999, $plain->get_id() ] as $id ) {
			$this->assertSame( 404, $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $this->payload() )->get_status(), (string) $id );
			$this->assertSame( 404, $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' )->get_status(), (string) $id );
		}
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_exported_order_is_409_on_update_and_load_with_the_reason( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$id = $this->create_through_the_route(
			[
				'shipping_line' => [
					'method_id'   => 'woodev_test_shipping',
					'instance_id' => 3,
					'cost'        => '100',
				],
				'pickup_point'  => [ 'id' => 'PVZ-968' ],
			]
		);

		$order = wc_get_order( $id );
		$order->update_meta_data( self::EXPORTED, 'CARRIER-968' );
		$order->save();

		$update = $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $this->payload() );

		$this->assertSame( 409, $update->get_status() );
		$this->assertSame( 'woodev_shipping_order_not_editable', $update->get_data()['code'] );
		$this->assertStringContainsString( 'отмените выгрузку', $update->get_data()['message'] );

		$this->assertSame( 409, $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' )->get_status() );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_final_status_is_409( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$id = $this->create_through_the_route();

		$order = wc_get_order( $id );
		$order->set_status( 'completed' );
		$order->save();

		$this->assertSame( 409, $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $this->payload() )->get_status() );
		$this->assertSame( 409, $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' )->get_status() );
	}

	// -------------------------------------------------------------------------
	// row action (#710 bug 6, operator rig acceptance 28.09.2026)
	// -------------------------------------------------------------------------

	/**
	 * A fresh REALISTIC order — on-hold, not exported, created through the wizard exactly as the
	 * operator's order 642 was on the rig — must show «Редактировать» in its row, exactly like a
	 * `test_shipping` order in the same state does
	 * ({@see \Woodev\Tests\Integration\Shipping\OrdersRestTest::test_the_row_offers_edit_while_the_order_is_editable_and_the_action_route_never_executes_it()}).
	 *
	 * Before the fix this failed: the realistic fixture registered a provider and a tracking
	 * handler but no `Abstract_Shipment_Handler`, and `Order_Actions::for_row()` withholds every
	 * action — including the client-side «Редактировать», which calls no handler at all — from a
	 * provider with none registered (spec D5 names no such condition; only the load/update routes'
	 * own {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::not_editable_reason()} does).
	 *
	 * @return void
	 */
	public function test_a_realistic_order_shows_the_edit_row_action_while_editable(): void {
		$this->login_as_manager();

		$id = $this->create_through_the_route();

		$order = wc_get_order( $id );
		$order->set_status( 'on-hold' );
		$order->save();

		$response = $this->send( 'GET', self::NAMESPACE_ROOT );
		$this->assertSame( 200, $response->get_status() );

		$row = null;

		foreach ( $response->get_data()['rows'] as $candidate ) {
			if ( $id === $candidate['id'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row, 'the created order must be a row of the page' );
		$this->assertContains(
			'edit',
			array_column( $row['actions'], 'action' ),
			'a realistic-carrier order must offer «Редактировать» exactly like a test_shipping one does'
		);
	}

	/**
	 * #988: «Редактировать» depends ONLY on the D5 policy, never on a registered shipment handler —
	 * a carrier with tariffs but no export through its API keeps the wizard's edit route.
	 *
	 * Re-registering a provider under its own id drops its shipment handler
	 * ({@see Orders_Registry::register_provider()}), which leaves the realistic carrier exactly as
	 * such a carrier is: a row of the page, no handler.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_carrier_without_a_shipment_handler_still_edits( bool $hpos ): void {
		$this->use_datastore( $hpos );
		$this->login_as_manager();

		$registry = Orders_Registry::instance();
		$registry->register_provider( $registry->get_provider( 'realistic' ), \Woodev_Realistic_Shipping_Plugin::instance() );

		$this->assertNull( $registry->get_shipment_handler( 'realistic' ), 'the carrier under test has no shipment handler' );

		$id = $this->create_through_the_route();

		$order = wc_get_order( $id );
		$order->set_status( 'on-hold' );
		$order->save();

		$response = $this->send( 'GET', self::NAMESPACE_ROOT );
		$this->assertSame( 200, $response->get_status() );

		$row = null;

		foreach ( $response->get_data()['rows'] as $candidate ) {
			if ( $id === $candidate['id'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row, 'the created order must be a row of the page' );
		$this->assertSame( [ 'edit' ], array_column( $row['actions'], 'action' ), 'edit, and none of the carrier actions that need a handler' );

		$loaded = $this->send( 'GET', self::NAMESPACE_ROOT . '/' . $id . '/edit' );

		$this->assertSame( 200, $loaded->get_status(), wp_json_encode( $loaded->get_data() ) );

		$body = $loaded->get_data();

		$body['items'][0]['quantity'] = 3;

		$updated = $this->send( 'PUT', self::NAMESPACE_ROOT . '/' . $id, $body );

		$this->assertSame( 200, $updated->get_status(), wp_json_encode( $updated->get_data() ) );
		$this->assertSame( 3, (int) array_values( wc_get_order( $id )->get_items( 'line_item' ) )[0]->get_quantity() );
	}
}

<?php
/**
 * Integration: the admin order wizard's create / update / load service on BOTH WooCommerce order
 * datastores — HPOS and the legacy CPT (#710 spec D4 / D5, card #968).
 *
 * What the unit tier cannot see. The unit tests pin the editor's decisions against doubles of the
 * checkout handler, the pickup handler and the order. Here the REAL fixture carriers run on the REAL
 * WooCommerce: an order is created and edited through {@see Order_Editor}, and everything is read
 * back from a FRESH order object, so a write that lands only on one datastore (or only on an object
 * the datastore never saved) is caught. The order is then judged the way the orders page judges it:
 * by the carrier marker, by {@see Orders_Registry::resolve_provider_for_order()} and by the page's
 * real list query ({@see Orders_Query}) — never by a hand-rolled `meta_query`, which
 * `wc_get_orders()` DROPS on the legacy CPT datastore (gotcha
 * `wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore`).
 *
 * Also proven here, because only the real WooCommerce can:
 *  - «New order» is sent exactly ONCE for a processing order and not at all for a pending one — the
 *    editor issues no explicit trigger (#962 I0 contradiction 1: it would double-send);
 *  - stock follows WooCommerce's own status transition on create and its own line adjustment on edit;
 *  - the three checkout hooks stay silent on an admin save, the admin hook fires.
 *
 * Fixture facts this file leans on: the realistic carrier (`realistic`) owns a courier method
 * `woodev_realistic_shipping` and a pickup method `woodev_realistic_pickup_shipping` (slot field
 * `realistic_pickup_point`); the test carrier (`test_shipping`) owns the pickup method
 * `woodev_test_shipping` (slot `carrier_pickup_point`) and declares a carrier-order-id meta key. Zone
 * instance ids are NOT verified by the service, so any positive id serves.
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE — the coordinator runs the integration suite.
 * How both datastores run in one process: see {@see OrderPersistenceDatastoresTest}.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Checkout\Checkout_Config;
use Woodev\Framework\Shipping\Order\Order_Marker;
use Woodev\Tests\Integration\TestCase;

class OrderEditorDatastoresTest extends TestCase {

	private const REALISTIC_COURIER = 'woodev_realistic_shipping';
	private const REALISTIC_PICKUP  = 'woodev_realistic_pickup_shipping';
	private const REALISTIC_MARKER  = '_woodev_realistic_shipping_marker';
	private const REALISTIC_SLOT    = 'realistic_pickup_point';
	private const REALISTIC_POINT   = 'REAL-MSK-1';

	private const TEST_METHOD   = 'woodev_test_shipping';
	private const TEST_MARKER   = '_woodev_test_shipping_marker';
	private const TEST_EXPORTED = '_woodev_test_shipping_carrier_order_id';
	private const TEST_SLOT     = 'carrier_pickup_point';
	private const TEST_POINT    = 'PVZ-968';

	/** @var int the tests' managed-stock product. */
	private $product_id = 0;

	/** @var array<string,array{0:string,1:callable}> hooks the test added, to remove. */
	private $listeners = [];

	/**
	 * Re-registers the fixtures' own providers on a clean registry, and gives the tests a product
	 * whose stock WooCommerce manages.
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
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$this->product_id = $product->save();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->listeners as $listener ) {
			remove_action( $listener[0], $listener[1], 10 );
			remove_filter( $listener[0], $listener[1], 10 );
		}

		$this->listeners = [];

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Runs a fixture's private orders-page registration (the code that builds its provider,
	 * marker writer included, and registers it WITH the plugin).
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
	 * Selects the datastore for everything created after this call (data sync off, so an order
	 * lives in exactly one of them) and PROVES the framework sees it.
	 *
	 * @param bool $hpos true => HPOS `wc_orders*`; false => legacy `posts` / `postmeta`.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * A payload the wizard would send for a courier delivery, to be bent one field at a time.
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
					'last_name'  => 'Иванов',
					'country'    => 'RU',
					'city'       => 'Москва',
					'address_1'  => 'ул. Тверская, 1',
					'postcode'   => '125009',
					'phone'      => '+79991234567',
					'email'      => 'ivan-968@example.test',
				],
				'items'          => [
					[
						'product_id' => $this->product_id,
						'quantity'   => 2,
						'price'      => '150',
					],
				],
				'shipping_line'  => [
					'method_id'   => self::REALISTIC_COURIER,
					'instance_id' => 6,
					'label'       => 'Курьер',
					'cost'        => '350',
					'meta'        => [ 'delivery_time' => '2-3 дня' ],
				],
				'payment_method' => 'cod',
				'status'         => 'processing',
			],
			$override
		);
	}

	/**
	 * The payload for a delivery to a pickup point of the realistic carrier.
	 *
	 * @param array<string,mixed> $override top-level keys to replace.
	 * @return array<string,mixed>
	 */
	private function pickup_payload( array $override = [] ): array {
		return $this->payload(
			array_replace(
				[
					'shipping_line' => [
						'method_id'   => self::REALISTIC_PICKUP,
						'instance_id' => 5,
						'label'       => 'ПВЗ',
						'cost'        => '200',
					],
					'pickup_point'  => [ 'id' => self::REALISTIC_POINT ],
				],
				$override
			)
		);
	}

	/**
	 * Creates an order through the service and fails the test with the service's own message when
	 * it refuses.
	 *
	 * @param array<string,mixed> $payload the request body.
	 * @return \WC_Order
	 */
	private function create( array $payload ): \WC_Order {
		$result = ( new Order_Editor() )->create( $payload );

		$this->assertInstanceOf( \WC_Order::class, $result, $result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );

		return $result;
	}

	/**
	 * A fresh read of an order, so only what the datastore really holds is compared.
	 *
	 * @param int $order_id order id.
	 * @return \WC_Order
	 */
	private function fresh( int $order_id ): \WC_Order {
		$fresh = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		return $fresh;
	}

	/**
	 * @param int $product_id product id.
	 * @return int the product's stock, read fresh.
	 */
	private function stock( int $product_id ): int {
		wp_cache_flush();

		return (int) wc_get_product( $product_id )->get_stock_quantity();
	}

	/**
	 * Ids of the orders the orders page lists for a carrier tab ('' = the aggregate tab), asked
	 * through the page's REAL list query.
	 *
	 * @param string $carrier provider id, or '' for the aggregate.
	 * @return int[]
	 */
	private function listed_ids( string $carrier ): array {
		$result = ( new Orders_Query() )->get_results(
			[
				'carrier'  => $carrier,
				'per_page' => 200,
			]
		);

		return array_map(
			static function ( \WC_Order $order ): int {
				return (int) $order->get_id();
			},
			$result->orders
		);
	}

	/**
	 * Counts how often a hook fires, until the test ends.
	 *
	 * @param string $hook the hook.
	 * @return \ArrayObject<string,int> `count` holds the number of firings.
	 */
	private function count_hook( string $hook ): \ArrayObject {
		$counter = new \ArrayObject( [ 'count' => 0 ] );

		$listener = static function ( $first = null ) use ( $counter ) {
			$counter['count'] = $counter['count'] + 1;

			return $first;
		};

		add_filter( $hook, $listener, 10, 5 );

		$this->listeners[] = [ $hook, $listener ];

		return $counter;
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	/**
	 * The order's data lands as the wizard sent it: lines at the EDITED price, ONE shipping line at
	 * the chosen rate with its meta, addresses, payment method, status, created-by-admin.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_writes_the_order_as_the_wizard_sent_it( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh( $this->create( $this->payload() )->get_id() );

		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'admin', $order->get_created_via() );
		$this->assertSame( 0, $order->get_customer_id(), 'a guest by default (O11)' );

		$this->assertSame( 'Иван', $order->get_billing_first_name() );
		$this->assertSame( 'Москва', $order->get_shipping_city(), 'an empty shipping address means ship-to-billing' );
		$this->assertSame( 'ivan-968@example.test', $order->get_billing_email() );

		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertNotSame( '', $order->get_payment_method_title() );

		$lines = array_values( $order->get_items( 'line_item' ) );

		$this->assertCount( 1, $lines );
		$this->assertSame( $this->product_id, (int) $lines[0]->get_product_id() );
		$this->assertSame( 2, (int) $lines[0]->get_quantity() );
		$this->assertEquals( 300, (float) $lines[0]->get_total(), 'the manager edited the unit price to 150 (O8)' );

		$shipping = array_values( $order->get_shipping_methods() );

		$this->assertCount( 1, $shipping );
		$this->assertSame( self::REALISTIC_COURIER, $shipping[0]->get_method_id() );
		$this->assertSame( 6, (int) $shipping[0]->get_instance_id() );
		$this->assertSame( 'Курьер', $shipping[0]->get_name() );
		$this->assertEquals( 350, (float) $shipping[0]->get_total() );
		$this->assertSame( '2-3 дня', $shipping[0]->get_meta( 'delivery_time', true ), 'the rate meta is copied onto the line' );

		// No tax is configured on the test site: the total is items + delivery.
		$this->assertEquals( 650, (float) $order->get_total() );
	}

	/**
	 * The order is a row of the orders page by the very rule a customer's is: a valid marker, a
	 * resolvable owner, a place in the carrier's list — and only there.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_created_order_is_a_row_of_the_orders_page( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh( $this->create( $this->payload() )->get_id() );

		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::REALISTIC_MARKER, true ) ) );
		$this->assertSame( 'realistic', Orders_Registry::instance()->resolve_provider_for_order( $order )->get_id() );
		$this->assertContains( $order->get_id(), $this->listed_ids( 'realistic' ) );
		$this->assertNotContains( $order->get_id(), $this->listed_ids( 'test_shipping' ) );
		$this->assertContains( $order->get_id(), $this->listed_ids( '' ) );
	}

	/**
	 * A pickup tariff persists the point through the carrier's own checkout handler — the slot
	 * field a checkout would have written — and a courier tariff leaves no slot at all (#745).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_persists_the_pickup_point_only_for_a_pickup_tariff( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->assertContains( self::REALISTIC_PICKUP, Checkout_Config::pickup_method_ids(), 'the fixture must declare the pickup method' );

		$pickup  = $this->fresh( $this->create( $this->pickup_payload() )->get_id() );
		$courier = $this->fresh( $this->create( $this->payload() )->get_id() );

		$this->assertSame( self::REALISTIC_POINT, $pickup->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertSame( '', $courier->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertTrue( Order_Marker::is_valid_value( $pickup->get_meta( self::REALISTIC_MARKER, true ) ) );
	}

	/**
	 * The second fixture carrier goes through the same code with ITS OWN handler and marker.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_works_for_the_other_carrier_with_its_own_marker_and_slot( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh(
			$this->create(
				$this->payload(
					[
						'shipping_line' => [
							'method_id'   => self::TEST_METHOD,
							'instance_id' => 3,
							'label'       => 'Тестовая доставка',
							'cost'        => '100',
						],
						'pickup_point'  => [ 'id' => self::TEST_POINT ],
					]
				)
			)->get_id()
		);

		$this->assertSame( self::TEST_POINT, $order->get_meta( self::TEST_SLOT, true ) );
		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::TEST_MARKER, true ) ) );
		$this->assertSame( '', (string) $order->get_meta( self::REALISTIC_MARKER, true ), "another carrier's marker never lands here" );
		$this->assertSame( 'test_shipping', Orders_Registry::instance()->resolve_provider_for_order( $order )->get_id() );
	}

	/**
	 * «New order» goes out once through WooCommerce's own status transition — an explicit extra
	 * trigger would double-send (#962 I0, contradiction 1) — and a pending order sends none.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_new_order_email_is_sent_once_for_processing_and_not_for_pending( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$emails = $this->count_hook( 'woocommerce_email_enabled_new_order' );

		$this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$this->assertSame( 0, $emails['count'], 'a pending order sends nothing, like a checkout order awaiting payment' );

		$this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$this->assertSame( 1, $emails['count'], 'once, from WooCommerce\'s own pending→processing notification' );
	}

	/**
	 * Stock follows WooCommerce's own status transition on create.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_reduces_stock_through_the_status_transition_only( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$this->assertSame( 10, $this->stock( $this->product_id ), 'a pending order does not touch stock' );

		$this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$this->assertSame( 8, $this->stock( $this->product_id ) );
	}

	/**
	 * The three checkout hooks are CHECKOUT-ONLY; an admin save fires its own.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_admin_save_fires_its_own_hook_and_none_of_the_checkout_ones( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$prefix = \Woodev_Realistic_Shipping_Plugin::instance()->get_checkout_handler()->plugin_id();

		$admin    = $this->count_hook( 'woodev_shipping_' . $prefix . '_admin_order_saved' );
		$checkout = [
			$this->count_hook( 'woodev_shipping_' . $prefix . '_checkout_field_saved' ),
			$this->count_hook( 'woodev_shipping_' . $prefix . '_checkout_data_saved' ),
			$this->count_hook( 'woodev_shipping_' . $prefix . '_checkout_processed' ),
		];

		$this->create( $this->pickup_payload() );

		$this->assertSame( 1, $admin['count'] );

		foreach ( $checkout as $counter ) {
			$this->assertSame( 0, $counter['count'], 'plugins listening to the checkout hooks must never see an admin save' );
		}
	}

	/**
	 * «Создать аккаунт» (O11): WooCommerce creates the user; the order belongs to it.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_create_account_creates_the_user_and_links_the_order( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->fresh( $this->create( $this->payload( [ 'customer' => [ 'create_account' => true ] ] ) )->get_id() );
		$user  = get_user_by( 'email', 'ivan-968@example.test' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( (int) $user->ID, $order->get_customer_id() );
	}

	/**
	 * A payload the validator refuses leaves NO order behind — and answers 422 with the problems as
	 * data.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_invalid_payload_creates_nothing_and_answers_422( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$before = count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) );

		$result = ( new Order_Editor() )->create( $this->payload( [ 'items' => [] ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertContains( 'items', array_column( $result->get_error_data()['errors'], 'field' ) );
		$this->assertSame( $before, count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ) );
	}

	/**
	 * A foreign shipping method is refused (O12) — it would not appear on the page.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_foreign_shipping_method_is_refused( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$result = ( new Order_Editor() )->create(
			$this->payload(
				[
					'shipping_line' => [
						'method_id'   => 'flat_rate',
						'instance_id' => 1,
						'cost'        => '10',
					],
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains( 'shipping_line.method_id', array_column( $result->get_error_data()['errors'], 'field' ) );
	}

	// -------------------------------------------------------------------------
	// update
	// -------------------------------------------------------------------------

	/**
	 * An edit replaces lines, the shipping line, addresses and the persisted fields IN PLACE: an
	 * edited line keeps its identity, the marker stays valid and the order stays a row of the page.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_edits_the_order_in_place( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending' ] ) );
		$line_id = (int) array_key_first( $created->get_items( 'line_item' ) );

		$updated = ( new Order_Editor() )->update(
			$created->get_id(),
			$this->pickup_payload(
				[
					'status'  => 'pending',
					'items'   => [
						[
							'item_id'    => $line_id,
							'product_id' => $this->product_id,
							'quantity'   => 3,
							'price'      => '120',
						],
					],
					'billing' => [
						'country' => 'RU',
						'city'    => 'Санкт-Петербург',
						'phone'   => '+79990000000',
					],
				]
			)
		);

		$this->assertInstanceOf( \WC_Order::class, $updated, $updated instanceof \WP_Error ? $updated->get_error_message() : '' );

		$order = $this->fresh( $created->get_id() );
		$lines = $order->get_items( 'line_item' );

		$this->assertCount( 1, $lines );
		$this->assertArrayHasKey( $line_id, $lines, 'the edited line keeps its identity' );
		$this->assertSame( 3, (int) $lines[ $line_id ]->get_quantity() );
		$this->assertEquals( 360, (float) $lines[ $line_id ]->get_total() );

		$shipping = array_values( $order->get_shipping_methods() );

		$this->assertCount( 1, $shipping, 'the shipping line is REPLACED, never added to' );
		$this->assertSame( self::REALISTIC_PICKUP, $shipping[0]->get_method_id() );
		$this->assertEquals( 200, (float) $shipping[0]->get_total() );

		$this->assertSame( 'Санкт-Петербург', $order->get_billing_city() );
		$this->assertSame( self::REALISTIC_POINT, $order->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertEquals( 560, (float) $order->get_total() );

		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::REALISTIC_MARKER, true ) ) );
		$this->assertContains( $order->get_id(), $this->listed_ids( 'realistic' ) );
	}

	/**
	 * Leaving a pickup tariff leaves no stale point behind — the slot the persistence core skips
	 * is removed by the editor (#745, on the edit path).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_from_pickup_to_courier_removes_the_stale_point( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->pickup_payload( [ 'status' => 'pending' ] ) );

		$this->assertSame( self::REALISTIC_POINT, $this->fresh( $created->get_id() )->get_meta( self::REALISTIC_SLOT, true ) );

		$updated = ( new Order_Editor() )->update( $created->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WC_Order::class, $updated );
		$this->assertSame( '', (string) $this->fresh( $created->get_id() )->get_meta( self::REALISTIC_SLOT, true ) );
	}

	/**
	 * Moving an order to ANOTHER carrier moves its marker: the old one is removed, the new one is
	 * written, and the order is listed under the new carrier only (an order carries one marker, #928).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_to_another_carrier_moves_the_marker( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		$updated = ( new Order_Editor() )->update(
			$created->get_id(),
			$this->payload(
				[
					'status'        => 'pending',
					'shipping_line' => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'label'       => 'Тестовая доставка',
						'cost'        => '100',
					],
					'pickup_point'  => [ 'id' => self::TEST_POINT ],
				]
			)
		);

		$this->assertInstanceOf( \WC_Order::class, $updated );

		$order = $this->fresh( $created->get_id() );

		$this->assertSame( '', (string) $order->get_meta( self::REALISTIC_MARKER, true ), 'the previous carrier\'s marker is gone' );
		$this->assertTrue( Order_Marker::is_valid_value( $order->get_meta( self::TEST_MARKER, true ) ) );
		$this->assertSame( 'test_shipping', Orders_Registry::instance()->resolve_provider_for_order( $order )->get_id() );
		$this->assertContains( $order->get_id(), $this->listed_ids( 'test_shipping' ) );
		$this->assertNotContains( $order->get_id(), $this->listed_ids( 'realistic' ) );
	}

	/**
	 * A line the wizard no longer sends is removed and its stock released; an edited line's stock
	 * follows WooCommerce's own adjustment — never a hand-made one.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_update_keeps_stock_in_step_with_the_lines( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'processing' ] ) );
		$line_id = (int) array_key_first( $created->get_items( 'line_item' ) );

		$this->assertSame( 8, $this->stock( $this->product_id ) );

		( new Order_Editor() )->update(
			$created->get_id(),
			$this->payload(
				[
					'status' => 'processing',
					'items'  => [
						[
							'item_id'    => $line_id,
							'product_id' => $this->product_id,
							'quantity'   => 3,
						],
					],
				]
			)
		);

		$this->assertSame( 7, $this->stock( $this->product_id ), 'quantity 2 → 3 takes one more from stock' );

		$other = new \WC_Product_Simple();
		$other->set_name( 'Другой товар' );
		$other->set_status( 'publish' );
		$other->set_regular_price( '10' );
		$other_id = $other->save();

		( new Order_Editor() )->update(
			$created->get_id(),
			$this->payload(
				[
					'status' => 'processing',
					'items'  => [
						[
							'product_id' => $other_id,
							'quantity'   => 1,
						],
					],
				]
			)
		);

		$order = $this->fresh( $created->get_id() );
		$lines = array_values( $order->get_items( 'line_item' ) );

		$this->assertCount( 1, $lines );
		$this->assertSame( $other_id, (int) $lines[0]->get_product_id() );
		$this->assertEquals( 10, (float) $lines[0]->get_total(), 'an absent price takes the product own' );
		$this->assertSame( 10, $this->stock( $this->product_id ), 'the removed line released its stock' );
	}

	/**
	 * Editing a PAID order so that its total changes leaves a private order note (O14); an unpaid
	 * one, or one whose total did not change, leaves none.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_editing_a_paid_order_that_changes_its_total_adds_a_private_note( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$edit_notes = static function ( int $order_id ): array {
			return array_filter(
				wc_get_order_notes( [ 'order_id' => $order_id ] ),
				static function ( $note ): bool {
					return false !== strpos( (string) $note->content, 'Заказ изменён из админки' );
				}
			);
		};

		$paid = $this->create( $this->payload( [ 'status' => 'processing' ] ) );

		( new Order_Editor() )->update( $paid->get_id(), $this->payload( [ 'status' => 'processing' ] ) );
		$this->assertCount( 0, $edit_notes( $paid->get_id() ), 'nothing changed the total' );

		( new Order_Editor() )->update(
			$paid->get_id(),
			$this->payload(
				[
					'status'  => 'processing',
					'items'   => [
						[
							'product_id' => $this->product_id,
							'quantity'   => 2,
							'price'      => '200',
						],
					],
				]
			)
		);
		$notes = $edit_notes( $paid->get_id() );

		$this->assertCount( 1, $notes );
		$this->assertFalse( (bool) reset( $notes )->customer_note, 'the note is private' );

		$unpaid = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		( new Order_Editor() )->update(
			$unpaid->get_id(),
			$this->payload(
				[
					'status' => 'pending',
					'items'  => [
						[
							'product_id' => $this->product_id,
							'quantity'   => 5,
							'price'      => '200',
						],
					],
				]
			)
		);

		$this->assertCount( 0, $edit_notes( $unpaid->get_id() ), 'an unpaid order has nothing to refund or top up' );
	}

	// -------------------------------------------------------------------------
	// D5: the editable-state policy, on both datastores
	// -------------------------------------------------------------------------

	/**
	 * An exported order is refused with 409 — on update AND on load — and nothing is written.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_exported_order_is_refused_with_409_and_left_untouched( bool $hpos ): void {
		$this->use_datastore( $hpos );

		// The test carrier declares a carrier-order-id meta key, so the exported order is one of ITS.
		$test = $this->create(
			$this->payload(
				[
					'status'        => 'pending',
					'shipping_line' => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'cost'        => '100',
					],
					'pickup_point'  => [ 'id' => self::TEST_POINT ],
				]
			)
		);

		$exported = $this->fresh( $test->get_id() );
		$exported->update_meta_data( self::TEST_EXPORTED, 'CARRIER-968' );
		$exported->save();

		$result = ( new Order_Editor() )->update( $test->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		$loaded = ( new Order_Editor() )->load( $test->get_id() );

		$this->assertInstanceOf( \WP_Error::class, $loaded );
		$this->assertSame( 409, $loaded->get_error_data()['status'] );

		$this->assertSame( 'CARRIER-968', $this->fresh( $test->get_id() )->get_meta( self::TEST_EXPORTED, true ) );
		$this->assertSame( self::TEST_POINT, $this->fresh( $test->get_id() )->get_meta( self::TEST_SLOT, true ), 'a refused edit writes nothing' );
	}

	/**
	 * The stale-row race: an order exported AFTER the wizard loaded it is refused on the write, on a
	 * fresh read — the client's row is never trusted.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_order_exported_after_the_wizard_loaded_it_is_refused( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create(
			$this->payload(
				[
					'status'        => 'pending',
					'shipping_line' => [
						'method_id'   => self::TEST_METHOD,
						'instance_id' => 3,
						'cost'        => '100',
					],
					'pickup_point'  => [ 'id' => self::TEST_POINT ],
				]
			)
		);

		$editor = new Order_Editor();
		$prefill = $editor->load( $created->get_id() );

		$this->assertIsArray( $prefill, 'editable while nothing exported it' );

		// Another admin exports it while this manager is still typing.
		$order = $this->fresh( $created->get_id() );
		$order->update_meta_data( self::TEST_EXPORTED, 'CARRIER-968' );
		$order->save();

		$result = $editor->update( $created->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * Every final status refuses an edit, and never becomes a target of one.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_final_status_is_neither_edited_nor_set_by_an_edit( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->payload( [ 'status' => 'pending' ] ) );

		$to_final = ( new Order_Editor() )->update( $created->get_id(), $this->payload( [ 'status' => 'cancelled' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $to_final );
		$this->assertSame( 422, $to_final->get_error_data()['status'] );
		$this->assertContains( 'status', array_column( $to_final->get_error_data()['errors'], 'field' ) );

		$order = $this->fresh( $created->get_id() );
		$order->set_status( 'completed' );
		$order->save();

		$from_final = ( new Order_Editor() )->update( $created->get_id(), $this->payload( [ 'status' => 'pending' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $from_final );
		$this->assertSame( 409, $from_final->get_error_data()['status'] );
	}

	/**
	 * An order that is not a row of the page — a plain WooCommerce order — is a 404, never editable
	 * here (O3), and an unknown id is a 404 too.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_order_that_is_not_a_row_of_the_page_is_a_404( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$plain = wc_create_order();
		$plain->save();

		$editor = new Order_Editor();

		foreach ( [ $plain->get_id(), 99999999 ] as $id ) {
			$result = $editor->update( $id, $this->payload() );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 404, $result->get_error_data()['status'] );
			$this->assertSame( 404, $editor->load( $id )->get_error_data()['status'] );
		}
	}

	// -------------------------------------------------------------------------
	// load
	// -------------------------------------------------------------------------

	/**
	 * The prefill is what the wizard's steps hold, read back: it round-trips into an update that
	 * changes nothing.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_load_returns_the_prefill_the_steps_need( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->pickup_payload( [ 'status' => 'processing' ] ) );

		$prefill = ( new Order_Editor() )->load( $created->get_id() );

		$this->assertIsArray( $prefill );
		$this->assertSame( $created->get_id(), $prefill['order']['id'] );
		$this->assertSame( 'processing', $prefill['order']['status'] );
		$this->assertTrue( $prefill['order']['is_paid'] );
		$this->assertSame( 'realistic', $prefill['carrier'] );

		$this->assertSame( 'Москва', $prefill['billing']['city'] );
		$this->assertSame( 'ivan-968@example.test', $prefill['billing']['email'] );
		$this->assertSame( 'cod', $prefill['payment_method'] );

		$this->assertCount( 1, $prefill['items'] );
		$this->assertSame( $this->product_id, $prefill['items'][0]['product_id'] );
		$this->assertSame( 2, $prefill['items'][0]['quantity'] );
		$this->assertEquals( 150, (float) $prefill['items'][0]['price'], 'the unit price the manager set, not the line total' );

		$this->assertSame( self::REALISTIC_PICKUP, $prefill['shipping_line']['method_id'] );
		$this->assertEquals( 200, (float) $prefill['shipping_line']['cost'] );
		$this->assertSame( self::REALISTIC_POINT, $prefill['pickup_point']['id'] );
		$this->assertSame( self::REALISTIC_POINT, $prefill['fields'][ self::REALISTIC_SLOT ] );
	}

	/**
	 * Loading an order and sending its prefill straight back changes nothing — the prefill and the
	 * payload are the same shape.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_prefill_round_trips_into_an_update_that_changes_nothing( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$created = $this->create( $this->pickup_payload( [ 'status' => 'pending' ] ) );
		$before  = $this->fresh( $created->get_id() );

		$editor  = new Order_Editor();
		$prefill = $editor->load( $created->get_id() );

		$updated = $editor->update( $created->get_id(), $prefill );

		$this->assertInstanceOf( \WC_Order::class, $updated, $updated instanceof \WP_Error ? $updated->get_error_message() . ' ' . wp_json_encode( $updated->get_error_data() ) : '' );

		$after = $this->fresh( $created->get_id() );

		$this->assertEquals( (float) $before->get_total(), (float) $after->get_total() );
		$this->assertSame( $before->get_billing_city(), $after->get_billing_city() );
		$this->assertSame( $before->get_meta( self::REALISTIC_SLOT, true ), $after->get_meta( self::REALISTIC_SLOT, true ) );
		$this->assertCount( 1, $after->get_shipping_methods() );
		$this->assertCount( 1, $after->get_items( 'line_item' ) );
	}
}

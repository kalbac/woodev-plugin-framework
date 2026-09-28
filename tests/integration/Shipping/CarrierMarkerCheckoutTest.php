<?php
/**
 * Integration: a checkout-placed fixture order carries its carrier's marker and shows on the
 * orders page — on BOTH WooCommerce order datastores, through BOTH checkout entry points
 * (#967, #710 I1b).
 *
 * The contract (I0 measurement, `docs-internal/research/2026-09-28-710-i0-measurement/`):
 * before I1b nothing wrote the marker at checkout — the fixtures wrote `'1'` only from their
 * seeders — so a real checkout order for a fixture carrier was invisible on the orders page.
 * Here the REAL fixture handlers run on the REAL WordPress hooks (classic
 * `woocommerce_checkout_order_processed`, block `woocommerce_store_api_checkout_order_processed`)
 * and the result is read back the way the orders page reads it:
 *
 *  - the marker meta from a FRESH order object;
 *  - {@see Orders_Registry::resolve_provider_for_order()} — the row owner / metabox, which
 *    treats an empty value as «no marker» (the value rule of the contract);
 *  - a `meta_query` `EXISTS` order query — the list query.
 *
 * The fixtures' provider registrations are re-run after a registry reset (other integration
 * tests reset that process-wide singleton for their own state), so this test exercises the
 * fixture's OWN registered writer, not one built here.
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE — the coordinator runs the integration suite.
 * How both datastores run in one process: see {@see OrderPersistenceDatastoresTest}.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Integration\TestCase;

class CarrierMarkerCheckoutTest extends TestCase {

	private const TEST_MARKER      = '_woodev_test_shipping_marker';
	private const REALISTIC_MARKER = '_woodev_realistic_shipping_marker';
	private const DEMO_CITY        = 'Москва';

	/**
	 * Re-registers the fixtures' own providers on a clean registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\Woodev_Test_Shipping_Method_Plugin' ) ) {
			$this->markTestSkipped( 'The test-shipping fixture plugin is not loaded.' );
		}

		Orders_Registry::instance()->reset_for_tests();

		$this->register_fixture_provider( \Woodev_Test_Shipping_Method_Plugin::instance(), 'init_test_shipping_orders_page' );

		if ( class_exists( '\Woodev_Realistic_Shipping_Plugin' ) ) {
			$this->register_fixture_provider( \Woodev_Realistic_Shipping_Plugin::instance(), 'init_realistic_orders_page' );
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = [];

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * Runs a fixture's private orders-page registration (the code that builds its provider,
	 * marker writer included).
	 *
	 * @param object $plugin fixture plugin instance.
	 * @param string $method its private registration method.
	 * @return void
	 */
	private function register_fixture_provider( $plugin, string $method ): void {
		$reflection = new \ReflectionMethod( $plugin, $method );
		$reflection->setAccessible( true );
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
	 * @param bool $hpos true => HPOS `wc_orders*`; false => legacy `posts` / `postmeta`.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * A saved order with one shipping line — what both checkouts leave behind before the
	 * order-processed hooks run.
	 *
	 * @param string $method_id   shipping method id.
	 * @param int    $instance_id zone-instance id.
	 * @return \WC_Order
	 */
	private function order_with_shipping( string $method_id, int $instance_id ): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_country( 'RU' );
		$order->set_billing_city( 'Москва' );
		$order->set_billing_address_2( self::DEMO_CITY );

		$line = new \WC_Order_Item_Shipping();
		$line->set_method_id( $method_id );
		$line->set_instance_id( $instance_id );
		$line->set_method_title( 'Доставка' );
		$line->set_total( '100' );
		$order->add_item( $line );
		$order->save();

		return $order;
	}

	/**
	 * @param \WC_Order $order  the saved order.
	 * @param string    $method the chosen `method:instance`.
	 * @return void
	 */
	private function place_through_the_classic_checkout( \WC_Order $order, string $method ): void {
		$_POST = [
			'billing_country'   => 'RU',
			'billing_city'      => 'Москва',
			'billing_address_2' => self::DEMO_CITY,
			'shipping_method'   => [ $method ],
		];

		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], $order );

		$_POST = [];
	}

	/**
	 * @param \WC_Order $order the saved order.
	 * @return void
	 */
	private function place_through_the_block_checkout( \WC_Order $order ): void {
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	}

	/**
	 * The marker value, read from a FRESH order object.
	 *
	 * @param int    $order_id order id.
	 * @param string $key      marker meta key.
	 * @return mixed
	 */
	private function marker( int $order_id, string $key ) {
		$fresh = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		return $fresh->get_meta( $key, true );
	}

	/**
	 * Ids of the orders the orders page's list query would match for a marker key.
	 *
	 * @param string $key marker meta key.
	 * @return int[]
	 */
	private function listed_ids( string $key ): array {
		return array_map(
			'intval',
			wc_get_orders(
				[
					'limit'      => -1,
					'return'     => 'ids',
					'meta_query' => [
						[
							'key'     => $key,
							'compare' => 'EXISTS',
						],
					],
				]
			)
		);
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_classic_checkout_order_is_marked_and_listed( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->order_with_shipping( 'woodev_test_shipping', 3 );
		$this->place_through_the_classic_checkout( $order, 'woodev_test_shipping:3' );

		$this->assertSame( '1', $this->marker( $order->get_id(), self::TEST_MARKER ) );
		$this->assertContains( $order->get_id(), $this->listed_ids( self::TEST_MARKER ) );

		$provider = Orders_Registry::instance()->resolve_provider_for_order( wc_get_order( $order->get_id() ) );
		$this->assertNotNull( $provider, 'the row owner must resolve — an empty marker value would not' );
		$this->assertSame( 'test_shipping', $provider->get_id() );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_block_checkout_order_is_marked_and_listed( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->order_with_shipping( 'woodev_test_shipping', 3 );
		$this->place_through_the_block_checkout( $order );

		$this->assertSame( '1', $this->marker( $order->get_id(), self::TEST_MARKER ) );
		$this->assertContains( $order->get_id(), $this->listed_ids( self::TEST_MARKER ) );
	}

	/**
	 * Both checkouts leave the same marker.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_both_checkouts_write_the_same_marker( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$classic = $this->order_with_shipping( 'woodev_test_shipping', 3 );
		$this->place_through_the_classic_checkout( $classic, 'woodev_test_shipping:3' );

		$block = $this->order_with_shipping( 'woodev_test_shipping', 3 );
		$this->place_through_the_block_checkout( $block );

		$this->assertSame(
			$this->marker( $classic->get_id(), self::TEST_MARKER ),
			$this->marker( $block->get_id(), self::TEST_MARKER )
		);
	}

	/**
	 * The checkout handlers run for EVERY order (once per active carrier plugin): a
	 * free-shipping order must stay unmarked, whichever plugin's handler sees it.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_free_shipping_order_is_not_marked( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$classic = $this->order_with_shipping( 'free_shipping', 1 );
		$this->place_through_the_classic_checkout( $classic, 'free_shipping:1' );

		$block = $this->order_with_shipping( 'free_shipping', 1 );
		$this->place_through_the_block_checkout( $block );

		foreach ( [ $classic, $block ] as $order ) {
			$this->assertSame( '', $this->marker( $order->get_id(), self::TEST_MARKER ) );
			$this->assertSame( '', $this->marker( $order->get_id(), self::REALISTIC_MARKER ) );
			$this->assertNotContains( $order->get_id(), $this->listed_ids( self::TEST_MARKER ) );
			$this->assertNotContains( $order->get_id(), $this->listed_ids( self::REALISTIC_MARKER ) );
		}
	}

	/**
	 * With two carrier plugins active, an order shipped by one carrier carries only that
	 * carrier's marker — the other plugin's handlers ran for it too and must have left it alone.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_an_order_carries_only_its_own_carriers_marker( bool $hpos ): void {
		if ( ! class_exists( '\Woodev_Realistic_Shipping_Plugin' ) ) {
			$this->markTestSkipped( 'The realistic-shipping fixture plugin is not loaded.' );
		}

		$this->use_datastore( $hpos );

		$order = $this->order_with_shipping( 'woodev_realistic_pickup_shipping', 5 );
		$this->place_through_the_block_checkout( $order );

		$this->assertSame( '1', $this->marker( $order->get_id(), self::REALISTIC_MARKER ) );
		$this->assertSame( '', $this->marker( $order->get_id(), self::TEST_MARKER ) );

		$provider = Orders_Registry::instance()->resolve_provider_for_order( wc_get_order( $order->get_id() ) );
		$this->assertNotNull( $provider );
		$this->assertSame( 'realistic', $provider->get_id() );
	}

	/**
	 * A carrier plugin can fire the hook more than once (a retried request, a second plugin's
	 * handler): the marker stays a single valid `'1'`.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_marking_twice_leaves_one_valid_marker( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->order_with_shipping( 'woodev_test_shipping', 3 );
		$this->place_through_the_block_checkout( $order );
		$this->place_through_the_block_checkout( wc_get_order( $order->get_id() ) );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $fresh->get_meta( self::TEST_MARKER, false ) );
		$this->assertSame( '1', $fresh->get_meta( self::TEST_MARKER, true ) );
	}
}

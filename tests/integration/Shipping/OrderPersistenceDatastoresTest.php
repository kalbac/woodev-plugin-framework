<?php
/**
 * Integration: the framework's order-data writes on BOTH WooCommerce order datastores —
 * HPOS and the legacy CPT — through BOTH checkout entry points (#964, #963).
 *
 * What the unit tier cannot see. The unit tests pin the persistence core's hooks, payloads and
 * write order against a stubbed meta layer. Here the REAL handlers of the shipping fixtures
 * run on the REAL WordPress hooks, and the meta is read back from a FRESH order object, so a
 * write that only lands on one datastore (or only on an object the datastore never saved)
 * is caught:
 *
 *  - the classic `woocommerce_checkout_order_processed` path, fed by `$_POST` exactly as the
 *    checkout form feeds it;
 *  - the block checkout's `woocommerce_store_api_checkout_order_processed` path, whose only
 *    argument is the order. The pickup point the customer confirmed lives in the session (the
 *    REST `select` route writes it there — that half is unit-tested against a fake session);
 *    here it is supplied through the `woodev_shipping_store_api_posted_data` filter the
 *    pickup handler itself contributes to, so the test covers everything downstream of it.
 *
 * The two paths must leave the SAME framework meta on equivalent orders — the parity #963 is
 * about. Meta whose key starts with `_` is WooCommerce's own bookkeeping (or the popular
 * settlement candidate, which only exists when the visitor has a location chain) and is not
 * part of the comparison.
 *
 * How both datastores run in one process: see {@see OrdersIdResolverDatastoresTest} — the
 * `woocommerce_custom_orders_table_enabled` option is flipped per test, data sync off, so an
 * order lives in exactly one datastore; {@see self::use_datastore()} proves the flip took.
 *
 * NOT RUN by the author of #964 (the coordinator runs the integration suite): written against
 * the fixture plugin's field ids (`carrier_pickup_point`, method `woodev_test_shipping`).
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Tests\Integration\TestCase;

class OrderPersistenceDatastoresTest extends TestCase {

	private const METHOD_ID    = 'woodev_test_shipping';
	private const INSTANCE_ID  = 3;
	private const POINT_FIELD  = 'carrier_pickup_point';
	private const POINT_ID     = 'PVZ-964';
	private const FILTER       = 'woodev_shipping_store_api_posted_data';

	/** @var callable|null the test's own contribution to the Store API posted-data filter */
	private $point_contribution = null;

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$_POST = [];

		if ( null !== $this->point_contribution ) {
			remove_filter( self::FILTER, $this->point_contribution, 5 );
			$this->point_contribution = null;
		}

		parent::tearDown();
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
	 * A saved order with one shipping line of the fixture's pickup method — what both checkouts
	 * leave behind before the order-processed hooks run.
	 *
	 * @return \WC_Order
	 */
	private function order_with_pickup_shipping(): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_country( 'RU' );
		$order->set_billing_city( 'Москва' );

		$line = new \WC_Order_Item_Shipping();
		$line->set_method_id( self::METHOD_ID );
		$line->set_instance_id( self::INSTANCE_ID );
		$line->set_method_title( 'Тестовая доставка' );
		$line->set_total( '100' );
		$order->add_item( $line );
		$order->save();

		return $order;
	}

	/**
	 * The framework's own order meta — every key WooCommerce does not own — read from a FRESH
	 * order object, so only what the datastore really holds is compared.
	 *
	 * @param int $order_id order id.
	 * @return array<string,mixed> meta key => value, sorted by key
	 */
	private function framework_meta( int $order_id ): array {
		$fresh = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		$out = [];

		foreach ( $fresh->get_meta_data() as $meta ) {
			$data = $meta->get_data();

			if ( 0 === strpos( (string) $data['key'], '_' ) ) {
				continue;
			}

			$out[ (string) $data['key'] ] = $data['value'];
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Fires the classic hook the way WooCommerce does after it saved the order, with the form
	 * the customer would have posted.
	 *
	 * @param \WC_Order $order the saved order.
	 * @return void
	 */
	private function place_through_the_classic_checkout( \WC_Order $order ): void {
		$_POST = [
			self::POINT_FIELD => self::POINT_ID,
			'billing_country' => 'RU',
			'billing_city'    => 'Москва',
			'shipping_method' => [ self::METHOD_ID . ':' . self::INSTANCE_ID ],
		];

		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], $order );

		$_POST = [];
	}

	/**
	 * Fires the block checkout's hook — one argument, the order — with the confirmed point
	 * supplied the way the pickup handler supplies it from the session.
	 *
	 * @param \WC_Order $order the saved order.
	 * @return void
	 */
	private function place_through_the_block_checkout( \WC_Order $order ): void {
		$this->point_contribution = static function ( $posted ) {
			if ( is_array( $posted ) && ! isset( $posted[ self::POINT_FIELD ] ) ) {
				$posted[ self::POINT_FIELD ] = self::POINT_ID;
			}

			return $posted;
		};

		add_filter( self::FILTER, $this->point_contribution, 5 );

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );
	}

	/**
	 * The fixture handlers listen on the block checkout's hook at all (a plugin that forgot
	 * `register()` would silently write nothing, and every other assertion here would then
	 * fail with a confusing empty diff).
	 *
	 * @return void
	 */
	public function test_the_block_checkout_hook_has_the_framework_listeners(): void {
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_order_processed' ), 'no listener on the Store API order-processed hook' );
	}

	/**
	 * Classic checkout: the pickup point field reaches the order and survives a datastore
	 * round trip.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_classic_checkout_persists_the_pickup_point_field( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->order_with_pickup_shipping();
		$this->place_through_the_classic_checkout( $order );

		$this->assertSame( self::POINT_ID, $this->framework_meta( $order->get_id() )[ self::POINT_FIELD ] ?? null );
	}

	/**
	 * Block checkout (#963): the same field reaches the order, on both datastores — before
	 * this, a Store API order carried none of it and never showed on the orders page.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_the_block_checkout_persists_the_pickup_point_field( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = $this->order_with_pickup_shipping();
		$this->place_through_the_block_checkout( $order );

		$this->assertSame( self::POINT_ID, $this->framework_meta( $order->get_id() )[ self::POINT_FIELD ] ?? null );
	}

	/**
	 * Parity: two equivalent orders, one per checkout, end with the same framework meta —
	 * every key, every value — on both datastores.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_both_checkouts_leave_the_same_framework_meta( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$classic = $this->order_with_pickup_shipping();
		$this->place_through_the_classic_checkout( $classic );

		$block = $this->order_with_pickup_shipping();
		$this->place_through_the_block_checkout( $block );

		$this->assertSame(
			$this->framework_meta( $classic->get_id() ),
			$this->framework_meta( $block->get_id() ),
			'a block-checkout order must carry the same framework meta as a classic one'
		);
		$this->assertArrayHasKey( self::POINT_FIELD, $this->framework_meta( $block->get_id() ) );
	}

	/**
	 * A block-checkout order placed with a NON-pickup method must not pick up the pickup
	 * point: the stale-value rule (#745) holds on this path too.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos datastore under test.
	 * @return void
	 */
	public function test_a_non_pickup_block_order_gets_no_pickup_point( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order = wc_create_order();
		$line  = new \WC_Order_Item_Shipping();
		$line->set_method_id( 'free_shipping' );
		$line->set_instance_id( 1 );
		$line->set_method_title( 'Бесплатная доставка' );
		$order->add_item( $line );
		$order->save();

		$this->place_through_the_block_checkout( $order );

		$this->assertArrayNotHasKey( self::POINT_FIELD, $this->framework_meta( $order->get_id() ) );
	}
}

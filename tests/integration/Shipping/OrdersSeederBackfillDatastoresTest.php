<?php
/**
 * Integration: the demo-orders seeders' backfill (#868) against REAL orders on BOTH WooCommerce
 * datastores — HPOS and the legacy CPT — for BOTH fixtures' seeders.
 *
 * The unit tier (`TestOrdersSeederBackfillTest`) pins the pure "which fields" decision. What only
 * a real WooCommerce can show is the walk itself: that `backfill_existing_orders()` finds the
 * seeder's OWN orders by the marker meta through `wc_get_orders()` on either datastore, writes
 * through the CRUD, leaves a foreign order (no marker, or another carrier's marker) alone, keeps a
 * manually edited value, and changes nothing on the second run.
 *
 * Datastore switching is the one `OrdersIdResolverDatastoresTest` documents: flip the
 * WooCommerce order-table options with data sync off, and prove the framework sees it.
 *
 * ⚠ Written for the coordinator's integration run; not run by the worker that authored it.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Tests\Integration\TestCase;

require_once dirname( __DIR__, 2 ) . '/_fixtures/woodev-test-shipping-method/class-test-orders-seeder.php';
require_once dirname( __DIR__, 2 ) . '/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-orders-seeder.php';

class OrdersSeederBackfillDatastoresTest extends TestCase {

	/**
	 * @return array<string,array{0:bool,1:class-string}>
	 */
	public function datastore_and_seeder_provider(): array {
		$out = [];

		foreach ( [
			'HPOS'       => true,
			'legacy CPT' => false,
		] as $datastore => $hpos ) {
			foreach ( [
				'test seeder'      => \Woodev_Test_Orders_Seeder::class,
				'realistic seeder' => \Woodev_Realistic_Orders_Seeder::class,
			] as $label => $seeder ) {
				$out[ $datastore . ' / ' . $label ] = [ $hpos, $seeder ];
			}
		}

		return $out;
	}

	/**
	 * @param bool $hpos true => HPOS `wc_orders*`; false => legacy `posts` / `postmeta`.
	 *
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * An order as an OLD seeder version left it: the marker and nothing else.
	 *
	 * @param string $marker_key marker meta key (empty string => a foreign order without one).
	 *
	 * @return \WC_Order
	 */
	private function bare_order( string $marker_key ): \WC_Order {
		$order = wc_create_order();

		if ( '' !== $marker_key ) {
			$order->update_meta_data( $marker_key, '1' );
		}

		$order->save();

		return $order;
	}

	/**
	 * @dataProvider datastore_and_seeder_provider
	 *
	 * @param bool         $hpos   datastore under test.
	 * @param class-string $seeder seeder class under test.
	 *
	 * @return void
	 */
	public function test_backfill_fills_empty_keeps_manual_edits_skips_foreign_and_is_idempotent( bool $hpos, string $seeder ): void {
		$this->use_datastore( $hpos );

		$own    = $this->bare_order( $seeder::MARKER_META_KEY );
		$edited = $this->bare_order( $seeder::MARKER_META_KEY );
		$edited->set_billing_city( 'Ручной город' );
		$edited->set_payment_method( 'cod' );
		$edited->save();

		$no_marker     = $this->bare_order( '' );
		$other_carrier = $this->bare_order( '_some_other_carrier_marker' );

		$changed = $seeder::backfill_existing_orders();

		$this->assertSame( 2, $changed, 'exactly the two orders carrying this seeder\'s marker are touched' );

		// Own, empty order: customer, address, payment, line items and totals all appear.
		$own = wc_get_order( $own->get_id() );
		$this->assertNotSame( '', $own->get_billing_first_name() );
		$this->assertNotSame( '', $own->get_billing_city() );
		$this->assertNotSame( '', $own->get_shipping_address_1() );
		$this->assertNotSame( '', $own->get_payment_method() );
		$this->assertNotSame( '', $own->get_payment_method_title() );
		$this->assertNotEmpty( $own->get_items( 'line_item' ) );
		$this->assertGreaterThan( 0.0, (float) $own->get_total() );

		// A manual edit survives; the fields around it are still filled.
		$edited = wc_get_order( $edited->get_id() );
		$this->assertSame( 'Ручной город', $edited->get_billing_city() );
		$this->assertSame( 'cod', $edited->get_payment_method() );
		$this->assertSame( 'Наложенный платёж', $edited->get_payment_method_title() );
		$this->assertNotSame( '', $edited->get_billing_last_name() );

		// Foreign orders are left exactly as they were.
		foreach ( [ $no_marker, $other_carrier ] as $foreign ) {
			$foreign = wc_get_order( $foreign->get_id() );
			$this->assertSame( '', $foreign->get_billing_first_name() );
			$this->assertSame( '', $foreign->get_payment_method() );
			$this->assertEmpty( $foreign->get_items( 'line_item' ) );
		}

		// Second run: nothing is empty any more, so nothing changes.
		$snapshot = [ $own->get_total(), $own->get_billing_city(), count( $own->get_items() ) ];

		$this->assertSame( 0, $seeder::backfill_existing_orders(), 'a second run must change nothing' );

		$own = wc_get_order( $own->get_id() );
		$this->assertSame( $snapshot, [ $own->get_total(), $own->get_billing_city(), count( $own->get_items() ) ] );
	}
}

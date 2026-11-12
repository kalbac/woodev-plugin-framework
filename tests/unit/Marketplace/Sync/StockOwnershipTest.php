<?php
/**
 * Who moves WooCommerce stock in each stock mode.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Sync\Mirror_Stock_Writer;
use Woodev\Framework\Marketplace\Sync\Order_Stock_Filters;
use Woodev\Framework\Marketplace\Sync\Stock_Mode;
use Woodev\Framework\Marketplace\Sync\Stock_Warehouse;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The stock ownership rules of the Ozon plugin's `StockOwnershipTest` and `StockCrashWindowTest`, on the pieces that moved:
 * the reduce / restore filters by the order's stamp, the readback in both modes and the mirror write.
 *
 * The donor ran these through WooCommerce's own order writer; that writer arrives with the orders flow, so here an order is a
 * double that carries only what the filters read.
 */
final class StockOwnershipTest extends Sync_Test_Case {
	/**
	 * The stamp decides who takes and gives back an order's stock.
	 *
	 * @dataProvider ownership
	 *
	 * @param string $stamp    Order stamp.
	 * @param string $mode     Stock mode of the order's account now.
	 * @param bool   $swapping Whether the order writer is swapping the order's lines.
	 * @param bool   $reduce   Whether WooCommerce may take the stock.
	 * @param bool   $restore  Whether WooCommerce may give it back.
	 * @return void
	 */
	public function test_the_stamp_decides_who_moves_the_stock( string $stamp, string $mode, bool $swapping, bool $reduce, bool $restore ): void {
		$this->set_account( [ 'stock_mode' => $mode ] );

		$filters = $this->filters( $stamp );
		$order   = $this->order( $swapping );

		$this->assertSame( $reduce, $filters->can_reduce( true, $order ), 'reduce' );
		$this->assertSame( $restore, $filters->can_restore( true, $order ), 'restore' );
		$this->assertFalse( $filters->can_reduce( false, $order ), 'a refusal of WooCommerce is never overturned' );
		$this->assertFalse( $filters->can_restore( false, $order ), 'a refusal of WooCommerce is never overturned' );
	}

	/**
	 * The rows of the ownership table.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:bool,4:bool}>
	 */
	public function ownership(): array {
		return [
			'not ours: WooCommerce decides'                    => [ '', Stock_Mode::SITE, false, true, true ],
			'site: WooCommerce decides'                        => [ Order_Stock_Filters::STAMP_SITE, Stock_Mode::SITE, false, true, true ],
			'site stamp survives a switch to marketplace mode' => [ Order_Stock_Filters::STAMP_SITE, Stock_Mode::MARKETPLACE, false, true, true ],
			'marketplace: the order moves nothing'             => [ Order_Stock_Filters::STAMP_MARKETPLACE, Stock_Mode::MARKETPLACE, false, false, false ],
			'marketplace stamp survives a switch to site mode' => [ Order_Stock_Filters::STAMP_MARKETPLACE, Stock_Mode::SITE, false, false, false ],
			'legacy: never reduces, restores in site mode'     => [ Order_Stock_Filters::STAMP_LEGACY, Stock_Mode::SITE, false, false, true ],
			'legacy in marketplace mode restores nothing'      => [ Order_Stock_Filters::STAMP_LEGACY, Stock_Mode::MARKETPLACE, false, false, false ],
			'legacy in off mode restores (nothing else does)'  => [ Order_Stock_Filters::STAMP_LEGACY, Stock_Mode::OFF, false, false, true ],
			'legacy line swap in off mode takes the new lines' => [ Order_Stock_Filters::STAMP_LEGACY, Stock_Mode::OFF, true, true, true ],
			'marketplace stamp in off mode moves nothing'      => [ Order_Stock_Filters::STAMP_MARKETPLACE, Stock_Mode::OFF, false, false, false ],
			'legacy line swap takes the new lines'             => [ Order_Stock_Filters::STAMP_LEGACY, Stock_Mode::SITE, true, true, true ],
			'legacy line swap in marketplace mode takes none'  => [ Order_Stock_Filters::STAMP_LEGACY, Stock_Mode::MARKETPLACE, true, false, false ],
		];
	}

	/**
	 * An order whose account is gone keeps WooCommerce's answer where the mode would decide, and anything that is not an order is
	 * passed through.
	 *
	 * @return void
	 */
	public function test_a_missing_account_and_a_foreign_value_keep_woocommerces_answer(): void {
		$filters = new Order_Stock_Filters(
			static function (): string {
				return Order_Stock_Filters::STAMP_LEGACY;
			},
			static function (): ?Account {
				return null;
			}
		);

		$this->assertTrue( $filters->can_restore( true, $this->order( false ) ) );
		$this->assertTrue( $filters->can_reduce( true, 'not an order' ) );
		$this->assertFalse( $filters->can_restore( false, null ) );
	}

	/**
	 * Both filters are hooked with the order as their second argument.
	 *
	 * @return void
	 */
	public function test_the_filters_are_hooked(): void {
		$filters = $this->filters( Order_Stock_Filters::STAMP_SITE );
		$filters->register();

		$this->assertSame( 10, has_filter( 'woocommerce_can_reduce_order_stock', [ $filters, 'can_reduce' ] ) );
		$this->assertSame( 10, has_filter( 'woocommerce_can_restore_order_stock', [ $filters, 'can_restore' ] ) );
	}

	/**
	 * `site` mode: the readback only compares — it never writes WC stock, whatever the marketplace reserved.
	 *
	 * @return void
	 */
	public function test_the_site_mode_readback_never_writes_wc_stock(): void {
		$product = $this->stock_product( 'a', 1001, 9 );

		$this->market->present['a']  = 9;
		$this->market->reserved['a'] = 1;

		$this->assertSame( [], $this->reconcile() );
		$this->assertSame( 9, $this->wc_stock( $product ) );
		$this->assertNull( $this->stock_row( $product ), 'no pair, nothing to remember' );
	}

	/**
	 * `marketplace` mode: the marketplace's numbers (`present − reserved`) set WC stock, once.
	 *
	 * @return void
	 */
	public function test_the_marketplace_mode_readback_mirrors_what_can_still_be_sold(): void {
		$this->set_account( [ 'stock_mode' => 'marketplace' ] );
		$product = $this->stock_product( 'a', 1001 );

		$this->market->present['a']  = 10;
		$this->market->reserved['a'] = 1;
		$mirrored                    = $this->reconcile();

		$this->assertSame( 9, $this->wc_stock( $product ) );
		$this->assertSame( 9, $mirrored[ $this->link_id( $product ) ]['expected'] );
		$this->assertSame( 10, $mirrored[ $this->link_id( $product ) ]['wc_stock'], 'the value before the mirror wrote' );
		$this->assertSame( [], $this->reconcile(), 'a second readback finds nothing to do' );
	}

	/**
	 * The mirror write does nothing for a product that does not manage stock, writes nothing when the stock already matches,
	 * and never goes below zero.
	 *
	 * @return void
	 */
	public function test_the_mirror_writes_only_a_changed_managed_stock(): void {
		$mirror    = new Mirror_Stock_Writer();
		$managed   = $this->product( 3 );
		$unmanaged = $this->product( 3, [ 'manage_stock' => false ] );

		$this->assertNull( $mirror->mirror( $this->products[ $unmanaged ], 5, 1 ) );
		$this->assertSame( 3, $this->wc_stock( $unmanaged ) );

		$statements = count( $this->db->statements );
		$this->assertSame( 3, $mirror->mirror( $this->products[ $managed ], 4, 1 ) );
		$this->assertSame( [], preg_grep( '/^UPDATE wp_fake_wc_stock/', array_slice( $this->db->statements, $statements ) ), 'an equal stock is not written again' );

		$this->assertSame( 0, $mirror->mirror( $this->products[ $managed ], 2, 5 ) );
		$this->assertSame( 0, $this->wc_stock( $managed ) );
	}

	/**
	 * The readback reports drift and the next flush repairs it, even when WC's value equals the last one sent.
	 *
	 * @return void
	 */
	public function test_the_readback_reports_and_repairs_drift(): void {
		$product = $this->stock_product( 'a', 1001 );
		$this->hook_stock();
		$this->set_stock( $product, 7 );
		$this->flush();

		$this->market->present['a'] = 5; // Changed in the marketplace's cabinet by hand.
		$drift                      = $this->reconcile();
		$link                       = $this->link_id( $product );

		$this->assertSame( [ $link ], array_keys( $drift ) );
		$this->assertSame( 7, $drift[ $link ]['expected'] );
		$this->assertSame( 5, $drift[ $link ]['present'] );

		$this->flush();

		$this->assertSame( 7, $this->market->present['a'] );
		$this->assertSame( [], $this->reconcile() );
	}

	/**
	 * A readback runs out of budget between warehouses and leaves the rest to the next run; it reads one warehouse per request.
	 *
	 * @return void
	 */
	public function test_the_readback_counts_one_request_per_warehouse(): void {
		$this->stock_product( 'here', 1001 );
		$this->stock_product( 'there', 1002, 10, [ 'warehouse_id' => 99 ] );

		$token = (string) $this->accounts()->claim( $this->account_id, 120 );
		$run   = $this->account_run( $token, 1 );

		$this->readback()->reconcile( $run, [ $this->links()->find_by_offer_id( $this->account_id, 'here' ), $this->links()->find_by_offer_id( $this->account_id, 'there' ) ] );

		$this->assertSame( 1, $run->requests() );
		$this->assertCount( 1, $this->market->requests );
	}

	/**
	 * The warehouse of a link: its own, else the account's default; an oversize product takes only its own.
	 *
	 * @return void
	 */
	public function test_the_warehouse_of_a_link(): void {
		$own      = $this->stock_product( 'own', 1, 1, [ 'warehouse_id' => 99 ] );
		$default  = $this->stock_product( 'default', 2 );
		$oversize = $this->stock_product( 'oversize', 3, 1, [ 'oversize' => true ] );
		$account  = $this->account_now();

		$this->assertSame( 99, Stock_Warehouse::for_link( $account, $this->link_of( $own ) ) );
		$this->assertSame( self::WAREHOUSE, Stock_Warehouse::for_link( $account, $this->link_of( $default ) ) );
		$this->assertNull( Stock_Warehouse::for_link( $account, $this->link_of( $oversize ) ) );

		$this->set_account( [ 'warehouse_id' => 0 ] );
		$this->assertNull( Stock_Warehouse::for_link( $this->account_now(), $this->link_of( $default ) ) );
	}

	/**
	 * An oversize product takes its link's warehouse, else the account's oversize warehouse; with neither it has none, and the
	 * default warehouse is never a substitute.
	 *
	 * @return void
	 */
	public function test_an_oversize_product_takes_the_account_oversize_warehouse_and_never_the_default(): void {
		$own      = $this->stock_product( 'own-big', 1, 1, [ 'oversize' => true, 'warehouse_id' => 77 ] );
		$oversize = $this->stock_product( 'big', 3, 1, [ 'oversize' => true ] );
		$regular  = $this->stock_product( 'regular', 2 );

		$this->set_account( [ 'oversize_warehouse_id' => 555 ] );
		$account = $this->account_now();

		$this->assertSame( 77, Stock_Warehouse::for_link( $account, $this->link_of( $own ) ), 'the link wins' );
		$this->assertSame( 555, Stock_Warehouse::for_link( $account, $this->link_of( $oversize ) ) );
		$this->assertSame( self::WAREHOUSE, Stock_Warehouse::for_link( $account, $this->link_of( $regular ) ), 'a regular product ignores it' );

		$this->set_account( [ 'oversize_warehouse_id' => 0 ] );
		$this->assertNull( Stock_Warehouse::for_link( $this->account_now(), $this->link_of( $oversize ) ), 'no oversize warehouse, no substitute' );
	}

	/**
	 * `off` pushes nothing, mirrors nothing and is not the site's stock either.
	 *
	 * @return void
	 */
	public function test_the_off_mode_syncs_no_stock(): void {
		$product = $this->stock_product( 'a', 1001, 9 );
		$this->market->present['a'] = 4;

		$this->set_account( [ 'stock_mode' => Stock_Mode::OFF ] );
		$account = $this->account_now();

		$this->assertTrue( Stock_Mode::is_off( $account ) );
		$this->assertFalse( Stock_Mode::is_site( $account ) );
		$this->assertSame( [], $this->reconcile() );
		$this->assertSame( 9, $this->wc_stock( $product ), 'WooCommerce stock is untouched' );
		$this->assertSame( [], $this->market->requests, 'the marketplace is not even read' );

		$this->set_account( [ 'stock_mode' => Stock_Mode::SITE ] );
		$this->assertFalse( Stock_Mode::is_off( $this->account_now() ) );
		$this->assertTrue( Stock_Mode::is_site( $this->account_now() ) );
	}

	/**
	 * Builds the filters for orders that all carry one stamp and belong to the test account.
	 *
	 * @param string $stamp Stamp.
	 * @return Order_Stock_Filters
	 */
	private function filters( string $stamp ): Order_Stock_Filters {
		return new Order_Stock_Filters(
			static function () use ( $stamp ): string {
				return $stamp;
			},
			function (): ?Account {
				return $this->account_now();
			}
		);
	}

	/**
	 * Builds an order double.
	 *
	 * @param bool $swapping Whether the order writer marked the order as swapping its lines.
	 * @return \WC_Order
	 */
	private function order( bool $swapping ): \WC_Order {
		$order = \Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( Order_Stock_Filters::META_STOCK_PENDING )->andReturn( $swapping ? '1' : '' );

		return $order;
	}
}

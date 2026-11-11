<?php
/**
 * The marketplace order query and WooCommerce order store on BOTH WooCommerce order datastores.
 *
 * `wc_get_orders()` honours `meta_query` on the HPOS store only; the legacy posts store drops it silently, so a lookup by remote
 * order number would return every order. Each test runs once per datastore (the data provider switches the options the way the
 * shipping module's datastore tests do) against the fake marketplace's order meta.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Flows\Order_Query;
use Woodev\Framework\Marketplace\Flows\Wc_Order_Store;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Tests\Integration\TestCase;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;

class OrderStoresTest extends TestCase {
	private const ACCOUNT = 7;

	/**
	 * The fake marketplace's order meta.
	 *
	 * @var Fake_Order_Meta
	 */
	private $meta;

	/**
	 * Builds the meta contract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_manage_stock', 'yes' );
		$this->meta = new Fake_Order_Meta();
	}

	/**
	 * Both datastores.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function datastore_provider(): array {
		return [
			'HPOS'       => [ true ],
			'legacy CPT' => [ false ],
		];
	}

	/**
	 * Selects the datastore.
	 *
	 * @param bool $hpos True for HPOS, false for the posts store.
	 * @return void
	 */
	private function use_datastore( bool $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );
	}

	/**
	 * Each remote order resolves to itself and nothing else, and the unrelated order is never returned.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_every_remote_order_resolves_to_itself_and_a_normal_order_is_never_returned( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$normal = $this->plain_order( null );
		$a      = $this->plain_order( 'A-1' );
		$b      = $this->plain_order( 'B-1' );

		$this->assertSame( [ $a ], Order_Query::ids_by_meta( $this->clause( 'A-1' ) ) );
		$this->assertSame( [ $b ], Order_Query::ids_by_meta( $this->clause( 'B-1' ) ) );
		$this->assertSame( [], Order_Query::ids_by_meta( $this->clause( 'C-1' ) ) );
		$this->assertNotContains( $normal, Order_Query::ids_by_meta( $this->clause( 'A-1' ) ) );
	}

	/**
	 * Pages of the same clause follow the id, and the filter does not leak into the next query.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_pages_follow_the_id_and_the_query_leaves_no_filter_behind( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$first  = $this->plain_order( 'SAME' );
		$second = $this->plain_order( 'SAME' );
		$third  = $this->plain_order( 'SAME' );

		$this->assertSame( [ $first, $second ], Order_Query::ids_by_meta( $this->clause( 'SAME' ), 2, 1 ) );
		$this->assertSame( [ $third ], Order_Query::ids_by_meta( $this->clause( 'SAME' ), 2, 2 ) );

		$this->assertFalse( has_filter( Order_Query::CPT_FILTER ) );
		$this->assertFalse( has_filter( Order_Query::UNSUPPORTED_FILTER ) );
	}

	/**
	 * `by_meta` loads the orders and leaves trashed ones out.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_by_meta_leaves_trashed_orders_out( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$live    = $this->plain_order( 'T-1' );
		$trashed = $this->plain_order( 'T-1' );
		wc_get_order( $trashed )->delete( false );

		$found = Order_Query::by_meta( $this->clause( 'T-1' ) );

		$this->assertSame( [ $live ], array_map( static function ( \WC_Order $order ): int {
			return $order->get_id();
		}, $found ) );
	}

	/**
	 * A new order is saved inert (`pending`), provisional, stamped with what the contract says, and found by its identity.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_create_saves_an_inert_provisional_order_the_store_can_find( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$store    = new Wc_Order_Store( $this->meta );
		$wc_order = $store->create( $this->dto( 'M-1', [ [ 'NO-SUCH-SKU', 2 ] ] ) );
		$fresh    = $store->get( $wc_order->get_id() );

		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertSame( 'fake-seller', $fresh->get_created_via() );
		$this->assertTrue( $store->is_provisional( $fresh ) );
		$this->assertSame( 'M-1', $fresh->get_meta( Fake_Order_Meta::ORDER ) );
		$this->assertSame( (string) self::ACCOUNT, $fresh->get_meta( Fake_Order_Meta::CABINET ) );

		$this->assertSame( $wc_order->get_id(), $store->find( $this->dto( 'M-1', [] ) )->get_id() );
		$this->assertNull( $store->find( $this->dto( 'M-2', [] ) ) );

		$notes = wc_get_order_notes( [ 'order_id' => $wc_order->get_id() ] );
		$this->assertStringContainsString( 'Товар не связан', $notes[0]->content );
	}

	/**
	 * `update` brings the order in line: status, members registry, provisional flag gone, stock taken once by WooCommerce.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_update_sets_status_and_members_and_takes_stock_once( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$product = $this->product( 'SKU-A', 10 );
		$store   = new Wc_Order_Store( $this->meta );
		$order   = $this->dto( 'U-1', [ [ 'SKU-A', 2 ] ] );
		$members = [
			'U-1' => [
				'status_canonical' => Status_Map::NEW,
				'quantity'         => 2,
			],
		];

		$wc_order = $store->create( $order );
		$store->update( $wc_order, $order, 'processing', $members, true );
		$store->update( $store->get( $wc_order->get_id() ), $order, 'processing', $members, false );

		$fresh = $store->get( $wc_order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertFalse( $store->is_provisional( $fresh ) );
		$this->assertSame( $members, json_decode( (string) $fresh->get_meta( Wc_Order_Store::META_GROUP_MEMBERS ), true ) );
		$this->assertSame( 8, wc_get_product( $product )->get_stock_quantity(), 'reduced once by two units, not twice' );
	}

	/**
	 * A changed line set on a stock-reduced order gives the old stock back and takes the new, so the order never holds stock twice.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_changed_line_set_gives_stock_back_and_takes_the_new_stock( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$a     = $this->product( 'SKU-A', 10 );
		$b     = $this->product( 'SKU-B', 10 );
		$store = new Wc_Order_Store( $this->meta );

		$first    = $this->dto( 'S-1', [ [ 'SKU-A', 2 ] ] );
		$wc_order = $store->create( $first );
		$store->update( $wc_order, $first, 'processing', [], true );
		$this->assertSame( 8, wc_get_product( $a )->get_stock_quantity() );

		$second = $this->dto( 'S-1', [ [ 'SKU-B', 3 ] ] );
		$store->update( $store->get( $wc_order->get_id() ), $second, 'processing', [], false );

		$this->assertSame( 10, wc_get_product( $a )->get_stock_quantity(), 'the old line gave its stock back' );
		$this->assertSame( 7, wc_get_product( $b )->get_stock_quantity(), 'the new line took its stock' );
		$this->assertSame( '', (string) $store->get( $wc_order->get_id() )->get_meta( Wc_Order_Store::META_STOCK_PENDING ) );
	}

	/**
	 * The process dies right after the replacement two-unit lines were saved and before their stock was taken; the retry of the same
	 * order, whose lines now match, still takes it: stock 8, and an identical retry changes nothing (donor finding 2).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_an_interrupted_line_swap_takes_its_stock_on_the_retry( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$product  = $this->product( 'SKU-A', 10 );
		$store    = new Wc_Order_Store( $this->meta );
		$one      = $this->dto( 'R-1', [ [ 'SKU-A', 1 ] ] );
		$two      = $this->dto( 'R-1', [ [ 'SKU-A', 2 ] ] );
		$wc_order = $store->create( $one );
		$store->update( $wc_order, $one, 'processing', [], true );
		$this->assertSame( 9, wc_get_product( $product )->get_stock_quantity() );

		$die = static function ( $saved ) use ( $wc_order ): void {
			if ( $saved instanceof \WC_Order && $saved->get_id() === $wc_order->get_id() && 2 === array_values( $saved->get_items() )[0]->get_quantity() ) {
				throw new \Error( 'Simulated death after the replacement was saved, before its stock was taken' );
			}
		};
		add_action( 'woocommerce_after_order_object_save', $die );

		try {
			$store->update( $store->get( $wc_order->get_id() ), $two, 'processing', [], false );
			$this->fail( 'The simulated death must stop the write.' );
		} catch ( \Error $e ) {
			$this->assertStringContainsString( 'Simulated death', $e->getMessage() );
		} finally {
			remove_action( 'woocommerce_after_order_object_save', $die );
		}

		$store->update( $store->get( $wc_order->get_id() ), $two, 'processing', [], false );
		$store->update( $store->get( $wc_order->get_id() ), $two, 'processing', [], false );

		$fresh = $store->get( $wc_order->get_id() );
		$this->assertSame( 8, wc_get_product( $product )->get_stock_quantity() );
		$this->assertSame( 2, array_values( $fresh->get_items() )[0]->get_quantity() );
		$this->assertSame( '', (string) $fresh->get_meta( Wc_Order_Store::META_STOCK_PENDING ) );
	}

	/**
	 * An order in a registered custom status that holds stock keeps holding it through a line swap: 1 → 2 units is stock 9 → 8, and
	 * an identical retry changes nothing (donor round 2, finding 8).
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_custom_status_order_keeps_its_stock_through_a_line_swap( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$statuses = static function ( array $known ): array {
			$known['wc-shipped'] = 'Shipped';

			return $known;
		};
		add_filter( 'wc_order_statuses', $statuses );

		try {
			$product  = $this->product( 'SKU-A', 10 );
			$store    = new Wc_Order_Store( $this->meta );
			$one      = $this->dto( 'C-1', [ [ 'SKU-A', 1 ] ] );
			$two      = $this->dto( 'C-1', [ [ 'SKU-A', 2 ] ] );
			$wc_order = $store->create( $one );
			$store->update( $wc_order, $one, 'processing', [], true );

			$order = $store->get( $wc_order->get_id() );
			$order->set_status( 'shipped' );
			$order->save();
			$this->assertSame( 9, wc_get_product( $product )->get_stock_quantity() );
			$this->assertTrue( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );

			$store->update( $store->get( $wc_order->get_id() ), $two, 'shipped', [], false );
			$store->update( $store->get( $wc_order->get_id() ), $two, 'shipped', [], false );

			$fresh = $store->get( $wc_order->get_id() );
			$this->assertSame( 'shipped', $fresh->get_status() );
			$this->assertSame( 2, array_values( $fresh->get_items() )[0]->get_quantity() );
			$this->assertSame( 8, wc_get_product( $product )->get_stock_quantity() );
			$this->assertSame( '', (string) $fresh->get_meta( Wc_Order_Store::META_STOCK_PENDING ) );
		} finally {
			remove_filter( 'wc_order_statuses', $statuses );
		}
	}

	/**
	 * An order that lost its registration goes to the trash and is no longer found.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_discard_moves_the_order_to_the_trash( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$store    = new Wc_Order_Store( $this->meta );
		$wc_order = $store->create( $this->dto( 'D-1', [] ) );

		$store->discard( $wc_order );

		$this->assertNull( $store->get( $wc_order->get_id() ) );
		$this->assertNull( $store->find( $this->dto( 'D-1', [] ) ) );
	}

	/**
	 * Builds the identity clause of a remote order.
	 *
	 * @param string $key Remote order key.
	 * @return array<int,array<string,string>>
	 */
	private function clause( string $key ): array {
		return $this->meta->identity( $this->dto( $key, [] ) );
	}

	/**
	 * Creates a plain order, with the fake marketplace's identity meta when a key is given.
	 *
	 * @param string|null $key Remote order key.
	 * @return int Order id.
	 */
	private function plain_order( ?string $key ): int {
		$order = wc_create_order();

		if ( null !== $key ) {
			$order->update_meta_data( Fake_Order_Meta::ORDER, $key );
		}

		$order->save();

		return $order->get_id();
	}

	/**
	 * Creates a simple product with managed stock and registers it as the product of an article.
	 *
	 * @param string $sku   Article.
	 * @param int    $stock Stock.
	 * @return int Product id.
	 */
	private function product( string $sku, int $stock ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $sku );
		$product->set_sku( $sku );
		$product->set_regular_price( '100' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->save();

		$this->meta->products[ $sku ] = $product;

		return $product->get_id();
	}

	/**
	 * Builds a remote order.
	 *
	 * @param string                  $key   Remote order key.
	 * @param array<int,array{0:string,1:int}> $lines Article and quantity of each line.
	 * @return Order_DTO
	 */
	private function dto( string $key, array $lines ): Order_DTO {
		$built = [];

		foreach ( $lines as $line ) {
			$built[] = new Order_Line( $line[0], [ 'item' => 1 ], $line[1], Money::from_string( '100.00', 'RUB' ), 'Товар ' . $line[0] );
		}

		return new Order_DTO( self::ACCOUNT, $key, '', 'new', Status_Map::NEW, $built );
	}
}

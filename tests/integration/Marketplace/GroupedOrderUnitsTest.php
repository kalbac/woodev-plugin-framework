<?php
/**
 * Units of a grouped marketplace order, on BOTH WooCommerce order datastores: a cancelled unit leaves the order's lines, gives its
 * stock back once, stays with the order as a record, and makes its stock pair due again although the number may not have changed.
 *
 * The writer, the order store, WooCommerce's own stock handling and the stock recorder are the real ones, over real marketplace
 * tables (made and dropped the way {@see PersistenceTest} does it) and the fake marketplace's order meta. «Sent» means what the
 * flusher would record after an accepted push: the sent payload equals the pending one.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Order_Units;
use Woodev\Framework\Marketplace\Flows\Wc_Order_Store;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Orders\Registry_Order_Writer;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Tests\Integration\TestCase;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;

class GroupedOrderUnitsTest extends TestCase {

	use Savepoint_Stock;

	private const WAREHOUSE = 22;

	/**
	 * Schema under test: an id no real plugin uses, so the tables are this test's own.
	 *
	 * @var Schema
	 */
	private $schema;

	/**
	 * The fake marketplace's order meta.
	 *
	 * @var Fake_Order_Meta
	 */
	private $meta;

	/**
	 * Account id.
	 *
	 * @var int
	 */
	private $account_id;

	/**
	 * Article keys of every announcement of cancelled units, one entry per announcement.
	 *
	 * @var array<int,string[]>
	 */
	private $announced = [];

	/**
	 * Creates real tables, then lets the WordPress test case open its transaction and takes its temporary-table filters off.
	 */
	protected function setUp(): void {
		$this->schema = new Schema( 'itunits' );
		$this->drop_tables();
		( new Lifecycle( $this->get_test_plugin(), $this->schema ) )->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		update_option( 'woocommerce_manage_stock', 'yes' );

		$this->meta      = new Fake_Order_Meta();
		$this->announced = [];

		$this->account_id = $this->accounts()->save(
			new Account(
				0,
				'itunits',
				'Основной',
				true,
				[
					'cabinet' => 'cabinet-1',
					'token'   => 'integration-token',
				],
				null,
				[
					'warehouse_id' => self::WAREHOUSE,
					'stock_mode'   => Wpdb_Account_Store::STOCK_SITE,
				]
			)
		);

		$stock = new Stock_Intents( [ $this->accounts(), 'get' ], $this->links(), $this->intents(), $this->queue(), new Stock_Policy( Stock_Policy::FORMULA_WC_ONLY ) );
		$stock->register();

		$hook = Wc_Order_Store::units_cancelled_hook( $this->meta->created_via() );
		add_action( $hook, [ $stock, 'on_units_cancelled' ], 10, 2 );
		add_action(
			$hook,
			function ( int $account_id, array $offer_ids ): void {
				$this->announced[] = $offer_ids;
			},
			10,
			2
		);
	}

	/**
	 * Lets the WordPress test case roll back, then drops the real tables.
	 */
	protected function tearDown(): void {
		parent::tearDown();

		$this->drop_tables();
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
	 * Cancel A, poll three more times, cancel B: stock comes back once per unit, the records and the total follow.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_cancelled_unit_gives_back_its_own_stock_once_and_stays_with_the_order( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$a = $this->product( 'SKU-A', 10 );
		$b = $this->product( 'SKU-B', 10 );

		$this->write( '1', 'SKU-A', Status_Map::NEW );
		$order_id = $this->write( '2', 'SKU-B', Status_Map::NEW );

		$this->assertSame( [ 9, 9 ], [ $this->stock( $a ), $this->stock( $b ) ] );
		$this->assertSame( [ '1', '2' ], $this->units_of_items( $order_id ) );

		$this->write( '1', 'SKU-A', Status_Map::CANCELLED );

		$this->assertSame( [ 10, 9 ], [ $this->stock( $a ), $this->stock( $b ) ], 'A is back, B is still taken' );
		$this->assertSame( [ '2' ], $this->units_of_items( $order_id ) );
		$this->assertSame( '100.00', wc_format_decimal( wc_get_order( $order_id )->get_total(), 2 ) );
		$this->assertSame( [ true, false ], $this->cancelled_flags( $order_id ) );

		$records = wc_get_order( $order_id )->get_meta( Order_Units::META );

		foreach ( [ '2026-11-03T10:00:00Z', '2026-11-04T10:00:00Z', '2026-11-05T10:00:00Z' ] as $later ) {
			$this->write( '1', 'SKU-A', Status_Map::CANCELLED, $later );
		}

		$this->assertSame( [ 10, 9 ], [ $this->stock( $a ), $this->stock( $b ) ] );
		$this->assertSame( $records, wc_get_order( $order_id )->get_meta( Order_Units::META ) );
		$this->assertSame( [ [ 'SKU-A' ] ], $this->announced, 'announced once' );

		$this->write( '2', 'SKU-B', Status_Map::CANCELLED );
		$this->write( '2', 'SKU-B', Status_Map::CANCELLED, '2026-11-06T10:00:00Z' );

		$this->assertSame( 'cancelled', wc_get_order( $order_id )->get_status() );
		$this->assertSame( [ 10, 10 ], [ $this->stock( $a ), $this->stock( $b ) ], 'B came back once' );
		$this->assertSame( [], $this->units_of_items( $order_id ) );
		$this->assertSame( [ true, true ], $this->cancelled_flags( $order_id ) );
		$this->assertSame( [ [ 'SKU-A' ], [ 'SKU-B' ] ], $this->announced );
	}

	/**
	 * Two units of the same size, one cancelled: exactly one comes back.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_one_of_two_equal_units_gives_back_exactly_one( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$product = $this->product( 'SKU-E', 10 );

		$this->write( '1', 'SKU-E', Status_Map::NEW );
		$order_id = $this->write( '2', 'SKU-E', Status_Map::NEW );
		$this->assertSame( 8, $this->stock( $product ) );

		$this->write( '1', 'SKU-E', Status_Map::CANCELLED );

		$this->assertSame( 9, $this->stock( $product ) );
		$this->assertSame( [ '2' ], $this->units_of_items( $order_id ) );
	}

	/**
	 * A unit that arrives already cancelled changes no number, and its pair is due all the same.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_unit_cancelled_before_it_was_ingested_makes_its_pair_due( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$product = $this->product( 'SKU-A', 10 );
		$this->send_stock( $product, 7 );

		$order_id = $this->write( '1', 'SKU-A', Status_Map::CANCELLED );

		$this->assertSame( 7, $this->stock( $product ) );
		$this->assertSame( 'cancelled', wc_get_order( $order_id )->get_status() );
		$this->assertSame( [ [ 'stock' => 7 ] ], $this->due() );
	}

	/**
	 * A unit ordered and cancelled between two sends brings the number back to the sent one; the pair is due.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_unit_cancelled_before_the_next_send_makes_its_pair_due( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$product = $this->product( 'SKU-A', 5 );
		$this->send_stock( $product, 10 );

		$this->write( '1', 'SKU-A', Status_Map::NEW );
		$this->write( '1', 'SKU-A', Status_Map::CANCELLED );

		$this->assertSame( 10, $this->stock( $product ) );
		$this->assertSame( [ [ 'stock' => 10 ] ], $this->due() );
	}

	/**
	 * The line that stays keeps its item and its tax; the tax lines and the total are the remaining unit's alone.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_partial_cancellation_leaves_the_remaining_unit_with_its_own_tax( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->product( 'SKU-A', 10 );
		$this->product( 'SKU-B', 10 );
		$this->write( '1', 'SKU-A', Status_Map::NEW );
		$order_id = $this->write( '2', 'SKU-B', Status_Map::NEW );
		$order    = wc_get_order( $order_id );

		foreach ( $order->get_items() as $item ) {
			$item->set_taxes(
				[
					'total'    => [ 1 => '20' ],
					'subtotal' => [ 1 => '20' ],
				]
			);
			$item->save();
		}

		$order->update_taxes();
		$order->calculate_totals( false );
		$this->assertSame( '240.00', wc_format_decimal( wc_get_order( $order_id )->get_total(), 2 ) );
		$remaining = $this->item_id_of_unit( $order_id, '2' );

		$this->write( '1', 'SKU-A', Status_Map::CANCELLED );

		$this->assertSame( [ '120.00', '20.00', '20.00' ], $this->totals( $order_id ), 'total, tax, sum of the tax lines' );
		$this->assertSame( $remaining, $this->item_id_of_unit( $order_id, '2' ), 'the remaining line is the same item' );

		$this->write( '2', 'SKU-B', Status_Map::CANCELLED );

		$this->assertSame( [ '0.00', '0.00', '0.00' ], $this->totals( $order_id ) );
	}

	/**
	 * A listener of WooCommerce's stock hook fails after the stock came back and before the item forgot it: nothing of that step
	 * stays, and the retry gives the unit back once.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_an_interrupted_give_back_returns_the_unit_once( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$a = $this->product( 'SKU-A', 10 );
		$b = $this->product( 'SKU-B', 10 );
		$this->write( '1', 'SKU-A', Status_Map::NEW );
		$order_id = $this->write( '2', 'SKU-B', Status_Map::NEW );

		$this->interrupt_once_at_stock_of(
			$a,
			function (): void {
				$this->write( '1', 'SKU-A', Status_Map::CANCELLED );
			}
		);

		$this->assertSame( [ 9, 9 ], [ $this->stock( $a ), $this->stock( $b ) ], 'the interrupted step left nothing' );

		$this->write( '1', 'SKU-A', Status_Map::CANCELLED );
		$this->write( '1', 'SKU-A', Status_Map::CANCELLED, '2026-11-03T10:00:00Z' );

		$this->assertSame( [ 10, 9 ], [ $this->stock( $a ), $this->stock( $b ) ] );
		$this->assertSame( [ '2' ], $this->units_of_items( $order_id ) );
		$this->assertSame( 'processing', wc_get_order( $order_id )->get_status() );
	}

	/**
	 * The same for a posting that is one order and whose quantity changes: the old quantity comes back once.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_an_interrupted_line_change_of_a_one_order_posting_returns_its_stock_once( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$product = $this->product( 'SKU-A', 10 );
		$writer  = $this->writer();
		$two     = $this->member( 'P-1', 'SKU-A', Status_Map::NEW, '', '', 2 );
		$one     = $this->member( 'P-1', 'SKU-A', Status_Map::NEW, '', '', 1 );

		$writer->upsert( $two, new Status_Map() );
		$this->assertSame( 8, $this->stock( $product ) );

		$this->interrupt_once_at_stock_of(
			$product,
			static function () use ( $writer, $one ): void {
				$writer->upsert( $one, new Status_Map() );
			}
		);

		$this->assertSame( 8, $this->stock( $product ) );

		$writer->upsert( $one, new Status_Map() );
		$writer->upsert( $one, new Status_Map() );

		$this->assertSame( 9, $this->stock( $product ) );
	}

	/**
	 * A pair that used up its attempts is sent again for a newly cancelled unit; the next poll of the same unit changes nothing.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_cancellation_arms_a_pair_that_had_failed_for_good( bool $hpos ): void {
		global $wpdb;

		$this->use_datastore( $hpos );

		$product = $this->product( 'SKU-A', 10 );
		$this->send_stock( $product, 7 );

		$table = $this->schema->table_name( 'sync_state', $wpdb->prefix );
		$wpdb->query( "UPDATE {$table} SET sent_payload = NULL, attempts = 5" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Test table.
		$this->assertSame( [], $this->due() );

		$this->write( '1', 'SKU-A', Status_Map::CANCELLED );

		$this->assertSame( [ [ 'stock' => 7 ] ], $this->due() );

		$wpdb->query( "UPDATE {$table} SET attempts = 5" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Test table.
		$this->write( '1', 'SKU-A', Status_Map::CANCELLED, '2026-11-03T10:00:00Z' );

		$this->assertSame( [], $this->due(), 'a unit already known as cancelled is no new reason' );
	}

	/**
	 * A posting that is one order keeps no units, tags no line and announces nothing when it is cancelled.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_one_order_posting_keeps_no_units( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->product( 'SKU-A', 10 );
		$writer = $this->writer();

		$order = $writer->upsert( $this->member( 'P-1', 'SKU-A', Status_Map::NEW ), new Status_Map() );
		$this->assertSame( [ '' ], $this->units_of_items( $order->get_id() ) );

		$writer->upsert( $this->member( 'P-1', 'SKU-A', Status_Map::CANCELLED ), new Status_Map() );

		$this->assertSame( [], Order_Units::of( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( [], $this->announced );
	}

	/**
	 * Writes one member of the cart.
	 *
	 * @param string $id         Member id.
	 * @param string $sku        Article.
	 * @param string $canonical  Canonical status.
	 * @param string $updated_at The marketplace's time of the change.
	 * @return int Order id.
	 */
	private function write( string $id, string $sku, string $canonical, string $updated_at = '2026-11-02T10:00:00Z' ): int {
		return $this->writer()->upsert_member( $this->member( $id, $sku, $canonical, 'cart-1', $updated_at ), new Status_Map() )->get_id();
	}

	private function member( string $id, string $sku, string $canonical, string $group = '', string $updated_at = '', int $quantity = 1 ): Order_DTO {
		$line  = new Order_Line( $sku, [ 'item' => 1 ], $quantity, Money::from_string( '100.00', 'RUB' ), 'Товар ' . $sku );
		$dates = '' === $updated_at ? [] : [ 'updated_at' => $updated_at ];

		return new Order_DTO( $this->account_id, $id, $group, $canonical, $canonical, [ $line ], [], null, $dates );
	}

	private function writer(): Registry_Order_Writer {
		global $wpdb;

		return new Registry_Order_Writer(
			new Order_Registry( $wpdb, $this->schema->table_name( 'postings', $wpdb->prefix ) ),
			new Wc_Order_Store( $this->meta, $this->savepoint_stock_transaction() ),
			static function (): string {
				return 'wc-processing';
			}
		);
	}

	/**
	 * Creates a simple product with managed stock, the product of its article, linked to a card whose stock the shop sends.
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
		$this->links()->save( new Product_Link( $this->account_id, $product->get_id(), 0, $sku, [], Product_Link::STATE_CREATED, [ 'sync_stock' => true ] ) );

		return $product->get_id();
	}

	private function stock( int $product_id ): int {
		wc_delete_product_transients( $product_id );

		return (int) wc_get_product( $product_id )->get_stock_quantity();
	}

	/**
	 * @param int $order_id Order id.
	 * @return string[] The unit each line item names, sorted.
	 */
	private function units_of_items( int $order_id ): array {
		$units = [];

		foreach ( wc_get_order( $order_id )->get_items() as $item ) {
			$units[] = (string) $item->get_meta( Wc_Order_Store::ITEM_UNIT );
		}

		sort( $units );

		return $units;
	}

	/**
	 * Runs a write that dies in a listener of the stock hook of one product, the first time it fires.
	 *
	 * @param int      $product_id Product whose stock write is interrupted.
	 * @param callable $write      The write.
	 * @return void
	 */
	private function interrupt_once_at_stock_of( int $product_id, callable $write ): void {
		$interrupt = static function ( $product ) use ( $product_id ): void {
			if ( $product->get_id() === $product_id ) {
				throw new \RuntimeException( 'interrupted after the stock write' );
			}
		};
		add_action( 'woocommerce_product_set_stock', $interrupt, 99 );

		try {
			$write();
			$this->fail( 'The interruption must stop the write.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'interrupted after the stock write', $e->getMessage() );
		} finally {
			remove_action( 'woocommerce_product_set_stock', $interrupt, 99 );
		}
	}

	/**
	 * @param int    $order_id Order id.
	 * @param string $unit     Unit id.
	 * @return int The id of the unit's line item, 0 for none.
	 */
	private function item_id_of_unit( int $order_id, string $unit ): int {
		foreach ( wc_get_order( $order_id )->get_items() as $item ) {
			if ( (string) $item->get_meta( Wc_Order_Store::ITEM_UNIT ) === $unit ) {
				return $item->get_id();
			}
		}

		return 0;
	}

	/**
	 * @param int $order_id Order id.
	 * @return string[] The order's total, its tax and the sum of its tax lines.
	 */
	private function totals( int $order_id ): array {
		$order = wc_get_order( $order_id );
		$lines = 0.0;

		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$lines += (float) $tax->get_tax_total();
		}

		return [
			wc_format_decimal( $order->get_total(), 2 ),
			wc_format_decimal( $order->get_total_tax(), 2 ),
			wc_format_decimal( $lines, 2 ),
		];
	}

	/**
	 * @param int $order_id Order id.
	 * @return bool[] Whether each unit record is cancelled, by unit id.
	 */
	private function cancelled_flags( int $order_id ): array {
		return array_map(
			static function ( $unit ): bool {
				return $unit->is_cancelled();
			},
			Order_Units::of( wc_get_order( $order_id ) )
		);
	}

	/**
	 * Changes a product's stock and records it as accepted by the marketplace, so that nothing is due.
	 *
	 * @param int $product_id Product id.
	 * @param int $stock      New stock.
	 * @return void
	 */
	private function send_stock( int $product_id, int $stock ): void {
		global $wpdb;

		wc_update_product_stock( wc_get_product( $product_id ), $stock );
		$this->assertSame( [ [ 'stock' => $stock ] ], $this->due() );

		$table = $this->schema->table_name( 'sync_state', $wpdb->prefix );
		$wpdb->query( "UPDATE {$table} SET sent_payload = pending_payload" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Test table.

		$this->assertSame( [], $this->due() );
	}

	/**
	 * @return array<int,array<string,mixed>> The pending payload of every stock pair the flusher would send now.
	 */
	private function due(): array {
		return array_column( $this->queue()->due( $this->account_id, Sync_State_Store::KIND_STOCK, 100 ), 'pending_payload' );
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

	private function accounts(): Wpdb_Account_Store {
		global $wpdb;

		return new Wpdb_Account_Store(
			$wpdb,
			$this->schema->table_name( 'accounts', $wpdb->prefix ),
			new Secret_Cipher( 'integration-salt' ),
			'itunits',
			new Credential_Fields( 'cabinet', [ 'token' ] )
		);
	}

	private function links(): Wpdb_Link_Store {
		global $wpdb;

		return new Wpdb_Link_Store( $wpdb, $this->schema->table_name( 'links', $wpdb->prefix ), $this->schema->table_name( 'link_ids', $wpdb->prefix ) );
	}

	private function intents(): Sync_State_Store {
		global $wpdb;

		return new Sync_State_Store( $wpdb, $this->schema->table_name( 'sync_state', $wpdb->prefix ) );
	}

	private function queue(): Sync_State_Queue {
		global $wpdb;

		return new Sync_State_Queue( $wpdb, $this->schema->table_name( 'sync_state', $wpdb->prefix ), $this->schema->table_name( 'accounts', $wpdb->prefix ), new Sync_Cadence( 0, 5 ) );
	}

	private function drop_tables(): void {
		global $wpdb;

		foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}
}

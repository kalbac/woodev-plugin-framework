<?php
/**
 * Orders the seller delivers, on BOTH WooCommerce order datastores: the address, the shipping line and the paid mark are written,
 * a merchant's correction and another writer's shipping line survive the polls, the buyer's data is exported and erased by
 * WooCommerce's privacy tools and removed with the account, and the orders table finds them by scheme.
 *
 * The writer, the order store, WooCommerce's order and privacy code and the real marketplace tables (made and dropped the way
 * {@see PersistenceTest} does it) are the real ones; the marketplace's order meta is the fake one.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Admin\Order\Seller_Delivery_Panel;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Filter;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Reader;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Deletion\Posting_Eraser;
use Woodev\Framework\Marketplace\Flows\Order_Privacy;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Flows\Wc_Order_Store;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Orders\Registry_Order_Writer;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Tests\Integration\TestCase;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;

class SellerDeliveredOrdersTest extends TestCase {

	use Savepoint_Stock;

	private const ACCOUNT = 1;

	private const BUYER_EMAIL = 'buyer@example.org';

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
	 * Creates real tables, then lets the WordPress test case open its transaction and takes its temporary-table filters off.
	 */
	protected function setUp(): void {
		$this->schema = new Schema( 'itseller' );
		$this->drop_tables();
		( new Lifecycle( $this->get_test_plugin(), $this->schema ) )->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		$this->meta = new Fake_Order_Meta();

		Order_Privacy::forget();
		( new Order_Privacy( $this->meta->created_via() ) )->register();
		update_option( 'woocommerce_erasure_request_removes_order_data', 'yes' );

		// WooCommerce's own analytics listener of the erasure takes an order id in older releases (8.5, 9.3) and is handed the order,
		// which wpdb::prepare() reports. It is not the subject here.
		remove_action( 'woocommerce_privacy_remove_order_personal_data', [ \Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::class, 'anonymize_customer' ] );
	}

	/**
	 * Lets the WordPress test case roll back, then drops the real tables.
	 */
	protected function tearDown(): void {
		$_POST = [];

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
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_the_order_gets_its_address_shipping_line_and_paid_mark( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->write( '1', $this->delivery()->with_payload( [ 'id' => '1' ] ) );
		$order = wc_get_order( $this->write( '2', $this->delivery()->with_payload( [ 'id' => '2' ] ) ) );

		$this->assertSame( 'Москва, Тверская 1', $order->get_shipping_address_1() );
		$this->assertSame( '', $order->get_shipping_city() );
		$this->assertSame( '+79991111111', $order->get_shipping_phone() );
		$this->assertSame( '+79991111111', $order->get_billing_phone() );
		$this->assertSame( 'Анна', $order->get_billing_first_name() );
		$this->assertSame( 'Домофон 12', $order->get_customer_note() );
		$this->assertSame( 'Тест', $order->get_payment_method_title() );
		$this->assertNotNull( $order->get_date_paid() );
		$this->assertSame( [ [ 'Доставка Тест', '1.00' ] ], $this->shipping_lines( $order ) );
		$this->assertSame( '201.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( 'dbs', Seller_Order::scheme( $order ) );
		$this->assertSame( '55.75', Seller_Order::delivery( $order )->latitude() );
		$this->assertSame( [ '#1', '#2' ], array_keys( Seller_Order::payload( $order ) ) );
		$this->assertSame( 'dbs', $this->registry_scheme() );
	}

	/**
	 * Import, the merchant corrects the phone and the address, another writer adds a shipping line, three polls come, one of them
	 * with a new phone: the corrections, the foreign line and the total stay; the marketplace's values and the unedited name follow.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_the_merchants_corrections_and_a_foreign_line_survive_the_polls( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$this->write( '1', $this->delivery()->with_payload( [ 'phone' => '+79991111111' ] ) );
		$order_id = $this->write( '2', $this->delivery() );

		$this->save_in_panel(
			$order_id,
			[
				'phone'     => '+79000000000',
				'address_1' => 'Москва, Арбат 2',
			]
		);
		$this->add_foreign_shipping_line( $order_id );

		$new = $this->delivery()->with_phone( '+79992222222' )->with_recipient( 'Ольга' );
		$this->write( '1', $new->with_payload( [ 'phone' => '+79992222222' ] ), '2026-11-03T10:00:00Z' );
		$this->write( '2', $new, '2026-11-04T10:00:00Z' );
		$this->write( '1', $new, '2026-11-05T10:00:00Z' );

		$order = wc_get_order( $order_id );

		$this->assertSame( '+79000000000', $order->get_shipping_phone() );
		$this->assertSame( '+79000000000', $order->get_billing_phone() );
		$this->assertSame( 'Москва, Арбат 2', $order->get_shipping_address_1() );
		$this->assertSame( 'Ольга', $order->get_shipping_first_name(), 'The name nobody edited follows the marketplace.' );
		$this->assertSame(
			[
				[ 'Доставка Тест', '1.00' ],
				[ 'СДЭК', '0.00' ],
			],
			$this->shipping_lines( $order )
		);
		$this->assertSame( '201.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( '+79992222222', Seller_Order::payload( $order )['#1']['phone'] );
		$this->assertSame( '+79992222222', Seller_Order::delivery( $order )->phone() );
	}

	/**
	 * A delivery price that arrives or changes later moves the framework's line and the total, never the foreign line.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_later_delivery_price_updates_only_the_framework_line( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$unpriced = ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )->with_address( 'Москва, Тверская 1' );
		$order_id = $this->write( '1', $unpriced );

		$this->assertSame( [], $this->shipping_lines( wc_get_order( $order_id ) ) );
		$this->add_foreign_shipping_line( $order_id );

		$this->write( '1', $unpriced->with_price( Money::from_string( '5.00', 'RUB' ) ), '2026-11-03T10:00:00Z' );
		$this->write( '1', $unpriced->with_price( Money::from_string( '7.00', 'RUB' ) ), '2026-11-04T10:00:00Z' );

		$order = wc_get_order( $order_id );

		$this->assertSame(
			[
				[ 'СДЭК', '0.00' ],
				[ 'Доставка Тест', '7.00' ],
			],
			$this->shipping_lines( $order )
		);
		$this->assertSame( '107.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/**
	 * A phone the merchant typed before the marketplace assigned one is never seeded over; an address the marketplace sends later is
	 * seeded into the still empty fields.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_a_field_filled_before_the_marketplace_told_it_stays( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order_id = $this->write( '1', new Seller_Delivery( 'Тест', 'Доставка Тест' ) );
		$this->save_in_panel( $order_id, [ 'phone' => '+79000000000' ] );

		$this->write( '1', $this->delivery(), '2026-11-03T10:00:00Z' );

		$order = wc_get_order( $order_id );

		$this->assertSame( '+79000000000', $order->get_shipping_phone() );
		$this->assertSame( 'Москва, Тверская 1', $order->get_shipping_address_1() );
	}

	/**
	 * WooCommerce's export and erasure by e-mail carry the marketplace's data, and an erased order stays erased.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_the_buyers_data_is_exported_and_erased( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order_id = $this->write( '1', $this->delivery()->with_other_phone( '+79993333333', '77' )->with_payload( [ 'client' => 'raw-buyer' ] ) );

		$order = wc_get_order( $order_id );
		$order->set_billing_email( self::BUYER_EMAIL );
		$order->save();

		$exported = wp_json_encode( \WC_Privacy_Exporters::order_data_exporter( self::BUYER_EMAIL, 1 ), JSON_UNESCAPED_UNICODE );

		foreach ( [ 'Москва, Тверская 1', '+79991111111', '+79993333333, добавочный 77', '55.75, 37.61', 'Домофон 12', 'raw-buyer' ] as $value ) {
			$this->assertStringContainsString( $value, $exported );
		}

		\WC_Privacy_Erasers::order_data_eraser( self::BUYER_EMAIL, 1 );

		$this->assert_erased( $order_id );

		$this->write( '1', $this->delivery()->with_payload( [ 'client' => 'raw-buyer' ] ), '2026-11-03T10:00:00Z' );

		$this->assert_erased( $order_id );
	}

	/**
	 * The account's deletion removes the marketplace's data from the order, and the order stays.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_the_accounts_deletion_removes_the_marketplaces_data_and_keeps_the_order( bool $hpos ): void {
		$this->use_datastore( $hpos );

		$order_id = $this->write( '1', $this->delivery()->with_payload( [ 'client' => 'raw-buyer' ] ) );

		$this->assertSame( 1, ( new Posting_Eraser( $this->registry() ) )->delete_account( self::ACCOUNT, 500 ) );

		$order = wc_get_order( $order_id );

		$this->assertNull( Seller_Order::delivery( $order ) );
		$this->assertSame( [], Seller_Order::payload( $order ) );
		$this->assertSame( '+79991111111', $order->get_shipping_phone() );
		$this->assertSame( 0, $this->registry()->count_for_account( self::ACCOUNT ) );
	}

	/**
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_the_orders_table_finds_orders_by_scheme( bool $hpos ): void {
		global $wpdb;

		$this->use_datastore( $hpos );

		$order_id = $this->write( '1', $this->delivery() );
		$reader   = new Site_Orders_Reader( $wpdb, $this->schema->table_name( 'postings', $wpdb->prefix ), Site_Orders_Storage::current() );

		$found = static function ( string $scheme ) use ( $reader ): array {
			return array_column( $reader->page( Site_Orders_Filter::from_query( self::ACCOUNT, [ 'filters' => [ 'scheme' => $scheme ] ] ), 1, 20 )['items'], 'order_id' );
		};

		$this->assertSame( [ $order_id ], $found( 'dbs' ) );
		$this->assertSame( [], $found( 'fbs' ) );
	}

	private function delivery(): Seller_Delivery {
		return ( new Seller_Delivery( 'Тест', 'Доставка Тест' ) )
			->with_recipient( 'Анна' )
			->with_phone( '+79991111111' )
			->with_address( 'Москва, Тверская 1', '55.75', '37.61' )
			->with_comment( 'Домофон 12' )
			->with_price( Money::from_string( '1.00', 'RUB' ) );
	}

	/**
	 * Writes one unit of the cart, 100 rubles each.
	 *
	 * @param string          $id         Unit id.
	 * @param Seller_Delivery $delivery   Delivery.
	 * @param string          $updated_at The marketplace's time of the change.
	 * @return int Order id.
	 */
	private function write( string $id, Seller_Delivery $delivery, string $updated_at = '2026-11-02T10:00:00Z' ): int {
		$line  = new Order_Line( 'SKU-' . $id, [ 'item' => 1 ], 1, Money::from_string( '100.00', 'RUB' ), 'Товар ' . $id );
		$order = ( new Order_DTO( self::ACCOUNT, $id, 'cart-1', 'new', Status_Map::NEW, [ $line ], [], null, [ 'updated_at' => $updated_at ] ) )
			->with_scheme( 'dbs' )
			->with_delivery( $delivery );
		$writer = new Registry_Order_Writer(
			$this->registry(),
			new Wc_Order_Store( $this->meta, $this->savepoint_stock_transaction() ),
			static function (): string {
				return 'wc-processing';
			}
		);

		return $writer->upsert_member( $order, new Status_Map() )->get_id();
	}

	/**
	 * Saves the order screen's recipient fields as a shop manager.
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string,string> $fields   Fields.
	 * @return void
	 */
	private function save_in_panel( int $order_id, array $fields ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST = [
			Seller_Delivery_Panel::NONCE_FIELD => wp_create_nonce( Seller_Delivery_Panel::nonce_action( $order_id ) ),
			Seller_Delivery_Panel::INPUT       => $fields,
		];

		$panel = new Seller_Delivery_Panel( new Screen_Names( 'itseller', 'Тест' ), new Fake_Marketplace_Adapter() );

		$this->assertTrue( $panel->save( wc_get_order( $order_id ) ) );

		$_POST = [];
	}

	/**
	 * Adds the shipping line a delivery plugin would add: it adds nothing to the order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private function add_foreign_shipping_line( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$line  = new \WC_Order_Item_Shipping();
		$line->set_method_title( 'СДЭК' );
		$line->set_total( '0' );
		$order->add_item( $line );
		$order->calculate_totals( false );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return array<int,string[]> Title and total of every shipping line, in the order they were added.
	 */
	private function shipping_lines( \WC_Order $order ): array {
		$items = $order->get_items( 'shipping' );
		ksort( $items );

		return array_values(
			array_map(
				static function ( $item ): array {
					return [ $item->get_method_title(), wc_format_decimal( $item->get_total(), 2 ) ];
				},
				$items
			)
		);
	}

	private function assert_erased( int $order_id ): void {
		$order = wc_get_order( $order_id );

		$this->assertSame( 'yes', $order->get_meta( '_anonymized' ) );
		$this->assertNotSame( 'Москва, Тверская 1', $order->get_shipping_address_1() );
		$this->assertNotSame( '+79991111111', $order->get_shipping_phone() );
		$this->assertNotSame( 'Анна', $order->get_shipping_first_name() );
		$this->assertSame( '', $order->get_customer_note() );
		$this->assertNull( Seller_Order::delivery( $order ) );
		$this->assertSame( [], Seller_Order::payload( $order ) );
		$this->assertTrue( Seller_Order::is_seller_delivered( $order ) );
	}

	private function registry(): Order_Registry {
		global $wpdb;

		return new Order_Registry( $wpdb, $this->schema->table_name( 'postings', $wpdb->prefix ) );
	}

	private function registry_scheme(): string {
		global $wpdb;

		$table = $this->schema->table_name( 'postings', $wpdb->prefix );

		return (string) $wpdb->get_var( "SELECT scheme FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Test table.
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

	private function drop_tables(): void {
		global $wpdb;

		foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}
}

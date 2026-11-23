<?php
/**
 * The marketplace «Заказы» table over REST, on a real database and BOTH WooCommerce order datastores (#1928).
 *
 * The table counts its orders in SQL and then loads them with `wc_get_orders()`. The ids used to go in as `include`, which that
 * function does not know: HPOS ignored it and returned the newest orders of the store, so an account whose orders were not the
 * newest ones (created by an older sync, or buried under later orders of the shop) got `rows: []` with `total: 4`.
 *
 * Like {@see PersistenceTest}, the test creates the real marketplace tables itself before the WordPress test case opens its
 * transaction, takes the temporary-table filters off, and drops the tables after the test case has rolled back.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use WP_REST_Request;
use Woodev\Framework\Admin\Records\Records_Registry;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Module;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Tests\Integration\TestCase;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;

require_once dirname( __DIR__, 2 ) . '/unit/Marketplace/Admin/Test_Marketplace_Plugin.php';

class SiteOrdersRestTest extends TestCase {

	/**
	 * The adapter id of the plugin double (`Fake_Marketplace_Adapter::id()`): the tables are named after it.
	 */
	private const ADAPTER = 'fake_wildberries';

	/**
	 * @var Schema
	 */
	private $schema;

	/**
	 * Creates the real tables, then lets the WordPress test case open its transaction and takes its temporary-table filters off.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schema = new Schema( self::ADAPTER );
		$this->drop_tables();
		( new Lifecycle( $this->get_test_plugin(), $this->schema ) )->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	/**
	 * Lets the WordPress test case roll back, then drops the real tables.
	 *
	 * @return void
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
	 * An account whose four orders are NOT the newest of the store (later orders of the shop, and of another account, sit above
	 * them) gets its rows, and the count is the number of rows.
	 *
	 * @dataProvider datastore_provider
	 * @param bool $hpos Datastore.
	 * @return void
	 */
	public function test_an_account_whose_orders_are_not_the_newest_of_the_store_gets_its_rows_and_the_count_is_the_rows( bool $hpos ): void {
		global $wpdb;

		$this->use_datastore( $hpos );

		$accounts = $this->accounts();
		$account  = $accounts->save( $this->account( 'cab-1' ) );
		$other    = $accounts->save( $this->account( 'cab-2' ) );
		$owner    = 'itest-orders-' . ( $hpos ? 'hpos' : 'cpt' );
		$postings = $this->schema->table_name( 'postings', $wpdb->prefix );
		$own      = [];

		foreach ( [ 'A-1', 'A-2', 'A-3', 'A-4' ] as $key ) {
			$own[] = $this->posting( $postings, $account, $key, $this->order( 'completed' ) );
		}

		// Newer than every order of the account: the shop's own orders and another account's.
		$this->order( 'processing' );
		$this->order( 'processing' );
		$this->posting( $postings, $other, 'B-1', $this->order( 'processing' ) );
		$this->posting( $postings, $other, 'B-2', $this->order( 'processing' ) );

		$plugin = new Test_Marketplace_Plugin(
			$owner,
			[
				'id'       => $owner,
				'title'    => 'Тест',
				'icon'     => 'https://example.test/icon.svg',
				'position' => '55.63',
			]
		);

		$this->assertTrue( Site_Orders_Module::register_provider( $plugin, $accounts, $wpdb ), 'The table is registered under the plugin.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// A fresh REST server, so the records routes exist.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		$request = new WP_REST_Request( 'GET', '/woodev/v1/admin/records/' . $owner . '/orders' );
		$request->set_query_params( [ 'context' => [ 'account_id' => (string) $account ] ] );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 4, $data['total'] );
		$this->assertCount( $data['total'], $data['rows'], 'Every counted order is a row.' );
		$this->assertEqualsCanonicalizing(
			$own,
			array_map(
				static function ( string $id ): int {
					return (int) substr( $id, strrpos( $id, ':' ) + 1 );
				},
				array_column( $data['rows'], 'id' )
			),
			'The rows are the orders of the account, not the newest orders of the store.'
		);
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
	 * A plain WooCommerce order, such as the shop or an older sync made: no marketplace meta on it.
	 *
	 * @param string $status Status without `wc-`.
	 * @return int Order id.
	 */
	private function order( string $status ): int {
		$order = wc_create_order();

		$order->set_status( $status );
		$order->save();

		return (int) $order->get_id();
	}

	/**
	 * Adds the registry row that makes an order a posting of an account.
	 *
	 * @param string $table    Full name of the postings table.
	 * @param int    $account  Account id.
	 * @param string $key      Posting number.
	 * @param int    $order_id Order id.
	 * @return int The order id.
	 */
	private function posting( string $table, int $account, string $key, int $order_id ): int {
		global $wpdb;

		$wpdb->insert(
			$table,
			[
				'account_id'    => $account,
				'external_key'  => $key,
				'wc_order_id'   => $order_id,
				'status_raw'    => 'delivering',
				'is_terminal'   => 0,
				'first_seen_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		return $order_id;
	}

	/**
	 * Account store over the real accounts table.
	 *
	 * @return Wpdb_Account_Store
	 */
	private function accounts(): Wpdb_Account_Store {
		global $wpdb;

		return new Wpdb_Account_Store(
			$wpdb,
			$this->schema->table_name( 'accounts', $wpdb->prefix ),
			new Secret_Cipher( 'integration-salt' ),
			self::ADAPTER,
			new Credential_Fields( 'cabinet', [ 'token' ] )
		);
	}

	/**
	 * @param string $identifier Cabinet identifier.
	 * @return Account
	 */
	private function account( string $identifier ): Account {
		return new Account(
			0,
			self::ADAPTER,
			'Кабинет ' . $identifier,
			true,
			[
				'cabinet' => $identifier,
				'token'   => 'integration-secret-token-0000',
			]
		);
	}

	/**
	 * Drops every table of the test schema, for real.
	 *
	 * @return void
	 */
	private function drop_tables(): void {
		global $wpdb;

		foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}
}

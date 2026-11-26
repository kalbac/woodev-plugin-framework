<?php
/**
 * A site whose marketplace tables predate a framework release is brought up to date by the first request, whatever it is, without
 * the consuming plugin being updated (#1797): the new column and the new table exist before any store reads them, the queued data
 * survives, and a price pull afterwards leaves no old send due.
 *
 * `dbDelta` on a real server is the point: the unit suite only records the statements. The tables are created and altered on a
 * connection of their own, and that connection stands in for the global `$wpdb` while the lifecycle and the options API run — they
 * read `global $wpdb` — so the DDL is not inside the WordPress test case's transaction.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Export_Price_Policy;
use Woodev\Framework\Marketplace\Governor\Sync_Cadence;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Framework\Marketplace\Sync\Price_Context;
use Woodev\Framework\Marketplace\Sync\Price_Reader;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;
use Woodev\Tests\Integration\TestCase;

class SchemaRevisionUpgradeTest extends TestCase {
	private const ADAPTER   = 'itestrev';
	private const PLUGIN_ID = 'itest-rev';
	private const VERSION   = '2.0.0';

	/**
	 * @var Schema
	 */
	private $schema;

	/**
	 * The connection that stands in for the global `$wpdb` while the test runs.
	 *
	 * @var \wpdb
	 */
	private $conn;

	/**
	 * The test case's own `$wpdb`, put back at the end.
	 *
	 * @var \wpdb
	 */
	private $original;

	/**
	 * Products the test created.
	 *
	 * @var int[]
	 */
	private $product_ids = [];

	protected function setUp(): void {
		$this->schema = new Schema( self::ADAPTER );

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		global $wpdb;

		$this->original = $wpdb;
		$this->conn     = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->conn->set_prefix( $this->original->prefix );

		// WooCommerce and other plugins register their own tables as properties of the global `$wpdb` (`wc_product_meta_lookup`, …);
		// a fresh connection does not have them and a product save would fail on `Undefined property: wpdb::$wc_product_meta_lookup`.
		foreach ( get_object_vars( $this->original ) as $name => $value ) {
			if ( is_string( $value ) && '' !== $value && 0 === strpos( $value, $this->original->prefix ) && ! isset( $this->conn->$name ) ) {
				$this->conn->$name = $value;
			}
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The connection that carries the DDL stands in for the global one.
		$wpdb = $this->conn;

		wp_cache_flush();
		$this->drop_tables();
	}

	protected function tearDown(): void {
		// The product was written through the connection that carries the DDL, outside the test case's transaction: remove it here.
		foreach ( $this->product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( $product instanceof \WC_Product ) {
				$product->delete( true );
			}
		}

		$this->drop_tables();

		foreach ( [ 'woodev_' . self::PLUGIN_ID . '_version', 'woodev_' . self::PLUGIN_ID . '_schema_revision', 'woodev_' . self::PLUGIN_ID . '_install_lock' ] as $option ) {
			delete_option( $option );
		}

		global $wpdb;

		$this->conn->close();
		$wpdb = $this->original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Put back.

		wp_cache_flush();
		parent::tearDown();
	}

	private function table( string $name ): string {
		return $this->schema->table_name( $name, $this->conn->prefix );
	}

	private function drop_tables(): void {
		foreach ( $this->schema->table_names( $this->conn->prefix ) as $table ) {
			$this->conn->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}

	/**
	 * The plugin the lifecycle belongs to: a double with only what the lifecycle asks of it.
	 *
	 * @return \Woodev_Plugin
	 */
	private function plugin(): \Woodev_Plugin {
		$plugin = \Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( 'itest-rev/itest-rev.php' );
		$plugin->shouldReceive( 'get_id' )->andReturn( self::PLUGIN_ID );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Itest Rev' );
		$plugin->shouldReceive( 'get_plugin_version_name' )->andReturn( 'woodev_' . self::PLUGIN_ID . '_version' );
		$plugin->shouldReceive( 'get_version' )->andReturn( self::VERSION );
		$plugin->shouldReceive( 'log_debug' );
		$plugin->shouldReceive( 'log' );

		// A refused column change reports through the admin notice handler (Lifecycle::report_schema_failure()).
		$notices = \Mockery::mock();
		$notices->shouldReceive( 'add_admin_notice' );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $notices );

		return $plugin;
	}

	/**
	 * The tables as the previous framework release made them: no `bulk_runs`, and `sync_state` without `pending_source`. Two rows of
	 * queued data go in: a price intent for link 1 (150 pending, never sent) and a stock intent.
	 *
	 * @return void
	 */
	private function install_the_previous_release(): void {
		$statements = $this->schema->statements( $this->conn->prefix, $this->conn->get_charset_collate() );
		$old        = str_replace( "pending_source longtext NULL,\n\t\t\t", '', $statements[ $this->table( 'sync_state' ) ] );

		$this->assertStringNotContainsString( 'pending_source', $old, 'The fixture is the old shape.' );
		$this->assertNotFalse( $this->conn->query( $old ) );

		foreach ( [ 'price', 'stock' ] as $kind ) {
			$this->conn->insert(
				$this->table( 'sync_state' ),
				[
					'account_id'      => 1,
					'link_id'         => 1,
					'warehouse_id'    => 'price' === $kind ? 0 : 7,
					'kind'            => $kind,
					'pending_payload' => 'price' === $kind ? '{"price":"150.00","old_price":"0","min_price":null,"currency_code":"RUB"}' : '{"stock":5}',
					'pending_rev'     => 1,
				]
			);
		}

		update_option( 'woodev_' . self::PLUGIN_ID . '_version', self::VERSION ); // The plugin is installed and its version has not changed.
	}

	private function columns_of_sync_state(): array {
		return (array) $this->conn->get_col( 'SHOW COLUMNS FROM ' . $this->table( 'sync_state' ), 0 );
	}

	/**
	 * The first request after the framework upgrade, of any kind, brings the schema up to date; the data stays.
	 *
	 * @return void
	 */
	public function test_the_first_request_adds_the_column_and_the_table_and_keeps_the_queued_data(): void {
		$this->install_the_previous_release();

		$this->assertNotContains( 'pending_source', $this->columns_of_sync_state() );
		$this->assertSame( [], (array) $this->conn->get_col( $this->conn->prepare( 'SHOW TABLES LIKE %s', $this->table( 'bulk_runs' ) ) ) );

		$lifecycle = new Lifecycle( $this->plugin(), $this->schema );
		$lifecycle->bring_schema_up_to_date(); // What the early `init` hook calls on a cron, REST or admin request alike.

		$this->assertContains( 'pending_source', $this->columns_of_sync_state() );
		$this->assertCount( 1, (array) $this->conn->get_col( $this->conn->prepare( 'SHOW TABLES LIKE %s', $this->table( 'bulk_runs' ) ) ) );
		$this->assertSame( (string) Schema::REVISION, (string) get_option( 'woodev_' . self::PLUGIN_ID . '_schema_revision' ) );
		$this->assertSame( self::VERSION, get_option( 'woodev_' . self::PLUGIN_ID . '_version' ), 'The plugin version is untouched.' );
		$this->assertSame( '{"stock":5}', $this->conn->get_var( 'SELECT pending_payload FROM ' . $this->table( 'sync_state' ) . " WHERE kind = 'stock'" ), 'The queued data survives dbDelta.' );

		$lifecycle->bring_schema_up_to_date(); // The second request is one option read.
		$this->assertSame( 1, (int) $this->conn->get_var( 'SELECT COUNT(*) FROM ' . $this->table( 'sync_state' ) . " WHERE kind = 'price'" ) );
	}

	/**
	 * A site at revision 3 has the postings registry without `processing_since` (#1933): the first request adds the column, an existing
	 * row keeps its data and reads NULL there (so the stuck postings fall back to `first_seen_at`), and the revision moves on.
	 *
	 * @return void
	 */
	public function test_the_first_request_adds_the_processing_date_to_the_postings_and_keeps_the_rows(): void {
		foreach ( $this->schema->statements( $this->conn->prefix, $this->conn->get_charset_collate() ) as $name => $statement ) {
			// Every table as the current release makes it, the postings as revision 3 made them.
			$statement = $name === $this->table( 'postings' ) ? str_replace( "processing_since datetime NULL DEFAULT NULL,\n\t\t\t", '', $statement ) : $statement;

			$this->assertNotFalse( $this->conn->query( $statement ) );
		}

		$this->assertNotContains( 'processing_since', (array) $this->conn->get_col( 'SHOW COLUMNS FROM ' . $this->table( 'postings' ), 0 ), 'The fixture is the old shape.' );

		$this->conn->insert(
			$this->table( 'postings' ),
			[
				'account_id'    => 1,
				'external_key'  => 'P-1',
				'wc_order_id'   => 700,
				'status_raw'    => 'awaiting_packaging',
				'first_seen_at' => '2026-11-20 10:00:00',
			]
		);
		update_option( 'woodev_' . self::PLUGIN_ID . '_version', self::VERSION );
		update_option( 'woodev_' . self::PLUGIN_ID . '_schema_revision', 3 );

		( new Lifecycle( $this->plugin(), $this->schema ) )->bring_schema_up_to_date();

		$this->assertContains( 'processing_since', (array) $this->conn->get_col( 'SHOW COLUMNS FROM ' . $this->table( 'postings' ), 0 ) );
		$this->assertSame( (string) Schema::REVISION, (string) get_option( 'woodev_' . self::PLUGIN_ID . '_schema_revision' ) );

		$row = $this->conn->get_row( 'SELECT status_raw, first_seen_at, processing_since FROM ' . $this->table( 'postings' ) . " WHERE external_key = 'P-1'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The test's own table.

		$this->assertSame( 'awaiting_packaging', $row['status_raw'], 'The row survives dbDelta.' );
		$this->assertSame( '2026-11-20 10:00:00', $row['first_seen_at'] );
		$this->assertNull( $row['processing_since'], 'An old row has no processing date: it counts from first_seen_at.' );
	}

	/**
	 * A site at revision 4 has neither the barcodes table nor the unit columns of the cards list (#2000): the first request adds
	 * both, an existing card row stays, and a link then keeps its barcodes byte for byte — two that differ in case included.
	 *
	 * @return void
	 */
	public function test_the_first_request_adds_the_barcodes_and_the_unit_columns(): void {
		foreach ( $this->schema->statements( $this->conn->prefix, $this->conn->get_charset_collate() ) as $name => $statement ) {
			if ( $name === $this->table( 'link_barcodes' ) ) {
				continue;
			}

			if ( $name === $this->table( 'cards' ) ) {
				$statement = (string) preg_replace( '/^\s*(unit_card|unit_article|unit_data) .*\n|,\s*KEY account_unit \(account_id,unit_card\)/m', '', $statement );
			}

			$this->assertNotFalse( $this->conn->query( $statement ) );
		}

		$this->conn->insert(
			$this->table( 'cards' ),
			[
				'account_id' => 1,
				'card_key'   => str_repeat( 'a', 32 ),
				'offer_id'   => 'OLD-1',
				'updated_at' => '2026-11-20 10:00:00',
			]
		);
		update_option( 'woodev_' . self::PLUGIN_ID . '_version', self::VERSION );
		update_option( 'woodev_' . self::PLUGIN_ID . '_schema_revision', 4 );

		( new Lifecycle( $this->plugin(), $this->schema ) )->bring_schema_up_to_date();

		$columns = (array) $this->conn->get_col( 'SHOW COLUMNS FROM ' . $this->table( 'cards' ), 0 );

		$this->assertSame( [ 'unit_card', 'unit_article', 'unit_data' ], array_values( array_intersect( [ 'unit_card', 'unit_article', 'unit_data' ], $columns ) ) );
		$this->assertSame( (string) Schema::REVISION, (string) get_option( 'woodev_' . self::PLUGIN_ID . '_schema_revision' ) );
		$this->assertSame( 'OLD-1', (string) $this->conn->get_var( 'SELECT offer_id FROM ' . $this->table( 'cards' ) ) );

		$links = new Wpdb_Link_Store( $this->conn, $this->table( 'links' ), $this->table( 'link_ids' ) );

		$links->insert_card( Link_Record::offer( 1, 10, 0, 'coat|M' ), '', null, [ '12345Ejf5', '12345EJF5' ] );

		$this->assertSame( [ '12345Ejf5', '12345EJF5' ], $links->barcodes_of( (int) $links->find( 1, 10 )->link_id() ) );
	}

	/**
	 * Every current table exists and only `sync_state.pending_source` is missing, and the database refuses exactly the `ALTER` that
	 * would add it: `dbDelta` returns normally and the table names are all there, yet no revision may be stored (#1829). Once the
	 * `ALTER` is allowed, the retry adds the column, stores the revision and leaves the queued rows as they were.
	 *
	 * @return void
	 */
	public function test_a_refused_alter_stores_no_revision_and_the_retry_adds_the_column(): void {
		// Every table of the current schema, then the column taken away again: the old shape with nothing else missing.
		foreach ( $this->schema->statements( $this->conn->prefix, $this->conn->get_charset_collate() ) as $statement ) {
			$this->assertNotFalse( $this->conn->query( $statement ) );
		}

		$this->conn->insert(
			$this->table( 'sync_state' ),
			[
				'account_id'      => 1,
				'link_id'         => 1,
				'warehouse_id'    => 7,
				'kind'            => 'stock',
				'pending_payload' => '{"stock":5}',
				'pending_rev'     => 1,
			]
		);
		$this->assertNotFalse( $this->conn->query( 'ALTER TABLE ' . $this->table( 'sync_state' ) . ' DROP COLUMN pending_source' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- The test's own table.
		update_option( 'woodev_' . self::PLUGIN_ID . '_version', self::VERSION );

		$this->assertNotContains( 'pending_source', $this->columns_of_sync_state() );

		$alters  = 0;
		$refuse  = true;
		$refuser = function ( $query ) use ( &$alters, &$refuse ) {
			if ( $refuse && 1 === preg_match( '/^\s*ALTER\s+TABLE\s+`?' . preg_quote( $this->table( 'sync_state' ), '/' ) . '`?\s+ADD\s+COLUMN\s+`?pending_source`?/i', (string) $query ) ) {
				++$alters;

				return 'SELECT 1 FROM `woodev_refused_alter_does_not_exist`'; // The statement the server refuses, in place of the ALTER.
			}

			return $query;
		};
		add_filter( 'query', $refuser );
		$printed = $GLOBALS['wpdb']->hide_errors(); // The refused statement must not print a wpdb error into the test output.

		try {
			$lifecycle = new Lifecycle( $this->plugin(), $this->schema );
			$lifecycle->bring_schema_up_to_date();

			$this->assertSame( 1, $alters, 'dbDelta generated and tried the ALTER of the missing column.' );
			$this->assertNotContains( 'pending_source', $this->columns_of_sync_state(), 'The ALTER was refused.' );
			$this->assertFalse( get_option( 'woodev_' . self::PLUGIN_ID . '_schema_revision' ), 'No revision is stored while the column is missing.' );
			$this->assertNotFalse( get_transient( 'woodev_' . self::PLUGIN_ID . '_schema_retry' ), 'The retry mark is set.' );

			// The refusal ends and the retry mark lapses.
			$refuse = false;
			delete_transient( 'woodev_' . self::PLUGIN_ID . '_schema_retry' );

			$lifecycle->bring_schema_up_to_date();
		} finally {
			remove_filter( 'query', $refuser );
			$GLOBALS['wpdb']->show_errors( $printed );
			delete_transient( 'woodev_' . self::PLUGIN_ID . '_schema_retry' );
		}

		$this->assertContains( 'pending_source', $this->columns_of_sync_state(), 'The retry added the column.' );
		$this->assertSame( (string) Schema::REVISION, (string) get_option( 'woodev_' . self::PLUGIN_ID . '_schema_revision' ) );
		$this->assertSame( '{"stock":5}', $this->conn->get_var( 'SELECT pending_payload FROM ' . $this->table( 'sync_state' ) . " WHERE kind = 'stock'" ), 'The queued row is unchanged.' );
		$this->assertSame( 1, (int) $this->conn->get_var( 'SELECT COUNT(*) FROM ' . $this->table( 'sync_state' ) ) );
	}

	/**
	 * After the upgrade a stock intent records and a price pull finishes: the old 150 is settled and nothing is left due to undo it.
	 *
	 * @return void
	 */
	public function test_stock_recording_and_the_price_pull_work_on_the_upgraded_tables_and_leave_no_old_send_due(): void {
		$this->install_the_previous_release();
		( new Lifecycle( $this->plugin(), $this->schema ) )->bring_schema_up_to_date();

		$accounts = new Wpdb_Account_Store( $this->conn, $this->table( 'accounts' ), new Secret_Cipher( 'integration-salt' ), self::ADAPTER, new Credential_Fields( 'cabinet', [ 'token' ] ) );
		$links    = new Wpdb_Link_Store( $this->conn, $this->table( 'links' ), $this->table( 'link_ids' ) );
		$store    = new Sync_State_Store( $this->conn, $this->table( 'sync_state' ) );
		$queue    = new Price_Sync_Queue( $this->conn, $this->table( 'sync_state' ), $this->table( 'links' ), $this->table( 'accounts' ), new Sync_Cadence( 360, 5 ) );

		$account_id = $accounts->save( new Account( 0, self::ADAPTER, 'Основной', true, [ 'cabinet' => 'c-1', 'token' => 't-1' ], null, [] ) );
		$product    = new \WC_Product_Simple();
		$product->set_regular_price( '150' );
		$product->save();

		$this->product_ids[] = $product->get_id();

		$links->save( new Product_Link( $account_id, $product->get_id(), 0, 'a', [ 'item' => 701 ], Product_Link::STATE_CREATED, [ 'sync_price' => true ] ) );

		$this->assertSame( 1, $account_id, 'The fixture rows belong to account 1 and link 1.' );
		$this->assertSame( 1, (int) $links->find( $account_id, $product->get_id() )->link_id() );

		$this->assertTrue( $store->record( $account_id, 1, 7, Sync_State_Store::KIND_STOCK, [ 'stock' => 6 ] ), 'A stock intent records on the upgraded table.' );
		$this->assertTrue( $store->record( $account_id, 1, 0, Sync_State_Store::KIND_PRICE, [ 'price' => '150.00', 'old_price' => '0', 'min_price' => null, 'currency_code' => 'RUB' ], [ 'regular' => '150', 'sale' => '', 'from' => null, 'to' => null ] ) );
		$this->assertCount( 1, $queue->due( $account_id, 10 ), 'The 150 is due before the pull.' );

		$token     = (string) $accounts->claim( $account_id, Job_Gate::CLAIM_TTL );
		$refresher = new Price_Refresher( $this->reader( [ 'a' => '100.00' ] ), $links, $this->contexts(), $queue );
		$result    = $refresher->pull_once( $accounts->get( $account_id ), [ 'a' ], new Claim_Fence( $accounts->table(), $token ) );
		$accounts->release_claim( $account_id, $token );

		$this->assertSame( [ 'a' ], $result->updated() );
		$this->assertSame( [], $queue->due( $account_id, 10 ), 'No old send is left to undo the pull.' );
	}

	/**
	 * @return Price_Context
	 */
	private function contexts(): Price_Context {
		return new class() implements Price_Context {
			public function product( int $product_id ): ?\WC_Product {
				$product = wc_get_product( $product_id );

				return $product instanceof \WC_Product ? $product : null;
			}

			public function price_pair( Account $account, \WC_Product $product, Link_Record $link ): ?Price_Pair {
				return new Price_Pair( Money::from_string( (string) $product->get_regular_price(), 'RUB' ) );
			}

			public function payload( Price_Pair $pair ): array {
				return Export_Price_Policy::payload( $pair );
			}

			public function price_terms( Account $account, \WC_Product $product ): array {
				return [
					'rule'     => new Price_Rule(),
					'currency' => Price_Currency::none( 'RUB' ),
				];
			}

			public function invert( Money $price, ?Money $old_price, Price_Rule $rule, ?array $override, Price_Currency $currency ): ?array {
				return Export_Price_Policy::invert( $price, $old_price, $rule, $override, $currency );
			}
		};
	}

	/**
	 * @param array<string,string> $prices Article => price the marketplace holds.
	 * @return Price_Reader
	 */
	private function reader( array $prices ): Price_Reader {
		return new class( $prices ) implements Price_Reader {
			private $prices;

			public function __construct( array $prices ) {
				$this->prices = $prices;
			}

			public function read( Account $account, array $offer_ids ): array {
				$read = [];

				foreach ( $offer_ids as $offer_id ) {
					if ( isset( $this->prices[ $offer_id ] ) ) {
						$read[ $offer_id ] = new Price_Pair( Money::from_string( $this->prices[ $offer_id ], 'RUB' ) );
					}
				}

				return $read;
			}
		};
	}
}

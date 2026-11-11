<?php
/**
 * The marketplace persistence layer on a real MySQL / MariaDB.
 *
 * The unit suite runs the stores' SQL on SQLite and the schema through a translation; what only a real server can show is here:
 * `dbDelta` on a second install and on a table that lost a column, the `INSERT … SELECT` and correlated `EXISTS` fences, InnoDB
 * transactions, `LIKE` with escaped underscores, and that a secret never reaches `wp_options`.
 *
 * The WordPress test case wraps every test in a transaction and turns `CREATE TABLE` / `DROP TABLE` into temporary-table
 * statements. This test needs real tables (`SHOW TABLES` does not list temporary ones, which is what the lifecycle looks for), so
 * it creates them itself under a marketplace id of its own, before the WordPress test case opens its transaction (a DDL statement
 * commits, and the test case's own rows must not be committed with it), takes the two filters off, and drops the tables after the
 * test case has rolled back. Rows a test writes into the tables are rolled back with the transaction; the few tests that run DDL
 * themselves clean the other rows they wrote.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Schema_Exception;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Tests\Integration\TestCase;

class PersistenceTest extends TestCase {

	private const SECRET = 'integration-secret-token-0000';

	/**
	 * Schema under test: an id no real plugin uses, so the tables are this test's own.
	 *
	 * @var Schema
	 */
	private $schema;

	/**
	 * Creates real tables, then lets the WordPress test case open its transaction and takes its temporary-table filters off.
	 */
	protected function setUp(): void {
		$this->schema = new Schema( 'itest' );
		$this->drop_tables();
		$this->lifecycle()->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	/**
	 * Lets the WordPress test case roll back, then drops the real tables.
	 */
	protected function tearDown(): void {
		parent::tearDown();

		$this->drop_tables();
	}

	/**
	 * A first install creates every table, on InnoDB (the fences and the label transaction need it).
	 */
	public function test_a_first_install_creates_every_table_on_innodb(): void {
		global $wpdb;

		$this->drop_tables();
		$this->lifecycle()->create_tables();

		$tables = $this->schema->table_names( $wpdb->prefix );
		$this->assertCount( 15, $tables );

		foreach ( $tables as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );

			$this->assertSame( 'InnoDB', $engine, $table );
		}
	}

	/**
	 * A second install is a no-op: `dbDelta` finds nothing to change in any of the 15 statements, and the rows survive.
	 */
	public function test_a_second_install_changes_nothing_and_loses_no_rows(): void {
		global $wpdb;

		// setUp() was the first install.
		$accounts = $this->schema->table_name( 'accounts', $wpdb->prefix );
		$wpdb->insert(
			$accounts,
			[
				'external_id'        => 'cab-1',
				'secret_enc'         => 'x',
				'notification_token' => 't',
			]
		);

		$changes = dbDelta( array_values( $this->schema->statements( $wpdb->prefix, $wpdb->get_charset_collate() ) ) );

		$this->assertSame( [], $changes, 'dbDelta reports no change on an unchanged schema: ' . implode( '; ', $changes ) );

		$this->lifecycle()->create_tables();
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$accounts}" ) );
	}

	/**
	 * An upgrade puts back a column a table lost, which is how a later framework release adds one.
	 */
	public function test_an_upgrade_restores_a_missing_column(): void {
		global $wpdb;

		$links = $this->schema->table_name( 'links', $wpdb->prefix );
		$wpdb->query( "ALTER TABLE {$links} DROP COLUMN last_error_text" );
		$this->assertNotContains( 'last_error_text', $wpdb->get_col( "SHOW COLUMNS FROM {$links}" ) );

		$this->lifecycle()->create_tables();

		$this->assertContains( 'last_error_text', $wpdb->get_col( "SHOW COLUMNS FROM {$links}" ) );
	}

	/**
	 * With a table missing and CREATE refused, the lifecycle says which one instead of pretending.
	 */
	public function test_a_missing_table_after_dbdelta_is_reported(): void {
		global $wpdb;

		$schema  = $this->schema;
		$handler = $this->lifecycle();

		$wpdb->query( 'DROP TABLE ' . $schema->table_name( 'throttle', $wpdb->prefix ) );
		$filter = static function ( $query ) {
			return 0 === strpos( trim( (string) $query ), 'CREATE TABLE' ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $filter );

		try {
			$handler->create_tables();
			$this->fail( 'A table that is still missing must raise Schema_Exception.' );
		} catch ( Schema_Exception $exception ) {
			$this->assertStringContainsString( 'throttle', $exception->getMessage() );
		} finally {
			remove_filter( 'query', $filter );
		}
	}

	/**
	 * With «Удалять данные при удалении плагина» on, uninstall drops the tables, deletes the plugin's option family, its transients
	 * and its product meta, and nothing of another plugin: the `LIKE` patterns run on the server with their escaped underscores.
	 */
	public function test_uninstall_removes_the_plugins_data_and_only_that(): void {
		global $wpdb;

		update_option( Uninstaller::option_prefix( 'itest-plugin' ) . Uninstaller::DELETE_DATA_SETTING, 'yes' );
		update_option( 'woodev_itest-plugin_version', '2.0.0' );
		update_option( 'woodev_other-plugin_version', '1.0.0' );
		( new Cards_Snapshot_Store( Uninstaller::option_prefix( 'itest-plugin' ) ) )->put( 3, [ [ 'offer_id' => 'A' ] ], 100 );
		( new Cards_Snapshot_Store( Uninstaller::option_prefix( 'other-plugin' ) ) )->put( 3, [ [ 'offer_id' => 'B' ] ], 100 );
		$post = self::factory()->post->create();
		update_post_meta( $post, Pinned_Offer_Id::meta_prefix( 'itest' ) . '7', 'MINE' );
		update_post_meta( $post, Pinned_Offer_Id::meta_prefix( 'other' ) . '7', 'THEIRS' );
		update_post_meta( $post, '_unrelated', 'KEEP' );

		try {
			Uninstaller::run( $wpdb, $this->schema, 'itest-plugin' );

			foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
				$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $table );
			}
			$this->assertFalse( get_option( 'woodev_itest-plugin_version' ) );
			$this->assertNull( ( new Cards_Snapshot_Store( Uninstaller::option_prefix( 'itest-plugin' ) ) )->get( 3 ) );
			$this->assertSame( '1.0.0', get_option( 'woodev_other-plugin_version' ) );
			$this->assertNotNull( ( new Cards_Snapshot_Store( Uninstaller::option_prefix( 'other-plugin' ) ) )->get( 3 ) );
			$this->assertSame( '', get_post_meta( $post, Pinned_Offer_Id::meta_prefix( 'itest' ) . '7', true ) );
			$this->assertSame( 'THEIRS', get_post_meta( $post, Pinned_Offer_Id::meta_prefix( 'other' ) . '7', true ) );
			$this->assertSame( 'KEEP', get_post_meta( $post, '_unrelated', true ) );
		} finally {
			// The DROP TABLE above committed these rows; the rollback at the end of the test cannot take them back.
			delete_option( 'woodev_other-plugin_version' );
			delete_transient( 'woodev_other-plugin_cards_3' );
			wp_delete_post( $post, true );
		}
	}

	/**
	 * Default off (the toggle was never saved): deleting the plugin keeps every table, option and product meta, as a WooCommerce
	 * extension does.
	 */
	public function test_uninstall_keeps_the_data_while_the_delete_data_toggle_is_off(): void {
		global $wpdb;

		update_option( 'woodev_itest-plugin_version', '2.0.0' );
		$post = self::factory()->post->create();
		update_post_meta( $post, Pinned_Offer_Id::meta_prefix( 'itest' ) . '7', 'MINE' );

		try {
			$this->assertFalse( Uninstaller::deletes_data( 'itest-plugin' ), 'the toggle was never saved' );

			Uninstaller::run( $wpdb, $this->schema, 'itest-plugin' );

			foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
				$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $table );
			}
			$this->assertSame( '2.0.0', get_option( 'woodev_itest-plugin_version' ) );
			$this->assertSame( 'MINE', get_post_meta( $post, Pinned_Offer_Id::meta_prefix( 'itest' ) . '7', true ) );

			update_option( Uninstaller::option_prefix( 'itest-plugin' ) . Uninstaller::DELETE_DATA_SETTING, 'no' );
			Uninstaller::run( $wpdb, $this->schema, 'itest-plugin' );

			$this->assertSame( '2.0.0', get_option( 'woodev_itest-plugin_version' ), 'an explicit «no» keeps the data too' );
		} finally {
			wp_delete_post( $post, true );
		}
	}

	/**
	 * Acceptance «encrypted secrets never in wp_options», on the real server: after an account is saved, rotated and checked, no
	 * option row, no column of the accounts row and no settings JSON holds the secret.
	 */
	public function test_a_secret_is_never_stored_in_clear_anywhere(): void {
		global $wpdb;

		$store = $this->accounts();
		$id    = $store->save(
			new Account(
				0,
				'itest',
				'Кабинет',
				true,
				[
					'cabinet' => 'cab-1',
					'token'   => self::SECRET,
				]
			)
		);
		$store->replace_secrets( $id, [ 'token' => self::SECRET . '-rotated' ] );

		$accounts = $this->schema->table_name( 'accounts', $wpdb->prefix );
		$row      = $wpdb->get_row( "SELECT * FROM {$accounts} WHERE account_id = {$id}", ARRAY_A );

		$this->assertStringStartsWith( Secret_Cipher::PREFIX, $row['secret_enc'] );
		$this->assertStringNotContainsString( self::SECRET, implode( '|', array_map( 'strval', $row ) ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( self::SECRET ) . '%' ) ) );
		$this->assertSame( self::SECRET . '-rotated', $store->get( $id )->credentials()['token'] );
	}

	/**
	 * The unique keys on the server: one account per identifier, one article per account, many parent rows with no article.
	 */
	public function test_unique_keys_hold_on_mysql(): void {
		$links = $this->links();

		$this->assertTrue( $links->insert_if_absent( Link_Record::offer( 7, 101, 0, 'SAME', [] ) ) );
		$this->assertFalse( $links->insert_if_absent( Link_Record::offer( 7, 101, 0, 'OTHER', [] ) ), 'same product' );
		$this->assertFalse( $links->insert_if_absent( Link_Record::offer( 7, 102, 0, 'SAME', [] ) ), 'same article, same account' );
		$this->assertTrue( $links->insert_if_absent( Link_Record::offer( 8, 102, 0, 'SAME', [] ) ), 'another account' );
		$this->assertTrue( $links->insert_if_absent( Link_Record::parent_row( 7, 200, [] ) ) );
		$this->assertTrue( $links->insert_if_absent( Link_Record::parent_row( 7, 201, [] ) ), 'NULL articles do not collide' );

		global $wpdb;

		$accounts = $this->accounts();
		$accounts->save( $this->account( 'cab-1' ) );

		$wpdb->suppress_errors( true );
		$duplicate = $wpdb->insert(
			$accounts->table(),
			[
				'external_id'        => 'cab-1',
				'secret_enc'         => 'x',
				'notification_token' => 't',
			]
		);
		$wpdb->suppress_errors( false );
		$this->assertFalse( $duplicate, 'the unique key on external_id refuses a second account' );

		$this->expectException( \InvalidArgumentException::class );
		$accounts->save( $this->account( 'cab-1' ) );
	}

	/**
	 * A marketplace's own identifiers, of any name and type, are stored in `link_ids` and found by an indexed read; a link that has
	 * one keeps its article when it is queued again, and detaching deletes them.
	 */
	public function test_remote_identifiers_of_any_name_round_trip_on_mysql(): void {
		global $wpdb;

		$links = $this->links();
		$ids   = [
			'nmID'   => 5000001,
			'imtID'  => 7000001,
			'chrtID' => 'c-9',
		];

		$link_id = $links->save( new Product_Link( 1, 101, 0, 'SKU-1', $ids, 'unlinked' ) );
		$found   = $links->by_remote_id( 1, 'chrtID', 'c-9' );

		$this->assertSame( $link_id, $found->link_id() );
		$this->assertSame( 5000001, $found->remote_id( 'nmID' ) );
		$this->assertSame( 'c-9', $found->remote_id( 'chrtID' ) );
		$this->assertSame( 101, $links->by_remote_id( 1, 'nmID', '5000001' )->wc_product_id() );
		$this->assertNull( $links->by_remote_id( 2, 'nmID', 5000001 ) );

		// The site's collation is case-insensitive; identifiers are not.
		$upper = $links->save( new Product_Link( 1, 102, 0, 'SKU-2', [ 'externalId' => 'shop-A' ], 'unlinked' ) );
		$lower = $links->save( new Product_Link( 1, 103, 0, 'SKU-3', [ 'externalId' => 'shop-a' ], 'unlinked' ) );
		$this->assertSame( $upper, $links->by_remote_id( 1, 'externalId', 'shop-A' )->link_id() );
		$this->assertSame( $lower, $links->by_remote_id( 1, 'externalId', 'shop-a' )->link_id() );
		$this->assertNull( $links->by_remote_id( 1, 'externalid', 'shop-A' ), 'the name is case-sensitive too' );
		$this->assertNull( $links->by_remote_id( 1, 'externalId', 'SHOP-A' ) );

		$this->assertTrue( $links->queue( $link_id, 'RENAMED' ) );
		$this->assertSame( 'SKU-1', $links->find( 1, 101 )->offer_id(), 'the article of a card the marketplace knows is frozen' );

		$this->assertTrue( $links->detach( 1, $link_id ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->schema->table_name( 'link_ids', $wpdb->prefix ) . ' WHERE link_id = %d', $link_id ) ) );
		$this->assertTrue( $links->queue( $link_id, 'RENAMED' ) );
		$this->assertSame( 'RENAMED', $links->find( 1, 101 )->offer_id(), 'with no identifier the article follows again' );
	}

	/**
	 * `INSERT … SELECT` fence: a card row is inserted only while the run still holds the account's claim.
	 */
	public function test_insert_card_is_fenced_by_the_account_claim_in_one_statement(): void {
		$accounts = $this->accounts();
		$account  = $accounts->save( $this->account( 'cab-1' ) );
		$token    = (string) $accounts->claim( $account, 120 );
		$links    = $this->links();
		$mine     = new Claim_Fence( $accounts->table(), $token );
		$theirs   = new Claim_Fence( $accounts->table(), 'not-the-token' );

		$this->assertSame( Wpdb_Link_Store::FENCED_OUT, $links->insert_card( Link_Record::offer( $account, 101, 0, 'A', [ 'remote_ids' => [ 'product_id' => 5 ] ] ), '', $theirs ) );
		$this->assertNull( $links->find( $account, 101 ) );

		$this->assertSame( Wpdb_Link_Store::INSERTED, $links->insert_card( Link_Record::offer( $account, 101, 0, 'A', [ 'remote_ids' => [ 'product_id' => 5 ] ] ), 'Отклонена', $mine ) );
		$this->assertSame( 'Отклонена', $links->last_error( (int) $links->find( $account, 101 )->link_id() )['text'] );
		$this->assertSame( Wpdb_Link_Store::EXISTS, $links->insert_card( Link_Record::offer( $account, 101, 0, 'A', [] ), '', $mine ) );

		$accounts->release_claim( $account, $token );
		$this->assertSame( Wpdb_Link_Store::FENCED_OUT, $links->insert_card( Link_Record::offer( $account, 102, 0, 'B', [] ), '', $mine ), 'a released claim fences the run out' );
	}

	/**
	 * Correlated `EXISTS` fences: queued rows go to a task, the task's rows advance and the remote state is filled only under the
	 * claim.
	 */
	public function test_updates_carry_the_claim_in_their_where_clause(): void {
		$accounts = $this->accounts();
		$account  = $accounts->save( $this->account( 'cab-1' ) );
		$token    = (string) $accounts->claim( $account, 120 );
		$links    = $this->links();
		$mine     = new Claim_Fence( $accounts->table(), $token );
		$theirs   = new Claim_Fence( $accounts->table(), 'not-the-token' );
		$links->insert_if_absent( Link_Record::offer( $account, 101, 0, 'A', [ 'card_state' => 'queued' ] ) );
		$link = (int) $links->find( $account, 101 )->link_id();

		$this->assertSame( [], $links->claim_for_task( 1, [ $link ], $theirs ) );
		$this->assertCount( 1, $links->claim_for_task( 1, [ $link ], $mine ) );
		$this->assertSame( [], $links->claim_for_task( 2, [ $link ], $mine ), 'taken once' );

		$this->assertFalse( $links->advance( $link, 1, 'created', [ 'remote_ids' => [ 'sku' => 9 ] ], $theirs, 'submitted' ) );
		$this->assertTrue( $links->advance( $link, 1, 'created', [ 'remote_ids' => [ 'sku' => 9 ] ], $mine, 'submitted' ) );

		$this->assertFalse( $links->fill_remote_state( $link, [ 'sku' => 99, 'model_id' => 31 ], 'created', true, $accounts->table(), 'not-the-token' ) );
		$this->assertTrue( $links->fill_remote_state( $link, [ 'sku' => 99, 'model_id' => 31 ], 'created', true, $accounts->table(), $token ) );
		$found = $links->find( $account, 101 );
		$this->assertSame( 9, $found->remote_id( 'sku' ), 'a known SKU is kept' );
		$this->assertSame( 31, $found->remote_id( 'model_id' ) );

		$this->assertTrue( $links->mark_archived( $link, 'Архив', $mine ) );
		$this->assertFalse( $links->mark_archived( $link, 'Архив', $theirs ) );
	}

	/**
	 * Transactions on InnoDB: a document is published together with the end of its task; a poll that lost the status fence
	 * rolls the document it inserted back, so nothing is left behind.
	 */
	public function test_a_losing_publish_rolls_its_document_back(): void {
		global $wpdb;

		$labels = new Wpdb_Label_Store( $wpdb, $this->schema->table_name( 'tasks', $wpdb->prefix ), $this->schema->table_name( 'documents', $wpdb->prefix ) );
		$documents = $this->schema->table_name( 'documents', $wpdb->prefix );

		$won  = $labels->add_task( 7, 'remote-1', [ 'A-1' ] );
		$lost = $labels->add_task( 7, 'remote-2', [ 'A-2' ] );
		$labels->finish_task( $lost, Async_Task::STATUS_EXPIRED, [] );

		$document = $labels->publish_document( $won, 7, [ 'A-1' ], 'application/pdf', "%PDF-1.4\x00\xff", [] );

		$this->assertNotNull( $document );
		$this->assertSame( "%PDF-1.4\x00\xff", $labels->document( $document )['body'], 'a blob survives the round trip' );
		$this->assertSame( Async_Task::STATUS_DONE, $labels->task( $won )['status'] );

		$this->assertNull( $labels->publish_document( $lost, 7, [ 'A-2' ], 'application/pdf', 'late', [] ) );
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$documents}" ), 'the late document was rolled back' );
		$this->assertNull( $labels->document_of_task( $lost ) );
		$this->assertSame( Async_Task::STATUS_EXPIRED, $labels->task( $lost )['status'] );
	}

	/**
	 * Builds the lifecycle for the test schema.
	 *
	 * @return Lifecycle
	 */
	private function lifecycle(): Lifecycle {
		return new Lifecycle( $this->get_test_plugin(), $this->schema );
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
			'itest',
			new Credential_Fields( 'cabinet', [ 'token' ] )
		);
	}

	/**
	 * Link store over the real links table.
	 *
	 * @return Wpdb_Link_Store
	 */
	private function links(): Wpdb_Link_Store {
		global $wpdb;

		return new Wpdb_Link_Store( $wpdb, $this->schema->table_name( 'links', $wpdb->prefix ), $this->schema->table_name( 'link_ids', $wpdb->prefix ) );
	}

	/**
	 * An account with one secret.
	 *
	 * @param string $identifier The cabinet reference.
	 * @return Account
	 */
	private function account( string $identifier ): Account {
		return new Account(
			0,
			'itest',
			'Кабинет',
			true,
			[
				'cabinet' => $identifier,
				'token'   => self::SECRET,
			]
		);
	}

	/**
	 * Drops every table of the test schema, for real.
	 */
	private function drop_tables(): void {
		global $wpdb;

		foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}
}

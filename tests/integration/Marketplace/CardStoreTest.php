<?php
/**
 * The stored cards list (#1863) on a real MySQL / MariaDB.
 *
 * The unit suite runs the store's SQL on SQLite; what only the real server shows is here: the multi-row `INSERT … ON DUPLICATE KEY
 * UPDATE` with `VALUES()`, a search that ignores the case of Cyrillic letters (the site's collation) and takes `_` and `%` literally
 * (`esc_like()` with MySQL's backslash), the join with the links table under the site's collation, and the batched `DELETE … LIMIT`
 * sweep. Real tables under a marketplace id of their own, created before the WordPress test case opens its transaction, as in
 * {@see PersistenceTest}.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Tests\Integration\TestCase;

class CardStoreTest extends TestCase {
	private const ACCOUNT = 3;

	/**
	 * Schema under test.
	 *
	 * @var Schema
	 */
	private $schema;

	protected function setUp(): void {
		$this->schema = new Schema( 'itestcards' );
		$this->drop_tables();
		( new Lifecycle( $this->get_test_plugin(), $this->schema ) )->create_tables();

		parent::setUp();

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	protected function tearDown(): void {
		parent::tearDown();

		$this->drop_tables();
	}

	private function store(): Wpdb_Card_Store {
		global $wpdb;

		return new Wpdb_Card_Store( $wpdb, $this->schema->table_name( 'cards', $wpdb->prefix ), $this->schema->table_name( 'links', $wpdb->prefix ), 'woodev_itest-cards_' );
	}

	/**
	 * @param string $offer_id Article.
	 * @param string $name     Name.
	 * @return array<string,mixed>
	 */
	private function row( string $offer_id, string $name ): array {
		return [
			'offer_id'   => $offer_id,
			'name'       => $name,
			'remote_ids' => [ 'product_id' => 101 ],
			'state'      => Link_Record::STATE_CREATED,
			'details_ok' => true,
			'price'      => '1200',
			'stock'      => 2,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return void
	 */
	private function refresh( array $rows ): void {
		$store = $this->store();
		$run   = $store->begin_refresh( self::ACCOUNT, 1700000000 );

		$store->upsert( self::ACCOUNT, $rows, $run, 1, 1700000000 );
		$store->finish_refresh( self::ACCOUNT, $run, 1700000000 );
	}

	/**
	 * @param string $search Search.
	 * @return string[]
	 */
	private function found( string $search ): array {
		return array_column( $this->store()->page( self::ACCOUNT, null, $search, 1, 50 )['cards'], 'offer_id' );
	}

	public function test_the_upsert_replaces_a_known_card_and_keeps_its_place(): void {
		$this->refresh( [ $this->row( 'A-1', 'Кружка' ), $this->row( 'A-2', 'Чашка' ) ] );

		$store = $this->store();
		$store->upsert( self::ACCOUNT, [ array_merge( $this->row( 'A-2', 'Чашка большая' ), [ 'price' => null ] ) ], $store->current_run( self::ACCOUNT ), 0, 1700000100 );

		$card = $store->find( self::ACCOUNT, Wpdb_Card_Store::key( 'A-2' ) );

		$this->assertSame( 2, $store->count( self::ACCOUNT ) );
		$this->assertSame( 'Чашка большая', $card['name'] );
		$this->assertNull( $card['price'] );
		$this->assertSame( 2, $card['stock'] );
		$this->assertSame( [ 'A-1', 'A-2' ], $this->found( '' ) );
	}

	public function test_a_search_ignores_the_case_of_cyrillic_and_takes_wildcards_literally(): void {
		$this->refresh( [ $this->row( 'X_1', 'Платье красное' ), $this->row( 'XA1', 'Юбка' ), $this->row( '50%', 'Шарф' ), $this->row( '500', 'Шапка' ) ] );

		$this->assertSame( [ 'X_1' ], $this->found( 'платье' ) );
		$this->assertSame( [ 'X_1' ], $this->found( 'ПЛАТЬЕ' ) );
		$this->assertSame( [ 'X_1' ], $this->found( 'X_' ) );
		$this->assertSame( [ '50%' ], $this->found( '0%' ) );
	}

	public function test_a_card_is_on_the_site_when_a_link_row_carries_its_article(): void {
		global $wpdb;

		$this->refresh( [ $this->row( 'L-1', 'Один' ), $this->row( 'L-2', 'Два' ) ] );
		$wpdb->insert(
			$this->schema->table_name( 'links', $wpdb->prefix ),
			[
				'account_id'    => self::ACCOUNT,
				'wc_product_id' => 77,
				'kind'          => Link_Record::KIND_OFFER,
				'offer_id'      => 'L-2',
			]
		);

		$on = $this->store()->page( self::ACCOUNT, true, '', 1, 50 );

		$this->assertSame( [ 'L-2' ], array_column( $on['cards'], 'offer_id' ) );
		$this->assertSame( [ 77 ], array_column( $on['cards'], 'wc_product_id' ) );
		$this->assertSame( [ 'on_site' => 1, 'not_on_site' => 1 ], $this->store()->on_site_counts( self::ACCOUNT, '' ) );
	}

	public function test_the_sweep_removes_the_cards_the_last_refresh_did_not_see(): void {
		$rows = [];

		for ( $i = 1; $i <= 1203; $i++ ) {
			$rows[] = $this->row( sprintf( 'S-%05d', $i ), 'Карточка ' . $i );
		}

		$this->refresh( $rows );
		$this->assertSame( 1203, $this->store()->count( self::ACCOUNT ) );

		$this->refresh( array_slice( $rows, 0, 3 ) );

		$this->assertSame( 3, $this->store()->count( self::ACCOUNT ) );
		$this->assertSame( [ 'S-00001', 'S-00002', 'S-00003' ], $this->found( '' ) );
	}

	public function test_the_fenced_sweep_deletes_nothing_for_a_run_that_lost_the_claim(): void {
		global $wpdb;

		$accounts = $this->schema->table_name( 'accounts', $wpdb->prefix );
		$wpdb->insert(
			$accounts,
			[
				'account_id'         => self::ACCOUNT,
				'external_id'        => 'cab-3',
				'secret_enc'         => '',
				'notification_token' => str_repeat( 'n', 32 ),
				'claim_token'        => str_repeat( 'b', 32 ),
				'claim_until'        => gmdate( 'Y-m-d H:i:s', time() + 120 ),
			]
		);
		$this->refresh( [ $this->row( 'KEPT', 'Карточка' ) ] );

		$store = $this->store();
		$run   = $store->begin_refresh( self::ACCOUNT, 1700000100 );

		$this->assertNull( $store->finish_refresh( self::ACCOUNT, $run, 1700000100, new \Woodev\Framework\Marketplace\Data\Claim_Fence( $accounts, str_repeat( 'a', 32 ) ) ) );
		$this->assertSame( [ 'KEPT' ], $this->found( '' ) );
		$this->assertSame( 1, $store->finish_refresh( self::ACCOUNT, $run, 1700000100, new \Woodev\Framework\Marketplace\Data\Claim_Fence( $accounts, str_repeat( 'b', 32 ) ) ) );
		$this->assertSame( [], $this->found( '' ) );
	}

	private function drop_tables(): void {
		global $wpdb;

		foreach ( $this->schema->table_names( $wpdb->prefix ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are this test's own.
		}
	}
}

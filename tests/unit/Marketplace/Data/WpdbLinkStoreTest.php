<?php
/**
 * The product link store on SQLite, over the real columns of the schema.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Links\Link_Store;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Link_Store;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * `links`: offer rows, parent rows with a NULL `offer_id`, the two unique keys, insert-only writes, and the writes that carry the
 * account row's claim in their SQL.
 */
final class WpdbLinkStoreTest extends TestCase {
	private const NOW = 1794139200;

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Store.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $store;

	/**
	 * Boots the stubs and the tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->store = new Wpdb_Link_Store(
			$this->db,
			Sqlite_Tables::table( 'links' ),
			Sqlite_Tables::table( 'link_ids' ),
			static function (): int {
				return self::NOW;
			}
		);
	}

	/**
	 * Tears the stubs down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The store is the layer's `Link_Store`.
	 *
	 * @return void
	 */
	public function test_it_implements_the_layer_contract(): void {
		$this->assertInstanceOf( Link_Store::class, $this->store );
	}

	/**
	 * A row comes back as it went in, JSON overrides included.
	 *
	 * @return void
	 */
	public function test_a_row_round_trips_with_its_overrides(): void {
		$record = Link_Record::offer(
			7,
			101,
			0,
			'SKU-1',
			[
				'remote_ids'          => [
					'product_id' => 555,
					'sku'        => 777,
				],
				'card_state'          => 'created',
				'sync_price'          => 1,
				'warehouse_id'        => 22,
				'price_rule_override' => [
					'price' => [
						'kind'  => 'percent',
						'value' => 20,
					],
				],
				'attribute_overrides' => [
					'85' => [
						[
							'dictionary_value_id' => 5,
							'value'               => 'Brand',
						],
					],
				],
				'mapping_id'          => 3,
			]
		);

		$this->assertTrue( $this->store->insert_if_absent( $record ) );

		$found = $this->store->find( 7, 101 );

		$this->assertNotNull( $found );
		$this->assertSame( 'SKU-1', $found->offer_id() );
		$this->assertSame( 555, $found->remote_id( 'product_id' ) );
		$this->assertSame( 22, $found->warehouse_id() );
		$this->assertTrue( $found->sync_price() );
		$this->assertFalse( $found->sync_stock() );
		$this->assertSame( 3, $found->mapping_id() );
		$this->assertSame( 20, $found->price_rule_override()['price']['value'] );
		$this->assertSame( 'Brand', $found->attribute_overrides()['85'][0]['value'] );
	}

	/**
	 * Insert-only: a row that exists is never replaced, whatever the new value says.
	 *
	 * @return void
	 */
	public function test_an_existing_row_is_never_overwritten(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'SKU-1', [ 'warehouse_id' => 11 ] ) );

		$inserted = $this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'SKU-OTHER', [ 'warehouse_id' => 99 ] ) );

		$this->assertFalse( $inserted );
		$this->assertSame( 'SKU-1', $this->store->find( 7, 101 )->offer_id() );
		$this->assertSame( 11, $this->store->find( 7, 101 )->warehouse_id() );
	}

	/**
	 * The two per-product switches default to off: a new row never makes a product push to a live marketplace on its own.
	 *
	 * @return void
	 */
	public function test_a_new_row_pushes_nothing_until_a_creator_says_so(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'SKU-1', [] ) );

		$found = $this->store->find( 7, 101 );

		$this->assertFalse( $found->sync_stock() );
		$this->assertFalse( $found->sync_price() );
		$this->assertSame( Link_Record::STATE_UNLINKED, $found->card_state() );
	}

	/**
	 * `offer_id` is unique per account; another account may reuse it.
	 *
	 * @return void
	 */
	public function test_offer_id_is_unique_per_account_only(): void {
		$this->assertTrue( $this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'SAME', [] ) ) );
		$this->assertFalse( $this->store->insert_if_absent( Link_Record::offer( 7, 102, 0, 'SAME', [] ) ), 'a second product of the account cannot take the offer_id' );
		$this->assertTrue( $this->store->insert_if_absent( Link_Record::offer( 8, 102, 0, 'SAME', [] ) ), 'another account can' );
		$this->assertSame( 101, $this->store->find_by_offer_id( 7, 'SAME' )->wc_product_id() );
	}

	/**
	 * Parent rows carry a NULL `offer_id`, so many of them coexist, and they are never a card.
	 *
	 * @return void
	 */
	public function test_parent_rows_have_no_offer_id_and_are_not_cards(): void {
		$this->assertTrue( $this->store->insert_if_absent( Link_Record::parent_row( 7, 200, [ 'mapping_id' => 4 ] ) ) );
		$this->assertTrue( $this->store->insert_if_absent( Link_Record::parent_row( 7, 201, [] ) ) );

		$parent = $this->store->find( 7, 200 );

		$this->assertFalse( $parent->is_offer() );
		$this->assertNull( $parent->offer_id() );
		$this->assertNull( $this->store->by_product( 7, 200 ), 'the layer never sees a parent as a link' );
		$this->assertSame( 2, $this->store->count( 7, Link_Record::KIND_PARENT ) );
		$this->assertSame( 0, $this->store->count( 7, Link_Record::KIND_OFFER ) );
		$this->assertSame( 2, $this->store->count( 7 ) );
	}

	/**
	 * An article as long as the widest policy an adapter may declare fits the column.
	 *
	 * @return void
	 */
	public function test_a_long_article_is_stored_whole(): void {
		$article = str_repeat( 'A', 120 );

		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, $article, [] ) );

		$this->assertSame( $article, $this->store->find_by_offer_id( 7, $article )->offer_id() );
	}

	/**
	 * The shared interface finds links by product, article and remote identifier.
	 *
	 * @return void
	 */
	public function test_the_layer_interface_finds_links(): void {
		$this->store->insert_if_absent(
			Link_Record::offer(
				7,
				101,
				0,
				'SKU-1',
				[
					'remote_ids'        => [
						'product_id' => 555,
						'sku'        => 777,
						'model_id'   => 31,
					],
					'sync_stock'        => 1,
				]
			)
		);

		$by_product = $this->store->by_product( 7, 101 );

		$this->assertInstanceOf( Product_Link::class, $by_product );
		$this->assertSame( 'SKU-1', $by_product->seller_sku() );
		$this->assertTrue( $by_product->flag( 'sync_stock' ) );
		$this->assertSame( 555, $by_product->remote_id( 'product_id' ) );
		$this->assertSame( 777, $by_product->remote_id( 'sku' ) );
		$this->assertSame( 31, $by_product->remote_id( 'model_id' ) );
		$this->assertSame( 101, $this->store->by_seller_sku( 7, 'SKU-1' )->wc_product_id() );
		$this->assertSame( 101, $this->store->by_remote_id( 7, 'sku', 777 )->wc_product_id() );
		$this->assertSame( 101, $this->store->by_remote_id( 7, 'model_id', 31 )->wc_product_id() );
		$this->assertNull( $this->store->by_remote_id( 7, 'sku', 0 ), 'a zero identifier means «not known yet»' );
		$this->assertNull( $this->store->by_remote_id( 7, 'nmID', 5 ), 'a name no link has finds nothing' );
		$this->assertNull( $this->store->by_remote_id( 8, 'sku', 777 ), 'another account' );
	}

	/**
	 * The identifiers a marketplace uses are kept whole, under its own names: a Wildberries-shaped link (`nmID`, `imtID`, `chrtID`)
	 * comes back as it went in and is found by any of them. The store may not impose three fixed integer slots.
	 *
	 * @return void
	 */
	public function test_a_marketplaces_own_identifiers_round_trip_and_are_found_by_name(): void {
		$ids = [
			'nmID'   => 5000001,
			'imtID'  => 7000001,
			'chrtID' => 9000001,
		];

		$id   = $this->store->save( new Product_Link( 1, 101, 0, 'SKU-1', $ids, 'created' ) );
		$link = $this->store->by_product( 1, 101 );

		$this->assertSame( $this->sorted( $ids ), $link->remote_ids() );
		$this->assertSame( $id, $link->link_id() );
		$this->assertSame( 101, $this->store->by_remote_id( 1, 'nmID', '5000001' )->wc_product_id(), 'a text value finds an integer identifier' );
		$this->assertSame( 101, $this->store->by_remote_id( 1, 'chrtID', 9000001 )->wc_product_id() );
		$this->assertNull( $this->store->by_remote_id( 1, 'chrtID', 5000001 ), 'the name counts' );
		$this->assertNull( $this->store->by_remote_id( 2, 'nmID', 5000001 ), 'the account counts' );
	}

	/**
	 * A string identifier is not turned into zero, and a number that is a string stays a string.
	 *
	 * @return void
	 */
	public function test_string_identifiers_keep_their_value_and_type(): void {
		$ids = [
			'product_id' => 'card-A42',
			'sku'        => '0042',
			'model_id'   => '7000001',
			'extra'      => 12,
		];

		$this->store->save( new Product_Link( 1, 101, 0, 'SKU-1', $ids ) );

		$this->assertSame( $this->sorted( $ids ), $this->store->by_product( 1, 101 )->remote_ids() );
		$this->assertSame( 101, $this->store->by_remote_id( 1, 'product_id', 'card-A42' )->wc_product_id() );
		$this->assertSame( 101, $this->store->by_remote_id( 1, 'sku', '0042' )->wc_product_id() );
	}

	/**
	 * «Not known yet» is no row: empty and zero values are skipped, and a later save adds or changes only what it carries.
	 *
	 * @return void
	 */
	public function test_unknown_identifiers_are_not_stored_and_a_save_changes_only_what_it_carries(): void {
		$this->store->save( new Product_Link( 1, 101, 0, 'SKU-1', [ 'nmID' => 5000001, 'imtID' => 0, 'chrtID' => '' ], 'pending_remote_ids' ) );
		$this->assertSame( [ 'nmID' => 5000001 ], $this->store->by_product( 1, 101 )->remote_ids() );

		$this->store->save( $this->store->by_product( 1, 101 )->with_remote_ids( [ 'chrtID' => 9000001 ] ) );
		$this->assertSame( [ 'chrtID' => 9000001, 'nmID' => 5000001 ], $this->store->by_product( 1, 101 )->remote_ids() );

		$this->store->save( $this->store->by_product( 1, 101 )->with_remote_ids( [ 'nmID' => 5000002 ] ) );
		$this->assertSame( 5000002, $this->store->by_product( 1, 101 )->remote_id( 'nmID' ) );
		$this->assertNull( $this->store->by_remote_id( 1, 'nmID', 5000001 ), 'the old value no longer finds it' );

		$this->assertNull( $this->store->by_remote_id( 1, 'nmID', 0 ) );
		$this->assertNull( $this->store->by_remote_id( 1, 'nmID', '' ) );
		$this->assertNull( $this->store->by_remote_id( 1, '', 5000002 ) );
	}

	/**
	 * A name or value too long for its column is refused before any row changes, and the link is not half-written.
	 *
	 * @return void
	 */
	public function test_an_identifier_that_does_not_fit_is_refused_and_nothing_is_kept(): void {
		try {
			$this->store->save( new Product_Link( 1, 101, 0, 'SKU-1', [ 'nmID' => 5, 'long' => str_repeat( 'x', 192 ) ] ) );
			$this->fail( 'An identifier that does not fit must be refused.' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertNull( $this->store->find( 1, 101 ), 'the link row was rolled back with its identifiers' );
			$this->assertSame( [], $this->db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( 'link_ids' ) ) );
		}
	}

	/**
	 * The real store answers the layer's contract the way the in-memory fake the second-marketplace tests use does.
	 *
	 * @return void
	 */
	public function test_it_can_stand_in_for_the_fake_link_store(): void {
		$link = new Product_Link( 1, 101, 0, 'SKU-1', [ 'nmID' => 5000001, 'chrtID' => 'c-9' ], 'created', [ 'sync_stock' => true ] );

		foreach ( [ new Fake_Link_Store(), $this->store ] as $store ) {
			$store->save( $link );

			$this->assertSame( 'SKU-1', $store->by_seller_sku( 1, 'SKU-1' )->seller_sku(), get_class( $store ) );
			$this->assertSame( 5000001, $store->by_product( 1, 101 )->remote_id( 'nmID' ), get_class( $store ) );
			$this->assertSame( 101, $store->by_remote_id( 1, 'chrtID', 'c-9' )->wc_product_id(), get_class( $store ) );
			$this->assertSame( 'c-9', $store->by_remote_id( 1, 'nmID', 5000001 )->remote_id( 'chrtID' ), get_class( $store ) );
			$this->assertNull( $store->by_remote_id( 1, 'nmID', 1 ), get_class( $store ) );
		}
	}

	/**
	 * Saving through the shared interface inserts a new link and updates an existing one without touching the overrides.
	 *
	 * @return void
	 */
	public function test_save_updates_the_shared_columns_and_keeps_the_overrides(): void {
		$this->store->insert_if_absent(
			Link_Record::offer(
				7,
				101,
				0,
				'SKU-1',
				[
					'mapping_id'          => 3,
					'attribute_overrides' => [
						'85' => [
							[
								'dictionary_value_id' => 0,
								'value'               => 'X',
							],
						],
					],
				]
			)
		);

		$existing = $this->store->by_product( 7, 101 );
		$id       = $this->store->save( $existing->with_state( 'created' )->with_remote_ids( [ 'sku' => 900 ] ) );

		$this->assertSame( (int) $existing->link_id(), $id );
		$found = $this->store->find( 7, 101 );
		$this->assertSame( 'created', $found->card_state() );
		$this->assertSame( 900, $found->remote_id( 'sku' ) );
		$this->assertSame( 3, $found->mapping_id(), 'the mapping is not part of the shared value and survives' );
		$this->assertSame( 'X', $found->attribute_overrides()['85'][0]['value'] );

		$new_id = $this->store->save( new Product_Link( 7, 102, 0, 'SKU-2' ) );
		$this->assertGreaterThan( $id, $new_id );
		$this->assertSame( 'SKU-2', $this->store->find( 7, 102 )->offer_id() );
	}

	/**
	 * A failed insert is an exception that names what failed, not a silent false.
	 *
	 * @return void
	 */
	public function test_a_refused_insert_throws_with_the_database_text(): void {
		$this->db->query( 'DROP TABLE ' . Sqlite_Tables::table( 'links' ) );

		try {
			$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'SKU-1', [] ) );
			$this->fail( 'The refused insert must throw.' );
		} catch ( Persistence_Exception $exception ) {
			$this->assertStringContainsString( 'the link of product 101', $exception->getMessage() );
		}
	}

	/**
	 * Fills the remote SKU only while it is unknown, and the state always.
	 *
	 * @return void
	 */
	public function test_fill_remote_state_never_overwrites_a_known_sku(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'A', [ 'remote_ids' => [ 'product_id' => 5 ] ] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 102, 0, 'B', [ 'remote_ids' => [ 'sku' => 42 ] ] ) );
		$first  = (int) $this->store->find( 7, 101 )->link_id();
		$second = (int) $this->store->find( 7, 102 )->link_id();

		$this->claim_account( 7, 'held' );

		$this->store->fill_remote_state(
			$first,
			[
				'sku'      => 900,
				'model_id' => 31,
			],
			'created',
			true,
			$this->accounts(),
			'held'
		);
		$this->store->fill_remote_state(
			$second,
			[
				'sku'      => 999,
				'model_id' => 32,
			],
			'created',
			true,
			$this->accounts(),
			'held'
		);

		$this->assertSame( 900, $this->store->find( 7, 101 )->remote_id( 'sku' ), 'an unknown SKU is filled' );
		$this->assertSame( 31, $this->store->find( 7, 101 )->remote_id( 'model_id' ) );
		$this->assertSame( 42, $this->store->find( 7, 102 )->remote_id( 'sku' ), 'a known SKU is kept' );
		$this->assertSame( 32, $this->store->find( 7, 102 )->remote_id( 'model_id' ), 'an unknown one beside it is filled' );
		$this->assertSame( 'created', $this->store->find( 7, 102 )->card_state() );
	}

	/**
	 * The write carries the account row's claim: another token, or an expired claim, changes nothing.
	 *
	 * @return void
	 */
	public function test_fill_remote_state_is_fenced_by_the_account_claim(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'A', [ 'remote_ids' => [ 'product_id' => 5 ] ] ) );
		$link = (int) $this->store->find( 7, 101 )->link_id();
		$ids  = [
			'sku'      => 900,
			'model_id' => 31,
		];

		$this->claim_account( 7, 'theirs' );
		$this->assertFalse( $this->store->fill_remote_state( $link, $ids, 'created', true, $this->accounts(), 'mine' ) );

		$this->claim_account( 7, 'mine', -1 );
		$this->assertFalse( $this->store->fill_remote_state( $link, $ids, 'created', true, $this->accounts(), 'mine' ), 'an expired claim' );
		$this->assertNull( $this->store->find( 7, 101 )->remote_id( 'model_id' ), 'a lost claim writes no identifier' );

		$this->claim_account( 7, 'mine' );
		$this->assertTrue( $this->store->fill_remote_state( $link, $ids, 'created', true, $this->accounts(), 'mine' ) );
		$this->assertSame( 31, $this->store->find( 7, 101 )->remote_id( 'model_id' ) );
	}

	/**
	 * Pages follow link-id order with a cursor.
	 *
	 * @return void
	 */
	public function test_pages_walk_the_account_in_link_id_order(): void {
		foreach ( [ 301, 302, 303 ] as $product_id ) {
			$this->store->insert_if_absent( Link_Record::offer( 7, $product_id, 0, 'P' . $product_id, [] ) );
		}
		$this->store->insert_if_absent( Link_Record::offer( 8, 304, 0, 'OTHER', [] ) );

		$first = $this->store->page( 7, 0, 2 );
		$rest  = $this->store->page( 7, (int) $first[1]->link_id(), 2 );

		$this->assertSame( [ 301, 302 ], $this->product_ids( $first ) );
		$this->assertSame( [ 303 ], $this->product_ids( $rest ) );
	}

	/**
	 * Queueing sets the article only while it is not frozen, and leaves a row being submitted or an archived card alone.
	 *
	 * @return void
	 */
	public function test_queue_respects_the_frozen_article_and_the_states_it_must_not_touch(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'OLD', [] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 102, 0, 'KEEP', [ 'remote_ids' => [ 'product_id' => 9 ] ] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 103, 0, 'SENT', [ 'card_state' => 'submitted' ] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 104, 0, 'GONE', [ 'card_state' => 'archived' ] ) );

		$this->assertTrue( $this->store->queue( $this->link_id( 101 ), 'NEW' ) );
		$this->assertSame( 'NEW', $this->store->find( 7, 101 )->offer_id() );
		$this->assertSame( 'queued', $this->store->find( 7, 101 )->card_state() );

		$this->assertTrue( $this->store->queue( $this->link_id( 102 ), 'NEW-2' ) );
		$this->assertSame( 'KEEP', $this->store->find( 7, 102 )->offer_id(), 'the article of a card the marketplace knows is frozen' );
		$this->assertSame( 'queued', $this->store->find( 7, 102 )->card_state() );

		$this->assertFalse( $this->store->queue( $this->link_id( 103 ), 'X' ), 'a row being submitted' );
		$this->assertFalse( $this->store->queue( $this->link_id( 104 ), 'X' ), 'an archived card' );
		$this->assertSame( 'SENT', $this->store->find( 7, 103 )->offer_id() );
	}

	/**
	 * A task takes queued rows in one statement: a second runner holding the same ids wins nothing, and a lost claim wins nothing.
	 *
	 * @return void
	 */
	public function test_claim_for_task_takes_each_queued_row_once_and_honours_the_fence(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'A', [ 'card_state' => 'queued' ] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 102, 0, 'B', [ 'card_state' => 'queued' ] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 103, 0, 'C', [] ) );
		$ids = [ $this->link_id( 101 ), $this->link_id( 102 ), $this->link_id( 103 ) ];

		$this->claim_account( 7, 'theirs' );
		$this->assertSame( [], $this->store->claim_for_task( 1, $ids, new Claim_Fence( $this->accounts(), 'mine' ) ), 'a lost claim wins nothing' );

		$this->claim_account( 7, 'mine' );
		$won = $this->store->claim_for_task( 1, $ids, new Claim_Fence( $this->accounts(), 'mine' ) );

		$this->assertSame( [ 101, 102 ], $this->product_ids( $won ), 'only queued rows' );
		$this->assertSame( [], $this->store->claim_for_task( 2, $ids ), 'the second runner finds nothing queued' );
		$this->assertSame( [ 1 ], $this->store->owning_task_ids( [ 'submitted' ], 10 ) );
		$this->assertTrue( $this->store->still_owns( $ids[0], 1, 'submitted' ) );
		$this->assertFalse( $this->store->still_owns( $ids[0], 2, 'submitted' ) );
		$this->assertSame( [], $this->store->claim_for_task( 3, [] ) );
	}

	/**
	 * A poll moves a row only while its task still owns it, the claim is held and the row is in the state the run read.
	 *
	 * @return void
	 */
	public function test_advance_is_fenced_by_task_state_and_claim(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'A', [ 'card_state' => 'queued' ] ) );
		$link = $this->link_id( 101 );
		$this->claim_account( 7, 'mine' );
		$fence = new Claim_Fence( $this->accounts(), 'mine' );
		$this->store->claim_for_task( 5, [ $link ], $fence );

		$this->assertFalse( $this->store->advance( $link, 6, 'created' ), 'another task does not own the row' );
		$this->assertFalse( $this->store->advance( $link, 5, 'created', [], null, 'queued' ), 'the row is not in the state the run read' );
		$this->assertFalse( $this->store->advance( $link, 5, 'created', [], new Claim_Fence( $this->accounts(), 'theirs' ) ), 'the claim is lost' );

		$this->assertTrue( $this->store->advance( $link, 5, 'created', [ 'remote_ids' => [ 'product_id' => 88 ], 'last_error_text' => null ], $fence, 'submitted' ) );
		$found = $this->store->find( 7, 101 );
		$this->assertSame( 'created', $found->card_state() );
		$this->assertSame( 88, $found->remote_id( 'product_id' ) );
		$this->assertSame( [ 'code' => '', 'text' => '' ], $this->store->last_error( $link ) );
		$this->assertSame( [ 'created' => 1 ], $this->store->state_counts( 7, [ $link ] ) );
	}

	/**
	 * A reset takes the task fence away, so a poll still running for the old task changes nothing; the identifiers stay.
	 *
	 * @return void
	 */
	public function test_reset_state_keeps_the_identifiers_and_drops_the_task_fence(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'A', [ 'card_state' => 'queued', 'remote_ids' => [ 'product_id' => 9 ] ] ) );
		$link = $this->link_id( 101 );
		$this->store->claim_for_task( 5, [ $link ] );

		$this->assertSame( 1, $this->store->reset_state( 7, [ $link, $link ] ) );
		$this->assertSame( 0, $this->store->reset_state( 7, [] ) );

		$found = $this->store->find( 7, 101 );
		$this->assertSame( 'unlinked', $found->card_state() );
		$this->assertSame( 9, $found->remote_id( 'product_id' ) );
		$this->assertFalse( $this->store->advance( $link, 5, 'created' ), 'the old task no longer owns the row' );
	}

	/**
	 * «Detach» forgets the card locally, keeps the merchant's choices, and leaves a row that is being submitted alone.
	 *
	 * @return void
	 */
	public function test_detach_forgets_the_card_but_not_the_settings(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'A', [ 'card_state' => 'created', 'remote_ids' => [ 'product_id' => 9, 'sku' => 8 ], 'sync_stock' => 1, 'mapping_id' => 3 ] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 102, 0, 'B', [ 'card_state' => 'submitted' ] ) );

		$this->assertTrue( $this->store->detach( 7, $this->link_id( 101 ) ) );
		$this->assertFalse( $this->store->detach( 7, $this->link_id( 102 ) ), 'a row being submitted' );
		$this->assertFalse( $this->store->detach( 8, $this->link_id( 101 ) ), 'another account\'s row' );

		$found = $this->store->find( 7, 101 );
		$this->assertSame( 'unlinked', $found->card_state() );
		$this->assertNull( $found->offer_id() );
		$this->assertSame( [], $found->remote_ids() );
		$this->assertSame( [], $this->db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( 'link_ids' ) . ' WHERE link_id = ' . $this->link_id( 101 ) ), 'the identifier rows are gone' );
		$this->assertTrue( $found->sync_stock() );
		$this->assertSame( 3, $found->mapping_id() );
	}

	/**
	 * Adopting an existing card touches only an idle row, and with a fence only while the claim is held.
	 *
	 * @return void
	 */
	public function test_adopt_card_touches_only_an_idle_row(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, '', [] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 102, 0, '', [ 'card_state' => 'queued' ] ) );
		$card = [
			'offer_id'   => 'A',
			'remote_ids' => [
				'product_id' => 5,
				'sku'        => 6,
			],
			'state'      => 'created',
			'is_created' => true,
		];
		$this->claim_account( 7, 'mine' );

		$this->assertFalse( $this->store->adopt_card( $this->link_id( 101 ), $card, new Claim_Fence( $this->accounts(), 'theirs' ) ) );
		$this->assertTrue( $this->store->adopt_card( $this->link_id( 101 ), $card, new Claim_Fence( $this->accounts(), 'mine' ) ) );
		$this->assertFalse( $this->store->adopt_card( $this->link_id( 101 ), $card ), 'now it has an identity' );
		$this->assertFalse( $this->store->adopt_card( $this->link_id( 102 ), $card ), 'an export queued it' );

		$this->assertSame( [ 'product_id' => 5, 'sku' => 6 ], $this->store->find( 7, 101 )->remote_ids() );
		$this->assertSame( [ 101 => [ 'offer_id' => 'A', 'has_remote_ids' => true, 'card_state' => 'created' ] ], $this->identity_of( 101 ) );
	}

	/**
	 * Inserting the row of an existing card: inserted, exists, or fenced out when the claim was taken over.
	 *
	 * @return void
	 */
	public function test_insert_card_reports_inserted_exists_and_fenced_out(): void {
		$this->claim_account( 7, 'mine' );
		$mine   = new Claim_Fence( $this->accounts(), 'mine' );
		$theirs = new Claim_Fence( $this->accounts(), 'theirs' );
		$record = Link_Record::offer( 7, 101, 0, 'A', [ 'remote_ids' => [ 'product_id' => 5 ] ] );

		$this->assertSame( Wpdb_Link_Store::FENCED_OUT, $this->store->insert_card( $record, '', $theirs ) );
		$this->assertNull( $this->store->find( 7, 101 ) );

		$this->assertSame( Wpdb_Link_Store::INSERTED, $this->store->insert_card( $record, 'Карточка отклонена', $mine ) );
		$this->assertSame( 'Карточка отклонена', $this->store->last_error( $this->link_id( 101 ) )['text'] );

		$this->assertSame( Wpdb_Link_Store::EXISTS, $this->store->insert_card( $record, '', $mine ) );
		$this->assertSame( Wpdb_Link_Store::INSERTED, $this->store->insert_card( Link_Record::offer( 7, 102, 0, 'B', [] ) ), 'a request-time link has no fence' );
	}

	/**
	 * What the marketplace answered for a stock send is recorded under the claim: the accepted quantity, the verbatim error, a lost
	 * claim reported as false.
	 *
	 * @return void
	 */
	public function test_record_stock_outcome_and_mark_archived_are_fenced(): void {
		$this->store->insert_if_absent( Link_Record::offer( 7, 101, 0, 'A', [ 'card_state' => 'created' ] ) );
		$link = $this->link_id( 101 );
		$this->claim_account( 7, 'mine' );
		$mine   = new Claim_Fence( $this->accounts(), 'mine' );
		$theirs = new Claim_Fence( $this->accounts(), 'theirs' );

		$this->assertFalse( $this->store->record_stock_outcome( $link, 5, null, null, $theirs ) );
		$this->assertTrue( $this->store->record_stock_outcome( $link, 5, null, null, $mine ) );
		$this->assertTrue( $this->store->record_stock_outcome( $link, null, str_repeat( 'E', 80 ), 'Слишком много запросов', $mine ) );

		$last = $this->store->last_error( $link );
		$this->assertSame( str_repeat( 'E', 64 ), $last['code'], 'the code is cut to its column' );
		$this->assertSame( 'Слишком много запросов', $last['text'] );

		$this->assertTrue( $this->store->record_stock_outcome( $link, null, null, null, $mine ), 'no error clears code and text' );
		$this->assertSame( [ 'code' => '', 'text' => '' ], $this->store->last_error( $link ) );
		$this->assertTrue( $this->store->record_stock_outcome( $link, 5, null, null, $mine ), 'an unchanged row is still visible under the claim' );

		$this->assertFalse( $this->store->mark_archived( $link, 'Архив', $theirs ) );
		$this->assertTrue( $this->store->mark_archived( $link, 'Архив', $mine ) );
		$this->assertSame( 'archived', $this->store->find( 7, 101 )->card_state() );
	}

	/**
	 * The product tab's writers: switches, warehouse, the explicit mapping and the price override; each touches only its columns.
	 *
	 * @return void
	 */
	public function test_per_product_settings_are_written_column_by_column(): void {
		foreach ( [ 101, 102 ] as $product_id ) {
			$this->store->insert_if_absent( Link_Record::offer( 7, $product_id, 0, 'P' . $product_id, [] ) );
		}
		$ids = [ $this->link_id( 101 ), $this->link_id( 102 ) ];

		$this->assertSame( 2, $this->store->set_sync_flag( 7, $ids, 'sync_stock', true ) );
		$this->assertSame( 0, $this->store->set_sync_flag( 7, $ids, 'sync_stock', true ), 'already on' );
		$this->assertSame( 0, $this->store->set_sync_flag( 7, $ids, 'is_created', true ), 'only the two switches' );
		$this->assertSame( 2, $this->store->set_warehouse( 7, $ids, 31 ) );
		$this->assertSame( 2, $this->store->set_warehouse( 7, $ids, null ) );

		$this->store->update_settings( $ids[0], [ 'sync_price' => true, 'is_oversize' => true, 'warehouse_id' => 9, 'mapping_id' => 0 ] );
		$found = $this->store->find( 7, 101 );
		$this->assertTrue( $found->sync_price() );
		$this->assertTrue( $found->is_oversize() );
		$this->assertSame( 9, $found->warehouse_id() );
		$this->assertNull( $found->mapping_id(), '0 means none' );
		$this->assertTrue( $found->sync_stock(), 'a key that is absent stays' );

		$this->store->set_price_override( $ids[0], [ 'price' => [ 'kind' => 'percent', 'value' => '20' ] ] );
		$this->assertSame( 'percent', $this->store->find( 7, 101 )->price_rule_override()['price']['kind'] );
		$this->store->set_price_override( $ids[0], null );
		$this->assertNull( $this->store->find( 7, 101 )->price_rule_override() );

		$this->expectException( \InvalidArgumentException::class );
		$this->store->set_price_override( $ids[0], [ 'bogus' => [ 'kind' => 'fixed', 'value' => '1' ] ] );
	}

	/**
	 * Offers of a product are its own row and, for a variable product, its variations'; parents are not offers.
	 *
	 * @return void
	 */
	public function test_offers_of_a_product_include_its_variations(): void {
		$this->store->insert_if_absent( Link_Record::parent_row( 7, 200, [] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 201, 200, 'V1', [] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 202, 200, 'V2', [] ) );
		$this->store->insert_if_absent( Link_Record::offer( 7, 300, 0, 'S', [] ) );
		$this->store->insert_if_absent( Link_Record::offer( 8, 201, 200, 'V1', [] ) );

		$this->assertSame( [ 201, 202 ], $this->product_ids( $this->store->offers_of( 7, [ 200 ] ) ) );
		$this->assertSame( [ 201, 202, 300 ], $this->product_ids( $this->store->offers_of( 7, [ 200, 300 ] ) ) );
		$this->assertSame( [], $this->store->offers_of( 7, [] ) );
		$this->assertCount( 2, $this->store->offers_of_product( 201 ), 'in every account' );
		$this->assertSame( [ 300 => [ 'offer_id' => 'S', 'has_remote_ids' => false, 'card_state' => 'unlinked' ] ], array_intersect_key( $this->store->identity_map( 7 ), [ 300 => 1 ] ) );
	}

	/**
	 * The projection onto the layer value carries the three identifier slots.
	 *
	 * @return void
	 */
	public function test_to_product_link_carries_the_remote_id_slots(): void {
		$link = $this->store->to_product_link( Link_Record::offer( 7, 101, 100, 'A', [ 'remote_ids' => [ 'product_id' => 1, 'sku' => 2, 'model_id' => 3 ], 'warehouse_id' => 4 ] ) );

		$this->assertSame( [ 1, 2, 3 ], [ $link->remote_id( 'product_id' ), $link->remote_id( 'sku' ), $link->remote_id( 'model_id' ) ] );
		$this->assertSame( 4, $link->warehouse_id() );
		$this->assertSame( 100, $link->wc_parent_id() );
	}

	/**
	 * Sorts an identifier bag by name, the order the store returns it in.
	 *
	 * @param array<string,int|string> $ids Identifiers.
	 * @return array<string,int|string>
	 */
	private function sorted( array $ids ): array {
		ksort( $ids );

		return $ids;
	}

	/**
	 * Link ids of the rows the fence tests need.
	 *
	 * @param int $product_id Product id (account 7).
	 * @return int
	 */
	private function link_id( int $product_id ): int {
		return (int) $this->store->find( 7, $product_id )->link_id();
	}

	/**
	 * Identity map entry of one product.
	 *
	 * @param int $product_id Product id (account 7).
	 * @return array<int,array<string,mixed>>
	 */
	private function identity_of( int $product_id ): array {
		$entry = $this->store->identity_map( 7 )[ $product_id ];

		return [
			$product_id => [
				'offer_id'          => $entry['offer_id'],
				'has_remote_ids'    => $entry['has_remote_ids'],
				'card_state'        => $entry['card_state'],
			],
		];
	}

	/**
	 * Product ids of records.
	 *
	 * @param Link_Record[] $records Records.
	 * @return int[]
	 */
	private function product_ids( array $records ): array {
		return array_map(
			static function ( Link_Record $record ): int {
				return $record->wc_product_id();
			},
			$records
		);
	}

	/**
	 * Full name of the accounts table.
	 *
	 * @return string
	 */
	private function accounts(): string {
		return Sqlite_Tables::table( 'accounts' );
	}

	/**
	 * Gives an account row a claim, relative to the store's fixed clock.
	 *
	 * @param int    $account_id Account id.
	 * @param string $token      Claim token.
	 * @param int    $ttl        Seconds from the store's clock.
	 * @return void
	 */
	private function claim_account( int $account_id, string $token, int $ttl = 120 ): void {
		$this->db->query( 'DELETE FROM ' . $this->accounts() . ' WHERE account_id = ' . $account_id );
		$this->db->insert(
			$this->accounts(),
			[
				'account_id'         => $account_id,
				'external_id'        => 'c' . $account_id,
				'secret_enc'         => 's',
				'notification_token' => 't',
				'claim_token'        => $token,
				'claim_until'        => gmdate( 'Y-m-d H:i:s', self::NOW + $ttl ),
			]
		);
	}
}

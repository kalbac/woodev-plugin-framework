<?php
/**
 * The stored cards list (#1863): its SQL page, filter, search and counts, the upsert, the generations and the sweep.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Runs the store's real SQL on SQLite over the schema's own columns. SQLite's `LIKE` ignores the case of Latin letters only and has
 * no escape character, so the searches here are in the case of the data and hold no wildcard; MySQL's collation and `esc_like()`
 * are checked by the integration test (`CardStoreTest`).
 *
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Card_Store
 */
final class WpdbCardStoreTest extends TestCase {
	private const ACCOUNT = 7;
	private const OTHER   = 8;
	private const NOW     = 1794139200;
	private const FAMILY  = 'woodev_fake-seller_';

	/**
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Options, name => value.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * @var int
	 */
	private $now = self::NOW;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = [];
		$this->now     = self::NOW;
		$this->db      = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function store(): Wpdb_Card_Store {
		return new Wpdb_Card_Store(
			$this->db,
			Sqlite_Tables::table( 'cards' ),
			Sqlite_Tables::table( 'links' ),
			self::FAMILY,
			function (): int {
				return $this->now;
			}
		);
	}

	/**
	 * A list row as an adapter gives it.
	 *
	 * @param string              $offer_id Article.
	 * @param string              $name     Name.
	 * @param array<string,mixed> $more     Keys to override.
	 * @return array<string,mixed>
	 */
	private function row( string $offer_id, string $name = '', array $more = [] ): array {
		return array_merge(
			[
				'offer_id'    => $offer_id,
				'name'        => '' === $name ? 'Карточка ' . $offer_id : $name,
				'remote_ids'  => [ 'card_ref' => 11 ],
				'state'       => Link_Record::STATE_CREATED,
				'is_created'  => true,
				'details_ok'  => true,
				'status_text' => '',
			],
			$more
		);
	}

	/**
	 * Stores a finished full refresh of these rows for an account.
	 *
	 * @param array<int,array<string,mixed>> $rows       Rows.
	 * @param int                            $account_id Account.
	 * @return void
	 */
	private function refresh( array $rows, int $account_id = self::ACCOUNT ): void {
		$store = $this->store();
		$run   = $store->begin_refresh( $account_id, $this->now );

		$store->upsert( $account_id, $rows, $run, 1, $this->now );
		$store->finish_refresh( $account_id, $run, $this->now );
	}

	/**
	 * A link row that puts a card on the site.
	 *
	 * @param string $offer_id   Article.
	 * @param int    $product_id Product.
	 * @param int    $account_id Account.
	 * @return void
	 */
	private function link( string $offer_id, int $product_id, int $account_id = self::ACCOUNT ): void {
		$this->db->insert(
			Sqlite_Tables::table( 'links' ),
			[
				'account_id'    => $account_id,
				'wc_product_id' => $product_id,
				'kind'          => Link_Record::KIND_OFFER,
				'offer_id'      => $offer_id,
			]
		);
	}

	/**
	 * @param array{cards:array<int,array<string,mixed>>,total:int} $page Page.
	 * @return string[]
	 */
	private static function articles( array $page ): array {
		return array_column( $page['cards'], 'offer_id' );
	}

	/**
	 * The four cards of the pure tests the SQL replaced.
	 *
	 * @return void
	 */
	private function four(): void {
		$this->refresh(
			[
				$this->row( 'A-1', 'Кружка синяя' ),
				$this->row( 'B-2', 'Футболка' ),
				$this->row( 'C 3/x', 'Кружка красная' ),
				$this->row( 'D-4', '-' ),
			]
		);
	}

	public function test_an_account_with_no_refresh_and_no_row_has_no_list(): void {
		$this->assertFalse( $this->store()->has_list( self::ACCOUNT ) );
		$this->assertSame( 0, $this->store()->refreshed_at( self::ACCOUNT ) );

		$this->refresh( [] );

		$this->assertTrue( $this->store()->has_list( self::ACCOUNT ), 'a refresh that found no card is a list, an empty one' );
		$this->assertSame( self::NOW, $this->store()->refreshed_at( self::ACCOUNT ) );
	}

	public function test_the_table_opens_on_the_cards_no_product_carries_and_the_others_carry_their_product(): void {
		$this->four();
		$this->link( 'B-2', 41 );

		$off = $this->store()->page( self::ACCOUNT, false, '', 1, 20 );
		$on  = $this->store()->page( self::ACCOUNT, true, '', 1, 20 );

		$this->assertSame( [ 'A-1', 'C 3/x', 'D-4' ], self::articles( $off ) );
		$this->assertSame( 3, $off['total'] );
		$this->assertSame( [ 0, 0, 0 ], array_column( $off['cards'], 'wc_product_id' ) );
		$this->assertSame( [ 'B-2' ], self::articles( $on ) );
		$this->assertSame( [ 41 ], array_column( $on['cards'], 'wc_product_id' ) );
	}

	public function test_no_filter_shows_every_card_in_the_marketplaces_order(): void {
		$this->four();
		$this->link( 'B-2', 41 );

		$page = $this->store()->page( self::ACCOUNT, null, '', 1, 20 );

		$this->assertSame( [ 'A-1', 'B-2', 'C 3/x', 'D-4' ], self::articles( $page ) );
		$this->assertSame( 4, $page['total'] );
	}

	public function test_a_link_of_another_account_or_a_group_row_does_not_put_a_card_on_the_site(): void {
		$this->four();
		$this->link( 'B-2', 41, self::OTHER );
		$this->db->insert(
			Sqlite_Tables::table( 'links' ),
			[
				'account_id'    => self::ACCOUNT,
				'wc_product_id' => 50,
				'kind'          => 'group',
				'offer_id'      => 'C 3/x',
			]
		);

		$this->assertSame( 4, $this->store()->page( self::ACCOUNT, false, '', 1, 20 )['total'] );
	}

	public function test_a_search_looks_in_the_article_and_the_name(): void {
		$this->four();

		$this->assertSame( [ 'A-1', 'C 3/x' ], self::articles( $this->store()->page( self::ACCOUNT, null, 'Кружка', 1, 20 ) ) );
		$this->assertSame( [ 'C 3/x' ], self::articles( $this->store()->page( self::ACCOUNT, null, ' c 3/X ', 1, 20 ) ), 'trimmed; Latin case ignored' );
		$this->assertSame( [], self::articles( $this->store()->page( self::ACCOUNT, null, 'нет такой', 1, 20 ) ) );
	}


	public function test_a_page_is_a_slice_and_the_total_is_the_whole_filtered_list(): void {
		$this->four();

		$second = $this->store()->page( self::ACCOUNT, null, '', 2, 3 );

		$this->assertSame( [ 'D-4' ], self::articles( $second ) );
		$this->assertSame( 4, $second['total'] );
		$this->assertSame( [], $this->store()->page( self::ACCOUNT, null, '', 3, 3 )['cards'], 'a page past the end is empty' );
	}

	public function test_the_archived_cards_are_counted_apart_for_their_own_account(): void {
		$this->refresh(
			[
				$this->row( 'A-1' ),
				$this->row( 'A-2', '', [ 'archived' => true ] ),
				$this->row( 'A-3', '', [ 'archived' => true ] ),
			]
		);
		$this->refresh( [ $this->row( 'B-1', '', [ 'archived' => true ] ) ], self::OTHER );

		$this->assertSame( 3, $this->store()->count( self::ACCOUNT ) );
		$this->assertSame( 2, $this->store()->archived_count( self::ACCOUNT ), 'The archive is a part of the list, which counts it.' );
		$this->assertSame( 0, $this->store()->archived_count( 99 ) );
	}

	public function test_the_on_site_counts_follow_the_search(): void {
		$this->four();
		$this->link( 'A-1', 40 );

		$this->assertSame( [ 'on_site' => 1, 'not_on_site' => 3 ], $this->store()->on_site_counts( self::ACCOUNT, '' ) );
		$this->assertSame( [ 'on_site' => 1, 'not_on_site' => 1 ], $this->store()->on_site_counts( self::ACCOUNT, 'Кружка' ) );
	}

	public function test_the_entries_of_a_filter_split_the_cards_to_import_from_those_on_the_site(): void {
		$this->refresh(
			[
				$this->row( 'G-1', '', [ 'group' => [ 'key' => ' grp ', 'size' => 2 ] ] ),
				$this->row( 'G-2' ),
				$this->row( 'G-3' ),
			]
		);
		$this->link( 'G-2', 41 );

		$this->assertSame(
			[
				'cards'   => [ [ 'G-1', 'grp' ], [ 'G-3', '' ] ],
				'on_site' => [ 'G-2' ],
			],
			$this->store()->entries( self::ACCOUNT, null, '' )
		);
		$this->assertSame( [ [ 'G-1', 'grp' ], [ 'G-3', '' ] ], $this->store()->entries( self::ACCOUNT, false, '' )['cards'] );
	}

	public function test_a_card_is_found_by_the_key_of_its_article_and_only_in_its_account(): void {
		$this->four();
		$this->refresh( [ $this->row( 'B-2', 'Чужая' ) ], self::OTHER );

		$this->assertSame( 'Футболка', $this->store()->find( self::ACCOUNT, Wpdb_Card_Store::key( 'B-2' ) )['name'] );
		$this->assertNull( $this->store()->find( self::ACCOUNT, Wpdb_Card_Store::key( 'nope' ) ) );
		$this->assertNull( $this->store()->find( self::ACCOUNT, 'not a key' ) );
		$found = array_keys( $this->store()->find_many( self::ACCOUNT, [ Wpdb_Card_Store::key( 'A-1' ), Wpdb_Card_Store::key( 'D-4' ), Wpdb_Card_Store::key( 'nope' ) ] ) );
		$want  = [ Wpdb_Card_Store::key( 'A-1' ), Wpdb_Card_Store::key( 'D-4' ) ];
		sort( $found );
		sort( $want );
		$this->assertSame( $want, $found, 'one read for many keys; an unknown key is absent' );
	}

	public function test_a_card_comes_back_with_every_field_the_screen_and_the_import_read(): void {
		$this->refresh(
			[
				$this->row(
					'F-1',
					'Платье',
					[
						'image_url'   => 'https://cdn.test/f1.jpg',
						'price'       => '1499.5',
						'stock'       => 3,
						'failed'      => true,
						'archived'    => true,
						'status_text' => 'Нет бренда',
						'remote_ids'  => [
							'card_ref' => 15,
							'sku'      => 'X1',
						],
						'group'       => [
							'key'  => '77',
							'size' => 3,
						],
					]
				),
			]
		);

		$card = $this->store()->find( self::ACCOUNT, Wpdb_Card_Store::key( 'F-1' ) );

		$this->assertSame( 'https://cdn.test/f1.jpg', $card['image_url'] );
		$this->assertSame( '1499.50', $card['price'] );
		$this->assertSame( 3, $card['stock'] );
		$this->assertTrue( $card['failed'] );
		$this->assertTrue( $card['archived'] );
		$this->assertTrue( $card['is_created'] );
		$this->assertTrue( $card['details_ok'] );
		$this->assertSame( 'Нет бренда', $card['status_text'] );
		$this->assertSame( [ 'card_ref' => 15, 'sku' => 'X1' ], $card['remote_ids'] );
		$this->assertSame( [ 'key' => '77', 'size' => 3 ], $card['group'] );
		$this->assertSame( 0, $card['wc_product_id'] );
	}

	public function test_an_unknown_price_or_stock_stays_unknown_and_a_bad_picture_is_dropped(): void {
		$this->refresh(
			[
				$this->row( 'U-1', '', [ 'price' => null, 'stock' => 'много', 'image_url' => 'javascript:alert(1)' ] ),
				$this->row( 'U-2', '', [ 'price' => 0, 'stock' => 0 ] ),
			]
		);

		$unknown = $this->store()->find( self::ACCOUNT, Wpdb_Card_Store::key( 'U-1' ) );
		$zero    = $this->store()->find( self::ACCOUNT, Wpdb_Card_Store::key( 'U-2' ) );

		$this->assertNull( $unknown['price'] );
		$this->assertNull( $unknown['stock'] );
		$this->assertSame( '', $unknown['image_url'] );
		$this->assertSame( '0.00', $zero['price'], 'zero is a price, not «unknown»' );
		$this->assertSame( 0, $zero['stock'] );
	}

	public function test_a_long_name_and_status_are_cut_and_a_row_without_an_article_is_skipped(): void {
		$written = $this->store()->upsert( self::ACCOUNT, [ $this->row( 'L-1', str_repeat( 'я', 600 ), [ 'status_text' => str_repeat( 'ё', 2500 ) ] ), [ 'name' => 'no article' ] ], 1, 1, self::NOW );

		$card = $this->store()->find( self::ACCOUNT, Wpdb_Card_Store::key( 'L-1' ) );

		$this->assertSame( 2, $written );
		$this->assertSame( 500, mb_strlen( $card['name'] ) );
		$this->assertSame( 2000, mb_strlen( $card['status_text'] ) );
		$this->assertSame( 1, $this->store()->count( self::ACCOUNT ) );
	}

	public function test_a_batch_bigger_than_one_insert_is_written_in_several(): void {
		$rows = [];

		for ( $i = 1; $i <= Wpdb_Card_Store::BATCH + 5; $i++ ) {
			$rows[] = $this->row( sprintf( 'M-%05d', $i ) );
		}

		$this->refresh( $rows );

		$inserts = array_filter(
			$this->db->statements,
			static function ( string $sql ): bool {
				return 0 === strpos( $sql, 'INSERT INTO ' . Sqlite_Tables::table( 'cards' ) );
			}
		);

		$this->assertCount( 2, $inserts );
		$this->assertSame( Wpdb_Card_Store::BATCH + 5, $this->store()->count( self::ACCOUNT ) );
		$this->assertSame( 'M-01001', self::articles( $this->store()->page( self::ACCOUNT, null, '', 21, 50 ) )[0], 'the order runs on across the batches' );
	}

	public function test_a_refresh_sweeps_the_cards_it_did_not_see_only_when_it_finishes(): void {
		$this->refresh( [ $this->row( 'S-1' ), $this->row( 'S-2' ), $this->row( 'S-3' ) ] );
		$this->refresh( [ $this->row( 'Z-9' ) ], self::OTHER );

		$store = $this->store();
		$run   = $store->begin_refresh( self::ACCOUNT, self::NOW + 60 );
		$store->upsert( self::ACCOUNT, [ $this->row( 'S-3' ), $this->row( 'S-1' ) ], $run, 1, self::NOW + 60 );

		$this->assertSame( [ 'S-3', 'S-1', 'S-2' ], self::articles( $store->page( self::ACCOUNT, null, '', 1, 20 ) ), 'nothing leaves half way; the seen cards take their new places' );

		$this->assertSame( 1, $store->finish_refresh( self::ACCOUNT, $run, self::NOW + 60 ) );
		$this->assertSame( [ 'S-3', 'S-1' ], self::articles( $store->page( self::ACCOUNT, null, '', 1, 20 ) ) );
		$this->assertSame( 1, $store->count( self::OTHER ), 'another account is not swept' );
		$this->assertSame( self::NOW + 60, $store->refreshed_at( self::ACCOUNT ) );
	}

	public function test_an_interrupted_refresh_goes_on_under_its_own_generation(): void {
		$this->refresh( [ $this->row( 'I-1' ) ] );

		$store = $this->store();
		$first = $store->begin_refresh( self::ACCOUNT, self::NOW );
		$again = $store->begin_refresh( self::ACCOUNT, self::NOW + 30, $first );

		$this->assertSame( $first, $again, 'a run that goes on from the saved cursor keeps the generation' );
		$this->assertSame( $first, $store->current_run( self::ACCOUNT ), 'a notification meanwhile writes under the running generation' );

		$store->finish_refresh( self::ACCOUNT, $first, self::NOW + 60 );

		$this->assertGreaterThan( $first, $store->begin_refresh( self::ACCOUNT, self::NOW + 90 ), 'the next refresh is a new generation' );
	}

	public function test_a_notification_keeps_the_place_of_a_known_card_and_puts_a_new_one_first(): void {
		$this->refresh( [ $this->row( 'P-1' ), $this->row( 'P-2' ) ] );

		$store = $this->store();
		$store->upsert( self::ACCOUNT, [ $this->row( 'P-2', 'Другое имя' ), $this->row( 'P-NEW' ) ], $store->current_run( self::ACCOUNT ), 0, self::NOW + 5 );

		$this->assertSame( [ 'P-NEW', 'P-1', 'P-2' ], self::articles( $store->page( self::ACCOUNT, null, '', 1, 20 ) ) );
		$this->assertSame( 'Другое имя', $store->find( self::ACCOUNT, Wpdb_Card_Store::key( 'P-2' ) )['name'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 5 ), $store->find( self::ACCOUNT, Wpdb_Card_Store::key( 'P-2' ) )['updated_at'] );
	}

	public function test_the_refreshing_mark_holds_for_its_ttl_and_a_failure_ends_it_and_keeps_the_rows(): void {
		$this->refresh( [ $this->row( 'E-1' ) ] );
		$store = $this->store();

		$store->mark_refreshing( self::ACCOUNT );
		$this->assertTrue( $store->is_refreshing( self::ACCOUNT ) );

		$this->now += Wpdb_Card_Store::REFRESHING_TTL;
		$this->assertFalse( $store->is_refreshing( self::ACCOUNT ), 'a refresh that gave no sign of life for its TTL no longer blocks the button' );

		$store->touch( self::ACCOUNT, $this->now );
		$this->assertTrue( $store->is_refreshing( self::ACCOUNT ) );

		$store->fail( self::ACCOUNT, 'Маркетплейс не ответил' );
		$this->assertFalse( $store->is_refreshing( self::ACCOUNT ) );
		$this->assertSame( 'Маркетплейс не ответил', $store->last_error( self::ACCOUNT ) );
		$this->assertSame( 1, $store->count( self::ACCOUNT ) );

		$store->mark_refreshing( self::ACCOUNT );
		$this->assertSame( '', $store->last_error( self::ACCOUNT ), 'a new refresh forgets the last failure' );
	}

	public function test_the_record_lives_under_the_plugins_family_and_goes_with_the_account(): void {
		$this->refresh( [ $this->row( 'O-1' ) ] );
		$this->refresh( [ $this->row( 'O-2' ) ], self::OTHER );

		$this->assertSame( [ self::FAMILY . 'cards_list_' . self::ACCOUNT, self::FAMILY . 'cards_list_' . self::OTHER ], array_keys( $this->options ) );

		$store = $this->store();
		while ( $store->delete_account( self::ACCOUNT, 1 ) > 0 ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedWhile -- Drains in batches.
		}
		$store->forget( self::ACCOUNT );

		$this->assertFalse( $store->has_list( self::ACCOUNT ) );
		$this->assertTrue( $store->has_list( self::OTHER ) );
		$this->assertSame( [ self::FAMILY . 'cards_list_' . self::OTHER ], array_keys( $this->options ) );
	}

	public function test_a_refused_write_throws(): void {
		$this->db->define( 'DROP TABLE ' . Sqlite_Tables::table( 'cards' ) );

		$this->expectException( \Woodev\Framework\Marketplace\Data\Persistence_Exception::class );

		$this->store()->upsert( self::ACCOUNT, [ $this->row( 'W-1' ) ], 1, 1, self::NOW );
	}

	public function test_a_traversal_started_over_gets_a_generation_above_both_counters_and_only_a_resumed_one_keeps_its_own(): void {
		$store = $this->store();

		$first = $store->begin_refresh( self::ACCOUNT, self::NOW );
		$store->fail( self::ACCOUNT, 'denied' );

		$this->assertSame( $first, $store->begin_refresh( self::ACCOUNT, self::NOW, $first ), 'the saved cursor of the running traversal goes on' );

		$again = $store->begin_refresh( self::ACCOUNT, self::NOW );
		$this->assertGreaterThan( $first, $again, 'from the first page: never the abandoned generation' );
		$this->assertGreaterThan( $again, $store->begin_refresh( self::ACCOUNT, self::NOW, $first ), 'a cursor of an older traversal is not resumed' );
	}

	public function test_finishing_an_obsolete_generation_sweeps_and_records_nothing(): void {
		$store = $this->store();
		$old   = $store->begin_refresh( self::ACCOUNT, self::NOW );
		$store->upsert( self::ACCOUNT, [ $this->row( 'OLD' ) ], $old, 1, self::NOW );

		$new = $store->begin_refresh( self::ACCOUNT, self::NOW + 10 );
		$store->upsert( self::ACCOUNT, [ $this->row( 'NEW' ) ], $new, 1, self::NOW + 10 );
		$store->finish_refresh( self::ACCOUNT, $new, self::NOW + 10 );

		$this->assertNull( $store->finish_refresh( self::ACCOUNT, $old, self::NOW + 20 ) );
		$this->assertSame( [ 'NEW' ], self::articles( $store->page( self::ACCOUNT, null, '', 1, 20 ) ) );
		$this->assertSame( self::NOW + 10, $store->refreshed_at( self::ACCOUNT ), 'the generation does not move back' );
	}

	public function test_a_fenced_sweep_of_a_run_whose_claim_was_taken_over_deletes_nothing(): void {
		$accounts = Sqlite_Tables::table( 'accounts' );
		$this->db->insert(
			$accounts,
			[
				'account_id'         => self::ACCOUNT,
				'external_id'        => 'cab-7',
				'secret_enc'         => '',
				'notification_token' => str_repeat( 'n', 32 ),
				'claim_token'        => str_repeat( 'b', 32 ),
				'claim_until'        => gmdate( 'Y-m-d H:i:s', self::NOW + 120 ),
			]
		);
		$this->refresh( [ $this->row( 'KEPT' ) ] );

		$store = $this->store();
		$run   = $store->begin_refresh( self::ACCOUNT, self::NOW );
		$lost  = new Claim_Fence( $accounts, str_repeat( 'a', 32 ) );

		$this->assertNull( $store->finish_refresh( self::ACCOUNT, $run, self::NOW, $lost ) );
		$this->assertSame( [ 'KEPT' ], self::articles( $store->page( self::ACCOUNT, null, '', 1, 20 ) ) );

		$this->assertSame( 1, $store->finish_refresh( self::ACCOUNT, $run, self::NOW, new Claim_Fence( $accounts, str_repeat( 'b', 32 ) ) ), 'the owner sweeps' );
	}
}
